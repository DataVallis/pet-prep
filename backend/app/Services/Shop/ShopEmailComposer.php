<?php

namespace App\Services\Shop;

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
            'subject' => $t($gift ? 'gift_subject' : 'kit_subject'),
            'preheader' => $t($gift ? 'gift_preheader' : 'kit_preheader'),
            'intro' => $t($gift ? 'gift_intro' : 'kit_intro'),
            'code' => $voucher?->code(),
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
            'attachments' => $voucher !== null ? [['type' => 'voucher_pdf', 'voucher_id' => $voucher->id]] : [],
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
        $lines[] = $t('help', ['email' => $v['support'], 'number' => $v['number']]);
        $lines[] = '';
        $lines[] = $t('signoff');
        $lines[] = $t('signature');
        $lines[] = '';
        $lines[] = $t('order', ['number' => $v['number']]).' · '.$t('receipt');

        return implode("\n", $lines);
    }
}
