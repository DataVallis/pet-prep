<?php

namespace App\Services\Alerts;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use LogicException;
use Throwable;

/**
 * Posts admin alerts to the admin Discord channel (M5-T19, David 2026-10-24:
 * admin alerts go to Discord instead of e-mail).
 *
 *  - URL from `DISCORD_ALERTS_WEBHOOK_URL` (config services.discord.alerts_webhook_url,
 *    a secret): empty → not configured, nothing is sent and no HTTP happens.
 *  - One POST with a short `content` line + one embed (colour by severity,
 *    fields with the amounts, link to the admin page). Mentions are disabled.
 *  - Timeout 10 s. HTTP 429 → waits Discord's `retry_after` (body, else the
 *    Retry-After header) and retries, at most MAX_RETRIES times and only when
 *    the wait is ≤ MAX_RETRY_WAIT_SECONDS.
 *  - Never inside a DB transaction (LogicException) — call it from a queued
 *    job (CheckAiBudgetAlerts) or an admin action.
 *  - Errors are thrown as DiscordWebhookException with a message that never
 *    contains the webhook URL (the underlying exception — whose message may
 *    contain it — is not chained), so report() and System health stay clean.
 */
class DiscordWebhook
{
    public const TIMEOUT_SECONDS = 10;

    public const MAX_RETRIES = 2;

    public const MAX_RETRY_WAIT_SECONDS = 10.0;

    public function configured(): bool
    {
        return $this->url() !== null;
    }

    /**
     * @throws DiscordWebhookException
     */
    public function send(AdminAlert $alert): void
    {
        $url = $this->url();
        if ($url === null) {
            throw new DiscordWebhookException('Discord webhook is not configured (DISCORD_ALERTS_WEBHOOK_URL).');
        }

        if (DB::transactionLevel() > (int) config('media.ambient_transaction_level', 0)) {
            throw new LogicException('DiscordWebhook must not be called inside a database transaction — send from a queued job.');
        }

        $payload = $this->payload($alert);

        for ($attempt = 0; ; $attempt++) {
            try {
                $response = Http::timeout(self::TIMEOUT_SECONDS)
                    ->connectTimeout(5)
                    ->acceptJson()
                    ->asJson()
                    ->post($url, $payload);
            } catch (Throwable $e) {
                // The message of a connection error contains the URL — never pass it on.
                throw new DiscordWebhookException('Discord webhook request failed ('.class_basename($e).').');
            }

            if ($response->successful()) {
                return;
            }

            if ($response->status() === 429 && $attempt < self::MAX_RETRIES) {
                $wait = $this->retryAfter($response);
                if ($wait !== null && $wait <= self::MAX_RETRY_WAIT_SECONDS) {
                    Sleep::for((int) ceil($wait * 1000))->milliseconds();

                    continue;
                }
            }

            $message = $response->json('message');

            throw new DiscordWebhookException(sprintf(
                'Discord webhook answered HTTP %d%s.',
                $response->status(),
                is_string($message) && $message !== '' ? ' ('.mb_substr($message, 0, 100).')' : '',
            ));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(AdminAlert $alert): array
    {
        $fields = [];
        foreach ($alert->fields as $name => $value) {
            $fields[] = ['name' => mb_substr((string) $name, 0, 256), 'value' => mb_substr($value !== '' ? $value : '—', 0, 1024), 'inline' => true];
        }

        return [
            'username' => 'PetPrep admin',
            'content' => mb_substr($alert->title, 0, 2000),
            'allowed_mentions' => ['parse' => []],
            'embeds' => [[
                'title' => mb_substr($alert->title, 0, 256),
                'description' => mb_substr($alert->body, 0, 4096),
                'url' => $alert->url(),
                'color' => $alert->kind->colour(),
                'fields' => array_slice($fields, 0, 25),
                'footer' => ['text' => 'PetPrep · '.config('app.env').' · '.$alert->kind->value],
                'timestamp' => now()->utc()->toIso8601String(),
            ]],
        ];
    }

    private function url(): ?string
    {
        $url = trim((string) config('services.discord.alerts_webhook_url', ''));

        return str_starts_with($url, 'https://') ? $url : null;
    }

    private function retryAfter(Response $response): ?float
    {
        $body = $response->json('retry_after');
        if (is_numeric($body)) {
            return max(0.0, (float) $body);
        }

        $header = $response->header('Retry-After');

        return is_numeric($header) ? max(0.0, (float) $header) : null;
    }
}
