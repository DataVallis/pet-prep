<?php

namespace App\Enums;

/**
 * A transition clip between two state poses (M5-T08, APP_REFINEMENT_SPEC §7):
 * played ONCE when the pet's state changes from `fromState()` to `toState()`, then the
 * target state's loop. Start frame = key image of `fromState()` (idle = the
 * reference image), end frame = key image of `toState()`.
 *
 * Mirrored in the pet_media_state_check CHECK constraint (kind = transition).
 * Which transitions a pet gets: MediaEntitlementService::transitionsFor()
 * (config media.transitions). Extension point: M5-T10 game phases add cases
 * the same way (+ a CHECK migration).
 */
enum PetTransition: string
{
    case IdleToSleeping = 'idle_to_sleeping';
    case SleepingToIdle = 'sleeping_to_idle';

    public function fromState(): PetStateEnum
    {
        return match ($this) {
            self::IdleToSleeping => PetStateEnum::Idle,
            self::SleepingToIdle => PetStateEnum::Sleeping,
        };
    }

    public function toState(): PetStateEnum
    {
        return match ($this) {
            self::IdleToSleeping => PetStateEnum::Sleeping,
            self::SleepingToIdle => PetStateEnum::Idle,
        };
    }

    /** What the animal does between the two poses (video prompt; no people, no text). */
    public function motion(Species $species): string
    {
        $noun = $species === Species::Cat ? 'cat' : 'dog';

        return match ($this) {
            self::IdleToSleeping => $species === Species::Cat
                ? 'slowly lies down, curls up into a ball and falls asleep, closing its eyes'
                : 'slowly lies down on the floor, curls up and falls asleep, closing its eyes',
            self::SleepingToIdle => "slowly wakes up, opens its eyes, stretches and gets up, ending calm and alert like the {$noun} in the end frame",
        };
    }
}
