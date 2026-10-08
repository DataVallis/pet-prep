<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\CareRefusal;
use App\Enums\PetLockReason;
use App\Enums\PlayKind;
use App\Enums\TrainingCommand;
use App\Events\PetUpdated;
use App\Models\ActivityLog;
use App\Models\BreedConfig;
use App\Models\Pet;
use App\Models\PetCareSession;
use App\Models\PetContract;
use App\Models\PetDailyStep;
use App\Models\PetTrainingSession;
use App\Models\User;
use App\Services\Media\PetMediaService;
use App\Services\Results\ActionResult;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Child actions (M1-04 steps, M1-05 cleaning, M1-07 feed / water / contract),
 * called by the child API (ChildPetController, ChildContractController).
 *
 * Every action follows the metric-write rule (backend/CLAUDE.md): transaction,
 * `lockForUpdate()` re-read, compute from the locked row, quiet write, one
 * `activities_log` row for an applied action, one `PetUpdated` broadcast
 * after commit (the activity-log observer is bypassed to avoid a duplicate).
 *
 * Family model (M2-01): every action takes the acting child. The activity
 * row records it (`actor_user_id`), the lock is evaluated for that child
 * (a caretaker without their own contract → 423 contract_required), and
 * steps are counted per child (`pet_daily_steps`); the pet's daily count is
 * the sum over its caretakers. Without an actor (system / old callers) the
 * primary caretaker (pets.user_id) is assumed.
 */
class PetActivityService
{
    /**
     * Anti-cheat (PRODUCT_SPEC §5): step increments implying more than this
     * many steps per minute since the last accepted sync are refused.
     */
    public const MAX_STEPS_PER_MINUTE = 200;

    /** "Pelji ven" twice within this many seconds counts once (double tap, M5-R02). */
    public const TAKE_OUT_DEDUPE_SECONDS = 60;

    public function __construct(
        private HygieneEventService $hygieneEvents,
        private DailyWalkService $dailyWalks,
        private PetDecayService $decay,
        private CareScheduleService $schedule,
        private LifeStageService $lifeStages,
        private BehaviourEventService $behaviour,
        private TrainingService $training,
        private ChallengeService $challenges,
        private PlayService $play,
        private ChallengeCreditService $credits,
        private WandPlayService $wand,
    ) {}

    /**
     * Sync today's step count from the device (HealthKit / Health Connect).
     *
     * - Idempotent: `$stepsToday` is the cumulative count for the family-local
     *   day; only the part above the stored count is new (the max wins).
     * - Anti-cheat: the increment is capped at 200 steps per minute between
     *   the last accepted sync (or local midnight) and `$recordedAt`; the
     *   excess is refused now and can still be accepted by a later sync.
     * - Energy = min(100, steps / today's step goal × 100) — the goal of the
     *   dog's life stage (M5-R01, LifeStageService); a sync
     *   never lowers it (birth-day grace, DECISIONS 2026-10-03).
     * - A sync for a local day that is already over is ignored (stale).
     * - Activity log: one `walked_pet` row per day, when the sync first
     *   reaches the daily goal (value = steps), not one per sync — so the
     *   dashboard counts a walk once (daily walk rule).
     * - Shared pet (M2-01): `$stepsToday` is the acting child's own count;
     *   idempotency and the anti-cheat cap apply per child; the pet's count
     *   (energy, daily walk goal) is the sum of all caretakers' steps that
     *   day. Steps the pet already has that no child row explains (data from
     *   before M2-01) belong to the primary caretaker.
     */
    public function recordSteps(Pet $pet, int $stepsToday, CarbonInterface $recordedAt, ?User $actor = null): ActionResult
    {
        if ($stepsToday < 0) {
            throw new InvalidArgumentException('stepsToday must be >= 0.');
        }

        return $this->withLockedPet($pet, ActivityType::WalkedPet, function (Pet $locked) use ($stepsToday, $recordedAt, $actor): ActionResult {
            $now = now()->startOfSecond();

            if ($reason = $locked->actionLockReasonFor($actor)) {
                return $this->locked($locked, $reason);
            }

            // M5-R06-04 (CAT_SPEC Q1, §5.3): phone steps are dog-only — the cat plays instead.
            if ($locked->isCat()) {
                return $this->refused($locked, CareRefusal::StepsNotApplicable);
            }

            $actorId = $actor?->id ?? $locked->user_id;

            // Device clocks can run ahead; never accept time from the future.
            $at = Carbon::instance($recordedAt)->utc()->startOfSecond();
            if ($at->greaterThan($now)) {
                $at = $now->copy();
            }

            // Midnight may have passed since the last tick: close the day
            // (daily walk rule) exactly as the tick would.
            $this->dailyWalks->closeDayIfNeeded($locked, $now, allowIllness: true);

            $today = $locked->localDate($now);
            if ($locked->localDate($at) !== $today) {
                return $this->unchanged(ActionResult::STALE, $locked);
            }

            $row = $this->childStepRow($locked, $actorId, $today);
            $petCount = (int) $locked->daily_step_count;
            $current = (int) $row->steps;
            $increment = $stepsToday - $current;
            if ($increment <= 0) {
                return $this->unchanged(ActionResult::UNCHANGED, $locked);
            }

            $dayStart = Carbon::parse($today, $locked->familyTimezone())->startOfDay()->utc();
            $reference = $row->last_sync_at !== null && $row->last_sync_at->greaterThan($dayStart)
                ? $row->last_sync_at
                : $dayStart;
            $seconds = max(0, (int) $reference->diffInSeconds($at, false));
            $allowed = intdiv($seconds * self::MAX_STEPS_PER_MINUTE, 60);
            $accepted = min($increment, $allowed);

            if ($accepted < $increment) {
                Log::warning('PetActivityService: step increment above anti-cheat limit', [
                    'pet_id' => $locked->id,
                    'actor_user_id' => $actorId,
                    'reported_increment' => $increment,
                    'accepted' => $accepted,
                    'seconds_since_reference' => $seconds,
                ]);
            }

            if ($accepted <= 0) {
                return $this->unchanged(ActionResult::REJECTED, $locked);
            }

            $breedConfig = $this->breedConfigOf($locked);
            // Today's goal of the dog's life stage (M5-R01).
            $goal = $this->lifeStages->rulesOn($locked, $today, $breedConfig)->stepGoal;

            $row->forceFill(['steps' => $current + $accepted, 'last_sync_at' => $at])->save();

            $newCount = $petCount + $accepted;
            $energy = max((float) $locked->energy_level, self::energyForSteps($newCount, $goal));

            $locked->forceFill([
                'daily_step_count' => $newCount,
                'energy_level' => $energy,
                'last_step_sync_at' => $at,
                'energy_zero_since' => null,
            ]);
            $locked->saveQuietly();

            if ($goal > 0 && $petCount < $goal && $newCount >= $goal) {
                $this->logActivity($locked, ActivityType::WalkedPet, $newCount, $actorId);
            }

            return $this->result($accepted < $increment ? ActionResult::CAPPED : ActionResult::ACCEPTED, $locked, $accepted);
        });
    }

