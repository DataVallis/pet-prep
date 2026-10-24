<?php

namespace App\Services;

use App\Enums\CareRefusal;
use App\Enums\CareSessionKind;
use App\Enums\CareSessionStatus;
use App\Enums\CareTask;
use App\Enums\StageParamKey;
use App\Models\ActivityLog;
use App\Models\Pet;
use App\Models\PetCareSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Breed care tasks (M5-R14 / R15 / R18 / R19 / R20, David 2026-10-24).
 *
 * Which tasks a dog has and how often: its breed's `*_frequency` row
 * ([times, period_days], breed_stage_params — ONLY breeds whose source states
 * a frequency), for a profiled dog with `care_tasks_enabled`. A period is the
 * program period of `period_days` days from the birth (LifeStageService::
 * programPeriodAt — payment-lock time is not program time), like the weekly
 * grooming: a month = 28 days, so the 12-week program has three months.
 *
 * CHILD tasks (teeth, folds, ears, nails) — the grooming model (CatChoreService):
 *  - a server-led mini-game session (`pet_care_sessions`, config/care_tasks.php):
 *    the app reports strokes / taps {t}, CatChoreService::score() judges them;
 *  - at most one per family-local day, at most `times` per period;
 *  - not while the pet sleeps (PetSleepService — checked first, »spečo žival ne
 *    motimo«) and not when the session would run into quiet hours; refused while
 *    a mess is open (hygiene 0 %), like grooming;
 *  - one game at a time: another child's / kind's running session blocks a start
 *    (CatChoreService::otherLiveRefusal), the child's own other games end;
 *  - success writes the task's activity row (value = its number in the period):
 *    the ledger's slots (RoutineLedgerService::careTaskRoutines) — fair share per
 *    child, missed slots in the parent report; no illness, no matted-coat
 *    equivalent, nothing extra shown to the child.
 *
 * PARENT reminders (groomer visit, bath): the parent confirms "done" once per
 * period (ParentGroomerVisit / ParentBathedPet row, actor = the parent); never
 * a routine, never in the Care Score or the traffic light (reminderFor()).
 *
 * EXISTING dogs (David 2026-10-24, answer (e)): `pets:activate-care-tasks`
 * (CareTaskActivationService) switches a dog on once its children's and a
 * parent's apps declare `care_tasks`, from the next family-local midnight
 * (`care_tasks_since`): nothing applies before it, the period it falls in
 * starts there and expects only its share of `times` (timesFrom()).
 *
 * Every caller that writes holds the pet's row lock (backend/CLAUDE.md).
 */
class CareTaskService
{
    public function __construct(
        private readonly LifeStageService $lifeStages,
        private readonly CatChoreService $chores,
        private readonly PetSleepService $sleep,
    ) {}

    // ──────────────────────────────────────────────────────────────
    //  Numbers
    // ──────────────────────────────────────────────────────────────

    /**
     * The task's sourced frequency on a family-local date, or null (no row,
     * not enabled, a cat / legacy pet).
     *
     * @return array{times: int, period_days: int}|null
     */
    public function frequencyOn(Pet $pet, string $localDate, CareTask $task): ?array
    {
        // An existing dog switched on later: nothing before its `care_tasks_since` midnight.
        if (! $pet->careTasksActiveOn($localDate)) {
            return null;
        }
        $value = $this->lifeStages->stageValueOn($pet, $localDate, $task->stageParamKey())['value'] ?? null;

        return StageParamKey::validFrequency($value) ? ['times' => $value[0], 'period_days' => $value[1]] : null;
    }

    /**
     * The tasks of this pet on a date (child tasks first, then reminders).
     *
     * @return list<CareTask>
     */
    public function tasksOn(Pet $pet, string $localDate): array
    {
        if (! $pet->careTasksEnabled()) {
            return [];
        }

        return array_values(array_filter(CareTask::cases(), fn (CareTask $t) => $this->frequencyOn($pet, $localDate, $t) !== null));
    }

