<?php

namespace App\Services\Media;

use App\Models\Pet;

/**
 * The `media` object of a pet in every API response and in PetUpdated (M4-05).
 * Typed so Scramble documents it (mobile/src/api/schema.ts).
 */
final class PetMediaPayload
{
    /**
     * @param  array<string, string>  $videos  state => signed URL, only stored videos
     * @param  list<string>  $states  entitled video states, idle first
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $referenceImageUrl,
        public readonly array $videos,
        public readonly ?string $currentVideoUrl,
        public readonly array $states,
        public readonly ?string $expiresAt,
    ) {}

    /**
     * The payload for a pet (resolves PetMediaService from the container).
     */
    public static function for(Pet $pet): self
    {
        return app(PetMediaService::class)->mediaFor($pet);
    }

    /**
     * @return array{status: string, reference_image_url: string|null, videos: object, current_video_url: string|null, states: list<string>, expires_at: string|null}
     */
    public function toArray(): array
    {
        return [
            /**
             * pending: reference image not stored yet · failed: no image (budget, fal error) ·
             * disabled: AI media off · partial: image stored, some videos missing · ready: all stored.
             *
             * @var 'disabled'|'pending'|'failed'|'partial'|'ready'
             */
            'status' => $this->status,
            // Signed URL of our stored reference image (null until stored).
            'reference_image_url' => $this->referenceImageUrl,
            /**
             * Signed URL per stored state video, keyed by pet state (idle, sleeping, …).
             * Always a JSON object ({} when empty).
             *
             * @var array<string, string>
             */
            'videos' => (object) $this->videos,
            // Video for the current pet_state, falling back to idle; null if none is stored.
            'current_video_url' => $this->currentVideoUrl,
            /**
             * Video states this pet is entitled to (basic: idle + sleeping; full: all six).
             *
             * @var list<'idle'|'sleeping'|'low_energy'|'hungry'|'sick'|'playing'>
             */
            'states' => $this->states,
            // When the URLs above stop working (ISO 8601); fetch the state again before.
            'expires_at' => $this->expiresAt,
        ];
    }
}
