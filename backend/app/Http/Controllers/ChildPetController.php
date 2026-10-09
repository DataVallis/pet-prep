<?php

namespace App\Http\Controllers;

use App\Enums\CareSessionKind;
use App\Http\Controllers\Concerns\HandlesChildPet;
use App\Http\Requests\ChildPetRequest;
use App\Http\Requests\FinishCareChoreRequest;
use App\Http\Requests\FinishScratchingRequest;
use App\Http\Requests\FinishTrainingRequest;
use App\Http\Requests\FinishWandRequest;
use App\Http\Requests\PlayRequest;
use App\Http\Requests\StartTrainingRequest;
use App\Http\Requests\StartWandRequest;
use App\Http\Requests\SyncStepsRequest;
use App\Http\Resources\ChildPetStateResource;
use App\Services\PetActivityService;
use Illuminate\Http\JsonResponse;

/**
 * Child API (M1-07): pet state and care actions. Rules live in
 * PetActivityService / CareScheduleService; this only maps HTTP.
 */
class ChildPetController extends Controller
{
    use HandlesChildPet;

    public function __construct(private readonly PetActivityService $activities) {}

    /**
     * Full pet state: displayed metrics, lock, feed windows, water, steps, contract.
     *
     * GET /api/child/pet
     */
    public function show(ChildPetRequest $request): JsonResponse
    {
        $pet = $this->childPet($request);

        return response()->json((new ChildPetStateResource($pet))->toArray($request));
    }

    /**
     * Feed inside a breed feed window (family-local), once per window → hunger 100 %;
     * an emergency meal outside a window while hunger shows ≤ 20 % (M3-12, body
     * `feed_mode`: window | emergency).
     *
     * POST /api/child/pet/feed
     */
    public function feed(ChildPetRequest $request): JsonResponse
    {
        $pet = $this->childPet($request);

        return $this->actionResponse($this->activities->feed($pet, $request->user()), $pet, $request);
    }

    /**
     * Fresh water (limit per family-local day, minimum gap) → thirst 100 %.
     *
     * POST /api/child/pet/water
     */
    public function water(ChildPetRequest $request): JsonResponse
    {
        $pet = $this->childPet($request);

        return $this->actionResponse($this->activities->water($pet, $request->user()), $pet, $request);
    }

    /**
     * Cleaning mini-game finished → every open poop / puppy accident cleaned,
     * hygiene 100 % (stays 0 while a chewing event is open, M5-R02).
     *
     * POST /api/child/pet/clean
     */
    public function clean(ChildPetRequest $request): JsonResponse
    {
        $pet = $this->childPet($request);

        return $this->actionResponse($this->activities->clean($pet, $request->user()), $pet, $request);
    }

    /**
     * "Pelji ven" (M5-R02): take the puppy out — its bladder clock restarts.
     * 422 take_out_not_needed when the pet is not a (non-legacy) puppy;
     * a repeat within a minute is `unchanged`.
     *
     * POST /api/child/pet/take-out
     */
    public function takeOut(ChildPetRequest $request): JsonResponse
    {
        $pet = $this->childPet($request);

        return $this->actionResponse($this->activities->takeOut($pet, $request->user()), $pet, $request);
    }

    /**
     * "Pospravi in daj igračo" (M5-R02): tidy up what the dog chewed and give
     * it a toy — resolves every open chewing event (`unchanged` when none).
     *
     * POST /api/child/pet/resolve-chewing
     */
    public function resolveChewing(ChildPetRequest $request): JsonResponse
    {
        $pet = $this->childPet($request);

        return $this->actionResponse($this->activities->resolveChewing($pet, $request->user()), $pet, $request);
    }

    /**
     * Training (M5-R03): start a reward-timing session for one command. The
     * response carries `session` — the server's schedule (cues, whether and
     * when the dog obeys, the praise window; offsets in ms since start).
     * 422 training_not_available | training_session_active (next_allowed_at
     * = its expiry) | training_day_ending (session + TTL would cross the
     * family-local midnight; next_allowed_at = midnight) |
     * training_daily_budget_used (next_allowed_at = local midnight) |
     * training_child_share_used (this child's fair share — budget / children
     * who can train — is used; next_allowed_at = local midnight); 423 while locked.
     *
     * POST /api/child/pet/training/start
     */
    public function startTraining(StartTrainingRequest $request): JsonResponse
    {
        $pet = $this->childPet($request);

        return $this->actionResponse($this->activities->startTraining($pet, $request->user(), $request->command()), $pet, $request);
    }

