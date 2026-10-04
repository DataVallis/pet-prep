<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/parent/hard-stop {pet_id?, active?} (M2-05 review).
 * `active` (bool) = the state the parent wants (idempotent set); without it
 * the endpoint keeps the deprecated toggle for old app builds.
 * Authorization (parent of the pet's family) happens in the controller.
 */
class HardStopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'pet_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'active' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    /** The requested state, or null for the legacy toggle. */
    public function desiredState(): ?bool
    {
        return $this->has('active') && $this->input('active') !== null
            ? $this->boolean('active')
            : null;
    }
}