    /**
     * The task's program period at $at, or null when the task does not apply.
     *
     * @return array{index: int, start: CarbonImmutable, end: CarbonImmutable, times: int, period_days: int}|null
     */
    public function periodAt(Pet $pet, CareTask $task, CarbonInterface $at): ?array
    {
        if ($pet->isUnborn()) {
            return null;
        }
        $frequency = $this->frequencyOn($pet, $pet->localDate($at), $task);
        if ($frequency === null) {
            return null;
        }
        $period = $this->lifeStages->programPeriodAt($pet, $at, $frequency['period_days']);
        if ($period === null) {
            return null;
        }
        $period += $frequency;

        // The period an existing dog was switched on in (David 2026-10-24, answer (e)):
        // it starts at `care_tasks_since`; a child task expects only its share of the
        // period that is left (timesFrom — 0 → the task starts with the next period).
        $since = $pet->careTasksSince();
        if ($since !== null && $since->greaterThan($period['start'])) {
            if ($task->isChildTask()) {
                $period['times'] = self::timesFrom($period['times'], $period['period_days'], $period['start'], $since);
                if ($period['times'] <= 0) {
                    return null;
                }
            }
            $period['start'] = $since;
        }

        return $period;
    }

    /**
     * How many of a child task's `times` the period an existing dog was switched
     * on in still expects: `times` × the share of the period's nominal length
     * (`period_days`) left after `$since`, rounded half up — never more than a
     * full period, never retroactive (David 2026-10-24, answer (e)). The share is
     * measured from the period START, which later locks never move, so the live
     * check and the ledger (RoutineLedgerService::careTaskRoutines) always agree.
     */
    public static function timesFrom(int $times, int $periodDays, CarbonInterface $periodStart, ?CarbonInterface $since): int
    {
        if ($since === null || ! $since->greaterThan($periodStart)) {
            return $times;
        }
        $length = max(1, $periodDays) * 86400;
        $left = max(0.0, 1.0 - ($since->getTimestamp() - $periodStart->getTimestamp()) / $length);

        return min($times, (int) round($times * $left, 0, PHP_ROUND_HALF_UP));
    }

    /** Completed tasks (the task's activity rows) in [$from, $to). */
    public function doneBetween(Pet $pet, CareTask $task, CarbonInterface $from, CarbonInterface $to): int
    {
        return ActivityLog::where('pet_id', $pet->id)
            ->where('activity_type', $task->activityType()->value)
            ->where('created_at', '>=', CarbonImmutable::instance($from)->utc())
            ->where('created_at', '<', CarbonImmutable::instance($to)->utc())
            ->count();
    }

    public static function sessionSeconds(CareTask $task): int
    {
        return max(1, (int) config("care_tasks.tasks.{$task->value}.session_seconds", 30));
    }

    public static function graceSeconds(CareTask $task): int
    {
        return max(0, (int) config("care_tasks.tasks.{$task->value}.finish_grace_seconds", 60));
    }

    /** Session + finish grace: the awake stretch a task needs (no task into quiet hours). */
    public static function neededSeconds(CareTask $task): int
    {
        return self::sessionSeconds($task) + self::graceSeconds($task);
    }

    // ──────────────────────────────────────────────────────────────
    //  Child tasks
    // ──────────────────────────────────────────────────────────────

    /** The running session of a child task (active, before its TTL, no lock began during it). */
    public function liveSession(Pet $pet, CareTask $task, CarbonInterface $now): ?PetCareSession
    {
        $kind = $task->sessionKind();
        if ($kind === null) {
            return null;
        }

        return PetCareSession::where('pet_id', $pet->id)
            ->where('kind', $kind->value)
            ->where('status', CareSessionStatus::Active->value)
            ->where('expires_at', '>', $now)
            ->whereNot(fn ($q) => CatChoreService::whereInterrupted($q, $now))
            ->first();
    }

