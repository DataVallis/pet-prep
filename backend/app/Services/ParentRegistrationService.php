<?php

namespace App\Services;

use App\Enums\TokenAbility;
use App\Enums\UserRole;
use App\Exceptions\EmailTakenException;
use App\Models\Family;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Parent self-registration with e-mail + password (M2-10a).
 *
 * One transaction: parent user (role parent, e-mail lower case, password
 * hashed, terms_accepted_at = now, terms_version from config/legal.php) +
 * the parent's own family in the chosen timezone + the first Sanctum token
 * with the `parent` ability. No external HTTP (no breach-password lookup,
 * no e-mail sending — verification is M2-10b).
 */
class ParentRegistrationService
{
    public function __construct(private readonly FamilyService $families) {}

    /**
     * @return array{user: User, family: Family, token: string}
     *
     * @throws EmailTakenException a concurrent sign-up won the race to the
     *                             e-mail unique index (validation had passed)
     * @throws UniqueConstraintViolationException any other unique violation
     */
    public function register(
        string $name,
        string $email,
        string $password,
        string $timezone,
        string $deviceName,
    ): array {
        $email = User::normalizeEmail($email);
        $termsVersion = (string) config('legal.terms_version', 'draft-2026-10');

        try {
            return DB::transaction(function () use ($name, $email, $password, $timezone, $deviceName, $termsVersion): array {
                $user = new User;
                $user->forceFill([
                    'name' => $name,
                    'email' => $email,
                    'password' => $password, // hashed by the model cast
                    'role' => UserRole::Parent,
                    // Mirror of families.timezone (legacy column, M2-01 bridge).
                    'timezone' => $timezone,
                    'terms_accepted_at' => now(),
                    // Which legal texts were accepted (texts still pending).
                    'terms_version' => $termsVersion,
                ]);
                $user->save();

                // The User::created hook already gave the parent a family (from
                // users.timezone); resolve it explicitly and make sure the
                // timezone is the one chosen at sign-up.
                $family = $this->families->ensureFamilyFor($user);
                if ($family->timezone !== $timezone) {
                    $family->timezone = $timezone;
                    $family->save();
                }

                $token = $user->createToken($deviceName, TokenAbility::abilitiesFor($user))->plainTextToken;

                return ['user' => $user, 'family' => $family, 'token' => $token];
            });
        } catch (UniqueConstraintViolationException $e) {
            // Lost a race against a concurrent sign-up with the same address.
            // Only the e-mail indexes mean that; anything else is a bug.
            if (self::isEmailUniqueViolation($e)) {
                throw new EmailTakenException;
            }

            throw $e;
        }
    }

    /**
     * The users e-mail unique indexes: `users_email_unique` (plain) and
     * `users_email_lower_unique` (lower(email), PR #25).
     */
    public static function isEmailUniqueViolation(UniqueConstraintViolationException $e): bool
    {
        return preg_match('/"(users_email_unique|users_email_lower_unique)"/', $e->getMessage()) === 1;
    }
}
