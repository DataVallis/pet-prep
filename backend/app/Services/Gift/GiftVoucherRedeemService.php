<?php

namespace App\Services\Gift;

use App\Jobs\SendGiftRedeemedPush;
use App\Jobs\SendGiftVoucherAlert;
use App\Models\Family;
use App\Models\GiftVoucher;
use App\Models\LemonSqueezyEvent;
use App\Models\User;
use App\Services\AppSettingsService;
use App\Services\ChallengeCreditService;
use App\Services\FamilyService;
use App\Services\LemonSqueezy\LemonSqueezyLicenseClient;
use App\Services\LemonSqueezy\LemonSqueezyUpstreamException;
use App\Services\LemonSqueezy\LicenseResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/gift/redeem (M6-S01, CONTRACT §2.4): a website gift voucher (a Lemon
 * Squeezy license key) → one `web_gift` challenge credit for the parent's family.
 *
 * Order: switch → local row → LS validate (no side effect) → lazy row upsert +
 * refunded-order check → parent lookup by e-mail (only now: the code is proven
 * real — no e-mail enumeration) → claim (short transaction) → LS activate
 * (outside any transaction) → commit (voucher → family locks, credit) → push
 * after commit. Same code + same family → always 200 (`already` after the
 * first); one credit per voucher (UNIQUE challenge_credits.gift_voucher_id).
 *
 * Logs carry voucher / LS key / family ids and the outcome — never the code,
 * the e-mail or LS customer fields.
 */
class GiftVoucherRedeemService
{
    public function __construct(
        private readonly AppSettingsService $settings,
        private readonly LemonSqueezyLicenseClient $ls,
        private readonly FamilyService $families,
        private readonly ChallengeCreditService $credits,
        private readonly GiftVoucherRows $rows,
    ) {}

    /** Is redemption on (admin switch) and the LS store configured? */
    public function available(): bool
    {
        return $this->settings->giftVouchers()
            && (string) config('services.lemonsqueezy.store_id') !== ''
            && (string) config('services.lemonsqueezy.gift_product_id') !== '';
    }

