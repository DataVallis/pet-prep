<?php

namespace App\Enums;

/**
 * Identity providers a parent can sign in with (M2-10c). Children never use
 * them (PIN only). Mirrored by the CHECK constraint on
 * `user_social_accounts.provider`.
 */
enum SocialProvider: string
{
    case Google = 'google';
    case Apple = 'apple';

    /** Where the provider publishes the public keys of its ID tokens. */
    public function jwksUrl(): string
    {
        return match ($this) {
            self::Google => (string) config('services.google.jwks_url', 'https://www.googleapis.com/oauth2/v3/certs'),
            self::Apple => (string) config('services.apple.jwks_url', 'https://appleid.apple.com/auth/keys'),
        };
    }

    /**
     * Accepted `iss` claims.
     *
     * @return list<string>
     */
    public function issuers(): array
    {
        return match ($this) {
            self::Google => ['https://accounts.google.com', 'accounts.google.com'],
            self::Apple => ['https://appleid.apple.com'],
        };
    }

    /**
     * Accepted `aud` claims (our client IDs). Empty → the provider is
     * disabled (503 `provider_disabled`).
     *
     * @return list<string>
     */
    public function audiences(): array
    {
        $configured = match ($this) {
            self::Google => config('services.google.client_ids', []),
            self::Apple => config('services.apple.audiences', []),
        };

        return array_values(array_filter(
            is_array($configured) ? $configured : [],
            fn ($aud): bool => is_string($aud) && trim($aud) !== '',
        ));
    }

    public function isEnabled(): bool
    {
        return $this->audiences() !== [];
    }

    /** Does the provider require a nonce in the ID token (Apple: yes)? */
    public function requiresNonce(): bool
    {
        return $this === self::Apple;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $p): string => $p->value, self::cases());
    }
}
