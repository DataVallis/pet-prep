<?php

namespace App\Http\Controllers;

use App\Exceptions\WishException;
use App\Http\Controllers\Concerns\HandlesChildPet;
use App\Http\Requests\ChildPetRequest;
use App\Http\Requests\CreateWishRequest;
use App\Http\Requests\ParentWishesRequest;
use App\Http\Requests\RedeemItemRequest;
use App\Http\Resources\ChildPetStateResource;
use App\Models\Pet;
use App\Models\PetWish;
use App\Models\User;
use App\Services\FamilyDashboardService;
use App\Services\FamilyService;
use App\Services\WishCreditService;
use App\Services\WishService;
use Illuminate\Http\JsonResponse;

/**
 * »Želje« (M6-W01 / W02, docs/engineering/WISHES_API.md): the child's wish
 * catalogue and treats, the parent's wish list, decline and redeem. Rules in
 * WishService / WishCreditService.
 */
class WishController extends Controller
{
    use HandlesChildPet;

    public function __construct(
        private readonly WishService $wishes,
        private readonly WishCreditService $credits,
        private readonly FamilyService $families,
        private readonly FamilyDashboardService $dashboard,
    ) {}

    /**
     * The child's wish catalogue for their pet: every item (not the bundle)
     * with `status` available | requested | declined_recently | owned (treats
     * carry `treats_left`), `open_count` / `open_limit` (3, all pets of the
     * child), `can_wish` (+ `blocked_reason` game_over | payment_required).
     * 404 `no_pet`.
     *
     * GET /api/child/wishes
     */
    public function childIndex(ChildPetRequest $request): JsonResponse
    {
        $pet = $this->childPet($request);

        return response()->json($this->wishes->childCatalogue($pet, $request->user()));
    }

    /**
     * Wish for an item (push to the parents, max one per child per 10 min).
     * 422 wish_limit_reached | already_requested | already_owned |
     * declined_recently (+ retry_at) | treats_still_left | unknown_item |
     * bundle_not_wishable; 423 game_over | payment_required.
     *
     * POST /api/child/wishes
     */
    public function store(CreateWishRequest $request): JsonResponse
    {
        $pet = $this->childPet($request);
        /** @var User $child */
        $child = $request->user();

        try {
            $wish = $this->wishes->create($pet, $child, $request->itemKey());
        } catch (WishException $e) {
            return response()->json($e->body(), $e->status);
        }

        return response()->json([
            'wish' => [
                'id' => $wish->id,
                'item_key' => $wish->item_key,
                /** @var 'open' */
                'status' => $wish->status->value,
                'created_at' => $wish->created_at->copy()->setTimezone($pet->familyTimezone())->toIso8601String(),
            ],
            /** @var int */
            'open_count' => $this->wishes->openCountOfChild($child),
        ], 201);
    }

    /**
     * Give the pet one treat (max 2 per family-local day; looks only — never a
     * need, score or certificate). 200 {treats_left, given_today, daily_limit,
     * media: null until M6-W03}; 422 no_treats | treat_limit_today (+ next_at =
     * the next local midnight) | pet_sleeping (+ next_at = wake time); 423 as
     * every child action. Every body carries `state`.
     *
     * POST /api/child/pet/treat
     */
    public function treat(ChildPetRequest $request): JsonResponse
    {
        $pet = $this->childPet($request);
        $result = $this->wishes->giveTreat($pet, $request->user());
        $tz = $pet->familyTimezone();
        $state = (new ChildPetStateResource($pet->refresh()))->toArray($request);
        $counts = [
            /** @var int */
            'treats_left' => $result['treats_left'],
            /** @var int */
            'given_today' => $result['given_today'],
            'daily_limit' => WishService::TREATS_PER_DAY,
        ];

        if ($result['status'] === 'locked') {
            return response()->json([
                'message' => 'The pet is locked right now.',
                'status' => 'locked',
                'reason' => $result['lock']->value,
                'locked_until' => $state['lock']['until'],
                'state' => $state,
            ], 423);
        }
        if ($result['status'] === 'refused') {
            return response()->json(array_merge([
                'message' => match ($result['reason']) {
                    'no_treats' => 'No treats left.',
                    'treat_limit_today' => 'Enough treats for today.',
                    default => 'The pet is sleeping.',
                },
                'status' => 'refused',
                'reason' => $result['reason'],
                'next_at' => $result['next_at']?->copy()->setTimezone($tz)->toIso8601String(),
            ], $counts, ['state' => $state]), 422);
        }

        return response()->json(array_merge(['status' => 'accepted'], $counts, [
            // M6-W03 adds the treat clip; until then always null (the app shows the picture). When set it
            // will use the existing PetMedia shape (PetMediaPayload: signed `video_url` / `url`), never a fal URL.
            'media' => null,
            'state' => $state,
        ]));
    }

