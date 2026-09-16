<?php

namespace App\Services;

use App\Enums\BreedType;
use App\Enums\UserRole;
use App\Models\BreedConfig;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
     * @return array{pin: string, expires_at: \Illuminate\Support\Carbon}
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
     * @throws \App\Exceptions\PairingException
     */
    public function pairChild(string $pin, User $child): array
    {
        return DB::transaction(function () use ($pin, $child) {
            $parent = User::where('pairing_pin', $pin)
                ->where('pin_expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (! $parent) {
                throw new \App\Exceptions\PairingException('Invalid or expired pairing PIN.');
            }

            if (! $parent->isParent()) {
                throw new \App\Exceptions\PairingException('The PIN does not belong to a parent profile.');
            }

            // Ensure the child is not already paired
            if ($child->parent_id !== null) {
                throw new \App\Exceptions\PairingException('This child profile is already paired to a parent.');
            }

            // Link child to parent
            $child->update([
                'parent_id' => $parent->id,
                'role' => UserRole::Child->value,
            ]);

            // Generate the pet's unique visual identity (Pet DNA) via fal.ai.
            // This creates a fixed seed, prompt anchor, visual traits, and
            // a canonical reference image used for all future video generation.
            // In dev/testing where fal.ai is disabled, pet_dna will have
            // seed + prompt_anchor but no reference_image_url.
            $breed = BreedType::Mutt; // Free tier default
            $petDna = $this->falAiService->generateInitialPetDna($breed);

            // Initialize the child's pet session with Pet DNA
            $pet = Pet::create([
                'user_id' => $child->id,
                'breed_type' => $breed->value,
                'pet_dna' => $petDna,
                'hunger_level' => 100,
                'energy_level' => 100,
                'hygiene_level' => 100,
                'born_at' => now(),
                'is_active' => true,
            ]);

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
