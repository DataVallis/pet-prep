<?php

namespace App\Services\Gift;

use App\Jobs\SendGiftRedeemedPush;
use App\Models\Family;
use App\Models\GiftVoucher;
use App\Services\AppSettingsService;
use App\Services\ChallengeCreditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/gift/redeem (M6-S01 / M6-S09): a website gift voucher code → one `web_gift`
 * challenge credit for the parent's family. The family comes from the website sign-in
 * token (M6-S01b, RequireWebSession).
 *
 * Since M6-S09 the code is ours (ShopFulfillment), so the whole redemption is local:
 * one transaction, voucher → family row locks, the credit, then the push after commit.
 * Same code + same family → always 200 (`already` after the first); one credit per
 * voucher (UNIQUE challenge_credits.gift_voucher_id).
 *
 * Logs carry voucher / family ids and the outcome — never the code or the token.
 */
class GiftVoucherRedeemService
{
    public function __construct(
        private readonly AppSettingsService $settings,
        private readonly ChallengeCreditService $credits,
    ) {}

    /** Is redemption on (admin switch)? */
    public function available(): bool
    {
        return $this->settings->giftVouchers();
    }

    public function redeem(string $code, Family $family): GiftRedeemResult
    {
        if (! $this->available()) {
            return GiftRedeemResult::error(GiftRedeemResult::UNAVAILABLE);
        }
        $canonical = GiftVoucher::canonical($code);
        if (strlen($canonical) !== 12) {
            return GiftRedeemResult::error(GiftRedeemResult::INVALID_CODE);
        }
        $voucherId = GiftVoucher::where('key_hash', GiftVoucher::hashOf($canonical))->value('id');
        if ($voucherId === null) {
            Log::info('GiftRedeem: unknown code', ['family_id' => $family->id]);

            return GiftRedeemResult::error(GiftRedeemResult::INVALID_CODE);
        }

        /** @var GiftRedeemResult $result */
        $result = DB::transaction(function () use ($voucherId, $family): GiftRedeemResult {
            $voucher = GiftVoucher::whereKey($voucherId)->lockForUpdate()->firstOrFail();
            $lockedFamily = Family::whereKey($family->id)->lockForUpdate()->first();

            if ($voucher->environment === 'SANDBOX' && ! config('services.stripe.accept_test_mode')) {
                return GiftRedeemResult::error(GiftRedeemResult::INVALID_CODE);
            }
            if ($voucher->status === GiftVoucher::STATUS_REDEEMED) {
                return $voucher->family_id === $family->id
                    ? GiftRedeemResult::redeemed(already: true)
                    : GiftRedeemResult::error(GiftRedeemResult::ALREADY_REDEEMED);
            }
            if (in_array($voucher->status, [GiftVoucher::STATUS_REFUNDED, GiftVoucher::STATUS_DISABLED], true)) {
                return GiftRedeemResult::error(GiftRedeemResult::REFUNDED);
            }
            if ($voucher->status === GiftVoucher::STATUS_EXPIRED || $voucher->expires_at->lt(now())) {
                if ($voucher->status === GiftVoucher::STATUS_PURCHASED) {
                    $voucher->forceFill(['status' => GiftVoucher::STATUS_EXPIRED])->save();
                }

                return GiftRedeemResult::error(GiftRedeemResult::EXPIRED);
            }
            if ($lockedFamily === null) {
                return GiftRedeemResult::error(GiftRedeemResult::UNAUTHENTICATED);
            }

            $voucher->forceFill([
                'status' => GiftVoucher::STATUS_REDEEMED,
                'redeemed_at' => now(),
                'family_id' => $lockedFamily->id,
            ])->save();
            $this->credits->createFromGift($lockedFamily, $voucher);

            return GiftRedeemResult::redeemed(already: false);
        });

        if ($result->error === null && ! $result->already) {
            SendGiftRedeemedPush::dispatch($voucherId);
            Log::info('GiftRedeem: voucher redeemed', ['voucher_id' => $voucherId, 'family_id' => $family->id]);
        } elseif ($result->error !== null) {
            Log::info('GiftRedeem: refused', ['voucher_id' => $voucherId, 'family_id' => $family->id, 'outcome' => $result->error]);
        }

        return $result;
    }
}
