<?php

namespace App\Exceptions;

use Carbon\CarbonInterface;
use RuntimeException;

/**
 * The family time zone was changed less than
 * FamilySettingsService::MIN_HOURS_BETWEEN_CHANGES ago (Claude default
 * 2026-11-08, DECISIONS) — or a pet's own zone less than
 * PetTimezoneService::MIN_HOURS_BETWEEN_CHANGES ago (David 2026-11-09, per
 * pet): answered as 422 `timezone_change_too_soon` with `next_allowed_at`
 * (ISO 8601, in the zone that is current until the change).
 */
class TimezoneChangeTooSoonException extends RuntimeException
{
    public const REASON = 'timezone_change_too_soon';

    public function __construct(
        public readonly CarbonInterface $nextAllowedAt,
        public readonly string $timezone,
        string $message = 'The family time zone can be changed once every 24 hours.',
    ) {
        parent::__construct($message);
    }

    /**
     * @return array{message: string, reason: string, next_allowed_at: string}
     */
    public function body(): array
    {
        return [
            'message' => $this->getMessage(),
            'reason' => self::REASON,
            'next_allowed_at' => $this->nextAllowedAt->copy()->setTimezone($this->timezone)->toIso8601String(),
        ];
    }
}
