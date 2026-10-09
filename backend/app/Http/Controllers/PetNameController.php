<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePetNameRequest;
use App\Models\User;
use App\Services\FamilyDashboardService;
use App\Services\FamilyService;
use App\Services\PetNameService;
use Illuminate\Http\JsonResponse;

/**
 * Optional pet name (M5-R08, David 2026-10-09): a parent of the pet's family
 * sets, changes or clears it at any time. The name is only a label in the
 * apps (child HUD title, parent cards) — never used in push texts, server
 * sentences or AI prompts.
 */
class PetNameController extends Controller
{
    public function __construct(
        private readonly PetNameService $names,
        private readonly FamilyService $families,
        private readonly FamilyDashboardService $dashboard,
    ) {}

    /**
     * Set (`{"name": "Luna"}`) or clear (`{"name": null}` / `""`) the pet's
     * name. 200 `{pet_id, name}` — `name` as stored (trimmed, whitespace
     * collapsed, ’ → '). A change broadcasts one `PetUpdated` with
     * `event_type: pet_renamed`; the same name again changes nothing.
     * 422 `name_invalid` / `name_too_long` / `name_not_allowed` (body
     * `codes.name` + `reason`). Child token → 403. Another family's pet (or an
     * unknown id) → 404 `pet_not_found`.
     *
     * PATCH /api/parent/pets/{pet}/name
     */
    public function update(UpdatePetNameRequest $request, string $pet): JsonResponse
    {
        /** @var User $parent */
        $parent = $request->user();
        $family = $this->families->ensureFamilyFor($parent);
        $target = $this->dashboard->targetPet($family, $pet);

        if ($target === null || ! $parent->can('rename', $target)) {
            return response()->json(['message' => 'No such pet in your family.', 'reason' => 'pet_not_found'], 404);
        }

        $renamed = $this->names->rename($target, $request->petName());

        return response()->json([
            'pet_id' => $renamed->id,
            /**
             * The pet's name (M5-R08); null = no name (the apps show breed / species).
             *
             * @var string|null
             */
            'name' => $renamed->name,
        ]);
    }
}
