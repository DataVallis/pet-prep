<?php

namespace App\Services\Media;

use App\Enums\AiCallFailure;
use App\Enums\AiSpendPurpose;
use App\Models\AiSpendLedger;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * The only class that sends requests to fal.ai (M4-02 / M4-07).
 *
 * Every call: profile enabled + API key → spend cap (AiSpendGuard::reserve,
 * fail closed) → HTTP → settle the ledger row. fal "exhausted balance"
 * (HTTP 402, or 401/403 mentioning balance / locked) becomes
 * AiCallFailure::FalBalance, logged once per hour and flagged for Filament.
 *
 * Call only from queued jobs, never inside a DB transaction (enforced: LogicException;
 * the ledger reservation commits before the HTTP request starts).
 *
 * Settlement (PR #22 review): void only when nothing reached fal (connect / DNS /
 * TLS failure before sending) or fal explicitly refused (4xx / 5xx answer);
 * a timeout or reset after sending keeps the cost (committed + http_error).
 */
class FalGateway
{
    public const SYNC_BASE_URL = 'https://fal.run';

    public const QUEUE_BASE_URL = 'https://queue.fal.run';

    public const BALANCE_FLAG_KEY = 'ai:fal_balance_exhausted_at';

    private const BALANCE_LOG_KEY = 'ai:fal_balance_logged';

    public function __construct(private readonly AiSpendGuard $guard) {}

    public function isEnabled(): bool
    {
        return filled(config('services.fal_ai.key'));
    }

    /**
     * Synchronous call (images): https://fal.run/{endpoint}. Returns the JSON body and latency.
     *
     * @param  array<string, mixed>  $input
     * @return array{body: array<string, mixed>, latency_ms: int, cost_usd: float}
     *
     * @throws AiCallException
     */
    public function run(ModelProfile $profile, array $input, AiSpendPurpose $purpose, ?int $petId = null, ?int $labResultId = null, int $timeoutSeconds = 60, ?int $petMediaId = null): array
    {
        $this->assertCallable($profile);
        $entry = $this->guard->reserve($profile, $purpose, $petId, $labResultId, $petMediaId);

        $started = hrtime(true);

        try {
            $response = $this->http()->timeout($timeoutSeconds)->post(self::SYNC_BASE_URL.'/'.$profile->endpoint, $input);
        } catch (Throwable $e) {
            throw $this->transportFailure($e, $entry, $profile);
        }

        $latencyMs = (int) round((hrtime(true) - $started) / 1_000_000);

        $this->throwIfFailed($response, $profile, $entry);
        $this->guard->commit($entry, $response->header('x-fal-request-id') ?: null);
        Cache::forget(self::BALANCE_FLAG_KEY);

        $body = $response->json();

        return ['body' => is_array($body) ? $body : [], 'latency_ms' => $latencyMs, 'cost_usd' => (float) $entry->cost_usd];
    }

    /**
     * Queue submission (videos): https://queue.fal.run/{endpoint}?fal_webhook=…
     *
     * @param  array<string, mixed>  $input
     * @return array{request_id: string, status_url: string|null, response_url: string|null, cost_usd: float}
     *
     * @throws AiCallException
     */
    public function submit(ModelProfile $profile, array $input, AiSpendPurpose $purpose, string $webhookUrl, ?int $petId = null, ?int $labResultId = null, ?int $petMediaId = null): array
    {
        $this->assertCallable($profile);
        $entry = $this->guard->reserve($profile, $purpose, $petId, $labResultId, $petMediaId);

        try {
            $response = $this->http()
                ->withQueryParameters(['fal_webhook' => $webhookUrl])
                ->post(self::QUEUE_BASE_URL.'/'.$profile->endpoint, $input);
        } catch (Throwable $e) {
            throw $this->transportFailure($e, $entry, $profile);
        }

        $this->throwIfFailed($response, $profile, $entry);

        $requestId = $response->json('request_id');

        if (! is_string($requestId) || $requestId === '') {
            // fal accepted (2xx) — it may run and bill the job even though we cannot track it.
            $this->guard->commit($entry, null, AiCallFailure::InvalidResponse);

            throw new AiCallException(AiCallFailure::InvalidResponse, 'fal.ai queue answer had no request_id.', (float) $entry->cost_usd, true);
        }

        $this->guard->commit($entry, $requestId);
        Cache::forget(self::BALANCE_FLAG_KEY);

        return [
            'request_id' => $requestId,
            'status_url' => $this->queueUrlOrNull($response->json('status_url')),
            'response_url' => $this->queueUrlOrNull($response->json('response_url')),
            'cost_usd' => (float) $entry->cost_usd,
        ];
    }

    /**
     * Poll a queued request (fallback when the webhook cannot reach us, e.g. local dev).
     * No spend: status reads are free.
     *
     * @return array{state: 'pending'|'completed'|'failed', body: array<string, mixed>, error: string|null}
     */
    public function poll(string $statusUrl, string $responseUrl): array
    {
        if (! $this->isEnabled() || $this->queueUrlOrNull($statusUrl) === null || $this->queueUrlOrNull($responseUrl) === null) {
            return ['state' => 'failed', 'body' => [], 'error' => 'Polling not possible (disabled or untrusted URL).'];
        }

        try {
            $status = $this->http()->timeout(20)->get($statusUrl);

            if (! $status->successful()) {
                return ['state' => 'pending', 'body' => [], 'error' => 'status HTTP '.$status->status()];
            }

            if ($status->json('status') !== 'COMPLETED') {
                return ['state' => 'pending', 'body' => [], 'error' => null];
            }

            $result = $this->http()->timeout(30)->get($responseUrl);
        } catch (Throwable $e) {
            return ['state' => 'pending', 'body' => [], 'error' => $e->getMessage()];
        }

        if (! $result->successful()) {
            $detail = $result->json('detail');

            return ['state' => 'failed', 'body' => [], 'error' => is_string($detail) ? $detail : 'result HTTP '.$result->status()];
        }

        $body = $result->json();

        return ['state' => 'completed', 'body' => is_array($body) ? $body : [], 'error' => null];
    }

