<?php

namespace App\Services;

use App\Enums\BreedType;
use App\Enums\FamilyRole;
use App\Enums\UserRole;
use App\Exceptions\FamilyException;
use App\Exceptions\PairingException;
use App\Jobs\GeneratePetReferenceImage;
use App\Models\FamilyMember;
use App\Models\Pet;
use App\Models\User;
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

            $family = $this->families->ensureFamilyFor($parent);

            // Unchanged rule: a child profile pairs once. (A second pet for
            // the same child, e.g. after game over, is a follow-up — ADR-012.)
            if ($child->parent_id !== null || FamilyMember::where('user_id', $child->id)->exists()) {
                throw new PairingException('This child profile is already paired to a parent.');
            }

            // Link child to the family (and the deprecated parent_id mirror).
            $child->forceFill([
                'parent_id' => $parent->id,
                'role' => UserRole::Child->value,
            ])->saveQuietly();
            $this->families->addMember($family, $child, FamilyRole::Child);

            $joinPetId = $parent->pairing_pet_id;

            if ($joinPetId !== null) {
                $pet = Pet::whereKey($joinPetId)->lockForUpdate()->first();
                if ($pet === null || $pet->family_id !== $family->id || ! $pet->is_active || $pet->is_game_over) {
                    throw new PairingException('This pet can no longer get another caretaker.');
                }

                $this->addCaretakerOrFail($pet, $child);
                $joined = true;
            } else {
                $pet = $this->createPet($family->id, $child);
                $joined = false;
            }

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

    private function createPet(int $familyId, User $child): Pet
    {
        // Pet DNA (seed, prompt anchor, visual traits) is generated offline.
        // The reference image is produced asynchronously by a queued job
        // dispatched after this transaction commits — never call fal.ai here.
        $breed = BreedType::Mutt; // Free tier default
        $petDna = $this->falAiService->generateInitialPetDna($breed);
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
        ]);

        // Pet::created already added the caretaker row; this is a no-op
        // that keeps the invariant explicit.
        $this->addCaretakerOrFail($pet, $child);

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
