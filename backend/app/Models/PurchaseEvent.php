<?php

namespace App\Models;

use App\Enums\PurchaseEventOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One RevenueCat webhook event (M3-08 ledger), stored once per RevenueCat
 * `event.id`. Written only by RevenueCatWebhookService; read-only elsewhere.
 *
 * @property int $id
 * @property string $event_id
 * @property string $type
 * @property string|null $app_user_id
 * @property string|null $original_app_user_id
 * @property list<string>|null $aliases
 * @property string|null $product_id
 * @property list<string>|null $entitlement_ids
 * @property string|null $store
 * @property string|null $environment
 * @property string|null $transaction_id
 * @property string|null $original_transaction_id
 * @property Carbon|null $purchased_at
 * @property Carbon|null $expiration_at
 * @property Carbon|null $event_at
 * @property int|null $family_id
 * @property array<string, mixed> $payload
 * @property PurchaseEventOutcome|null $outcome
 * @property Carbon|null $processed_at
 */
class PurchaseEvent extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id', 'type', 'app_user_id', 'original_app_user_id', 'aliases', 'product_id', 'entitlement_ids',
        'store', 'environment', 'transaction_id', 'original_transaction_id', 'purchased_at', 'expiration_at',
        'event_at', 'family_id', 'payload', 'outcome', 'processed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'entitlement_ids' => 'array',
            'payload' => 'array',
            'purchased_at' => 'datetime',
            'expiration_at' => 'datetime',
            'event_at' => 'datetime',
            'processed_at' => 'datetime',
            'outcome' => PurchaseEventOutcome::class,
        ];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }
}