    /**
     * Clean up after the dog (cleaning mini-game done): every open poop and
     * puppy accident (M5-R02) is cleaned; hygiene back to 100 % and the
     * hygiene neglect clock cleared — unless a chewing event is still open
     * (that one is tidied up with resolveChewing; hygiene stays 0 until
     * then). Decay owed since the last tick is caught up first, so messes
     * that are already due (also an accident) happen before the clean and
     * the pet doesn't get dirty again a minute after cleaning.
     */
    public function clean(Pet $pet, ?User $actor = null): ActionResult
    {
        return $this->withLockedPet($pet, ActivityType::CleanedPoop, function (Pet $locked) use ($actor): ActionResult {
            $now = now()->startOfSecond();

            if ($reason = $locked->actionLockReasonFor($actor)) {
                return $this->locked($locked, $reason);
            }

            $this->decay->catchUpLocked($locked);

            $handled = $this->hygieneEvents->settleForCleaning($locked, $now, $locked->quietHours());
            $stillOpen = $this->hygieneEvents->openEvents($locked)->isNotEmpty();

            if ($handled === 0 && ((float) $locked->hygiene_level >= 100.0 || $stillOpen)) {
                return $this->unchanged(ActionResult::UNCHANGED, $locked);
            }

            $this->restoreHygieneIfResolved($locked, $stillOpen, $now);

            $this->logActivity($locked, ActivityType::CleanedPoop, null, $actor?->id ?? $locked->user_id);

            return $this->result(ActionResult::ACCEPTED, $locked);
        });
    }

    /**
     * "Pospravi in daj igračo" (M5-R02, David 2026-10-06): the child tidies
     * up what the dog chewed and gives it a toy — every open chewing event
     * is resolved; hygiene back to 100 % unless a poop / accident is still
     * open. Nothing to tidy up → unchanged (idempotent repeat).
     */
    public function resolveChewing(Pet $pet, ?User $actor = null): ActionResult
    {
        return $this->withLockedPet($pet, ActivityType::ResolvedChewing, function (Pet $locked) use ($actor): ActionResult {
            $now = now()->startOfSecond();

            if ($reason = $locked->actionLockReasonFor($actor)) {
                return $this->locked($locked, $reason);
            }

            $this->decay->catchUpLocked($locked);

            if ($this->hygieneEvents->settleChewing($locked, $now, $locked->quietHours()) === 0) {
                return $this->unchanged(ActionResult::UNCHANGED, $locked);
            }

            $this->restoreHygieneIfResolved($locked, $this->hygieneEvents->openEvents($locked)->isNotEmpty(), $now);

            $this->logActivity($locked, ActivityType::ResolvedChewing, null, $actor?->id ?? $locked->user_id);

            return $this->result(ActionResult::ACCEPTED, $locked);
        });
    }

