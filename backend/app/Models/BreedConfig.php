<?php

namespace App\Models;

use App\Enums\BreedType;
use Illuminate\Database\Eloquent\Model;

/**
 * Every tunable game number per breed (M1-06). Services read these values;
 * never hard-code them. Seeded idempotently by BreedConfigsSeeder.
 *
 * @property int $daily_steps_required Steps for 100 % energy (M1-04).
 * @property float $hunger_decay_rate % per hour outside quiet hours.
 * @property float $thirst_decay_rate % per hour outside quiet hours.
 * @property int $poops_per_day Random hygiene events per family-local day (M1-05).
 * @property list<array{0: string, 1: string}> $feed_windows Family-local [start, end) "HH:MM" pairs (M1-07).
 * @property int $water_times_per_day Max water refills per family-local day (M1-07).
 * @property int $water_min_gap_minutes Minimum gap between refills (M1-07).
 */
class BreedConfig extends Model
{
    /**
     * Default feeding windows (family-local time): morning and evening.
     */
    public const DEFAULT_FEED_WINDOWS = [['06:00', '10:00'], ['17:00', '21:00']];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'breed_slug',
        'daily_steps_required',
        'hunger_decay_rate',
        'thirst_decay_rate',
        'poops_per_day',
        'feed_windows',
        'water_times_per_day',
        'water_min_gap_minutes',
        'premium_unlock',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'daily_steps_required' => 'integer',
            'hunger_decay_rate' => 'float',
            'thirst_decay_rate' => 'float',
            'poops_per_day' => 'integer',
            'feed_windows' => 'array',
            'water_times_per_day' => 'integer',
            'water_min_gap_minutes' => 'integer',
            'premium_unlock' => 'boolean',
        ];
    }

    // ──────────────────────────────────────────────────────────────
    //  Lookup Helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * Get the breed configuration by breed type enum.
     */
    public static function forBreed(BreedType $breed): ?self
    {
        return static::where('breed_slug', $breed->slug())->first();
    }

    /**
     * Energy (0–100, precise) for a number of steps walked today:
     * min(100, steps / daily_steps_required × 100). PRODUCT_SPEC §5.
     */
    public function energyForSteps(int $steps): float
    {
        if ($this->daily_steps_required <= 0) {
            return 100.0;
        }

        return min(100.0, max(0, $steps) / $this->daily_steps_required * 100);
    }
}
