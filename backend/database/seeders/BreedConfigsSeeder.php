<?php

namespace Database\Seeders;

use App\Enums\BreedType;
use App\Models\BreedConfig;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Canonical breed tunables (PRODUCT_SPEC §5). Idempotent: production deploys
 * run it every time (scripts/deploy-production.sh), so it upserts by slug and
 * keeps the original created_at.
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
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now();

        foreach (self::configs() as $config) {
            $config['feed_windows'] = json_encode($config['feed_windows']);
            $exists = DB::table('breed_configs')->where('breed_slug', $config['breed_slug'])->exists();

            DB::table('breed_configs')->updateOrInsert(
                ['breed_slug' => $config['breed_slug']],
                array_merge($config, ['updated_at' => $now], $exists ? [] : ['created_at' => $now])
            );
        }
    }
}
