<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePetQuietHoursRequest;
use App\Models\Pet;
use App\Services\PetQuietHoursService;
use Illuminate\Http\JsonResponse;

/**
 * Quiet hours per pet (David 2026-11-16): school / sleep times per animal —
 * the daughter's cat, the son's dog and the parents' dogs each on their own
 * schedule. Default = the family's quiet hours.
 */
class PetQuietHoursController extends Controller
{
    public function __construct(private readonly PetQuietHoursService $quietHours) {}

    /**
     * Give the pet its own quiet hours (`{"quiet_hours": {…}}`) or make it
     * follow the family's again (`{"quiet_hours": null}`).
     *
     * PUT /api/parent/pets/{pet}/quiet-hours — decay ×0.1, sleep, no pushes,
     * no messes and parent-covered meals of THIS pet follow the new schedule
     * from now on (like a family change: the next tick, closed days never
     * recomputed); the family's quiet hours and the other pets stay. A later
     * change of the family's quiet hours never moves a pet with its own.
     * One `pet_quiet_hours_changed` broadcast when the value changed. Child
     * token or another family's pet → 403; unknown pet → 404.
     */
    public function update(UpdatePetQuietHoursRequest $request, Pet $pet): JsonResponse
    {
        $changed = $this->quietHours->change($request->user(), $pet, $request->petQuietHours());

        return response()->json([
            'pet_id' => $changed->id,
            /**
             * The pet's effective quiet hours (own, else the family's), "HH:MM" | null,
             * read on the pet's own clock (`timezone`).
             *
             * @var array{school_start: string|null, school_end: string|null, bedtime_start: string|null, bedtime_end: string|null, is_active: bool, follows_family: bool}
             */
            'quiet_hours' => PetQuietHoursService::payloadFor($changed),
            /**
             * True = the pet follows the family's quiet hours (`pets.own_quiet_hours` null).
             *
             * @var bool
             */
            'quiet_hours_follow_family' => ! $changed->hasOwnQuietHours(),
        ]);
    }
}
