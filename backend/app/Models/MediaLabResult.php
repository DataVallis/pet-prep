<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One generated image or video in an AI Lab run. Videos are submitted to the
 * fal queue; the signed webhook (or the "check pending" poll) completes them
 * by request_id.
 */
class MediaLabResult extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'media_lab_run_id',
        'kind',
        'profile',
        'endpoint',
        'sample_index',
        'seed',
        'traits',
        'prompt',
        'negative_prompt',
        'params',
        'source_image_url',
        'request_id',
        'status_url',
        'response_url',
        'status',
        'error_reason',
        'error',
        'result_url',
        'latency_ms',
        'estimated_cost_usd',
        'started_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'traits' => 'array',
            'params' => 'array',
            'seed' => 'integer',
            'latency_ms' => 'integer',
            'estimated_cost_usd' => 'float',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(MediaLabRun::class, 'media_lab_run_id');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }
}
