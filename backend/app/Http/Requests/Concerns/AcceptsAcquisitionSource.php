<?php

namespace App\Http\Requests\Concerns;

use App\Enums\AcquisitionSource;
use App\Services\Validation\AcquisitionAnswer;
use Illuminate\Validation\Rule;

/**
 * The optional »Kje ste izvedeli za PetPrep?« fields (VALIDATION_PLAN §4.1, David 2026-11-21) of
 * POST /api/register, POST /api/auth/social and PATCH /api/parent/settings. An invalid answer never
 * fails the request: prepareAcquisitionInput() (call it from prepareForValidation) turns an unknown
 * source into null (logged at info) and trims / cuts the detail, so the rules below — there for the
 * OpenAPI types — always pass.
 */
trait AcceptsAcquisitionSource
{
    protected function prepareAcquisitionInput(): void
    {
        $merge = [];
        if ($this->exists('acquisition_source')) {
            $merge['acquisition_source'] = AcquisitionAnswer::normalizeSource($this->input('acquisition_source'));
        }
        if ($this->exists('acquisition_detail')) {
            $merge['acquisition_detail'] = AcquisitionAnswer::normalizeDetail($this->input('acquisition_detail'));
        }
        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function acquisitionRules(): array
    {
        return [
            // »Kje ste izvedeli za PetPrep?« — optional; an unknown value is stored as null (never a 422).
            'acquisition_source' => ['sometimes', 'nullable', 'string', Rule::in(AcquisitionSource::values())],
            // Free text / campaign code, kept only with `other` or `ad`; trimmed, at most 60 characters (longer is cut).
            'acquisition_detail' => ['sometimes', 'nullable', 'string', 'max:'.AcquisitionAnswer::MAX_DETAIL_LENGTH],
        ];
    }

    public function acquisitionAnswer(): ?AcquisitionAnswer
    {
        return AcquisitionAnswer::fromInput($this->input('acquisition_source'), $this->input('acquisition_detail'));
    }

    /** Did the body carry one of the two fields (even an invalid one)? */
    public function sentAcquisition(): bool
    {
        return $this->exists('acquisition_source') || $this->exists('acquisition_detail');
    }
}