    public function redeem(string $code, string $email): GiftRedeemResult
    {
        // 2. Switch / configuration.
        if (! $this->available()) {
            return GiftRedeemResult::error(GiftRedeemResult::UNAVAILABLE);
        }

        // m2 (QA): Lemon Squeezy gets the code exactly as entered (trimmed, case kept);
        // the canonical form (no whitespace, lower case) is only for our hash.
        $canonical = GiftVoucher::canonical($code);
        $lsKey = trim($code);

        // 3. Local lookup.
        $voucher = GiftVoucher::where('key_hash', GiftVoucher::hashOf($canonical))->first();
        if ($voucher !== null && $voucher->status !== GiftVoucher::STATUS_PURCHASED) {
            return $this->settled($voucher, $email);
        }
        if ($voucher !== null && $voucher->expires_at->lt(now())) {
            $this->rows->move($voucher, [GiftVoucher::STATUS_PURCHASED], GiftVoucher::STATUS_EXPIRED);

            return $this->finish($voucher, GiftRedeemResult::EXPIRED);
        }

        // 4. LS validate (HTTP, never in a transaction).
        try {
            $license = $this->ls->validate($lsKey);
        } catch (LemonSqueezyUpstreamException $e) {
            Log::warning('GiftRedeem: LS validate failed', ['voucher_id' => $voucher?->id, 'error' => $e->getMessage()]);

            return GiftRedeemResult::error(GiftRedeemResult::UPSTREAM_ERROR);
        }
        $refusal = $this->licenseRefusal($license);
        if ($refusal !== null) {
            return $refusal;
        }
        /** @var int $licenseKeyId */
        $licenseKeyId = $license->licenseKeyId;
        /** @var int $orderId (licenseRefusal: never null — MA1) */
        $orderId = $license->orderId;
        // m4 (QA): the 12 months run from the purchase. Without LS's created_at, the order's
        // created_at from our webhook ledger; without both, refuse (never start them today).
        $purchasedAt = $license->createdAt ?? $this->orderCreatedAt($orderId);
        if ($purchasedAt === null) {
            Log::warning('GiftRedeem: no purchase date from LS and no order in the ledger', ['ls_license_key_id' => $licenseKeyId, 'ls_order_id' => $orderId]);

            return GiftRedeemResult::error(GiftRedeemResult::UPSTREAM_ERROR);
        }

        if ($license->status === 'disabled') {
            $voucher = $this->rows->upsert($canonical, $licenseKeyId, $orderId, $license->testMode, $purchasedAt, GiftVoucher::STATUS_DISABLED);
            $this->rows->move($voucher, [GiftVoucher::STATUS_PURCHASED, GiftVoucher::STATUS_EXPIRED], GiftVoucher::STATUS_DISABLED);

            return $this->finish($voucher, GiftRedeemResult::REFUNDED);
        }
        if ($license->status === 'expired' || GiftVoucher::expiryFor($purchasedAt)->lt(now())) {
            $voucher = $this->rows->upsert($canonical, $licenseKeyId, $orderId, $license->testMode, $purchasedAt, GiftVoucher::STATUS_EXPIRED);
            $this->rows->move($voucher, [GiftVoucher::STATUS_PURCHASED], GiftVoucher::STATUS_EXPIRED);

            return $voucher->status === GiftVoucher::STATUS_EXPIRED
                ? $this->finish($voucher, GiftRedeemResult::EXPIRED)
                : $this->settled($voucher, $email);
        }

        // 5. Lazy row + a refund that arrived before any key row existed.
        $voucher = $this->rows->upsert($canonical, $licenseKeyId, $orderId, $license->testMode, $purchasedAt);
        if ($voucher->status === GiftVoucher::STATUS_PURCHASED && $this->orderRefunded($voucher->ls_order_id)) {
            $this->rows->move($voucher, [GiftVoucher::STATUS_PURCHASED, GiftVoucher::STATUS_EXPIRED], GiftVoucher::STATUS_REFUNDED);
        }
        if ($voucher->status !== GiftVoucher::STATUS_PURCHASED) {
            return $this->settled($voucher, $email);
        }

        // 6. Parent lookup (the code is proven real now).
        $family = $this->familyFor($email);
        if ($family === null) {
            return $this->finish($voucher, GiftRedeemResult::NO_ACCOUNT);
        }

        // 7. LS already shows the activation → recovery.
        if ($license->usedUp()) {
            return $this->recover($voucher, $family, $lsKey, $licenseKeyId);
        }

        // 8. Claim (short transaction).
        $claim = $this->claim($voucher, $family);
        if ($claim !== null) {
            return $claim;
        }

        // 9. LS activate — outside any transaction.
        try {
            $activation = $this->ls->activate($lsKey, self::instanceName($family));
        } catch (LemonSqueezyUpstreamException $e) {
            Log::warning('GiftRedeem: LS activate failed', ['voucher_id' => $voucher->id, 'family_id' => $family->id, 'error' => $e->getMessage()]);

            return GiftRedeemResult::error(GiftRedeemResult::UPSTREAM_ERROR);
        }
        if ($activation->activated) {
            return $this->commit($voucher, $family, $activation->instanceId);
        }

        // Not activated: decide by the counters, never by LS's error text (L3).
        try {
            $again = $this->ls->validate($lsKey);
        } catch (LemonSqueezyUpstreamException) {
            return GiftRedeemResult::error(GiftRedeemResult::UPSTREAM_ERROR);
        }
        if ($again->licenseKeyId === $licenseKeyId && $again->usedUp()) {
            return $this->recover($voucher, $family, $lsKey, $licenseKeyId);
        }
        Log::warning('GiftRedeem: LS refused the activation', ['voucher_id' => $voucher->id, 'family_id' => $family->id, 'http' => $activation->httpStatus]);

        return GiftRedeemResult::error(GiftRedeemResult::UPSTREAM_ERROR);
    }

