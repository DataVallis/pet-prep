<?php

namespace App\Services\Mail;

use App\Jobs\SendShopAlert;
use App\Models\GiftVoucher;
use App\Models\OutboundEmail;
use App\Services\Shop\VoucherPdfRenderer;
use Illuminate\Mail\Message;
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
 *  - a permanent SMTP refusal (5xx — no such mailbox, refused domain) → `failed` at once;
 *  - anything else → `pending` again after config('shop.email.retry_minutes')[attempts − 1];
 *    after the last one → `failed`.
 * Every `failed` row gets ONE admin alert (Discord, ids only). A row stuck in `sending`
 * (crashed run) goes back to `pending`. No send happens inside a DB transaction.
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
        foreach ($ids as $id) {
            $email = OutboundEmail::find($id);
            if ($email !== null) {
                $counts[$this->sendOne($email)]++;
            }
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

    private function releaseStale(): void
    {
        OutboundEmail::where('status', OutboundEmail::STATUS_SENDING)
            ->where('updated_at', '<', now()->subMinutes((int) config('shop.email.stale_sending_minutes', 15)))
            ->update(['status' => OutboundEmail::STATUS_PENDING, 'next_attempt_at' => now(), 'updated_at' => now()]);
    }

    /** @return 'sent'|'retry'|'failed' */
    private function sendOne(OutboundEmail $email): string
    {
        $attempt = $email->attempts + 1;
        try {
            $attachments = $this->attachments($email);
            Mail::html($email->html, function (Message $m) use ($email, $attachments): void {
                $m->to($email->to_address, $email->to_name)
                    ->from((string) config('shop.email.from_address'), (string) config('shop.email.from_name'))
                    ->replyTo((string) config('shop.email.reply_to'))
                    ->subject($email->subject);
                if ($email->text !== null && $email->text !== '') {
                    $m->getSymfonyMessage()->text($email->text);
                }
                foreach ($attachments as $a) {
                    $m->attachData($a['data'], $a['name'], ['mime' => $a['mime']]);
                }
            });
        } catch (Throwable $e) {
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
    private function failed(OutboundEmail $email, int $attempt, Throwable $e): string
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
            SendShopAlert::dispatch(SendShopAlert::REASON_EMAIL_FAILED, $email->shop_order_id, $email->id);

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

    /** An SMTP answer 5xx (e.g. 550 no such user) will not get better by waiting. */
    public static function isPermanent(Throwable $e): bool
    {
        $code = (int) $e->getCode();
        if ($code >= 500 && $code < 600) {
            return true;
        }

        return (bool) preg_match('/\b(?:code|response)\D{0,6}"?5\d\d\b/i', $e->getMessage());
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
