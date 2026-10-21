<?php

namespace App\Services;

use App\Enums\StageParamKey;
use App\Models\Pet;
use App\Models\QuietHours;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The `care_guide` object of the child state (M5-T05, APP_REFINEMENT_SPEC §5):
 * the numbers behind the card "Kako skrbim za …" / "How I care for …".
 *
 * Structured data only (numbers + keys, never prose): the app renders the
 * localized sentences. Every number is read from the same place the
 * simulation reads it, so the guide can never drift from the rules:
 * - meals: today's feed windows of THIS pet (PetProfilePayload →
 *   CareScheduleService::feedWindowsStartingOn, incl. parent-covered meals);
 * - steps: today's stage goal (LifeStageService rules, the energy goal);
 * - take-out: the puppy's hold hours today (BehaviourEventService::holdHoursOn);
 * - water: the breed's refill threshold + gap (CareScheduleService / breed_configs);
 * - grooming: groomings per program week (CatChoreService::groomingGoalOn);
 * - sleep: the family's quiet hours (= when the pet sleeps, PetSleepService);
 * - cat: wand sessions + gap (WandPlayService), litter scoop deadline and
 *   change days (LitterService), scratching after a missed play day
 *   (ScratchingService);
 * - messes: the 2 h cleaning deadline (RoutineLedgerService::CLEAN_WITHIN_SECONDS);
 * - digestion: the breed's meal / exercise gap (CareScheduleService::digestingUntil source).
 *
 * Pet facts only — no personal data. Null blocks = the rule does not apply.
 */
final class CareGuidePayload
{
    /**
     * @param  array{per_day: int, by_child: int, by_parent: int, windows: list<array{start: string, end: string, parent_covered: bool}>}  $meals
     * @param  array{goal: int, exercise_minutes: int|null}|null  $steps
     * @param  array{hold_hours: int}|null  $takeOut
     * @param  array{refill_below: int, min_gap_minutes: int}|null  $water
     * @param  array{per_week: int}|null  $grooming
     * @param  array{night: array{start: string, end: string}|null, school: array{start: string, end: string}|null}  $sleep
     * @param  array{sessions_per_day: int, min_gap_minutes: int}|null  $play
     * @param  array{scoop_within_hours: float|int, change_every_days: int}|null  $litter
     */
    public function __construct(
        public readonly string $species,
        public readonly ?string $lifeStage,
        public readonly array $meals,
        public readonly ?array $steps,
        public readonly ?array $takeOut,
        public readonly ?array $water,
        public readonly ?array $grooming,
        public readonly array $sleep,
        public readonly ?array $play,
        public readonly ?array $litter,
        public readonly bool $scratching,
        public readonly int $cleanWithinHours,
        public readonly ?int $digestionGapMinutes,
    ) {}

    public static function for(Pet $pet, ?CarbonInterface $now = null, ?PetProfilePayload $profile = null): self
    {
        $now = CarbonImmutable::instance($now ?? now());
        $profile ??= PetProfilePayload::for($pet, $now);
        $today = $profile->date;
        // The profile carries the rows it was built from (no second query, same values).
        $loaded = $profile->quietHours !== null;
        $config = $loaded ? $profile->breedConfig : $pet->breedConfig();
        $cat = $pet->isCat();
        $lifeStages = app(LifeStageService::class);

        $holdHours = $cat ? null : app(BehaviourEventService::class)->holdHoursOn($pet, $today);
        $groomingGoal = app(CatChoreService::class)->groomingGoalOn($pet, $today);

        $play = null;
        $litter = null;
        $scratching = false;
        if ($cat) {
            $wand = app(WandPlayService::class);
            $goal = $wand->goalOn($pet, $today);
            $play = $goal > 0 ? ['sessions_per_day' => $goal, 'min_gap_minutes' => $wand->minGapMinutes($pet, $today)] : null;
            $litterService = app(LitterService::class);
            $scoop = $litterService->scoopDeadlineHours($pet, $today);
            $changeDays = $litterService->changeDays($pet, $today);
            $litter = $litterService->appliesTo($pet) && ($scoop > 0 || $changeDays > 0)
                ? ['scoop_within_hours' => self::number($scoop), 'change_every_days' => $changeDays]
                : null;
            $scratching = app(ScratchingService::class)->appliesOn($pet, $today);
        }

        $gap = $cat ? null : ($lifeStages->stageValueOn($pet, $today, StageParamKey::MealExerciseGapMinutes)['value'] ?? null);

        return new self(
            species: $pet->speciesValue()->value,
            lifeStage: $profile->lifeStage,
            meals: [
                'per_day' => $profile->mealsPerDay,
                'by_child' => $profile->mealsByChild,
                'by_parent' => $profile->mealsByParent,
                'windows' => array_map(
                    fn (array $w): array => ['start' => $w['start'], 'end' => $w['end'], 'parent_covered' => $w['parent_covered']],
                    $profile->feedWindows,
                ),
            ],
            // A cat has no steps (its energy is the wand play meter).
            steps: $cat ? null : ['goal' => $profile->stepGoal, 'exercise_minutes' => $profile->exerciseMinutes],
            takeOut: $holdHours !== null ? ['hold_hours' => $holdHours] : null,
            water: $config !== null ? [
                'refill_below' => CareScheduleService::refillBelow($config),
                'min_gap_minutes' => max(0, (int) $config->water_min_gap_minutes),
            ] : null,
            grooming: $groomingGoal > 0 ? ['per_week' => $groomingGoal] : null,
            sleep: self::sleepOf($profile->quietHours ?? $pet->quietHours()),
            play: $play,
            litter: $litter,
            scratching: $scratching,
            cleanWithinHours: intdiv(RoutineLedgerService::CLEAN_WITHIN_SECONDS, 3600),
            digestionGapMinutes: is_int($gap) && $gap > 0 ? $gap : null,
        );
    }