    /**
     * "Pelji ven" (M5-R02, David 2026-10-06): a puppy's bladder clock
     * restarts now (BehaviourEventService). Only for a pet with a bladder
     * clock — behaviour events enabled, puppy stage (else 422
     * take_out_not_needed, exactly when `behaviour.take_out` is null). An accident that is
     * already due happens first (decay catch-up). A second take-out by
     * anyone within a minute is a double tap → unchanged. Allowed while a
     * mess is open (it is a different job) and in quiet hours.
     */
    public function takeOut(Pet $pet, ?User $actor = null): ActionResult
    {
        return $this->withLockedPet($pet, ActivityType::TookOutPet, function (Pet $locked) use ($actor): ActionResult {
            $now = now()->startOfSecond();

            if ($reason = $locked->actionLockReasonFor($actor)) {
                return $this->locked($locked, $reason);
            }

            $this->decay->catchUpLocked($locked);

            // Same rule as the payload (`behaviour.take_out` null ⇔ refused, PR #42 m2).
            if ($this->behaviour->pottyClock($locked, $locked->quietHours()) === null) {
                return $this->refused($locked, CareRefusal::TakeOutNotNeeded);
            }

            $recent = ActivityLog::where('pet_id', $locked->id)
                ->where('activity_type', ActivityType::TookOutPet->value)
                ->where('created_at', '>', $now->copy()->subSeconds(self::TAKE_OUT_DEDUPE_SECONDS))
                ->exists();
            if ($recent) {
                return $this->unchanged(ActionResult::UNCHANGED, $locked);
            }

            $this->behaviour->takeOut($locked, $now);
            $locked->saveQuietly();

            $this->logActivity($locked, ActivityType::TookOutPet, null, $actor?->id ?? $locked->user_id);

            return $this->result(ActionResult::ACCEPTED, $locked);
        });
    }

    /**
     * Start a training session (M5-R03, David 2026-10-06): the server
     * generates the schedule (TrainingService::start). Locks first (423),
     * then 422 training_not_available / training_session_active /
     * training_day_ending / training_daily_budget_used /
     * training_child_share_used (M5-R03b). `extra.session` = the
     * schedule for the app. One `PetUpdated('training_started')` (the payload
     * shows a running session). Broadcast only, no activity row (would need a
     * new activity type + timeline label; the routine is the completed session).
     */
    public function startTraining(Pet $pet, User $child, TrainingCommand $command): ActionResult
    {
        return $this->withLockedPet($pet, 'training_started', function (Pet $locked) use ($child, $command): ActionResult {
            $now = now();

            if ($reason = $locked->actionLockReasonFor($child)) {
                return $this->locked($locked, $reason);
            }

            // A missed day since the last tick decays progress first (same rule as the tick).
            $this->training->applyDecay($locked, $now);

            $started = $this->training->start($locked, $child, $command, $now);
            if ($started['refusal'] !== null) {
                return $this->refused($locked, $started['refusal'], $started['next_allowed_at']);
            }

            $locked->saveQuietly(); // learning factor drawn on the first session, decay pointer

            return $this->result(ActionResult::ACCEPTED, $locked, extra: [
                'session' => self::sessionPayload($started['session'], $locked->familyTimezone()),
            ]);
        });
    }

    /**
     * Finish a training session with the child's tap offsets (M5-R03): the
     * server scores them against its schedule, adds progress and logs one
     * `trained_pet` row (the day's training routine, value = correctly timed
     * praises). A repeat of a completed finish → `unchanged` with the stored
     * result. Refusals 422: training_not_available, training_session_invalid,
     * training_session_expired, training_session_not_over, training_invalid_taps,
     * training_session_interrupted (a lock began during the session — PR #53 m2).
     *
     * @param  list<int>  $taps  ms since the session start
     */
    public function finishTraining(Pet $pet, User $child, string $sessionId, array $taps): ActionResult
    {
        return $this->withLockedPet($pet, ActivityType::TrainedPet->value, function (Pet $locked) use ($child, $sessionId, $taps): ActionResult {
            $now = now();

            if ($reason = $locked->actionLockReasonFor($child)) {
                return $this->locked($locked, $reason);
            }

            $this->training->applyDecay($locked, $now);

            $finished = $this->training->finish($locked, $child, $sessionId, $taps, $now);
            if ($finished['refusal'] !== null) {
                return $this->refused($locked, $finished['refusal']);
            }

            /** @var PetTrainingSession $session */
            $session = $finished['session'];
            $extra = ['result' => self::resultPayload($session)];
            if ($finished['repeat']) {
                return $this->unchanged(ActionResult::UNCHANGED, $locked, $extra);
            }

            $locked->saveQuietly();
            $this->logActivity($locked, ActivityType::TrainedPet, (int) ($session->result['successes'] ?? 0), $child->id);

            return $this->result(ActionResult::ACCEPTED, $locked, extra: $extra);
        });
    }

