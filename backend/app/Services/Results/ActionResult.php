<?php

namespace App\Services\Results;

use App\Enums\CareRefusal;
use App\Enums\PetLockReason;
use Carbon\CarbonInterface;

/**
 * Outcome of a child action handled by PetActivityService. The HTTP layer
 * (ChildPetController, M1-07) maps it to a status code:
 * Locked → 423, Refused → 422 (409 for a duplicate contract), the rest → 200.
 */
final readonly class ActionResult
{
    /** The full increment / action was applied. */
    public const ACCEPTED = 'accepted';

    /** Steps: only part of the increment was plausible (≤ 200 steps / min); the rest was refused. */
    public const CAPPED = 'capped';

    /** Steps: an increment was reported but none of it was plausible. */
    public const REJECTED = 'rejected';

    /** Nothing to do (same or lower step count, already clean). Idempotent repeat. */
    public const UNCHANGED = 'unchanged';

    /** Steps: reported for a family-local day that is already over. */
    public const STALE = 'stale';

    /** Pet hard-stopped, ill, inactive or game over — nothing was changed. See $lockReason. */
    public const LOCKED = 'locked';

    /** A game rule forbids the action right now (feed window, water limit …). See $refusal. */
    public const REFUSED = 'refused';

    public function __construct(
        public string $status,
        public int $acceptedSteps = 0,
        public int $dailyStepCount = 0,
        public int $energyLevel = 0,
        public int $hygieneLevel = 0,
        public ?PetLockReason $lockReason = null,
        public ?CareRefusal $refusal = null,
        public ?CarbonInterface $nextAllowedAt = null,
    ) {}

    public function changed(): bool
    {
        return in_array($this->status, [self::ACCEPTED, self::CAPPED], true);
    }
}
