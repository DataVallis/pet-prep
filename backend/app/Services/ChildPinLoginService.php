<?php

namespace App\Services;

use App\Enums\FamilyRole;
use App\Enums\PetPlan;
use App\Enums\Species;
use App\Enums\TokenAbility;
use App\Exceptions\ChildLoginException;
use App\Exceptions\FamilyException;
use App\Exceptions\PairingException;
use App\Models\ChildLoginPin;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\Pet;
use App\Models\PetCaretaker;
use App\Models\User;
use App\Services\Results\PetProfileChoice;
use App\Support\ClientIp;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * PIN-only child login (M2-02).
 *
 * The parent generates a one-time 6-digit PIN for a child profile of their
 * family (POST /api/parent/generate-pin {child_id, pet_id?}); the child types
 * it on their own device (POST /api/child/pin-login, no account, no e-mail).
 * What the PIN does is decided under the row locks when it is used:
 *
 *  - new_pet  — child never paired, no pet_id: the child's own unborn pet is
 *               created (contract births it, M1-07b);
 *  - join_pet — child never paired, pet_id: the child becomes a caretaker of
 *               that shared pet and signs their own contract;
 *  - relogin  — child already a caretaker (or pet_id = their pet): only a new
 *               token for the same child / pet, nothing is re-paired.
 *
 * Security (6-digit space = 10^6 values):
 *  - only HMAC-SHA256(pin, APP_KEY) is stored; lookup by hash + hash_equals;
 *  - single use, 15 minutes, a new PIN for the child revokes its open one;
 *  - wrong, expired and used PINs get the same 422 `invalid_pin` (no
 *    enumeration of children, families or PIN states);
 *  - failed attempts: MAX_IP_FAILURES per IP and MAX_GLOBAL_FAILURES across
 *    all clients per FAILURE_WINDOW_SECONDS → 429 (successes never reset the
 *    counters, so a parent can't launder an attack with their own PINs);
 *    plus the route throttle `pin-login` (per IP per minute).
 */
class ChildPinLoginService
{
    public const PIN_EXPIRY_MINUTES = PairingService::PIN_EXPIRY_MINUTES;

    public const MAX_IP_FAILURES = 10;

    public const MAX_GLOBAL_FAILURES = 100;

    public const FAILURE_WINDOW_SECONDS = 900;

    public const MODE_NEW_PET = 'new_pet';

    public const MODE_JOIN_PET = 'join_pet';

    public const MODE_RELOGIN = 'relogin';

    private const GLOBAL_KEY = 'child-pin-login:failures:global';

    public function __construct(
        private readonly FamilyService $families,
        private readonly PairingService $pairing,
        private readonly ChildProfileService $profiles,
    ) {}

    public static function hashPin(string $pin): string
    {
        return hash_hmac('sha256', $pin, (string) config('app.key'));
    }

    /**
     * Issue a one-time PIN for $child (a child of the parent's family).
     * `$profile` (M5-R01) is the new pet's breed / origin / age stage, kept
     * on the PIN until it creates the pet (ignored for join / re-login);
     * null = no profile chosen (old app builds) → the PIN creates a
     * legacy-profile pet on the pre-M5 rules. `$plan` (M3-11) is the new
     * pet's plan, kept on the PIN the same way (free → mutt only).
     * `$planChosen` (M5-F03): the parent sent `plan` explicitly — then a
     * challenge with the mutt (or no breed → mutt) is refused.
     *
     * @return array{pin: string, expires_at: Carbon, child_id: int, pet_id: int|null, mode: string, pet_profile: array{breed: string, origin: string, age_stage: string, features: list<string>}|null, plan: string|null, trial_available: bool|null}
     *
     * @throws FamilyException child_not_found (404), pet_not_joinable (422),
     *                         already_paired (422), breed_locked (422),
     *                         challenge_requires_paid_breed (422)
     */
    public function generatePin(User $parent, int $childId, ?int $joinPetId, ?PetProfileChoice $profile = null, PetPlan $plan = PetPlan::Challenge, bool $planChosen = false): array
    {
        if (! $parent->isParent()) {
            throw new FamilyException('not_a_parent', 'Only a parent can generate a PIN.', 403);
        }

        // Warm the cats-switch cache outside the row locks (see login()).
        app(AppSettingsService::class)->storedCats();

        return DB::transaction(function () use ($parent, $childId, $joinPetId, $profile, $plan, $planChosen): array {
            // Lock order: parent user row → child user row → family row.
            User::whereKey($parent->id)->lockForUpdate()->first();

            $child = $this->profiles->childOfParentFamily($parent, $childId);
            if ($child === null) {
                throw new FamilyException('child_not_found', 'No such child in your family.', 404);
            }
            User::whereKey($child->id)->lockForUpdate()->first();

            $family = $this->families->familyOf($parent);
            Family::whereKey($family->id)->lockForUpdate()->first();

            $mode = $this->resolveMode($family, $child, $joinPetId, forGeneration: true);
            if ($mode === self::MODE_NEW_PET && $profile !== null) {
                // M5-R06-01: breed ↔ species, cats only when available (switch for this family + `species_cat`).
                $this->pairing->assertSpeciesAllowed($profile, $family);
                $this->pairing->assertProfileAllowed($profile, $plan);
            }
            // M5-F03: an explicitly chosen challenge needs a paid breed (no profile → mutt).
            if ($mode === self::MODE_NEW_PET && $planChosen) {
                $this->pairing->assertPlanAllowed($profile, $plan);
            }
            // PAYMENTS_SPEC P4: no plan sent (builds before M3-09) → the challenge only for a
            // paid breed; a free breed (no profile → the mutt) is the free plan (never a
            // lockable challenge). M5-R06-01: "free" = `breed_configs.premium_unlock` false.
            if (! $planChosen) {
                $plan = PairingService::defaultPlanFor($profile?->breed ?? Species::Dog->freeBreed());
            }

            // One open PIN per child: a new one replaces the previous.
            ChildLoginPin::open()->where('child_user_id', $child->id)->update(['revoked_at' => now()]);

            $expiresAt = now()->addMinutes(self::PIN_EXPIRY_MINUTES)->startOfSecond();
            // No profile (old app builds) → no options → a legacy-profile pet (pre-M5 rules).
            $options = $mode === self::MODE_NEW_PET ? $profile?->toArray() : null;
            $newPetPlan = $mode === self::MODE_NEW_PET ? $plan : null;
            $pin = $this->insertUniquePin($family, $child, $parent, $joinPetId, $expiresAt, $options, $newPetPlan);

            return [
                'pin' => $pin,
                'expires_at' => $expiresAt,
                'child_id' => $child->id,
                'pet_id' => $joinPetId,
                'mode' => $mode,
                'pet_profile' => $options,
                'plan' => $newPetPlan?->value,
                // M3-13 (David 2026-10-08): no free trial any more. Deprecated, always
                // null — `false` would make TestFlight 3.0.0 say "already had a free
                // trial" (QA PR #83 m1). Kept in the shape for old app builds.
                'trial_available' => null,
            ];
        });
    }

    /**
     * Consume a PIN and sign the child in on this device.
     *
     * @param  list<string>  $clientFeatures  ClientFeature values this child device supports (M5-R02):
     *                                        a new pet gets only features the parent's PIN AND this device declared.
     * @return array{token: string, child: User, pet: Pet, mode: string, joined_existing: bool}
     *
     * @throws ChildLoginException
     */
    public function login(string $pin, string $deviceName, string $ip, array $clientFeatures = []): array
    {
        // IPv6 is keyed on its /64 (a client usually owns the whole prefix).
        $ipKey = 'child-pin-login:failures:ip:'.ClientIp::rateLimitKey($ip);
        $this->assertNotLockedOut($ipKey);

        $hash = self::hashPin($pin);
        $candidate = ChildLoginPin::open()->where('pin_hash', $hash)->first();

        if ($candidate === null
            || ! hash_equals($candidate->pin_hash, $hash)
            || ! $candidate->expires_at->isFuture()) {
            $this->recordFailure($ipKey, $ip);

            throw ChildLoginException::invalidPin();
        }

        // Warm the cats-switch cache outside the row locks (a cache miss inside
        // the transaction would upsert the shared cache row while holding them).
        app(AppSettingsService::class)->storedCats();

        try {
            return DB::transaction(function () use ($candidate, $deviceName, $clientFeatures): array {
                // Lock order (backend/CLAUDE.md): parent → child → family, then
                // the PIN row; re-validated under the locks.
                if ($candidate->created_by !== null) {
                    User::whereKey($candidate->created_by)->lockForUpdate()->first();
                }
                $child = User::whereKey($candidate->child_user_id)->lockForUpdate()->first();
                $family = Family::whereKey($candidate->family_id)->lockForUpdate()->first();
                $locked = ChildLoginPin::whereKey($candidate->id)->lockForUpdate()->first();

                if ($locked === null || ! $locked->isUsable()) {
                    // Used / revoked by a concurrent request.
                    throw ChildLoginException::invalidPin();
                }

                if ($child === null || $family === null || ! $child->isChild()
                    || ! FamilyMember::where('family_id', $family->id)->where('user_id', $child->id)
                        ->where('role', FamilyRole::Child->value)->exists()) {
                    throw new PairingException('The child profile is no longer part of this family.');
                }

                $mode = $this->resolveMode($family, $child, $locked->pet_id, forGeneration: false);
                $this->assertDeviceCanShowPet($mode, $child, $family, $locked, $clientFeatures);

                $joined = false;
                if ($mode === self::MODE_RELOGIN) {
                    $pet = $child->currentPet();
                } else {
                    // First pairing of this profile.
                    if ($child->parent_id === null) {
                        // Deprecated mirror (M2-01b).
                        $child->forceFill([
                            'parent_id' => $locked->created_by ?? $family->parents()->value('users.id'),
                        ])->saveQuietly();
                    }
                    ['pet' => $pet, 'joined_existing' => $joined] = $this->pairing->attachChildToPet(
                        // A PIN without options (issued before M5-R01) → legacy-profile pet.
                        // M5-R02: features = parent PIN ∩ this child device (both apps must show them).
                        $family, $child, $locked->pet_id, $locked->pet_options !== null ? PetProfileChoice::fromArray($locked->pet_options)->withOnlyFeatures($clientFeatures) : null,
                        // M3-11: a PIN from an old app build has no plan → challenge.
                        $locked->plan ?? PetPlan::Challenge,
                    );
                }

                if ($pet === null) {
                    throw new PairingException('The child has no pet.');
                }

                $locked->forceFill(['consumed_at' => now()])->save();

                $token = $child->createToken(ChildProfileService::deviceLabel($deviceName), TokenAbility::abilitiesFor($child))->plainTextToken;
                $this->profiles->pruneDevices($child);

                return [
                    'token' => $token,
                    'child' => $child,
                    'pet' => $pet,
                    'mode' => $mode,
                    'joined_existing' => $joined,
                ];
            });
        } catch (PairingException|FamilyException $e) {
            // The PIN matched but can no longer do what it was issued for
            // (e.g. the shared pet ended, the child got another pet). Kill it
            // so it can't be retried; the parent issues a new one.
            ChildLoginPin::whereKey($candidate->id)->whereNull('consumed_at')->update(['revoked_at' => now()]);
            Log::info('Child PIN login refused after a match', ['pin_id' => $candidate->id, 'reason' => $e->getMessage()]);

            throw new ChildLoginException('pin_not_usable', 'This code can no longer be used. Ask your parent for a new code.');
        }
    }

    /**
     * M5-R06-01 (plan T4): a child app build without `species_cat` can't show a
     * cat — signing in to a cat (new, shared or re-login) → 422
     * `app_update_required`; the PIN stays usable (update the app, try again).
     * A NEW cat additionally needs the cats switch for the PIN's family at
     * login time (M5-R06-09: /admin → Funkcije): switched off since the PIN
     * was issued → the PIN is revoked (`pin_not_usable`). Join / re-login to
     * an existing cat never depends on the switch.
     *
     * @param  list<string>  $clientFeatures
     *
     * @throws ChildLoginException|PairingException
     */
    private function assertDeviceCanShowPet(string $mode, User $child, Family $family, ChildLoginPin $pin, array $clientFeatures): void
    {
        $species = match ($mode) {
            self::MODE_NEW_PET => $pin->pet_options !== null ? PetProfileChoice::fromArray($pin->pet_options)->species : Species::Dog,
            self::MODE_JOIN_PET => Pet::whereKey($pin->pet_id)->value('species'),
            default => ($pin->pet_id !== null ? Pet::whereKey($pin->pet_id)->value('species') : null)
                ?? $child->currentPet()?->species,
        };
        $species = $species instanceof Species ? $species : (Species::tryFrom((string) $species) ?? Species::Dog);

        if (! SpeciesAvailability::appSupports($species, $clientFeatures)) {
            throw new ChildLoginException('app_update_required', 'Update the app to look after this pet.');
        }

        if ($mode === self::MODE_NEW_PET && $species === Species::Cat && ! app(SpeciesAvailability::class)->catsEnabledFor($family)) {
            throw new PairingException('Cats are not available any more.');
        }
    }

    /**
     * new_pet | join_pet | relogin for this child and PIN target.
     *
     * @throws FamilyException (generation) / PairingException (login)
     */
    private function resolveMode(Family $family, User $child, ?int $joinPetId, bool $forGeneration): string
    {
        $caretakerPetIds = PetCaretaker::where('user_id', $child->id)->pluck('pet_id');

        if ($joinPetId !== null && $caretakerPetIds->contains($joinPetId)) {
            return self::MODE_RELOGIN;
        }

        if ($caretakerPetIds->isNotEmpty()) {
            if ($joinPetId === null) {
                return self::MODE_RELOGIN;
            }

            // A paired child can't move to another pet (new pet after game
            // over / switching pets is an open question for David, ADR-012).
            $this->refuse($forGeneration, 'already_paired', 'This child already has a pet.');
        }

        if ($joinPetId === null) {
            return self::MODE_NEW_PET;
        }

        $pet = Pet::where('family_id', $family->id)->find($joinPetId);
        if ($pet === null || ! $pet->is_active || $pet->is_game_over) {
            $this->refuse($forGeneration, 'pet_not_joinable', 'This pet cannot get another caretaker.');
        }

        return self::MODE_JOIN_PET;
    }

    private function refuse(bool $forGeneration, string $reason, string $message): never
    {
        if ($forGeneration) {
            throw new FamilyException($reason, $message);
        }

        throw new PairingException($message);
    }

    /**
     * @param  array<string, string>|null  $petOptions
     */
    private function insertUniquePin(Family $family, User $child, User $parent, ?int $joinPetId, Carbon $expiresAt, ?array $petOptions = null, ?PetPlan $plan = null): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $hash = self::hashPin($pin);

            // An expired-but-open row with the same value must not block it.
            ChildLoginPin::open()->where('pin_hash', $hash)->where('expires_at', '<=', now())
                ->update(['revoked_at' => now()]);

            try {
                // Savepoint: a unique violation must not abort the outer
                // PostgreSQL transaction.
                DB::transaction(fn () => ChildLoginPin::create([
                    'family_id' => $family->id,
                    'child_user_id' => $child->id,
                    'created_by' => $parent->id,
                    'pet_id' => $joinPetId,
                    'pin_hash' => $hash,
                    'expires_at' => $expiresAt,
                    'pet_options' => $petOptions,
                    'plan' => $plan?->value,
                ]));

                return $pin;
            } catch (UniqueConstraintViolationException) {
                // Another open PIN has this value — draw again.
            }
        }

        throw new \RuntimeException('Could not allocate a unique child PIN.');
    }

    private function assertNotLockedOut(string $ipKey): void
    {
        foreach ([[$ipKey, self::MAX_IP_FAILURES], [self::GLOBAL_KEY, self::MAX_GLOBAL_FAILURES]] as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw new ChildLoginException(
                    'too_many_attempts',
                    'Too many wrong codes. Try again later.',
                    429,
                    max(1, RateLimiter::availableIn($key)),
                );
            }
        }
    }

    private function recordFailure(string $ipKey, string $ip): void
    {
        RateLimiter::hit($ipKey, self::FAILURE_WINDOW_SECONDS);
        $global = RateLimiter::hit(self::GLOBAL_KEY, self::FAILURE_WINDOW_SECONDS);

        if ($global === self::MAX_GLOBAL_FAILURES) {
            Log::warning('Child PIN login: global failed-attempt limit reached, PIN login paused', [
                'window_seconds' => self::FAILURE_WINDOW_SECONDS,
                'last_ip' => $ip,
            ]);
        }
    }
}
