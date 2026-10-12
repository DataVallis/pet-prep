<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\CareRefusal;
use App\Enums\SocialisationExperience;
use App\Enums\StageParamKey;
use App\Models\ActivityLog;
use App\Models\Pet;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * M5-R17 puppy socialisation (David Tacer 2026-10-12, "tedenska naloga"):
 * while the dog is a puppy (game life stage `puppy`, up to 9 months of game
 * age) the child gets one weekly "new experience" (»nova izkušnja«) — they
 * pick a new person, another dog, a new sound or a new place and mark it
 * done with one tap. One task per PROGRAM week (the 7-day period from one
 * weekly birthday to the next on the program clock, like the weekly
 * grooming — LifeStageService::programPeriodAt; payment-lock time does not
 * count).
 *
 * The number per week is the breed's puppy-stage `socialisation_tasks_per_week`
 * row (1, insert-only seeded for the breeds whose sources give socialisation
 * advice; no row = no task — the mutt, the Border Collie and the other
 * breeds without a sourced statement). The week's task exists when the age
 * the week starts with (arrival age + week index, 1 program week = 1 dog
 * month) falls in the puppy stage. Gated per pet: `Pet::socialisationEnabled()`
 * (a NEW profiled dog whose apps declared `socialisation`).
 *
 * NOT a routine: no `RoutineLedgerService` routine, no Care Score, no
 * traffic light, no metric, no penalty (David: »brez kazni«). A week without
 * the experience only shows as missed in the parent's report row. The
 * source of truth is the `socialised_pet` rows of `activities_log`
 * (value = SocialisationExperience::code()).
 */
class SocialisationService
{
    public const PERIOD_DAYS = 7;

    public function __construct(private readonly LifeStageService $lifeStages) {}

    /**
     * Tasks expected in program week $index (0 = the week from birth): the
     * breed's puppy-stage row at the age the week starts with. 0 = no task
     * (not enabled, unborn, not a puppy week, no breed row).
     */
    public function goalForWeek(Pet $pet, int $index): int
    {
        if ($index < 0 || $pet->isUnborn() || ! $pet->socialisationEnabled()) {
            return 0;
        }
        $age = (int) $pet->arrival_age_months + $index;
        $value = $this->lifeStages->stageValueAtAge($pet, $age, StageParamKey::SocialisationTasksPerWeek)['value'] ?? null;

        return is_int($value) && $value > 0 ? $value : 0;
    }

    /**
     * The program week containing $at when it has a task: index, start,
     * end (UTC) and its goal. Null = no task this week.
     *
     * @return array{index: int, start: CarbonImmutable, end: CarbonImmutable, goal: int}|null
     */
    public function weekAt(Pet $pet, CarbonInterface $at): ?array
    {
        if ($pet->isUnborn() || ! $pet->socialisationEnabled()) {
            return null;
        }
        $period = $this->lifeStages->programPeriodAt($pet, $at, self::PERIOD_DAYS);
        if ($period === null) {
            return null;
        }
        $goal = $this->goalForWeek($pet, $period['index']);

        return $goal > 0 ? $period + ['goal' => $goal] : null;
    }