    /**
     * cURL errors that happen before a single byte of the request reached fal:
     * proxy / DNS resolution, TCP connect, TLS handshake / certificate.
     */
    private const NOT_SENT_CURL_ERRORS = [5, 6, 7, 35, 51, 58, 60];

    /**
     * True only when we are sure fal never received the request. A timeout or a
     * reset after sending (cURL 28 "Operation timed out", 52, 56, …) may still
     * be run and billed by fal, so the cost must keep counting.
     */
    public static function failedBeforeSend(Throwable $e): bool
    {
        $message = $e->getMessage();

        if (preg_match('/cURL error (\d+)/', $message, $m) === 1) {
            $code = (int) $m[1];

            if (in_array($code, self::NOT_SENT_CURL_ERRORS, true)) {
                return true;
            }

            // 28 is both "Resolving / Connection timed out" (not sent) and "Operation timed out" (sent).
            return $code === 28 && preg_match('/(Resolving|Connection) timed out/i', $message) === 1;
        }

        return preg_match('/Could not resolve host|Failed to connect|Connection refused/i', $message) === 1;
    }

    private function transportFailure(Throwable $e, AiSpendLedger $entry, ModelProfile $profile): AiCallException
    {
        if (self::failedBeforeSend($e)) {
            $this->guard->void($entry, AiCallFailure::HttpError);
            Log::error('FalGateway: request did not reach fal.ai', ['profile' => $profile->key, 'error' => $e->getMessage()]);

            return new AiCallException(AiCallFailure::HttpError, 'fal.ai not reachable: '.$e->getMessage());
        }

        // Sent, answer lost: fal may still run (and bill) it — keep the cost.
        $this->guard->commit($entry, null, AiCallFailure::HttpError);
        Log::error('FalGateway: request sent but no answer (timeout / reset) — cost kept', ['profile' => $profile->key, 'error' => $e->getMessage()]);

        return new AiCallException(AiCallFailure::HttpError, 'fal.ai did not answer: '.$e->getMessage(), (float) $entry->cost_usd, true);
    }

    /**
     * fal answers an exhausted balance with 402, or 401/403 and a message such as
     * "User is locked. Reason: Exhausted balance."
     */
    public static function isBalanceError(Response $response): bool
    {
        if ($response->status() === 402) {
            return true;
        }

        if (! in_array($response->status(), [401, 403], true)) {
            return false;
        }

        $body = strtolower($response->body());

        return str_contains($body, 'balance') || str_contains($body, 'exhausted') || str_contains($body, 'locked');
    }

    public static function balanceExhaustedAt(): ?string
    {
        $value = Cache::get(self::BALANCE_FLAG_KEY);

        return is_string($value) ? $value : null;
    }

    private function assertCallable(ModelProfile $profile): void
    {
        // Rule: no external HTTP inside a DB transaction (PR #22 review). Tests run inside
        // RefreshDatabase's transaction, which TestCase records as the ambient level.
        if (DB::transactionLevel() > (int) config('media.ambient_transaction_level', 0)) {
            throw new LogicException('FalGateway must not be called inside a database transaction — dispatch a queued job after commit.');
        }

        if (! $this->isEnabled() || ! $profile->enabled) {
            throw new AiCallException(AiCallFailure::Disabled);
        }
    }

    /**
     * @throws AiCallException
     */
    private function throwIfFailed(Response $response, ModelProfile $profile, AiSpendLedger $entry): void
    {
        if ($response->successful()) {
            return;
        }

        if (self::isBalanceError($response)) {
            $this->guard->void($entry, AiCallFailure::FalBalance);
            Cache::put(self::BALANCE_FLAG_KEY, now()->toIso8601String(), now()->addDay());

            if (Cache::add(self::BALANCE_LOG_KEY, true, now()->addHour())) {
                Log::critical('FalGateway: fal.ai balance exhausted — AI media paused until top-up', [
                    'profile' => $profile->key,
                    'status' => $response->status(),
                ]);
            }

            throw new AiCallException(AiCallFailure::FalBalance);
        }

        $this->guard->void($entry, AiCallFailure::HttpError);
        Log::error('FalGateway: fal.ai answered with an error', ['profile' => $profile->key, 'status' => $response->status()]);

        throw new AiCallException(AiCallFailure::HttpError, 'fal.ai HTTP '.$response->status());
    }

    private function queueUrlOrNull(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $parts = parse_url($url);

        return ($parts !== false && ($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') === 'queue.fal.run' && ! isset($parts['user']) && ! isset($parts['port']))
            ? $url
            : null;
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => 'Key '.config('services.fal_ai.key'),
        ])->acceptJson()->asJson()->timeout(120);
    }
}
