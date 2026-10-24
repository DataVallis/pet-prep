<?php

namespace App\Http\Requests;

use App\Enums\CareTask;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/parent/pets/{pet}/care-reminders (M5-R18 / R20): the parent
 * confirms a reminder "done" for the current period. Parents only
 * (UserPolicy::viewBilling → 403 for a child); whether the pet belongs to the
 * parent's family is checked in the controller (PetPolicy::manage → 404).
 */
class ConfirmCareReminderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewBilling', User::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /** groomer_visit | bath */
            'task' => ['required', 'string', Rule::in(CareTask::parentValues())],
        ];
    }

    public function task(): CareTask
    {
        return CareTask::from((string) $this->validated('task'));
    }
}
