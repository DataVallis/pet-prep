<?php

namespace App\Services;

use App\Enums\BreedType;
use App\Enums\ClientFeature;
use App\Enums\FamilyRole;
use App\Enums\UserRole;
use App\Exceptions\FamilyException;
use App\Exceptions\PairingException;
use App\Jobs\GeneratePetReferenceImage;
use App\Models\BreedConfig;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\Pet;
use App\Models\User;
use App\Services\Media\PetDnaService;
use App\Services\Results\PetProfileChoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Child pairing (M1, family model M2-01 / ADR-012).
 *
 * A parent generates a 6-digit child PIN, either for a NEW pet (default) or
 * to let the child JOIN an existing pet of the family (shared pet, `pet_id`).
 * The PIN lives on the parent (users.pairing_pin / pairing_pet_id), valid
 * 15 minutes, single use. A newly generated PIN replaces the parent's
 * previous one.
 */
class PairingService
{
    public function __construct(
        private readonly FalAiService $falAiService,
        private readonly FamilyService $families,
        private readonly PetDnaService $petDna,
        private readonly LifeStageService $lifeStages,
        private readonly TrainingService $training,
    ) {}

    /**
     * The PIN expiration window in minutes.
     */
    public const PIN_EXPIRY_MINUTES = 15;

