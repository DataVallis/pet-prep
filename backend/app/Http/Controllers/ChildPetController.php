<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesChildPet;
use App\Http\Requests\ChildPetRequest;
use App\Http\Requests\FinishTrainingRequest;
use App\Http\Requests\StartTrainingRequest;
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
     * Feed inside a breed feed window (family-local), once per window → hunger 100 %.
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
     * Step sync: today's cumulative count from HealthKit / Health Connect.
     * status: accepted | capped (anti-cheat kept part) | rejected | unchanged | stale.
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