    /**
     * Play & cuddle (M5-R05, PLAY_CUDDLE_SPEC §12.5): the child finished a
     * ball game or a cuddle. Locks first (423, incl. this child's contract),
     * then 422 play_not_available (no play for this pet, quiet hours —
     * next_allowed_at = their end — or a mess to clean). Completes the shown
     * invitation of that kind or records a free play; the dog is happy for
     * `play.happy_minutes`; one timeline row per child + kind per merge
     * window. A repeat by the same child and kind within `play.repeat_seconds`
     * → unchanged. Never touches a metric, routine or score. One
     * `PetUpdated('play')` after commit. `extra.play` = what was recorded.
     */
    public function play(Pet $pet, User $child, PlayKind $kind): ActionResult
    {
        return $this->withLockedPet($pet, 'play', function (Pet $locked) use ($child, $kind): ActionResult {
            $now = now()->startOfSecond();

            if ($reason = $locked->actionLockReasonFor($child)) {
                return $this->locked($locked, $reason);
            }

            // Due messes / the midnight happen first (same as every action).
            $this->decay->catchUpLocked($locked);

            // M5-R06-04 (David 2026-10-08): the ball game is dog-only — a cat's play
            // is the wand game (its care routine); cuddles stay for both.
            if (! $this->play->kindAvailable($locked, $kind)) {
                return $this->refused($locked, CareRefusal::PlayNotAvailable);
            }

            if (! $this->play->canPlayNow($locked, $now)) {
                return $this->refused($locked, CareRefusal::PlayNotAvailable, $this->play->nextPlayAt($locked, $now));
            }

            $event = $this->play->complete($locked, $child, $kind, $now);
            if ($event === null) {
                return $this->unchanged(ActionResult::UNCHANGED, $locked);
            }

            $locked->saveQuietly();
            $this->play->recordTimeline($locked, $child, $kind, $now);

            return $this->result(ActionResult::ACCEPTED, $locked, extra: [
                'play' => [
                    'kind' => $event->kind->value,
                    'source' => $event->source->value,
                ],
            ]);
        });
    }

    /**
     * Cat wand play (M5-R06-04, CAT_SPEC §5.2): start a ~60 s session — the
     * server's schedule (pounces, the catch at the end) in `extra.session`.
     * Locks first (423), then 422 wand_not_available (a dog, no play data) /
     * needs_cleaning / wand_too_soon (next_allowed_at = end of the 2 h gap
     * after the last SUCCESSFUL session) / wand_session_active (another
     * child's game; next_allowed_at = its TTL) / wand_day_ending. The same
     * child's unfinished game is replaced (no penalty). One
     * `PetUpdated('wand_started')`; no activity row (the routine is the
     * successful session).
     */
    public function startWand(Pet $pet, User $child): ActionResult
    {
        return $this->withLockedPet($pet, 'wand_started', function (Pet $locked) use ($child): ActionResult {
            $now = now();

            if ($reason = $locked->actionLockReasonFor($child)) {
                return $this->locked($locked, $reason);
            }

            // Due messes / the midnight (meter → 0) happen first.
            $this->decay->catchUpLocked($locked);

            $started = $this->wand->start($locked, $child, $now);
            if ($started['refusal'] !== null) {
                return $this->refused($locked, $started['refusal'], $started['next_allowed_at']);
            }

            if ($locked->isDirty()) {
                $locked->saveQuietly();
            }

            return $this->result(ActionResult::ACCEPTED, $locked, extra: [
                'session' => self::wandSessionPayload($started['session'], $locked->familyTimezone()),
            ]);
        });
    }

    /**
     * Finish a wand session with the feather moves the app saw
     * ({t: ms since start, away: bool}). The server judges participation
     * (WandPlayService::score): success → the session counts — play meter,
     * one `played_wand` row (the play routine, value = the session's number
     * of the day), the 2 h gap starts — status `accepted`; not enough →
     * `rejected` (200, nothing counts, no penalty, start again at once;
     * one `PetUpdated('wand_finished')` — the game ended).
     * `extra.result` = the verdict. A repeat → `unchanged` with the stored
     * result. 422 wand_not_available / wand_session_invalid /
     * wand_session_expired / wand_session_not_over / wand_invalid_moves /
     * wand_session_interrupted; 423 while locked.
     *
     * @param  list<array{t: int, away: bool}>  $moves
     */
    public function finishWand(Pet $pet, User $child, string $sessionId, array $moves): ActionResult
    {
        return $this->withLockedPet($pet, ActivityType::PlayedWand, function (Pet $locked) use ($child, $sessionId, $moves): ActionResult {
            $now = now();

            if ($reason = $locked->actionLockReasonFor($child)) {
                return $this->locked($locked, $reason);
            }

            $this->decay->catchUpLocked($locked);

            $finished = $this->wand->finish($locked, $child, $sessionId, $moves, $now);
            if ($finished['refusal'] !== null) {
                return $this->refused($locked, $finished['refusal']);
            }

            /** @var PetCareSession $session */
            $session = $finished['session'];
            $extra = ['result' => self::wandResultPayload($session)];
            if ($finished['repeat']) {
                return $this->unchanged(ActionResult::UNCHANGED, $locked, $extra);
            }
            if (! $finished['counted']) {
                // Nothing counts, but the running game ended (QA: siblings / parents
                // must not keep seeing session_running) → one `wand_finished`.
                if ($locked->isDirty()) {
                    $locked->saveQuietly();
                }

                return new ActionResult(
                    status: ActionResult::REJECTED,
                    dailyStepCount: (int) $locked->daily_step_count,
                    energyLevel: $locked->displayMetric('energy_level'),
                    hygieneLevel: $locked->displayMetric('hygiene_level'),
                    extra: $extra,
                    broadcastAs: 'wand_finished',
                );
            }

            $number = $this->wand->applySuccess($locked, $session);
            $locked->pet_state = $this->decay->derivePetState($locked, $now);
            $locked->saveQuietly();
            $this->logActivity($locked, ActivityType::PlayedWand, $number, $child->id);

            return $this->result(ActionResult::ACCEPTED, $locked, extra: $extra);
        });
    }

