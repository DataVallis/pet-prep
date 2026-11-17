<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Stripe webhook ledger (M6-S09): one row per `event.id` — each event is processed
 * once. Ids, type and outcome only; no payload, no personal data.
 *
 * @property int $id
 * @property string $event_id
 * @property string $type
 * @property string|null $object_id
 * @property int|null $shop_order_id
 * @property bool $livemode
 * @property string|null $outcome
 * @property Carbon|null $processed_at
 */
class StripeEvent extends Model
{
    public const OUTCOME_FULFILLED = 'fulfilled';

    public const OUTCOME_PENDING = 'pending';

    public const OUTCOME_FAILED = 'failed';

    public const OUTCOME_REFUNDED = 'refunded';

    public const OUTCOME_PARTIAL_REFUND = 'partial_refund';

    public const OUTCOME_DISPUTED = 'disputed';

    public const OUTCOME_DISPUTE_LOST = 'dispute_lost';

    public const OUTCOME_DISPUTE_WON = 'dispute_won';

    public const OUTCOME_UNKNOWN_ORDER = 'unknown_order';

    public const OUTCOME_NOT_OURS = 'not_ours';

    public const OUTCOME_TEST_MODE_IGNORED = 'test_mode_ignored';

    public const OUTCOME_IGNORED = 'ignored';

    /** @var list<string> */
    protected $fillable = ['event_id', 'type', 'object_id', 'shop_order_id', 'livemode', 'outcome', 'processed_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'livemode' => 'boolean',
            'shop_order_id' => 'integer',
            'processed_at' => 'datetime',
        ];
    }
}
