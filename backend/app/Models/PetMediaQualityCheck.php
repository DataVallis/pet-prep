<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One automatic quality check of one clip generation (David 2026-10-23,
 * MediaQualityService). Unique per (pet_media_id, generation): inserting the
 * row is the claim — a second check job of the same generation never runs the
 * checks (or pays for the vision call) again. Frames are of the AI pet only.
 *
 * @property int $id
 * @property int $pet_media_id
 * @property int $generation
 * @property string $trigger
 * @property string $status
 * @property array<string, mixed>|null $technical
 * @property bool|null $technical_ok
 * @property string $ai_status
 * @property array<string, mixed>|null $ai_verdict
 * @property float $ai_cost_usd
 * @property float|null $ai_reported_cost_usd
 * @property string|null $verdict
 * @property string|null $reason
 * @property string|null $sheet_path
 */
class PetMediaQualityCheck extends Model
{
    public const TRIGGER_PIPELINE = 'pipeline';

    public const TRIGGER_COMMAND = 'command';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DECIDED = 'decided';

    public const AI_PASSED = 'passed';

    public const AI_FAILED = 'failed';

    /** The production budget had no room (or the fal balance is out) — technical result decides. */
    public const AI_SKIPPED_BUDGET = 'skipped_budget';

    /** No enabled vision profile / fal disabled — technical result decides. */
    public const AI_DISABLED = 'disabled';

    /** The vision call failed or its answer was unusable — technical result decides. */
    public const AI_ERROR = 'error';

    /** QA #363 m2: the paid vision call is in flight (a stale takeover never calls again). */
    public const AI_CALLING = 'calling';

    /** Not run (technical fail, or a dry run). */
    public const AI_NOT_RUN = 'not_run';

    public const VERDICT_PASS = 'pass';

    public const VERDICT_FAIL = 'fail';

    protected $table = 'pet_media_quality_checks';

    /** @var list<string> */
    protected $fillable = [
        'pet_media_id', 'generation', 'trigger', 'status', 'technical', 'technical_ok',
        'ai_status', 'ai_verdict', 'ai_cost_usd', 'ai_reported_cost_usd', 'verdict', 'reason',
        'sheet_path', 'decided_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'generation' => 'integer',
            'technical' => 'array',
            'technical_ok' => 'boolean',
            'ai_verdict' => 'array',
            'ai_cost_usd' => 'float',
            'ai_reported_cost_usd' => 'float',
            'decided_at' => 'datetime',
        ];
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(PetMedia::class, 'pet_media_id');
    }
}
