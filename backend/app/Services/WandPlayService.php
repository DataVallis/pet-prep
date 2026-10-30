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
use App\Models\QuietHours;
use App\Models\User;
use App\Services\Media\GameMediaReadiness;
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
 * Rules v2 (GAMES_SPEC §5, David 2026-10-30, P1 + P2): a start with `rules: 2`
 * (new app) gets ONE ~25 s hunt (scheduleV2(): three pounces with a crouch cue,
 * the catch at the end) judged in stars (scoreV2(): 3 dodges + the catch,
 * counted at ≥ 2). The version lives in the session's schedule (`rules_version`,
 * rulesOf()); a start without it keeps the v1 minute and score() byte for byte.
 * Goal, 2 h gap, play meter, routine and the scratched sofa are the same for
 * both. Start refusals (quiet hours, day ending) keep reserving the v1 minute
 * for both versions — the payload's `can_start` and every start agree.
 *
 * Only cats with a profile (life-stage data); every caller that writes
 * holds the pet's row lock (backend/CLAUDE.md); pet attributes are set on
 * $pet, the caller saves.
 */
class WandPlayService
{
    /** GAMES_SPEC §7.2: the v1 minute (old apps, a start without `rules`). */
    public const RULES_V1 = 1;

    /** GAMES_SPEC §5 (David 2026-10-30, P1 + P2): one ~25 s hunt scored in stars. */
    public const RULES_V2 = 2;

