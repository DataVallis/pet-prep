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
        public ?CarbonImmutable $nextStart,
        public ?CarbonImmutable $nextEnd,
        public ?CarbonImmutable $lastFedAt,
    ) {}

    /** Inside a window that has not been used yet (game rules only, not locks). */
    public function windowOpen(): bool
    {
        return $this->currentStart !== null && ! $this->fedInCurrent;
    }
}
