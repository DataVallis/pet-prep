<?php

namespace App\Http\Requests;

use App\Enums\DashboardOpenTrigger;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/parent/dashboard-opened (H6, VALIDATION_PLAN §4.1). An unknown `trigger` is stored as
 * null — a measurement call never fails over it.
 */
class DashboardOpenedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageFamily', User::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $trigger = $this->input('trigger');
        if ($this->exists('trigger') && (! is_string($trigger) || DashboardOpenTrigger::tryFrom($trigger) === null)) {
            $this->merge(['trigger' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // What brought the dashboard into focus; optional (unknown → null).
            'trigger' => ['sometimes', 'nullable', 'string', Rule::in(DashboardOpenTrigger::values())],
        ];
    }

    public function trigger(): ?DashboardOpenTrigger
    {
        $value = $this->validated('trigger');

        return is_string($value) ? DashboardOpenTrigger::tryFrom($value) : null;
    }
}
