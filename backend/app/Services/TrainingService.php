<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\CareRefusal;
use App\Enums\LifeStage;
use App\Enums\RoutineStatus;
use App\Enums\RoutineType;
use App\Enums\StageParamKey;
use App\Enums\TrainingCommand;
use App\Models\ActivityLog;
use App\Models\Pet;
use App\Models\PetCaretaker;
use App\Models\PetTrainingSession;
use App\Models\PetTrainingSkill;
use App\Models\User;
use App\Services\Media\GameMediaReadiness;
use App\Services\Media\SchoolClipDerivativeService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Training ("šolanje", M5-R03, David 2026-10-06, REALISM_SPEC §4,
 * PRODUCT_SPEC §5 / §11).
 *
 * Mini-game = reward timing. The child picks a command (sit, come, place,
 * potty) and starts a session; the SERVER generates the schedule (school smooth
 * playback, David 2026-10-28, SCHOOL_SMOOTH_PLAYBACK.md §B): after a lead-in,
 * TRIALS_PER_SESSION (6) cues; at each cue the app gives the command and the dog
 * either obeys or not. An obeying dog »thinks« THINK_MIN–MAX_MS, then plays its
 * performing clip from frame 0 (perform_at_ms) and reaches the pose exactly when
 * the clip ends (obey_at_ms = perform_at + the pet's STORED performing length P);
 * after the praise window it stands up again (release_at_ms, the release clip of
 * length R) and stays calm CALM_MS before the next cue; an ignored cue takes
 * IGNORE_SLOT_MS. The child must tap "Pohvali" inside PRAISE_WINDOW_MS right after
 * the dog obeys. At finish the app sends only
 * its tap offsets (ms since the session start); the server scores them
 * against its own stored schedule — the first tap of a trial decides:
 * in_time (progress), too_early / too_late / no_praise (no progress), and
 * for a trial where the dog did not obey: waited / praised_without_obeying.
 * The mini-game timings are UI mechanics (constants here, PRODUCT_SPEC §5),
 * not dog data.
 *
 * Progress per command 0–100 % (precise double, rounded for output):
 * + training_progress_per_success × training_learning_multiplier (breed:
 * Border Collie 2×, mixed breed 1×, David) × the pet's individual factor
 * (mixed breed: uniform ±training_individual_variation = ±20 %, David;
 * drawn once from an RNG seeded per pet and stored in
 * pets.training_learning_factor) × the session pace factor
 * PACE_REFERENCE_TRIALS / trials of the session (8 / 6 since 2026-10-28 — a full
 * session moves a skill as much as an 8-cue session did; 1 for an old 8-cue
 * schedule) per in_time trial. The dog obeys more
 * often the better it knows the command (OBEY_CHANCE_*); every session has
 * at least one cue the dog obeys and one it ignores.
 *
 * Budget: training_minutes_per_day of mini-game per dog and family-local
 * day (5 min, S36 / S37, confirmed by David 2026-10-06); every started
 * session counts its full length. Fair share (M5-R03b, David 2026-10-06):
 * the budget is split equally between the children who can train the dog
 * — active caretakers who signed their contract (or grandfathered ones);
 * one child alone = the whole budget. A child cannot start a session that
 * would exceed THEIR share (training_child_share_used); the pet-wide check
 * stays. One active session per pet; a session can be finished from its
 * scheduled end (minus FINISH_EARLY_TOLERANCE_MS) until FINISH_GRACE_SECONDS
 * later (TTL); a later finish is refused and the session expires. Only the
 * child who started it can finish it.
 *
 * Routine: a completed session writes one `trained_pet` activity row (actor
 * = child) — the day's training routine (RoutineLedgerService). A day whose
 * training routine was MISSED (not excused) costs every command
 * training_decay_per_missed_day points (applyDecay(), at the first tick
 * after the family-local midnight).
 *
 * Starting progress (M5-R03b, David 2026-10-06): a dog arriving young, adult
 * or senior (bought or adopted) already knows some commands —
 * training_starting_progress of its arrival stage, applied once when the
 * pet is created (applyStartingProgress); a puppy starts at 0.
 *
 * Effects (proposals): potty progress lets a puppy "ask to go out" instead
 * of having an accident (accidentAvoidanceChance, S47); place progress
 * lowers the teething chewing chance (chewingChanceFactor, S33).
 *
 * Rules v2 (GAMES_SPEC §4.1, David 2026-10-30 Š1–Š3): a start with `rules: 2` (new app)
 * gets 2 rounds × 3 cues with a ROUND_BREAK_MS break after cue 3, R = MASK_MS (the release
 * clip is no longer played — a cut under the feedback mask), and may end after round 1
 * (`ended_after_round: 1`: progress counts, the routine does not, duration = round 1).
 * A start reserves its version's maxSessionMs(); the child state reserves the larger of
 * both. Praise window, obey chance, progress / decay, budget, fair share and routines are
 * the same for both versions; the version lives in the schedule (`rules_version`).
 *
 * Only pets with training enabled (Pet::trainingEnabled — profile + app
 * feature `training`); legacy-profile pets never. All callers hold the
 * pet's row lock (backend/CLAUDE.md); pet attributes are written on $pet,
 * the caller saves.
 */
class TrainingService
{
    /** David 2026-10-28: 6 commands per session (~60 s; was 8 × 6 s). */
    public const TRIALS_PER_SESSION = 6;

    /** GAMES_SPEC §7.2: v1 schedule (old apps, a start without `rules`). */
    public const RULES_V1 = 1;

    /** GAMES_SPEC §4.1 (David 2026-10-30, Š1–Š3): 2 rounds × 3 cues, the mask instead of the release clip. */
    public const RULES_V2 = 2;

    /** v2 (Š1): cues per round — TRIALS_PER_SESSION / TRIALS_PER_ROUND rounds. */
    public const TRIALS_PER_ROUND = 3;

    /** v2 (Š1): the break after each round but the last (cue 3 → cue 4). */
    public const ROUND_BREAK_MS = 2500;

    /** v2 (Š2): the feedback mask the cut success_loop → attention_loop hides under — R of the schedule. */
    public const MASK_MS = 1200;

    /**
     * Learning pace (orchestrator 2026-10-28, kept): progress per on-time praise ×
     * PACE_REFERENCE_TRIALS / trials of the session, so a full 6-cue session moves a
     * skill as much as the former 8-cue one (an old 8-cue schedule: × 1).
     */
    public const PACE_REFERENCE_TRIALS = 8;

    public const LEAD_IN_MS = 2000;

    /** The dog »thinks« this long after the cue before its performing clip starts (random, unpredictable). */
    public const THINK_MIN_MS = 300;

    public const THINK_MAX_MS = 1500;

    public const PRAISE_WINDOW_MS = 1500;

    /** A short calm moment standing after the release clip, before the next cue. */
    public const CALM_MS = 700;

    /** Slot of a cue the dog ignores (cue → next cue). */
    public const IGNORE_SLOT_MS = 4500;

    /** P / R without a stored clip (contract §B). */
    public const DEFAULT_PERFORMING_MS = SchoolClipDerivativeService::DEFAULT_PERFORMING_MS;

    public const DEFAULT_RELEASE_MS = SchoolClipDerivativeService::DEFAULT_RELEASE_MS;

    /** Safety bounds on stored lengths (a broken duration never makes an endless or instant trial). */
    public const MIN_PERFORMING_MS = 1000;

    public const MAX_PERFORMING_MS = 6000;

    public const MIN_RELEASE_MS = 500;

    public const MAX_RELEASE_MS = 2500;

    /**
     * Human reaction floor (PR #53 M1): a praise less than this long after
     * the dog obeys cannot be a reaction to it — it counts as `too_early`.
     */
    public const MIN_REACTION_MS = 150;

    /**
     * PR #53 M1: a session whose in-time praises (≥ SUSPICIOUS_MIN_TAPS) all
     * have the same latency within ±SUSPICIOUS_LATENCY_SPREAD_MS looks
     * scripted — logged (pet id only), still scored.
     */
    public const SUSPICIOUS_MIN_TAPS = 3;

    public const SUSPICIOUS_LATENCY_SPREAD_MS = 30;

    /** Chance the dog obeys a cue at 0 % / 100 % progress (linear in between). */
    public const OBEY_CHANCE_UNTRAINED = 0.5;

    public const OBEY_CHANCE_TRAINED = 0.9;

    /** TTL: a finish is accepted until this long after the scheduled end. */
    public const FINISH_GRACE_SECONDS = 60;

    /** A finish this much before the scheduled end is still accepted (clock skew). */
    public const FINISH_EARLY_TOLERANCE_MS = 2000;

    public const MAX_TAPS = 64;

    /** Missed days decayed in one go at most (scheduler outage). */
    public const MAX_DECAY_DAYS = HygieneEventService::MAX_CATCH_UP_DAYS;

    public function __construct(
        private readonly LifeStageService $lifeStages,
        private readonly GameMediaReadiness $media,
        private readonly RoutineLedgerService $ledger,
        private readonly PetSleepService $sleep,
        private ?string $seedSalt = null,
        // Tests only (DogRegressionSnapshotTest): a fixed seed makes the schedule reproducible
        // (per start instant — pet ids differ between test runs). Null in production = the secure RNG.
        private ?string $scheduleSeed = null,
    ) {
        $this->seedSalt ??= (string) config('app.key');
    }

    /**
     * The longest session the given clip lengths can produce (every cue obeyed after the
     * longest think) — what the budget and the midnight checks reserve before the schedule
     * is drawn. The started session counts its real length (duration_ms).
     */
    public static function maxSessionMs(int $performingMs = self::DEFAULT_PERFORMING_MS, int $releaseMs = self::DEFAULT_RELEASE_MS, int $rules = self::RULES_V1): int
    {
        $r = $rules === self::RULES_V2 ? self::MASK_MS : self::clampRelease($releaseMs);
        $obeySlot = self::THINK_MAX_MS + self::clampPerforming($performingMs) + self::PRAISE_WINDOW_MS + $r + self::CALM_MS;
        $breaks = $rules === self::RULES_V2 ? (self::rounds() - 1) * self::ROUND_BREAK_MS : 0;

        return self::LEAD_IN_MS + self::TRIALS_PER_SESSION * max($obeySlot, self::IGNORE_SLOT_MS) + $breaks;
    }

    /** v2: rounds of a session (TRIALS_PER_SESSION / TRIALS_PER_ROUND = 2). */
    public static function rounds(): int
    {
        return intdiv(self::TRIALS_PER_SESSION + self::TRIALS_PER_ROUND - 1, self::TRIALS_PER_ROUND);
    }

    /**
     * maxSessionMs() of this pet: its longest command (stored clip lengths). `$rules` 1 / 2 =
     * that version (what a start of that version reserves); null = the larger of both — the
     * child state (`can_start`, `session_seconds`) does not know the app's version, so it
     * reserves what either start would and never offers a start the server refuses.
     */
    public function maxSessionMsFor(Pet $pet, ?int $rules = null): int
    {
        $versions = $rules === null ? [self::RULES_V1, self::RULES_V2] : [$rules];
        $timings = $this->clipTimings($pet) ?: [['performing_ms' => self::DEFAULT_PERFORMING_MS, 'release_ms' => self::DEFAULT_RELEASE_MS]];
        $max = 0;
        foreach ($timings as $t) {
            foreach ($versions as $v) {
                $max = max($max, self::maxSessionMs($t['performing_ms'], $t['release_ms'], $v));
            }
        }

        return $max;
    }

    /**
     * P and R per command (SchoolClipDerivativeService::timingsFor — the stored performing
     * clip and its release clip; defaults without them).
     *
     * @return array<string, array{performing_ms: int, release_ms: int}>
     */
    public function clipTimings(Pet $pet): array
    {
        return app(SchoolClipDerivativeService::class)->timingsFor($pet);
    }

    private static function clampPerforming(int $ms): int
    {
        return max(self::MIN_PERFORMING_MS, min(self::MAX_PERFORMING_MS, $ms));
    }

    private static function clampRelease(int $ms): int
    {
        return max(self::MIN_RELEASE_MS, min(self::MAX_RELEASE_MS, $ms));
    }

    // ──────────────────────────────────────────────────────────────
    //  Numbers from breed_stage_params
    // ──────────────────────────────────────────────────────────────

    /** The dog's training budget of a family-local day in seconds (0 without data). */
    public function dailyBudgetSeconds(Pet $pet, string $localDate): int
    {
        $minutes = $this->number($pet, $localDate, StageParamKey::TrainingMinutesPerDay);

        return $minutes === null ? 0 : (int) round($minutes * 60);
    }

    /**
     * Seconds of mini-game already started on a family-local day: every
     * session counts in full, except one a lock (hard stop, vet, game over)
     * interrupted — PR #53 m2: its time is refunded. With $child: only the
     * sessions that child started (their fair share, M5-R03b).
     */
    public function usedSecondsOn(Pet $pet, string $localDate, ?User $child = null): int
    {
        $now = now();
        $ms = (int) PetTrainingSession::where('pet_id', $pet->id)->where('local_date', $localDate)
            ->when($child !== null, fn ($q) => $q->where('user_id', $child->id))
            ->where('status', '!=', PetTrainingSession::STATUS_INTERRUPTED)
            ->where(fn ($q) => $q->where('status', '!=', PetTrainingSession::STATUS_ACTIVE)
                ->orWhereNot(fn ($q) => $this->whereInterrupted($q, $now)))
            ->sum('duration_ms');

        return intdiv($ms + 999, 1000);
    }

    /**
     * Children who can train the dog (M5-R03b): ACTIVE caretakers (not a
     * deleted child's tombstone) who signed their own contract, or whose row
     * needs none (grandfathered). A child who joined but has not signed yet
     * is locked (contract_required) and does not reduce the others' share.
     *
     * @return list<int>
     */
    public function trainerIds(Pet $pet): array
    {
        return PetCaretaker::where('pet_id', $pet->id)->active()
            ->where(fn ($q) => $q->where('requires_contract', false)
                ->orWhereExists(fn ($c) => $c->selectRaw('1')->from('pet_contracts')
                    ->whereColumn('pet_contracts.pet_id', 'pet_caretakers.pet_id')
                    ->whereColumn('pet_contracts.user_id', 'pet_caretakers.user_id')))
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * A child's fair share of the dog's daily budget (M5-R03b): budget / n
     * (whole seconds, rounded down) for the n children who can train; one
     * child alone = the whole budget. 0 for a viewer who cannot train.
     * At least one session (QA m3, Claude — waiting for David): with 7+
     * children budget / n would be shorter than a session and nobody could
     * ever train, so each share is max(budget / n, one session); the
     * pet-wide budget still caps the total (the first children to train
     * get it).
     *
     * @param  list<int>|null  $trainerIds  trainerIds() when already loaded
     */
    public function childShareSeconds(Pet $pet, string $localDate, User $child, ?array $trainerIds = null): int
    {
        $trainerIds ??= $this->trainerIds($pet);
        if (! in_array($child->id, $trainerIds, true)) {
            return 0;
        }

        $budget = $this->dailyBudgetSeconds($pet, $localDate);
        if ($budget <= 0) {
            return 0;
        }

        return max(intdiv($budget, count($trainerIds)), $this->sessionSecondsFor($pet));
    }

    /**
     * Whole seconds a session of this pet reserves from the budget before it starts (its
     * longest possible length, maxSessionMsFor); the started session counts its real length.
     */
    public function sessionSecondsFor(Pet $pet, ?int $rules = null): int
    {
        return intdiv($this->maxSessionMsFor($pet, $rules) + 999, 1000);
    }

    /**
     * Progress points one correctly timed praise earns on that day:
     * base × breed multiplier × the pet's individual factor.
     */
    public function gainPerSuccess(Pet $pet, string $localDate): float
    {
        $base = $this->number($pet, $localDate, StageParamKey::TrainingProgressPerSuccess) ?? 0.0;
        $multiplier = $this->number($pet, $localDate, StageParamKey::TrainingLearningMultiplier) ?? 1.0;

        return max(0.0, $base * $multiplier * $this->learningFactor($pet));
    }

    /**
     * The pet's individual learning factor: 1 ± variation (uniform), drawn
     * once from an RNG seeded per pet and stored — stable for the dog's life.
     * No variation row (Border Collie) = 1.0. Writes the attribute on $pet
     * the first time (the caller holds the lock and saves).
     */
    public function learningFactor(Pet $pet): float
    {
        if ($pet->training_learning_factor !== null) {
            return (float) $pet->training_learning_factor;
        }

        $date = $pet->localDate($pet->born_at ?? now());
        $variation = $this->number($pet, $date, StageParamKey::TrainingIndividualVariation) ?? 0.0;
        $variation = max(0.0, min(1.0, $variation));
        $u = $this->rng($pet, 'training-factor')->nextFloat();
        $factor = round(1 + $variation * (2 * $u - 1), 6);

        $pet->training_learning_factor = $factor;

        return $factor;
    }

    /**
     * Share of due puppy accidents a potty-trained puppy avoids (it asks to
     * go out, S47): potty_training_accident_reduction × potty progress.
     */
    public function accidentAvoidanceChance(Pet $pet, string $localDate): float
    {
        if (! $pet->trainingEnabled()) {
            return 0.0;
        }

        $max = $this->number($pet, $localDate, StageParamKey::PottyTrainingAccidentReduction) ?? 0.0;

        return max(0.0, min(1.0, $max * $this->progressOf($pet, TrainingCommand::Potty) / 100));
    }

    /**
     * Multiplier on the teething chewing chance (1 = untrained):
     * 1 − place_training_chewing_reduction × place progress.
     */
    public function chewingChanceFactor(Pet $pet, string $localDate): float
    {
        if (! $pet->trainingEnabled()) {
            return 1.0;
        }

        $max = $this->number($pet, $localDate, StageParamKey::PlaceTrainingChewingReduction) ?? 0.0;

        return max(0.0, min(1.0, 1 - $max * $this->progressOf($pet, TrainingCommand::Place) / 100));
    }

    /**
     * Deterministic roll in [0, 1) for "did the trained puppy ask to go out"
     * at a due accident instant (salted like the behaviour RNGs).
     */
    public function accidentSignalRoll(Pet $pet, CarbonInterface $dueAt): float
    {
        return $this->rng($pet, 'potty-signal|'.CarbonImmutable::instance($dueAt)->utc()->getTimestamp())->nextFloat();
    }

    // ──────────────────────────────────────────────────────────────
    //  Reading
    // ──────────────────────────────────────────────────────────────

    /**
     * Skill rows by command value (missing command = never practised).
     *
     * @return Collection<string, PetTrainingSkill>
     */
    public function skills(Pet $pet): Collection
    {
        return PetTrainingSkill::where('pet_id', $pet->id)->get()->keyBy(fn (PetTrainingSkill $s) => $s->command->value);
    }

    public function progressOf(Pet $pet, TrainingCommand $command): float
    {
        return (float) (PetTrainingSkill::where('pet_id', $pet->id)->where('command', $command->value)->value('progress') ?? 0.0);
    }

    /** The pet's running session (active, not past its TTL, not interrupted by a lock), if any. */
    public function liveSession(Pet $pet, CarbonInterface $now): ?PetTrainingSession
    {
        return PetTrainingSession::where('pet_id', $pet->id)
            ->where('status', PetTrainingSession::STATUS_ACTIVE)
            ->where('expires_at', '>', $now)
            ->whereNot(fn ($q) => $this->whereInterrupted($q, $now))
            ->first();
    }

    /**
     * Sessions during which a lock began (PR #53 m2): a hard stop / illness /
     * inactive (game over) period of the pet started after the session
     * started, before its TTL and not after $now.
     *
     * @param  Builder<PetTrainingSession>  $query
     */
    private function whereInterrupted($query, CarbonInterface $now): void
    {
        $at = CarbonImmutable::instance($now)->utc()->format('Y-m-d H:i:s');
        $query->whereExists(fn ($q) => $q->selectRaw('1')->from('pet_status_periods')
            ->whereColumn('pet_status_periods.pet_id', 'pet_training_sessions.pet_id')
            ->whereColumn('pet_status_periods.started_at', '>', 'pet_training_sessions.started_at')
            ->whereColumn('pet_status_periods.started_at', '<=', 'pet_training_sessions.expires_at')
            ->where('pet_status_periods.started_at', '<=', $at));
    }

    /**
     * The family-local midnight ending the day of $now (UTC). A session must
     * expire before it (PR #53 m1: the routine and the budget belong to one day).
     */
    public function dayEndsAt(Pet $pet, CarbonInterface $now): CarbonImmutable
    {
        return CarbonImmutable::parse($pet->localDate($now), $pet->familyTimezone())->addDay()->startOfDay()->utc();
    }

    /** A session started now would still run (TTL included) at the family-local midnight. */
    public function dayEndingAt(Pet $pet, CarbonInterface $now, ?int $sessionMs = null): bool
    {
        $expires = CarbonImmutable::instance($now)->utc()->startOfSecond()
            ->addMilliseconds($sessionMs ?? $this->maxSessionMsFor($pet))->addSeconds(self::FINISH_GRACE_SECONDS);

        return $expires->greaterThan($this->dayEndsAt($pet, $now));
    }

    /** A completed session (any child) on the family-local day of $now. */
    public function doneOn(Pet $pet, CarbonInterface $now): bool
    {
        $tz = $pet->familyTimezone();
        $start = Carbon::parse($pet->localDate($now), $tz)->startOfDay();

        return ActivityLog::where('pet_id', $pet->id)
            ->where('activity_type', ActivityType::TrainedPet->value)
            ->where('created_at', '>=', $start->copy()->utc())
            ->where('created_at', '<', $start->copy()->addDay()->startOfDay()->utc())
            ->exists();
    }

    // ──────────────────────────────────────────────────────────────
    //  Starting progress (M5-R03b)
    // ──────────────────────────────────────────────────────────────

    /**
     * Give a newly created pet the progress its arrival stage already brings
     * (training_starting_progress, David 2026-10-06: young / adult / senior
     * sit 50, potty 70, come 30, place 0; puppy 0). Only for pets with
     * training; called once by PairingService::createPet (the only place a
     * training pet gets its profile). Not a session: sessions_completed 0,
     * last_practised_at null. Commands with 0 get no row (= never practised).
     * Returns the skills written as {command: progress}.
     *
     * @return array<string, float>
     */
    public function applyStartingProgress(Pet $pet, CarbonInterface $now): array
    {
        if (! $pet->trainingEnabled()) {
            return [];
        }

        $value = $this->lifeStages->stageValueOn($pet, $pet->localDate($now), StageParamKey::TrainingStartingProgress)['value'] ?? null;
        if (! is_array($value)) {
            if ($pet->life_stage !== LifeStage::Puppy) {
                // QA m2: a grown arrival should know some commands — the data row is missing.
                Log::warning('TrainingService: no training_starting_progress for a grown arrival', [
                    'pet_id' => $pet->id, 'breed' => $pet->breed_type->value, 'life_stage' => $pet->life_stage?->value,
                ]);
            }

            return [];
        }

        $written = [];
        foreach (TrainingCommand::cases() as $command) {
            $progress = $value[$command->value] ?? 0;
            $progress = is_int($progress) || is_float($progress) ? max(0.0, min(100.0, (float) $progress)) : 0.0;
            if ($progress <= 0) {
                continue;
            }

            PetTrainingSkill::updateOrCreate(
                ['pet_id' => $pet->id, 'command' => $command->value],
                ['progress' => $progress, 'last_practised_at' => null, 'sessions_completed' => 0],
            );
            $written[$command->value] = $progress;
        }

        return $written;
    }

    // ──────────────────────────────────────────────────────────────
    //  Session start / finish (caller holds the pet lock)
    // ──────────────────────────────────────────────────────────────

    /**
     * Would a session started by $child now be refused, and until when?
     * Null = it may start (the caller adds the locks, 423). Shared by start()
     * and the child state (`training.can_start` / `blocked_reason` /
     * `next_allowed_at`, M5-T02) so the app never offers what the server
     * refuses. Read only. The first that applies:
     *
     * - training_not_available — no training for this pet (also: no budget
     *   data, or $child is not one of the children who can train it);
     * - training_pet_sleeping — the pet sleeps (PetSleepService: family
     *   quiet hours); next_allowed = when it wakes (M5-T02, David 2026-10-20);
     * - training_session_active — next_allowed = its expiry;
     * - media_not_ready — the school's clips are not all shown yet (David
     *   2026-10-28, GameMediaReadiness::SCHOOL; »Šolo še pripravljamo«);
     * - training_day_ending — the session + TTL would run past the
     *   family-local midnight; next_allowed = midnight (PR #53 m1);
     * - training_daily_budget_used — next_allowed = next local midnight;
     * - training_child_share_used — this child's fair share of the budget
     *   is used (M5-R03b); next_allowed = next local midnight.
     *
     * With $child null (no viewing child) the per-child checks are skipped. `$mediaReady`:
     * the caller's mediaReady() result when it already has it (child state — QA #434 m8).
     *
     * @return array{refusal: CareRefusal, next_allowed_at: CarbonInterface|null}|null
     */
    public function startRefusal(Pet $pet, ?User $child, CarbonInterface $now, bool $logMissingData = false, ?bool $mediaReady = null, ?int $rules = null): ?array
    {
        $now = CarbonImmutable::instance($now)->utc()->startOfSecond();
        $refuse = fn (CareRefusal $r, ?CarbonInterface $next = null): array => ['refusal' => $r, 'next_allowed_at' => $next];

        if (! $pet->trainingEnabled()) {
            return $refuse(CareRefusal::TrainingNotAvailable);
        }
        if (($wakes = $this->sleep->wakesAt($pet, $now)) !== null) {
            return $refuse(CareRefusal::TrainingPetSleeping, $wakes);
        }
        $live = $this->liveSession($pet, $now);
        if ($live !== null) {
            return $refuse(CareRefusal::TrainingSessionActive, $live->expires_at);
        }
        // David 2026-10-28: the school opens only once all its clips are shown (pets with
        // school phase clips; every other pet is ready) — never a school without animations.
        if (! ($mediaReady ?? $this->mediaReady($pet))) {
            return $refuse(CareRefusal::MediaNotReady);
        }
        // GAMES_SPEC §7.3: a start reserves its version's longest session; without a version
        // (child state) the larger of both.
        $sessionMs = $this->maxSessionMsFor($pet, $rules);
        if ($this->dayEndingAt($pet, $now, $sessionMs)) {
            return $refuse(CareRefusal::TrainingDayEnding, $this->dayEndsAt($pet, $now));
        }

        $today = $pet->localDate($now);
        $budget = $this->dailyBudgetSeconds($pet, $today);
        if ($budget <= 0) {
            if ($logMissingData) {
                Log::warning('TrainingService: no training budget data', ['pet_id' => $pet->id, 'breed' => $pet->breed_type->value]);
            }

            return $refuse(CareRefusal::TrainingNotAvailable);
        }
        $sessionSeconds = intdiv($sessionMs + 999, 1000);
        $midnight = Carbon::parse($today, $pet->familyTimezone())->addDay()->startOfDay()->utc();
        if ($this->usedSecondsOn($pet, $today) + $sessionSeconds > $budget) {
            return $refuse(CareRefusal::TrainingDailyBudgetUsed, $midnight);
        }
        if ($child === null) {
            return null;
        }
        // M5-R03b fair share: budget / n children who can train — the same
        // trainerIds() rule as the payload (QA n1). A child who is not one of
        // them (no signed contract, not an active caretaker) cannot train.
        $trainers = $this->trainerIds($pet);
        if (! in_array($child->id, $trainers, true)) {
            return $refuse(CareRefusal::TrainingNotAvailable);
        }
        if ($this->usedSecondsOn($pet, $today, $child) + $sessionSeconds > $this->childShareSeconds($pet, $today, $child, $trainers)) {
            return $refuse(CareRefusal::TrainingChildShareUsed, $midnight);
        }

        return null;
    }

    /**
     * David 2026-10-28 (home first, school locked): every school phase clip of the pet is
     * shown — or the pet gets none (free / older set / switch off: always ready).
     */
    public function mediaReady(Pet $pet): bool
    {
        return $this->media->ready($pet, GameMediaReadiness::SCHOOL);
    }

    /**
     * Start a session; refusals as startRefusal() (stale sessions are
     * expired first).
     *
     * @return array{refusal: CareRefusal|null, next_allowed_at: CarbonInterface|null, session: PetTrainingSession|null}
     */
    public function start(Pet $pet, User $child, TrainingCommand $command, CarbonInterface $now, int $rules = self::RULES_V1): array
    {
        $now = CarbonImmutable::instance($now)->utc()->startOfSecond();
        $rules = $rules === self::RULES_V2 ? self::RULES_V2 : self::RULES_V1;

        if ($pet->trainingEnabled()) {
            $this->expireStale($pet, $now);
        }
        if (($refusal = $this->startRefusal($pet, $child, $now, true, rules: $rules)) !== null) {
            return [...$refusal, 'session' => null];
        }

        $today = $pet->localDate($now);
        $this->learningFactor($pet); // drawn and stored on the first session
        $progress = $this->progressOf($pet, $command);
        // School smooth playback (2026-10-28): timed by the pet's stored clip lengths.
        $timing = $this->clipTimings($pet)[$command->value];
        $rng = $this->scheduleSeed === null
            ? new Randomizer
            : new Randomizer(new Xoshiro256StarStar(hash('sha256', $this->scheduleSeed.'|schedule|'.$now->getTimestamp(), true)));
        $schedule = $this->schedule($progress, $rng, $timing['performing_ms'], $timing['release_ms'], $rules);
        $durationMs = $schedule['duration_ms'];
        $endsAt = $now->addMilliseconds($durationMs);

        $session = PetTrainingSession::create([
            'public_id' => (string) Str::uuid(),
            'pet_id' => $pet->id,
            'user_id' => $child->id,
            'command' => $command->value,
            'local_date' => $today,
            'started_at' => $now,
            'ends_at' => $endsAt,
            'expires_at' => $endsAt->addSeconds(self::FINISH_GRACE_SECONDS),
            'duration_ms' => $durationMs,
            'schedule' => $schedule,
            'status' => PetTrainingSession::STATUS_ACTIVE,
            'progress_before' => $progress,
        ]);

        return ['refusal' => null, 'next_allowed_at' => null, 'session' => $session];
    }

    /**
     * Finish a session with the child's tap offsets (ms since start).
     * A repeat for an already completed session of the same child returns
     * it again (`repeat` = true, nothing written — idempotent retry).
     *
     * Early end (GAMES_SPEC §4.1 Š3, David 2026-10-30, rules v2 only): `$endedAfterRound` 1 =
     * the child stopped after round 1. Accepted from round 1's end (minus
     * FINISH_EARLY_TOLERANCE_MS); only round 1's cues are scored (taps before round 2's first
     * cue), progress counts with the session's pace factor (× 8 / 6 as a full session),
     * `duration_ms` becomes round 1's end (the budget counts that), and `full` = false — the
     * caller writes no `trained_pet` row, so the »Šolanje« routine counts only a full session.
     * A v1 session refuses an early end (training_session_not_over).
     *
     * @param  list<int>  $taps
     * @return array{refusal: CareRefusal|null, session: PetTrainingSession|null, repeat: bool, full: bool}
     */
    public function finish(Pet $pet, User $child, string $publicId, array $taps, CarbonInterface $now, ?int $endedAfterRound = null): array
    {
        $exact = CarbonImmutable::instance($now)->utc();
        $now = $exact->startOfSecond();
        $refuse = fn (CareRefusal $r, ?PetTrainingSession $s = null): array => ['refusal' => $r, 'session' => $s, 'repeat' => false, 'full' => false];

        if (! $pet->trainingEnabled()) {
            return $refuse(CareRefusal::TrainingNotAvailable);
        }

        $session = PetTrainingSession::where('public_id', $publicId)->where('pet_id', $pet->id)->first();
        if ($session === null || (int) $session->user_id !== $child->id) {
            return $refuse(CareRefusal::TrainingSessionInvalid);
        }
        if ($session->status === PetTrainingSession::STATUS_COMPLETED) {
            return ['refusal' => null, 'session' => $session, 'repeat' => true, 'full' => ($session->result['ended_after_round'] ?? null) === null];
        }
        $this->expireStale($pet, $now);
        $session->refresh();
        if ($session->status === PetTrainingSession::STATUS_INTERRUPTED) {
            return $refuse(CareRefusal::TrainingSessionInterrupted, $session);
        }
        if ($session->status !== PetTrainingSession::STATUS_ACTIVE || ! $session->expires_at->greaterThan($now)) {
            if ($session->status === PetTrainingSession::STATUS_ACTIVE) {
                $session->forceFill(['status' => PetTrainingSession::STATUS_EXPIRED])->save();
            }

            return $refuse(CareRefusal::TrainingSessionExpired, $session);
        }
        $schedule = (array) $session->schedule;
        $round = null;
        if ($endedAfterRound !== null) {
            // Š3: only a v2 session, only after round 1 (and never the last round).
            $round = self::rulesOf($schedule) === self::RULES_V2 ? ($schedule['rounds'][$endedAfterRound - 1] ?? null) : null;
            if ($round === null || $endedAfterRound >= count($schedule['rounds'] ?? [])) {
                return $refuse(CareRefusal::TrainingSessionNotOver, $session);
            }
        }
        $endsAt = $round === null
            ? $session->ends_at->copy()
            : $session->started_at->copy()->addMilliseconds((int) $round['end_ms']);
        if ($exact->lessThan($endsAt->subMilliseconds(self::FINISH_EARLY_TOLERANCE_MS))) {
            return $refuse(CareRefusal::TrainingSessionNotOver, $session);
        }
        foreach ($taps as $tap) {
            if (! is_int($tap) || $tap < 0 || $tap > $session->duration_ms) {
                return $refuse(CareRefusal::TrainingInvalidTaps, $session);
            }
        }

        $trials = $schedule['trials'];
        $scoredTaps = $taps;
        if ($round !== null) {
            // Round 1's cues only; a tap counts up to the next round's first cue (as in a full session).
            $next = $trials[(int) $round['last_index'] + 1]['cue_at_ms'] ?? PHP_INT_MAX;
            $trials = array_slice($trials, (int) $round['first_index'], (int) $round['last_index'] - (int) $round['first_index'] + 1);
            $scoredTaps = array_values(array_filter($taps, fn (int $t): bool => $t < $next));
        }
        $score = self::score(
            $trials,
            (int) $schedule['praise_window_ms'],
            $scoredTaps,
            (int) ($schedule['min_reaction_ms'] ?? self::MIN_REACTION_MS),
        );
        if (self::rulesOf($schedule) === self::RULES_V2) {
            $score['ended_after_round'] = $round === null ? null : $endedAfterRound;
        }
        $score['suspicious'] = self::looksScripted($score);
        if ($score['suspicious']) {
            // PR #53 M1: identical reaction times look like a modified app. Pet id only (no child data).
            Log::warning('TrainingService: suspicious training session (uniform praise latency)', ['pet_id' => $pet->id]);
        }
        $today = $pet->localDate($now);
        $skill = PetTrainingSkill::firstOrNew(['pet_id' => $pet->id, 'command' => $session->command->value]);
        $before = (float) ($skill->progress ?? 0.0);
        // Learning pace kept (2026-10-28): × 8 / trials, so 6 cues earn what 8 did (old 8-cue schedule × 1).
        $gain = $score['successes'] * $this->gainPerSuccess($pet, $today) * self::paceFactor($session->schedule);
        $after = min(100.0, $before + $gain);

        $skill->forceFill([
            'progress' => $after,
            'last_practised_at' => $now,
            'sessions_completed' => (int) $skill->sessions_completed + 1,
        ])->save();

        sort($taps);
        $session->forceFill([
            'status' => PetTrainingSession::STATUS_COMPLETED,
            'finished_at' => $now,
            'taps' => $taps,
            'result' => $score,
            'progress_before' => $before,
            'progress_gain' => $after - $before,
            // Š3: an early end uses (and the budget counts) round 1 only.
            ...($round === null ? [] : ['duration_ms' => (int) $round['end_ms']]),
        ])->save();

        return ['refusal' => null, 'session' => $session, 'repeat' => false, 'full' => $round === null];
    }

    /**
     * Score taps against a schedule. Taps are assigned to the trial whose
     * slot [cue, next cue) contains them (taps before the first cue are
     * ignored); the FIRST tap of a trial decides. In time = obey_at +
     * $minReactionMs ≤ tap ≤ obey_at + window; a faster "reaction" is
     * too_early (PR #53 M1). Pure — no I/O.
     *
     * @param  list<array{index: int, cue_at_ms: int, obeys: bool, obey_at_ms: int|null, window_end_ms: int|null}>  $trials
     * @param  list<int>  $taps
     * @return array{successes: int, obeyed: int, trials: list<array{index: int, obeys: bool, outcome: string, tap_ms: int|null, latency_ms: int|null}>}
     */
    public static function score(array $trials, int $windowMs, array $taps, int $minReactionMs = self::MIN_REACTION_MS): array
    {
        sort($taps);
        $out = [];
        $successes = 0;
        $obeyed = 0;

        foreach (array_values($trials) as $i => $trial) {
            $from = (int) $trial['cue_at_ms'];
            $to = isset($trials[$i + 1]) ? (int) $trials[$i + 1]['cue_at_ms'] : PHP_INT_MAX;
            $first = null;
            foreach ($taps as $t) {
                if ($t >= $from && $t < $to) {
                    $first = $t;
                    break;
                }
            }

            if ($trial['obeys']) {
                $obeyed++;
                $obeyAt = (int) $trial['obey_at_ms'];
                $outcome = match (true) {
                    $first === null => 'no_praise',
                    $first < $obeyAt + $minReactionMs => 'too_early',
                    $first <= $obeyAt + $windowMs => 'in_time',
                    default => 'too_late',
                };
            } else {
                $outcome = $first === null ? 'waited' : 'praised_without_obeying';
            }

            if ($outcome === 'in_time') {
                $successes++;
            }
            $out[] = [
                'index' => (int) $trial['index'],
                'obeys' => (bool) $trial['obeys'],
                'outcome' => $outcome,
                'tap_ms' => $first,
                'latency_ms' => $trial['obeys'] && $first !== null ? $first - (int) $trial['obey_at_ms'] : null,
            ];
        }

        return ['successes' => $successes, 'obeyed' => $obeyed, 'trials' => $out];
    }

    /**
     * PR #53 M1: at least SUSPICIOUS_MIN_TAPS in-time praises whose latencies
     * all lie within ±SUSPICIOUS_LATENCY_SPREAD_MS of one value — human
     * reactions vary far more. Pure.
     *
     * @param  array{trials: list<array{outcome: string, latency_ms: int|null}>}  $score
     */
    public static function looksScripted(array $score): bool
    {
        $latencies = [];
        foreach ($score['trials'] as $t) {
            if ($t['outcome'] === 'in_time' && $t['latency_ms'] !== null) {
                $latencies[] = $t['latency_ms'];
            }
        }

        return count($latencies) >= self::SUSPICIOUS_MIN_TAPS
            && max($latencies) - min($latencies) <= 2 * self::SUSPICIOUS_LATENCY_SPREAD_MS;
    }

    /** Progress multiplier of a session: PACE_REFERENCE_TRIALS / its number of trials. */
    public static function paceFactor(array $schedule): float
    {
        $trials = count($schedule['trials'] ?? []);

        return $trials > 0 ? self::PACE_REFERENCE_TRIALS / $trials : 1.0;
    }

    /**
     * A new schedule (school smooth playback, David 2026-10-28, SCHOOL_SMOOTH_PLAYBACK.md §B).
     * The dog obeys a cue with a chance that grows with progress; at least one cue is obeyed
     * and at least one is not. P = the pet's stored performing clip length, R = its release
     * clip length (ms; clamped to MIN/MAX_*):
     *
     *  - cue_at_ms = the previous trial's slot end (the first = LEAD_IN_MS);
     *  - obeys: perform_at_ms = cue + think (THINK_MIN–MAX_MS), obey_at_ms = perform_at + P,
     *    window_end_ms = obey_at + PRAISE_WINDOW_MS, release_at_ms = window_end;
     *    slot end = release_at + R + CALM_MS;
     *  - ignores: perform_at / obey_at / window_end / release_at null; slot end = cue + IGNORE_SLOT_MS;
     *  - duration_ms = the last slot end.
     *
     * Rules v2 (GAMES_SPEC §4.1, David 2026-10-30): R = MASK_MS (Š2 — the release clip is not
     * played; the cut success_loop → attention_loop hides under the feedback mask) and after
     * every TRIALS_PER_ROUND cues but the last a ROUND_BREAK_MS break (Š1: the next cue =
     * that slot end + ROUND_BREAK_MS). The draw (obeys, think times) is the same as v1. Extra
     * keys: `rules_version` 2, `mask_ms`, `round_break_ms`, `round_breaks_ms` (start of each
     * break), `rounds` [{round, first_index, last_index, start_ms, end_ms}] — end_ms of round 1
     * is where an early finish (Š3) ends the session.
     *
     * @return array{praise_window_ms: int, min_reaction_ms: int, duration_ms: int, trials: list<array{index: int, cue_at_ms: int, obeys: bool, perform_at_ms: int|null, obey_at_ms: int|null, window_end_ms: int|null, release_at_ms: int|null}>, rules_version?: int, mask_ms?: int, round_break_ms?: int, round_breaks_ms?: list<int>, rounds?: list<array{round: int, first_index: int, last_index: int, start_ms: int, end_ms: int}>}
     */
    public function schedule(float $progress, Randomizer $rng, int $performingMs = self::DEFAULT_PERFORMING_MS, int $releaseMs = self::DEFAULT_RELEASE_MS, int $rules = self::RULES_V1): array
    {
        $v2 = $rules === self::RULES_V2;
        $chance = self::OBEY_CHANCE_UNTRAINED + (self::OBEY_CHANCE_TRAINED - self::OBEY_CHANCE_UNTRAINED) * max(0.0, min(100.0, $progress)) / 100;
        $performingMs = self::clampPerforming($performingMs);
        $releaseMs = $v2 ? self::MASK_MS : self::clampRelease($releaseMs);

        $obeys = [];
        for ($i = 0; $i < self::TRIALS_PER_SESSION; $i++) {
            $obeys[$i] = $rng->nextFloat() < $chance;
        }
        if (! in_array(true, $obeys, true)) {
            $obeys[$rng->getInt(0, self::TRIALS_PER_SESSION - 1)] = true;
        }
        if (! in_array(false, $obeys, true)) {
            $obeys[$rng->getInt(0, self::TRIALS_PER_SESSION - 1)] = false;
        }

        $trials = [];
        $cue = self::LEAD_IN_MS;
        $rounds = [];
        $breaks = [];
        $roundStart = 0;
        for ($i = 0; $i < self::TRIALS_PER_SESSION; $i++) {
            if ($v2 && $i > 0 && $i % self::TRIALS_PER_ROUND === 0) {
                // Š1: the round ended at this slot end — a break, then the next round's first cue.
                $rounds[] = ['round' => count($rounds) + 1, 'first_index' => $i - self::TRIALS_PER_ROUND, 'last_index' => $i - 1, 'start_ms' => $roundStart, 'end_ms' => $cue];
                $breaks[] = $cue;
                $cue += self::ROUND_BREAK_MS;
                $roundStart = $cue;
            }
            if ($obeys[$i]) {
                $performAt = $cue + $rng->getInt(self::THINK_MIN_MS, self::THINK_MAX_MS);
                $obeyAt = $performAt + $performingMs;
                $windowEnd = $obeyAt + self::PRAISE_WINDOW_MS;
                $trials[] = [
                    'index' => $i,
                    'cue_at_ms' => $cue,
                    'obeys' => true,
                    'perform_at_ms' => $performAt,
                    'obey_at_ms' => $obeyAt,
                    'window_end_ms' => $windowEnd,
                    'release_at_ms' => $windowEnd,
                ];
                $cue = $windowEnd + $releaseMs + self::CALM_MS;
            } else {
                $trials[] = [
                    'index' => $i,
                    'cue_at_ms' => $cue,
                    'obeys' => false,
                    'perform_at_ms' => null,
                    'obey_at_ms' => null,
                    'window_end_ms' => null,
                    'release_at_ms' => null,
                ];
                $cue += self::IGNORE_SLOT_MS;
            }
        }

        $schedule = ['praise_window_ms' => self::PRAISE_WINDOW_MS, 'min_reaction_ms' => self::MIN_REACTION_MS, 'duration_ms' => $cue, 'trials' => $trials];
        if (! $v2) {
            return $schedule;
        }
        $last = intdiv(self::TRIALS_PER_SESSION - 1, self::TRIALS_PER_ROUND) * self::TRIALS_PER_ROUND;
        $rounds[] = ['round' => count($rounds) + 1, 'first_index' => $last, 'last_index' => self::TRIALS_PER_SESSION - 1, 'start_ms' => $roundStart, 'end_ms' => $cue];

        return $schedule + [
            'rules_version' => self::RULES_V2,
            'mask_ms' => self::MASK_MS,
            'round_break_ms' => self::ROUND_BREAK_MS,
            'round_breaks_ms' => $breaks,
            'rounds' => $rounds,
        ];
    }

    /** The rules version of a stored schedule (none = v1). */
    public static function rulesOf(array $schedule): int
    {
        return (int) ($schedule['rules_version'] ?? self::RULES_V1) === self::RULES_V2 ? self::RULES_V2 : self::RULES_V1;
    }

    /**
     * The v2 keys of a stored schedule for the app (empty for v1 — a v1 payload stays byte
     * for byte): rules_version, mask_ms, round_break_ms, round_breaks_ms, rounds.
     *
     * @param  array<string, mixed>  $schedule
     * @return array{rules_version?: int, mask_ms?: int, round_break_ms?: int, round_breaks_ms?: list<int>, rounds?: list<array{round: int, first_index: int, last_index: int, start_ms: int, end_ms: int}>}
     */
    public static function v2KeysOf(array $schedule): array
    {
        if (self::rulesOf($schedule) !== self::RULES_V2) {
            return [];
        }

        return [
            'rules_version' => self::RULES_V2,
            'mask_ms' => (int) ($schedule['mask_ms'] ?? self::MASK_MS),
            'round_break_ms' => (int) ($schedule['round_break_ms'] ?? self::ROUND_BREAK_MS),
            'round_breaks_ms' => array_map('intval', array_values($schedule['round_breaks_ms'] ?? [])),
            'rounds' => array_map(fn (array $r): array => [
                'round' => (int) $r['round'],
                'first_index' => (int) $r['first_index'],
                'last_index' => (int) $r['last_index'],
                'start_ms' => (int) $r['start_ms'],
                'end_ms' => (int) $r['end_ms'],
            ], array_values($schedule['rounds'] ?? [])),
        ];
    }

    /**
     * The trials of a stored schedule with a stable key order (jsonb does not
     * keep it): index, cue_at_ms, obeys, [perform_at_ms], obey_at_ms, window_end_ms,
     * [release_at_ms]. perform_at_ms / release_at_ms only for a schedule made since
     * 2026-10-28 — an older running schedule is returned as stored (the app falls back).
     *
     * @param  array<string, mixed>  $schedule
     * @return list<array{index: int, cue_at_ms: int, obeys: bool, perform_at_ms?: int|null, obey_at_ms: int|null, window_end_ms: int|null, release_at_ms?: int|null}>
     */
    public static function trialsOf(array $schedule): array
    {
        $int = fn (array $t, string $k): ?int => ($t[$k] ?? null) === null ? null : (int) $t[$k];

        return array_map(function (array $t) use ($int): array {
            $trial = ['index' => (int) $t['index'], 'cue_at_ms' => (int) $t['cue_at_ms'], 'obeys' => (bool) $t['obeys']];
            if (array_key_exists('perform_at_ms', $t)) {
                $trial['perform_at_ms'] = $int($t, 'perform_at_ms');
            }
            $trial['obey_at_ms'] = $int($t, 'obey_at_ms');
            $trial['window_end_ms'] = $int($t, 'window_end_ms');
            if (array_key_exists('release_at_ms', $t)) {
                $trial['release_at_ms'] = $int($t, 'release_at_ms');
            }

            return $trial;
        }, array_values($schedule['trials'] ?? []));
    }

    /**
     * Settle active sessions: one during which a lock began becomes
     * `interrupted` (its time is refunded, it never counts — PR #53 m2);
     * one past its TTL becomes `expired`. Frees the one-active slot.
     */
    public function expireStale(Pet $pet, CarbonInterface $now): void
    {
        PetTrainingSession::where('pet_id', $pet->id)
            ->where('status', PetTrainingSession::STATUS_ACTIVE)
            ->where(fn ($q) => $this->whereInterrupted($q, $now))
            ->update(['status' => PetTrainingSession::STATUS_INTERRUPTED, 'updated_at' => $now]);
        PetTrainingSession::where('pet_id', $pet->id)
            ->where('status', PetTrainingSession::STATUS_ACTIVE)
            ->where('expires_at', '<=', $now)
            ->update(['status' => PetTrainingSession::STATUS_EXPIRED, 'updated_at' => $now]);
    }

    // ──────────────────────────────────────────────────────────────
    //  Decay (tick)
    // ──────────────────────────────────────────────────────────────

    /**
     * "No practice → progress slowly decays": every finished family-local
     * day since the last run whose training routine was MISSED (the ledger:
     * not the birth day, not a day excused by hard stop / vet / game over)
     * costs every command training_decay_per_missed_day points (min 0).
     * Advances pets.training_decayed_through to yesterday (attribute on
     * $pet; the caller saves). Returns the number of missed days applied.
     */
    public function applyDecay(Pet $pet, CarbonInterface $now): int
    {
        if (! $pet->trainingEnabled() || $pet->isUnborn()) {
            return 0;
        }

        $today = $pet->localDate($now);
        $yesterday = CarbonImmutable::parse($today, 'UTC')->subDay()->toDateString();
        $start = $pet->training_decayed_through !== null
            ? CarbonImmutable::parse(substr((string) $pet->training_decayed_through, 0, 10), 'UTC')->addDay()->toDateString()
            : $pet->localDate($pet->born_at);
        $start = max($start, CarbonImmutable::parse($today, 'UTC')->subDays(self::MAX_DECAY_DAYS)->toDateString());
        if ($start > $yesterday) {
            return 0;
        }

        $routines = $this->ledger->routinesFor(collect([$pet]), $start, $yesterday, $now)[$pet->id] ?? [];
        $points = 0.0;
        $missed = 0;
        foreach ($routines as $routine) {
            if ($routine->type === RoutineType::Training && $routine->status === RoutineStatus::Missed) {
                $missed++;
                $points += $this->number($pet, $routine->localDate, StageParamKey::TrainingDecayPerMissedDay) ?? 0.0;
            }
        }

        if ($points > 0) {
            foreach (PetTrainingSkill::where('pet_id', $pet->id)->where('progress', '>', 0)->get() as $skill) {
                $skill->forceFill(['progress' => max(0.0, (float) $skill->progress - $points)])->save();
            }
        }

        $pet->training_decayed_through = $yesterday;

        return $missed;
    }

    // ──────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────

    private function number(Pet $pet, string $localDate, StageParamKey $key): ?float
    {
        $value = $this->lifeStages->stageValueOn($pet, $localDate, $key)['value'] ?? null;

        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    /** Deterministic RNG per pet and purpose (APP_KEY salt unless a test pins it). */
    private function rng(Pet $pet, string $purpose): Randomizer
    {
        return new Randomizer(new Xoshiro256StarStar(hash('sha256', $this->seedSalt.'|'.$purpose.'|'.$pet->id, true)));
    }
}
