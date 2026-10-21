<?php

namespace App\Http\Requests;

use App\Enums\StepUnit;
use App\Models\Pet;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * POST /api/child/pet/steps (M1-04 / M1-07): today's cumulative step count
 * from the phone. The service handles idempotency (max wins), anti-cheat and
 * stale days; `source` is validated but not stored (data minimisation).
 *
 * `unit` (M5-T12, optional, default `steps`): `pushes` when the phone counted
 * wheelchair pushes (the child's adapted mode, 1 push = 1 step). The number is
 * treated exactly like steps (goal, anti-cheat, energy); the unit only names
 * the day in the parent report (`pet_daily_steps.unit`).
 */
class SyncStepsRequest extends FormRequest
{
    public const SOURCES = ['healthkit', 'health_connect', 'pedometer'];

    /** Upper bound for one family-local day (well above any real walk). */
    public const MAX_STEPS_PER_DAY = 100000;

    /** YYYY-MM-DDTHH:MM:SS, optional .fraction (1–6 digits), then Z or ±HH:MM. */
    public const RECORDED_AT_REGEX = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/';

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
            // M5-T12: what was counted (older apps send nothing → steps).
            'unit' => ['sometimes', 'string', 'in:'.implode(',', array_column(StepUnit::cases(), 'value'))],
            // Strict ISO 8601 with an explicit offset: the server must not guess
            // the device's zone. Accepts 2026-10-04T15:30:00+02:00, …Z and
            // fractional seconds (…15:30:00.123Z); rejects "now", "yesterday",
            // date-only and offset-less strings.
            'recorded_at' => ['required', 'string', 'regex:'.self::RECORDED_AT_REGEX, 'date'],
        ];
    }

    public function unit(): StepUnit
    {
        return StepUnit::from((string) ($this->validated('unit') ?? StepUnit::Steps->value));
    }

    public function recordedAt(): Carbon
    {
        return Carbon::parse((string) $this->validated('recorded_at'));
    }
}
