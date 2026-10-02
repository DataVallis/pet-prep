<?php

namespace Database\Seeders;

use App\Enums\BreedType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BreedConfigsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now();

        foreach ([
            [
                'breed_slug' => BreedType::Mutt->slug(),
                'daily_steps_required' => 4000,
                'hunger_decay_rate' => 8.0,
                'premium_unlock' => false,
            ],
            [
                'breed_slug' => BreedType::BorderCollie->slug(),
                'daily_steps_required' => 10000,
                'hunger_decay_rate' => 12.0,
                'premium_unlock' => true,
            ],
        ] as $config) {
            DB::table('breed_configs')->updateOrInsert(
                ['breed_slug' => $config['breed_slug']],
                array_merge($config, [
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
            );
        }
    }
}
