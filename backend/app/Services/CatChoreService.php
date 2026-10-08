<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\CareRefusal;
use App\Enums\CareSessionKind;
use App\Enums\CareSessionStatus;
use App\Enums\RoutineStatus;
use App\Enums\RoutineType;
use App\Enums\StageParamKey;
use App\Models\ActivityLog;
use App\Models\Pet;
use App\Models\PetCareSession;
use App\Models\QuietHours;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The cat's server-led chores (M5-R06-05, M5-R06_PLAN T6): the weekly full
 * litter change (CAT_SPEC Q3) and the Maine Coon's grooming (Q8), both
 * `pet_care_sessions` like the wand game (M5-R06-04): start → the server's
 * schedule (length, what is checked); finish → the app reports the strokes
 * it saw ({t: ms since start}) and score() judges participation
 * (config/cat_care.php). Only `completed` counts; any other end has no
 * consequence and the child may start again at once.
 *
 * Litter change: one per program period of `litter_full_change_days` (7)
 * days from the birth (LitterService::changePeriodAt) — a second one in the
 * same week is refused (`litter_change_done`). Success writes
 * `changed_litter` (the week's `litter_change` routine) and scoops every
 * open litter use ("dump everything"). Allowed in quiet hours (a chore, like
 * cleaning); refused while a mess is open (PRODUCT_SPEC §8).
 *
 * Grooming (Maine Coon — `grooming_sessions_per_week` 3, other cats have no
 * row): at most one per family-local day (CAT_SPEC Q8 "≥ 1 day between
 * sessions" — Claude's reading, DECISIONS), at most the goal per program
 * week, not in quiet hours (the cat sleeps, like the wand game). Success
 * writes `groomed_pet` (value = its number in the week; the week's
 * `grooming` routine slots). Matted coat (David 2026-10-08 ~22:20): at the
 * end of each program week (closeGroomingWeek, tick) ≥ MATTED_AFTER_MISSED
 * of the week's slots MISSED (ledger) → `pets.coat_matted_at` + a
 * `pet_coat_matted` timeline row. No illness, no mood / metric effect — only
 * the missed routines count. The next grooming lasts ~2×
 * (`matted_session_seconds`) and clears it.
 *
 * Every caller that writes holds the pet's row lock (backend/CLAUDE.md).
 */
class CatChoreService
{
    /**
     * Matted coat when at least this many of a program week's groomings were
     * missed (data.json maine_coon.grooming.game_matted_after_missed, David
     * 2026-10-08 13:47 + ~22:20 "≥ 2 of the 3"). A game rule, not a breed
     * value → a constant like the 2 h cleaning deadline.
     */
    public const MATTED_AFTER_MISSED = 2;

    /** Grooming is evaluated per program week. */
    public const GROOMING_PERIOD_DAYS = 7;

    public function __construct(
        private readonly LifeStageService $lifeStages,
        private readonly LitterService $litter,
        private readonly RoutineLedgerService $ledger,
    ) {}

    // ──────────────────────────────────────────────────────────────
    //  Numbers
    // ──────────────────────────────────────────────────────────────

    /** Groomings per program week on a family-local date (Maine Coon 3; 0 = no grooming routine). */
    public function groomingGoalOn(Pet $pet, string $localDate): int
    {
        if (! $pet->isCat() || $pet->isLegacyProfile()) {
            return 0;
        }
        $value = $this->lifeStages->stageValueOn($pet, $localDate, StageParamKey::GroomingSessionsPerWeek)['value'] ?? null;

        return is_int($value) && $value > 0 ? $value : 0;
    }

    public function groomingAppliesTo(Pet $pet, ?CarbonInterface $now = null): bool
    {
        return $this->groomingGoalOn($pet, $pet->localDate($now ?? now())) > 0;
    }

    /**
     * @return array{index: int, start: CarbonImmutable, end: CarbonImmutable}|null
     */
    public function groomingPeriodAt(Pet $pet, CarbonInterface $at): ?array
    {
        return $this->groomingAppliesTo($pet, $at) ? $this->lifeStages->programPeriodAt($pet, $at, self::GROOMING_PERIOD_DAYS) : null;
    }

    /** Completed groomings (`groomed_pet` rows) in [$from, $to). */
    public function groomingsBetween(Pet $pet, CarbonInterface $from, CarbonInterface $to): int
    {
        return ActivityLog::where('pet_id', $pet->id)
            ->where('activity_type', ActivityType::GroomedPet->value)
            ->where('created_at', '>=', CarbonImmutable::instance($from)->utc())
            ->where('created_at', '<', CarbonImmutable::instance($to)->utc())
            ->count();
    }

    public function isMatted(Pet $pet): bool
    {
        return $pet->coat_matted_at !== null;
    }

    /** Length of a session of $kind for this pet now (grooming: ~2× while matted). */
    public function sessionSeconds(Pet $pet, CareSessionKind $kind): int
    {
        $key = $kind === CareSessionKind::Grooming && $this->isMatted($pet) ? 'matted_session_seconds' : 'session_seconds';

        return max(1, (int) config("cat_care.{$kind->value}.{$key}", 30));
    }

    public static function graceSeconds(CareSessionKind $kind): int
    {
        return max(0, (int) config("cat_care.{$kind->value}.finish_grace_seconds", 60));
    }

    // ──────────────────────────────────────────────────────────────
    //  Reading
    // ──────────────────────────────────────────────────────────────

    /** The running session of $kind (active, before its TTL, no lock began during it), if any. */
    public function liveSession(Pet $pet, CareSessionKind $kind, CarbonInterface $now): ?PetCareSession
    {
        return PetCareSession::where('pet_id', $pet->id)
            ->where('kind', $kind->value)
            ->where('status', CareSessionStatus::Active->value)
            ->where('expires_at', '>', $now)
            ->whereNot(fn ($q) => self::whereInterrupted($q, $now))
            ->first();
    }

    /**
     * QA m1: one game with the cat at a time. The live session of ANOTHER
     * kind (wand, grooming, litter change, scratching) that blocks a start of
     * $kind — by another child (or anyone when $child is null: pet level).
     */
    public static function otherLiveSession(Pet $pet, CareSessionKind $kind, ?User $child, CarbonInterface $now): ?PetCareSession
    {
        return PetCareSession::where('pet_id', $pet->id)
            ->where('kind', '!=', $kind->value)
            ->where('status', CareSessionStatus::Active->value)
            ->where('expires_at', '>', $now)
            ->whereNot(fn ($q) => self::whereInterrupted($q, $now))
            ->when($child !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $child->id)))
            ->orderByDesc('expires_at')
            ->first();
    }

    /**
     * The refusal for otherLiveSession(), or null.
     *
     * @return array{refusal: CareRefusal, next_allowed_at: CarbonImmutable|null}|null
     */
    public static function otherLiveRefusal(Pet $pet, CareSessionKind $kind, ?User $child, CarbonInterface $now): ?array
    {
        $other = self::otherLiveSession($pet, $kind, $child, $now);

        return $other === null ? null : ['refusal' => CareRefusal::CareSessionActive, 'next_allowed_at' => CarbonImmutable::instance($other->expires_at)->utc()];
    }

    /** The child's own unfinished games of other kinds end (aborted, no penalty) when they start $kind. */
    public static function abortOwnOtherKinds(Pet $pet, CareSessionKind $kind, User $child, CarbonInterface $now): void
    {
        PetCareSession::where('pet_id', $pet->id)
            ->where('kind', '!=', $kind->value)
            ->where('status', CareSessionStatus::Active->value)
            ->where('user_id', $child->id)
            ->update(['status' => CareSessionStatus::Aborted->value, 'updated_at' => $now]);
    }

    /**
     * Would a start of $kind by $child be refused now, and until when? Null
     * = it may start. The caller adds the locks (423). Shared by start(),
     * the payloads (`can_start`) and the litter reminder (M3-12).
     *
     * @return array{refusal: CareRefusal, next_allowed_at: CarbonImmutable|null}|null
     */
    public function startRefusal(Pet $pet, ?User $child, CareSessionKind $kind, CarbonInterface $now): ?array
    {
        $now = CarbonImmutable::instance($now)->utc();
        $refuse = fn (CareRefusal $r, ?CarbonImmutable $next = null): array => ['refusal' => $r, 'next_allowed_at' => $next];

        if ($kind === CareSessionKind::LitterChange) {
            $period = $this->litter->changePeriodAt($pet, $now);
            if (! $this->litter->appliesTo($pet) || $period === null) {
                return $refuse(CareRefusal::LitterNotAvailable);
            }
            if ($pet->displayMetric('hygiene_level') <= 0) {
                return $refuse(CareRefusal::NeedsCleaning);
            }
            if ($this->litter->changedBetween($pet, $period['start'], $now)) {
                return $refuse(CareRefusal::LitterChangeDone, $period['end']);
            }
            if (($other = self::otherLiveRefusal($pet, $kind, $child, $now)) !== null) {
                return $other;
            }
            $live = $this->liveSession($pet, $kind, $now);
            if ($live !== null && ($child === null || (int) $live->user_id !== $child->id)) {
                return $refuse(CareRefusal::LitterChangeSessionActive, CarbonImmutable::instance($live->expires_at)->utc());
            }

            return null;
        }

        // Grooming (Maine Coon).
        $period = $this->groomingPeriodAt($pet, $now);
        if ($period === null) {
            return $refuse(CareRefusal::GroomingNotAvailable);
        }
        if ($pet->displayMetric('hygiene_level') <= 0) {
            return $refuse(CareRefusal::NeedsCleaning);
        }
        $goal = $this->groomingGoalOn($pet, $pet->localDate($now));
        $seconds = $this->sessionSeconds($pet, $kind) + self::graceSeconds($kind);
        // QA n1: the next possible start — not inside the quiet hours that follow.
        $awake = fn (CarbonImmutable $at): CarbonImmutable => $this->quietBlocksUntil($pet, $at, $seconds) ?? $at;
        if (! $this->isMatted($pet) && $this->groomingsBetween($pet, $period['start'], $now->addSecond()) >= $goal) {
            return $refuse(CareRefusal::GroomingWeekDone, $awake($period['end']));
        }
        $dayStart = CarbonImmutable::parse($pet->localDate($now), $pet->familyTimezone())->startOfDay()->utc();
        $dayEnd = CarbonImmutable::parse($pet->localDate($now), $pet->familyTimezone())->addDay()->startOfDay()->utc();
        if ($this->groomingsBetween($pet, $dayStart, $now->addSecond()) > 0) {
            return $refuse(CareRefusal::GroomingDoneToday, $awake($dayEnd));
        }
        if (($other = self::otherLiveRefusal($pet, $kind, $child, $now)) !== null) {
            return $other;
        }
        $live = $this->liveSession($pet, $kind, $now);
        if ($live !== null && ($child === null || (int) $live->user_id !== $child->id)) {
            return $refuse(CareRefusal::GroomingSessionActive, CarbonImmutable::instance($live->expires_at)->utc());
        }
        if (($quietEnd = $this->quietBlocksUntil($pet, $now, $seconds)) !== null) {
            return $refuse(CareRefusal::GroomingQuietHours, $quietEnd);
        }

        return null;
    }

    /**
     * The end of the quiet stretch a session of $seconds started at $now
     * would touch (the cat sleeps), or null.
     */
    public function quietBlocksUntil(Pet $pet, CarbonInterface $now, int $seconds): ?CarbonImmutable
    {
        $quiet = $pet->quietHours();
        if (! $quiet->is_active) {
            return null;
        }
        $start = CarbonImmutable::instance($now)->utc()->startOfSecond();
        foreach (QuietHours::segmentsBetween($quiet, $start, $start->addSeconds($seconds)) as [$segStart, , $isQuiet]) {
            if ($isQuiet) {
                $at = CarbonImmutable::instance($segStart)->utc();
                for ($hop = 0; $hop < 8 && $quiet->isQuietNow($at); $hop++) {
                    $next = $quiet->nextBoundaryAfter($at);
                    if ($next === null) {
                        break;
                    }
                    $at = CarbonImmutable::instance($next)->utc();
                }

                return $at;
            }
        }

        return null;
    }

    // ──────────────────────────────────────────────────────────────
    //  Start / finish (caller holds the pet lock)
    // ──────────────────────────────────────────────────────────────

    /**
     * Start a grooming / litter change session. The same child's own
     * running session of that kind is replaced (`aborted`, no penalty).
     *
     * @return array{refusal: CareRefusal|null, next_allowed_at: CarbonInterface|null, session: PetCareSession|null}
     */
    public function start(Pet $pet, User $child, CareSessionKind $kind, CarbonInterface $now): array
    {
        $now = CarbonImmutable::instance($now)->utc()->startOfSecond();
        $this->expireStale($pet, $now);

        $refusal = $this->startRefusal($pet, $child, $kind, $now);
        if ($refusal !== null) {
            return ['refusal' => $refusal['refusal'], 'next_allowed_at' => $refusal['next_allowed_at'], 'session' => null];
        }

        PetCareSession::where('pet_id', $pet->id)
            ->where('kind', $kind->value)
            ->where('status', CareSessionStatus::Active->value)
            ->update(['status' => CareSessionStatus::Aborted->value, 'updated_at' => $now]);
        self::abortOwnOtherKinds($pet, $kind, $child, $now);

        $matted = $kind === CareSessionKind::Grooming && $this->isMatted($pet);
        $durationMs = $this->sessionSeconds($pet, $kind) * 1000;
        $endsAt = $now->addMilliseconds($durationMs);
        $minStrokes = (int) config("cat_care.{$kind->value}.".($matted ? 'matted_min_strokes' : 'min_strokes'), 10);

        $session = PetCareSession::create([
            'public_id' => (string) Str::uuid(),
            'pet_id' => $pet->id,
            'user_id' => $child->id,
            'kind' => $kind,
            'local_date' => $pet->localDate($now),
            'started_at' => $now,
            'ends_at' => $endsAt,
            'expires_at' => $endsAt->addSeconds(self::graceSeconds($kind)),
            'duration_ms' => $durationMs,
            'schedule' => [
                'min_strokes' => max(1, $minStrokes),
                'segments' => max(1, (int) config("cat_care.{$kind->value}.segments", 3)),
                'min_stroke_interval_ms' => max(0, (int) config('cat_care.min_stroke_interval_ms', 150)),
                // Grooming only: this is the longer session that resolves a matted coat.
                'matted' => $matted,
            ],
            'status' => CareSessionStatus::Active,
        ]);

        return ['refusal' => null, 'next_allowed_at' => null, 'session' => $session];
    }

    /**
     * Finish with the strokes the app saw. A repeat for an already finished
     * session returns it again (`repeat`). `counted` = completed (success).
     *
     * @param  list<array{t: int}>  $strokes
     * @return array{refusal: CareRefusal|null, session: PetCareSession|null, repeat: bool, counted: bool}
     */
    public function finish(Pet $pet, User $child, CareSessionKind $kind, string $publicId, array $strokes, CarbonInterface $now): array
    {
        $exact = CarbonImmutable::instance($now)->utc();
        $now = $exact->startOfSecond();
        $refuse = fn (CareRefusal $r, ?PetCareSession $s = null): array => ['refusal' => $r, 'session' => $s, 'repeat' => false, 'counted' => false];

        $applies = $kind === CareSessionKind::LitterChange ? $this->litter->appliesTo($pet) : $this->groomingAppliesTo($pet, $now);
        if (! $applies) {
            return $refuse($kind === CareSessionKind::LitterChange ? CareRefusal::LitterNotAvailable : CareRefusal::GroomingNotAvailable);
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
        $tolerance = max(0, (int) config("cat_care.{$kind->value}.finish_early_tolerance_seconds", 3));
        if ($exact->lessThan(CarbonImmutable::instance($session->ends_at)->utc()->subSeconds($tolerance))) {
            return $refuse(CareRefusal::CareSessionNotOver, $session);
        }
        foreach ($strokes as $stroke) {
            if (! is_array($stroke) || ! is_int($stroke['t'] ?? null) || $stroke['t'] < 0 || $stroke['t'] > $session->duration_ms) {
                return $refuse(CareRefusal::CareSessionInvalidInput, $session);
            }
        }

        $schedule = $session->schedule;
        $result = self::score(
            array_map(fn (array $s): int => $s['t'], $strokes),
            $session->duration_ms,
            (int) ($schedule['min_strokes'] ?? 10),
            (int) ($schedule['segments'] ?? 3),
        );
        if ($result['reason'] === 'too_uniform') {
            Log::warning('CatChoreService: care session with machine-regular strokes', ['pet_id' => $pet->id, 'kind' => $kind->value]);
        }
        $session->forceFill([
            'status' => $result['success'] ? CareSessionStatus::Completed : CareSessionStatus::Failed,
            'finished_at' => $now,
            'result' => $result + ['matted' => (bool) ($schedule['matted'] ?? false)],
        ])->save();

        return ['refusal' => null, 'session' => $session, 'repeat' => false, 'counted' => $result['success']];
    }

    /**
     * After a successful finish (caller holds the lock; attributes on $pet,
     * the caller saves): grooming → clears a matted coat when this was the
     * longer session; litter change → scoops every open litter use. Returns
     * the session's number in its program week (activity value).
     */
    public function applySuccess(Pet $pet, PetCareSession $session, CarbonInterface $now): int
    {
        if ($session->kind === CareSessionKind::LitterChange) {
            $this->litter->scoop($pet, $now);
            $period = $this->litter->changePeriodAt($pet, $now);

            return $period !== null ? 1 + (int) ActivityLog::where('pet_id', $pet->id)
                ->where('activity_type', ActivityType::ChangedLitter->value)
                ->where('created_at', '>=', $period['start'])
                ->count() : 1;
        }

        if ((bool) ($session->schedule['matted'] ?? false) || $this->isMatted($pet)) {
            $pet->coat_matted_at = null;
        }
        $period = $this->groomingPeriodAt($pet, $now);

        return $period !== null ? 1 + $this->groomingsBetween($pet, $period['start'], CarbonImmutable::instance($now)->utc()->addSecond()) : 1;
    }

    /**
     * Judge the reported strokes (pure — no I/O). Strokes closer than
     * `min_stroke_interval_ms` to the previous counted one count once. A
     * session counts with ≥ $minStrokes strokes, at least one in each of
     * $segments equal parts, and gaps that are not machine-regular.
     *
     * @param  list<int>  $times  ms since start
     * @return array{success: bool, reason: 'too_few_strokes'|'not_spread'|'too_uniform'|null, strokes: int, min_strokes: int, segments_hit: int, segments: int}
     */
    public static function score(array $times, int $durationMs, int $minStrokes, int $segments): array
    {
        $minStrokes = max(1, $minStrokes);
        $segments = max(1, $segments);
        $interval = max(0, (int) config('cat_care.min_stroke_interval_ms', 150));
        $uniformGaps = max(2, (int) config('cat_care.uniform_min_gaps', 8));
        $uniformSpread = max(0, (int) config('cat_care.uniform_max_spread_ms', 40));

        sort($times);
        $count = 0;
        $hit = [];
        $gaps = [];
        $last = null;
        foreach ($times as $t) {
            if ($last !== null && $interval > $t - $last) {
                continue;
            }
            if ($last !== null) {
                $gaps[] = $t - $last;
            }
            $last = $t;
            $count++;
            $hit[min($segments - 1, intdiv($t * $segments, max(1, $durationMs)))] = true;
        }
        $uniform = count($gaps) >= $uniformGaps && max($gaps) - min($gaps) <= $uniformSpread;

        $reason = match (true) {
            $count < $minStrokes => 'too_few_strokes',
            count($hit) < $segments => 'not_spread',
            $uniform => 'too_uniform',
            default => null,
        };

        return [
            'success' => $reason === null,
            'reason' => $reason,
            'strokes' => $count,
            'min_strokes' => $minStrokes,
            'segments_hit' => count($hit),
            'segments' => $segments,
        ];
    }

    /**
     * Settle active grooming / litter change / scratching sessions: a lock
     * began during it → `interrupted`; past its TTL → `expired`. Returns the
     * number settled (the tick broadcasts `care_session_ended` then).
     */
    public function expireStale(Pet $pet, CarbonInterface $now): int
    {
        $kinds = [CareSessionKind::Grooming->value, CareSessionKind::LitterChange->value, CareSessionKind::Scratching->value];

        return PetCareSession::where('pet_id', $pet->id)
            ->whereIn('kind', $kinds)
            ->where('status', CareSessionStatus::Active->value)
            ->where(fn ($q) => self::whereInterrupted($q, $now))
            ->update(['status' => CareSessionStatus::Interrupted->value, 'updated_at' => $now])
            + PetCareSession::where('pet_id', $pet->id)
                ->whereIn('kind', $kinds)
                ->where('status', CareSessionStatus::Active->value)
                ->where('expires_at', '<=', $now)
                ->update(['status' => CareSessionStatus::Expired->value, 'updated_at' => $now]);
    }

    /**
     * Sessions during which a lock began (pet_status_periods): the wand / training rule.
     *
     * @param  Builder<PetCareSession>  $query
     */
    public static function whereInterrupted($query, CarbonInterface $now): void
    {
        $at = CarbonImmutable::instance($now)->utc()->format('Y-m-d H:i:s');
        $query->whereExists(fn ($q) => $q->selectRaw('1')->from('pet_status_periods')
            ->whereColumn('pet_status_periods.pet_id', 'pet_care_sessions.pet_id')
            ->whereColumn('pet_status_periods.started_at', '>', 'pet_care_sessions.started_at')
            ->whereColumn('pet_status_periods.started_at', '<=', 'pet_care_sessions.expires_at')
            ->where('pet_status_periods.started_at', '<=', $at));
    }

    // ──────────────────────────────────────────────────────────────
    //  Matted coat (tick, under the lock)
    // ──────────────────────────────────────────────────────────────

    /**
     * The end of a program week (the weekly birthday): when ≥
     * MATTED_AFTER_MISSED of the finished week's grooming slots were MISSED
     * (ledger; excused / not expected slots do not count) the coat is
     * matted from the week's end. Each week is evaluated once
     * (`pets.grooming_weeks_checked`); after an outage only the last
     * finished week. Returns true when the coat became matted. Attributes on
     * $pet; the caller saves.
     */
    public function closeGroomingWeek(Pet $pet, CarbonInterface $now): bool
    {
        if ($pet->isUnborn() || ! $pet->isCat() || $pet->isLegacyProfile()) {
            return false;
        }
        $period = $this->lifeStages->programPeriodAt($pet, $now, self::GROOMING_PERIOD_DAYS);
        if ($period === null) {
            return false;
        }
        $checked = $pet->grooming_weeks_checked;
        if ($checked === null) {
            $pet->grooming_weeks_checked = $period['index'];

            return false;
        }
        if ($checked >= $period['index']) {
            return false;
        }
        $pet->grooming_weeks_checked = $period['index'];

        $weekEnd = $period['start'];
        $date = $pet->localDate($weekEnd);
        if ($this->groomingGoalOn($pet, $date) <= 0) {
            return false; // not a Maine Coon (no grooming routine)
        }
        $missed = 0;
        foreach ($this->ledger->routinesFor(collect([$pet]), $date, $date, $now)[$pet->id] ?? [] as $routine) {
            if ($routine->type === RoutineType::Grooming && $routine->status === RoutineStatus::Missed
                && $routine->dueAt->equalTo($weekEnd)) {
                $missed++;
            }
        }
        if ($missed < self::MATTED_AFTER_MISSED || $this->isMatted($pet)) {
            return false;
        }

        $pet->coat_matted_at = $weekEnd;
        HygieneEventService::logSystemEvent($pet, ActivityType::PetCoatMatted, $weekEnd);
        Log::info('CatChoreService: matted coat', ['pet_id' => $pet->id, 'missed' => $missed, 'week_end' => $weekEnd->toIso8601String()]);

        return true;
    }
}
