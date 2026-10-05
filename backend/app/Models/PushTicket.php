<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Expo's answer for one message to one device (M3-02). `status` / `error`
 * come from the push ticket, `receipt_*` from the receipt checked ≥ 15 min
 * later (`push:receipts`). Unique per (notification, device): a retried send
 * job skips devices that already have a ticket.
 *
 * @property int $id
 * @property int $push_notification_id
 * @property int $device_push_token_id
 * @property string|null $ticket_id
 * @property string $status
 * @property string|null $error
 * @property string|null $receipt_status
 * @property string|null $receipt_error
 * @property Carbon|null $receipt_checked_at
 * @property Carbon $created_at
 */
class PushTicket extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'push_notification_id',
        'device_push_token_id',
        'ticket_id',
        'status',
        'error',
        'receipt_status',
        'receipt_error',
        'receipt_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'receipt_checked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DevicePushToken, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(DevicePushToken::class, 'device_push_token_id');
    }
}
