<?php

namespace App\Services\Shop;

use App\Jobs\SendShopAlert;
use App\Models\GiftVoucher;
use App\Models\ShopOrder;
use App\Services\ChallengeCreditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Refunds and disputes of a shop order (M6-S09, spec §3.4), from the Stripe webhook.
 * Lock order: order → voucher → (ChallengeCreditService) family → credit → pet.
 *
 * Full refund / lost dispute of a gift order:
 *  - unredeemed voucher → refunded / disabled;
 *  - redeemed, credit not assigned → credit revoked;
 *  - assigned, challenge running → the existing refund rule (pet locked / back to free);
 *  - challenge finished → only the status (ChallengeService::markRefunded decides).
 * A partial refund changes nothing but the order (+ admin alert). Alerts go after commit.
 */
class ShopRefundService
{
    public const RESULT_UNKNOWN = 'unknown_order';

    public function __construct(private readonly ChallengeCreditService $credits) {}

    /**
     * A charge.refunded event.
     *
     * @return array{0: string, 1: ShopOrder|null} [outcome, order]
     */
    public function refunded(string $paymentIntentId, int $amount, int $amountRefunded, bool $full, string $eventId): array
    {
        /** @var array{0: string, 1: ShopOrder|null, 2: string|null} $r */
        $r = DB::transaction(function () use ($paymentIntentId, $amount, $amountRefunded, $full, $eventId): array {
            $order = ShopOrder::where('stripe_payment_intent_id', $paymentIntentId)->lockForUpdate()->first();
            if ($order === null) {
                return [self::RESULT_UNKNOWN, null, null];
            }
            $order->forceFill(['amount_refunded' => $amountRefunded]);
            if (! $full && $amountRefunded < $amount) {
                if ($order->status === ShopOrder::STATUS_PAID) {
                    $order->status = ShopOrder::STATUS_PARTIALLY_REFUNDED;
                }
                $order->save();

                return ['partial_refund', $order, SendShopAlert::REASON_PARTIAL_REFUND];
            }
            $this->takeBack($order, GiftVoucher::STATUS_REFUNDED, $eventId);
            $order->forceFill(['status' => ShopOrder::STATUS_REFUNDED, 'refunded_at' => $order->refunded_at ?? now()])->save();

            return ['refunded', $order, null];
        });

        return $this->after($r);
    }

    /**
     * charge.dispute.created / charge.dispute.closed.
     *
     * @return array{0: string, 1: ShopOrder|null}
     */
    public function dispute(string $paymentIntentId, string $disputeStatus, bool $closed, string $eventId): array
    {
        /** @var array{0: string, 1: ShopOrder|null, 2: string|null} $r */
        $r = DB::transaction(function () use ($paymentIntentId, $disputeStatus, $closed, $eventId): array {
            $order = ShopOrder::where('stripe_payment_intent_id', $paymentIntentId)->lockForUpdate()->first();
            if ($order === null) {
                return [self::RESULT_UNKNOWN, null, null];
            }
            if (! $closed) {
                if (in_array($order->status, [ShopOrder::STATUS_PAID, ShopOrder::STATUS_PARTIALLY_REFUNDED], true)) {
                    $order->forceFill(['status' => ShopOrder::STATUS_DISPUTED, 'disputed_at' => now()])->save();
                }

                return ['disputed', $order, SendShopAlert::REASON_DISPUTE_OPENED];
            }
            if ($disputeStatus === 'lost') {
                $this->takeBack($order, GiftVoucher::STATUS_DISABLED, $eventId);
                $order->forceFill(['status' => ShopOrder::STATUS_REFUNDED, 'refunded_at' => $order->refunded_at ?? now()])->save();

                return ['dispute_lost', $order, SendShopAlert::REASON_DISPUTE_LOST];
            }
            if ($order->status === ShopOrder::STATUS_DISPUTED) {
                $order->forceFill(['status' => $order->amount_refunded > 0 ? ShopOrder::STATUS_PARTIALLY_REFUNDED : ShopOrder::STATUS_PAID])->save();
            }

            return ['dispute_won', $order, null];
        });

        return $this->after($r);
    }

    /** Caller holds the order lock. */
    private function takeBack(ShopOrder $order, string $to, string $eventId): void
    {
        $voucher = GiftVoucher::where('shop_order_id', $order->id)->lockForUpdate()->first();
        if ($voucher === null || in_array($voucher->status, [GiftVoucher::STATUS_REFUNDED, GiftVoucher::STATUS_DISABLED], true)) {
            return;
        }
        $now = Carbon::now()->startOfSecond();
        $wasRedeemed = $voucher->status === GiftVoucher::STATUS_REDEEMED;
        $voucher->forceFill([
            'status' => $to,
            'refunded_at' => $to === GiftVoucher::STATUS_REFUNDED ? $now : $voucher->refunded_at,
            'disabled_at' => $to === GiftVoucher::STATUS_DISABLED ? $now : $voucher->disabled_at,
        ])->save();
        if ($wasRedeemed && $this->credits->revokeGiftCredit($voucher, 'stripe:'.$eventId, $now) !== null) {
            Log::info('ShopRefund: gift credit revoked', ['voucher_id' => $voucher->id, 'family_id' => $voucher->family_id, 'to' => $to]);
        }
    }

    /**
     * @param  array{0: string, 1: ShopOrder|null, 2: string|null}  $r
     * @return array{0: string, 1: ShopOrder|null}
     */
    private function after(array $r): array
    {
        [$outcome, $order, $alert] = $r;
        if ($alert !== null && $order !== null) {
            SendShopAlert::dispatch($alert, $order->id);
        }

        return [$outcome, $order];
    }
}
