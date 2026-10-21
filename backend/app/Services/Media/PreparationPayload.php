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
    ) {}

    /**
     * @param  Collection<int, PetMedia>|null  $slots  the pet's media rows when already loaded
     */
    public static function for(Pet $pet, ?Collection $slots = null): self
    {
        return app(PetPreparationService::class)->summary($pet, $slots);
    }

    /**
     * @return array{ready: bool, pending: list<string>, preparing: bool, running: bool, ready_at: string|null, announce: bool}
     */
    public function toArray(): array
    {
        return [
            // Everything the pet shows is stored (or nothing will come). Turns true with the
            // `video_ready` PetUpdated of the last item (then `pet_ready` once, if `running`).
            'ready' => $this->ready,
            /**
             * What is not stored yet, in the order it is made: `image` (the pose), then video
             * states (idle, sleeping, hungry, playing, …), transitions, then (M5-T09 / T10) the school
             * and game phase clips. Empty when ready.
             *
             * @var list<'image'|'idle'|'sleeping'|'low_energy'|'hungry'|'sick'|'playing'|'accident'|'chewing'|'scratching'|'idle_to_sleeping'|'sleeping_to_idle'|'school_sit_attention'|'school_sit_performing'|'school_sit_success'|'school_come_attention'|'school_come_performing'|'school_come_success'|'school_place_attention'|'school_place_performing'|'school_place_success'|'school_potty_attention'|'school_potty_performing'|'school_potty_success'|'ball_waiting'|'ball_running'|'ball_fetching'|'wand_stalking'|'wand_pouncing'|'wand_catching'>
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
        ];
    }
}
