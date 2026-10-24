<?php

namespace App\Services;

use App\Enums\CareTask;
use App\Models\Pet;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The `care_tasks` list (child state, PetUpdated) and the `walk_heat` object
 * of a dog (M5-R14 / R15 / R19 / R20, David 2026-10-24). Empty list / null for
 * every pet without them (a breed whose source gives no frequency, cats,
 * legacy pets, a dog created without `care_tasks_enabled`).
 *
 * One entry per CHILD task (tooth_brushing | fold_cleaning | ear_cleaning |
 * nail_trim) — the parent reminders are on the parent dashboard
 * (`care_reminders`), the care guide lists both:
 *  - `times` per `period_days` (the breed's sourced frequency) and
 *    `done_this_period` of the current program period (`period_started_at` →
 *    `period_ends_at`);
 *  - `blocked_reason` (care_task_not_available | needs_cleaning |
 *    care_task_period_done | care_task_done_today | care_task_session_active |
 *    care_session_active | care_task_quiet_hours; null = it may start),
 *    `next_allowed_at` its end; `can_start` for the viewing child incl. locks;
 *  - `session`: the viewing child's running session; `session_running`: anyone's.
 *
 * Instants are ISO 8601 in the family timezone.
 */
final class CareTasksPayload
{
    /**
     * @return list<array{task: 'tooth_brushing'|'fold_cleaning'|'ear_cleaning'|'nail_trim', times: int, period_days: int, done_this_period: int, period_started_at: string, period_ends_at: string, session_seconds: int, next_allowed_at: string|null, blocked_reason: 'care_task_not_available'|'needs_cleaning'|'care_task_period_done'|'care_task_done_today'|'care_task_session_active'|'care_session_active'|'care_task_quiet_hours'|null, can_start: bool, session: array{id: string, task: 'tooth_brushing'|'fold_cleaning'|'ear_cleaning'|'nail_trim', started_at: string, ends_at: string, expires_at: string, duration_ms: int, min_strokes: int, segments: int, min_stroke_interval_ms: int}|null, session_running: bool}>
     */
    public static function tasks(Pet $pet, ?User $viewer = null, ?CarbonInterface $now = null): array
    {
        if ($pet->isUnborn() || ! $pet->careTasksEnabled()) {
            return [];
        }
        $service = app(CareTaskService::class);
        $now = CarbonImmutable::instance($now ?? now())->utc();
        $tz = $pet->familyTimezone();
        $iso = fn (?CarbonInterface $at): ?string => $at === null ? null : CarbonImmutable::instance($at)->setTimezone($tz)->toIso8601String();
        $lock = $pet->actionLockReasonFor($viewer);

        $out = [];
        foreach (CareTask::childTasks() as $task) {
            $period = $service->periodAt($pet, $task, $now);
            if ($period === null) {
                continue;
            }
            $refusal = $service->startRefusal($pet, $viewer, $task, $now);
            $live = $service->liveSession($pet, $task, $now);
            $mine = $viewer !== null && $live !== null && (int) $live->user_id === $viewer->id ? $live : null;
            $out[] = [
                'task' => $task->value,
                // The breed's sourced frequency: `times` per `period_days` program days (at most 1 per day).
                'times' => $period['times'],
                'period_days' => $period['period_days'],
                'done_this_period' => $service->doneBetween($pet, $task, $period['start'], $now->addSecond()),
                'period_started_at' => (string) $iso($period['start']),
                'period_ends_at' => (string) $iso($period['end']),
                'session_seconds' => CareTaskService::sessionSeconds($task),
                // When the refusal in blocked_reason ends (next day / next period / when the pet wakes).
                'next_allowed_at' => $iso($refusal['next_allowed_at'] ?? null),
                // Why a start would be refused now (not the lock); null = it may start.
                'blocked_reason' => $refusal['refusal']->value ?? null,
                // POST /api/child/pet/care-task/start would be accepted now.
                'can_start' => $refusal === null && $lock === null,
                // The viewing child's running session (as returned by start), else null.
                'session' => $mine !== null ? CareSessionPayload::careTask($mine, $tz) : null,
                'session_running' => $live !== null,
            ];
        }

        return $out;
    }

    /**
     * M5-R14 summer heat: today's midday window of a flat-faced breed with the
     * rule (`starts_at` / `ends_at`, family timezone) and whether it is on now;
     * null on every other day / pet. The app shows the warning while
     * starts_at ≤ now < ends_at (no penalty, steps still count).
     *
     * @return array{starts_at: string, ends_at: string, active: bool}|null
     */
    public static function walkHeat(Pet $pet, ?CarbonInterface $now = null): ?array
    {
        if ($pet->isUnborn()) {
            return null;
        }
        $now = CarbonImmutable::instance($now ?? now())->utc();
        $window = app(WalkHeatService::class)->windowOn($pet, $now);
        if ($window === null) {
            return null;
        }
        $tz = $pet->familyTimezone();

        return [
            'starts_at' => $window['starts_at']->setTimezone($tz)->toIso8601String(),
            'ends_at' => $window['ends_at']->setTimezone($tz)->toIso8601String(),
            'active' => $now->greaterThanOrEqualTo($window['starts_at']) && $now->lessThan($window['ends_at']),
        ];
    }

    /**
     * The parent dashboard's `care_reminders` of a pet (groomer visit, bath):
     * the current period and whether the parent confirmed it. Never scored.
     *
     * @return list<array{task: 'groomer_visit'|'bath', period_days: int, period_started_at: string, period_ends_at: string, done: bool, done_at: string|null}>
     */
    public static function reminders(Pet $pet, ?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now())->utc();
        $tz = $pet->familyTimezone();
        $born = $pet->born_at !== null ? CarbonImmutable::instance($pet->born_at)->utc() : null;

        return array_map(fn (array $r): array => [
            /** @var 'groomer_visit'|'bath' */
            'task' => $r['task']->value,
            // The breed's sourced frequency: once per `period_days` program days.
            'period_days' => $r['period']['period_days'],
            'period_started_at' => ($born !== null && $r['period']['start']->lessThan($born) ? $born : $r['period']['start'])->setTimezone($tz)->toIso8601String(),
            // Due by: the end of the current period.
            'period_ends_at' => $r['period']['end']->setTimezone($tz)->toIso8601String(),
            'done' => $r['done_at'] !== null,
            'done_at' => $r['done_at']?->setTimezone($tz)->toIso8601String(),
        ], app(CareTaskService::class)->remindersFor($pet, $now));
    }
}
