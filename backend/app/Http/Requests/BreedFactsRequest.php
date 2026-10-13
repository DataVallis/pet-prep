<?php

namespace App\Http\Requests;

use App\Models\BreedFact;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/breeds/{breed}/facts and POST /api/breed-facts/{fact}/read
 * (M5-R09b). Parents and children (BreedFactPolicy::useFacts → 403 for
 * anyone else); which breed / fact the caller may see is decided in
 * BreedFactService (another family's breed or a hidden fact → 404).
 */
class BreedFactsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('useFacts', BreedFact::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
