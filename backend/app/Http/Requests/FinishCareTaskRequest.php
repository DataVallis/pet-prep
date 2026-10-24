<?php

namespace App\Http\Requests;

use App\Enums\CareTask;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * POST /api/child/pet/care-task/finish (M5-R14 / R15 / R19 / R20): the task,
 * the session id from start and the strokes / claw taps the app saw — whole
 * milliseconds since the session start (`t`). Offsets beyond the session's
 * own length are refused by CareTaskService (422 care_session_invalid_input).
 */
class FinishCareTaskRequest extends FinishCareChoreRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /** tooth_brushing | fold_cleaning | ear_cleaning | nail_trim */
            'task' => ['required', 'string', Rule::in(CareTask::childValues())],
            ...parent::rules(),
        ];
    }

    public function task(): CareTask
    {
        return CareTask::from((string) $this->validated('task'));
    }
}
