<?php

namespace App\Http\Resources;

use App\Enums\MediaDisplay;
use App\Enums\PetLockReason;
use App\Models\Pet;
use App\Models\User;
use App\Services\BehaviourPayload;
use App\Services\BirthdayPayload;
use App\Services\CareGuidePayload;
use App\Services\CareScheduleService;
use App\Services\CareTasksPayload;
use App\Services\ChallengeCertificateService;
use App\Services\ChildCertificatePayload;
use App\Services\GroomingPayload;
use App\Services\LitterPayload;
use App\Services\Media\PetMediaPayload;
use App\Services\Media\PetMediaService;
use App\Services\Media\PreparationPayload;
use App\Services\PetPlanPayload;
use App\Services\PetProfilePayload;
use App\Services\PetSleepService;
use App\Services\PlayPayload;
use App\Services\Results\WaterStatus;
use App\Services\ScratchingPayload;
use App\Services\SleepPayload;
use App\Services\SocialisationPayload;
use App\Services\TrainingPayload;
use App\Services\TrainingService;
use App\Services\WandPayload;
use App\Services\WishService;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full pet state for the child app (GET /api/child/pet, and `state` in every
 * child action response — M1-07). Metrics are the displayed integers 0–100
 * (Pet::displayMetric). Instants are ISO 8601 in the family timezone
 * (e.g. 2026-10-04T17:00:00+02:00); feed windows also as local "HH:MM".
 *
 * `can_feed` / `can_water` combine every server rule (lock, mess, window /
 * water gap + refill threshold, emergency meal — CareScheduleService::feedCheck / waterCheck), so
 * the app can disable the button and show `next_*` times; `feed_mode` says
 * whether a feed now is an on-time window meal or an emergency meal (M3-12).
 *
 * Family model (M2-01): the state is for the requesting child. `lock`,
 * `pet.awaiting_contract` and `contract` are that child's (a child who
 * joined a shared pet sees contract_required until they sign, while the pet
 * itself is born); `steps.steps_today` is the pet's combined count (energy),
 * `steps.my_steps_today` the child's own.
 *
 * `pet.profile` (M5-R01): origin, age, life stage and today's stage rules
 * (meals and which the parent covers in quiet hours, step goal) —
 * {@see PetProfilePayload}. `steps.goal` is today's stage goal.
 *
 * `behaviour` (M5-R02): puppy bladder clock (`take_out`), open messes
 * (`active_events`: poop | accident | chewing, with the 2-hour deadline),
 * the behaviour video to show (`scene`) and whether the take-out /
 * resolve-chewing actions apply now — {@see BehaviourPayload}.
 *
 * `training` (M5-R03): progress per command, today's routine, the running
 * session, the dog's daily mini-game budget and whether a session can start
 * now — {@see TrainingPayload}.
 *
 * `sleep` (M5-T02): whether the pet sleeps now (family quiet hours,
 * PetSleepService) and when it wakes — play and school are off while it
 * sleeps; `training.blocked_reason` / `training.next_allowed_at` explain a
 * false `training.can_start` (TrainingService::startRefusal).
 *
 * `play` (M5-R05): whether the child can play / cuddle now, the dog's
 * invitation and the happy scene — {@see PlayPayload}; null without play.
 *
 * `wand` (M5-R06-04, cats): the daily wand play — goal, today's successful
 * sessions, the 2 h gap, the running game, whether a start is possible —
 * {@see WandPayload}; null for a dog. A cat's `pet.energy_level` /
 * `steps.energy_level` is its play meter (plan T5).
 * `litter` / `grooming` / `scratching` (M5-R06-05, cats): the litter tray
 * (uses, scoop deadlines, weekly change), a brushed cat's grooming (week,
 * matted coat) and the scratching resolve — {@see LitterPayload},
 * {@see GroomingPayload}, {@see ScratchingPayload}; null for a dog —
 * except `grooming` for a brushed dog (M5-R13, `grooming_enabled`).
 * `socialisation` (M5-R17, David 2026-10-12): the puppy's weekly "new
 * experience" — {@see SocialisationPayload}; null when this program week has
 * no task (not a puppy week, no breed row, a cat, `socialisation_enabled` off).
 *
 * `care_tasks` (M5-R14 / R15 / R19 / R20, David 2026-10-24): the child's breed
 * care mini-games (teeth, folds, ears, nails) — {@see CareTasksPayload}; an empty
 * list when the breed's source states no frequency or `care_tasks_enabled` is off.
 * `walk_heat` (M5-R14): today's summer midday window of a flat-faced breed whose
 * source says not to exercise it in the heat (calm warning, no penalty); null otherwise.
 *
 * `feeding.digesting_until` (M5-R16): end of the "wait with the walk, the dog
 * is digesting" warning after a meal, for breeds whose source advises a gap
 * between eating and exercise (bloat / GDV); null otherwise. A warning only.
 *
 * `certificate` (M2-01d): this child's own challenge end on this pet —
 * null while it runs; `{outcome: 'report', …null}` without score or reason
 * (the child just sees "challenge finished"); for a certificate the names,
 * score, number and — once a parent generated it — the signed PDF URL
 * ({@see ChildCertificatePayload}).
 *
 * `care_guide` (M5-T05): the numbers of the card "Kako skrbim za …" — meals,
 * steps, take-out hold hours, water rule, grooming per week, sleep (quiet
 * hours), cat play / litter / scratching — read from the same services the
 * simulation uses ({@see CareGuidePayload}); the app renders the sentences.
 *
 * `pet.media` (M4-05): status + signed URLs of the stored reference image and
 * state videos ({@see PetMediaService::mediaFor()}); `current_video_url` is
 * the video for `pet_state`, falling back to idle.
 *
 * @property Pet $resource
 */
