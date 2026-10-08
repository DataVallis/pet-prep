<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\CareRefusal;
use App\Enums\CareSessionKind;
use App\Enums\CareSessionStatus;
use App\Enums\RoutineStatus;
use App\Enums\RoutineType;
use App\Enums\StageParamKey;
use App\Models\Pet;
use App\Models\PetCareSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Random\Randomizer;

/**
 * The cat's daily play — "Palica s peresom" (M5-R06-04, David 2026-10-08,
 * CAT_SPEC Q1 / Q2 / §5.2, PRODUCT_SPEC §13, M5-R06_PLAN T5 / T6).
 *
 * Phone steps are dog-only; a cat's exercise is a ~60 s wand mini-game. The
 * SERVER runs it like the training game: start → a session with the
 * schedule (length, the cat's pounces, the catch at the end); finish → the
 * app reports only the feather moves it saw and the server judges whether
 * the child really took part (score(): enough "away" moves, spread over the
 * whole minute, mostly away from the cat — config/wand.php). Only a
 * `completed` session counts; failed / aborted / expired / interrupted
 * sessions have NO consequence and the child may start again at once
 * (David 2026-10-08 ~20:40).
 *
 * Numbers (breed_stage_params, per life stage of the day):
 *  - goal = `play_sessions_per_day` (kitten 3, grown cat 2 — CAT_SPEC Q1);
 *  - gap = `play_min_gap_minutes` (120, David 2026-10-08 ~20:40) between two
 *    SUCCESSFUL sessions, measured from the last successful finish.
 *
 * Play meter (plan T5): the cat reuses `pets.energy_level` = successful
 * sessions of the family-local day / goal × 100 (capped; a finish never
 * lowers it — the birth day keeps its 100 %), back to 0 at the family-local
 * midnight (Pet::resetDailyStepsIfNewDay, same as the dog's walk). It is
 * NOT on the phase ladder and never makes the cat ill (CAT_SPEC Q2); one
 * reminder a day (`play_reminder`, NotificationService::playReminder).
 *
 * Routine: every successful session writes one `played_wand` activity row
 * (actor = the child) — the day's rows make the `play` routine
 * (RoutineLedgerService; fair share in CareScoreService). A day whose play
 * routine was MISSED is persisted at the cat's midnight close
 * (`pets.play_missed_on`, closeDay()) for M5-R06-05's "scratched the sofa
 * the next day" (CAT_SPEC Q10).
 *
 * Only cats with a profile (life-stage data); every caller that writes
 * holds the pet's row lock (backend/CLAUDE.md); pet attributes are set on
 * $pet, the caller saves.
 */
class WandPlayService
{
    public function __construct(
        private readonly LifeStageService $lifeStages,
        private readonly RoutineLedgerService $ledger,
    ) {}

    // ──────────────────────────────────────────────────────────────
    //  Who / numbers
    // ──────────────────────────────────────────────────────────────

    /** The wand play rules apply to this pet at all: a cat with a profile (never a dog). */
    public function appliesTo(Pet $pet): bool
    {
        return $pet->isCat() && ! $pet->isLegacyProfile();
    }

    /** Successful sessions the cat needs on a family-local date (0 = no play routine: dog, no data). */
    public function goalOn(Pet $pet, string $localDate): int
    {
        if (! $this->appliesTo($pet)) {
            return 0;
        }
        $value = $this->lifeStages->stageValueOn($pet, $localDate, StageParamKey::PlaySessionsPerDay)['value'] ?? null;

        return is_int($value) && $value > 0 ? $value : 0;
    }

    /** Minutes between two successful sessions (0 without data). */
    public function minGapMinutes(Pet $pet, string $localDate): int
    {
        $value = $this->lifeStages->stageValueOn($pet, $localDate, StageParamKey::PlayMinGapMinutes)['value'] ?? null;

        return is_int($value) && $value > 0 ? $value : 0;
    }

    public static function sessionDurationMs(): int
    {
        return max(1, (int) config('wand.session_seconds', 60)) * 1000;
    }

    public static function graceSeconds(): int
    {
        return max(0, (int) config('wand.finish_grace_seconds', 60));
    }

    // ──────────────────────────────────────────────────────────────
    //  Reading
    // ──────────────────────────────────────────────────────────────

    /** Successful sessions on a family-local date — all children, or one child. */
    public function successfulOn(Pet $pet, string $localDate, ?User $child = null): int
    {
        return PetCareSession::where('pet_id', $pet->id)
            ->where('kind', CareSessionKind::WandPlay->value)
            ->where('status', CareSessionStatus::Completed->value)
            ->where('local_date', $localDate)
            ->when($child !== null, fn ($q) => $q->where('user_id', $child->id))
            ->count();
    }

