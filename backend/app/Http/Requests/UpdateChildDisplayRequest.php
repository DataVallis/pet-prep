<?php

namespace App\Http\Requests;

use App\Enums\MediaDisplay;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/child/settings (M5-T11, APP_REFINEMENT_SPEC §1). Body
 * `{"media_display": "video"|"picture"}` — the child's own »Prikaz: Video / Slika«.
 * Children only (route group `ability:child` + UserPolicy::updateOwnDisplay).
 * The display is the only setting a child may change here (adapted mode is the parent's).
 */
class UpdateChildDisplayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('updateOwnDisplay', $this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'media_display' => ['required', 'string', Rule::enum(MediaDisplay::class)],
        ];
    }

    public function mediaDisplay(): MediaDisplay
    {
        return MediaDisplay::from((string) $this->validated('media_display'));
    }
}
