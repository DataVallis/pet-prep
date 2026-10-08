<?php

namespace App\Http\Requests;

use App\Models\Pet;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/child/pet/wand/finish (M5-R06-04): the session id from start and
 * the feather moves the app saw — whole milliseconds since the session
 * start (`t`) and whether the feather moved AWAY from the cat (`away`,
 * C11: "like prey"). Static bounds here; offsets beyond the session's own
 * length are refused by WandPlayService (422 wand_invalid_moves).
 */
class FinishWandRequest extends FormRequest
{
    /** Upper bound of a move offset (well above any session length). */
    public const MAX_MOVE_MS = 600000;

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
            // May be empty (the child never moved the feather → not counted).
            'moves' => ['present', 'array', 'max:'.max(1, (int) config('wand.max_moves', 600))],
            'moves.*' => ['array:t,away'],
            'moves.*.t' => ['required', 'integer', 'min:0', 'max:'.self::MAX_MOVE_MS],
            'moves.*.away' => ['required', 'boolean'],
        ];
    }

    public function sessionId(): string
    {
        return strtolower((string) $this->validated('session_id'));
    }

    /**
     * @return list<array{t: int, away: bool}>
     */
    public function moves(): array
    {
        return array_values(array_map(
            fn (array $m): array => ['t' => (int) $m['t'], 'away' => filter_var($m['away'], FILTER_VALIDATE_BOOLEAN)],
            (array) $this->validated('moves', []),
        ));
    }
}
