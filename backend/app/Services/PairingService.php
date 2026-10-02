<?php

namespace App\Services;

use App\Enums\BreedType;
use App\Enums\UserRole;
use App\Exceptions\PairingException;
use App\Jobs\GeneratePetReferenceImage;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PairingService
{
    public function __construct(
        private readonly FalAiService $falAiService,
    ) {}

    /**
     * The PIN expiration window in minutes.
     */
    public const PIN_EXPIRY_MINUTES = 15;

    /**
     * Generate a unique 6-digit pairing PIN for a parent user.
     * Sets an expiration timestamp (15 minutes from now).
     *
     * @return array{pin: string, expires_at: Carbon}
     */
    public function generatePin(User $parent): array
    {
        if (! $parent->isParent()) {
            throw new \DomainException('Only parent profiles can generate pairing PINs.');
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

        $parent->update([
            'pairing_pin' => $pin,
            'pin_expires_at' => $expiresAt,
        ]);

        return [
            'pin' => $pin,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Pair a child user to a parent using a 6-digit PIN.
     * Runs inside an atomic DB transaction: sets parent_id on the child
     * and initializes the child's pet session (Mutt by default).
     *
     * @return array{parent: User, pet: Pet}
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

            // Ensure the child is not already paired
            if ($child->parent_id !== null) {
                throw new PairingException('This child profile is already paired to a parent.');
            }

            // Link child to parent
            $child->update([
                'parent_id' => $parent->id,
                'role' => UserRole::Child->value,
            ]);

            // Pet DNA (seed, prompt anchor, visual traits) is generated offline.
            // The reference image is produced asynchronously by a queued job
            // dispatched after this transaction commits — never call fal.ai here.
            $breed = BreedType::Mutt; // Free tier default
            $petDna = $this->falAiService->generateInitialPetDna($breed);
            $mediaEnabled = $this->falAiService->isEnabled();

            $pet = Pet::create([
                'user_id' => $child->id,
                'breed_type' => $breed->value,
                'pet_dna' => $petDna,
                'media_status' => $mediaEnabled ? 'pending' : 'disabled',
                'hunger_level' => 100,
                'energy_level' => 100,
                'hygiene_level' => 100,
                'born_at' => now(),
                'is_active' => true,
            ]);

            if ($mediaEnabled) {
                GeneratePetReferenceImage::dispatch($pet->id)->afterCommit();
            }

            // Consume the PIN so it cannot be reused
            $parent->update([
                'pairing_pin' => null,
                'pin_expires_at' => null,
            ]);

            return [
                'parent' => $parent->fresh(),
                'pet' => $pet,
            ];
        });
    }
}
