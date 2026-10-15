<?php

namespace App\Services\Auth;

use App\Enums\SocialProvider;
use Carbon\CarbonImmutable;

/**
 * The verified claims of a provider ID token (M2-10c). Built only by
 * SocialTokenVerifier after signature, issuer, audience, expiry and (Apple)
 * nonce checks.
 */
final class SocialIdentity
{
    public function __construct(
        public readonly SocialProvider $provider,
        /** Stable provider user id (`sub`). */
        public readonly string $subject,
        /** Lower case; null when the token has none. */
        public readonly ?string $email,
        /** The provider says it verified the e-mail. */
        public readonly bool $emailVerified,
        /**
         * The provider is authoritative for the address, so it may be used to
         * find / link an existing account (QA M1): Apple when verified; Google
         * only when verified AND (@gmail.com / @googlemail.com or the token
         * carries `hd`, a Google Workspace domain). A verified non-Gmail
         * address of a consumer Google account is not proof of ownership.
         */
        public readonly bool $emailLinkable,
        /** Display name from the token (Google `name`); Apple never sends one. */
        public readonly ?string $name,
        /** `iat` — when the user authenticated with the provider. */
        public readonly CarbonImmutable $issuedAt,
        /** The token's `aud` that matched our configuration (Apple: the client id the app signed in with). */
        public readonly string $audience = '',
    ) {}

    /** The e-mail only when the provider verified it. */
    public function verifiedEmail(): ?string
    {
        return $this->emailVerified ? $this->email : null;
    }

    /** The e-mail only when the provider is authoritative for it (see $emailLinkable). */
    public function linkableEmail(): ?string
    {
        return $this->emailLinkable ? $this->email : null;
    }
}
