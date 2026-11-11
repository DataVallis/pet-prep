<?php

namespace App\Services\LemonSqueezy;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;

/**
 * The only place that talks HTTP to Lemon Squeezy (M6-S01, CONTRACT §1 L1–L6, L16).
 * Never inside a DB transaction (throws, like ExpoPushClient). Base URL from
 * `services.lemonsqueezy.api_base`; timeout 10 s, connect 5 s, no retries.
 *
 *  - validate() / activate(): public License API (form-encoded, no API key — the
 *    license key is the secret). A 4xx answer is parsed (unknown key → no
 *    `license_key.id`); connection error, timeout, 429 or 5xx →
 *    LemonSqueezyUpstreamException.
 *  - instances(): Admin API (JSON:API, Bearer `services.lemonsqueezy.api_key`),
 *    crash recovery only. Any non-2xx → LemonSqueezyUpstreamException.
 *
 * The caller checks store / product / variant / test mode (matches()); this class
 * never logs the license key or the API key.
 */
class LemonSqueezyLicenseClient
{
    public function validate(string $licenseKey, ?string $instanceId = null): LicenseResponse
    {
        $form = ['license_key' => $licenseKey];
        if ($instanceId !== null) {
            $form['instance_id'] = $instanceId;
        }

        return $this->license('/v1/licenses/validate', $form);
    }

    public function activate(string $licenseKey, string $instanceName): LicenseResponse
    {
        return $this->license('/v1/licenses/activate', ['license_key' => $licenseKey, 'instance_name' => $instanceName]);
    }

    /**
     * Instances of a license key (L6): [{identifier, name}].
     *
     * @return list<array{identifier: string, name: string}>
     *
     * @throws LemonSqueezyUpstreamException
     */
    public function instances(int $licenseKeyId): array
    {
        $this->assertOutsideTransaction();
        $apiKey = (string) config('services.lemonsqueezy.api_key');
        if ($apiKey === '') {
            throw new LogicException('LEMONSQUEEZY_API_KEY is not set.');
        }

        try {
            $response = $this->http()
                ->withToken($apiKey)
                ->accept('application/vnd.api+json')
                ->get($this->base().'/v1/license-key-instances', ['filter[license_key_id]' => $licenseKeyId]);
        } catch (ConnectionException) {
            throw new LemonSqueezyUpstreamException('Lemon Squeezy Admin API unreachable.');
        }
        if (! $response->successful()) {
            throw new LemonSqueezyUpstreamException('Lemon Squeezy Admin API answered '.$response->status().'.');
        }

        $out = [];
        foreach ((array) ($response->json('data') ?? []) as $row) {
            $attrs = is_array($row) && is_array($row['attributes'] ?? null) ? $row['attributes'] : [];
            if (isset($attrs['identifier'], $attrs['name']) && is_scalar($attrs['identifier']) && is_scalar($attrs['name'])) {
                $out[] = ['identifier' => (string) $attrs['identifier'], 'name' => (string) $attrs['name']];
            }
        }

        return $out;
    }

    /**
     * Is this answer about OUR gift product (L15)? Store and product must match;
     * the variant only when LEMONSQUEEZY_GIFT_VARIANT_ID is set.
     */
    public static function matches(LicenseResponse $r): bool
    {
        $store = LicenseResponse::int((string) config('services.lemonsqueezy.store_id'));
        $product = LicenseResponse::int((string) config('services.lemonsqueezy.gift_product_id'));
        $variant = LicenseResponse::int((string) config('services.lemonsqueezy.gift_variant_id'));

        return $store !== null && $product !== null
            && $r->storeId === $store && $r->productId === $product
            && ($variant === null || $r->variantId === $variant);
    }

    /**
     * @param  array<string, string>  $form
     */
    private function license(string $path, array $form): LicenseResponse
    {
        $this->assertOutsideTransaction();

        try {
            $response = $this->http()->asForm()->accept('application/json')->post($this->base().$path, $form);
        } catch (ConnectionException) {
            throw new LemonSqueezyUpstreamException('Lemon Squeezy License API unreachable.');
        }

        return $this->parse($response);
    }

    private function parse(Response $response): LicenseResponse
    {
        $status = $response->status();
        if ($status === 429 || $status >= 500) {
            throw new LemonSqueezyUpstreamException('Lemon Squeezy License API answered '.$status.'.');
        }
        $json = $response->json();
        if (! is_array($json)) {
            if ($status >= 400) {
                // A 4xx without a JSON body: not a key we know of.
                return LicenseResponse::fromJson($status, []);
            }
            throw new LemonSqueezyUpstreamException('Lemon Squeezy License API answered without JSON.');
        }

        return LicenseResponse::fromJson($status, $json);
    }

    private function http(): PendingRequest
    {
        return Http::timeout((int) config('services.lemonsqueezy.timeout_seconds', 10))
            ->connectTimeout((int) config('services.lemonsqueezy.connect_timeout_seconds', 5));
    }

    private function base(): string
    {
        return rtrim((string) config('services.lemonsqueezy.api_base', 'https://api.lemonsqueezy.com'), '/');
    }

    private function assertOutsideTransaction(): void
    {
        // Rule: no external HTTP inside a DB transaction. Tests run inside
        // RefreshDatabase's transaction, recorded as the ambient level (TestCase).
        if (DB::transactionLevel() > (int) config('media.ambient_transaction_level', 0)) {
            throw new LogicException('LemonSqueezyLicenseClient must not be called inside a database transaction.');
        }
    }
}
