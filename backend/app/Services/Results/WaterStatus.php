<?php

namespace App\Services\Results;

use Carbon\CarbonImmutable;

/**
 * Water refills of a pet at one instant (CareScheduleService). Instants are
 * in the family timezone.
 */
final readonly class WaterStatus
{
    public function __construct(
        public int $timesPerDay,
        public int $minGapMinutes,
        public int $usedToday,
        public ?CarbonImmutable $lastAt,
        /** Earliest instant a refill is allowed again; null when allowed now (or never possible). */
        public ?CarbonImmutable $nextAllowedAt,
        public bool $limitReached,
        public bool $tooSoon,
    ) {}

    public function remainingToday(): int
    {
        return max(0, $this->timesPerDay - $this->usedToday);
    }

    /** Game rules allow a refill now (not counting locks). */
    public function allowedNow(): bool
    {
        return ! $this->limitReached && ! $this->tooSoon;
    }
}
