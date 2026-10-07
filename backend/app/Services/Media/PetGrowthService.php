<?php

namespace App\Services\Media;

use App\Models\Pet;
use App\Models\PetMedia;
use App\Models\PetMediaHistory;
use App\Services\LifeStageService;
use Carbon\CarbonImmutable;

/**
 * Growth album (M5-R04 part 2, PRODUCT_SPEC §10, REALISM_SPEC §2): the pet's
 * reference images across life stages.
 *
 * Sources: `pet_media_history` (images archived at a stage change, M5-R01 —
 * {@see PetMediaService::startStageTransition()}) and the current image slot
 * in `pet_media`. Rules:
 *  - oldest first (history by generation), the current image last;
 *  - only images whose file exists on the pet-media disk — a failed or still
 *    running generation is never an entry;
 *  - while the next stage's image is pending (or failed), the slot still
 *    serves the archived file: that history entry is the current one (no
 *    duplicate);
 *  - age_months = the dog's age when the picture was stored
 *    ({@see LifeStageService::ageMonthsAt()}), null for a legacy pet.
 *
 * URLs are the same signed capabilities as the pet's media
 * (GET /api/media/{id} and GET /api/media/history/{id}); authorization of the
 * caller happens in the controller (PetPolicy).
 */
class PetGrowthService
{
    public function __construct(
        private readonly PetMediaService $media,
        private readonly LifeStageService $stages,
    ) {}

    public function albumFor(Pet $pet, ?CarbonImmutable $expires = null): PetGrowthPayload
    {
        $expires ??= $this->media->urlExpiry();
        $disk = $this->media->disk();

        $slot = PetMedia::query()->images()->where('pet_id', $pet->id)->first();
        $currentPath = $slot?->isServable() ? (string) $slot->storage_path : null;

        $entries = [];
        $currentInHistory = false;

        $history = PetMediaHistory::query()
            ->where('pet_id', $pet->id)
            ->where('kind', PetMedia::KIND_IMAGE)
            ->orderBy('generation')
            ->orderBy('id')
            ->get();

        foreach ($history as $row) {
            if (! $disk->exists($row->storage_path)) {
                continue;
            }

            $isCurrent = $currentPath !== null && $row->storage_path === $currentPath;
            $currentInHistory = $currentInHistory || $isCurrent;
            $takenAt = $row->taken_at !== null
                ? CarbonImmutable::instance($row->taken_at)
                : $this->media->fileModifiedAt($row->storage_path);

            $entries[] = $this->entry(
                $pet,
                $row->generation,
                $row->life_stage,
                $takenAt,
                $isCurrent,
                $this->media->signedHistoryUrl($row, $expires),
            );
        }

        if ($slot !== null && $currentPath !== null && ! $currentInHistory && $disk->exists($currentPath)) {
            $entries[] = $this->entry(
                $pet,
                $slot->generation,
                $slot->life_stage,
                $this->media->storedAt($slot),
                true,
                $this->media->signedUrl($slot, $expires),
            );
        }

        return new PetGrowthPayload(
            petId: $pet->id,
            entries: $entries,
            expiresAt: $entries === [] ? null : $expires->toIso8601String(),
        );
    }

    /**
     * @return array{generation: int, life_stage: string|null, age_months: int|null, taken_at: string|null, is_current: bool, image_url: string}
     */
    private function entry(Pet $pet, int $generation, ?string $stage, ?CarbonImmutable $takenAt, bool $isCurrent, string $url): array
    {
        return [
            'generation' => $generation,
            // A legacy pet has no life stages (its slot may still carry one from a backfill).
            'life_stage' => $pet->isLegacyProfile() ? null : $stage,
            'age_months' => $takenAt !== null ? $this->stages->ageMonthsAt($pet, $takenAt) : null,
            'taken_at' => $takenAt?->utc()->toIso8601String(),
            'is_current' => $isCurrent,
            'image_url' => $url,
        ];
    }
}
