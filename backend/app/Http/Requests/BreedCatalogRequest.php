<?php

namespace App\Http\Requests;

use App\Enums\ClientFeature;
use App\Enums\Species;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/breeds (M5-R06-01). Parents only (UserPolicy::manageFamily → 403):
 * the picker runs before the child PIN.
 */
class BreedCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageFamily', User::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Only this species' breeds; omitted = every available species.
            'species' => ['sometimes', 'nullable', Rule::enum(Species::class)],
            // The app build's client features, same values as generate-pin
            // `features` (query: features[]=species_cat). Unknown values are ignored.
            'features' => ['sometimes', 'nullable', 'array', 'max:10'],
            'features.*' => ['string', 'max:64'],
        ];
    }

    public function species(): ?Species
    {
        $species = $this->validated('species');

        return $species === null ? null : Species::from((string) $species);
    }

    /**
     * @return list<string>
     */
    public function features(): array
    {
        return ClientFeature::known($this->validated('features') ?? []);
    }
}