    /** `instance_name` of our activation = the redeeming family (no personal data). */
    public static function instanceName(Family $family): string
    {
        return 'family:'.$family->id;
    }

    /** Step 4 refusals before anything is written (L15). */
    private function licenseRefusal(LicenseResponse $license): ?GiftRedeemResult
    {
        if ($license->licenseKeyId === null) {
            return GiftRedeemResult::error(GiftRedeemResult::INVALID_CODE);
        }
        if ($license->orderId === null) {
            // MA1 (QA): without the order id a later refund could never find the voucher.
            Log::warning('GiftRedeem: LS answer without meta.order_id — refused', ['ls_license_key_id' => $license->licenseKeyId]);

            return GiftRedeemResult::error(GiftRedeemResult::UPSTREAM_ERROR);
        }
        if (! LemonSqueezyLicenseClient::matches($license)) {
            Log::warning('GiftRedeem: license key of another store / product / variant', [
                'ls_license_key_id' => $license->licenseKeyId,
                'store_id' => $license->storeId,
                'product_id' => $license->productId,
                'variant_id' => $license->variantId,
            ]);

            return GiftRedeemResult::error(GiftRedeemResult::INVALID_CODE);
        }
        if ($license->testMode && ! config('services.lemonsqueezy.accept_test_mode')) {
            return GiftRedeemResult::error(GiftRedeemResult::INVALID_CODE);
        }
        if ($license->testMode && app()->environment('production')) {
            // m5 (QA): a test-mode key redeems into a real family — System health shows it.
            Log::error('GiftRedeem: a Lemon Squeezy test-mode key was accepted in production (LEMONSQUEEZY_ACCEPT_TEST_MODE=true) — set it to false', ['ls_license_key_id' => $license->licenseKeyId]);
        }
        if (! in_array($license->status, LicenseResponse::KNOWN_STATUSES, true)) {
            Log::warning('GiftRedeem: unknown LS license status', ['ls_license_key_id' => $license->licenseKeyId]);

            return GiftRedeemResult::error(GiftRedeemResult::INVALID_CODE);
        }

        return null;
    }

    /** A voucher that is not `purchased` any more answers from its own state. */
    private function settled(GiftVoucher $voucher, string $email): GiftRedeemResult
    {
        return match ($voucher->status) {
            GiftVoucher::STATUS_REFUNDED, GiftVoucher::STATUS_DISABLED => $this->finish($voucher, GiftRedeemResult::REFUNDED),
            GiftVoucher::STATUS_EXPIRED => $this->finish($voucher, GiftRedeemResult::EXPIRED),
            GiftVoucher::STATUS_REDEEMED => $this->redeemedFor($voucher, $this->familyFor($email)),
            default => $voucher->expires_at->lt(now())
                ? $this->finish($voucher, GiftRedeemResult::EXPIRED)
                : GiftRedeemResult::error(GiftRedeemResult::UPSTREAM_ERROR),
        };
    }

    private function redeemedFor(GiftVoucher $voucher, ?Family $family): GiftRedeemResult
    {
        if ($family !== null && $voucher->family_id === $family->id) {
            Log::info('GiftRedeem: replay of a redeemed voucher', ['voucher_id' => $voucher->id, 'family_id' => $family->id]);

            return GiftRedeemResult::redeemed(already: true);
        }

        return $this->finish($voucher, GiftRedeemResult::ALREADY_REDEEMED);
    }

    /** The parent's family by account e-mail (`users.email`, case-insensitive), or null. */
    private function familyFor(string $email): ?Family
    {
        $user = User::findByEmail($email);
        if ($user === null || ! $user->isParent()) {
            return null;
        }

        return $this->families->familyOf($user);
    }

    /** created_at of the order from the webhook ledger (m4), or null. */
    private function orderCreatedAt(int $lsOrderId): ?Carbon
    {
        $payload = LemonSqueezyEvent::where('ls_order_id', $lsOrderId)->where('object_type', 'orders')
            ->orderBy('id')->get(['payload'])->map(fn (LemonSqueezyEvent $e) => $e->payload['created_at'] ?? null)->filter()->first();

        return LicenseResponse::date($payload);
    }

