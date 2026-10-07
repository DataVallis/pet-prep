<?php

namespace App\Services;

use App\Enums\BreedType;
use App\Enums\EntitlementRevokeReason;
use App\Events\PetUpdated;
use App\Models\BreedConfig;
use App\Models\Family;
use App\Models\FamilyEntitlement;
use App\Models\Pet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What a family may use (M3-08, ADR-012 family model).
 *
 * The single read point for paid features: `familyHas($family, 'challenge')`
 * and the breed gate `canUseBreed()` (a breed with
 * `breed_configs.premium_unlock` — today the Border Collie — needs the
 * family's active `challenge` entitlement; free breeds always pass). Every
 * parent of the family shares the entitlement, whoever bought it.
 *
 * Writes (`grant` / `revoke`) are called only by RevenueCatWebhookService,
 * inside its transaction, with the family row locked. When a write flips
 * whether an entitlement is active, `broadcastChanged()` queues one
 * `PetUpdated` (`entitlements_updated`) per active pet of the family after
 * commit, so open apps refetch GET /api/parent/entitlements.
 *
 * Not here yet (being confirmed with David, M3-11): the 7-day trial and
 * what exactly is free / paid beyond the premium breed.
 */
class EntitlementService
{
    public const CHALLENGE = 'challenge';

    public const EVENT_TYPE = 'entitlements_updated';

    /**
     * Entitlement keys we know: `challenge` + every value of the product map.
     *
     * @return list<string>
     */
    public function knownKeys(): array
    {
        $keys = [self::CHALLENGE];
        foreach ((array) config('services.revenuecat.entitlements', []) as $key) {
            if (is_string($key) && $key !== '' && ! in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    public function familyHas(?Family $family, string $key): bool
    {
        if ($family === null) {
            return false;
        }

        return FamilyEntitlement::where('family_id', $family->id)
            ->where('entitlement', $key)
            ->active()
            ->exists();
    }

    /**
     * Does this breed need a purchase? Breeds without a config are locked
     * too (fail closed), like before M3-08.
     */
    public function breedRequiresEntitlement(BreedType $breed): bool
    {
        return BreedConfig::forBreed($breed)?->premium_unlock !== false;
    }

    /**
     * Premium breed gate for new pets (generate-pin profile, pairing): free
     * breeds always; a premium breed with the family's active `challenge`.
     */
    public function canUseBreed(?Family $family, BreedType $breed): bool
    {
        if (BreedConfig::forBreed($breed) === null) {
            return false;
        }

        return ! $this->breedRequiresEntitlement($breed) || $this->familyHas($family, self::CHALLENGE);
    }

    /**
     * Read model for GET /api/parent/entitlements: one entry per known key
     * from the family's unrevoked row; no unrevoked row (never bought,
     * refunded, expired, transferred away) → inactive with nulls. A lapsed
     * subscription not yet revoked shows active false + its past expires_at.
     *
     * @return list<array{key: string, active: bool, source: string|null, store: string|null, granted_at: string|null, expires_at: string|null}>
     */
    public function forFamily(?Family $family): array
    {
        $rows = $family === null ? collect() : FamilyEntitlement::where('family_id', $family->id)
            ->unrevoked()
            ->get()
            ->keyBy('entitlement');

        $out = [];
        foreach ($this->knownKeys() as $key) {
            /** @var FamilyEntitlement|null $row */
            $row = $rows->get($key);
            $active = $row?->isActive() ?? false;
            $out[] = [
                'key' => $key,
                'active' => $active,
                'source' => $row?->source,
                'store' => $row?->store,
                'granted_at' => $row?->granted_at?->toIso8601String(),
                'expires_at' => $row?->expires_at?->toIso8601String(),
            ];
        }

        return $out;
    }

    /**
     * Grant or extend `$key` for the family. Caller holds the transaction
     * and the family row lock.
     *
     * Merge rule for an existing unrevoked row: lifetime (null expiry) wins;
     * otherwise the later expiry. A lifetime row keeps its product when a
     * subscription event arrives. `granted_at` restarts only when the row
     * was inactive (lapsed subscription bought again).
     *
     * @param  array{product_id: string|null, store: string|null, environment: string|null}  $attrs
     * @return array{created: bool, changed: bool} changed = the active flag flipped
     */
    public function grant(Family $family, string $key, array $attrs, string $eventId, ?Carbon $expiresAt, Carbon $grantedAt): array
    {
        $row = FamilyEntitlement::where('family_id', $family->id)
            ->where('entitlement', $key)
            ->unrevoked()
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            $row = FamilyEntitlement::create([
                'family_id' => $family->id,
                'entitlement' => $key,
                'source' => FamilyEntitlement::SOURCE_REVENUECAT,
                'product_id' => $attrs['product_id'],
                'store' => $attrs['store'],
                'environment' => $attrs['environment'],
                'granted_at' => $grantedAt,
                'expires_at' => $expiresAt,
                'last_event_id' => $eventId,
            ]);

            return ['created' => true, 'changed' => $row->isActive()];
        }

        $wasActive = $row->isActive();
        $lifetime = $row->expires_at === null;

        if (! $lifetime) {
            $row->expires_at = $expiresAt === null ? null : ($row->expires_at->gt($expiresAt) ? $row->expires_at : $expiresAt);
            $row->product_id = $attrs['product_id'];
            $row->store = $attrs['store'];
            $row->environment = $attrs['environment'];
        }
        if (! $wasActive) {
            $row->granted_at = $grantedAt;
        }
        $row->last_event_id = $eventId;
        $row->save();

        return ['created' => false, 'changed' => $wasActive !== $row->isActive()];
    }

    /**
     * End the family's unrevoked `$key` row. `$applies` (optional) decides
     * on the locked row whether this event still concerns it (product match,
     * out-of-order expiration). Caller holds the transaction + family lock.
     *
     * @param  (callable(FamilyEntitlement): bool)|null  $applies
     * @return array{revoked: FamilyEntitlement|null, changed: bool}
     */
    public function revoke(Family $family, string $key, EntitlementRevokeReason $reason, string $eventId, ?callable $applies = null): array
    {
        $row = FamilyEntitlement::where('family_id', $family->id)
            ->where('entitlement', $key)
            ->unrevoked()
            ->lockForUpdate()
            ->first();

        if ($row === null || ($applies !== null && ! $applies($row))) {
            return ['revoked' => null, 'changed' => false];
        }

        $wasActive = $row->isActive();
        $row->forceFill([
            'revoked_at' => now(),
            'revoke_reason' => $reason,
            'last_event_id' => $eventId,
        ])->save();

        return ['revoked' => $row, 'changed' => $wasActive];
    }

    /**
     * Unrevoked RevenueCat rows of a family (TRANSFER moves them).
     *
     * @return list<string>
     */
    public function unrevokedKeys(Family $family): array
    {
        return FamilyEntitlement::where('family_id', $family->id)
            ->where('source', FamilyEntitlement::SOURCE_REVENUECAT)
            ->unrevoked()
            ->orderBy('entitlement')
            ->pluck('entitlement')
            ->all();
    }

    /**
     * One PetUpdated (`entitlements_updated`) per active pet of the family,
     * after the surrounding transaction commits (private pet channels: the
     * family's parents + caretakers).
     */
    public function broadcastChanged(Family $family): void
    {
        Pet::where('family_id', $family->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->each(fn (Pet $pet) => PetUpdated::afterCommit($pet, self::EVENT_TYPE));
    }

    /**
     * Lock family rows in id order (deadlock-free for multi-family writes).
     *
     * @param  list<int>  $familyIds
     */
    public function lockFamilies(array $familyIds): void
    {
        $ids = array_values(array_unique($familyIds));
        sort($ids);
        if ($ids !== []) {
            DB::table('families')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
        }
    }
}
