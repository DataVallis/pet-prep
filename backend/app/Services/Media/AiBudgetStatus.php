<?php

namespace App\Services\Media;

/**
 * Where one AI budget stands (M5-T15): production or AI Lab, today or this
 * month. Spent = reserved + committed estimated cost, the same numbers as the
 * AI budget page (AiSpendGuard). The level follows the percentage of the cap,
 * so it follows whatever cap is configured.
 */
final readonly class AiBudgetStatus
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const BLOCKED = 'blocked';

    public function __construct(
        public string $budget,      // production | lab
        public string $period,      // day | month
        public string $periodKey,   // 2026-10-22 | 2026-10 (budget timezone)
        public float $spentUsd,
        public float $capUsd,
    ) {}

    /** Percent of the cap used; a cap of 0 (no AI calls allowed) counts as 100 %. */
    public function percent(): float
    {
        if ($this->capUsd <= 0) {
            return 100.0;
        }

        return round(100 * $this->spentUsd / $this->capUsd, 1);
    }

    /**
     * Thresholds reached (ascending), e.g. [80] or [80, 100]. Compared on the
     * exact ratio, not the rounded percent (79.96 % is not 80 %).
     *
     * @return list<int>
     */
    public function reachedThresholds(): array
    {
        $reached = [];
        foreach ([AiBudgetMonitor::WARN_PERCENT, AiBudgetMonitor::BLOCK_PERCENT] as $threshold) {
            if ($this->capUsd <= 0 || $this->spentUsd + 1e-9 >= $this->capUsd * $threshold / 100) {
                $reached[] = $threshold;
            }
        }

        return $reached;
    }

    public function level(): string
    {
        $reached = $this->reachedThresholds();

        return match (true) {
            in_array(AiBudgetMonitor::BLOCK_PERCENT, $reached, true) => self::BLOCKED,
            $reached !== [] => self::WARNING,
            default => self::OK,
        };
    }

    /** Filament colour of the level. */
    public function color(): string
    {
        return match ($this->level()) {
            self::BLOCKED => 'danger',
            self::WARNING => 'warning',
            default => 'success',
        };
    }

    public function leftUsd(): float
    {
        return max(0.0, $this->capUsd - $this->spentUsd);
    }
}
