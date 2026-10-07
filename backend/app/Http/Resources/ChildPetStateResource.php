<?php

namespace App\Http\Resources;

use App\Enums\PetLockReason;
use App\Models\Pet;
use App\Models\User;
use App\Services\BehaviourPayload;
use App\Services\CareScheduleService;
use App\Services\Media\PetMediaPayload;
use App\Services\Media\PetMediaService;
use App\Services\PetPlanPayload;
use App\Services\PetProfilePayload;
use App\Services\TrainingPayload;
use App\Services\TrainingService;
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
 * limit, emergency meal — CareScheduleService::feedCheck / waterCheck), so
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
        $media = PetMediaPayload::for($pet);
        $profile = PetProfilePayload::for($pet, $now);
        $behaviour = BehaviourPayload::for($pet, $now);
        $hasOpenChewing = in_array('chewing', array_column($behaviour->activeEvents, 'kind'), true);
        $training = TrainingPayload::for($pet, $actor, $now);

        return [
            'pet' => [
                'id' => $pet->id,
                'breed_type' => $pet->breed_type->value,
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
                // M3-11: free | challenge, trial / payment_required / paid (null for free).
                'plan' => PetPlanPayload::for($pet, $tz, $now)->toArray(),
                // Legacy fields (pre-M4-05 builds): now our signed URLs, never fal URLs.
                'current_video_url' => $media->currentVideoUrl,
                'media_status' => $pet->media_status,
                'reference_image_url' => $media->referenceImageUrl,
                // AI media (M4-03 / M4-05): signed, expiring URLs (re-issued with every state).
                'media' => $media->toArray(),
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
            ],
            'water' => [
                'times_per_day' => $water->timesPerDay ?? 0,
                'min_gap_minutes' => $water->minGapMinutes ?? 0,
                'used_today' => $water->usedToday ?? 0,
                'remaining_today' => $water?->remainingToday() ?? 0,
                'last_watered_at' => $iso($water?->lastAt),
                'can_water' => ! $locked && (bool) $waterCheck?->allowed,
                // Earliest next refill by the water rules; null when allowed now.
                'next_allowed_at' => $iso($water?->nextAllowedAt),
            ],
            'steps' => [
                'steps_today' => (int) $pet->daily_step_count,
                'my_steps_today' => $actor !== null
                    ? $pet->stepsTodayOf($actor, $now)
                    : (int) $pet->daily_step_count,
                // Today's step goal of the dog's life stage (M5-R01).
                'goal' => $profile->stepGoal,
                'energy_level' => $pet->displayMetric('energy_level'),
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
                /** @var 'accident'|'chewing'|null */
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
                 * locked, no running session, enough of the dog's budget AND of this
                 * child's fair share left for one session, and the session would end
                 * before the family-local midnight).
                 *
                 * @var bool
                 */
                'can_start' => (bool) (! $locked && $training->enabled && $training->session === null
                    && $training->dailyBudgetLeftSeconds >= TrainingPayload::sessionSeconds()
                    && $training->mySecondsLeft >= TrainingPayload::sessionSeconds()
                    && ! app(TrainingService::class)->dayEndingAt($pet, $now)),
            ]),
        ];
    }
}