    private function orderRefunded(int $lsOrderId): bool
    {
        return LemonSqueezyEvent::where('event_name', 'order_refunded')
            ->where('ls_order_id', $lsOrderId)
            ->whereIn('outcome', [LemonSqueezyEvent::OUTCOME_REFUNDED, LemonSqueezyEvent::OUTCOME_REVOKED])
            ->exists();
    }

    /** Step 8: null = claimed for $family; otherwise the answer. */
    private function claim(GiftVoucher $voucher, Family $family): ?GiftRedeemResult
    {
        /** @var GiftRedeemResult|GiftVoucher $result */
        $result = DB::transaction(function () use ($voucher, $family): GiftRedeemResult|GiftVoucher {
            $locked = GiftVoucher::whereKey($voucher->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== GiftVoucher::STATUS_PURCHASED) {
                return $locked;
            }
            if ($locked->claim_family_id !== null && $locked->claim_family_id !== $family->id
                && $locked->claimed_at !== null && $locked->claimed_at->gt(now()->subMinutes(GiftVoucher::CLAIM_TTL_MINUTES))) {
                return GiftRedeemResult::error(GiftRedeemResult::ALREADY_REDEEMED);
            }
            $locked->forceFill(['claim_family_id' => $family->id, 'claimed_at' => now()])->save();

            return GiftRedeemResult::redeemed(false); // marker: claimed
        });

        if ($result instanceof GiftVoucher) {
            return $this->settledFor($result, $family);
        }
        if ($result->error !== null) {
            return $this->finish($voucher, $result->error);
        }

        return null;
    }

    /** Like settled(), with the family already resolved. */
    private function settledFor(GiftVoucher $voucher, Family $family): GiftRedeemResult
    {
        return match ($voucher->status) {
            GiftVoucher::STATUS_REDEEMED => $this->redeemedFor($voucher, $family),
            GiftVoucher::STATUS_EXPIRED => $this->finish($voucher, GiftRedeemResult::EXPIRED),
            default => $this->finish($voucher, GiftRedeemResult::REFUNDED),
        };
    }

    /**
     * R — LS shows the key as used but our row is not redeemed (e.g. a crash between
     * activate and commit, CONTRACT §2.4 R).
     */
    private function recover(GiftVoucher $voucher, Family $family, string $lsKey, int $licenseKeyId): GiftRedeemResult
    {
        // m1 (QA): another request of this family may have committed meanwhile (double submit).
        $fresh = $voucher->fresh() ?? $voucher;
        if ($fresh->status !== GiftVoucher::STATUS_PURCHASED) {
            return $this->settledFor($fresh, $family);
        }
        if ((string) config('services.lemonsqueezy.api_key') !== '') {
            try {
                $instances = $this->ls->instances($licenseKeyId);
            } catch (LemonSqueezyUpstreamException $e) {
                Log::warning('GiftRedeem: LS instance list failed', ['voucher_id' => $voucher->id, 'error' => $e->getMessage()]);

                return GiftRedeemResult::error(GiftRedeemResult::UPSTREAM_ERROR);
            }
            $ours = collect($instances)->firstWhere('name', self::instanceName($family));
            if ($ours !== null) {
                try {
                    $check = $this->ls->validate($lsKey, $ours['identifier']);
                } catch (LemonSqueezyUpstreamException) {
                    return GiftRedeemResult::error(GiftRedeemResult::UPSTREAM_ERROR);
                }
                if (! $check->valid || $check->licenseKeyId !== $licenseKeyId) {
                    Log::warning('GiftRedeem: recovery instance did not validate', ['voucher_id' => $voucher->id, 'family_id' => $family->id]);

                    return GiftRedeemResult::error(GiftRedeemResult::UPSTREAM_ERROR);
                }
                Log::warning('GiftRedeem: recovered an activation without a commit', ['voucher_id' => $voucher->id, 'family_id' => $family->id]);

                return $this->commit($voucher, $family, $ours['identifier']);
            }
            if ($instances !== []) {
                $this->alert(SendGiftVoucherAlert::REASON_INSTANCE_MISMATCH, $voucher);

                return $this->finish($voucher, GiftRedeemResult::ALREADY_REDEEMED);
            }
            // LS counts an activation but lists none: fall back to our own claim row.
        }

        if ($voucher->fresh()?->claim_family_id === $family->id) {
            Log::warning('GiftRedeem: recovered by our claim row (no LS instance id)', ['voucher_id' => $voucher->id, 'family_id' => $family->id]);

            return $this->commit($voucher, $family, null);
        }

        $this->alert(SendGiftVoucherAlert::REASON_ACTIVATED_ELSEWHERE, $voucher);

        return $this->finish($voucher, GiftRedeemResult::ALREADY_REDEEMED);
    }