    /** The cat's last successful session (the 2 h gap runs from its finish). */
    public function lastSuccess(Pet $pet): ?PetCareSession
    {
        return PetCareSession::where('pet_id', $pet->id)
            ->where('kind', CareSessionKind::WandPlay->value)
            ->where('status', CareSessionStatus::Completed->value)
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->first();
    }

    /** End of the gap after the last successful session, when that is still ahead of $now. */
    public function gapEndsAt(Pet $pet, CarbonInterface $now): ?CarbonImmutable
    {
        $last = $this->lastSuccess($pet);
        if ($last === null || $last->finished_at === null) {
            return null;
        }
        $gap = $this->minGapMinutes($pet, $pet->localDate($now));
        $end = CarbonImmutable::instance($last->finished_at)->utc()->addMinutes($gap);

        return $end->greaterThan($now) ? $end : null;
    }

    /** The running session of the cat (active, before its TTL, no lock began during it), if any. */
    public function liveSession(Pet $pet, CarbonInterface $now): ?PetCareSession
    {
        return PetCareSession::where('pet_id', $pet->id)
            ->where('kind', CareSessionKind::WandPlay->value)
            ->where('status', CareSessionStatus::Active->value)
            ->where('expires_at', '>', $now)
            ->whereNot(fn ($q) => $this->whereInterrupted($q, $now))
            ->first();
    }

    /** The family-local midnight ending the day of $now (UTC). */
    public function dayEndsAt(Pet $pet, CarbonInterface $now): CarbonImmutable
    {
        return CarbonImmutable::parse($pet->localDate($now), $pet->familyTimezone())->addDay()->startOfDay()->utc();
    }

    /** A session started now would still run (TTL included) at the family-local midnight. */
    public function dayEndingAt(Pet $pet, CarbonInterface $now): bool
    {
        $expires = CarbonImmutable::instance($now)->utc()->startOfSecond()
            ->addMilliseconds(self::sessionDurationMs())->addSeconds(self::graceSeconds());

        return $expires->greaterThan($this->dayEndsAt($pet, $now));
    }

    /**
     * Play meter of a family-local date (0–100, precise): successful
     * sessions / goal × 100, capped. A cat without a goal → 0.
     */
    public function meterOn(Pet $pet, string $localDate): float
    {
        $goal = $this->goalOn($pet, $localDate);

        return $goal > 0 ? min(100.0, $this->successfulOn($pet, $localDate) / $goal * 100) : 0.0;
    }

    /**
     * Would a start by $child be refused right now (pet level + this child),
     * and until when? Null = it may start. The caller adds the locks (423).
     * Shared by start(), the payload (`can_start`) and the reminder push
     * (M3-12: never ask for an action the app refuses).
     *
     * @return array{refusal: CareRefusal, next_allowed_at: CarbonImmutable|null}|null
     */
    public function startRefusal(Pet $pet, ?User $child, CarbonInterface $now): ?array
    {
        $now = CarbonImmutable::instance($now)->utc();
        $refuse = fn (CareRefusal $r, ?CarbonImmutable $next = null): array => ['refusal' => $r, 'next_allowed_at' => $next];

        if ($this->goalOn($pet, $pet->localDate($now)) <= 0) {
            return $refuse(CareRefusal::WandNotAvailable);
        }
        // PRODUCT_SPEC §8: while a mess is open every other action waits for the clean-up.
        if ($pet->displayMetric('hygiene_level') <= 0) {
            return $refuse(CareRefusal::NeedsCleaning);
        }
        if (($gapEnd = $this->gapEndsAt($pet, $now)) !== null) {
            return $refuse(CareRefusal::WandTooSoon, $gapEnd);
        }
        $live = $this->liveSession($pet, $now);
        if ($live !== null && ($child === null || (int) $live->user_id !== $child->id)) {
            return $refuse(CareRefusal::WandSessionActive, CarbonImmutable::instance($live->expires_at)->utc());
        }
        if ($this->dayEndingAt($pet, $now)) {
            return $refuse(CareRefusal::WandDayEnding, $this->dayEndsAt($pet, $now));
        }

        return null;
    }

    // ──────────────────────────────────────────────────────────────
    //  Start / finish (caller holds the pet lock)
    // ──────────────────────────────────────────────────────────────

