<?php

namespace App\Http\Requests;

use App\Models\Pet;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * POST /api/child/pet/steps (M1-04 / M1-07): today's cumulative step count
 * from the phone. The service handles idempotency (max wins), anti-cheat and
 * stale days; `source` is validated but not stored (data minimisation).
 */
class SyncStepsRequest extends FormRequest
{
    public const SOURCES = ['healthkit', 'health_connect', 'pedometer'];

    /** Upper bound for one family-local day (well above any real walk). */
    public const MAX_STEPS_PER_DAY = 100000;

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
            'steps_today' => ['required', 'integer', 'min:0', 'max:'.self::MAX_STEPS_PER_DAY],
            'source' => ['required', 'string', 'in:'.implode(',', self::SOURCES)],
            // ISO 8601 with offset, e.g. 2026-10-04T15:30:00+02:00.
            'recorded_at' => ['required', 'string', 'date'],
        ];
    }

    public function recordedAt(): Carbon
    {
        return Carbon::parse((string) $this->validated('recorded_at'));
    }
}
