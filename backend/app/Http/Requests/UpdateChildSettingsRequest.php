<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/parent/children/{child}/settings (M5-T12, David 2026-10-20). Body
 * `{"adapted_mode": true|false}` — »Prilagojen način«: the child's walk counts
 * wheelchair pushes instead of steps. Parents only (UserPolicy::manageFamily →
 * 403 for a child; the route group also needs the `parent` token ability);
 * whether the child belongs to the parent's family is checked in the
 * controller (another family's child looks exactly like a missing one, 404).
 *
 * Accessibility, not a diagnosis: only the switch is accepted and stored —
 * there is no field for a reason or a condition (minimum data about a minor).
 */
class UpdateChildSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manageFamily', User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'adapted_mode' => ['required', 'boolean'],
        ];
    }

    public function adaptedMode(): bool
    {
        return (bool) $this->validated('adapted_mode');
    }
}