    /**
     * Start a wand session. Refusals: wand_not_available, needs_cleaning,
     * wand_too_soon (next_allowed = end of the 2 h gap), wand_session_active
     * (another child's game; next_allowed = its TTL), wand_day_ending
     * (next_allowed = local midnight). The same child's own running session
     * is replaced (status `aborted`): "can restart immediately".
     *
     * @return array{refusal: CareRefusal|null, next_allowed_at: CarbonInterface|null, session: PetCareSession|null}
     */
    public function start(Pet $pet, User $child, CarbonInterface $now): array
    {
        $now = CarbonImmutable::instance($now)->utc()->startOfSecond();
        $this->expireStale($pet, $now);

        $refusal = $this->startRefusal($pet, $child, $now);
        if ($refusal !== null) {
            return ['refusal' => $refusal['refusal'], 'next_allowed_at' => $refusal['next_allowed_at'], 'session' => null];
        }

        // The same child restarts: the unfinished game ends without consequence.
        PetCareSession::where('pet_id', $pet->id)
            ->where('kind', CareSessionKind::WandPlay->value)
            ->where('status', CareSessionStatus::Active->value)
            ->update(['status' => CareSessionStatus::Aborted->value, 'updated_at' => $now]);

        $durationMs = self::sessionDurationMs();
        $endsAt = $now->addMilliseconds($durationMs);

        $session = PetCareSession::create([
            'public_id' => (string) Str::uuid(),
            'pet_id' => $pet->id,
            'user_id' => $child->id,
            'kind' => CareSessionKind::WandPlay,
            'local_date' => $pet->localDate($now),
            'started_at' => $now,
            'ends_at' => $endsAt,
            'expires_at' => $endsAt->addSeconds(self::graceSeconds()),
            'duration_ms' => $durationMs,
            'schedule' => $this->schedule($durationMs, new Randomizer),
            'status' => CareSessionStatus::Active,
        ]);

        return ['refusal' => null, 'next_allowed_at' => null, 'session' => $session];
    }

    /**
     * Finish a session with the feather moves the app saw. A repeat for an
     * already finished session of the same child returns it again (`repeat`,
     * nothing written). `counted` = the server judged the child took part:
     * the session is `completed` and counts (meter, routine, gap); otherwise
     * it is `failed` — no consequence.
     *
     * @param  list<array{t: int, away: bool}>  $moves
     * @return array{refusal: CareRefusal|null, session: PetCareSession|null, repeat: bool, counted: bool}
     */
    public function finish(Pet $pet, User $child, string $publicId, array $moves, CarbonInterface $now): array
    {
        $exact = CarbonImmutable::instance($now)->utc();
        $now = $exact->startOfSecond();
        $refuse = fn (CareRefusal $r, ?PetCareSession $s = null): array => ['refusal' => $r, 'session' => $s, 'repeat' => false, 'counted' => false];

        if (! $this->appliesTo($pet)) {
            return $refuse(CareRefusal::WandNotAvailable);
        }

        $session = PetCareSession::where('public_id', $publicId)
            ->where('pet_id', $pet->id)
            ->where('kind', CareSessionKind::WandPlay->value)
            ->first();
        if ($session === null || (int) $session->user_id !== $child->id) {
            return $refuse(CareRefusal::WandSessionInvalid);
        }
        if (in_array($session->status, [CareSessionStatus::Completed, CareSessionStatus::Failed], true)) {
            return ['refusal' => null, 'session' => $session, 'repeat' => true, 'counted' => $session->status === CareSessionStatus::Completed];
        }

        $this->expireStale($pet, $now);
        $session->refresh();
        if ($session->status === CareSessionStatus::Interrupted) {
            return $refuse(CareRefusal::WandSessionInterrupted, $session);
        }
        if ($session->status !== CareSessionStatus::Active || ! $session->expires_at->greaterThan($now)) {
            if ($session->status === CareSessionStatus::Active) {
                $session->forceFill(['status' => CareSessionStatus::Expired])->save();
            }

            return $refuse(CareRefusal::WandSessionExpired, $session);
        }
        $tolerance = max(0, (int) config('wand.finish_early_tolerance_seconds', 5));
        if ($exact->lessThan(CarbonImmutable::instance($session->ends_at)->utc()->subSeconds($tolerance))) {
            return $refuse(CareRefusal::WandSessionNotOver, $session);
        }
        foreach ($moves as $move) {
            if (! is_array($move) || ! is_int($move['t'] ?? null) || ! is_bool($move['away'] ?? null)
                || $move['t'] < 0 || $move['t'] > $session->duration_ms) {
                return $refuse(CareRefusal::WandInvalidMoves, $session);
            }
        }

        $result = self::score($moves, $session->duration_ms, array_map('intval', array_values($session->schedule['pounces_ms'] ?? [])));
        if ($result['reason'] === 'too_uniform') {
            // Like training's scripted-latency log (pet id only, no child data).
            Log::warning('WandPlayService: wand session with machine-regular moves', ['pet_id' => $pet->id]);
        }
        $session->forceFill([
            'status' => $result['success'] ? CareSessionStatus::Completed : CareSessionStatus::Failed,
            'finished_at' => $now,
            'result' => $result,
        ])->save();

        return ['refusal' => null, 'session' => $session, 'repeat' => false, 'counted' => $result['success']];
    }

