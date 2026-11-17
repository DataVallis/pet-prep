<?php

namespace App\Services\Mail;

use App\Jobs\SendShopAlert;
use App\Models\GiftVoucher;
use App\Models\OutboundEmail;
use App\Models\ShopOrder;
use App\Services\Shop\ShopEmailComposer;
use App\Services\Shop\VoucherPdfRenderer;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends the e-mail queue (M6-S09, WEBSITE_SALES_SPEC §3.6, David 2026-11-17): `emails:send`
 * every minute takes the due `pending` rows (FOR UPDATE SKIP LOCKED, so two runs never
 * send the same row), marks them `sending`, sends each through the configured mailer
 * (SMTP mail.datavallis.com in production) and records the result:
 *  - sent → `sent`;
 *  - a refusal of the recipient (550 / 551 / 553, enhanced 5.1.x — no such mailbox) → `failed` at once;
 *  - the mail server itself is down or refuses us (connection, 421, 530 / 535 auth, …) → this row
 *    is retried and the rest of the batch goes back to the queue untouched (one alert per hour),
 *    so a wrong password never burns the attempts of every e-mail;
 *  - anything else → `pending` again after config('shop.email.retry_minutes')[attempts − 1];
 *    after the last one → `failed`.
 * Every `failed` row gets ONE admin alert (Discord, ids only). A row stuck in `sending`
 * (crashed run) counts as a failed attempt. The voucher code is never stored in the row:
 * ShopEmailComposer::CODE_TOKEN is replaced right before sending. A row of an order that no
 * longer delivers (refunded) is cancelled instead of sent. No send happens inside a DB transaction.
 */
class OutboundEmailSender
{
    public function __construct(private readonly VoucherPdfRenderer $vouchers) {}

    /**
     * @return array{sent: int, retry: int, failed: int}
     */
    public function run(?int $limit = null): array
    {
        $this->releaseStale();
        $ids = $this->claim($limit ?? (int) config('shop.email.batch', 50));
        $counts = ['sent' => 0, 'retry' => 0, 'failed' => 0];
        foreach ($ids as $i => $id) {
            $email = OutboundEmail::find($id);
            if ($email === null) {
                continue;
            }
            $email->touch(); // a long batch never looks stale to a parallel run
            $result = $this->sendOne($email);
            if ($result === 'down') {
                $counts['retry']++;
                $this->putBack(array_slice($ids, $i + 1));
                break;
            }
            $counts[$result === 'cancelled' ? 'failed' : $result]++;
        }

        return $counts;
    }

    /** Put a row back in the queue now (admin »Send again«); a new attempt series. */
    public function requeue(OutboundEmail $email): void
    {
        $email->forceFill([
            'status' => OutboundEmail::STATUS_PENDING,
            'attempts' => 0,
            'next_attempt_at' => now(),
            'failed_at' => null,
            'alerted_at' => null,
            'last_error' => null,
        ])->save();
    }

    /**
     * @return list<int>
     */
    private function claim(int $limit): array
    {
        return DB::transaction(function () use ($limit): array {
            $ids = OutboundEmail::query()
                ->where('status', OutboundEmail::STATUS_PENDING)
                ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                ->orderBy('id')
                ->limit($limit)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->pluck('id')
                ->all();
            if ($ids !== []) {
                OutboundEmail::whereIn('id', $ids)->update(['status' => OutboundEmail::STATUS_SENDING, 'updated_at' => now()]);
            }

            return array_map('intval', $ids);
        });
    }

    /** A row left in `sending` by a crashed run counts as a failed attempt (retry ladder, alert at the end). */
    private function releaseStale(): void
    {
        $stale = OutboundEmail::where('status', OutboundEmail::STATUS_SENDING)
            ->where('updated_at', '<', now()->subMinutes((int) config('shop.email.stale_sending_minutes', 15)))
            ->get();
        foreach ($stale as $email) {
            $this->failed($email, $email->attempts + 1, new \RuntimeException('Stale `sending` row (the sending run did not finish)'));
        }
    }

    /**
     * Claimed rows that were not tried because the mail server is down: back to the queue, attempts unchanged.
     *
     * @param  list<int>  $ids
     */
    private function putBack(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $delay = (int) (((array) config('shop.email.retry_minutes', [5]))[0] ?? 5);
        OutboundEmail::whereIn('id', $ids)->where('status', OutboundEmail::STATUS_SENDING)
            ->update(['status' => OutboundEmail::STATUS_PENDING, 'next_attempt_at' => now()->addMinutes($delay), 'updated_at' => now()]);
    }

