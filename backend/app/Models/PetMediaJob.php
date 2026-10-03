<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An asynchronous fal.ai generation request (currently: state videos).
 * Webhooks are only accepted for request IDs recorded here.
 */
class PetMediaJob extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const KIND_VIDEO = 'video';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'pet_id',
        'request_id',
        'kind',
        'pet_state',
        'status',
        'result_url',
        'error',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
