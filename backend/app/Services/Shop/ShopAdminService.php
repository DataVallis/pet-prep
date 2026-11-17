<?php

namespace App\Services\Shop;

use App\Models\GiftVoucher;
use App\Models\OutboundEmail;
use App\Models\ShopAuditLog;
use App\Models\ShopOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Admin actions of the shop (M6-S09, spec §3.7) — every one logged in shop_audit_logs.
 * Superadmin only (the Filament resources check it).
 */
class ShopAdminService
{
    public function __construct(private readonly ShopEmailComposer $emails) {}

    public function revealCode(User $admin, GiftVoucher $voucher): string
    {
        ShopAuditLog::record($admin, 'reveal_code', 'gift_voucher', $voucher->id);

        return $voucher->code();
    }

    /** A freshly composed e-mail (new download links) for the order's buyer. */
    public function resend(User $admin, ShopOrder $order): ?OutboundEmail
    {
        if (! $order->delivers() || $order->customer_email === null) {
            return null;
        }

        return DB::transaction(function () use ($admin, $order): ?OutboundEmail {
            $email = $this->emails->queueFor($order);
            ShopAuditLog::record($admin, 'resend_email', 'shop_order', $order->id, ['email_id' => $email?->id]);

            return $email;
        });
    }

    public function changeEmailAndResend(User $admin, ShopOrder $order, string $address): ?OutboundEmail
    {
        return DB::transaction(function () use ($admin, $order, $address): ?OutboundEmail {
            $locked = ShopOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['customer_email' => mb_strtolower(trim($address))])->save();
            ShopAuditLog::record($admin, 'change_email', 'shop_order', $locked->id);
            OutboundEmail::where('shop_order_id', $locked->id)
                ->whereIn('status', [OutboundEmail::STATUS_PENDING, OutboundEmail::STATUS_FAILED])
                ->update(['status' => OutboundEmail::STATUS_CANCELLED, 'updated_at' => now()]);

            return $locked->delivers() ? $this->emails->queueFor($locked) : null;
        });
    }

    public function cancelEmail(User $admin, OutboundEmail $email): void
    {
        if (in_array($email->status, [OutboundEmail::STATUS_PENDING, OutboundEmail::STATUS_FAILED], true)) {
            $email->forceFill(['status' => OutboundEmail::STATUS_CANCELLED])->save();
            ShopAuditLog::record($admin, 'cancel_email', 'outbound_email', $email->id);
        }
    }
}
