<?php

namespace App\Models;

use App\Enums\PushType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One escalation push decision (M3-02): queued → sent | failed, or
 * suppressed (quiet hours, duplicate guard, nobody to reach, pet locked,
 * walk done), or scheduled → queued once `send_after` passes (illness / game
 * over held over quiet hours, the daily walk reminder held until its time). The row is the
 * duplicate guard and the send job's idempotency key; `recipients` holds
 * user ids + audience only (no names). Rows older than 30 days are deleted by `push:receipts`.
 *
 * @property int $id
 * @property string $idempotency_key
 * @property int $pet_id
 * @property PushType $type
 * @property string|null $metric
 * @property list<array{user_id: int, audience: string}> $recipients
 * @property string $status
 * @property string|null $suppressed_reason
 * @property int $attempts
 * @property Carbon|null $sent_at
 * @property Carbon|null $send_after
 * @property string|null $last_error
 * @property Carbon $created_at
 */
class PushNotification extends Model
{
    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_SUPPRESSED = 'suppressed';

    public const STATUS_FAILED = 'failed';

    public const AUDIENCE_CHILD = 'child';

    public const AUDIENCE_PARENT = 'parent';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'idempotency_key',
        'pet_id',
        'type',
        'metric',
        'recipients',
        'status',
        'suppressed_reason',
        'attempts',
        'sent_at',
        'send_after',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'type' => PushType::class,
            'recipients' => 'array',
            'sent_at' => 'datetime',
            'send_after' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Pet, $this>
     */
    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    /**
     * @return HasMany<PushTicket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(PushTicket::class);
    }
}
