<?php

namespace App\Services\Media;

use App\Models\Pet;
use App\Models\PetMedia;
use Illuminate\Support\Collection;

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
        /** @var array<string, string> M5-T08: state => signed URL of its stored key image (never idle) */
        public readonly array $keyframes = [],
        /** @var array<string, string> M5-T08: transition (idle_to_sleeping, …) => signed URL */
        public readonly array $transitions = [],
        /** @var list<string> M5-T08: states whose stored video is a seamless loop */
        public readonly array $loops = [],
        /** M5-T08: the pet gets key images / loops / transitions (MediaEntitlementService::usesStateMedia) */
        public readonly bool $stateMedia = false,
    ) {}

    /**
     * The payload for a pet (resolves PetMediaService from the container).
     *
     * @param  Collection<int, PetMedia>|null  $slots  the pet's media rows when already loaded (M5-T06: one read for media + preparation)
     */
    public static function for(Pet $pet, ?Collection $slots = null): self
    {
        return app(PetMediaService::class)->mediaFor($pet, $slots);
    }

    /**
     * @return array{status: string, reference_image_url: string|null, videos: object, current_video_url: string|null, states: list<string>, expires_at: string|null, keyframes: object, transitions: object, loops: list<string>, state_media: bool}
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
             * Video states this pet is entitled to (basic: idle + sleeping; full: all six +
             * M5-R02 behaviour videos accident (non-legacy puppy) / chewing (non-legacy dog);
             * M5-R06-07: a cat's full set has scratching (non-legacy cat), never accident / chewing).
             *
             * @var list<'idle'|'sleeping'|'low_energy'|'hungry'|'sick'|'playing'|'accident'|'chewing'|'scratching'>
             */
            'states' => $this->states,
            // When the URLs above stop working (ISO 8601); fetch the state again before.
            'expires_at' => $this->expiresAt,
            /**
             * M5-T08: signed URL of the stored KEY IMAGE per state (the pose the state's
             * loop starts and ends in). Never `idle` — the reference image is the idle pose.
             * Shown while a state's clip is missing and in picture mode (M5-T11). Always an object.
             *
             * @var array<string, string>
             */
            'keyframes' => (object) $this->keyframes,
            /**
             * M5-T08: signed URL per stored TRANSITION clip, keyed `<from>_to_<to>`
             * (idle_to_sleeping, sleeping_to_idle). Played once on that state change,
             * then the target state's loop. Always an object.
             *
             * @var array<string, string>
             */
            'transitions' => (object) $this->transitions,
            /**
             * M5-T08: states whose stored video starts and ends on its key image (loops
             * without a visible restart); other videos are older clips.
             *
             * @var list<string>
             */
            'loops' => $this->loops,
            /**
             * M5-T08: this pet's media follow the state rules — a missing clip shows the state's
             * key image (or the still reference image), never a clip of another state (§0 rule 1).
             * false = the older set (substitute clips sick → sleeping → idle).
             */
            'state_media' => $this->stateMedia,
        ];
    }
}