    /**
     * The family's open wishes (newest first), the pets that can receive items
     * (with their items and `bundle_eligible`) and purchases not yet on a pet
     * (`unassigned_credits`).
     *
     * GET /api/parent/wishes
     */
    public function parentIndex(ParentWishesRequest $request): JsonResponse
    {
        /** @var User $parent */
        $parent = $request->user();

        return response()->json($this->wishes->parentList($this->families->familyOf($parent)));
    }

    /**
     * »Ne zdaj«: the child may ask for the same item again after 7 days.
     * 404 another family's / unknown wish; 422 wish_not_open.
     *
     * POST /api/parent/wishes/{wish}/decline
     */
    public function decline(ParentWishesRequest $request, string $wish): JsonResponse
    {
        /** @var User $parent */
        $parent = $request->user();
        $family = $this->families->familyOf($parent);
        $row = $family !== null && ctype_digit($wish)
            ? PetWish::whereKey((int) $wish)->whereIn('pet_id', Pet::where('family_id', $family->id)->select('id'))->first()
            : null;
        if ($row === null) {
            return response()->json(['message' => 'No such wish in your family.', 'reason' => 'wish_not_found'], 404);
        }

        try {
            $declined = $this->wishes->decline($row, $parent);
        } catch (WishException $e) {
            return response()->json($e->body(), $e->status);
        }

        return response()->json([
            'wish' => [
                'id' => $declined->id,
                /** @var 'declined' */
                'status' => $declined->status->value,
            ],
            /** @var int */
            'open_count' => $this->wishes->openCountOfFamily($family),
        ]);
    }

    /**
     * Put a bought item on the pet right after the store purchase: the
     * family's oldest unused wish credit of `product_id`. Optional `wish_id`
     * (an open wish of this pet the product covers) → bought; other open
     * wishes of the pet for a covered key → cancelled. Header
     * `Idempotency-Key` (store transaction id): a repeat → `already_assigned`.
     * 409 no_credit (retry); 422 already_owned | bundle_not_eligible |
     * unknown_product | pet_not_active | wish_mismatch; 404 pet_not_found.
     *
     * POST /api/parent/pets/{pet}/items/redeem
     */
    public function redeem(RedeemItemRequest $request, string $pet): JsonResponse
    {
        /** @var User $parent */
        $parent = $request->user();
        $family = $this->families->familyOf($parent);
        $target = $family !== null ? $this->dashboard->targetPet($family, $pet) : null;
        if ($target === null || ! $parent->can('manage', $target)) {
            return response()->json(['message' => 'No such pet in your family.', 'reason' => 'pet_not_found'], 404);
        }

        try {
            $result = $this->credits->redeem($target, $parent, $request->productId(), $request->wishId(), $request->idempotencyKey());
        } catch (WishException $e) {
            return response()->json($e->body(), $e->status);
        }

        return response()->json([
            /** @var 'assigned'|'already_assigned' */
            'status' => $result['status'],
            'items' => $this->wishes->parentItems($result['pet']),
            /** @var int */
            'open_count' => $this->wishes->openCountOfFamily($family),
        ]);
    }
}