class ChildPetStateResource extends JsonResource
{
    /** No `data` wrapper. */
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $pet = $this->resource;
        $now = now()->startOfSecond();
        $tz = $pet->familyTimezone();
        $config = $pet->breedConfig();
        $schedule = app(CareScheduleService::class);

        $actor = $request->user();
        $actor = $actor instanceof User && $actor->isChild() ? $actor : null;

        $lockReason = $pet->actionLockReasonFor($actor);
        $locked = $lockReason !== null;
        $needsCleaning = $pet->displayMetric('hygiene_level') <= 0;
        $iso = fn (?CarbonInterface $at): ?string => $at?->copy()->setTimezone($tz)->toIso8601String();

        $feeding = $config ? $schedule->feeding($pet, $config, $now) : null;
        $water = $config ? $schedule->water($pet, $config, $now) : null;
        // M3-12: one answer to "may the child feed / water now" (also used by the actions and pushes).
        $feedCheck = $config && $feeding ? $schedule->feedCheck($pet, $config, $now, $feeding) : null;
        $waterCheck = $config && $water ? $schedule->waterCheck($pet, $config, $now, $water) : null;
        $canFeed = ! $locked && (bool) $feedCheck?->allowed;
        $contract = $actor !== null ? $pet->contractOf($actor) : $pet->contract;
        $slots = $pet->relationLoaded('media') ? $pet->getRelation('media') : $pet->media()->get();
        $media = PetMediaPayload::for($pet, $slots);
        $profile = PetProfilePayload::for($pet, $now);
        $behaviour = BehaviourPayload::for($pet, $now);
        $hasOpenChewing = in_array('chewing', array_column($behaviour->activeEvents, 'kind'), true);
        $training = TrainingPayload::for($pet, $actor, $now);
        // M5-T02: the one start rule (sleep, running session, day ending, budget, share).
        $trainingRefusal = $training->enabled && ! $pet->isUnborn()
            ? app(TrainingService::class)->startRefusal($pet, $actor, $now)
            : null;

