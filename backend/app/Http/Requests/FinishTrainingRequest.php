<?php

namespace App\Http\Requests;

use App\Models\Pet;
use App\Services\TrainingService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/child/pet/training/finish (M5-R03): the session id from start
 * and the child's "Pohvali" taps as whole milliseconds since the session
 * start (the app's own clock from the moment it started the session).
 * Static bounds here; offsets beyond the session's own length are refused
 * by TrainingService (422 training_invalid_taps).
 */
class FinishTrainingRequest extends FormRequest
{
    /** Upper bound of a tap offset (well above any session length). */
    public const MAX_TAP_MS = 600000;

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
            'session_id' => ['required', 'string', 'uuid'],
            // May be empty (the child never tapped).
            'taps' => ['present', 'array', 'max:'.TrainingService::MAX_TAPS],
            'taps.*' => ['integer', 'min:0', 'max:'.self::MAX_TAP_MS],
        ];
    }

    public function sessionId(): string
    {
        return strtolower((string) $this->validated('session_id'));
    }

    /**
     * @return list<int>
     */
    public function taps(): array
    {
        return array_values(array_map('intval', (array) $this->validated('taps', [])));
    }
}
