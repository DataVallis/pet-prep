<?php

namespace App\Http\Requests;

use App\Models\Pet;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/child/pet/litter-change/finish and /grooming/finish
 * (M5-R06-05): the session id from start and the strokes the app saw —
 * whole milliseconds since the session start (`t`). Static bounds here;
 * offsets beyond the session's own length are refused by CatChoreService
 * (422 care_session_invalid_input).
 */
class FinishCareChoreRequest extends FormRequest
{
    /** Upper bound of a stroke offset (well above any session length). */
    public const MAX_STROKE_MS = 600000;

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
            // May be empty (the child did nothing → not counted).
            'strokes' => ['present', 'array', 'max:'.max(1, (int) config('cat_care.max_strokes', 600))],
            'strokes.*' => ['array:t'],
            'strokes.*.t' => ['required', 'integer', 'min:0', 'max:'.self::MAX_STROKE_MS],
        ];
    }

    public function sessionId(): string
    {
        return strtolower((string) $this->validated('session_id'));
    }

    /**
     * @return list<array{t: int}>
     */
    public function strokes(): array
    {
        return array_values(array_map(
            fn (array $s): array => ['t' => (int) $s['t']],
            (array) $this->validated('strokes', []),
        ));
    }
}