    /**
     * Training (M5-R03): finish the session with the "Pohvali" tap offsets
     * (ms since start). The server scores them against its schedule and
     * returns `result` (per-cue outcome, progress before / after). A repeat
     * of a completed finish → `unchanged` with the same result.
     * 422 training_session_invalid | training_session_expired |
     * training_session_not_over | training_invalid_taps | training_session_interrupted |
     * training_not_available.
     *
     * POST /api/child/pet/training/finish
     */
    public function finishTraining(FinishTrainingRequest $request): JsonResponse
    {
        $pet = $this->childPet($request);

        return $this->actionResponse($this->activities->finishTraining($pet, $request->user(), $request->sessionId(), $request->taps()), $pet, $request);
    }

    /**
     * Play & cuddle (M5-R05): the child finished a ball game (`play`) or a
     * cuddle (`cuddle`) — free or from the dog's invitation. Mood / video
     * only: the dog is happy for 30 minutes (`state.play.mood`); no score,
     * routine or metric changes. The response carries `play` {kind, source:
     * invitation | free}. A repeat by the same child and kind within 10 s →
     * `unchanged`. 422 play_not_available (no play for this pet — free mutt,
     * legacy pet, the ball game for a cat — M5-R06-04 —, quiet hours:
     * next_allowed_at = their end, or a mess to clean first); 423 while
     * locked (incl. contract_required, payment_required).
     *
     * POST /api/child/pet/play
     */
    public function play(PlayRequest $request): JsonResponse
    {
        $pet = $this->childPet($request);

        return $this->actionResponse($this->activities->play($pet, $request->user(), $request->kind()), $pet, $request);
    }

    /**
     * Cat wand play (M5-R06-04, CAT_SPEC §5.2): start the ~60 s "Palica s
     * peresom" game. The response carries `session` — the server's schedule
     * (length, the cat's pounces, `catch_at_ms` = the catch at the end, what
     * the finish is checked against). The same child's unfinished game is
     * replaced (no penalty). 422 wand_not_available (a dog, no play data) |
     * needs_cleaning | wand_too_soon (next_allowed_at = end of the 2 h gap
     * after the last successful session) | wand_session_active (another
     * child's game; next_allowed_at = its expiry) | wand_day_ending
     * (next_allowed_at = local midnight); 423 while locked.
     *
     * POST /api/child/pet/wand/start
     */
    public function startWand(StartWandRequest $request): JsonResponse
    {
        $pet = $this->childPet($request);

        return $this->actionResponse($this->activities->startWand($pet, $request->user()), $pet, $request);
    }

    /**
     * Cat wand play (M5-R06-04): finish the game with the feather moves the
     * app saw (`t` ms since start, `away` = moved away from the cat). The
     * server judges whether the child took part and returns `result`
     * {success, reason: too_few_moves | not_spread | wrong_technique | null,
     * counts}. `accepted` = it counts (play meter, routine, the 2 h gap);
     * `rejected` = it does not (no penalty — start again at once); a repeat
     * → `unchanged` with the same result. 422 wand_not_available |
     * wand_session_invalid | wand_session_expired | wand_session_not_over |
     * wand_invalid_moves | wand_session_interrupted; 423 while locked.
     *
     * POST /api/child/pet/wand/finish
     */
    public function finishWand(FinishWandRequest $request): JsonResponse
    {
        $pet = $this->childPet($request);

        return $this->actionResponse($this->activities->finishWand($pet, $request->user(), $request->sessionId(), $request->moves()), $pet, $request);
    }

    /**
     * Cat litter (M5-R06-05, CAT_SPEC Q3): scoop the tray — every open
     * litter use is scooped now (`scooped` = how many). Scooped before its
     * deadline (4 h outside quiet hours; 2 h while the weekly change is
     * overdue) the use's `litter_scoop` routine is done. Nothing to scoop →
     * `unchanged`. 422 litter_not_available (not a cat with litter rules);
     * 423 while locked.
     *
     * POST /api/child/pet/litter/scoop
     */
    public function scoopLitter(ChildPetRequest $request): JsonResponse
    {
        $pet = $this->childPet($request);

        return $this->actionResponse($this->activities->scoopLitter($pet, $request->user()), $pet, $request);
    }