    /**
     * After a successful finish (caller holds the lock): the play meter of
     * today (never lowered), and the session's number of the day for the
     * activity row. Attributes on $pet; the caller saves.
     */
    public function applySuccess(Pet $pet, PetCareSession $session): int
    {
        $date = $session->localDateString();
        $count = $this->successfulOn($pet, $date);
        if ($pet->localDate(now()) === $date) {
            $pet->energy_level = max((float) $pet->energy_level, $this->meterOn($pet, $date));
            $pet->energy_zero_since = null;
        }

        return $count;
    }

    /**
     * Judge the reported feather moves (pure — no I/O). Moves closer than
     * `min_move_interval_ms` to the previous counted move count once. A
     * session counts when there are ≥ `min_away_moves` "away" moves, at
     * least one in each of `segments` equal parts of the game, "away" moves
     * are ≥ `min_away_share` of all counted moves, an "away" move follows
     * every pounce cue within `pounce_window_ms` (QA: the child reacts to the
     * cat), and the gaps between counted moves are not machine-regular
     * (≥ `uniform_min_gaps` gaps all within `uniform_max_spread_ms` → scripted).
     *
     * @param  list<array{t: int, away: bool}>  $moves
     * @param  list<int>  $pounces  pounce cues of the schedule (ms since start)
     * @return array{success: bool, reason: 'too_few_moves'|'not_spread'|'wrong_technique'|'missed_pounces'|'too_uniform'|null, away_moves: int, toward_moves: int, segments_hit: int, segments: int, min_away_moves: int, pounces_hit: int, pounces: int}
     */
    public static function score(array $moves, int $durationMs, array $pounces = []): array
    {
        $minAway = max(1, (int) config('wand.min_away_moves', 8));
        $segments = max(1, (int) config('wand.segments', 4));
        $interval = max(0, (int) config('wand.min_move_interval_ms', 300));
        $minShare = max(0.0, min(1.0, (float) config('wand.min_away_share', 0.5)));
        $window = max(0, (int) config('wand.pounce_window_ms', 2000));
        $uniformGaps = max(2, (int) config('wand.uniform_min_gaps', 6));
        $uniformSpread = max(0, (int) config('wand.uniform_max_spread_ms', 60));

        usort($moves, fn (array $a, array $b): int => $a['t'] <=> $b['t']);

        $away = 0;
        $toward = 0;
        $hit = [];
        $last = null;
        $awayTimes = [];
        $gaps = [];
        foreach ($moves as $move) {
            if ($last !== null && $interval > $move['t'] - $last) {
                continue;
            }
            if ($last !== null) {
                $gaps[] = $move['t'] - $last;
            }
            $last = $move['t'];
            if ($move['away']) {
                $away++;
                $awayTimes[] = $move['t'];
                $hit[min($segments - 1, intdiv($move['t'] * $segments, max(1, $durationMs)))] = true;
            } else {
                $toward++;
            }
        }

        $pouncesHit = 0;
        foreach ($pounces as $p) {
            foreach ($awayTimes as $t) {
                if ($t >= $p && $t <= $p + $window) {
                    $pouncesHit++;
                    break;
                }
            }
        }
        $uniform = count($gaps) >= $uniformGaps && max($gaps) - min($gaps) <= $uniformSpread;

        $reason = match (true) {
            $away < $minAway => 'too_few_moves',
            count($hit) < $segments => 'not_spread',
            $away < $minShare * ($away + $toward) => 'wrong_technique',
            $pouncesHit < count($pounces) => 'missed_pounces',
            $uniform => 'too_uniform',
            default => null,
        };

        return [
            'success' => $reason === null,
            'reason' => $reason,
            'away_moves' => $away,
            'toward_moves' => $toward,
            'segments_hit' => count($hit),
            'segments' => $segments,
            'min_away_moves' => $minAway,
            'pounces_hit' => $pouncesHit,
            'pounces' => count($pounces),
        ];
    }

