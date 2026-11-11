<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * POST /api/web/parent-session refused (M6-S01b, CONTRACT_WEB_SIGNIN §1.1).
 * `error` is the stable code the website maps; rendered by
 * WebParentSessionController as `{error, message}` (message: lang/{en,sl}/web_session.php).
 */
class WebSessionException extends RuntimeException
{
    public const INVALID_REQUEST = 'invalid_request';

    public const INVALID_CREDENTIALS = 'invalid_credentials';

    public const INVALID_TOKEN = 'invalid_token';

    public const ACCOUNT_NOT_FOUND = 'account_not_found';

    public const NOT_PARENT = 'not_parent';

    public const RATE_LIMITED = 'rate_limited';

    public const PROVIDER_DISABLED = 'provider_disabled';

    public const PROVIDER_UNAVAILABLE = 'provider_unavailable';

    public const UNAVAILABLE = 'unavailable';

    /** error → HTTP status */
    public const HTTP = [
        self::INVALID_REQUEST => 422,
        self::INVALID_CREDENTIALS => 401,
        self::INVALID_TOKEN => 401,
        self::ACCOUNT_NOT_FOUND => 404,
        self::NOT_PARENT => 403,
        self::RATE_LIMITED => 429,
        self::PROVIDER_DISABLED => 503,
        self::PROVIDER_UNAVAILABLE => 503,
        self::UNAVAILABLE => 503,
    ];

    public function __construct(
        public readonly string $error,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($error);
    }

    public function status(): int
    {
        return self::HTTP[$this->error];
    }

    /** The app's verifier refusals, re-coded for the website (the app's own rendering is untouched). */
    public static function fromSocial(SocialAuthException $e): self
    {
        return new self(match ($e->reason) {
            SocialAuthException::PROVIDER_DISABLED => self::PROVIDER_DISABLED,
            SocialAuthException::PROVIDER_UNAVAILABLE => self::PROVIDER_UNAVAILABLE,
            default => self::INVALID_TOKEN,
        });
    }
}
