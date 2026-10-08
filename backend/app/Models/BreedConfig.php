<?php

namespace App\Models;

use App\Enums\BreedType;
use App\Enums\Species;
use App\Services\BreedCatalogService;
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
 * @property int|null $daily_steps_cap Optional cap on the stage-derived step goal (M5-R01; null = none, David 2026-10-05).
 * @property bool $premium_unlock Paid breed (12-week challenge). Since M5-R06-01 the ONLY source of the
 *                                free / paid rule (BreedType::isPremium(), BreedCatalogService).
 * @property Species $species Catalogue species (M5-R06-01); for an enum breed always BreedType::species().
 * @property int $sort_order Picker order inside "free" / "paid" of a species (M5-R06-01).
 * @property list<string> $search_keywords Picker search synonyms (M5-R06-01, CAT_SPEC §10).
 * @property string|null $label_key i18n key of the breed name in the apps, e.g. `breeds.domestic_cat`.
 *
 * Since M5-R01 meals / feed windows / the step goal depend on the pet's life
 * stage (`breed_stage_params`, LifeStageService). `feed_windows` stay the
 * windows of two-meal days and the fallback; `daily_steps_required` is used
 * only for a breed without life-stage data.
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
        'daily_steps_cap',
        'species',
        'sort_order',
        'search_keywords',
        'label_key',
    ];

    protected static function booted(): void
    {
        // An enum breed's species is fixed by the enum (pets_species_breed_check);
        // the catalogue column follows it whatever the admin form sent.
        static::saving(function (BreedConfig $config): void {
            $breed = BreedType::fromSlug((string) $config->breed_slug);
            if ($breed !== null) {
                $config->species = $breed->species();
            }
        });
        static::saved(fn () => BreedCatalogService::forget());
        static::deleted(fn () => BreedCatalogService::forget());
    }

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
            'daily_steps_cap' => 'integer',
            'species' => Species::class,
            'sort_order' => 'integer',
            'search_keywords' => 'array',
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
