<?php

namespace App\Services\SystemHealth;

/**
 * Text helpers of the error capture (System health, David 2026-10-19):
 * scrub obvious secrets / personal data out of a message before it is
 * stored, and normalise it for the fingerprint (ids, numbers and quoted
 * values vary per occurrence, the error does not).
 */
final class ErrorText
{
    /**
     * Ordered: the most specific patterns first.
     *
     * @var array<string, string>
     */
    private const SCRUB = [
        // key=value / "key": "value" pairs whose value is a credential.
        '/((?:password|passwd|secret|token|api[_-]?key|authorization|signature|code|pin|nonce|cookie|session)["\']?\s*[:=]\s*["\']?)[^\s"\'&,;)}\]]+/i' => '$1[redacted]',
        // Bearer / Basic credentials.
        '/\b(Bearer|Basic)\s+[A-Za-z0-9\-._~+\/]+=*/i' => '$1 [redacted]',
        // JWTs.
        '/\beyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]*/' => '[jwt]',
        // Sanctum plain-text tokens (id|secret).
        '/\b\d+\|[A-Za-z0-9]{20,}\b/' => '[token]',
        // E-mail addresses.
        '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/' => '[email]',
        // IPv4 addresses.
        '/\b(?:\d{1,3}\.){3}\d{1,3}\b/' => '[ip]',
        // Long opaque strings (keys, hashes, base64 blobs).
        '/\b[A-Za-z0-9+\/_\-]{40,}={0,2}/' => '[secret]',
    ];

    /** Scrub and truncate a message for storage. */
    public static function scrub(string $text, int $maxChars): string
    {
        $text = (string) preg_replace(array_keys(self::SCRUB), array_values(self::SCRUB), $text);
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strlen($text) > $maxChars ? mb_substr($text, 0, $maxChars - 1).'…' : $text;
    }

    /** The message with everything that varies per occurrence replaced. */
    public static function normalise(string $text): string
    {
        $text = (string) preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '{uuid}', $text);
        $text = (string) preg_replace('/\'[^\']*\'|"[^"]*"/', '{s}', $text);
        $text = (string) preg_replace('/\b0x[0-9a-f]+\b|\b[0-9a-f]{12,}\b/i', '{hex}', $text);
        $text = (string) preg_replace('/\d+(\.\d+)?/', '{n}', $text);

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    public static function firstLine(string $text): string
    {
        $line = strtok($text, "\n");

        return mb_substr(trim($line === false ? $text : $line), 0, 300);
    }
}
