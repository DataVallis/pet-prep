<?php

namespace App\Services\Gift;

use App\Models\GiftVoucher;
use Illuminate\Support\Carbon;

/**
 * The one upsert of a gift_vouchers row (CONTRACT §2.2): the webhook
 * (license_key_created / _updated) and the lazy redeem path both call it,
 * whichever comes first. INSERT … ON CONFLICT DO NOTHING, then reselect — an
 * existing row is never changed here (status, family, redemption stay).
 */
class GiftVoucherRows
{
    public function upsert(string $canonicalKey, int $lsLicenseKeyId, int $lsOrderId, bool $testMode, Carbon $purchasedAt, string $status = GiftVoucher::STATUS_PURCHASED): GiftVoucher
    {
        $now = now();
        $purchasedAt = $purchasedAt->copy()->utc()->startOfSecond();
        GiftVoucher::query()->insertOrIgnore([
            'key_hash' => GiftVoucher::hashOf($canonicalKey),
            'key_last4' => substr($canonicalKey, -4),
            'ls_license_key_id' => $lsLicenseKeyId,
            'ls_order_id' => $lsOrderId,
            'environment' => $testMode ? 'SANDBOX' : 'PRODUCTION',
            'status' => $status,
            'purchased_at' => $purchasedAt,
            'expires_at' => GiftVoucher::expiryFor($purchasedAt),
            'refunded_at' => $status === GiftVoucher::STATUS_REFUNDED ? $now : null,
            'disabled_at' => $status === GiftVoucher::STATUS_DISABLED ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return GiftVoucher::where('ls_license_key_id', $lsLicenseKeyId)->first()
            ?? GiftVoucher::where('key_hash', GiftVoucher::hashOf($canonicalKey))->firstOrFail();
    }

    /**
     * A conditional status move (no lock needed: the WHERE is the guard).
     *
     * @param  list<string>  $from
     */
    public function move(GiftVoucher $voucher, array $from, string $to): GiftVoucher
    {
        $now = now();
        GiftVoucher::whereKey($voucher->id)->whereIn('status', $from)->update(array_filter([
            'status' => $to,
            'refunded_at' => $to === GiftVoucher::STATUS_REFUNDED ? $now : null,
            'disabled_at' => $to === GiftVoucher::STATUS_DISABLED ? $now : null,
            'updated_at' => $now,
        ], fn ($v) => $v !== null));

        return $voucher->refresh();
    }
}
