<?php

namespace App\Services\Gift;

/**
 * Outcome of POST /api/gift/redeem (CONTRACT §5.1). `error` null = redeemed.
 */
final class GiftRedeemResult
{
    public const INVALID_REQUEST = 'invalid_request';

    public const INVALID_CODE = 'invalid_code';

    public const EXPIRED = 'expired';

    public const REFUNDED = 'refunded';

    public const NO_ACCOUNT = 'no_account';

    public const ALREADY_REDEEMED = 'already_redeemed';

    public const RATE_LIMITED = 'rate_limited';

    public const UPSTREAM_ERROR = 'upstream_error';

    public const UNAVAILABLE = 'unavailable';

    /** error code → HTTP status */
    public const HTTP = [
        self::INVALID_REQUEST => 422,
        self::INVALID_CODE => 422,
        self::EXPIRED => 422,
        self::REFUNDED => 422,
        self::NO_ACCOUNT => 422,
        self::ALREADY_REDEEMED => 409,
        self::RATE_LIMITED => 429,
        self::UPSTREAM_ERROR => 502,
        self::UNAVAILABLE => 503,
    ];

    private function __construct(
        public readonly ?string $error,
        public readonly bool $already = false,
    ) {}

    public static function redeemed(bool $already): self
    {
        return new self(null, $already);
    }

    public static function error(string $code): self
    {
        return new self($code);
    }

    public function httpStatus(): int
    {
        return $this->error === null ? 200 : self::HTTP[$this->error];
    }
}
