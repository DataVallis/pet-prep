<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\CareRefusal;
use App\Http\Resources\ChildPetStateResource;
use App\Models\Pet;
use App\Models\User;
use App\Services\Results\ActionResult;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Shared pieces of the child API (M1-07): find the child's pet and map an
 * ActionResult to the HTTP contract.
 *
 *  - 200 accepted / unchanged / capped / rejected / stale
 *  - 422 refused by a game rule (reason + next_allowed_at)
 *  - 409 contract already signed
 *  - 423 locked (reason: game_over | inactive | hard_stopped |
 *    contract_required | ill; contract_required = sign the contract first,
 *    only POST /api/child/contract is allowed — M1-07b)
 *
 * Every body carries `state` (ChildPetStateResource) so the app can update
 * its cache without a second request.
 */
trait HandlesChildPet
{
    /**
     * The authenticated child's pet: the pet they care for (pet_caretakers,
     * M2-01) — active, else the latest one, e.g. after game over. 404
     * `no_pet` when the child has not been paired yet.
     */
    protected function childPet(Request $request): Pet
    {
        /** @var User $child */
        $child = $request->user();
        $pet = $child->currentPet();

        if ($pet === null) {
            throw new HttpResponseException(response()->json([
                'message' => 'No pet yet. Pair with a parent first.',
                'reason' => 'no_pet',
            ], 404));
        }

        Gate::authorize('act', $pet);

        return $pet;
    }

    /**
     * @param  array<string, mixed>  $extra  Additional top-level keys (e.g. accepted_steps).
     */
    protected function actionResponse(ActionResult $result, Pet $pet, Request $request, array $extra = [], int $successStatus = 200): JsonResponse
    {
        $tz = $pet->familyTimezone();
        $state = (new ChildPetStateResource($pet->refresh()))->toArray($request);

        if ($result->status === ActionResult::LOCKED) {
            return response()->json([
                'message' => 'The pet is locked right now.',
                'status' => $result->status,
                'reason' => $result->lockReason?->value,
                'locked_until' => $state['lock']['until'],
                'state' => $state,
            ], 423);
        }

        if ($result->status === ActionResult::REFUSED) {
            $conflict = $result->refusal === CareRefusal::ContractAlreadySigned;

            return response()->json([
                'message' => $this->refusalMessage($result->refusal),
                'status' => $result->status,
                'reason' => $result->refusal?->value,
                'next_allowed_at' => $result->nextAllowedAt?->copy()->setTimezone($tz)->toIso8601String(),
                'state' => $state,
            ], $conflict ? 409 : 422);
        }

        return response()->json(array_merge([
            'status' => $result->status,
        ], $extra, [
            'state' => $state,
        ]), $result->status === ActionResult::ACCEPTED ? $successStatus : 200);
    }

    private function refusalMessage(?CareRefusal $refusal): string
    {
        return match ($refusal) {
            CareRefusal::OutsideFeedWindow => 'Feeding is only possible inside a feeding window.',
            CareRefusal::AlreadyFedThisWindow => 'The pet was already fed in this feeding window.',
            CareRefusal::WaterDailyLimit => 'No more water refills today.',
            CareRefusal::WaterTooSoon => 'Too soon since the last water refill.',
            CareRefusal::NeedsCleaning => 'Clean up the mess first.',
            CareRefusal::ContractAlreadySigned => 'The contract is already signed.',
            default => 'Action not allowed right now.',
        };
    }
}
