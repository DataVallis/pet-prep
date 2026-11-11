<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One Lemon Squeezy webhook delivery (M6-S01 ledger, like purchase_events).
 * LS sends no event id, so `idempotency_key` = sha256(event_name|data.type|data.id|updated_at)
 * (raw-body hash when updated_at is missing). `payload` is a whitelist of ids, status,
 * amounts and dates — never the key, buyer or customer fields. Written only by
 * LemonSqueezyWebhookService.
 *
 * @property int $id
 * @property string $idempotency_key
 * @property string $event_name
 * @property string|null $object_type
 * @property int|null $object_id
 * @property int|null $ls_order_id
 * @property int|null $store_id
 * @property bool $test_mode
 * @property array<string, mixed> $payload
 * @property string|null $outcome
 * @property Carbon|null $processed_at
 */
class LemonSqueezyEvent extends Model
{
    protected $table = 'lemonsqueezy_events';

    public const OUTCOME_RECORDED = 'recorded';

    public const OUTCOME_VOUCHER_CREATED = 'voucher_created';

    public const OUTCOME_VOUCHER_UPDATED = 'voucher_updated';

    public const OUTCOME_REFUNDED = 'refunded';

    public const OUTCOME_REVOKED = 'revoked';

    public const OUTCOME_DISABLED = 'disabled';

    public const OUTCOME_EXPIRED = 'expired';

    public const OUTCOME_PARTIAL_REFUND = 'partial_refund';

    public const OUTCOME_IGNORED = 'ignored';

    public const OUTCOME_FOREIGN_STORE = 'foreign_store';

    public const OUTCOME_UNKNOWN_PRODUCT = 'unknown_product';

    public const OUTCOME_TEST_MODE_IGNORED = 'test_mode_ignored';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'idempotency_key', 'event_name', 'object_type', 'object_id', 'ls_order_id', 'store_id', 'test_mode',
        'payload', 'outcome', 'processed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'object_id' => 'integer',
            'ls_order_id' => 'integer',
            'store_id' => 'integer',
            'test_mode' => 'boolean',
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