    public function __construct(
        private readonly LifeStageService $lifeStages,
        private readonly RoutineLedgerService $ledger,
        private readonly GameMediaReadiness $media,
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
        // David 2026-10-08 ~21:5x: the cat sleeps in quiet hours — no game now,
        // and none that would still run (TTL included) when they begin.
        if (($quietEnd = $this->quietBlocksUntil($pet, $now)) !== null) {
            return $refuse(CareRefusal::WandQuietHours, $quietEnd);
        }
        // M5-T04 (David 2026-10-20, APP_REFINEMENT_SPEC §10): a clip of the game is
        // not stored yet — no start (»Igra se še pripravlja«), never half working.
        if (! $this->mediaReady($pet)) {
            return $refuse(CareRefusal::MediaNotReady);
        }
        // QA M5-R06-05 m1: one game with the cat at a time (grooming, litter change, scratching).
        if (($other = CatChoreService::otherLiveRefusal($pet, CareSessionKind::WandPlay, $child, $now)) !== null) {
            return $other;
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

    /** M5-T04: every clip the wand game shows (`media.game_clips.wand`) is stored for the cat. */
    public function mediaReady(Pet $pet): bool
    {
        return $this->media->ready($pet, GameMediaReadiness::WAND);
    }

    /**
     * When quiet hours forbid a game started at $now: the end of the quiet
     * stretch that is on now, or of the one the game (duration + TTL) would
     * run into. Null = the game fits outside quiet hours.
     */
    public function quietBlocksUntil(Pet $pet, CarbonInterface $now): ?CarbonImmutable
    {
        $quiet = $pet->quietHours();
        if (! $quiet->is_active) {
            return null;
        }
        $start = CarbonImmutable::instance($now)->utc()->startOfSecond();
        $end = $start->addMilliseconds(self::sessionDurationMs())->addSeconds(self::graceSeconds());
        foreach (QuietHours::segmentsBetween($quiet, $start, $end) as [$segStart, , $isQuiet]) {
            if ($isQuiet) {
                return $this->endOfQuiet($quiet, CarbonImmutable::instance($segStart)->utc());
            }
        }

        return null;
    }

    /** The first non-quiet instant at or after $from (bounded hops; DST-safe via QuietHours). */
    private function endOfQuiet(QuietHours $quiet, CarbonImmutable $from): CarbonImmutable
    {
        $at = $from;
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
     * Derived rule (David 2026-10-08 ~21:5x, DECISIONS): how many successful
     * games fit into a family-local day [$dayStart, $dayEnd) outside quiet
     * hours — greedy from the earliest moment: a game needs duration + TTL
     * fully outside quiet hours and before midnight; the next may start
     * $gapMinutes after the previous finish. A day whose quiet hours leave
     * room for fewer games than the goal is not expected (like the birth
     * day) — the ledger excuses it. Pure apart from QuietHours.
     */
    public static function feasibleSessions(?QuietHours $quiet, CarbonInterface $dayStart, CarbonInterface $dayEnd, int $gapMinutes, int $cap = 24): int
    {
        $durationS = intdiv(self::sessionDurationMs() + 999, 1000);
        $need = $durationS + self::graceSeconds();
        $start = CarbonImmutable::instance($dayStart)->utc();
        $end = CarbonImmutable::instance($dayEnd)->utc();
        $segments = $quiet === null || ! $quiet->is_active
            ? [[$start, $end, false]]
            : QuietHours::segmentsBetween($quiet, $start, $end);

        $count = 0;
        $t = $start;
        foreach ($segments as [$a, $b, $isQuiet]) {
            if ($isQuiet) {
                continue;
            }
            $a = CarbonImmutable::instance($a)->utc();
            $b = CarbonImmutable::instance($b)->utc();
            if ($t->lessThan($a)) {
                $t = $a;
            }
            while ($count < $cap && $t->addSeconds($need)->lessThanOrEqualTo($b)) {
                $count++;
                $t = $t->addSeconds($durationS)->addMinutes(max(0, $gapMinutes));
            }
        }

        return $count;
    }

    // ──────────────────────────────────────────────────────────────
    //  Start / finish (caller holds the pet lock)
    // ──────────────────────────────────────────────────────────────

    /**
     * Start a wand session. Refusals: wand_not_available, needs_cleaning,
     * wand_quiet_hours (the cat sleeps — now, or before the game would end;
     * next_allowed_at = end of that quiet stretch), wand_too_soon (next_allowed = end of the 2 h gap), wand_session_active
     * (another child's game; next_allowed = its TTL), wand_day_ending
     * (next_allowed = local midnight). The same child's own running session
     * is replaced (status `aborted`): "can restart immediately".
     *
     * @return array{refusal: CareRefusal|null, next_allowed_at: CarbonInterface|null, session: PetCareSession|null}
     */
    public function start(Pet $pet, User $child, CarbonInterface $now, int $rules = self::RULES_V1): array
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
        CatChoreService::abortOwnOtherKinds($pet, CareSessionKind::WandPlay, $child, $now);

        // GAMES_SPEC §7.2: a new app asks for rules v2 (one ~25 s hunt in stars); without it
        // the v1 minute stays byte for byte. The session stores its version in the schedule.
        if ($rules === self::RULES_V2) {
            $schedule = self::scheduleV2(new Randomizer);
            $durationMs = $schedule['duration_ms'];
        } else {
            $durationMs = self::sessionDurationMs();
            $schedule = $this->schedule($durationMs, new Randomizer);
        }
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
            'schedule' => $schedule,
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
                || $move['t'] < 0 || $move['t'] > $session->duration_ms
                || (array_key_exists('in_zone', $move) && ! is_bool($move['in_zone']))
                || (array_key_exists('speed_ok', $move) && ! is_bool($move['speed_ok']))) {
                return $refuse(CareRefusal::WandInvalidMoves, $session);
            }
        }

        if (self::rulesOf($session) === self::RULES_V2) {
            $result = self::scoreV2($moves, (array) $session->schedule);
            if ($result['uniform']) {
                // v2: logged only, never a refusal (GAMES_SPEC §5.3 — three target events are too few for statistics).
                Log::warning('WandPlayService: wand session with machine-regular moves', ['pet_id' => $pet->id]);
            }
        } else {
            $result = self::score($moves, $session->duration_ms, array_map('intval', array_values($session->schedule['pounces_ms'] ?? [])));
        }
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
     * Rules v1 (old apps). Judge the reported feather moves (pure — no I/O). Moves closer than
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

    /** The rules version a session was started with (stored in its schedule; none = v1). */
    public static function rulesOf(PetCareSession $session): int
    {
        return (int) (((array) $session->schedule)['rules_version'] ?? self::RULES_V1) === self::RULES_V2 ? self::RULES_V2 : self::RULES_V1;
    }

    /**
     * Rules v2 schedule (GAMES_SPEC §5.2, config `wand.v2`): stalk, three pounces with a
     * crouch cue `crouch_cue_ms` before each, the catch `catch_after_last_ms` after the last
     * pounce and the end `catch_clip_ms` after the catch (~23–29 s, ≤ `round_ms`). Carries
     * every number the app needs to mirror the server's star rules. Pure apart from the RNG.
     *
     * @return array{rules_version: int, duration_ms: int, stalk_ms: int, pounces_ms: list<int>, crouch_cues_ms: list<int>, catch_at_ms: int, catch_window_ms: int, calm_window_ms: int, calm_max_moves: int, dodge_window_ms: array{0: int, 1: int}, min_move_interval_ms: int, min_stars: int, max_stars: int, max_speed_pt_s: int, min_dodge_pt: int}
     */
    public static function scheduleV2(Randomizer $rng): array
    {
        $c = (array) config('wand.v2', []);
        $range = function (string $key, array $default) use ($c): array {
            $v = array_values((array) ($c[$key] ?? $default));
            $lo = max(0, (int) ($v[0] ?? $default[0]));

            return [$lo, max($lo, (int) ($v[1] ?? $default[1]))];
        };
        $stalk = max(0, (int) ($c['stalk_ms'] ?? 3000));
        $count = max(1, (int) ($c['pounces'] ?? 3));
        $cue = max(0, (int) ($c['crouch_cue_ms'] ?? 900));
        [$firstLo, $firstHi] = $range('first_pounce_ms', [6000, 7500]);
        [$gapLo, $gapHi] = $range('pounce_gap_ms', [5000, 6500]);

        $pounces = [];
        $p = max($stalk + $cue, $rng->getInt($firstLo, $firstHi));
        for ($i = 0; $i < $count; $i++) {
            $pounces[] = $p;
            $p += $rng->getInt($gapLo, $gapHi);
        }
        $catchAt = end($pounces) + max(0, (int) ($c['catch_after_last_ms'] ?? 3500));
        $duration = min(max(1, (int) ($c['round_ms'] ?? 30000)), $catchAt + max(0, (int) ($c['catch_clip_ms'] ?? 3500)));
        $catchAt = min($catchAt, $duration);

        return [
            'rules_version' => self::RULES_V2,
            'duration_ms' => $duration,
            'stalk_ms' => $stalk,
            'pounces_ms' => $pounces,
            'crouch_cues_ms' => array_map(fn (int $at): int => max(0, $at - $cue), $pounces),
            'catch_at_ms' => $catchAt,
            'catch_window_ms' => max(0, (int) ($c['catch_window_ms'] ?? 1500)),
            'calm_window_ms' => max(0, (int) ($c['calm_window_ms'] ?? 800)),
            'calm_max_moves' => max(0, (int) ($c['calm_max_moves'] ?? 1)),
            'dodge_window_ms' => $range('dodge_window_ms', [150, 1500]),
            'min_move_interval_ms' => max(0, (int) config('wand.min_move_interval_ms', 300)),
            'min_stars' => max(1, (int) ($c['min_stars'] ?? 2)),
            'max_stars' => $count + 1,
            'max_speed_pt_s' => max(1, (int) ($c['max_speed_pt_s'] ?? 4000)),
            'min_dodge_pt' => max(0, (int) ($c['min_dodge_pt'] ?? 40)),
        ];
    }

    /**
     * Rules v2 verdict (GAMES_SPEC §5.3, David 2026-10-30 P2) — pure, no I/O. Moves closer
     * than `min_move_interval_ms` to the previous counted move count once (as v1). One star
     * per pounce p when ALL hold:
     *  1. calm: at most `calm_max_moves` counted moves (any direction) in [p − calm_window, p);
     *  2. reaction: the FIRST counted away move at or after p lies in [p + dodge_window[0],
     *     p + dodge_window[1]] (earlier = `too_early`, none in time = `no_dodge`);
     *  3. that move ended inside the play zone (`in_zone: true`, reported by the app);
     *  4. it was a clean stroke (`speed_ok: true`: ≤ max_speed_pt_s and ≥ min_dodge_pt).
     * A move without `in_zone` / `speed_ok` fails 3 / 4 (fail closed — the v2 app sends both).
     * One star for the catch c: no counted away move in [c, c + catch_window] and the feather
     * is in the zone (the last counted move up to c + catch_window, if any, has `in_zone`).
     * The hunt counts at ≥ `min_stars`. `hint` = the first failed event in time (calm | dodge
     * | catch, GAMES_SPEC §5.4), null with every star. `uniform` = machine-regular gaps (the
     * v1 `uniform_*` test) — logged only, never a refusal in v2.
     *
     * Why spam earns nothing: continuous rapid strokes put ≥ 2 counted moves into every calm
     * window (rule 1) and an away move into the catch window — 0 stars.
     *
     * @param  list<array{t: int, away: bool, in_zone?: bool, speed_ok?: bool}>  $moves
     * @param  array<string, mixed>  $schedule  a scheduleV2() schedule
     * @return array{success: bool, reason: 'too_few_stars'|null, stars: int, min_stars: int, max_stars: int, dodges: list<array{pounce_ms: int, star: bool, reason: 'moved_during_crouch'|'too_early'|'no_dodge'|'out_of_zone'|'bad_stroke'|null, move_ms: int|null}>, catch: array{star: bool, reason: 'pulled_away'|'out_of_zone'|null}, hint: 'calm'|'dodge'|'catch'|null, uniform: bool, away_moves: int, toward_moves: int, segments_hit: int, segments: int, min_away_moves: int, pounces_hit: int, pounces: int}
     */
    public static function scoreV2(array $moves, array $schedule): array
    {
        $interval = max(0, (int) ($schedule['min_move_interval_ms'] ?? 300));
        $calmWindow = max(0, (int) ($schedule['calm_window_ms'] ?? 800));
        $calmMax = max(0, (int) ($schedule['calm_max_moves'] ?? 1));
        $dodge = array_values((array) ($schedule['dodge_window_ms'] ?? [150, 1500]));
        $dodgeFrom = (int) ($dodge[0] ?? 150);
        $dodgeTo = (int) ($dodge[1] ?? 1500);
        $catchAt = (int) ($schedule['catch_at_ms'] ?? 0);
        $catchWindow = max(0, (int) ($schedule['catch_window_ms'] ?? 1500));
        $pounces = array_map('intval', array_values((array) ($schedule['pounces_ms'] ?? [])));
        $minStars = max(1, (int) ($schedule['min_stars'] ?? 2));
        $uniformGaps = max(2, (int) config('wand.uniform_min_gaps', 6));
        $uniformSpread = max(0, (int) config('wand.uniform_max_spread_ms', 60));

        usort($moves, fn (array $a, array $b): int => $a['t'] <=> $b['t']);

        /** @var list<array{t: int, away: bool, in_zone: bool, speed_ok: bool}> $counted */
        $counted = [];
        $gaps = [];
        $last = null;
        foreach ($moves as $move) {
            if ($last !== null && $interval > $move['t'] - $last) {
                continue;
            }
            if ($last !== null) {
                $gaps[] = $move['t'] - $last;
            }
            $last = $move['t'];
            $counted[] = [
                't' => (int) $move['t'],
                'away' => (bool) $move['away'],
                'in_zone' => ($move['in_zone'] ?? false) === true,
                'speed_ok' => ($move['speed_ok'] ?? false) === true,
            ];
        }

        $failures = []; // [time, hint]
        $dodges = [];
        $stars = 0;
        foreach ($pounces as $p) {
            $calmMoves = count(array_filter($counted, fn (array $m): bool => $m['t'] >= $p - $calmWindow && $m['t'] < $p));
            $first = null;
            foreach ($counted as $m) {
                if ($m['away'] && $m['t'] >= $p) {
                    $first = $m;
                    break;
                }
            }
            $inTime = $first !== null && $first['t'] >= $p + $dodgeFrom && $first['t'] <= $p + $dodgeTo;
            $reason = match (true) {
                $calmMoves > $calmMax => 'moved_during_crouch',
                $first !== null && $first['t'] < $p + $dodgeFrom => 'too_early',
                ! $inTime => 'no_dodge',
                ! $first['in_zone'] => 'out_of_zone',
                ! $first['speed_ok'] => 'bad_stroke',
                default => null,
            };
            if ($reason === null) {
                $stars++;
            } else {
                $failures[] = [$p, $reason === 'moved_during_crouch' ? 'calm' : 'dodge'];
            }
            $dodges[] = ['pounce_ms' => $p, 'star' => $reason === null, 'reason' => $reason, 'move_ms' => $inTime ? $first['t'] : null];
        }

        $catchEnd = $catchAt + $catchWindow;
        $pulled = false;
        $lastBefore = null;
        foreach ($counted as $m) {
            if ($m['t'] > $catchEnd) {
                break;
            }
            $lastBefore = $m;
            if ($m['away'] && $m['t'] >= $catchAt) {
                $pulled = true;
            }
        }
        $catchReason = match (true) {
            $pulled => 'pulled_away',
            $lastBefore !== null && ! $lastBefore['in_zone'] => 'out_of_zone',
            default => null,
        };
        if ($catchReason === null) {
            $stars++;
        } else {
            $failures[] = [$catchAt, 'catch'];
        }

        usort($failures, fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $away = count(array_filter($counted, fn (array $m): bool => $m['away']));

        return [
            'success' => $stars >= $minStars,
            'reason' => $stars >= $minStars ? null : 'too_few_stars',
            'stars' => $stars,
            'min_stars' => $minStars,
            'max_stars' => count($pounces) + 1,
            'dodges' => $dodges,
            'catch' => ['star' => $catchReason === null, 'reason' => $catchReason],
            'hint' => $failures[0][1] ?? null,
            'uniform' => count($gaps) >= $uniformGaps && max($gaps) - min($gaps) <= $uniformSpread,
            // v1 keys kept so the result shape stays one type (counts only in v2).
            'away_moves' => $away,
            'toward_moves' => count($counted) - $away,
            'segments_hit' => 0,
            'segments' => 0,
            'min_away_moves' => 0,
            'pounces_hit' => count(array_filter($dodges, fn (array $d): bool => $d['star'])),
            'pounces' => count($pounces),
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
