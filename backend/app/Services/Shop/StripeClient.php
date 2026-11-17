<?php

namespace App\Services\Shop;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;

/**
 * The only Stripe HTTP of the backend (M6-S09). Plain REST with Laravel's HTTP
 * client — no SDK (packagist is unreachable from the cloud agents and the calls are
 * few). Form-encoded bodies, `Stripe-Version` pinned to config('services.stripe.api_version').
 * Never inside a DB transaction; never logs the key or a buyer field.
 */
class StripeClient
{
    public function configured(): bool
    {
        return (string) config('services.stripe.secret_key') !== '';
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function createCheckoutSession(array $params, ?string $idempotencyKey = null): array
    {
        $request = $this->http();
        if ($idempotencyKey !== null) {
            $request = $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
        }

        return $this->decode($this->send(fn () => $request->asForm()->post('/v1/checkout/sessions', $params)));
    }

    /**
     * A Checkout Session with what fulfilment needs (line items + price, customer details, payment intent id).
     *
     * @return array<string, mixed>|null null = no such session (404)
     */
    public function retrieveCheckoutSession(string $sessionId): ?array
    {
        $response = $this->send(fn () => $this->http()->get('/v1/checkout/sessions/'.rawurlencode($sessionId), [
            'expand' => ['line_items'],
        ]));
        if ($response->status() === 404) {
            return null;
        }

        return $this->decode($response);
    }

    /** @return array<string, mixed>|null */
    public function retrievePrice(string $priceId): ?array
    {
        $response = $this->send(fn () => $this->http()->get('/v1/prices/'.rawurlencode($priceId), ['expand' => ['product']]));
        if ($response->status() === 404) {
            return null;
        }

        return $this->decode($response);
    }

    /** @return array<string, mixed>|null */
    public function retrievePaymentIntent(string $id): ?array
    {
        $response = $this->send(fn () => $this->http()->get('/v1/payment_intents/'.rawurlencode($id)));
        if ($response->status() === 404) {
            return null;
        }

        return $this->decode($response);
    }

    private function http(): PendingRequest
    {
        if (! $this->configured()) {
            throw new StripeException('Stripe is not configured (STRIPE_SECRET_KEY).');
        }

        return Http::baseUrl((string) config('services.stripe.api_base'))
            ->withToken((string) config('services.stripe.secret_key'))
            ->withHeaders(['Stripe-Version' => (string) config('services.stripe.api_version')])
            ->acceptJson()
            ->timeout((int) config('services.stripe.timeout_seconds', 15))
            ->connectTimeout((int) config('services.stripe.connect_timeout_seconds', 5));
    }

    /**
     * @param  callable(): Response  $call
     */
    private function send(callable $call): Response
    {
        if (DB::transactionLevel() > (int) config('media.ambient_transaction_level', 0)) {
            throw new LogicException('StripeClient must not be called inside a DB transaction.');
        }
        try {
            return $call();
        } catch (ConnectionException $e) {
            // The message of a connection error contains the request URL (with the session id): redact it.
            throw new StripeException('Stripe unreachable: '.(string) preg_replace('/\\b(cs|pi)_[A-Za-z0-9_]+/', '$1_…', $e->getMessage()));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $json = $response->json();
        if (! $response->successful() || ! is_array($json)) {
            $type = is_array($json) ? (string) data_get($json, 'error.type', '') : '';
            $code = is_array($json) ? (string) data_get($json, 'error.code', '') : '';
            throw new StripeException(trim('Stripe HTTP '.$response->status().' '.$type.' '.$code));
        }

        return $json;
    }
}
