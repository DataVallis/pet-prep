<?php

namespace App\Services\Shop;

use App\Models\GiftVoucher;
use App\Models\OutboundEmail;
use App\Models\ShopOrder;

/**
 * Builds the buyer's e-mail of a paid order and puts it in the queue (M6-S09, spec
 * §3.6). The whole HTML is rendered now and stored; attachments are only described
 * (the voucher PDF is drawn at send time, never stored). Pure DB work — may run inside
 * the fulfilment transaction.
 */
class ShopEmailComposer
{
    public function queueFor(ShopOrder $order): ?OutboundEmail
    {
        if ($order->customer_email === null || $order->customer_email === '') {
            return null;
        }
        $mail = $this->compose($order);

        return OutboundEmail::create([
            'kind' => $order->product_key,
            'shop_order_id' => $order->id,
            'to_address' => $order->customer_email,
            'to_name' => $order->customer_name,
            'subject' => $mail['subject'],
            'html' => $mail['html'],
            'text' => $mail['text'],
            'attachments' => $mail['attachments'],
            'locale' => $order->locale,
            'status' => OutboundEmail::STATUS_PENDING,
            'next_attempt_at' => now(),
        ]);
    }

    /**
     * @return array{subject: string, html: string, text: string, attachments: list<array<string, mixed>>}
     */
    /** Stands in for the voucher code in the stored HTML / text; replaced by OutboundEmailSender just before sending. */
    public const CODE_TOKEN = '[[petprep-voucher-code]]';

    public function compose(ShopOrder $order): array
    {
        $locale = ShopCheckoutService::locale($order->locale);
        $t = fn (string $key, array $replace = []): string => (string) trans('shop.'.$key, $replace, $locale);
        $voucher = $order->product_key === ShopOrder::PRODUCT_GIFT_VOUCHER ? $order->voucher()->first() : null;
        $gift = $voucher !== null;
        $linkDays = (int) config('shop.email_download_link_days', 30);
        $downloads = array_map(fn (array $l): array => $l + [
            'language' => (string) trans('shop.language_name', [], $l['locale']),
        ], ShopFiles::links($order, $linkDays * 24 * 60, all: false));

        $vars = [
            'locale' => $locale,
            't' => $t,
            // The order number in the subject keeps e-mails of separate orders out of one thread (David 2026-11-17).
            'subject' => $t($gift ? 'gift_subject' : 'kit_subject').' · '.$order->number,
            'preheader' => $t($gift ? 'gift_preheader' : 'kit_preheader'),
            'intro' => $t($gift ? 'gift_intro' : 'kit_intro'),
            // The code is never stored in outbound_emails: a token here, the real code at send time (OutboundEmailSender).
            'code' => $voucher !== null ? self::CODE_TOKEN : null,
            'validUntil' => $voucher !== null ? ShopLinks::date($voucher->expires_at, $locale) : null,
            'redeemUrl' => ShopLinks::redeemUrl($locale),
            'redeemDisplay' => ShopLinks::redeemDisplay($locale),
            'downloads' => $downloads,
            'linkDays' => $linkDays,
            'support' => (string) config('shop.email.support_address'),
            'number' => (string) $order->number,
            'logoUrl' => (string) config('shop.branding.logo_url'),
        ];

        return [
            'subject' => $vars['subject'],
            'html' => view('emails.shop.order', $vars)->render(),
            'text' => $this->text($vars),
            'attachments' => $this->attachmentsFor($order, $voucher, $locale),
        ];
    }

    /**
     * @param  array<string, mixed>  $v
     */
    private function text(array $v): string
    {
        $t = $v['t'];
        $lines = [$t('hello'), '', $v['intro'], ''];
        if ($v['code'] !== null) {
            $lines[] = $t('code_label').': '.$v['code'];
            $lines[] = $t('valid_until', ['date' => $v['validUntil']]);
            $lines[] = '';
            $lines[] = $t('how_title');
            $lines[] = '1. '.$t('step_print');
            $lines[] = '2. '.$t('step_redeem', ['url' => $v['redeemDisplay']]);
            $lines[] = '3. '.$t('step_start');
            $lines[] = $v['redeemUrl'];
            $lines[] = '';
        }
        if ($v['downloads'] !== []) {
            if ($v['code'] !== null) {
                $lines[] = $t('kit_title');
            }
            foreach ($v['downloads'] as $d) {
                $lines[] = $t('download_lang', ['language' => $d['language']]).': '.$d['url'];
            }
            $lines[] = $t('links_valid', ['days' => $v['linkDays']]);
            $lines[] = '';
        }
        $lines[] = $t($v['code'] !== null ? 'help' : 'help_kit', ['email' => $v['support'], 'number' => $v['number']]);
        $lines[] = '';
        $lines[] = $t('signoff');
        $lines[] = $t('signature');
        $lines[] = '';
        $lines[] = $t('order', ['number' => $v['number']]).' · '.$t('receipt');

        return implode("\n", $lines);
    }

    /**
     * The voucher PDF for a gift order; the kit PDF in the buyer's language (else English) for a kit
     * order (David 2026-11-17: the kit e-mail carries the file, not only links). Descriptions only —
     * the files are read at send time.
     *
     * @return list<array<string, int|string>>
     */
    private function attachmentsFor(ShopOrder $order, ?GiftVoucher $voucher, string $locale): array
    {
        if ($voucher !== null) {
            return [['type' => 'voucher_pdf', 'voucher_id' => $voucher->id]];
        }
        if ($order->product_key === ShopOrder::PRODUCT_PROMISE_KIT) {
            foreach ([$locale, 'en'] as $l) {
                if (ShopFiles::exists(ShopOrder::PRODUCT_PROMISE_KIT, $l)) {
                    return [['type' => 'shop_file', 'product' => ShopOrder::PRODUCT_PROMISE_KIT, 'locale' => $l]];
                }
            }
        }

        return [];
    }
}
