<?php

namespace App\Services\Validation;

use App\Enums\AcquisitionSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * A parent's answer to »Kje ste izvedeli za PetPrep?« (VALIDATION_PLAN §4.1, David 2026-11-21 D2),
 * already cleaned: a known source, plus the detail only for `other` / `ad` (trimmed, at most
 * MAX_DETAIL_LENGTH characters). fromInput() never throws — an invalid answer must never block a
 * sign-up, so it becomes "no answer" (null) and one info log line.
 */
final class AcquisitionAnswer
{
    public const MAX_DETAIL_LENGTH = 60;

    private function __construct(
        public readonly AcquisitionSource $source,
        public readonly ?string $detail,
    ) {}

    /** Null when no valid source was sent (old app, skipped, unknown value). */
    public static function fromInput(mixed $source, mixed $detail): ?self
    {
        $source = self::normalizeSource($source);
        if ($source === null) {
            return null;
        }
        $enum = AcquisitionSource::from($source);

        return new self($enum, $enum->keepsDetail() ? self::normalizeDetail($detail) : null);
    }

    /**
     * A known source value, else null. A sent but unknown value is logged at info level (length
     * and type only — the value is free text from a client).
     */
    public static function normalizeSource(mixed $source): ?string
    {
        if ($source === null || $source === '') {
            return null;
        }
        if (is_string($source) && AcquisitionSource::tryFrom(trim($source)) !== null) {
            return trim($source);
        }

        Log::info('acquisition_source ignored: unknown value', [
            'type' => get_debug_type($source),
            'length' => is_string($source) ? mb_strlen($source) : null,
        ]);

        return null;
    }

    /** Trimmed, inner whitespace collapsed, cut to MAX_DETAIL_LENGTH; null when empty or not a string. */
    public static function normalizeDetail(mixed $detail): ?string
    {
        if (! is_string($detail)) {
            return null;
        }
        $detail = trim((string) preg_replace('/\s+/u', ' ', $detail));
        if ($detail === '') {
            return null;
        }

        return mb_substr($detail, 0, self::MAX_DETAIL_LENGTH);
    }

    /**
     * The `users` columns this answer writes.
     *
     * @return array{acquisition_source: string, acquisition_detail: string|null, acquisition_recorded_at: Carbon}
     */
    public function columns(): array
    {
        return [
            'acquisition_source' => $this->source->value,
            'acquisition_detail' => $this->detail,
            'acquisition_recorded_at' => now(),
        ];
    }
}