    /**
     * Would a start of the child task by $child be refused now, and until
     * when? Null = it may start (the caller adds the locks, 423). Shared by
     * start() and the payload (`can_start`).
     *
     * @return array{refusal: CareRefusal, next_allowed_at: CarbonImmutable|null}|null
     */
    public function startRefusal(Pet $pet, ?User $child, CareTask $task, CarbonInterface $now): ?array
    {
        $now = CarbonImmutable::instance($now)->utc();
        $refuse = fn (CareRefusal $r, ?CarbonImmutable $next = null): array => ['refusal' => $r, 'next_allowed_at' => $next];
        $kind = $task->sessionKind();

        $period = $kind === null ? null : $this->periodAt($pet, $task, $now);
        if ($period === null) {
            return $refuse(CareRefusal::CareTaskNotAvailable);
        }
        // »Spečo žival ne motimo« (David 2026-10-21 / 2026-10-24): the sleep is the
        // reason before any other block; next_allowed_at = when the pet wakes.
        if (($wakes = $this->sleep->wakesAt($pet, $now)) !== null) {
            return $refuse(CareRefusal::CareTaskQuietHours, $wakes);
        }
        if ($pet->displayMetric('hygiene_level') <= 0) {
            return $refuse(CareRefusal::NeedsCleaning);
        }
        $seconds = self::neededSeconds($task);
        $awake = fn (CarbonImmutable $at): CarbonImmutable => $this->chores->quietBlocksUntil($pet, $at, $seconds) ?? $at;
        if ($this->doneBetween($pet, $task, $period['start'], $now->addSecond()) >= $period['times']) {
            return $refuse(CareRefusal::CareTaskPeriodDone, $awake($period['end']));
        }
        $tz = $pet->familyTimezone();
        $dayStart = CarbonImmutable::parse($pet->localDate($now), $tz)->startOfDay()->utc();
        $dayEnd = CarbonImmutable::parse($pet->localDate($now), $tz)->addDay()->startOfDay()->utc();
        if ($this->doneBetween($pet, $task, $dayStart, $now->addSecond()) > 0) {
            return $refuse(CareRefusal::CareTaskDoneToday, $awake($dayEnd->lessThan($period['end']) ? $dayEnd : $period['end']));
        }
        if (($other = CatChoreService::otherLiveRefusal($pet, $kind, $child, $now)) !== null) {
            return $other;
        }
        $live = $this->liveSession($pet, $task, $now);
        if ($live !== null && ($child === null || (int) $live->user_id !== $child->id)) {
            return $refuse(CareRefusal::CareTaskSessionActive, CarbonImmutable::instance($live->expires_at)->utc());
        }
        if (($quietEnd = $this->chores->quietBlocksUntil($pet, $now, $seconds)) !== null) {
            return $refuse(CareRefusal::CareTaskQuietHours, $quietEnd);
        }

        return null;
    }

    /**
     * Start a child task session. The same child's own running session of the
     * task is replaced (`aborted`, no penalty); their games of other kinds end.
     *
     * @return array{refusal: CareRefusal|null, next_allowed_at: CarbonInterface|null, session: PetCareSession|null}
     */
    public function start(Pet $pet, User $child, CareTask $task, CarbonInterface $now): array
    {
        $now = CarbonImmutable::instance($now)->utc()->startOfSecond();
        $this->expireStale($pet, $now);

        $refusal = $this->startRefusal($pet, $child, $task, $now);
        if ($refusal !== null) {
            return ['refusal' => $refusal['refusal'], 'next_allowed_at' => $refusal['next_allowed_at'], 'session' => null];
        }
        /** @var CareSessionKind $kind */
        $kind = $task->sessionKind();

        PetCareSession::where('pet_id', $pet->id)
            ->where('kind', $kind->value)
            ->where('status', CareSessionStatus::Active->value)
            ->update(['status' => CareSessionStatus::Aborted->value, 'updated_at' => $now]);
        CatChoreService::abortOwnOtherKinds($pet, $kind, $child, $now);

        $durationMs = self::sessionSeconds($task) * 1000;
        $endsAt = $now->addMilliseconds($durationMs);
        $cfg = "care_tasks.tasks.{$task->value}";

        $session = PetCareSession::create([
            'public_id' => (string) Str::uuid(),
            'pet_id' => $pet->id,
            'user_id' => $child->id,
            'kind' => $kind,
            'local_date' => $pet->localDate($now),
            'started_at' => $now,
            'ends_at' => $endsAt,
            'expires_at' => $endsAt->addSeconds(self::graceSeconds($task)),
            'duration_ms' => $durationMs,
            'schedule' => [
                'min_strokes' => max(1, (int) config("{$cfg}.min_strokes", 10)),
                'segments' => max(1, (int) config("{$cfg}.segments", 3)),
                'min_stroke_interval_ms' => max(0, (int) config('cat_care.min_stroke_interval_ms', 150)),
                'matted' => false,
            ],
            'status' => CareSessionStatus::Active,
        ]);

        return ['refusal' => null, 'next_allowed_at' => null, 'session' => $session];
    }

