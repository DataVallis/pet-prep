<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The e-mail queue (M6-S09, WEBSITE_SALES_SPEC §3.6, David 2026-11-17): every e-mail
 * PetPrep sends is first stored here with its full HTML, then sent by `emails:send`
 * (Mail\OutboundEmailSender) through the SMTP server. Failures retry on
 * config('shop.email.retry_minutes'); after the last one the row is `failed` and an
 * admin alert goes out. Attachments are descriptions (rebuilt at send time), never files.
 *
 * @property int $id
 * @property string $kind gift_voucher | promise_kit
 * @property int|null $shop_order_id
 * @property string $to_address
 * @property string|null $to_name
 * @property string $subject
 * @property string $html
 * @property string|null $text
 * @property list<array<string, mixed>>|null $attachments
 * @property string $locale
 * @property string $status pending | sending | sent | failed | cancelled
 * @property int $attempts
 * @property Carbon|null $next_attempt_at
 * @property string|null $last_error
 * @property Carbon|null $sent_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $alerted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class OutboundEmail extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_SENDING, self::STATUS_SENT, self::STATUS_FAILED, self::STATUS_CANCELLED];

    /** @var list<string> */
    protected $fillable = [
        'kind', 'shop_order_id', 'to_address', 'to_name', 'subject', 'html', 'text', 'attachments', 'locale',
        'status', 'attempts', 'next_attempt_at', 'last_error', 'sent_at', 'failed_at', 'alerted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shop_order_id' => 'integer',
            'attachments' => 'array',
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'alerted_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ShopOrder::class, 'shop_order_id');
    }
}
