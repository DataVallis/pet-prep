<?php

namespace App\Support;

/**
 * Language of an API request (M1-18).
 *
 * Parses `Accept-Language` (RFC 9110 §12.5.4): comma-separated language
 * ranges with optional `;q=` weights. Only the primary subtag counts
 * (`sl-SI`, `sl_SI`, `SL` → `sl`); ranges with q=0, `*`, unsupported
 * languages and garbage are skipped. Highest weight wins, ties keep header
 * order. The supported list and the default live in config/locales.php.
 */
final class RequestLocale
{
    /** Longer headers are cut (real browsers send < 200 bytes). */
    private const MAX_HEADER_LENGTH = 512;

    /** At most this many ranges are looked at. */
    private const MAX_RANGES = 20;

    /**
     * @return list<string>
     */
    public static function supported(): array
    {
        $list = config('locales.supported', ['en']);

        return array_values(array_filter(
            is_array($list) ? $list : [],
            fn ($code): bool => is_string($code) && $code !== '',
        ));
    }

    public static function default(): string
    {
        $default = config('locales.default', 'en');
        $supported = self::supported();

        return is_string($default) && in_array($default, $supported, true)
            ? $default
            : ($supported[0] ?? 'en');
    }

    public static function isSupported(?string $locale): bool
    {
        return $locale !== null && in_array($locale, self::supported(), true);
    }

    /**
     * The supported language the header asks for, or null when it names
     * none (missing, empty, unsupported only, garbage).
     */
    public static function fromHeader(?string $header): ?string
    {
        if ($header === null) {
            return null;
        }

        $header = substr($header, 0, self::MAX_HEADER_LENGTH);
        $supported = self::supported();
        $candidates = [];

        foreach (array_slice(explode(',', $header), 0, self::MAX_RANGES) as $position => $range) {
            $parts = explode(';', $range);
            $tag = strtolower(trim($parts[0]));
            if (preg_match('/^([a-z]{1,8})(?:[-_][a-z0-9]{1,8})*$/', $tag, $m) !== 1) {
                continue; // `*`, empty, garbage
            }

            $quality = self::quality(array_slice($parts, 1));
            if ($quality === null || $quality <= 0.0) {
                continue;
            }

            $code = $m[1];
            if (! in_array($code, $supported, true)) {
                continue;
            }

            $candidates[] = ['code' => $code, 'q' => $quality, 'position' => $position];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (array $a, array $b): int => [$b['q'], $a['position']] <=> [$a['q'], $b['position']]);

        return $candidates[0]['code'];
    }

    /** The request's language: the header's choice, else the default. */
    public static function resolve(?string $header): string
    {
        return self::fromHeader($header) ?? self::default();
    }

    /**
     * Weight from the range parameters: 1.0 without `q`, null when `q` is
     * malformed (the range is ignored, as if it weren't there).
     *
     * @param  list<string>  $params
     */
    private static function quality(array $params): ?float
    {
        foreach ($params as $param) {
            $param = trim($param);
            if (! str_starts_with(strtolower($param), 'q=')) {
                continue;
            }
            $value = trim(substr($param, 2));
            if (preg_match('/^(0(\.\d{0,3})?|1(\.0{0,3})?)$/', $value) !== 1) {
                return null;
            }

            return (float) $value;
        }

        return 1.0;
    }
}
