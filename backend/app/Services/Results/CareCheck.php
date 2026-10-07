<?php

namespace App\Services\Results;

use App\Enums\CareRefusal;
use Carbon\CarbonImmutable;

/**
 * May the child feed / water the pet right now by the game rules (M3-12)?
 * The single answer used by the feed / water actions, the child state
 * (`can_feed`, `feed_mode`, `can_water`) and the push copy
 * (CareScheduleService::feedCheck / waterCheck). Locks (hard stop, vet,
 * contract …) are not part of it — they are per child and checked first by
 * the callers.
 */
final readonly class CareCheck
{
    /** Feeding inside an unused meal window (scored as on time). */
    public const MODE_WINDOW = 'window';

    /**
     * Emergency meal outside a window (M3-12, David 2026-10-07): hunger shows
     * ≤ CareScheduleService::EMERGENCY_FEED_THRESHOLD. Never makes a window
     * "on time" (the routine ledger only counts feeds inside a window).
     */
    public const MODE_EMERGENCY = 'emergency';

    public function __construct(
        public bool $allowed,
        /** window | emergency for an allowed feed; null for water and refusals. */
        public ?string $mode,
        public ?CareRefusal $refusal,
        /** When the refused action is possible again by the rules (null: unknown / not time-based, e.g. clean first). */
        public ?CarbonImmutable $nextAllowedAt,
    ) {}

    public static function allow(?string $mode = null): self
    {
        return new self(true, $mode, null, null);
    }

    public static function refuse(CareRefusal $refusal, ?CarbonImmutable $nextAllowedAt = null): self
    {
        return new self(false, null, $refusal, $nextAllowedAt);
    }

    public function isEmergency(): bool
    {
        return $this->allowed && $this->mode === self::MODE_EMERGENCY;
    }
}
