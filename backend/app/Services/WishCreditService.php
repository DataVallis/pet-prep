<?php

namespace App\Services;

use App\Enums\ChallengeCreditRevokeReason;
use App\Enums\WishStatus;
use App\Events\PetUpdated;
use App\Exceptions\WishException;
use App\Models\Family;
use App\Models\Pet;
use App\Models\PetItem;
use App\Models\PetWish;
use App\Models\PurchaseEvent;
use App\Models\ShopItem;
use App\Models\User;
use App\Models\WishCredit;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Wish credits (M6-W02, WISHES_API §4–5): one per store purchase of a wish
 * product (`services.revenuecat.wish_products`), owned by the buyer's family.
 * Unlike challenge credits the webhook never assigns one — the parent app
 * redeems it for a pet right after the purchase (buying from a wish assigns
 * it to that wish's pet — David 2026-10-27).
 *
 * Lock order: family row → pet row → credit / item / wish rows (webhook and redeem alike).
 *
 *  - create() (webhook, family row locked by the caller).
 *  - redeem() (POST /api/parent/pets/{pet}/items/redeem): the family's oldest
 *    unused credit of the product → the pet's items; the wish → bought, other
 *    open wishes of the pet for a covered key → cancelled. Idempotency-Key
 *    (store transaction id) stored on the credit: a repeat answers
 *    `already_assigned` without using another credit.
 *  - revokeForRefund() (webhook): the credit is revoked; its pet's items from
 *    that credit are revoked (treats: the pack's unused treats go). An open
 *    wish is never re-opened.
 *  - transferUnassigned() (webhook TRANSFER): only unassigned credits move.
 *
 * No refund on game over / pet deletion (S11): an assigned credit stays used
 * (pet_id null after a deletion).
 */
class WishCreditService
{
    public function __construct(
        private readonly WishService $wishes,
    ) {}

    /**
     * @param  array{product_id: string, item_key: string, store: string|null, environment: string|null, transaction_id: string|null}  $attrs
     */
    public function create(Family $family, PurchaseEvent $event, array $attrs, CarbonInterface $purchasedAt): WishCredit
    {
        return WishCredit::create([
            'family_id' => $family->id,
            'purchase_event_id' => $event->id,
            'product_id' => $attrs['product_id'],
            'item_key' => $attrs['item_key'],
            'store' => $attrs['store'],
            'environment' => $attrs['environment'],
            'transaction_id' => $attrs['transaction_id'],
            'purchased_at' => $purchasedAt,
        ]);
    }

    /**
     * @return array{status: 'assigned'|'already_assigned', pet: Pet, credit: WishCredit}
     *
     * @throws WishException unknown_product / pet_not_active / wish_mismatch / already_owned / bundle_not_eligible (422), no_credit (409)
     */
    public function redeem(Pet $pet, User $parent, string $productId, ?int $wishId, ?string $idempotencyKey): array
    {
        $itemKey = WishService::keyForProduct($productId);
        $item = $itemKey !== null ? ShopItem::find($itemKey) : null;
        if ($item === null) {
            throw new WishException('unknown_product', 'This is not a wish product.');
        }

        return DB::transaction(function () use ($pet, $parent, $productId, $item, $wishId, $idempotencyKey): array {
            Family::whereKey($pet->family_id)->lockForUpdate()->first(['id']);
            $locked = Pet::whereKey($pet->id)->lockForUpdate()->firstOrFail();

            if ($idempotencyKey !== null) {
                $done = WishCredit::where('family_id', $locked->family_id)->where('redeem_key', $idempotencyKey)->first();
                if ($done !== null) {
                    return ['status' => 'already_assigned', 'pet' => $locked, 'credit' => $done];
                }
            }

            if ($this->wishes->blockedReason($locked) !== null) {
                throw new WishException('pet_not_active', 'This pet cannot receive items now.');
            }

            $covers = $item->covers;
            $wish = null;
            if ($wishId !== null) {
                $wish = PetWish::whereKey($wishId)->where('pet_id', $locked->id)->where('status', WishStatus::Open->value)->lockForUpdate()->first();
                if ($wish === null || ! in_array($wish->item_key, $covers, true)) {
                    throw new WishException('wish_mismatch', 'The wish does not match this pet and product.');
                }
            }

            if ($item->isBundle()) {
                if (! $this->wishes->bundleEligible($locked, $item)) {
                    throw new WishException('bundle_not_eligible', 'The pet already has one of the bundle items.');
                }
            } elseif ($item->isDurable() && $this->wishes->ownedItems($locked)->has($item->key)) {
                throw new WishException('already_owned', 'The pet already has this item.');
            }

            $credit = WishCredit::where('family_id', $locked->family_id)
                ->where('product_id', $productId)
                ->available()
                ->orderBy('purchased_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();
            if ($credit === null) {
                throw new WishException('no_credit', 'The purchase has not reached the server yet.', 409);
            }

            $now = now()->startOfSecond();
            $credit->forceFill([
                'pet_id' => $locked->id,
                'assigned_at' => $now,
                'assigned_via' => WishCredit::VIA_PARENT,
                'redeem_key' => $idempotencyKey,
            ])->save();

            $catalogue = ShopItem::catalogue();
            $species = $locked->speciesValue();
            foreach ($covers as $key) {
                $part = $catalogue->get($key);
                if ($part === null) {
                    continue;
                }
                $quantity = $part->isDurable() ? 1 : (int) ($part->pack_size ?? 1);
                PetItem::create([
                    'pet_id' => $locked->id,
                    'item_key' => $part->key,
                    'kind' => $part->isDurable() ? ShopItem::KIND_DURABLE : ShopItem::KIND_CONSUMABLE,
                    'variant' => (string) $part->variantFor($species),
                    'wish_credit_id' => $credit->id,
                    'quantity_total' => $quantity,
                    'quantity_left' => $quantity,
                    'acquired_at' => $now,
                ]);
            }

            if ($wish !== null) {
                $wish->forceFill(['status' => WishStatus::Bought, 'decided_by_user_id' => $parent->id, 'decided_at' => $now])->save();
            }
            PetWish::where('pet_id', $locked->id)->where('status', WishStatus::Open->value)->whereIn('item_key', $covers)
                ->update(['status' => WishStatus::Cancelled->value, 'decided_by_user_id' => $parent->id, 'decided_at' => $now, 'updated_at' => $now]);

            PetUpdated::afterCommit($locked, 'item_acquired');

            return ['status' => 'assigned', 'pet' => $locked, 'credit' => $credit];
        });
    }

    /**
     * Revoke the wish credit of a refunded purchase (caller: webhook, family row
     * locked). Match: the purchase's transaction id; without one, the family's
     * newest unrevoked credit of the product, unassigned first.
     */
    public function revokeForRefund(Family $family, ?string $productId, ?string $transactionId, string $eventId, CarbonInterface $now): ?WishCredit
    {
        $query = WishCredit::where('family_id', $family->id)->unrevoked();
        if ($transactionId !== null) {
            $query->where('transaction_id', $transactionId);
        } else {
            $query->when($productId !== null, fn ($q) => $q->where('product_id', $productId))
                ->orderByRaw('assigned_at IS NOT NULL')
                ->orderByDesc('purchased_at')
                ->orderByDesc('id');
        }
        // Peek first, then lock in the family → pet → credit order.
        $peek = $query->first();
        if ($peek === null) {
            return null;
        }
        $pet = $peek->pet_id !== null ? Pet::whereKey($peek->pet_id)->lockForUpdate()->first() : null;
        $credit = WishCredit::whereKey($peek->id)->whereNull('revoked_at')->lockForUpdate()->first();
        if ($credit === null) {
            return null;
        }

        $credit->forceFill([
            'revoked_at' => $now,
            'revoke_reason' => ChallengeCreditRevokeReason::Refund,
            'revoke_event_id' => $eventId,
        ])->save();

        $removed = PetItem::where('wish_credit_id', $credit->id)->whereNull('revoked_at')
            ->update(['revoked_at' => $now, 'quantity_left' => 0, 'updated_at' => $now]);
        if ($pet !== null && $removed > 0) {
            PetUpdated::afterCommit($pet, 'item_revoked');
        }

        return $credit;
    }

    /**
     * TRANSFER: move every available wish credit of $from to $to (both family
     * rows locked by the caller). Assigned credits stay with their pet.
     */
    public function transferUnassigned(Family $from, Family $to, CarbonInterface $now): int
    {
        return WishCredit::where('family_id', $from->id)->available()->update([
            'family_id' => $to->id,
            'transferred_from_family_id' => $from->id,
            'transferred_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