    /** Step 10 + 11: voucher → family locks, the credit, push after commit. */
    private function commit(GiftVoucher $voucher, Family $family, ?string $instanceId): GiftRedeemResult
    {
        /** @var array{0: GiftRedeemResult, 1: bool} $outcome  [result, LS activated for F but the row went elsewhere] */
        $outcome = DB::transaction(function () use ($voucher, $family, $instanceId): array {
            $locked = GiftVoucher::whereKey($voucher->id)->lockForUpdate()->firstOrFail();
            $lockedFamily = Family::whereKey($family->id)->lockForUpdate()->first();

            if ($locked->status === GiftVoucher::STATUS_REDEEMED) {
                return $locked->family_id === $family->id
                    ? [GiftRedeemResult::redeemed(already: true), false]
                    : [GiftRedeemResult::error(GiftRedeemResult::ALREADY_REDEEMED), true];
            }
            if (in_array($locked->status, [GiftVoucher::STATUS_REFUNDED, GiftVoucher::STATUS_DISABLED], true)) {
                return [GiftRedeemResult::error(GiftRedeemResult::REFUNDED), false];
            }
            if ($locked->status === GiftVoucher::STATUS_EXPIRED) {
                return [GiftRedeemResult::error(GiftRedeemResult::EXPIRED), false];
            }
            if ($lockedFamily === null) {
                return [GiftRedeemResult::error(GiftRedeemResult::NO_ACCOUNT), false];
            }

            $locked->forceFill([
                'status' => GiftVoucher::STATUS_REDEEMED,
                'redeemed_at' => now(),
                'family_id' => $lockedFamily->id,
                'ls_instance_id' => $instanceId,
                'claim_family_id' => null,
                'claimed_at' => null,
            ])->save();
            $this->credits->createFromGift($lockedFamily, $locked);

            return [GiftRedeemResult::redeemed(already: false), false];
        });

        [$result, $conflict] = $outcome;
        if ($conflict) {
            Log::error('GiftRedeem: LS activated for this family but another family holds the voucher', ['voucher_id' => $voucher->id, 'family_id' => $family->id]);
            $this->alert(SendGiftVoucherAlert::REASON_INSTANCE_MISMATCH, $voucher);
        }
        if ($result->error === null && ! $result->already) {
            // After the commit (the closure has returned): one push to the family's parents.
            SendGiftRedeemedPush::dispatch($voucher->id);
            Log::info('GiftRedeem: voucher redeemed', ['voucher_id' => $voucher->id, 'ls_license_key_id' => $voucher->ls_license_key_id, 'family_id' => $family->id]);

            return $result;
        }

        return $result->error === null ? $result : $this->finish($voucher, $result->error);
    }

    private function alert(string $reason, GiftVoucher $voucher): void
    {
        SendGiftVoucherAlert::dispatch($reason, $voucher->id, $voucher->ls_order_id);
    }

    private function finish(GiftVoucher $voucher, string $error): GiftRedeemResult
    {
        Log::info('GiftRedeem: refused', ['voucher_id' => $voucher->id, 'ls_license_key_id' => $voucher->ls_license_key_id, 'outcome' => $error]);

        return GiftRedeemResult::error($error);
    }
}