    /**
     * @return array{night: array{start: string, end: string}|null, school: array{start: string, end: string}|null}
     */
    private static function sleepOf(QuietHours $quiet): array
    {
        $window = function (?string $start, ?string $end) use ($quiet): ?array {
            if (! $quiet->is_active || ! $start || ! $end) {
                return null;
            }

            return ['start' => substr($start, 0, 5), 'end' => substr($end, 0, 5)];
        };

        return [
            'night' => $window($quiet->bedtime_start, $quiet->bedtime_end),
            'school' => $window($quiet->school_start, $quiet->school_end),
        ];
    }

    private static function number(float $value): float|int
    {
        return floor($value) === $value ? (int) $value : $value;
    }

    /**
     * @return array{species: 'dog'|'cat', life_stage: 'puppy'|'young'|'adult'|'senior'|null, meals: array{per_day: int, by_child: int, by_parent: int, windows: list<array{start: string, end: string, parent_covered: bool}>}, steps: array{goal: int, exercise_minutes: int|null}|null, take_out: array{hold_hours: int}|null, water: array{refill_below: int, min_gap_minutes: int}|null, grooming: array{per_week: int}|null, sleep: array{night: array{start: string, end: string}|null, school: array{start: string, end: string}|null}, play: array{sessions_per_day: int, min_gap_minutes: int}|null, litter: array{scoop_within_hours: float|int, change_every_days: int}|null, scratching: bool, clean_within_hours: int, digestion_gap_minutes: int|null}
     */
    public function toArray(): array
    {
        return [
            /** @var 'dog'|'cat' */
            'species' => $this->species,
            /** @var 'puppy'|'young'|'adult'|'senior'|null */
            'life_stage' => $this->lifeStage,
            /**
             * Today's meals of THIS pet: count, who gives them (quiet-hour meals are the
             * parent's) and the family-local windows "HH:MM" [start, end).
             *
             * @var array{per_day: int, by_child: int, by_parent: int, windows: list<array{start: string, end: string, parent_covered: bool}>}
             */
            'meals' => $this->meals,
            /**
             * Dog: today's step goal of its life stage (100 % energy) and the sourced
             * exercise minutes; null for a cat.
             *
             * @var array{goal: int, exercise_minutes: int|null}|null
             */
            'steps' => $this->steps,
            /**
             * Puppy with behaviour events: hours it holds today ("Pelji ven" before they run
             * out; steps never reset it); null otherwise.
             *
             * @var array{hold_hours: int}|null
             */
            'take_out' => $this->takeOut,
            /**
             * Water rule (M5-F11 "dolij, ko se prazni"): refill when thirst shows ≤
             * `refill_below` %, at least `min_gap_minutes` apart.
             *
             * @var array{refill_below: int, min_gap_minutes: int}|null
             */
            'water' => $this->water,
            /**
             * Groomings per program week (brushed cat / brushed dog); null without a routine.
             *
             * @var array{per_week: int}|null
             */
            'grooming' => $this->grooming,
            /**
             * The family's quiet hours = when the pet sleeps (family-local "HH:MM"); a
             * window is null when not set or quiet hours are off.
             *
             * @var array{night: array{start: string, end: string}|null, school: array{start: string, end: string}|null}
             */
            'sleep' => $this->sleep,
            /**
             * Cat: successful wand sessions per day and the gap between them; null for a dog.
             *
             * @var array{sessions_per_day: int, min_gap_minutes: int}|null
             */
            'play' => $this->play,
            /**
             * Cat: scoop within this many hours of a use, change all litter every N days; null for a dog.
             *
             * @var array{scoop_within_hours: float|int, change_every_days: int}|null
             */
            'litter' => $this->litter,
            /**
             * Cat: a missed play day leads to a scratched sofa (take it to the scratching post).
             *
             * @var bool
             */
            'scratching' => $this->scratching,
            /**
             * A mess must be cleaned within this many hours (outside quiet hours).
             *
             * @var int
             */
            'clean_within_hours' => $this->cleanWithinHours,
            /**
             * Dog breeds with a meal / exercise gap (bloat): wait this long after a meal
             * before the walk; null otherwise.
             *
             * @var int|null
             */
            'digestion_gap_minutes' => $this->digestionGapMinutes,
        ];
    }
}
