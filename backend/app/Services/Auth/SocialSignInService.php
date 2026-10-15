<?php

namespace App\Services\Auth;

use App\Enums\TokenAbility;
use App\Enums\UserRole;
use App\Exceptions\SocialAuthException;
use App\Http\Requests\RegisterParentRequest;
use App\Models\User;
use App\Models\UserSocialAccount;
use App\Services\FamilyService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Parent sign-in / sign-up with a verified Google or Apple identity (M2-10c).
 * The token is verified BEFORE this service runs (SocialTokenVerifier, external
 * HTTP); everything here is one DB transaction.
 *
 * 1. (provider, sub) already linked → sign that parent in.
 * 2. Not linked, an account with the same e-mail exists:
 *    - child → 403 `not_a_parent`; superadmin → 403 `superadmin_protected`;
 *    - has a password (e-mail sign-up) → 409 `email_in_use`, never auto-linked:
 *      PetPrep never verified that address (M2-10b), so linking would let
 *      whoever pre-registered it take over the provider's user (or the other
 *      way round);
 *    - no password (created by social sign-in) → linked only when the
 *      provider is authoritative for the e-mail (SocialIdentity::$emailLinkable:
 *      Apple verified; Google verified AND Gmail or Workspace `hd`) and the
 *      account has no other identity of the same provider; otherwise 409
 *      `email_link_unavailable` (QA M1 / m3).
 * 3. No account → create parent + own family + token like POST /api/register
 *    (terms required, else 422 `terms_required` and nothing is written).
 *    `users.email` is stored only when the provider is authoritative for it
 *    (else null) — so a later Apple sign-in with the same address can never be
 *    linked into an account opened with a non-authoritative Google address.
 */
class SocialSignInService
{
    public const FALLBACK_NAME = 'Parent';

    public function __construct(private readonly FamilyService $families) {}

    /**
     * @throws SocialAuthException
     */
    public function signIn(
        SocialIdentity $identity,
        bool $termsAccepted,
        string $timezone,
        ?string $requestName,
        string $deviceName,
    ): SocialSignInResult {
        try {
            return $this->attempt($identity, $termsAccepted, $timezone, $requestName, $deviceName);
        } catch (UniqueConstraintViolationException) {
            // A concurrent first sign-in with the same identity / e-mail won
            // the race; the second pass finds its rows and signs in or links.
            return $this->attempt($identity, $termsAccepted, $timezone, $requestName, $deviceName);
        }
    }

    private function attempt(
        SocialIdentity $identity,
        bool $termsAccepted,
        string $timezone,
        ?string $requestName,
        string $deviceName,
    ): SocialSignInResult {
        return DB::transaction(function () use ($identity, $termsAccepted, $timezone, $requestName, $deviceName): SocialSignInResult {
            $link = UserSocialAccount::where('provider', $identity->provider->value)
                ->where('subject', $identity->subject)
                ->first();

            if ($link !== null) {
                $user = User::whereKey($link->user_id)->lockForUpdate()->firstOrFail();
                $this->assertMaySignIn($user);

                return new SocialSignInResult($user, $this->issueToken($user, $deviceName), false);
            }

            $existing = $identity->email !== null ? User::findByEmail($identity->email) : null;

            if ($existing !== null) {
                $user = User::whereKey($existing->id)->lockForUpdate()->firstOrFail();
                $this->assertMaySignIn($user);

                $sameProviderLinked = UserSocialAccount::where('user_id', $user->id)
                    ->where('provider', $identity->provider->value)
                    ->exists();

                if ($user->hasPassword()) {
                    throw new SocialAuthException(
                        SocialAuthException::EMAIL_IN_USE,
                        'An account with this e-mail already exists. Sign in with e-mail and password.',
                        409,
                    );
                }
                if (! $identity->emailLinkable || $sameProviderLinked) {
                    throw new SocialAuthException(
                        SocialAuthException::EMAIL_LINK_UNAVAILABLE,
                        'This e-mail belongs to an account that uses another sign-in method.',
                        409,
                    );
                }

                $this->link($user, $identity);

                return new SocialSignInResult($user, $this->issueToken($user, $deviceName), false);
            }

            if (! $termsAccepted) {
                throw new SocialAuthException(
                    SocialAuthException::TERMS_REQUIRED,
                    'You must accept the terms of use and the privacy policy.',
                    422,
                );
            }

            $user = $this->createParent($identity, $timezone, $requestName);
            $this->link($user, $identity);

            return new SocialSignInResult($user, $this->issueToken($user, $deviceName), true);
        });
    }

    private function assertMaySignIn(User $user): void
    {
        if (! $user->isParent()) {
            throw new SocialAuthException(SocialAuthException::NOT_A_PARENT, 'Only parents can sign in this way.', 403);
        }
        if ($user->isSuperadmin()) {
            throw new SocialAuthException(SocialAuthException::SUPERADMIN_PROTECTED, 'This account cannot sign in this way.', 403);
        }
    }

    private function createParent(SocialIdentity $identity, string $timezone, ?string $requestName): User
    {
        $user = new User;
        $user->forceFill([
            'name' => $this->displayName($identity, $requestName),
            // Only an address the provider is authoritative for (Apple relay
            // addresses are; a non-Gmail Google address without `hd` is not).
            'email' => $identity->linkableEmail(),
            'password' => null,
            'role' => UserRole::Parent,
            // Mirror of families.timezone (legacy column, M2-01 bridge).
            'timezone' => $timezone,
            'terms_accepted_at' => now(),
            'terms_version' => (string) config('legal.terms_version', 'draft-2026-10'),
        ]);
        $user->save();

        // The User::created hook already gave the parent a family; make sure
        // its timezone is the device's (as in ParentRegistrationService).
        $family = $this->families->ensureFamilyFor($user);
        if ($family->timezone !== $timezone) {
            $family->timezone = $timezone;
            $family->save();
        }

        return $user;
    }

    /**
     * Token claim (Google `name`) › name sent by the app (Apple, first
     * authorization only) › e-mail local part › "Parent". Whitespace
     * collapsed, ≤ 60 characters (same limit as POST /api/register).
     */
    private function displayName(SocialIdentity $identity, ?string $requestName): string
    {
        $candidates = [$identity->name, $requestName, $identity->verifiedEmail() !== null ? strstr($identity->verifiedEmail(), '@', true) : null];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }
            $clean = trim((string) preg_replace('/\s+/u', ' ', $candidate));
            if ($clean !== '') {
                return mb_substr($clean, 0, RegisterParentRequest::MAX_NAME_LENGTH);
            }
        }

        return self::FALLBACK_NAME;
    }

    private function link(User $user, SocialIdentity $identity): void
    {
        UserSocialAccount::create([
            'user_id' => $user->id,
            'provider' => $identity->provider->value,
            'subject' => $identity->subject,
            // As reported at link time, only when the provider verified it (QA N5).
            'email' => $identity->verifiedEmail(),
        ]);
    }

    private function issueToken(User $user, string $deviceName): string
    {
        return $user->createToken($deviceName, TokenAbility::abilitiesFor($user))->plainTextToken;
    }
}
