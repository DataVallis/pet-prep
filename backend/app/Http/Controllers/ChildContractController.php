<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesChildPet;
use App\Http\Requests\SignContractRequest;
use App\Services\PetActivityService;
use Illuminate\Http\JsonResponse;

/**
 * Responsibility contract (PRODUCT_SPEC §3, M1-07).
 */
class ChildContractController extends Controller
{
    use HandlesChildPet;

    public function __construct(private readonly PetActivityService $activities) {}

    /**
     * Store the child's finger signature once per pet: 201 with the state,
     * 409 `contract_already_signed` for a second signature (the first stays).
     *
     * POST /api/child/contract
     */
    public function store(SignContractRequest $request): JsonResponse
    {
        $pet = $this->childPet($request);

        $result = $this->activities->signContract(
            $pet,
            $request->user(),
            (string) $request->validated('signature_format'),
            $request->normalizedSignature(),
        );

        return $this->actionResponse($result, $pet, $request, successStatus: 201);
    }
}
