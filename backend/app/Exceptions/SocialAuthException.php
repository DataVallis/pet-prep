<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Social sign-in refused (M2-10c). `code` is the stable machine-readable
 * reason for the app: `invalid_token` (401), `provider_disabled` (503),
 * `provider_unavailable` (503), `terms_required` (422), `email_in_use` (409,
 * the address belongs to an account with a password), `email_link_unavailable`
 * (409, the address belongs to an account that uses another sign-in method and
 * cannot be linked from this token),
 * `not_a_parent` (403), `superadmin_protected` (403).
 */
class SocialAuthException extends RuntimeException
{
    public const INVALID_TOKEN = 'invalid_token';

    public const PROVIDER_DISABLED = 'provider_disabled';

    public const PROVIDER_UNAVAILABLE = 'provider_unavailable';

    public const TERMS_REQUIRED = 'terms_required';

    public const EMAIL_IN_USE = 'email_in_use';

    public const EMAIL_LINK_UNAVAILABLE = 'email_link_unavailable';

    public const NOT_A_PARENT = 'not_a_parent';

    public const SUPERADMIN_PROTECTED = 'superadmin_protected';

    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function invalidToken(string $why = 'The sign-in token is not valid.'): self
    {
        return new self(self::INVALID_TOKEN, $why, 401);
    }

    public static function providerDisabled(): self
    {
        return new self(self::PROVIDER_DISABLED, 'This sign-in method is not available.', 503);
    }

    public static function providerUnavailable(): self
    {
        return new self(self::PROVIDER_UNAVAILABLE, 'The sign-in provider could not be reached. Please try again.', 503);
    }

    /** `{message, code}` with the status. */
    public function toResponse(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->reason], $this->status);
    }

    /** Rendered by the exception handler (keeps the controller's OpenAPI 200 typed). */
    public function render(): JsonResponse
    {
        return $this->toResponse();
    }

    /** An expected refusal, not an error: never logged by the handler (the verifier logs the reason). */
    public function report(): void {}
}
