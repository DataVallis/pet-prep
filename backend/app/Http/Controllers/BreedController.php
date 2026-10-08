<?php

namespace App\Http\Controllers;

use App\Enums\Species;
use App\Http\Requests\BreedCatalogRequest;
use App\Http\Resources\BreedCatalogResource;
use App\Services\BreedCatalogService;
use App\Services\SpeciesAvailability;
use Illuminate\Http\JsonResponse;

/**
 * Breed picker catalogue (M5-R06-01, M5-R06_PLAN T3): the apps no longer
 * hard-code breeds or which of them are free / paid.
 */
class BreedController extends Controller
{
    public function __construct(
        private readonly BreedCatalogService $catalogue,
        private readonly SpeciesAvailability $availability,
    ) {}

    /**
     * The species and breeds this app build may choose for a new pet.
     *
     * `species` lists the available species (`dog` always; `cat` only while
     * the server flag `PETPREP_CATS_ENABLED` is on AND the query carries
     * `features[]=species_cat` — cats are hidden until M5-R06-09).
     * `breeds`: per species (dog first) the free breed first (mutt / domestic
     * cat), then the paid breeds by `sort_order`. Optional `species` filters
     * one species; an unavailable species returns an empty `breeds` list
     * (indistinguishable from a species without breeds).
     *
     * Free / paid = `breed_configs.premium_unlock` (the same rule
     * generate-pin applies: `plan: free` → `free_plan_allowed`, `plan:
     * challenge` → `challenge_allowed`, else 422 `breed_locked` /
     * `challenge_requires_paid_breed`).
     *
     * GET /api/breeds
     */
    public function index(BreedCatalogRequest $request): JsonResponse
    {
        $available = $this->availability->available($request->features());

        return response()->json([
            /** @var list<'dog'|'cat'> */
            'species' => array_map(fn (Species $s): string => $s->value, $available),
            'breeds' => BreedCatalogResource::collection($this->catalogue->catalogue($available, $request->species())),
        ]);
    }
}
