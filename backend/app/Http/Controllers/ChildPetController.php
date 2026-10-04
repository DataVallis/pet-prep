<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesChildPet;
use App\Http\Requests\ChildPetRequest;
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

        return $this->actionResponse($this->activities->feed($pet), $pet, $request);
    }

    /**
     * Fresh water (limit per family-local day, minimum gap) → thirst 100 %.
     *
     * POST /api/child/pet/water
     */
    public function water(ChildPetRequest $request): JsonResponse
    {
        $pet = $this->childPet($request);

        return $this->actionResponse($this->activities->water($pet), $pet, $request);
    }

    /**
     * Cleaning mini-game finished → hygiene 100 %.
     *
     * POST /api/child/pet/clean
     */
    public function clean(ChildPetRequest $request): JsonResponse
    {
        $pet = $this->childPet($request);

        return $this->actionResponse($this->activities->clean($pet), $pet, $request);
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
        $result = $this->activities->recordSteps($pet, (int) $request->validated('steps_today'), $request->recordedAt());

        return $this->actionResponse($result, $pet, $request, [
            'accepted_steps' => $result->acceptedSteps,
            'steps_today' => $result->dailyStepCount,
            'energy_level' => $result->energyLevel,
        ]);
    }
}
