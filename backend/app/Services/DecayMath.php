<?php

namespace App\Services;

use App\Models\Pet;
use App\Models\QuietHours;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The hunger / thirst decay formula in one place (PetDecayService tick,
 * M5-F11 water forecast). Pure: no queries, no writes.
 *
 * - weightedHours(): hours between two instants, quiet time counted at
 *   PetDecayService::QUIET_HOURS_DECAY_MULTIPLIER (QuietHours::splitSecondsBetween).
 * - decay(): subtract and clamp to 0–100, snapping float noise onto integers.
 * - liveValue(): a metric decayed from `pets.last_decay_at` to $now — what the
 *   next tick would write — without saving. Frozen / unborn pets do not decay.
 * - firstMinuteAtOrBelow() (M5-F11, David 2026-10-12 "Dolij, ko se prazni"):
 *   the first whole minute at which the DISPLAYED value (Pet::displayValue,
 *   half up) is ≤ a threshold. Decay is monotone, so a binary search to the
 *   minute over a bounded horizon is exact.
 */
final class DecayMath
{
    /** Values within this distance of an integer are snapped to it (see PetDecayService). */
    public const SNAP_EPSILON = 1e-6;

    /** How far ahead firstMinuteAtOrBelow() looks (48 h). */
    public const FORECAST_HORIZON_MINUTES = 2880;

    public static function weightedHours(?QuietHours $quietHours, CarbonInterface $from, CarbonInterface $to): float
    {
        ['normal' => $normal, 'quiet' => $quiet] = QuietHours::splitSecondsBetween($quietHours, $from, $to);

        return ($normal + $quiet * PetDecayService::QUIET_HOURS_DECAY_MULTIPLIER) / 3600;
    }

    public static function decay(float $current, float $amount): float
    {
        $value = max(0.0, min(100.0, $current - $amount));
        $nearest = round($value);

        return abs($value - $nearest) < self::SNAP_EPSILON ? $nearest : $value;
    }

    /**
     * Does the pet's metric decay right now? Not while unborn, inactive, game
     * over, hard-stopped, payment-locked or ill (same rule as the tick).
     */
    public static function decays(Pet $pet): bool
    {
        return ! $pet->isUnborn()
            && (bool) $pet->is_active
            && ! $pet->is_game_over
            && ! $pet->isFrozen();
    }

    /**
     * The precise value of a time-decayed metric (`hunger_level` /
     * `thirst_level`) at $now: the stored value decayed since last_decay_at
     * (the stored value itself before the first tick started the clock).
     */
    public static function liveValue(Pet $pet, string $metric, float $ratePerHour, CarbonInterface $now, ?QuietHours $quietHours): float
    {
        $stored = (float) $pet->getAttribute($metric);
        if (! self::decays($pet) || $pet->last_decay_at === null || $pet->last_decay_at->greaterThanOrEqualTo($now)) {
            return $stored;
        }

        return self::decay($stored, $ratePerHour * self::weightedHours($quietHours, $pet->last_decay_at, $now));
    }

    /**
     * The first whole minute (seconds 00, at or after $from) at which a
     * metric that is $value at $from shows ≤ $threshold. $from itself when it
     * already does. Null when the rate is not positive or the moment lies
     * beyond the horizon. The caller decides whether the pet decays at all.
     */
    public static function firstMinuteAtOrBelow(
        float $value,
        float $ratePerHour,
        int $threshold,
        CarbonInterface $from,
        ?QuietHours $quietHours,
        int $horizonMinutes = self::FORECAST_HORIZON_MINUTES,
    ): ?CarbonImmutable {
        $from = CarbonImmutable::instance($from);
        if (Pet::displayValue($value) <= $threshold) {
            return $from;
        }
        if ($ratePerHour <= 0) {
            return null;
        }

        $base = $from->second === 0 && $from->micro === 0 ? $from : $from->startOfMinute()->addMinute();
        $reached = fn (int $minutes): bool => Pet::displayValue(
            self::decay($value, $ratePerHour * self::weightedHours($quietHours, $from, $base->addMinutes($minutes)))
        ) <= $threshold;

        if (! $reached($horizonMinutes)) {
            return null;
        }

        // Smallest k in [0, horizon] with reached(k); reached is monotone.
        $low = 0;
        $high = $horizonMinutes;
        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            if ($reached($mid)) {
                $high = $mid;
            } else {
                $low = $mid + 1;
            }
        }

        return $base->addMinutes($low);
    }
}
