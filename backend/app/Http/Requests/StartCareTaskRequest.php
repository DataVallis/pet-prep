<?php

namespace App\Http\Requests;

use App\Enums\CareTask;
use App\Models\Pet;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/child/pet/care-task/start (M5-R14 / R15 / R19 / R20): which breed
 * care mini-game the child starts. Only children (PetPolicy); whether the pet
 * has that task is CareTaskService's (422 care_task_not_available).
 */
class StartCareTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('useChildApi', Pet::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /** tooth_brushing | fold_cleaning | ear_cleaning | nail_trim */
            'task' => ['required', 'string', Rule::in(CareTask::childValues())],
        ];
    }

    public function task(): CareTask
    {
        return CareTask::from((string) $this->validated('task'));
    }
}
