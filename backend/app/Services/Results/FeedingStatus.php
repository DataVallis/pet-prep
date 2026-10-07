<?php

namespace App\Services\Results;

use Carbon\CarbonImmutable;

/**
 * Feeding windows of a pet at one instant (CareScheduleService). All
 * instants are in the family timezone.
 */
final readonly class FeedingStatus
{
    /**
     * @param  list<array{0: string, 1: string}>  $windows  Configured family-local [start, end) "HH:MM" pairs.
     */
    public function __construct(
        public array $windows,
        public ?CarbonImmutable $currentStart,
        public ?CarbonImmutable $currentEnd,
        public bool $fedInCurrent,
        /** Current unused window, else the next window the CHILD feeds (parent-covered skipped, M3-12). */
        public ?CarbonImmutable $nextStart,
        public ?CarbonImmutable $nextEnd,
        public ?CarbonImmutable $lastFedAt,
        /**
         * M3-12 rule A: the most recent CHILD window (parent-covered skipped)
         * that has already ended started after the birth, and nothing (child or
         * parent meal) was fed since its start. No ended window since the birth = not missed (a newborn starts
         * at 100 % and reaches its first window long before 20 %).
         */
        public bool $missedMeal = false,
    ) {}

    /** The emergency meal's precondition (hunger aside) holds — CareScheduleService::feedCheck. */
    public function emergencyPossible(): bool
    {
        return $this->missedMeal && ! $this->windowOpen();
    }

    /** Inside a window that has not been used yet (game rules only, not locks). */
    public function windowOpen(): bool
    {
        return $this->currentStart !== null && ! $this->fedInCurrent;
    }
}