    /**
     * Finish with the strokes / taps the app saw. A repeat for an already
     * finished session returns it again (`repeat`). `counted` = completed.
     *
     * @param  list<array{t: int}>  $strokes
     * @return array{refusal: CareRefusal|null, session: PetCareSession|null, repeat: bool, counted: bool}
     */
    public function finish(Pet $pet, User $child, CareTask $task, string $publicId, array $strokes, CarbonInterface $now): array
    {
        $exact = CarbonImmutable::instance($now)->utc();
        $now = $exact->startOfSecond();
        $refuse = fn (CareRefusal $r, ?PetCareSession $s = null): array => ['refusal' => $r, 'session' => $s, 'repeat' => false, 'counted' => false];
        $kind = $task->sessionKind();

        if ($kind === null || $this->periodAt($pet, $task, $now) === null) {
            return $refuse(CareRefusal::CareTaskNotAvailable);
        }

        $session = PetCareSession::where('public_id', $publicId)->where('pet_id', $pet->id)->where('kind', $kind->value)->first();
        if ($session === null || (int) $session->user_id !== $child->id) {
            return $refuse(CareRefusal::CareSessionInvalid);
        }
        if (in_array($session->status, [CareSessionStatus::Completed, CareSessionStatus::Failed], true)) {
            return ['refusal' => null, 'session' => $session, 'repeat' => true, 'counted' => $session->status === CareSessionStatus::Completed];
        }

        $this->expireStale($pet, $now);
        $session->refresh();
        if ($session->status === CareSessionStatus::Interrupted) {
            return $refuse(CareRefusal::CareSessionInterrupted, $session);
        }
        if ($session->status !== CareSessionStatus::Active || ! $session->expires_at->greaterThan($now)) {
            if ($session->status === CareSessionStatus::Active) {
                $session->forceFill(['status' => CareSessionStatus::Expired])->save();
            }

            return $refuse(CareRefusal::CareSessionExpired, $session);
        }
        $tolerance = max(0, (int) config("care_tasks.tasks.{$task->value}.finish_early_tolerance_seconds", 3));
        if ($exact->lessThan(CarbonImmutable::instance($session->ends_at)->utc()->subSeconds($tolerance))) {
            return $refuse(CareRefusal::CareSessionNotOver, $session);
        }
        foreach ($strokes as $stroke) {
            if (! is_array($stroke) || ! is_int($stroke['t'] ?? null) || $stroke['t'] < 0 || $stroke['t'] > $session->duration_ms) {
                return $refuse(CareRefusal::CareSessionInvalidInput, $session);
            }
        }

        $schedule = $session->schedule;
        $result = CatChoreService::score(
            array_map(fn (array $s): int => $s['t'], $strokes),
            $session->duration_ms,
            (int) ($schedule['min_strokes'] ?? 10),
            (int) ($schedule['segments'] ?? 3),
        );
        if ($result['reason'] === 'too_uniform') {
            Log::warning('CareTaskService: care task with machine-regular strokes', ['pet_id' => $pet->id, 'task' => $task->value]);
        }
        $session->forceFill([
            'status' => $result['success'] ? CareSessionStatus::Completed : CareSessionStatus::Failed,
            'finished_at' => $now,
            'result' => $result + ['matted' => false],
        ])->save();

        return ['refusal' => null, 'session' => $session, 'repeat' => false, 'counted' => $result['success']];
    }

    /** The completed task's number in its period (the activity value). */
    public function numberInPeriod(Pet $pet, CareTask $task, CarbonInterface $now): int
    {
        $period = $this->periodAt($pet, $task, $now);

        return $period !== null ? 1 + $this->doneBetween($pet, $task, $period['start'], CarbonImmutable::instance($now)->utc()->addSecond()) : 1;
    }