    /**
     * The wand session as the app gets it on start (instants in the family tz).
     *
     * @return array{id: string, started_at: string, ends_at: string, expires_at: string, duration_ms: int, catch_at_ms: int, pounces_ms: list<int>, min_away_moves: int, segments: int, min_move_interval_ms: int, pounce_window_ms: int}
     */
    public static function wandSessionPayload(PetCareSession $session, string $tz): array
    {
        $schedule = $session->schedule;

        return [
            'id' => $session->public_id,
            'started_at' => $session->started_at->copy()->setTimezone($tz)->toIso8601String(),
            // The catch: the game has run its course; finish from here on.
            'ends_at' => $session->ends_at->copy()->setTimezone($tz)->toIso8601String(),
            // Last moment a finish is accepted (TTL).
            'expires_at' => $session->expires_at->copy()->setTimezone($tz)->toIso8601String(),
            'duration_ms' => $session->duration_ms,
            'catch_at_ms' => (int) ($schedule['catch_at_ms'] ?? $session->duration_ms),
            'pounces_ms' => array_map('intval', array_values($schedule['pounces_ms'] ?? [])),
            // What the server checks at finish (the app may show progress).
            'min_away_moves' => (int) ($schedule['min_away_moves'] ?? 0),
            'segments' => (int) ($schedule['segments'] ?? 1),
            'min_move_interval_ms' => (int) ($schedule['min_move_interval_ms'] ?? 0),
            // An "away" move must follow each pounce within this window (QA M5-R06-04).
            'pounce_window_ms' => (int) ($schedule['pounce_window_ms'] ?? 0),
        ];
    }

    /**
     * The server's verdict on finish.
     *
     * @return array{session_id: string, success: bool, reason: 'too_few_moves'|'not_spread'|'wrong_technique'|'missed_pounces'|'too_uniform'|null, away_moves: int, toward_moves: int, segments_hit: int, segments: int, pounces_hit: int, pounces: int}
     */
    public static function wandResultPayload(PetCareSession $session): array
    {
        $r = $session->result ?? [];

        return [
            'session_id' => $session->public_id,
            'success' => (bool) ($r['success'] ?? false),
            'reason' => $r['reason'] ?? null,
            'away_moves' => (int) ($r['away_moves'] ?? 0),
            'toward_moves' => (int) ($r['toward_moves'] ?? 0),
            'segments_hit' => (int) ($r['segments_hit'] ?? 0),
            'segments' => (int) ($r['segments'] ?? 0),
            'pounces_hit' => (int) ($r['pounces_hit'] ?? 0),
            'pounces' => (int) ($r['pounces'] ?? 0),
        ];
    }

    /**
     * The session as the app gets it on start (instants in the family tz).
     *
     * @return array{id: string, command: 'sit'|'come'|'place'|'potty', started_at: string, ends_at: string, expires_at: string, duration_ms: int, praise_window_ms: int, min_reaction_ms: int, trials: list<array{index: int, cue_at_ms: int, obeys: bool, obey_at_ms: int|null, window_end_ms: int|null}>}
     */
    public static function sessionPayload(PetTrainingSession $session, string $tz): array
    {
        return [
            'id' => $session->public_id,
            'command' => $session->command->value,
            'started_at' => $session->started_at->copy()->setTimezone($tz)->toIso8601String(),
            // The schedule has run its course; finish from here on.
            'ends_at' => $session->ends_at->copy()->setTimezone($tz)->toIso8601String(),
            // Last moment a finish is accepted (TTL).
            'expires_at' => $session->expires_at->copy()->setTimezone($tz)->toIso8601String(),
            'duration_ms' => $session->duration_ms,
            'praise_window_ms' => (int) $session->schedule['praise_window_ms'],
            // A praise earlier than obey_at + this counts as too early (human reaction floor).
            'min_reaction_ms' => (int) ($session->schedule['min_reaction_ms'] ?? TrainingService::MIN_REACTION_MS),
            'trials' => TrainingService::trialsOf($session->schedule),
        ];
    }