    /**
     * A new schedule: the cat pounces a few times (animation cues) and
     * catches the feather at the end (`catch_at_ms` = the duration).
     *
     * @return array{catch_at_ms: int, pounces_ms: list<int>, min_away_moves: int, segments: int, min_move_interval_ms: int, pounce_window_ms: int}
     */
    public function schedule(int $durationMs, Randomizer $rng): array
    {
        $margin = max(0, (int) config('wand.pounce_margin_ms', 8000));
        $min = max(0, (int) config('wand.pounces_min', 3));
        $max = max($min, (int) config('wand.pounces_max', 5));
        $pounces = [];
        if ($durationMs - 2 * $margin > 0) {
            $count = $rng->getInt($min, $max);
            for ($i = 0; $i < $count; $i++) {
                $pounces[] = $rng->getInt($margin, $durationMs - $margin);
            }
            sort($pounces);
        }

        return [
            'catch_at_ms' => $durationMs,
            'pounces_ms' => array_values(array_unique($pounces)),
            'min_away_moves' => max(1, (int) config('wand.min_away_moves', 8)),
            'segments' => max(1, (int) config('wand.segments', 4)),
            'min_move_interval_ms' => max(0, (int) config('wand.min_move_interval_ms', 300)),
            'pounce_window_ms' => max(0, (int) config('wand.pounce_window_ms', 2000)),
        ];
    }

    /**
     * Settle active sessions: one during which a lock began → `interrupted`;
     * one past its TTL → `expired`. Frees the one-active slot. Returns the
     * number of sessions settled (the tick broadcasts `wand_ended` then).
     */
    public function expireStale(Pet $pet, CarbonInterface $now): int
    {
        return PetCareSession::where('pet_id', $pet->id)
            ->where('kind', CareSessionKind::WandPlay->value)
            ->where('status', CareSessionStatus::Active->value)
            ->where(fn ($q) => $this->whereInterrupted($q, $now))
            ->update(['status' => CareSessionStatus::Interrupted->value, 'updated_at' => $now])
            + PetCareSession::where('pet_id', $pet->id)
                ->where('kind', CareSessionKind::WandPlay->value)
                ->where('status', CareSessionStatus::Active->value)
                ->where('expires_at', '<=', $now)
                ->update(['status' => CareSessionStatus::Expired->value, 'updated_at' => $now]);
    }

    /**
     * Sessions during which a lock began (hard stop, vet, inactive / game
     * over, payment lock — pet_status_periods): the same rule as training.
     *
     * @param  Builder<PetCareSession>  $query
     */
    private function whereInterrupted($query, CarbonInterface $now): void
    {
        $at = CarbonImmutable::instance($now)->utc()->format('Y-m-d H:i:s');
        $query->whereExists(fn ($q) => $q->selectRaw('1')->from('pet_status_periods')
            ->whereColumn('pet_status_periods.pet_id', 'pet_care_sessions.pet_id')
            ->whereColumn('pet_status_periods.started_at', '>', 'pet_care_sessions.started_at')
            ->whereColumn('pet_status_periods.started_at', '<=', 'pet_care_sessions.expires_at')
            ->where('pet_status_periods.started_at', '<=', $at));
    }

    // ──────────────────────────────────────────────────────────────
    //  Midnight (DailyWalkService::closeDayIfNeeded, under the lock)
    // ──────────────────────────────────────────────────────────────

    /**
     * The cat's family-local day $closedDate ended: when the ledger's play
     * routine of that day is MISSED (not the birth day, not excused by a
     * long hard stop / vet / game over, goal not reached), remember the day
     * in `pets.play_missed_on` — M5-R06-05 turns it into "scratched the sofa"
     * the next day (CAT_SPEC Q2 / Q10). Never an illness. Only the day right
     * before $now counts (after a scheduler outage nothing is made up).
     * Attribute on $pet; the caller saves.
     */
    public function closeDay(Pet $pet, string $closedDate, CarbonInterface $now): bool
    {
        if (! $this->appliesTo($pet) || $this->goalOn($pet, $closedDate) <= 0) {
            return false;
        }
        $yesterday = CarbonImmutable::parse($pet->localDate($now), 'UTC')->subDay()->toDateString();
        if ($closedDate !== $yesterday) {
            return false;
        }

        $routines = $this->ledger->routinesFor(collect([$pet]), $closedDate, $closedDate, $now)[$pet->id] ?? [];
        foreach ($routines as $routine) {
            if ($routine->type === RoutineType::Play && $routine->status === RoutineStatus::Missed) {
                $pet->play_missed_on = $closedDate;

                return true;
            }
        }

        return false;
    }

    /**
     * The activity rows this feature writes (one per successful session).
     *
     * @return list<ActivityType>
     */
    public static function activityTypes(): array
    {
        return [ActivityType::PlayedWand];
    }
}
