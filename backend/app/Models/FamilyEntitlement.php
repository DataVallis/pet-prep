<?php

namespace App\Models;

use App\Enums\EntitlementRevokeReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a family may use (M3-08), e.g. `challenge` — the paid 12-week
 * challenge (BUSINESS_MODEL §7). At most one unrevoked row per (family,
 * entitlement) (partial unique index). Active = not revoked and not past
 * `expires_at` (null = lifetime, the non-consumable). Written only by
 * EntitlementService.
 *
 * @property int $id
 * @property int $family_id
 * @property string $entitlement
 * @property string $source
 * @property string|null $product_id
 * @property string|null $store
 * @property string|null $environment
 * @property Carbon $granted_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property EntitlementRevokeReason|null $revoke_reason
 * @property string|null $last_event_id
 */
class FamilyEntitlement extends Model
{
    public const SOURCE_REVENUECAT = 'revenuecat';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'family_id', 'entitlement', 'source', 'product_id', 'store', 'environment',
        'granted_at', 'expires_at', 'revoked_at', 'revoke_reason', 'last_event_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'revoke_reason' => EntitlementRevokeReason::class,
        ];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /**
     * The one unrevoked row per (family, entitlement) — may be past expires_at.
     *
     * @param  Builder<FamilyEntitlement>  $query
     */
    public function scopeUnrevoked(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    /**
     * @param  Builder<FamilyEntitlement>  $query
     */
    public function scopeActive(Builder $query, ?Carbon $at = null): void
    {
        $at ??= now();
        $query->whereNull('revoked_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $at));
    }

    public function isActive(?Carbon $at = null): bool
    {
        $at ??= now();

        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->gt($at));
    }
}
