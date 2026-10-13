<?php

namespace App\Services\Auth;

use InvalidArgumentException;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Sign in with Apple `client_secret` for the token / revoke endpoints
 * (M2-10c, account deletion → App Store guideline 5.1.1(v)).
 *
 * An ES256 JWT `{iss: team id, iat, exp: iat + 300, aud:
 * https://appleid.apple.com, sub: client id}` with header `{alg: ES256, kid:
 * key id}`, signed with the Sign in with Apple private key (.p8, P-256) via
 * ext-openssl. openssl_sign() returns an ASN.1 DER ECDSA signature; JWS needs
 * the raw 64-byte R‖S (RFC 7518 §3.4) — derToRaw() converts it.
 *
 * The private key is a secret: it is read from config (env
 * APPLE_SIGN_IN_PRIVATE_KEY), never logged, never put into an exception message.
 */
class AppleClientSecret
{
    public const AUDIENCE = 'https://appleid.apple.com';

    public const TTL_SECONDS = 300;

    /** P-256: 32-byte coordinates / signature halves. */
    public const PART_LENGTH = 32;

    public const DEFAULT_CLIENT_ID = 'com.datavallis.petprep';

    /** Is a usable P-256 private key configured (and team / key ids)? */
    public function isConfigured(): bool
    {
        return $this->teamId() !== '' && $this->keyId() !== '' && $this->privateKey() !== null;
    }

    /**
     * The client id of the token / revoke calls: env APPLE_CLIENT_ID (override)
     * › the `aud` of the Apple token the app signed in with (the id the code
     * was issued to) › the bundle id.
     */
    public function clientId(?string $tokenAudience = null): string
    {
        $explicit = config('services.apple.client_id');
        if (is_string($explicit) && trim($explicit) !== '') {
            return trim($explicit);
        }
        if (is_string($tokenAudience) && trim($tokenAudience) !== '') {
            return trim($tokenAudience);
        }

        return self::DEFAULT_CLIENT_ID;
    }

    /**
     * @param  string  $clientId  `sub` — must equal the `client_id` sent with it
     *
     * @throws RuntimeException no usable key configured / signing failed
     */
    public function make(string $clientId): string
    {
        $key = $this->privateKey();
        if ($key === null || ! $this->isConfigured()) {
            throw new RuntimeException('Sign in with Apple key is not configured.');
        }

        $now = now()->getTimestamp();
        $header = ['alg' => 'ES256', 'kid' => $this->keyId()];
        $claims = [
            'iss' => $this->teamId(),
            'iat' => $now,
            'exp' => $now + self::TTL_SECONDS,
            'aud' => self::AUDIENCE,
            'sub' => $clientId,
        ];

        $input = RsaJwt::base64UrlEncode((string) json_encode($header, JSON_UNESCAPED_SLASHES))
            .'.'.RsaJwt::base64UrlEncode((string) json_encode($claims, JSON_UNESCAPED_SLASHES));

        if (! openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the Apple client secret.');
        }

        return $input.'.'.RsaJwt::base64UrlEncode(self::derToRaw($der));
    }

    /**
     * ASN.1 DER `SEQUENCE { INTEGER r, INTEGER s }` → raw R‖S, each half
     * left-padded with zeros to $partLength (a DER integer drops leading zero
     * bytes and gains a 0x00 when its high bit is set).
     *
     * @throws InvalidArgumentException malformed DER or an integer too long
     */
    public static function derToRaw(string $der, int $partLength = self::PART_LENGTH): string
    {
        $offset = 0;
        if (self::byteAt($der, $offset++) !== 0x30) {
            throw new InvalidArgumentException('DER: not a sequence');
        }
        $seqLength = self::readLength($der, $offset);
        if ($offset + $seqLength !== strlen($der)) {
            throw new InvalidArgumentException('DER: sequence length mismatch');
        }

        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            if (self::byteAt($der, $offset++) !== 0x02) {
                throw new InvalidArgumentException('DER: not an integer');
            }
            $length = self::readLength($der, $offset);
            if ($length < 1 || $offset + $length > strlen($der)) {
                throw new InvalidArgumentException('DER: integer length');
            }
            $int = ltrim(substr($der, $offset, $length), "\x00");
            $offset += $length;
            if (strlen($int) > $partLength) {
                throw new InvalidArgumentException('DER: integer too long');
            }
            $parts[] = str_pad($int, $partLength, "\x00", STR_PAD_LEFT);
        }

        if ($offset !== strlen($der)) {
            throw new InvalidArgumentException('DER: trailing bytes');
        }

        return $parts[0].$parts[1];
    }

    /**
     * Raw R‖S → DER (the inverse; used to verify a JWS signature with openssl).
     */
    public static function rawToDer(string $raw): string
    {
        if ($raw === '' || strlen($raw) % 2 !== 0) {
            throw new InvalidArgumentException('raw signature length');
        }
        $half = intdiv(strlen($raw), 2);
        $content = '';
        foreach ([substr($raw, 0, $half), substr($raw, $half)] as $int) {
            $int = ltrim($int, "\x00");
            if ($int === '' || (ord($int[0]) & 0x80) !== 0) {
                $int = "\x00".$int;
            }
            $content .= "\x02".self::derLength(strlen($int)).$int;
        }

        return "\x30".self::derLength(strlen($content)).$content;
    }

    private function teamId(): string
    {
        return trim((string) config('services.apple.team_id', ''));
    }

    private function keyId(): string
    {
        return trim((string) config('services.apple.key_id', ''));
    }

    /** The configured P-256 private key, or null (missing / unreadable / wrong type). */
    private function privateKey(): ?OpenSSLAsymmetricKey
    {
        $value = config('services.apple.private_key');
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $value = trim($value);

        if (str_contains($value, '-----BEGIN')) {
            $pem = str_replace('\n', "\n", $value);
        } else {
            $decoded = base64_decode($value, true);
            if ($decoded === false || ! str_contains($decoded, '-----BEGIN')) {
                return null;
            }
            $pem = $decoded;
        }

        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            return null;
        }
        $details = openssl_pkey_get_details($key);
        if (! is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC
            || ($details['ec']['curve_name'] ?? null) !== 'prime256v1') {
            return null;
        }

        return $key;
    }

    private static function byteAt(string $bytes, int $offset): int
    {
        if ($offset >= strlen($bytes)) {
            throw new InvalidArgumentException('DER: truncated');
        }

        return ord($bytes[$offset]);
    }

    private static function readLength(string $der, int &$offset): int
    {
        $first = self::byteAt($der, $offset++);
        if ($first < 0x80) {
            return $first;
        }
        $count = $first & 0x7F;
        if ($count < 1 || $count > 2) {
            throw new InvalidArgumentException('DER: length form');
        }
        $length = 0;
        for ($i = 0; $i < $count; $i++) {
            $length = ($length << 8) | self::byteAt($der, $offset++);
        }

        return $length;
    }

    private static function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        return $length < 0x100 ? "\x81".chr($length) : "\x82".pack('n', $length);
    }
}
