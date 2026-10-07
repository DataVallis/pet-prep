<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesChildPet;
use App\Http\Requests\ChildPetRequest;
use App\Http\Requests\ParentPetGrowthRequest;
use App\Models\User;
use App\Services\FamilyDashboardService;
use App\Services\FamilyService;
use App\Services\Media\PetGrowthService;
use Illuminate\Http\JsonResponse;

/**
 * Growth album (M5-R04 part 2): the pet's reference images across life
 * stages, oldest first, with signed expiring URLs. A dedicated read instead
 * of a field in the `media` object: that object rides on every PetUpdated
 * broadcast and every state poll, while the album changes at most a few times
 * in a pet's life and costs a history query + one file check per picture.
 *
 * Who may read: the pet's caretaker children (PetPolicy::act, like
 * GET /api/child/pet) and every parent of the pet's family (PetPolicy::manage).
 */
class PetGrowthController extends Controller
{
    use HandlesChildPet;

    public function __construct(
        private readonly PetGrowthService $growth,
        private readonly FamilyService $families,
        private readonly FamilyDashboardService $dashboard,
    ) {}

    /**
     * The child's pet. 404 `no_pet` before pairing.
     *
     * GET /api/child/pet/growth
     */
    public function child(ChildPetRequest $request): JsonResponse
    {
        $pet = $this->childPet($request);

        return response()->json($this->growth->albumFor($pet)->toArray());
    }

    /**
     * A pet of the parent's family. Another family's pet (or an unknown id)
     * → 404 `pet_not_found`.
     *
     * GET /api/parent/pets/{pet}/growth
     */
    public function parent(ParentPetGrowthRequest $request, string $pet): JsonResponse
    {
        /** @var User $parent */
        $parent = $request->user();
        $family = $this->families->familyOf($parent); // GET: lookup only
        $target = $family !== null ? $this->dashboard->targetPet($family, $pet) : null;

        if ($target === null || ! $parent->can('manage', $target)) {
            return response()->json(['message' => 'No such pet in your family.', 'reason' => 'pet_not_found'], 404);
        }

        return response()->json($this->growth->albumFor($target)->toArray());
    }
}