    /**
     * The scored session on finish.
     *
     * @return array{session_id: string, command: 'sit'|'come'|'place'|'potty', successes: int, obeyed: int, trials: list<array{index: int, obeys: bool, outcome: 'in_time'|'too_early'|'too_late'|'no_praise'|'waited'|'praised_without_obeying', tap_ms: int|null}>, progress_before: int, progress_after: int, progress_gain: float}
     */
    public static function resultPayload(PetTrainingSession $session): array
    {
        $before = (float) $session->progress_before;
        $gain = (float) $session->progress_gain;

        return [
            'session_id' => $session->public_id,
            'command' => $session->command->value,
            'successes' => (int) ($session->result['successes'] ?? 0),
            'obeyed' => (int) ($session->result['obeyed'] ?? 0),
            'trials' => $session->result['trials'] ?? [],
            'progress_before' => Pet::displayValue($before),
            'progress_after' => Pet::displayValue($before + $gain),
            // Precise gain in percentage points (rounded to 0.01 for display).
            'progress_gain' => round($gain, 2),
        ];
    }

    /**
     * After a mess was resolved: hygiene 100 % and its neglect clock cleared
     * once no mess of any kind is open (M5-R02); the pet state follows.
     */
    private function restoreHygieneIfResolved(Pet $locked, bool $stillOpen, CarbonInterface $now): void
    {
        if (! $stillOpen) {
            $locked->forceFill([
                'hygiene_level' => 100.0,
                'hygiene_zero_since' => null,
            ]);
        }
        $locked->pet_state = $this->decay->derivePetState($locked, $now);
        $locked->saveQuietly();
    }

    /**
     * Feed the dog (PRODUCT_SPEC §5): inside a breed feed window
     * (family-local), one feed per window, hunger → 100 %. Emergency meal
     * (M3-12): also outside a window while hunger shows ≤ 20 % — the same
     * `fed_pet` row, which lies outside every window, so the ledger keeps
     * the window missed and the next window stays feedable. Refused while the
     * mess isn't cleaned (hygiene shows 0 %, PRODUCT_SPEC §8). Rules:
     * CareScheduleService::feedCheck(). Activity value = hunger shown before
     * feeding.
     */
    public function feed(Pet $pet, ?User $actor = null): ActionResult
    {
        return $this->withLockedPet($pet, ActivityType::FedPet, function (Pet $locked) use ($actor): ActionResult {
            $now = now()->startOfSecond();

            if ($reason = $locked->actionLockReasonFor($actor)) {
                return $this->locked($locked, $reason);
            }

            // Decay owed since the last tick applies to the old value.
            $this->decay->catchUpLocked($locked);

            $check = $this->schedule->feedCheck($locked, $this->breedConfigOf($locked), $now);
            if (! $check->allowed) {
                return $this->refused($locked, $check->refusal ?? CareRefusal::OutsideFeedWindow, $check->nextAllowedAt);
            }

            $before = $locked->displayMetric('hunger_level');
            $locked->forceFill([
                'hunger_level' => 100.0,
                'hunger_zero_since' => null,
            ]);
            $locked->pet_state = $this->decay->derivePetState($locked, $now);
            $locked->saveQuietly();

            $this->logActivity($locked, ActivityType::FedPet, $before, $actor?->id ?? $locked->user_id);

            // M3-12: the app words an emergency meal honestly ("doesn't count as on time").
            return $this->result(ActionResult::ACCEPTED, $locked, extra: ['feed_mode' => $check->mode]);
        });
    }

    /**
     * Fresh water (PRODUCT_SPEC §5): at most breed water_times_per_day per
     * family-local day, at least water_min_gap_minutes since the last refill,
     * thirst → 100 %. Refused while the mess isn't cleaned.
     * Activity value = thirst shown before the refill.
     */
    public function water(Pet $pet, ?User $actor = null): ActionResult
    {
        return $this->withLockedPet($pet, ActivityType::WateredPet, function (Pet $locked) use ($actor): ActionResult {
            $now = now()->startOfSecond();

            if ($reason = $locked->actionLockReasonFor($actor)) {
                return $this->locked($locked, $reason);
            }

            $this->decay->catchUpLocked($locked);

            $check = $this->schedule->waterCheck($locked, $this->breedConfigOf($locked), $now);
            if (! $check->allowed) {
                return $this->refused($locked, $check->refusal ?? CareRefusal::WaterTooSoon, $check->nextAllowedAt);
            }

            $before = $locked->displayMetric('thirst_level');
            $locked->forceFill([
                'thirst_level' => 100.0,
                'thirst_zero_since' => null,
            ]);
            $locked->pet_state = $this->decay->derivePetState($locked, $now);
            $locked->saveQuietly();

            $this->logActivity($locked, ActivityType::WateredPet, $before, $actor?->id ?? $locked->user_id);

            return $this->result(ActionResult::ACCEPTED, $locked);
        });
    }

