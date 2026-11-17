<?php

namespace App\Services\Shop;

use App\Models\StripeEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Stripe webhook → `stripe_events` ledger → shop (M6-S09). Each `event.id` is processed
 * once: the ledger row is claimed first (UNIQUE event_id); a failure deletes the claim
 * so Stripe's retry runs it again. Fulfilment reads the session from Stripe (HTTP), so
 * the claim is not one transaction with the work — every step is idempotent itself.
 * Runs whatever the shop switches say (payments and refunds are never lost).
 */
class StripeWebhookService
{
    public const HANDLED = [
        'checkout.session.completed',
        'checkout.session.async_payment_succeeded',
        'checkout.session.async_payment_failed',
        'charge.refunded',
        'charge.dispute.created',
        'charge.dispute.closed',
    ];

    public function __construct(
        private readonly ShopFulfillment $fulfillment,
        private readonly ShopRefundService $refunds,
    ) {}

    /**
     * @return string|null the outcome, or null for a duplicate delivery
     */
    public function handle(string $rawBody): ?string
    {
        $event = json_decode($rawBody, true);
        $id = is_array($event) ? ($event['id'] ?? null) : null;
        $type = is_array($event) ? ($event['type'] ?? null) : null;
        if (! is_string($id) || $id === '' || ! is_string($type)) {
            Log::warning('StripeWebhook: body without id / type — not stored');

            return StripeEvent::OUTCOME_IGNORED;
        }
        /** @var array<string, mixed> $object */
        $object = is_array(data_get($event, 'data.object')) ? data_get($event, 'data.object') : [];
        $livemode = (bool) ($event['livemode'] ?? false);

        if (StripeEvent::where('event_id', mb_substr($id, 0, 64))->exists()) {
            return null;
        }
        try {
            // Own (sub)transaction: a concurrent duplicate's unique violation rolls back only this.
            $record = DB::transaction(fn (): StripeEvent => StripeEvent::create([
                'event_id' => mb_substr($id, 0, 64),
                'type' => mb_substr($type, 0, 64),
                'object_id' => is_string($object['id'] ?? null) ? mb_substr($object['id'], 0, 255) : null,
                'livemode' => $livemode,
            ]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        try {
            [$outcome, $orderId] = $this->process($type, $object, $livemode, $id);
        } catch (\Throwable $e) {
            $record->delete(); // Stripe retries; the claim must not swallow the event
            throw $e;
        }
        $record->forceFill(['outcome' => $outcome, 'shop_order_id' => $orderId, 'processed_at' => now()])->save();
        Log::info('StripeWebhook: event processed', ['event_id' => $id, 'type' => $type, 'order_id' => $orderId, 'outcome' => $outcome]);

        return $outcome;
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array{0: string, 1: int|null}
     */
    private function process(string $type, array $object, bool $livemode, string $eventId): array
    {
        if (! in_array($type, self::HANDLED, true)) {
            return [StripeEvent::OUTCOME_IGNORED, null];
        }
        if (! $livemode && ! config('services.stripe.accept_test_mode')) {
            return [StripeEvent::OUTCOME_TEST_MODE_IGNORED, null];
        }

        if (str_starts_with($type, 'checkout.session.')) {
            $sessionId = (string) ($object['id'] ?? '');
            if (data_get($object, 'metadata.petprep_product') === null) {
                return [StripeEvent::OUTCOME_NOT_OURS, null]; // another sale of this Stripe account
            }
            if ($type === 'checkout.session.async_payment_failed') {
                $order = $this->fulfillment->markFailed($sessionId);

                return [$order === null ? StripeEvent::OUTCOME_UNKNOWN_ORDER : StripeEvent::OUTCOME_FAILED, $order?->id];
            }
            $order = $this->fulfillment->fulfill($sessionId);
            if ($order === null) {
                return [$this->fulfillment->lastRejected ? StripeEvent::OUTCOME_REJECTED : StripeEvent::OUTCOME_NOT_OURS, null];
            }

            return [$order->fulfilled_at !== null ? StripeEvent::OUTCOME_FULFILLED : StripeEvent::OUTCOME_PENDING, $order->id];
        }

        $pi = $object['payment_intent'] ?? null;
        $pi = is_array($pi) ? ($pi['id'] ?? null) : $pi;
        if (! is_string($pi) || $pi === '') {
            return [StripeEvent::OUTCOME_UNKNOWN_ORDER, null];
        }

        if ($type === 'charge.refunded') {
            [$outcome, $order] = $this->refunds->refunded(
                $pi,
                (int) ($object['amount'] ?? 0),
                (int) ($object['amount_refunded'] ?? 0),
                (bool) ($object['refunded'] ?? false),
                $eventId,
            );
        } else {
            [$outcome, $order] = $this->refunds->dispute($pi, (string) ($object['status'] ?? ''), $type === 'charge.dispute.closed', $eventId);
        }

        return [$order === null ? StripeEvent::OUTCOME_UNKNOWN_ORDER : $outcome, $order?->id];
    }
}
