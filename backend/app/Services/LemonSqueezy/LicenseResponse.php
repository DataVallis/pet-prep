<?php

namespace App\Services\LemonSqueezy;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * One parsed License API answer (validate / activate, CONTRACT L2–L5). Only ids,
 * status, counters and dates are kept — never the key or the customer fields.
 */
final class LicenseResponse
{
    /** LS license key statuses we understand (L4). */
    public const KNOWN_STATUSES = ['inactive', 'active', 'expired', 'disabled'];

    public function __construct(
        public readonly int $httpStatus,
        public readonly bool $valid,
        public readonly bool $activated,
        public readonly ?int $licenseKeyId,
        public readonly ?string $status,
        public readonly ?int $activationLimit,
        public readonly int $activationUsage,
        public readonly ?Carbon $createdAt,
        public readonly bool $testMode,
        public readonly ?int $storeId,
        public readonly ?int $productId,
        public readonly ?int $variantId,
        public readonly ?int $orderId,
        public readonly ?string $instanceId,
    ) {}

    /**
     * @param  array<string, mixed>  $json
     */
    public static function fromJson(int $httpStatus, array $json): self
    {
        $key = is_array($json['license_key'] ?? null) ? $json['license_key'] : [];
        $meta = is_array($json['meta'] ?? null) ? $json['meta'] : [];
        $instance = is_array($json['instance'] ?? null) ? $json['instance'] : [];

        return new self(
            httpStatus: $httpStatus,
            valid: ($json['valid'] ?? false) === true,
            activated: ($json['activated'] ?? false) === true,
            licenseKeyId: self::int($key['id'] ?? null),
            status: is_string($key['status'] ?? null) ? $key['status'] : null,
            activationLimit: self::int($key['activation_limit'] ?? null),
            activationUsage: self::int($key['activation_usage'] ?? null) ?? 0,
            createdAt: self::date($key['created_at'] ?? null),
            testMode: ($key['test_mode'] ?? false) === true,
            storeId: self::int($meta['store_id'] ?? null),
            productId: self::int($meta['product_id'] ?? null),
            variantId: self::int($meta['variant_id'] ?? null),
            orderId: self::int($meta['order_id'] ?? null),
            instanceId: isset($instance['id']) && (is_string($instance['id']) || is_int($instance['id'])) ? (string) $instance['id'] : null,
        );
    }

    /** The key's activations are used up (limit null = 1, activation limit 1 per L13). */
    public function usedUp(): bool
    {
        return $this->activationUsage >= max(1, $this->activationLimit ?? 1);
    }

    public static function int(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && preg_match('/^\d+$/', $v) === 1) {
            return (int) $v;
        }

        return null;
    }

    public static function date(mixed $v): ?Carbon
    {
        if (! is_string($v) || $v === '') {
            return null;
        }
        try {
            return Carbon::parse($v)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