    /**
     * Store the child's signed responsibility contract (PRODUCT_SPEC §3):
     * once per pet, server time as signed_at. A second signature is refused
     * (CareRefusal::ContractAlreadySigned → 409); the first one stays.
     * The caller validated the signature (SignContractRequest).
     *
     * Contract before birth (David 2026-10-04, M1-07b): for an unborn pet
     * the signature is the birth — in the same transaction, under the same
     * row lock, `born_at` = signed_at, metrics 100 %, every clock starts now
     * (Pet::giveBirth). The only lock it passes is `contract_required`; a
     * hard stop / inactive pet is still 423. A pet born before M1-07b
     * (grandfathered) can sign later; that only stores the contract.
     * One `PetUpdated('signed_contract')` after commit.
     *
     * Shared pet (M2-01): one contract per (pet, child). The pet is born at
     * the FIRST contract; a child who joins later signs their own (no
     * rebirth) and is locked with contract_required until then. The same
     * child signing twice → 409.
     */
    public function signContract(Pet $pet, User $child, string $format, string $signature): ActionResult
    {
        // M3-13: a purchase made before the birth that is still unassigned pays
        // this pet first (own transaction, lock order family → pet → credit), so
        // the birth below is not locked. Only when this contract will proceed
        // (QA PR #83 m2): an unborn pet whose only lock for this child is the
        // contract itself, and no contract of this child yet. A race between
        // this pre-check and the locked birth only means the credit pays a pet
        // that is the family's only unpaid one anyway.
        $fresh = $pet->fresh();
        if ($fresh !== null && $fresh->isUnborn()
            && $fresh->actionLockReasonFor($child) === PetLockReason::ContractRequired
            && ! PetContract::where('pet_id', $fresh->id)->where('user_id', $child->id)->exists()) {
            $this->credits->assignAvailableBeforeBirth($fresh);
        }

        return $this->withLockedPet($pet, ActivityType::SignedContract, function (Pet $locked) use ($child, $format, $signature): ActionResult {
            $lockReason = $locked->actionLockReasonFor($child);
            if ($lockReason !== null && $lockReason !== PetLockReason::ContractRequired) {
                return $this->locked($locked, $lockReason);
            }

            if (PetContract::where('pet_id', $locked->id)->where('user_id', $child->id)->exists()) {
                return $this->refused($locked, CareRefusal::ContractAlreadySigned);
            }

            $now = now()->startOfSecond();

            PetContract::create([
                'pet_id' => $locked->id,
                'user_id' => $child->id,
                'signature_format' => $format,
                'signature' => $signature,
                'signed_at' => $now,
            ]);

            if ($locked->isUnborn()) {
                // M3-13: no free trial — giveBirth() sets trial_ends_at = birth.
                $locked->giveBirth($now);
                $locked->pet_state = $this->decay->derivePetState($locked, $now);
                $locked->saveQuietly();
                // M3-13 (David 2026-10-08): an unpaid challenge waits for the purchase
                // from birth on — locked now, so the program clock never runs unpaid.
                $this->challenges->lockAtBirth($locked, $now);

                // State videos start at birth (M4-03, 2026-10-05): queued after the commit,
                // once the reference image is stored (else StorePetMedia queues them later).
                $petId = $locked->id;
                DB::afterCommit(function () use ($petId): void {
                    try {
                        $born = Pet::find($petId);

                        if ($born !== null) {
                            app(PetMediaService::class)->queueStateVideos($born);
                        }
                    } catch (\Throwable $e) {
                        // The contract is signed either way; media:backfill can catch up.
                        Log::error('signContract: state videos not queued', ['pet_id' => $petId, 'error' => $e->getMessage()]);
                        report($e);
                    }
                });
            }

            $this->logActivity($locked, ActivityType::SignedContract, null, $child->id);

            return $this->result(ActionResult::ACCEPTED, $locked);
        });
    }

    /**
     * Run $action on a freshly locked copy of the pet, copy the result back
     * into $pet and broadcast once after commit if something changed.
     *
     * @param  Closure(Pet): ActionResult  $action
     */
    private function withLockedPet(Pet $pet, ActivityType|string $activity, Closure $action): ActionResult
    {
        $eventType = $activity instanceof ActivityType ? $activity->value : $activity;

        $work = function () use ($pet, $action): array {
            $locked = Pet::whereKey($pet->id)->lockForUpdate()->firstOrFail();
            // An illness that ended before the next tick: fresh start first,
            // so the action sees the recovered pet.
            $recovered = $locked->recoverFromIllnessIfDue(now()->startOfSecond());
            $dayBefore = $locked->last_step_reset_at?->toIso8601String();
            $shownBefore = $this->shownState($locked);

            $result = $action($locked);
            if ($recovered && $locked->isDirty()) {
                $locked->saveQuietly();
            }

            // The action closed the previous local day (steps / energy → 0,
            // maybe a walk illness planned) or caught up decay that changed
            // what the child sees, even if the action itself was refused.
            $dayClosed = $dayBefore !== $locked->last_step_reset_at?->toIso8601String();
            $shownChanged = $shownBefore !== $this->shownState($locked);

            return [$locked, $result, $recovered || $dayClosed || $shownChanged];
        };

        /** @var array{0: Pet, 1: ActionResult, 2: bool} $outcome */
        $outcome = DB::transactionLevel() > 0 ? $work() : DB::transaction($work);
        [$locked, $result, $bookkeeping] = $outcome;

        $pet->setRawAttributes($locked->getAttributes(), true);

        // One broadcast after commit: the action's own event, or a plain
        // metric_changed for a recovery / midnight reset / decay catch-up
        // the action applied.
        if ($result->changed()) {
            PetUpdated::afterCommit($locked, $eventType);
        } elseif ($result->broadcastAs !== null) {
            PetUpdated::afterCommit($locked, $result->broadcastAs);
        } elseif ($bookkeeping) {
            PetUpdated::afterCommit($locked, 'metric_changed');
        }

        return $result;
    }

