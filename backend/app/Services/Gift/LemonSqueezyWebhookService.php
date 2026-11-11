<?php

namespace App\Services\Gift;

use App\Jobs\SendGiftVoucherAlert;
use App\Models\GiftVoucher;
use App\Models\LemonSqueezyEvent;
use App\Services\ChallengeCreditService;
use App\Services\LemonSqueezy\LicenseResponse;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Lemon Squeezy webhook → `lemonsqueezy_events` ledger → gift vouchers
 * (M6-S01, CONTRACT §4 / §5.2). Pattern of RevenueCatWebhookService: an
 * exists-check on the idempotency key, then ONE transaction (ledger insert,
 * processing, outcome); a concurrent duplicate is caught as a unique violation.
 *
 * Idempotency (L9, LS sends no event id): sha256(event_name|data.type|data.id|updated_at),
 * the raw body's sha256 when updated_at is missing.
 *
 * Checked BEFORE anything else (L15): store id, gift product id (and variant when
 * set and present), test mode. Outcomes: order_created → recorded;
 * license_key_created → voucher_created; license_key_updated → disabled | expired |
 * voucher_updated; order_refunded (full) → refunded | revoked | recorded, partial →
 * partial_refund (+ admin alert, nothing revoked); other events → ignored.
 * Lock order voucher → family → credit → pet (ChallengeCreditService::revokeGiftCredit).
 * Runs whatever the »Gift vouchers« switch says (refunds are never lost).
 *
 * Never stores or logs the license key, buyer / customer fields or custom data.
 */
class LemonSqueezyWebhookService
{
    public const HANDLED = ['order_created', 'order_refunded', 'license_key_created', 'license_key_updated'];

    /** Payload keys kept in the ledger (everything else is dropped). */
    private const WHITELIST = [
        'store_id', 'order_id', 'order_item_id', 'product_id', 'variant_id', 'status', 'refunded', 'refunded_amount',
        'total', 'currency', 'activation_limit', 'instances_count', 'disabled', 'expires_at', 'created_at', 'updated_at', 'test_mode',
    ];

    /** @var list<array{0: string, 1: int|null, 2: int|null}> alerts to send after the commit */
    private array $alerts = [];

    public function __construct(
        private readonly ChallengeCreditService $credits,
        private readonly GiftVoucherRows $rows,
    ) {}

