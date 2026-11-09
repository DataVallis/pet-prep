<?php

namespace App\Http\Controllers;

use App\Exceptions\TimezoneChangeTooSoonException;
use App\Http\Requests\UpdatePetTimezoneRequest;
use App\Models\Pet;
use App\Services\PetTimezoneService;
use Illuminate\Http\JsonResponse;

/**
 * Time zone per pet (David 2026-11-09): family members in different places
 * each read their own pet's wall clock.
 */
class PetTimezoneController extends Controller
{
    public function __construct(private readonly PetTimezoneService $zones) {}

    /**
     * Set the pet's own time zone (`{"timezone": "Europe/Lisbon"}`) or make
     * it follow the family zone again (`{"timezone": null}`).
     *
     * PUT /api/parent/pets/{pet}/timezone — meal windows, quiet hours, the
     * midnight and the step day of THIS pet move; the family zone and the
     * other pets stay. A change of the pet's effective zone is allowed at
     * most once per 24 h per pet → otherwise 422 `timezone_change_too_soon`
     * with `next_allowed_at`; pinning / resetting without moving the clock is
     * free. One `pet_timezone_changed` broadcast. Child token or another
     * family's pet → 403; unknown pet → 404.
     */
    public function update(UpdatePetTimezoneRequest $request, Pet $pet): JsonResponse
    {
        try {
            $changed = $this->zones->change($request->user(), $pet, $request->petTimezone());
        } catch (TimezoneChangeTooSoonException $e) {
            return response()->json($e->body(), 422);
        }

        return response()->json([
            'pet_id' => $changed->id,
            /**
             * The pet's effective IANA zone (own, else the family's).
             *
             * @var string
             */
            'timezone' => $changed->effectiveTimezone(),
            /**
             * True = the pet follows the family zone (`pets.timezone` null).
             *
             * @var bool
             */
            'timezone_follows_family' => ! $changed->hasOwnTimezone(),
        ]);
    }
}
