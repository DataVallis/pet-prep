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
 *
 * Rules v2 (GAMES_SPEC §5.3, additive — a v1 session ignores them): per move
 * `in_zone` (the stroke ended inside the play zone) and `speed_ok` (peak speed
 * ≤ max_speed_pt_s and length ≥ min_dodge_pt of the schedule). Both are
 * reported by the app; in a v2 session a move without them never earns a star.
 */
class FinishWandRequest extends FormRequest
{
    /** Upper bound of a move offset (well above any session length). */
    public const MAX_MOVE_MS = 600000;

    /** The keys a move may carry (t, away — v1; in_zone, speed_ok — rules v2, optional). */
    public const MOVE_KEYS = ['t', 'away', 'in_zone', 'speed_ok'];

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
            // Only these keys (not `array:…` — Scramble would type every listed key as required,
            // and in_zone / speed_ok are optional on the wire: old apps never send them).
            'moves.*' => ['array', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_array($value) && array_diff(array_keys($value), self::MOVE_KEYS) !== []) {
                    $fail('The '.$attribute.' field must only contain '.implode(', ', self::MOVE_KEYS).'.');
                }
            }],
            'moves.*.t' => ['required', 'integer', 'min:0', 'max:'.self::MAX_MOVE_MS],
            'moves.*.away' => ['required', 'boolean'],
            'moves.*.in_zone' => ['sometimes', 'boolean'],
            'moves.*.speed_ok' => ['sometimes', 'boolean'],
        ];
    }

    public function sessionId(): string
    {
        return strtolower((string) $this->validated('session_id'));
    }

    /**
     * @return list<array{t: int, away: bool, in_zone?: bool, speed_ok?: bool}>
     */
    public function moves(): array
    {
        return array_values(array_map(function (array $m): array {
            $move = ['t' => (int) $m['t'], 'away' => filter_var($m['away'], FILTER_VALIDATE_BOOLEAN)];
            foreach (['in_zone', 'speed_ok'] as $key) {
                if (array_key_exists($key, $m)) {
                    $move[$key] = filter_var($m[$key], FILTER_VALIDATE_BOOLEAN);
                }
            }

            return $move;
        }, (array) $this->validated('moves', [])));
    }
}