    /**
     * Cat litter (M5-R06-05): start the weekly full change (dump, wash,
     * refill — a short stroke mini-game). `session` = the server's schedule.
     * 422 litter_not_available | needs_cleaning | litter_change_done
     * (next_allowed_at = the next program week) | litter_change_session_active;
     * 423 while locked.
     *
     * POST /api/child/pet/litter-change/start
     */
    public function startLitterChange(ChildPetRequest $request): JsonResponse
    {
        $pet = $this->childPet($request);

        return $this->actionResponse($this->activities->startChore($pet, $request->user(), CareSessionKind::LitterChange), $pet, $request);
    }

    /**
     * Cat litter (M5-R06-05): finish the weekly change with the strokes the
     * app saw (`t` ms since start). `accepted` = the week's change is done
     * (and every open litter use scooped); `rejected` = not enough (no
     * penalty, start again). `result` = the verdict. 422
     * litter_not_available | care_session_invalid | care_session_expired |
     * care_session_not_over | care_session_invalid_input |
     * care_session_interrupted; 423 while locked.
     *
     * POST /api/child/pet/litter-change/finish
     */
    public function finishLitterChange(FinishCareChoreRequest $request): JsonResponse
    {
        $pet = $this->childPet($request);

        return $this->actionResponse($this->activities->finishChore($pet, $request->user(), CareSessionKind::LitterChange, $request->sessionId(), $request->strokes()), $pet, $request);
    }

    /**
     * Maine Coon grooming (M5-R06-05, CAT_SPEC Q8): start a combing session
     * (~30 s; ~60 s while the coat is matted — `session.matted`). 422
     * grooming_not_available | needs_cleaning | grooming_week_done |
     * grooming_done_today | grooming_session_active | grooming_quiet_hours
     * (next_allowed_at where the refusal ends); 423 while locked.
     *
     * POST /api/child/pet/grooming/start
     */
    public function startGrooming(ChildPetRequest $request): JsonResponse
    {
        $pet = $this->childPet($request);

        return $this->actionResponse($this->activities->startChore($pet, $request->user(), CareSessionKind::Grooming), $pet, $request);
    }

    /**
     * Maine Coon grooming (M5-R06-05): finish with the comb strokes.
     * `accepted` = one of the week's groomings (and a matted coat resolved);
     * `rejected` = not enough (no penalty). 422 grooming_not_available |
     * care_session_*; 423 while locked.
     *
     * POST /api/child/pet/grooming/finish
     */
    public function finishGrooming(FinishCareChoreRequest $request): JsonResponse
    {
        $pet = $this->childPet($request);

        return $this->actionResponse($this->activities->finishChore($pet, $request->user(), CareSessionKind::Grooming, $request->sessionId(), $request->strokes()), $pet, $request);
    }

    /**
     * Cat scratching (M5-R06-05, CAT_SPEC Q10): "Odnesi na praskalnik" —
     * the child carries the cat to the scratcher. `session.land_at_ms` =
     * when it lands; praise within `praise_window_ms` (3 s) after that.
     * 422 scratching_not_needed | scratching_session_active; 423 while locked.
     *
     * POST /api/child/pet/scratching/start
     */
    public function startScratching(ChildPetRequest $request): JsonResponse
    {
        $pet = $this->childPet($request);

        return $this->actionResponse($this->activities->startScratching($pet, $request->user()), $pet, $request);
    }

    /**
     * Cat scratching (M5-R06-05): "… in pohvali" — `praise_ms` since the
     * start (null = no praise). `accepted` = in time: the scratching is
     * resolved; `rejected` = too early / too late / no praise (never a
     * punishment — try again). 422 care_session_*; 423 while locked.
     *
     * POST /api/child/pet/scratching/finish
     */
    public function finishScratching(FinishScratchingRequest $request): JsonResponse
    {
        $pet = $this->childPet($request);

        return $this->actionResponse($this->activities->finishScratching($pet, $request->user(), $request->sessionId(), $request->praiseMs()), $pet, $request);
    }

    /**
     * Step sync: today's cumulative count from HealthKit / Health Connect.
     * status: accepted | capped (anti-cheat kept part) | rejected | unchanged | stale.
     * 422 steps_not_applicable for a cat (M5-R06-04: steps are dog-only).
     *
     * POST /api/child/pet/steps
     */
    public function steps(SyncStepsRequest $request): JsonResponse
    {
        $pet = $this->childPet($request);
        $result = $this->activities->recordSteps($pet, (int) $request->validated('steps_today'), $request->recordedAt(), $request->user());

        return $this->actionResponse($result, $pet, $request, [
            'accepted_steps' => $result->acceptedSteps,
            'steps_today' => $result->dailyStepCount,
            'energy_level' => $result->energyLevel,
        ]);
    }
}