        return [
            'pet' => [
                'id' => $pet->id,
                'breed_type' => $pet->breed_type->value,
                // M5-R06-01: dog | cat.
                'species' => $pet->speciesValue()->value,
                // M5-R08: optional name set by a parent (null = none) — a label only.
                'name' => $pet->name,
                // null until the first contract is signed (unborn, M1-07b).
                'born_at' => $pet->born_at?->copy()->setTimezone($tz)->toIso8601String(),
                // This child must sign before acting (pet unborn, or this
                // child joined a shared pet and hasn't signed yet — M2-01).
                'awaiting_contract' => $lockReason === PetLockReason::ContractRequired,
                'caretakers_count' => $pet->caretakerRows()->count(),
                // Months (= program weeks) since birth: the 12-week challenge clock (payment-lock time excluded, M3-11b).
                'virtual_age_months' => $pet->virtualAgeInMonths(),
                // M5-R01: the dog's age (arrival age + weeks since birth), origin, stage;
                // null for a legacy pet (pre-M5 rules, `profile.legacy`).
                'age_months' => $profile->ageMonths,
                /** @var 'bought'|'adopted'|null */
                'origin' => $profile->origin,
                /** @var 'puppy'|'young'|'adult'|'senior'|null */
                'life_stage' => $profile->lifeStage,
                'profile' => $profile->toArray(),
                'hunger_level' => $pet->displayMetric('hunger_level'),
                'thirst_level' => $pet->displayMetric('thirst_level'),
                'energy_level' => $pet->displayMetric('energy_level'),
                'hygiene_level' => $pet->displayMetric('hygiene_level'),
                'pet_state' => $pet->pet_state->value,
                'escalation_level' => (int) $pet->escalation_level,
                'needs_cleaning' => $needsCleaning,
                'is_active' => (bool) $pet->is_active,
                'is_hard_stopped' => (bool) $pet->is_hard_stopped,
                'is_ill' => $pet->isIll(),
                'illness_until' => $pet->isIll() ? $iso($pet->illness_until) : null,
                'is_game_over' => (bool) $pet->is_game_over,
                'certificate_eligible' => (bool) $pet->certificate_eligible,
                // M3-11 / M3-13: free | challenge, payment_required / paid (trial only for a pre-M3-13 trial; null for free).
                'plan' => PetPlanPayload::for($pet, $tz, $now)->toArray(),
                // Legacy fields (pre-M4-05 builds): now our signed URLs, never fal URLs.
                'current_video_url' => $media->currentVideoUrl,
                'media_status' => $pet->media_status,
                'reference_image_url' => $media->referenceImageUrl,
                // AI media (M4-03 / M4-05): signed, expiring URLs (re-issued with every state).
                'media' => $media->toArray(),
                // M5-T06: is the pet ready (everything it shows stored), what is still on its way,
                // whether to show »Pripravljamo …« — never says how it is prepared.
                'preparation' => PreparationPayload::for($pet, $slots)->toArray(),
                // M5-T13: the pet's first birthday (1 year by the simulation's age formula) —
                // null when it has none in the simulation. `is_today` = party hat all day.
                'birthday' => BirthdayPayload::for($pet, $now)?->toArray(),
            ],
            'lock' => [
                'is_locked' => $locked,
                // game_over | inactive | hard_stopped | payment_required | contract_required | ill | null
                'reason' => $lockReason?->value,
                // End of the vet visit; null for locks without an end time.
                'until' => $lockReason === PetLockReason::Ill ? $iso($pet->illness_until) : null,
            ],
            'timezone' => $tz,
            'server_time' => $iso($now),
            // M5-T02 (David 2026-10-20): the pet sleeps now (family quiet hours) and when it
            // wakes — play and school are off while asleep ({@see SleepPayload}).
            'sleep' => SleepPayload::for($pet, $now)->toArray(),
            'feeding' => [
                'windows' => array_map(
                    fn (array $window): array => ['start' => $window[0], 'end' => $window[1]],
                    $feeding->windows ?? [],
                ),
                'current_window' => $feeding?->currentStart
                    ? ['start' => $iso($feeding->currentStart), 'end' => $iso($feeding->currentEnd)]
                    : null,
                'fed_in_current_window' => (bool) $feeding?->fedInCurrent,
                'can_feed' => $canFeed,
                /**
                 * M3-12: how a feed now would count — `window` (inside an unused meal
                 * window, on time) or `emergency` (outside a window because hunger shows
                 * ≤ `emergency_threshold` %; the missed window stays missed). Null when
                 * feeding is not possible now.
                 *
                 * @var 'window'|'emergency'|null
                 */
                'feed_mode' => $canFeed ? $feedCheck?->mode : null,
                /**
                 * M3-12 rule A: displayed hunger (%) at or below which an emergency meal
                 * is allowed — sent only while the last ended meal window was missed
                 * (nothing fed since its start) and no window is open; null otherwise.
                 * The app compares the live hunger with it and nothing else.
                 *
                 * @var int|null
                 */
                'emergency_threshold' => $feeding?->emergencyPossible() ? CareScheduleService::EMERGENCY_FEED_THRESHOLD : null,
                // The current window while unused, otherwise the next one.
                'next_feed_window' => $feeding?->nextStart
                    ? ['start' => $iso($feeding->nextStart), 'end' => $iso($feeding->nextEnd)]
                    : null,
                'last_fed_at' => $iso($feeding?->lastFedAt),
                /**
                 * M5-R16 (David 2026-10-12): until this instant the app shows the digestion
                 * warning (wait with the walk, the dog is digesting): last meal + the breed's
                 * meal / exercise gap (Cane Corso, Dobermann, Boxer 60 min). Null for every other
                 * breed, a cat, a legacy pet, or once the gap has passed. Only a warning:
                 * steps still count.
                 *
                 * @var string|null
                 */
                'digesting_until' => $iso($schedule->digestingUntil($pet, $feeding?->lastFedAt, $now)),
            ],
            // M5-F11 (David 2026-10-12, "Dolij, ko se prazni"): no daily cap — water when the
            // live thirst shows ≤ `refill_below` and `min_gap_minutes` have passed.
            'water' => [
                // Expected refills per day for the Care Score (a minimum, not a cap).
                'times_per_day' => $water->timesPerDay ?? 0,
                'min_gap_minutes' => $water->minGapMinutes ?? 0,
                'used_today' => $water->usedToday ?? 0,
                // Expected refills still open for the score today: max(0, times_per_day − used_today). Not a cap.
                'remaining_today' => $water?->remainingToday() ?? 0,
                'last_watered_at' => $iso($water?->lastAt),
                'can_water' => ! $locked && (bool) $waterCheck?->allowed,
                // Earliest next refill (later of the gap end and the minute thirst shows ≤ refill_below);
                // null when allowed now or when no time can be forecast (frozen pet, > 48 h).
                'next_allowed_at' => $iso($water?->nextAllowedAt),
                /**
                 * Display only (David 2026-10-25): the time the app shows for the next refill —
                 * `next_allowed_at`, or the pet's wake time when that falls while it sleeps (quiet
                 * hours, PetSleepService::shownAt — »jutri 7:00« instead of »ob 23:58«). Null with
                 * `next_allowed_at`. The refill rule is `next_allowed_at`; this never changes it.
                 *
                 * @var string|null
                 */
                'shown_at' => $iso(app(PetSleepService::class)->shownAt($pet, $water?->nextAllowedAt)),
                /**
                 * M5-F11: displayed thirst (%) at or below which water is allowed (breed
                 * `water_refill_below`, default 60).
                 *
                 * @var int
                 */
                'refill_below' => $water->refillBelow ?? WaterStatus::DEFAULT_REFILL_BELOW,
            ],
            'steps' => [
                'steps_today' => (int) $pet->daily_step_count,
                'my_steps_today' => $actor !== null
                    ? $pet->stepsTodayOf($actor, $now)
                    : (int) $pet->daily_step_count,
                /**
                 * M5-T12: the child's own steps today from a device only (`my_steps_today`
                 * minus walk minutes a parent logged). The app compares its health / sensor
                 * reading with this number; `my_steps_today` is for display.
                 *
                 * @var int
                 */
                'my_device_steps_today' => $actor !== null
                    ? $pet->deviceStepsTodayOf($actor, $now)
                    : (int) $pet->daily_step_count,
                // Today's step goal of the dog's life stage (M5-R01).
                'goal' => $profile->stepGoal,
                'energy_level' => $pet->displayMetric('energy_level'),
                /**
                 * M5-T12 »Prilagojen način« of the requesting child (set by a parent): true =
                 * the phone reads wheelchair pushes instead of steps (1 push = 1 step) and the
                 * app says "potiski" / "pushes"; goal, energy and scores are the same numbers.
                 * Always false for a parent's view.
                 *
                 * @var bool
                 */
                'adapted_mode' => (bool) ($actor?->adapted_mode ?? false),
            ],
            /**
             * M5-T11 / M5-T17 settings of the requesting child (display: the child or a parent; easy gestures: a parent only).
             */
            'settings' => [
                /**
                 * »Prikaz: Video / Slika«: `video` (default) plays the state videos; `picture`
                 * shows the key image of the current state and downloads no video. Always
                 * `video` for a parent's view.
                 *
                 * @var 'video'|'picture'
                 */
                'media_display' => ($actor?->media_display ?? MediaDisplay::Video)->value,
                /**
                 * M5-T17 »Lažje kretnje« (set by a parent): true = press-and-hold instead of
                 * swipe in every swipe game (brushing, wand, litter change, ball play); false
                 * (default) = swipe only. The app also uses hold when the phone's screen
                 * reader is on. Always false for a parent's view.
                 *
                 * @var bool
                 */
                'easy_gestures' => (bool) ($actor?->easy_gestures ?? false),
            ],
            'contract' => [
                'signed' => $contract !== null,
                'signed_at' => $iso($contract?->signed_at),
            ],
            // M5-R02 behaviour events: puppy bladder clock ("Pelji ven"), open messes
            // (poop / accident / chewing) with their 2-hour deadline, the behaviour video.
            'behaviour' => [
                'take_out' => $behaviour->takeOut,
                'active_events' => $behaviour->activeEvents,
                /** @var 'accident'|'chewing'|'scratching'|null */
                'scene' => $behaviour->scene,
                /**
                 * POST /api/child/pet/take-out would be accepted (puppy, not locked).
                 *
                 * @var bool
                 */
                'can_take_out' => (bool) (! $locked && $behaviour->takeOut !== null),
                /**
                 * POST /api/child/pet/resolve-chewing has something to tidy up.
                 *
                 * @var bool
                 */
                'can_resolve_chewing' => (bool) (! $locked && $hasOpenChewing),
            ],
            // M5-R03 training: progress per command (sit, come, place, potty), today's
            // routine, the running session and the dog's daily mini-game budget.
            'training' => array_merge($training->toArray(), [
                /**
                 * POST /api/child/pet/training/start would be accepted now (enabled, not
                 * locked, the pet awake — M5-T02, no running session, enough of the dog's
                 * budget AND of this child's fair share left for one session, and the
                 * session would end before the family-local midnight) —
                 * TrainingService::startRefusal().
                 *
                 * @var bool
                 */
                'can_start' => ! $locked && $actor !== null && $training->enabled && $trainingRefusal === null,
                /**
                 * M5-T02: why a start would be refused now (null = it may start, or training
                 * is off; lock reasons are in `lock`). `training_pet_sleeping` while the pet
                 * sleeps (next_allowed_at = when it wakes).
                 *
                 * @var 'training_not_available'|'training_pet_sleeping'|'training_session_active'|'training_day_ending'|'training_daily_budget_used'|'training_child_share_used'|null
                 */
                'blocked_reason' => $training->enabled ? $trainingRefusal['refusal']->value ?? null : null,
                /**
                 * M5-T02: when `blocked_reason` ends (wake time, the running session's expiry,
                 * local midnight; family offset); null without a time.
                 *
                 * @var string|null
                 */
                'next_allowed_at' => $training->enabled ? $iso($trainingRefusal['next_allowed_at'] ?? null) : null,
            ]),
            // M5-R05 play & cuddle: can the child play now, the dog's invitation shown now,
            // the 30-minute happy scene. Null for a pet without play (free mutt, legacy, unpaid).
            'play' => PlayPayload::for($pet, $actor, $now)?->toArray(),
            // M5-R06-04 cat wand play (the cat's daily play routine); null for a dog.
            'wand' => WandPayload::for($pet, $actor, $now)?->toArray(),
            // M5-R06-05 cat litter tray (uses, scoop deadlines, weekly change); null for a dog.
            'litter' => LitterPayload::for($pet, $actor, $now)?->toArray(),
            // M5-R06-05 grooming of a brushed cat (M5-R13: also a brushed dog — week, matted coat, session); null for every other pet.
            'grooming' => GroomingPayload::for($pet, $actor, $now)?->toArray(),
            // M5-R06-05 cat scratching (open mess, redirect + praise session); null for a dog.
            'scratching' => ScratchingPayload::for($pet, $actor, $now)?->toArray(),
            // M2-01d: this child's 12-week challenge end on this pet (null while it runs).
            'certificate' => $actor !== null ? app(ChallengeCertificateService::class)->childPayload($pet, $actor, $tz)?->toArray() : null,
            // M5-R17 the puppy's weekly "new experience" (socialisation); null when this week has no task.
            'socialisation' => SocialisationPayload::for($pet, $actor, $now)?->toArray(),
            // M5-R14 / R15 / R19 / R20 the child's breed care mini-games (teeth, folds, ears, nails); [] without one.
            'care_tasks' => CareTasksPayload::tasks($pet, $actor, $now),
            // M5-R14 summer midday heat warning before a walk (flat-faced breeds with a sourced rule); null otherwise.
            'walk_heat' => CareTasksPayload::walkHeat($pet, $now),
            // M5-T05 care guide ("Kako skrbim za …"): numbers + keys from the simulation's own rules, no prose.
            'care_guide' => CareGuidePayload::for($pet, $now, $profile)->toArray(),
            // M6-W01 »Želje«: open wishes for this pet (all children), the pet's durable items, treats (left / today / limit).
            /** @var array{open_count: int, items: list<array{key: 'bed'|'bowls'|'toy_1'|'toy_2', variant: string, media_ready: bool}>, treats: array{left: int, given_today: int, daily_limit: int}} */
            'wishes' => app(WishService::class)->stateBlock($pet, $actor, $now),
        ];
    }
}
