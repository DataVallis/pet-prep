<?php

namespace App\Services\Auth;

use JsonException;
use OpenSSLAsymmetricKey;
use UnexpectedValueException;

/**
 * Minimal RS256 JSON Web Token reader for provider ID tokens (M2-10c).
 *
 * Deliberately narrow: only compact JWS with `alg` = RS256 and a `kid`, only
 * RSA keys from a JWKS (≥ 2048 bits), signature checked with ext-openssl
 * (`openssl_verify`, SHA-256, PKCS#1 v1.5). No `none`, no HMAC, no key from
 * the token itself (`jwk` / `jku` / `x5u` headers are ignored) — so there is
 * no algorithm confusion. Claim checks (iss / aud / exp / nonce) live in
 * SocialTokenVerifier. Built in-house instead of adding a JWT package
 * (DECISIONS 2026-10-13).
 */
final class RsaJwt
{
    public const MAX_TOKEN_LENGTH = 4096;

    public const MIN_KEY_BITS = 2048;

    /**
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $claims
     */
    private function __construct(
        public readonly array $header,
        public readonly array $claims,
        public readonly string $kid,
        private readonly string $signingInput,
        private readonly string $signature,
    ) {}

    /**
     * @throws UnexpectedValueException malformed token, wrong `alg`, missing `kid`
     */
    public static function parse(string $token): self
    {
        if ($token === '' || strlen($token) > self::MAX_TOKEN_LENGTH) {
            throw new UnexpectedValueException('token length');
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3 || in_array('', $parts, true)) {
            throw new UnexpectedValueException('not a compact JWS');
        }
        [$h64, $p64, $s64] = $parts;

        $header = self::jsonObject(self::base64UrlDecode($h64));
        $claims = self::jsonObject(self::base64UrlDecode($p64));
        $signature = self::base64UrlDecode($s64);

        if (($header['alg'] ?? null) !== 'RS256') {
            throw new UnexpectedValueException('alg');
        }
        if (isset($header['crit'])) {
            throw new UnexpectedValueException('crit');
        }
        $kid = $header['kid'] ?? null;
        if (! is_string($kid) || $kid === '' || strlen($kid) > 255) {
            throw new UnexpectedValueException('kid');
        }

        return new self($header, $claims, $kid, $h64.'.'.$p64, $signature);
    }

    public function verifiesWith(OpenSSLAsymmetricKey $key): bool
    {
        return openssl_verify($this->signingInput, $this->signature, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * An RSA public key from a JWK (`kty` RSA, `n`, `e`), or null when the
     * entry is not a usable RS256 signing key.
     *
     * @param  array<string, mixed>  $jwk
     */
    public static function publicKeyFromJwk(array $jwk): ?OpenSSLAsymmetricKey
    {
        if (($jwk['kty'] ?? null) !== 'RSA') {
            return null;
        }
        if (isset($jwk['alg']) && $jwk['alg'] !== 'RS256') {
            return null;
        }
        if (isset($jwk['use']) && $jwk['use'] !== 'sig') {
            return null;
        }
        if (! is_string($jwk['n'] ?? null) || ! is_string($jwk['e'] ?? null)) {
            return null;
        }

        try {
            $n = self::base64UrlDecode($jwk['n']);
            $e = self::base64UrlDecode($jwk['e']);
        } catch (UnexpectedValueException) {
            return null;
        }

        $pem = self::rsaPublicKeyPem($n, $e);
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            return null;
        }

        $details = openssl_pkey_get_details($key);
        if (! is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || ($details['bits'] ?? 0) < self::MIN_KEY_BITS) {
            return null;
        }

        return $key;
    }

    /**
     * PEM SubjectPublicKeyInfo for an RSA modulus + exponent (big-endian bytes).
     */
    public static function rsaPublicKeyPem(string $modulus, string $exponent): string
    {
        $rsaPublicKey = self::derSequence(self::derInteger($modulus).self::derInteger($exponent));
        // AlgorithmIdentifier: rsaEncryption (1.2.840.113549.1.1.1) + NULL.
        $algorithm = self::derSequence("\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01"."\x05\x00");
        $bitString = "\x03".self::derLength(strlen($rsaPublicKey) + 1)."\x00".$rsaPublicKey;
        $spki = self::derSequence($algorithm.$bitString);

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    public static function base64UrlDecode(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            throw new UnexpectedValueException('base64url');
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new UnexpectedValueException('base64url');
        }

        return $decoded;
    }

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * @return array<string, mixed>
     */
    private static function jsonObject(string $json): array
    {
        try {
            $value = json_decode($json, true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new UnexpectedValueException('json');
        }

        if (! is_array($value) || array_is_list($value) && $value !== []) {
            throw new UnexpectedValueException('json object');
        }

        return $value;
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00".$bytes; // keep it positive
        }

        return "\x02".self::derLength(strlen($bytes)).$bytes;
    }

    private static function derSequence(string $content): string
    {
        return "\x30".self::derLength(strlen($content)).$content;
    }

    private static function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)).$bytes;
    }
}
