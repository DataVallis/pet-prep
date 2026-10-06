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
 * potty) and starts a session; the SERVER generates the schedule: after a
 * lead-in, TRIALS_PER_SESSION cues, one every TRIAL_SLOT_MS; at each cue the
 * app gives the command and the dog either obeys after a random delay
 * (OBEY_DELAY_*) or does not. The child must tap "Pohvali" inside
 * PRAISE_WINDOW_MS right after the dog obeys. At finish the app sends only
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
 * pets.training_learning_factor) per in_time trial. The dog obeys more
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
 * Only pets with training enabled (Pet::trainingEnabled — profile + app
 * feature `training`); legacy-profile pets never. All callers hold the
 * pet's row lock (backend/CLAUDE.md); pet attributes are written on $pet,
 * the caller saves.
 */
class TrainingService
{
    public const TRIALS_PER_SESSION = 8;

    public const LEAD_IN_MS = 2000;

    public const TRIAL_SLOT_MS = 6000;

    public const OBEY_DELAY_MIN_MS = 800;

    public const OBEY_DELAY_MAX_MS = 2500;

    public const PRAISE_WINDOW_MS = 1500;

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
        private readonly RoutineLedgerService $ledger,
        private ?string $seedSalt = null,
    ) {
        $this->seedSalt ??= (string) config('app.key');
    }

    public static function sessionDurationMs(): int
    {
        return self::LEAD_IN_MS + self::TRIALS_PER_SESSION * self::TRIAL_SLOT_MS;
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

        return max(intdiv($budget, count($trainerIds)), self::sessionSeconds());
    }

    /** Whole seconds one session takes from the budget. */
    public static function sessionSeconds(): int
    {
        return intdiv(self::sessionDurationMs() + 999, 1000);
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
    public function dayEndingAt(Pet $pet, CarbonInterface $now): bool
    {
        $expires = CarbonImmutable::instance($now)->utc()->startOfSecond()
            ->addMilliseconds(self::sessionDurationMs())->addSeconds(self::FINISH_GRACE_SECONDS);

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
     * Start a session. Refusals: training_not_available (no training for
     * this pet), training_session_active (next_allowed = its expiry),
     * training_day_ending (the session + TTL would run past the
     * family-local midnight; next_allowed = midnight — PR #53 m1),
     * training_daily_budget_used (next_allowed = next local midnight),
     * training_child_share_used (this child's fair share of the budget is
     * used — M5-R03b; next_allowed = next local midnight).
     *
     * @return array{refusal: CareRefusal|null, next_allowed_at: CarbonInterface|null, session: PetTrainingSession|null}
     */
    public function start(Pet $pet, User $child, TrainingCommand $command, CarbonInterface $now): array
    {
        $now = CarbonImmutable::instance($now)->utc()->startOfSecond();
        $refuse = fn (CareRefusal $r, ?CarbonInterface $next = null): array => ['refusal' => $r, 'next_allowed_at' => $next, 'session' => null];

        if (! $pet->trainingEnabled()) {
            return $refuse(CareRefusal::TrainingNotAvailable);
        }

        $this->expireStale($pet, $now);
        $live = $this->liveSession($pet, $now);
        if ($live !== null) {
            return $refuse(CareRefusal::TrainingSessionActive, $live->expires_at);
        }
        if ($this->dayEndingAt($pet, $now)) {
            return $refuse(CareRefusal::TrainingDayEnding, $this->dayEndsAt($pet, $now));
        }

        $today = $pet->localDate($now);
        $durationMs = self::sessionDurationMs();
        $budget = $this->dailyBudgetSeconds($pet, $today);
        if ($budget <= 0) {
            Log::warning('TrainingService: no training budget data', ['pet_id' => $pet->id, 'breed' => $pet->breed_type->value]);

            return $refuse(CareRefusal::TrainingNotAvailable);
        }
        $sessionSeconds = intdiv($durationMs + 999, 1000);
        $midnight = Carbon::parse($today, $pet->familyTimezone())->addDay()->startOfDay()->utc();
        if ($this->usedSecondsOn($pet, $today) + $sessionSeconds > $budget) {
            return $refuse(CareRefusal::TrainingDailyBudgetUsed, $midnight);
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

        $this->learningFactor($pet); // drawn and stored on the first session
        $progress = $this->progressOf($pet, $command);
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
            'schedule' => $this->schedule($progress, new Randomizer),
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
     * @param  list<int>  $taps
     * @return array{refusal: CareRefusal|null, session: PetTrainingSession|null, repeat: bool}
     */
    public function finish(Pet $pet, User $child, string $publicId, array $taps, CarbonInterface $now): array
    {
        $exact = CarbonImmutable::instance($now)->utc();
        $now = $exact->startOfSecond();
        $refuse = fn (CareRefusal $r, ?PetTrainingSession $s = null): array => ['refusal' => $r, 'session' => $s, 'repeat' => false];

        if (! $pet->trainingEnabled()) {
            return $refuse(CareRefusal::TrainingNotAvailable);
        }

        $session = PetTrainingSession::where('public_id', $publicId)->where('pet_id', $pet->id)->first();
        if ($session === null || (int) $session->user_id !== $child->id) {
            return $refuse(CareRefusal::TrainingSessionInvalid);
        }
        if ($session->status === PetTrainingSession::STATUS_COMPLETED) {
            return ['refusal' => null, 'session' => $session, 'repeat' => true];
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
        if ($exact->lessThan($session->ends_at->copy()->subMilliseconds(self::FINISH_EARLY_TOLERANCE_MS))) {
            return $refuse(CareRefusal::TrainingSessionNotOver, $session);
        }
        foreach ($taps as $tap) {
            if (! is_int($tap) || $tap < 0 || $tap > $session->duration_ms) {
                return $refuse(CareRefusal::TrainingInvalidTaps, $session);
            }
        }

        $score = self::score(
            $session->schedule['trials'],
            (int) $session->schedule['praise_window_ms'],
            $taps,
            (int) ($session->schedule['min_reaction_ms'] ?? self::MIN_REACTION_MS),
        );
        $score['suspicious'] = self::looksScripted($score);
        if ($score['suspicious']) {
            // PR #53 M1: identical reaction times look like a modified app. Pet id only (no child data).
            Log::warning('TrainingService: suspicious training session (uniform praise latency)', ['pet_id' => $pet->id]);
        }
        $today = $pet->localDate($now);
        $skill = PetTrainingSkill::firstOrNew(['pet_id' => $pet->id, 'command' => $session->command->value]);
        $before = (float) ($skill->progress ?? 0.0);
        $gain = $score['successes'] * $this->gainPerSuccess($pet, $today);
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
        ])->save();

        return ['refusal' => null, 'session' => $session, 'repeat' => false];
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

    /**
     * A new schedule: cue every TRIAL_SLOT_MS after the lead-in; the dog obeys
     * with a chance that grows with progress, after a random delay. At least
     * one cue is obeyed and at least one is not.
     *
     * @return array{praise_window_ms: int, min_reaction_ms: int, trials: list<array{index: int, cue_at_ms: int, obeys: bool, obey_at_ms: int|null, window_end_ms: int|null}>}
     */
    public function schedule(float $progress, Randomizer $rng): array
    {
        $chance = self::OBEY_CHANCE_UNTRAINED + (self::OBEY_CHANCE_TRAINED - self::OBEY_CHANCE_UNTRAINED) * max(0.0, min(100.0, $progress)) / 100;

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
        for ($i = 0; $i < self::TRIALS_PER_SESSION; $i++) {
            $cue = self::LEAD_IN_MS + $i * self::TRIAL_SLOT_MS;
            $delay = $rng->getInt(self::OBEY_DELAY_MIN_MS, self::OBEY_DELAY_MAX_MS);
            $trials[] = [
                'index' => $i,
                'cue_at_ms' => $cue,
                'obeys' => $obeys[$i],
                'obey_at_ms' => $obeys[$i] ? $cue + $delay : null,
                'window_end_ms' => $obeys[$i] ? $cue + $delay + self::PRAISE_WINDOW_MS : null,
            ];
        }

        return ['praise_window_ms' => self::PRAISE_WINDOW_MS, 'min_reaction_ms' => self::MIN_REACTION_MS, 'trials' => $trials];
    }

    /**
     * The trials of a stored schedule with a stable key order (jsonb does not
     * keep it): index, cue_at_ms, obeys, obey_at_ms, window_end_ms.
     *
     * @param  array<string, mixed>  $schedule
     * @return list<array{index: int, cue_at_ms: int, obeys: bool, obey_at_ms: int|null, window_end_ms: int|null}>
     */
    public static function trialsOf(array $schedule): array
    {
        return array_map(fn (array $t): array => [
            'index' => (int) $t['index'],
            'cue_at_ms' => (int) $t['cue_at_ms'],
            'obeys' => (bool) $t['obeys'],
            'obey_at_ms' => $t['obey_at_ms'] === null ? null : (int) $t['obey_at_ms'],
            'window_end_ms' => $t['window_end_ms'] === null ? null : (int) $t['window_end_ms'],
        ], array_values($schedule['trials'] ?? []));
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
