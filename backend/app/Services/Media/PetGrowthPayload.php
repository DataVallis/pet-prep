<?php

namespace App\Services\Media;

/**
 * The growth album of one pet (M5-R04 part 2): every reference image the pet
 * had across its life stages, oldest first, the current one last. Body of
 * GET /api/child/pet/growth and GET /api/parent/pets/{pet}/growth; also in
 * the parent's account export. Typed so Scramble documents it
 * (mobile/src/api/schema.ts).
 */
final class PetGrowthPayload
{
    /**
     * @param  list<array{generation: int, life_stage: string|null, age_months: int|null, taken_at: string|null, is_current: bool, image_url: string}>  $entries
     */
    public function __construct(
        public readonly int $petId,
        public readonly array $entries,
        public readonly ?string $expiresAt,
    ) {}

    /**
     * @return array{pet_id: int, growth: list<array{generation: int, life_stage: 'puppy'|'young'|'adult'|'senior'|null, age_months: int|null, taken_at: string|null, is_current: bool, image_url: string}>, expires_at: string|null}
     */
    public function toArray(): array
    {
        return [
            'pet_id' => $this->petId,
            /**
             * Pictures of the pet, oldest first; the pet's current picture is the
             * one with `is_current` (normally the last). Only stored images (failed
             * or missing generations are left out). A pet that never changed its
             * life stage — and every legacy pet — has exactly one entry; show an
             * album only from 2 entries on.
             *
             * - generation: image generation of the pet (unique per pet, ascending — use as key)
             * - life_stage: stage the picture shows; null for a legacy pet (no life stages)
             * - age_months: the dog's age in months when the picture was taken; null for a legacy pet
             * - taken_at: when the picture became the pet's image (ISO 8601, UTC)
             * - image_url: signed URL, valid until `expires_at`
             *
             * @var list<array{generation: int, life_stage: 'puppy'|'young'|'adult'|'senior'|null, age_months: int|null, taken_at: string|null, is_current: bool, image_url: string}>
             */
            'growth' => $this->entries,
            // When the image URLs stop working (ISO 8601); null when there is no picture.
            'expires_at' => $this->expiresAt,
        ];
    }
}
