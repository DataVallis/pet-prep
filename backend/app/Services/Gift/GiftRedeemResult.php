<?php

namespace App\Services\Gift;

/**
 * Outcome of POST /api/gift/redeem (CONTRACT §5.1, amended by CONTRACT_WEB_SIGNIN §3.3).
 * `error` null = redeemed. `unauthenticated` / `not_parent` come from RequireWebSession
 * (and `unauthenticated` from the commit when the family is gone).
 */
final class GiftRedeemResult
{
    public const INVALID_REQUEST = 'invalid_request';

    public const INVALID_CODE = 'invalid_code';

    public const EXPIRED = 'expired';

    public const REFUNDED = 'refunded';

    public const UNAUTHENTICATED = 'unauthenticated';

    public const NOT_PARENT = 'not_parent';

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
        self::UNAUTHENTICATED => 401,
        self::NOT_PARENT => 403,
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
