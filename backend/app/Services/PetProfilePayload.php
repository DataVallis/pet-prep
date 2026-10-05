<?php

namespace App\Services;

use App\Models\Pet;
use App\Services\Results\StageRules;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The `profile` object of a pet in API responses (M5-R01): origin, age,
 * life stage and today's stage rules (meals and who covers them, step goal).
 * Typed so Scramble documents it (mobile/src/api/schema.ts). No personal
 * data — pet facts only.
 */
final class PetProfilePayload
{
    /**
     * @param  list<array{start: string, end: string, parent_covered: bool}>  $feedWindows
     * @param  array{min: float|int, max: float|int}|null  $sleepHours
     * @param  array{life_stage: string, from_date: string}|null  $nextStage
     * @param  list<string>  $unverified
     */
    public function __construct(
        public readonly string $origin,
        public readonly int $arrivalAgeMonths,
        public readonly int $ageMonths,
        public readonly ?string $lifeStage,
        public readonly ?array $nextStage,
        public readonly bool $dataVerified,
        public readonly array $unverified,
        public readonly string $date,
        public readonly int $mealsPerDay,
        public readonly int $mealsByParent,
        public readonly int $mealsByChild,
        public readonly array $feedWindows,
        public readonly int $stepGoal,
        public readonly ?int $exerciseMinutes,
        public readonly ?array $sleepHours,
    ) {}

    public static function for(Pet $pet, ?CarbonInterface $now = null): self
    {
        $now = CarbonImmutable::instance($now ?? now());
        $lifeStages = app(LifeStageService::class);
        $schedule = app(CareScheduleService::class);
        $config = $pet->breedConfig();
        $today = $pet->localDate($now);
        $rules = $config !== null
            ? $lifeStages->rulesOn($pet, $today, $config)
            : new StageRules($today, $lifeStages->ageMonthsOn($pet, $today), null, 0, [], null, 0, null, []);

        $quiet = $pet->quietHours();
        $windows = [];
        foreach ($config !== null ? $schedule->feedWindowsStartingOn($pet, $config, $today) : [] as [$start, $end]) {
            $windows[] = [
                'start' => $start->format('H:i'),
                'end' => $end->format('H:i'),
                'parent_covered' => $schedule->isParentCovered($quiet, $start, $end),
            ];
        }
        $byParent = count(array_filter($windows, fn (array $w) => $w['parent_covered']));
        $next = $lifeStages->nextTransition($pet, $now);
        $origin = $pet->origin;

        return new self(
            origin: $origin instanceof \BackedEnum ? $origin->value : (string) ($origin ?? 'bought'),
            arrivalAgeMonths: (int) $pet->arrival_age_months,
            ageMonths: $lifeStages->ageMonthsAt($pet, $now),
            lifeStage: $rules->lifeStage?->value,
            nextStage: $next !== null ? ['life_stage' => $next['stage']->value, 'from_date' => $next['date']] : null,
            dataVerified: $rules->verified(),
            unverified: $rules->unverifiedKeys(),
            date: $today,
            mealsPerDay: count($windows),
            mealsByParent: $byParent,
            mealsByChild: count($windows) - $byParent,
            feedWindows: $windows,
            stepGoal: $rules->stepGoal,
            exerciseMinutes: $rules->exerciseMinutes,
            sleepHours: $rules->sleepHours !== null ? ['min' => $rules->sleepHours[0], 'max' => $rules->sleepHours[1]] : null,
        );
    }

    /**
     * @return array{origin: string, arrival_age_months: int, age_months: int, life_stage: string|null, next_stage: array{life_stage: string, from_date: string}|null, data_verified: bool, unverified: list<string>, today: array{date: string, meals_per_day: int, meals_by_child: int, meals_by_parent: int, feed_windows: list<array{start: string, end: string, parent_covered: bool}>, step_goal: int, exercise_minutes: int|null, sleep_hours: array{min: float|int, max: float|int}|null}}
     */
    public function toArray(): array
    {
        return [
            /** @var 'bought'|'adopted' */
            'origin' => $this->origin,
            // Age in months when the dog came home (parent's choice: puppy 2, young 9, adult 36, senior 108 / 118).
            'arrival_age_months' => $this->arrivalAgeMonths,
            // The dog's age now: arrival age + one month per real week since birth (contract).
            'age_months' => $this->ageMonths,
            /**
             * Stage of today's rules (switches at the family-local midnight after the weekly birthday);
             * null for a breed without life-stage data (pre-M5 rules).
             *
             * @var 'puppy'|'young'|'adult'|'senior'|null
             */
            'life_stage' => $this->lifeStage,
            /**
             * The next stage and the family-local date its rules start; null for an unborn pet / a senior.
             *
             * @var array{life_stage: 'puppy'|'young'|'adult'|'senior', from_date: string}|null
             */
            'next_stage' => $this->nextStage,
            // True when every number of today's rules is backed by a source (docs/research/dog-data).
            'data_verified' => $this->dataVerified,
            /**
             * Rule keys whose value is a proposal (UNSOURCED) — never show these as facts.
             *
             * @var list<string>
             */
            'unverified' => $this->unverified,
            'today' => [
                // Family-local date (Y-m-d) these rules are for.
                'date' => $this->date,
                'meals_per_day' => $this->mealsPerDay,
                // Meals the child is expected to give (windows not covered by the parent).
                'meals_by_child' => $this->mealsByChild,
                // Meals in quiet hours (school / sleep): the parent feeds, never a child routine.
                'meals_by_parent' => $this->mealsByParent,
                /**
                 * Today's feed windows, family-local "HH:MM", [start, end).
                 *
                 * @var list<array{start: string, end: string, parent_covered: bool}>
                 */
                'feed_windows' => $this->feedWindows,
                // Steps for 100 % energy today (exercise minutes × 100 steps).
                'step_goal' => $this->stepGoal,
                'exercise_minutes' => $this->exerciseMinutes,
                /**
                 * Typical sleep hours per day at this age (sourced); null when no number exists.
                 *
                 * @var array{min: float|int, max: float|int}|null
                 */
                'sleep_hours' => $this->sleepHours,
            ],
        ];
    }

    /**
     * Small subset for broadcasts (PetUpdated).
     *
     * @return array{origin: string, age_months: int, life_stage: string|null}
     */
    public static function brief(Pet $pet): array
    {
        $lifeStages = app(LifeStageService::class);
        $origin = $pet->origin;
        $config = $pet->breedConfig();

        return [
            'origin' => $origin instanceof \BackedEnum ? $origin->value : (string) ($origin ?? 'bought'),
            'age_months' => $lifeStages->ageMonthsAt($pet, now()),
            'life_stage' => $config !== null ? $lifeStages->rulesAt($pet, now(), $config)->lifeStage?->value : null,
        ];
    }
}