    /**
     * Generate a unique 6-digit pairing PIN for a parent user.
     *
     * @param  int|null  $joinPetId  existing pet of the parent's family the child
     *                               will share; null = the PIN creates a new pet
     * @return array{pin: string, expires_at: Carbon, pet_id: int|null}
     *
     * @throws FamilyException the pet is not a joinable pet of this family
     */
    public function generatePin(User $parent, ?int $joinPetId = null): array
    {
        if (! $parent->isParent()) {
            throw new \DomainException('Only parent profiles can generate pairing PINs.');
        }

        $family = $this->families->ensureFamilyFor($parent);

        if ($joinPetId !== null) {
            $pet = Pet::where('family_id', $family->id)->find($joinPetId);
            // Another family's pet looks exactly like a missing one.
            if ($pet === null || ! $pet->is_active || $pet->is_game_over) {
                throw new FamilyException('pet_not_joinable', 'This pet cannot get another caretaker.');
            }
        }

        // Generate a unique 6-digit PIN (avoid collisions with active PINs)
        do {
            $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (
            User::where('pairing_pin', $pin)
                ->where('pin_expires_at', '>', now())
                ->exists()
        );

        $expiresAt = now()->addMinutes(self::PIN_EXPIRY_MINUTES);

        // Written unconditionally: the in-memory model may be stale (the
        // previous PIN was consumed by another request), so dirty checking
        // could skip an unchanged-looking column.
        $values = [
            'pairing_pin' => $pin,
            'pin_expires_at' => $expiresAt,
            'pairing_pet_id' => $joinPetId,
        ];
        User::whereKey($parent->id)->update($values);
        $parent->forceFill($values)->syncOriginal();

        return [
            'pin' => $pin,
            'expires_at' => $expiresAt,
            'pet_id' => $joinPetId,
        ];
    }

    /**
     * Pair a child using a parent's PIN, atomically:
     *  - the child joins the parent's family (users.parent_id is still set,
     *    deprecated mirror for old app builds);
     *  - new-pet PIN: the child's own unborn pet is created (Mutt) with the
     *    child as caretaker — the contract births it (M1-07b);
     *  - join PIN: the child becomes a caretaker of that pet (shared pet). The
     *    pet is not re-born; the child must sign their own contract before
     *    acting (423 contract_required for this child only).
     *
     * A child who is already paired (any family) is refused.
     *
     * @return array{parent: User, pet: Pet, joined_existing: bool}
     *
     * @throws PairingException
     */
    public function pairChild(string $pin, User $child): array
    {
        return DB::transaction(function () use ($pin, $child) {
            $parent = User::where('pairing_pin', $pin)
                ->where('pin_expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (! $parent) {
                throw new PairingException('Invalid or expired pairing PIN.');
            }

            if (! $parent->isParent()) {
                throw new PairingException('The PIN does not belong to a parent profile.');
            }

            if ($child->isParent()) {
                throw new PairingException('A parent profile cannot be paired as a child.');
            }

            // Lock order (shared with FamilyInviteService::joinFamily):
            // parent user row (above) → child user row → family row.
            // Re-reading the child under the lock makes two concurrent
            // pairings of the same child end in one 201 and one 422 (not a
            // unique-key 500), and a parent moving to another family can't
            // delete the family this pet is being created in.
            $lockedChild = User::whereKey($child->id)->lockForUpdate()->first();

            $family = $this->families->ensureFamilyFor($parent);
            Family::whereKey($family->id)->lockForUpdate()->first();

            // Unchanged rule: a child profile pairs once. (A second pet for
            // the same child, e.g. after game over, is a follow-up — ADR-012.)
            if ($lockedChild === null
                || $lockedChild->parent_id !== null
                || FamilyMember::where('user_id', $child->id)->exists()) {
                throw new PairingException('This child profile is already paired to a parent.');
            }

            // Link child to the family (and the deprecated parent_id mirror).
            $child->forceFill([
                'parent_id' => $parent->id,
                'role' => UserRole::Child->value,
            ])->saveQuietly();
            $this->families->addMember($family, $child, FamilyRole::Child);

            ['pet' => $pet, 'joined_existing' => $joined] = $this->attachChildToPet($family, $child, $parent->pairing_pet_id);

            // Consume the PIN so it cannot be reused
            $parent->update([
                'pairing_pin' => null,
                'pin_expires_at' => null,
                'pairing_pet_id' => null,
            ]);

            return [
                'parent' => $parent->fresh(),
                'pet' => $pet,
                'joined_existing' => $joined,
            ];
        });
    }

    /**
     * Give a freshly paired child their pet: join the shared pet `$joinPetId`
     * (becomes a caretaker, must sign their own contract) or create the
     * child's own unborn pet. Shared by the legacy PIN pairing (pairChild)
     * and the PIN-only child login (ChildPinLoginService, M2-02).
     *
     * Must run inside the caller's transaction, after the parent → child →
     * family row locks; the child must already be a member of $family.
     *
     * `$profile` (M5-R01) = the parent's choice stored on the PIN; null (a
     * PIN issued before M5-R01, the deprecated /child/pair path) creates a
     * legacy-profile pet that keeps the pre-M5 rules (grandfathering).
     *
     * @return array{pet: Pet, joined_existing: bool}
     *
     * @throws PairingException
     */
    public function attachChildToPet(Family $family, User $child, ?int $joinPetId, ?PetProfileChoice $profile = null): array
    {
        if ($joinPetId !== null) {
            $pet = Pet::whereKey($joinPetId)->lockForUpdate()->first();
            if ($pet === null || $pet->family_id !== $family->id || ! $pet->is_active || $pet->is_game_over) {
                throw new PairingException('This pet can no longer get another caretaker.');
            }

            $this->addCaretakerOrFail($pet, $child);

            return ['pet' => $pet, 'joined_existing' => true];
        }

        return ['pet' => $this->createPet($family->id, $child, $profile), 'joined_existing' => false];
    }

    /**
     * A new pet's profile must be a breed with a config that needs no
     * purchase (M5-R01): premium breeds stay purchase-only as before (the
     * RevenueCat unlock upgrades an existing pet). Every origin / age stage
     * is free (David 2026-10-05).
     *
     * @throws FamilyException breed_locked (422)
     */
    public function assertProfileAllowed(PetProfileChoice $profile): void
    {
        $config = BreedConfig::forBreed($profile->breed);

        if ($config === null || $config->premium_unlock) {
            throw new FamilyException('breed_locked', 'This breed is part of the paid PetPrep challenge.');
        }
    }

    private function createPet(int $familyId, User $child, ?PetProfileChoice $profile): Pet
    {
        // Pet DNA is generated offline. The reference image is produced
        // asynchronously by a queued job dispatched after this transaction
        // commits — never call fal.ai here.
        // DNA v2 (M4-08): unique traits seeded from the pet id + a random salt,
        // so it is assigned right after the insert; the caller holds the
        // family row lock, which makes the per-family uniqueness check safe.
        // M5-R01: breed / origin / age stage chosen by the parent (default:
        // a bought mutt puppy). A premium breed chosen at PIN time but locked
        // by now falls back to the mutt. Without a profile (old PIN,
        // deprecated /child/pair) → a legacy-profile mutt (pre-M5 rules).
        $breed = $profile?->breed ?? BreedType::Mutt;
        if (BreedConfig::forBreed($breed)?->premium_unlock !== false) {
            $breed = BreedType::Mutt;
        }
        $arrivalAge = $profile !== null ? $this->lifeStages->arrivalAgeFor($breed->slug(), $profile->ageStage) : null;
        $stage = $arrivalAge !== null ? $this->lifeStages->stageForAge($breed->slug(), $arrivalAge) : null;
        $dnaVersion = (int) config('media.pet_dna_version', PetDnaService::VERSION);
        $petDna = $dnaVersion === PetDnaService::VERSION ? null : $this->falAiService->generateInitialPetDna($breed);
        $mediaEnabled = $this->falAiService->isEnabled();

        // Contract before birth (David 2026-10-04, PRODUCT_SPEC §3,
        // M1-07b): the pet exists from now on (DNA, reference image) but
        // is unborn — born_at stays null, the game loop ignores it and
        // child actions return 423 contract_required until the child
        // signs (POST /api/child/contract births it).
        $pet = Pet::create([
            'user_id' => $child->id, // primary caretaker (deprecated mirror)
            'family_id' => $familyId,
            'breed_type' => $breed->value,
            'pet_dna' => $petDna,
            'media_status' => $mediaEnabled ? 'pending' : 'disabled',
            'hunger_level' => 100,
            'thirst_level' => 100,
            'energy_level' => 100,
            'hygiene_level' => 100,
            'born_at' => null,
            'is_active' => true,
            // Legacy profile (null arrival age) without a choice or without
            // life-stage data for the breed: the pre-M5 rules.
            'origin' => $profile?->origin->value,
            'arrival_age_months' => $arrivalAge,
            'life_stage' => $stage?->value,
            // M5-R02 (PR #42 B1): behaviour events only when the creating app
            // build can show them (generate-pin `features`) and the pet has a
            // profile; never changed later (a joining caretaker keeps it).
            'behaviour_events_enabled' => $arrivalAge !== null && $profile?->supports(ClientFeature::BehaviourEvents) === true,
            // M5-R03: training (mini-game + daily training routine) the same way —
            // profile + `training` declared by the parent's PIN and the child's device.
            'training_enabled' => $arrivalAge !== null && $profile?->supports(ClientFeature::Training) === true,
        ]);

        if ($petDna === null) {
            $pet->forceFill(['pet_dna' => $this->petDna->forNewPet($pet)])->saveQuietly();
        }

        // Pet::created already added the caretaker row; this is a no-op
        // that keeps the invariant explicit.
        $this->addCaretakerOrFail($pet, $child);

        // M5-R03b (David 2026-10-06): a dog arriving young / adult / senior
        // already knows some commands (its arrival stage's
        // training_starting_progress); a puppy starts at 0. Here because this
        // is the only place a pet gets its profile and `training_enabled` —
        // never changed later, so the values are applied exactly once.
        $this->training->applyStartingProgress($pet, now());

        if ($mediaEnabled) {
            GeneratePetReferenceImage::dispatch($pet->id)->afterCommit();
        }

        return $pet;
    }

    private function addCaretakerOrFail(Pet $pet, User $child): void
    {
        try {
            $this->families->addCaretaker($pet, $child, requiresContract: true);
        } catch (FamilyException $e) {
            throw new PairingException($e->getMessage());
        }
    }
}