    /**
     * @return string|null the outcome, or null for a duplicate delivery
     */
    public function handle(string $rawBody): ?string
    {
        $body = json_decode($rawBody, true);
        $eventName = is_array($body) && is_array($body['meta'] ?? null) && is_string($body['meta']['event_name'] ?? null)
            ? $body['meta']['event_name'] : null;
        if ($eventName === null || $eventName === '') {
            Log::warning('LemonSqueezyWebhook: body without meta.event_name — not stored');

            return LemonSqueezyEvent::OUTCOME_IGNORED;
        }

        /** @var array<string, mixed> $body */
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $attrs = is_array($data['attributes'] ?? null) ? $data['attributes'] : [];
        $key = self::idempotencyKey($eventName, $data, $attrs, $rawBody);

        if (LemonSqueezyEvent::where('idempotency_key', $key)->exists()) {
            return null;
        }

        $this->alerts = [];
        try {
            $outcome = DB::transaction(function () use ($key, $eventName, $body, $data, $attrs): string {
                $type = is_string($data['type'] ?? null) ? $data['type'] : null;
                $objectId = LicenseResponse::int($data['id'] ?? null);
                $record = LemonSqueezyEvent::create([
                    'idempotency_key' => $key,
                    'event_name' => $eventName,
                    'object_type' => $type,
                    'object_id' => $objectId,
                    'ls_order_id' => $type === 'orders' ? $objectId : LicenseResponse::int($attrs['order_id'] ?? null),
                    'store_id' => LicenseResponse::int($attrs['store_id'] ?? null),
                    'test_mode' => self::testMode($body, $attrs),
                    'payload' => self::payload($eventName, $type, $objectId, $attrs),
                ]);

                $outcome = $this->process($record, $eventName, $type, $objectId, $attrs);

                $record->forceFill(['outcome' => $outcome, 'processed_at' => now()])->save();
                Log::info('LemonSqueezyWebhook: event processed', [
                    'event_id' => $record->id,
                    'event_name' => $eventName,
                    'ls_order_id' => $record->ls_order_id,
                    'outcome' => $outcome,
                ]);

                return $outcome;
            });
        } catch (UniqueConstraintViolationException $e) {
            // The same delivery twice at once: the other request won.
            if (LemonSqueezyEvent::where('idempotency_key', $key)->exists()) {
                return null;
            }

            throw $e;
        }

        foreach ($this->alerts as [$reason, $voucherId, $orderId]) {
            SendGiftVoucherAlert::dispatch($reason, $voucherId, $orderId);
        }
        $this->alerts = [];

        return $outcome;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $attrs
     */
    public static function idempotencyKey(string $eventName, array $data, array $attrs, string $rawBody): string
    {
        $updatedAt = $attrs['updated_at'] ?? null;
        if (! is_string($updatedAt) || $updatedAt === '') {
            return hash('sha256', $rawBody);
        }

        return hash('sha256', $eventName.'|'.(string) ($data['type'] ?? '').'|'.(string) ($data['id'] ?? '').'|'.$updatedAt);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function process(LemonSqueezyEvent $record, string $eventName, ?string $type, ?int $objectId, array $attrs): string
    {
        if (! in_array($eventName, self::HANDLED, true)) {
            return LemonSqueezyEvent::OUTCOME_IGNORED;
        }
        if ($record->store_id === null || $record->store_id !== LicenseResponse::int((string) config('services.lemonsqueezy.store_id'))) {
            return LemonSqueezyEvent::OUTCOME_FOREIGN_STORE;
        }
        if (! $this->isGiftProduct($type, $attrs) && ! $this->orderHasVoucher($type, $objectId)) {
            return LemonSqueezyEvent::OUTCOME_UNKNOWN_PRODUCT;
        }
        if ($record->test_mode && ! config('services.lemonsqueezy.accept_test_mode')) {
            return LemonSqueezyEvent::OUTCOME_TEST_MODE_IGNORED;
        }

        return match ($eventName) {
            'order_created' => LemonSqueezyEvent::OUTCOME_RECORDED,
            'order_refunded' => $this->orderRefunded($record, $objectId, $attrs),
            'license_key_created' => $this->licenseKey($record, $objectId, $attrs, created: true),
            default => $this->licenseKey($record, $objectId, $attrs, created: false),
        };
    }

    /**
     * Is this event about the gift product? A license key carries its product; an order
     * (m7, QA) counts when ANY of its items is the gift — `first_order_item` and, when LS
     * sends them, `order_items` (a cart with another product first is still a gift order).
     *
     * @param  array<string, mixed>  $attrs
     */
    private function isGiftProduct(?string $type, array $attrs): bool
    {
        if ($type !== 'orders') {
            return self::isGiftItem($attrs);
        }
        $items = [];
        if (is_array($attrs['first_order_item'] ?? null)) {
            $items[] = $attrs['first_order_item'];
        }
        foreach ((array) ($attrs['order_items'] ?? []) as $item) {
            if (is_array($item)) {
                $items[] = is_array($item['attributes'] ?? null) ? $item['attributes'] : $item;
            }
        }

        return collect($items)->contains(fn (array $item): bool => self::isGiftItem($item));
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function isGiftItem(array $item): bool
    {
        $product = LicenseResponse::int($item['product_id'] ?? null);
        if ($product === null || $product !== LicenseResponse::int((string) config('services.lemonsqueezy.gift_product_id'))) {
            return false;
        }
        // The variant only where LS sends it (orders; license keys carry none — L12).
        $wanted = LicenseResponse::int((string) config('services.lemonsqueezy.gift_variant_id'));
        $variant = LicenseResponse::int($item['variant_id'] ?? null);

        return $wanted === null || $variant === null || $variant === $wanted;
    }

    /** m7 (QA): an order of our store that already has a gift voucher row is a gift order. */
    private function orderHasVoucher(?string $type, ?int $orderId): bool
    {
        return $type === 'orders' && $orderId !== null && GiftVoucher::where('ls_order_id', $orderId)->exists();
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function licenseKey(LemonSqueezyEvent $record, ?int $licenseKeyId, array $attrs, bool $created): string
    {
        $key = is_string($attrs['key'] ?? null) ? GiftVoucher::canonical($attrs['key']) : '';
        if ($licenseKeyId === null || $key === '' || $record->ls_order_id === null) {
            Log::warning('LemonSqueezyWebhook: license key event without id / key / order', ['event_id' => $record->id]);

            return LemonSqueezyEvent::OUTCOME_RECORDED;
        }
        $purchasedAt = LicenseResponse::date($attrs['created_at'] ?? null) ?? now();
        $voucher = $this->rows->upsert($key, $licenseKeyId, $record->ls_order_id, $record->test_mode, $purchasedAt);
        $voucher = GiftVoucher::whereKey($voucher->id)->lockForUpdate()->firstOrFail();
        $this->fillMissing($voucher, $record, $attrs);
        if ($created) {
            return LemonSqueezyEvent::OUTCOME_VOUCHER_CREATED;
        }

        if (($attrs['status'] ?? null) === 'disabled' || ($attrs['disabled'] ?? false) === true) {
            $changed = $this->terminate($voucher, GiftVoucher::STATUS_DISABLED, $record);

            return $changed === null ? LemonSqueezyEvent::OUTCOME_RECORDED : LemonSqueezyEvent::OUTCOME_DISABLED;
        }
        if (($attrs['status'] ?? null) === 'expired' && $voucher->status === GiftVoucher::STATUS_PURCHASED) {
            $this->rows->move($voucher, [GiftVoucher::STATUS_PURCHASED], GiftVoucher::STATUS_EXPIRED);

            return LemonSqueezyEvent::OUTCOME_EXPIRED;
        }

        return LemonSqueezyEvent::OUTCOME_VOUCHER_UPDATED;
    }

    /**
     * m3 (QA, CONTRACT §2.2): the webhook completes an existing row — the order id when it
     * is missing, the purchase date from the license key's own created_at while the voucher
     * is not redeemed yet, and a full refund already in the ledger (it arrived before the
     * key row). Never a downgrade: refunded / disabled stay; status is never set back to
     * purchased. Caller holds the voucher row lock.
     *
     * @param  array<string, mixed>  $attrs
     */
    private function fillMissing(GiftVoucher $voucher, LemonSqueezyEvent $record, array $attrs): void
    {
        $fill = [];
        if (($voucher->ls_order_id === null || $voucher->ls_order_id === 0) && $record->ls_order_id !== null) {
            $fill['ls_order_id'] = $record->ls_order_id;
        }
        $created = LicenseResponse::date($attrs['created_at'] ?? null)?->startOfSecond();
        if ($created !== null && in_array($voucher->status, [GiftVoucher::STATUS_PURCHASED, GiftVoucher::STATUS_EXPIRED], true)
            && ! $voucher->purchased_at->equalTo($created)) {
            $fill['purchased_at'] = $created;
            $fill['expires_at'] = GiftVoucher::expiryFor($created);
        }
        if ($fill !== []) {
            $voucher->forceFill($fill)->save();
        }

        $orderId = $voucher->ls_order_id;
        if (in_array($voucher->status, [GiftVoucher::STATUS_PURCHASED, GiftVoucher::STATUS_EXPIRED], true)
            && LemonSqueezyEvent::where('event_name', 'order_refunded')->where('ls_order_id', $orderId)
                ->whereIn('outcome', [LemonSqueezyEvent::OUTCOME_REFUNDED, LemonSqueezyEvent::OUTCOME_REVOKED])->exists()) {
            $voucher->forceFill(['status' => GiftVoucher::STATUS_REFUNDED, 'refunded_at' => now()])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function orderRefunded(LemonSqueezyEvent $record, ?int $orderId, array $attrs): string
    {
        if (($attrs['refunded'] ?? null) !== true) {
            // Partial refund (L11): recorded, nothing revoked, an admin decides.
            $this->alerts[] = [SendGiftVoucherAlert::REASON_PARTIAL_REFUND, null, $orderId];

            return LemonSqueezyEvent::OUTCOME_PARTIAL_REFUND;
        }
        if ($orderId === null) {
            return LemonSqueezyEvent::OUTCOME_RECORDED;
        }

        $vouchers = GiftVoucher::where('ls_order_id', $orderId)->orderBy('id')->lockForUpdate()->get();
        if ($vouchers->isEmpty()) {
            // No key row yet: the redeem path applies this event later (CONTRACT §2.2).
            return LemonSqueezyEvent::OUTCOME_REFUNDED;
        }

        $results = $vouchers->map(fn (GiftVoucher $v): ?string => $this->terminate($v, GiftVoucher::STATUS_REFUNDED, $record));
        if ($results->contains('revoked')) {
            return LemonSqueezyEvent::OUTCOME_REVOKED;
        }

        return $results->contains('changed') ? LemonSqueezyEvent::OUTCOME_REFUNDED : LemonSqueezyEvent::OUTCOME_RECORDED;
    }

    /**
     * A full refund or a disabled key (CONTRACT §4 table). Caller holds the voucher
     * row lock. Returns 'revoked' (a credit was revoked), 'changed' (status moved
     * only) or null (already refunded / disabled — terminal, nothing to do).
     */
    private function terminate(GiftVoucher $voucher, string $to, LemonSqueezyEvent $record): ?string
    {
        if (in_array($voucher->status, [GiftVoucher::STATUS_REFUNDED, GiftVoucher::STATUS_DISABLED], true)) {
            return null;
        }

        $now = Carbon::now()->startOfSecond();
        $wasRedeemed = $voucher->status === GiftVoucher::STATUS_REDEEMED;
        $voucher->forceFill([
            'status' => $to,
            'refunded_at' => $to === GiftVoucher::STATUS_REFUNDED ? $now : $voucher->refunded_at,
            'disabled_at' => $to === GiftVoucher::STATUS_DISABLED ? $now : $voucher->disabled_at,
        ])->save();

        if ($wasRedeemed && $this->credits->revokeGiftCredit($voucher, $record->idempotency_key, $now) !== null) {
            Log::info('LemonSqueezyWebhook: gift credit revoked', ['voucher_id' => $voucher->id, 'family_id' => $voucher->family_id, 'to' => $to]);

            return 'revoked';
        }

        return 'changed';
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $attrs
     */
    private static function testMode(array $body, array $attrs): bool
    {
        $meta = is_array($body['meta'] ?? null) ? $body['meta'] : [];

        return ($meta['test_mode'] ?? false) === true || ($attrs['test_mode'] ?? false) === true;
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @return array<string, mixed>
     */
    private static function payload(string $eventName, ?string $type, ?int $objectId, array $attrs): array
    {
        $flat = $attrs;
        $item = is_array($attrs['first_order_item'] ?? null) ? $attrs['first_order_item'] : [];
        foreach (['order_id', 'order_item_id', 'product_id', 'variant_id'] as $k) {
            if (! array_key_exists($k, $flat) && array_key_exists($k, $item)) {
                $flat[$k] = $item[$k];
            }
        }
        $out = ['event_name' => $eventName, 'data.type' => $type, 'data.id' => $objectId];
        foreach (self::WHITELIST as $k) {
            if (array_key_exists($k, $flat) && (is_scalar($flat[$k]) || $flat[$k] === null)) {
                $out[$k] = $flat[$k];
            }
        }

        return $out;
    }
}
