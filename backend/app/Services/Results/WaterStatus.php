<?php

namespace App\Services\Results;

use Carbon\CarbonImmutable;

/**
 * Water refills of a pet at one instant (CareScheduleService). Instants are
 * in the family timezone.
 *
 * M5-F11 (David 2026-10-12, "Dolij, ko se prazni"): no daily cap. A refill is
 * allowed once the minimum gap has passed AND the live thirst shows at or
 * below `refillBelow`. `timesPerDay` is only the expected minimum for the
 * Care Score (RoutineLedgerService); `remainingToday()` = expected refills
 * still open for the score, never a cap.
 */
final readonly class WaterStatus
{
    /** breed_configs.water_refill_below when a row has none (migration default). */
    public const DEFAULT_REFILL_BELOW = 60;

    public function __construct(
        public int $timesPerDay,
        public int $minGapMinutes,
        /** Displayed thirst (%) at or below which a refill is allowed. */
        public int $refillBelow,
        public int $usedToday,
        public ?CarbonImmutable $lastAt,
        /**
         * Earliest instant a refill is allowed again (later of the gap end and the
         * forecast minute thirst shows ≤ refillBelow); null when allowed now, or when
         * no time can be forecast (frozen / locked pet, zero decay rate, > 48 h).
         */
        public ?CarbonImmutable $nextAllowedAt,
        /** The minimum gap since the last refill has not passed. */
        public bool $tooSoon,
        /** The live thirst shows above refillBelow (the bowl is still full enough). */
        public bool $notThirsty,
        /** Live thirst as displayed (decayed to the instant, rounded half up). */
        public int $thirstShown,
    ) {}

    /** Expected refills still open for the score today (not a limit). */
    public function remainingToday(): int
    {
        return max(0, $this->timesPerDay - $this->usedToday);
    }

    /** Game rules allow a refill now (not counting locks or a mess). */
    public function allowedNow(): bool
    {
        return ! $this->tooSoon && ! $this->notThirsty;
    }
}