    /**
     * `socialised_pet` rows of the pet in [$from, $to), oldest first.
     *
     * @return Collection<int, ActivityLog>
     */
    public function rowsBetween(Pet $pet, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return ActivityLog::where('pet_id', $pet->id)
            ->where('activity_type', ActivityType::SocialisedPet->value)
            ->where('created_at', '>=', CarbonImmutable::instance($from)->utc())
            ->where('created_at', '<', CarbonImmutable::instance($to)->utc())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Would recording a new experience now be refused, and until when?
     * Null = allowed. The caller adds the locks (423).
     *
     * @return array{refusal: CareRefusal, next_allowed_at: CarbonImmutable|null}|null
     */
    public function refusal(Pet $pet, CarbonInterface $now): ?array
    {
        $now = CarbonImmutable::instance($now)->utc();
        $week = $this->weekAt($pet, $now);
        if ($week === null) {
            return ['refusal' => CareRefusal::SocialisationNotAvailable, 'next_allowed_at' => null];
        }
        if ($this->rowsBetween($pet, $week['start'], $now->addSecond())->count() >= $week['goal']) {
            return ['refusal' => CareRefusal::SocialisationWeekDone, 'next_allowed_at' => $this->nextWeekStart($pet, $week)];
        }
        if (($quietEnd = $this->quietUntil($pet, $now)) !== null) {
            return ['refusal' => CareRefusal::SocialisationQuietHours, 'next_allowed_at' => $quietEnd];
        }

        return null;
    }

    /** The start of the next week when it has a task too (else null — the puppy grows up). */
    public function nextWeekStart(Pet $pet, array $week): ?CarbonImmutable
    {
        return $this->goalForWeek($pet, $week['index'] + 1) > 0 ? $week['end'] : null;
    }

    /** The end of the quiet stretch $now lies in (the puppy sleeps), or null. */
    public function quietUntil(Pet $pet, CarbonInterface $now): ?CarbonImmutable
    {
        $quiet = $pet->quietHours();
        $at = CarbonImmutable::instance($now)->utc();
        if (! $quiet->isQuietNow($at)) {
            return null;
        }
        // School and bedtime may touch: hop over adjoining quiet stretches.
        for ($hop = 0; $hop < 8 && $quiet->isQuietNow($at); $hop++) {
            $next = $quiet->nextBoundaryAfter($at);
            if ($next === null) {
                break;
            }
            $at = CarbonImmutable::instance($next)->utc();
        }

        return $at;
    }

    /**
     * Every program week with a task from the first one up to the week
     * containing $now (oldest first): its number (1 = the week from birth),
     * start / end, goal, the experiences recorded (with the acting child)
     * and its status — done (goal reached), open (the current week, not
     * yet done) or missed (over without it; shown, never scored).
     *
     * @return list<array{week: int, index: int, start: CarbonImmutable, end: CarbonImmutable, goal: int, done: int, status: 'done'|'open'|'missed', entries: list<array{experience: string|null, actor_user_id: int|null, at: CarbonImmutable}>}>
     */
    public function weeks(Pet $pet, CarbonInterface $now): array
    {
        $now = CarbonImmutable::instance($now)->utc();
        if ($pet->isUnborn() || ! $pet->socialisationEnabled()) {
            return [];
        }
        $period = $this->lifeStages->programPeriodAt($pet, $now, self::PERIOD_DAYS);
        if ($period === null) {
            return [];
        }

        // The task weeks (cheap: cached breed rows), then their periods — walking
        // back from the current week, one program period at a time.
        $indices = array_values(array_filter(range(0, $period['index']), fn (int $i) => $this->goalForWeek($pet, $i) > 0));
        if ($indices === []) {
            return [];
        }
        $periods = [];
        for ($p = $period; $p !== null && $p['index'] >= $indices[0];) {
            if (in_array($p['index'], $indices, true)) {
                $periods[] = $p + ['goal' => $this->goalForWeek($pet, $p['index'])];
            }
            $previous = $p['index'] > 0 ? $this->lifeStages->programPeriodAt($pet, $p['start']->subSecond(), self::PERIOD_DAYS) : null;
            // Guard: the walk must move back (never loop on an odd lock layout).
            $p = $previous !== null && $previous['index'] < $p['index'] ? $previous : null;
        }
        if ($periods === []) {
            return [];
        }
        $periods = array_reverse($periods);

        $rows = $this->rowsBetween($pet, $periods[0]['start'], $now->addSecond());
        $weeks = [];
        foreach ($periods as $p) {
            $in = $rows->filter(fn (ActivityLog $r) => $r->created_at->greaterThanOrEqualTo($p['start']) && $r->created_at->lessThan($p['end']))->values();
            $done = $in->count();
            $weeks[] = [
                'week' => $p['index'] + 1,
                'index' => $p['index'],
                'start' => $p['start'],
                'end' => $p['end'],
                'goal' => $p['goal'],
                'done' => min($done, $p['goal']),
                'status' => $done >= $p['goal'] ? 'done' : ($p['end']->lessThanOrEqualTo($now) ? 'missed' : 'open'),
                'entries' => $in->map(fn (ActivityLog $r) => [
                    'experience' => SocialisationExperience::fromCode($r->value !== null ? (int) $r->value : null)?->value,
                    'actor_user_id' => $r->actor_user_id !== null ? (int) $r->actor_user_id : null,
                    'at' => CarbonImmutable::instance($r->created_at)->utc(),
                ])->all(),
            ];
        }

        return $weeks;
    }

    /**
     * The parent report's weekly row (M5-R17) for one child over the
     * report period from the family-local date $fromDate: the task weeks that
     * end after the period starts. Null when no such week exists (no task
     * for this pet / breed, or none in the period). `done_by_child` = weeks
     * this child's experience completed; `missed` weeks change nothing in
     * the Care Score or the traffic light.
     *
     * @return array{goal_per_week: int, expected: int, done: int, done_by_child: int, missed: int, open: int, scored: false, weeks: list<array<string, mixed>>}|null
     */
    public function reportFor(Pet $pet, int $childId, string $fromDate, ?CarbonInterface $now = null): ?array
    {
        $now = CarbonImmutable::instance($now ?? now())->utc();
        $tz = $pet->familyTimezone();
        $fromUtc = CarbonImmutable::parse($fromDate, $tz)->startOfDay()->utc();
        $weeks = array_values(array_filter($this->weeks($pet, $now), fn (array $w) => $w['end']->greaterThan($fromUtc)));
        if ($weeks === []) {
            return null;
        }

        $local = fn (CarbonImmutable $t): string => $t->setTimezone($tz)->toIso8601String();
        $rows = array_map(function (array $w) use ($childId, $local): array {
            $mine = array_values(array_filter($w['entries'], fn (array $e) => $e['actor_user_id'] === $childId));

            return [
                'week' => $w['week'],
                'started_at' => $local($w['start']),
                'ends_at' => $local($w['end']),
                'goal' => $w['goal'],
                'done' => $w['done'],
                'status' => $w['status'],
                // The experiences of the week (any caretaker), oldest first.
                'experiences' => array_values(array_filter(array_map(fn (array $e) => $e['experience'], $w['entries']))),
                'by_child' => $mine !== [],
            ];
        }, $weeks);

        return [
            'goal_per_week' => $weeks[array_key_last($weeks)]['goal'],
            'expected' => count($weeks),
            'done' => count(array_filter($rows, fn (array $r) => $r['status'] === 'done')),
            'done_by_child' => count(array_filter($rows, fn (array $r) => $r['status'] === 'done' && $r['by_child'])),
            'missed' => count(array_filter($rows, fn (array $r) => $r['status'] === 'missed')),
            'open' => count(array_filter($rows, fn (array $r) => $r['status'] === 'open')),
            // Never part of the Care Score / traffic light (David 2026-10-12: »brez kazni«).
            'scored' => false,
            'weeks' => $rows,
        ];
    }
}
