<?php

namespace App\Services\Media;

use App\Models\Pet;
use App\Models\PetMedia;
use Illuminate\Support\Collection;

/**
 * The `preparation` object of a pet in the child state, the parent dashboard
 * and PetUpdated (M5-T06, APP_REFINEMENT_SPEC §13). Typed for Scramble.
 * Neutral wording only — the apps never say that media are generated.
 */
final class PreparationPayload
{
    /**
     * @param  list<string>  $pending
     */
    public function __construct(
        public readonly bool $ready,
        public readonly array $pending,
        public readonly bool $preparing,
        public readonly bool $running,
        public readonly ?string $readyAt,
        public readonly bool $announce = false,
        public readonly bool $schoolPending = false,
    ) {}

    /**
     * @param  Collection<int, PetMedia>|null  $slots  the pet's media rows when already loaded
     */
    public static function for(Pet $pet, ?Collection $slots = null): self
    {
        return app(PetPreparationService::class)->summary($pet, $slots);
    }

    /**
     * @return array{ready: bool, pending: list<string>, preparing: bool, running: bool, ready_at: string|null, announce: bool, school_pending: bool}
     */
    public function toArray(): array
    {
        return [
            // Everything the pet shows is stored (or nothing will come). Turns true with the
            // `video_ready` PetUpdated of the last item (then `pet_ready` once, if `running`).
            'ready' => $this->ready,
            /**
             * What is not stored yet, in the order it is made: `image` (the pose), then video
             * states (idle, sleeping, hungry, playing, …), then the transitions. Empty when ready.
             * Never the school / game phase clips (QA m6): games report `media_ready`, the school
             * `school_pending`.
             *
             * @var list<'image'|'idle'|'sleeping'|'low_energy'|'hungry'|'sick'|'playing'|'accident'|'chewing'|'scratching'|'idle_to_sleeping'|'sleeping_to_idle'>
             */
            'pending' => $this->pending,
            // Show »Pripravljamo [ime] … to lahko traja nekaj minut«: the pet's preparation runs
            // (started ≤ 15 min ago) and the pose or a first-minutes clip (idle, sleeping,
            // hungry) is still on its way (never for a failed item).
            'preparing' => $this->preparing,
            // The pet's preparation runs (started at birth / purchase, not finished). Goes
            // true → false exactly once, with `ready` true (news: see `announce`)
            // (PetUpdated `pet_ready` + one push to the caretaker children).
            'running' => $this->running,
            // When the shown preparation ended (ISO 8601, UTC); null when it never ran or still runs.
            'ready_at' => $this->readyAt,
            // The end is news (QA m1/m4, server decides): the preparation was seen (≥ 10 s) and
            // ended ≤ 2 h after it started. The child app then says »Vse je pripravljeno: Luna«
            // once per pet (within 12 h of `ready_at`); the same rule sends `pet_ready` + push.
            'announce' => $this->announce,
            // M5-T09 (QA m6): a school phase clip of the pet is still on its way (not stored, not
            // failed) — the app shows the poses and »Šola se še pripravlja«; practice works.
            // Independent of `running`: the school clips come after the preparation ended.
            'school_pending' => $this->schoolPending,
        ];
    }
}
