<?php

namespace App\Services\Results;

use App\Enums\LifeStage;

/**
 * The care rules of one pet on one family-local day (M5-R01,
 * LifeStageService::rulesOn). Rules switch at the family-local midnight:
 * they follow the dog's age at the START of the day.
 *
 * Without life-stage data for the breed or for a legacy-profile pet
 * (`$lifeStage` null; `$ageMonths` null for legacy) these are the pre-M5
 * rules: the breed's feed windows and daily_steps_required.
 */
final readonly class StageRules
{
    /**
     * @param  list<array{0: string, 1: string}>  $feedWindows  family-local [start, end) "HH:MM"
     * @param  array{0: float|int, 1: float|int}|null  $sleepHours
     * @param  array<string, array{source_id: string|null, verified: bool, confidence: string}>  $provenance  used key → provenance
     */
    public function __construct(
        public string $date,
        public ?int $ageMonths,
        public ?LifeStage $lifeStage,
        public int $mealsPerDay,
        public array $feedWindows,
        public ?int $exerciseMinutes,
        public int $stepGoal,
        public ?array $sleepHours,
        public array $provenance,
    ) {}

    /** True when every value these rules use is backed by a source / David decision. */
    public function verified(): bool
    {
        foreach ($this->provenance as $p) {
            if (! $p['verified']) {
                return false;
            }
        }

        return $this->lifeStage !== null;
    }

    /**
     * Keys whose value is an UNSOURCED proposal.
     *
     * @return list<string>
     */
    public function unverifiedKeys(): array
    {
        return array_keys(array_filter($this->provenance, fn (array $p) => ! $p['verified']));
    }
}
