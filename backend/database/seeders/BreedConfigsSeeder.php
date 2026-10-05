<?php

namespace Database\Seeders;

use App\Enums\BreedType;
use App\Models\BreedConfig;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Starting breed tunables (PRODUCT_SPEC §5). Production deploys run it every
 * time (scripts/deploy-production.sh), so it is INSERT-ONLY: a breed that
 * already exists is never touched — values edited in Filament always win
 * (DECISIONS 2026-10-03). The numbers come from the original product spec and
 * are pending verification against sourced breed data (ROADMAP M1-19).
 */
class BreedConfigsSeeder extends Seeder
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function configs(): array
    {
        return [
            [
                'breed_slug' => BreedType::Mutt->slug(),
                'daily_steps_required' => 4000,
                'hunger_decay_rate' => 8.0,
                'thirst_decay_rate' => 10.0,
                'poops_per_day' => 1,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'premium_unlock' => false,
            ],
            [
                'breed_slug' => BreedType::BorderCollie->slug(),
                'daily_steps_required' => 10000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'premium_unlock' => true,
            ],
        ];
    }

    /**
     * Run the database seeds: breed tunables, then the sourced life-stage
     * data (M5-R01, BreedStageParamsSeeder — also insert-only). The deploy
     * runs only this seeder, so both arrive with every deploy.
     */
    public function run(): void
    {
        $this->seedConfigs();
        (new BreedStageParamsSeeder)->run();
    }

    /**
     * Only the breed_configs rows (insert-only). Tests that are not about
     * life stages use this (tests/Pest.php seedBreedConfigs()): without
     * stage data a pet keeps the pre-M5 rules (breed feed windows,
     * daily_steps_required).
     */
    public function seedConfigs(): void
    {
        $now = now();

        foreach (self::configs() as $config) {
            $config['feed_windows'] = json_encode($config['feed_windows']);

            DB::table('breed_configs')->insertOrIgnore(
                array_merge($config, ['created_at' => $now, 'updated_at' => $now])
            );
        }
    }
}