    /** @return 'sent'|'retry'|'failed'|'cancelled'|'down' */
    private function sendOne(OutboundEmail $email): string
    {
        $order = $email->shop_order_id !== null ? ShopOrder::find($email->shop_order_id) : null;
        if ($order !== null && ! $order->delivers()) {
            $email->forceFill(['status' => OutboundEmail::STATUS_CANCELLED, 'next_attempt_at' => null, 'last_error' => 'Order no longer delivers (refunded)'])->save();

            return 'cancelled';
        }
        $attempt = $email->attempts + 1;
        try {
            $attachments = $this->attachments($email);
            $code = $this->voucherCode($email);
            $html = str_replace(ShopEmailComposer::CODE_TOKEN, e($code), (string) $email->html);
            $text = str_replace(ShopEmailComposer::CODE_TOKEN, $code, (string) $email->text);
            Mail::html($html, function (Message $m) use ($email, $attachments, $text): void {
                $m->to($email->to_address, $email->to_name)
                    ->from((string) config('shop.email.from_address'), (string) config('shop.email.from_name'))
                    ->replyTo((string) config('shop.email.reply_to'))
                    ->subject($email->subject);
                if ($text !== '') {
                    $m->getSymfonyMessage()->text($text);
                }
                foreach ($attachments as $a) {
                    $m->attachData($a['data'], $a['name'], ['mime' => $a['mime']]);
                }
            });
        } catch (Throwable $e) {
            if (self::isServerDown($e)) {
                $this->failed($email, $attempt, $e, alert: false);
                if (Cache::add('shop:smtp-down-alert', 1, now()->addHour())) {
                    SendShopAlert::dispatch(SendShopAlert::REASON_SMTP_DOWN, null, $email->id, class_basename($e).' '.(int) $e->getCode());
                }

                return 'down';
            }

            return $this->failed($email, $attempt, $e);
        }

        $email->forceFill([
            'status' => OutboundEmail::STATUS_SENT,
            'attempts' => $attempt,
            'sent_at' => now(),
            'next_attempt_at' => null,
            'last_error' => null,
        ])->save();
        Log::info('Email sent', ['email_id' => $email->id, 'kind' => $email->kind, 'attempt' => $attempt]);

        return 'sent';
    }

    /** @return 'retry'|'failed' */
    private function failed(OutboundEmail $email, int $attempt, Throwable $e, bool $alert = true): string
    {
        $delays = (array) config('shop.email.retry_minutes', [5, 30, 120, 360, 1440]);
        $permanent = self::isPermanent($e);
        $error = mb_substr(class_basename($e).': '.$e->getMessage(), 0, 1000);

        if ($permanent || $attempt > count($delays)) {
            $email->forceFill([
                'status' => OutboundEmail::STATUS_FAILED,
                'attempts' => $attempt,
                'failed_at' => now(),
                'next_attempt_at' => null,
                'last_error' => $error,
                'alerted_at' => now(),
            ])->save();
            Log::warning('Email failed for good', ['email_id' => $email->id, 'attempt' => $attempt, 'permanent' => $permanent]);
            if ($alert || $attempt > count($delays)) {
                SendShopAlert::dispatch(SendShopAlert::REASON_EMAIL_FAILED, $email->shop_order_id, $email->id);
            }

            return 'failed';
        }

        $email->forceFill([
            'status' => OutboundEmail::STATUS_PENDING,
            'attempts' => $attempt,
            'next_attempt_at' => now()->addMinutes((int) $delays[$attempt - 1]),
            'last_error' => $error,
        ])->save();
        Log::info('Email will be retried', ['email_id' => $email->id, 'attempt' => $attempt]);

        return 'retry';
    }

    /**
     * The recipient was refused (550 / 551 / 553 or an enhanced 5.1.x status — no such mailbox, bad
     * address): waiting will not help. Server-level 5xx (530 / 535 authentication, 554 relay) are not
     * permanent for the e-mail — see isServerDown().
     */
    public static function isPermanent(Throwable $e): bool
    {
        if (self::isServerDown($e)) {
            return false;
        }
        if (in_array((int) $e->getCode(), [550, 551, 553], true)) {
            return true;
        }

        return (bool) preg_match('/\b(?:got code\D{0,3}"?(?:550|551|553)\b|5\.1\.\d{1,3}\b)/i', $e->getMessage());
    }

    /** Our mail server cannot be reached or refuses us (not the recipient): connection, 421, 530 / 535 auth, 454. */
    public static function isServerDown(Throwable $e): bool
    {
        $code = (int) $e->getCode();
        if (in_array($code, [421, 454, 530, 534, 535], true)) {
            return true;
        }
        $m = $e->getMessage();

        return (bool) preg_match('/Connection could not be established|Failed to authenticate|Unable to connect|Connection refused|timed out|got code\D{0,3}"?(?:421|454|530|534|535)\b/i', $m);
    }

    /** The decrypted voucher code for CODE_TOKEN (the row's voucher attachment names the voucher). */
    private function voucherCode(OutboundEmail $email): string
    {
        if (! str_contains((string) $email->html, ShopEmailComposer::CODE_TOKEN) && ! str_contains((string) $email->text, ShopEmailComposer::CODE_TOKEN)) {
            return '';
        }
        foreach ((array) $email->attachments as $a) {
            if (($a['type'] ?? null) === 'voucher_pdf' && ($v = GiftVoucher::find((int) ($a['voucher_id'] ?? 0))) !== null) {
                return $v->code();
            }
        }
        throw new \RuntimeException('Voucher of the e-mail not found');
    }

    /**
     * @return list<array{data: string, name: string, mime: string}>
     */
    private function attachments(OutboundEmail $email): array
    {
        $out = [];
        foreach ((array) $email->attachments as $a) {
            if (($a['type'] ?? null) === 'voucher_pdf') {
                $voucher = GiftVoucher::find((int) ($a['voucher_id'] ?? 0));
                if ($voucher !== null) {
                    $out[] = [
                        'data' => $this->vouchers->render($voucher, $email->locale),
                        'name' => VoucherPdfRenderer::fileName($email->locale),
                        'mime' => 'application/pdf',
                    ];
                }
            }
        }

        return $out;
    }
}