    /**
     * Persist bookkeeping (e.g. a midnight reset, illness recovery) without
     * counting as an action.
     */
    /**
     * @param  array<string, mixed>  $extra
     */
    private function unchanged(string $status, Pet $locked, array $extra = []): ActionResult
    {
        if ($locked->isDirty()) {
            $locked->saveQuietly();
        }

        return $this->result($status, $locked, extra: $extra);
    }

    /**
     * Hard stop / illness / inactive / game over / contract required:
     * nothing is changed (a due recovery was already applied by withLockedPet).
     */
    private function locked(Pet $locked, PetLockReason $reason): ActionResult
    {
        return $this->result(ActionResult::LOCKED, $locked, lockReason: $reason);
    }

    /**
     * Today's step row of one child on this pet (caller holds the pet lock).
     * Steps the pet already counts that no child row explains (recorded
     * before per-child steps existed) are given to the primary caretaker.
     */
    private function childStepRow(Pet $locked, ?int $actorId, string $today): PetDailyStep
    {
        $rows = PetDailyStep::where('pet_id', $locked->id)->where('local_date', $today)->get();
        $untracked = (int) $locked->daily_step_count - (int) $rows->sum('steps');

        if ($untracked > 0 && $locked->user_id !== null) {
            $primary = $rows->firstWhere('user_id', $locked->user_id)
                ?? new PetDailyStep(['pet_id' => $locked->id, 'user_id' => $locked->user_id, 'local_date' => $today, 'steps' => 0]);
            $primary->forceFill([
                'steps' => (int) $primary->steps + $untracked,
                'last_sync_at' => $primary->last_sync_at ?? $locked->last_step_sync_at,
            ])->save();
            $rows = $rows->reject(fn (PetDailyStep $r) => (int) $r->user_id === (int) $locked->user_id)->push($primary);
        }

        return $rows->firstWhere('user_id', $actorId)
            ?? new PetDailyStep(['pet_id' => $locked->id, 'user_id' => $actorId, 'local_date' => $today, 'steps' => 0]);
    }

    /**
     * A game rule refuses the action; bookkeeping done so far (decay
     * catch-up, midnight) is still saved.
     */
    private function refused(Pet $locked, CareRefusal $refusal, ?CarbonInterface $nextAllowedAt = null): ActionResult
    {
        if ($locked->isDirty()) {
            $locked->saveQuietly();
        }

        return $this->result(ActionResult::REFUSED, $locked, refusal: $refusal, nextAllowedAt: $nextAllowedAt);
    }

    private function result(
        string $status,
        Pet $pet,
        int $acceptedSteps = 0,
        ?PetLockReason $lockReason = null,
        ?CareRefusal $refusal = null,
        ?CarbonInterface $nextAllowedAt = null,
        array $extra = [],
    ): ActionResult {
        return new ActionResult(
            status: $status,
            acceptedSteps: $acceptedSteps,
            dailyStepCount: (int) $pet->daily_step_count,
            energyLevel: $pet->displayMetric('energy_level'),
            hygieneLevel: $pet->displayMetric('hygiene_level'),
            lockReason: $lockReason,
            refusal: $refusal,
            nextAllowedAt: $nextAllowedAt,
            extra: $extra,
        );
    }

    /**
     * What the child / parent sees of the pet (displayed metrics and state);
     * decides whether bookkeeping inside an action needs a broadcast.
     *
     * @return array<string, mixed>
     */
    private function shownState(Pet $pet): array
    {
        return array_merge($pet->displayMetrics(), [
            'pet_state' => $pet->pet_state?->value,
            'escalation_level' => (int) $pet->escalation_level,
            'is_ill' => $pet->isIll(),
        ]);
    }

    /**
     * Energy (0–100, precise) for a number of steps and a goal:
     * min(100, steps / goal × 100); a goal of 0 means "no walk needed".
     */
    public static function energyForSteps(int $steps, int $goal): float
    {
        if ($goal <= 0) {
            return 100.0;
        }

        return min(100.0, max(0, $steps) / $goal * 100);
    }

    private function breedConfigOf(Pet $pet): BreedConfig
    {
        return $pet->breedConfig()
            ?? throw new RuntimeException("No breed config for pet {$pet->id}.");
    }

    /**
     * One activities_log row per applied action. Created without model events:
     * the action broadcasts once itself after commit.
     */
    private function logActivity(Pet $pet, ActivityType $type, ?int $value, ?int $actorId): void
    {
        ActivityLog::withoutEvents(fn () => ActivityLog::create([
            'pet_id' => $pet->id,
            'actor_user_id' => $actorId,
            'activity_type' => $type->value,
            'value' => $value,
        ]));
    }
}
