<?php

namespace App\Services\Results;

use App\Enums\RoutineStatus;
use App\Enums\RoutineType;
use Carbon\CarbonImmutable;

/**
 * One expected routine of a pet (RoutineLedgerService, M2-06) — either a
 * stored row of a closed day or computed live. Instants are UTC.
 *
 * - `opensAt`: when the routine starts (feed window start, hygiene event,
 *   day start / birth for water and walk). A caretaker child shares the
 *   routine when they started caring at or before this instant.
 * - `dueAt`: the deadline (window end, event + 2 h outside quiet hours, end
 *   of the family-local day).
 * - `actorUserId`: the child who did it (activities_log.actor_user_id);
 *   for a walk the child whose step sync reached the goal.
 */
final readonly class Routine
{
    public function __construct(
        public int $petId,
        public string $localDate,
        public RoutineType $type,
        public int $slot,
        public CarbonImmutable $opensAt,
        public CarbonImmutable $dueAt,
        public RoutineStatus $status,
        public ?CarbonImmutable $doneAt = null,
        public ?int $actorUserId = null,
        public ?int $steps = null,
        public ?int $goal = null,
    ) {}

    public function isDone(): bool
    {
        return $this->status === RoutineStatus::Done;
    }

    public function isMissed(): bool
    {
        return $this->status === RoutineStatus::Missed;
    }

    public function isPending(): bool
    {
        return $this->status === RoutineStatus::Pending;
    }
}
