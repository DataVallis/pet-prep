<?php

namespace App\Services\Push;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;

/**
 * The only place that talks HTTP to the Expo Push Service (M3-02).
 * Called from queued jobs only, never inside a DB transaction.
 *
 * - send(): ≤ 100 messages → one ticket per message, in order.
 * - receipts(): ≤ 1000 ticket ids → receipt per id.
 *
 * Errors: connection problems, 429 and 5xx → ExpoPushException (retryable);
 * any other 4xx or an unreadable body → ExpoPushException (not retryable).
 */
class ExpoPushClient
{
    /**
     * @param  list<array<string, mixed>>  $messages
     * @return list<array{status: string, id?: string, message?: string, details?: array<string, mixed>}>
     */
    public function send(array $messages): array
    {
        if (count($messages) > (int) config('push.chunk_size', 100)) {
            throw new LogicException('Expo accepts at most 100 messages per request — chunk first.');
        }

        $response = $this->post((string) config('push.send_url'), $messages);
        $data = $response->json('data');

        if (! is_array($data) || ! array_is_list($data) || count($data) !== count($messages)) {
            throw new ExpoPushException('Expo send answered without one ticket per message.', retryable: false);
        }

        return $data;
    }

    /**
     * @param  list<string>  $ticketIds
     * @return array<string, array{status: string, message?: string, details?: array<string, mixed>}>
     */
    public function receipts(array $ticketIds): array
    {
        if ($ticketIds === []) {
            return [];
        }

        $response = $this->post((string) config('push.receipts_url'), ['ids' => array_values($ticketIds)]);
        $data = $response->json('data');

        if (! is_array($data)) {
            throw new ExpoPushException('Expo receipts answered without data.', retryable: false);
        }

        return $data;
    }

    /**
     * @param  array<int|string, mixed>  $body
     */
    private function post(string $url, array $body): Response
    {
        // Rule: no external HTTP inside a DB transaction. Tests run inside
        // RefreshDatabase's transaction, recorded as the ambient level (TestCase).
        if (DB::transactionLevel() > (int) config('media.ambient_transaction_level', 0)) {
            throw new LogicException('ExpoPushClient must not be called inside a database transaction — dispatch a queued job after commit.');
        }

        try {
            $response = $this->request()->post($url, $body);
        } catch (ConnectionException $e) {
            throw new ExpoPushException('Expo unreachable: '.$e->getMessage(), retryable: true, previous: $e);
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw new ExpoPushException("Expo answered HTTP {$response->status()}", retryable: true);
        }

        if (! $response->successful()) {
            $code = $response->json('errors.0.code');

            throw new ExpoPushException(
                "Expo rejected the request: HTTP {$response->status()}".(is_string($code) ? " {$code}" : ''),
                retryable: false,
            );
        }

        return $response;
    }

    private function request(): PendingRequest
    {
        $request = Http::acceptJson()
            ->asJson()
            ->timeout((int) config('push.http_timeout_seconds', 10))
            ->connectTimeout(5);

        $token = config('push.expo_access_token');
        if (is_string($token) && $token !== '') {
            $request = $request->withToken($token);
        }

        return $request;
    }
}
