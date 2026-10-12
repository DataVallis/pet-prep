<?php

namespace App\Http\Controllers;

use App\Enums\BreedType;
use App\Http\Requests\BreedFactsRequest;
use App\Http\Requests\ChildPetRequest;
use App\Models\BreedFact;
use App\Models\User;
use App\Services\BreedFactPayload;
use App\Services\BreedFactService;
use App\Services\FamilyService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * »Ali si vedel?« / breed facts (M5-R09b, DID_YOU_KNOW_SPEC §7). Additive
 * endpoints: only approved facts, child vs parent audience, only the breeds
 * of the caller's own pets (child) / family (parent). Facts change in the
 * admin without a new app build. Not gated by a ClientFeature — nothing about
 * a pet changes; an old app simply never calls them.
 */
class BreedFactController extends Controller
{
    public function __construct(
        private readonly BreedFactService $facts,
        private readonly FamilyService $families,
    ) {}

    /**
     * Facts of one breed for the caller (title / why in the request language).
     *
     * Child token: `audience=child` facts of the breed of a pet the child
     * actively cares for, without source links. Parent token: both audiences
     * for any breed of the family's pets, with `source_url`, health facts
     * flagged `not_vet_reviewed`, and `read_by_children`. A breed that is not
     * the caller's (or an unknown breed) → 404 `breed_not_found`. A breed of
     * the caller without approved facts (always mutt / domestic cat) → 200
     * with an empty `facts` list.
     *
     * GET /api/breeds/{breed}/facts
     */
    public function index(BreedFactsRequest $request, string $breed): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $type = BreedType::tryFrom($breed) ?? BreedType::fromSlug($breed);

        if ($type === null || ! $this->facts->mayReadBreed($user, $type)) {
            return response()->json(['message' => 'No such breed among your pets.', 'reason' => 'breed_not_found'], 404);
        }

        return response()->json(BreedFactPayload::list($this->facts->listFor($user, $type)));
    }

    /**
     * Mark a fact read by the caller. Idempotent (the first `read_at` stays).
     * A fact the caller may not see (not approved, other audience, another
     * family's breed, unknown id) → 404 `fact_not_found`.
     *
     * POST /api/breed-facts/{fact}/read
     */
    public function read(BreedFactsRequest $request, string $fact): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $row = ctype_digit($fact) ? BreedFact::find((int) $fact) : null;

        if ($row === null || ! $user->can('read', $row)) {
            return response()->json(['message' => 'No such fact.', 'reason' => 'fact_not_found'], 404);
        }

        $read = $this->facts->markRead($user, $row);

        return response()->json([
            // Always `read` (also on a repeated call).
            'status' => 'read',
            /** @var array{id: int, key: string, breed: string, category: 'origin'|'body'|'temperament'|'exercise'|'diet'|'grooming'|'training'|'family'|'health', audience: 'child'|'parent', title: string, why: string|null, source_name: string, source_url: string|null, game_rule_key: string|null, not_vet_reviewed: bool, read: bool, read_at: string|null, read_by_children: list<int>} */
            'fact' => $this->facts->row($row, $user, CarbonImmutable::instance($read->read_at)->utc()),
        ]);
    }

    /**
     * The child's »Ali si vedel?« for today: the next unread fact of their
     * active pet's breed + whether the sheet may open on its own
     * (`may_auto_show`, the server half of spec §3.2). A child without an
     * active pet gets `fact: null`, `reason: no_pet`.
     *
     * GET /api/child/fact-of-the-day
     */
    public function factOfTheDay(ChildPetRequest $request): JsonResponse
    {
        /** @var User $child */
        $child = $request->user();

        return response()->json(BreedFactPayload::factOfTheDay(
            $this->facts->factOfTheDay($child, $this->families->activePetOf($child)),
        ));
    }

    /**
     * Record that the sheet opened on its own (counts towards the 2 per
     * program week, across the child's devices). Idempotent per
     * family-local day: 200 `recorded` | `already_shown`. Not allowed now →
     * 422 `refused` with the `reason` of `may_auto_show`. Every body carries
     * the updated `state` (as GET).
     *
     * POST /api/child/fact-of-the-day/shown
     */
    public function shown(ChildPetRequest $request): JsonResponse
    {
        /** @var User $child */
        $child = $request->user();
        $pet = $this->families->activePetOf($child);

        $result = $pet !== null
            ? $this->facts->recordShown($child, $pet)
            : ['status' => 'refused', 'reason' => 'no_pet'];

        return response()->json([
            /** @var 'recorded'|'already_shown'|'refused' */
            'status' => $result['status'],
            /** @var 'no_pet'|'non_pedigree'|'locked'|'no_image'|'no_fact'|'first_day'|'quiet_hours'|'shown_today'|'week_cap'|'too_soon'|null */
            'reason' => $result['reason'],
            'state' => BreedFactPayload::factOfTheDay($this->facts->factOfTheDay($child, $pet)),
        ], $result['status'] === 'refused' ? 422 : 200);
    }
}