    /**
     * Settle active care task sessions: a lock began during it → `interrupted`;
     * past its TTL → `expired`. Returns the number settled.
     */
    public function expireStale(Pet $pet, CarbonInterface $now): int
    {
        $kinds = array_map(fn (CareTask $t) => $t->sessionKind()?->value, CareTask::childTasks());

        return PetCareSession::where('pet_id', $pet->id)
            ->whereIn('kind', $kinds)
            ->where('status', CareSessionStatus::Active->value)
            ->where(fn ($q) => CatChoreService::whereInterrupted($q, $now))
            ->update(['status' => CareSessionStatus::Interrupted->value, 'updated_at' => $now])
            + PetCareSession::where('pet_id', $pet->id)
                ->whereIn('kind', $kinds)
                ->where('status', CareSessionStatus::Active->value)
                ->where('expires_at', '<=', $now)
                ->update(['status' => CareSessionStatus::Expired->value, 'updated_at' => $now]);
    }

    // ──────────────────────────────────────────────────────────────
    //  Parent reminders
    // ──────────────────────────────────────────────────────────────

    /**
     * The reminder of the current period: due unless the parent confirmed it
     * in this period. Null when the pet has no such reminder.
     *
     * @return array{task: CareTask, period: array{index: int, start: CarbonImmutable, end: CarbonImmutable, times: int, period_days: int}, done_at: CarbonImmutable|null}|null
     */
    public function reminderFor(Pet $pet, CareTask $task, CarbonInterface $now): ?array
    {
        if ($task->isChildTask()) {
            return null;
        }
        $period = $this->periodAt($pet, $task, $now);
        if ($period === null) {
            return null;
        }
        $row = ActivityLog::where('pet_id', $pet->id)
            ->where('activity_type', $task->activityType()->value)
            ->where('created_at', '>=', $period['start'])
            ->where('created_at', '<', $period['end'])
            ->orderBy('created_at')
            ->first(['created_at']);

        return ['task' => $task, 'period' => $period, 'done_at' => $row !== null ? CarbonImmutable::instance($row->created_at)->utc() : null];
    }

    /**
     * The pet's parent reminders now.
     *
     * @return list<array{task: CareTask, period: array{index: int, start: CarbonImmutable, end: CarbonImmutable, times: int, period_days: int}, done_at: CarbonImmutable|null}>
     */
    public function remindersFor(Pet $pet, CarbonInterface $now): array
    {
        if (! $pet->careTasksEnabled() || $pet->isUnborn()) {
            return [];
        }

        return array_values(array_filter(array_map(fn (CareTask $t) => $this->reminderFor($pet, $t, $now), CareTask::parentTasks())));
    }

    /**
     * Report rows of a parent reminder: every period that overlaps [$from, $now]
     * (done / open / not done), newest first. Never scored.
     *
     * @return list<array{task: string, period_started_at: CarbonImmutable, period_ends_at: CarbonImmutable, done_at: CarbonImmutable|null, status: 'done'|'open'|'not_done'}>
     */
    public function reminderHistory(Pet $pet, CarbonInterface $from, CarbonInterface $now): array
    {
        if (! $pet->careTasksEnabled() || $pet->isUnborn()) {
            return [];
        }
        $now = CarbonImmutable::instance($now)->utc();
        $from = CarbonImmutable::instance($from)->utc();
        $born = CarbonImmutable::instance($pet->born_at)->utc();
        $rows = [];
        foreach (CareTask::parentTasks() as $task) {
            $at = $now;
            for ($i = 0; $i < 12; $i++) {
                $period = $this->periodAt($pet, $task, $at);
                if ($period === null || $period['end']->lessThanOrEqualTo($from)) {
                    break;
                }
                $done = ActivityLog::where('pet_id', $pet->id)
                    ->where('activity_type', $task->activityType()->value)
                    ->where('created_at', '>=', $period['start'])
                    ->where('created_at', '<', $period['end'])
                    ->orderBy('created_at')
                    ->value('created_at');
                $doneAt = $done !== null ? CarbonImmutable::parse($done, 'UTC') : null;
                $rows[] = [
                    'task' => $task->value,
                    'period_started_at' => $period['start']->lessThan($born) ? $born : $period['start'],
                    'period_ends_at' => $period['end'],
                    'done_at' => $doneAt,
                    'status' => $doneAt !== null ? 'done' : ($period['end']->greaterThan($now) ? 'open' : 'not_done'),
                ];
                if ($period['index'] === 0) {
                    break;
                }
                $at = $period['start']->subSecond();
            }
        }

        return $rows;
    }
}
