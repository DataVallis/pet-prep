<?php

namespace Database\Seeders;

use App\Enums\BreedType;
use App\Enums\UserRole;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TestUsersSeeder extends Seeder
{
    /**
     * Create a test parent + child + pet for development.
     */
    public function run(): void
    {
        // Create test parent (if not already present)
        $parent = User::firstOrCreate(
            ['email' => 'parent@test.com'],
            [
                'name' => 'Test Parent',
                'password' => Hash::make('password'),
                'role' => UserRole::Parent,
            ],
        );

        // Create test child linked to parent
        $child = User::firstOrCreate(
            ['email' => 'child@test.com'],
            [
                'name' => 'Test Child',
                'password' => Hash::make('password'),
                'role' => UserRole::Child,
                'parent_id' => $parent->id,
            ],
        );

        // Create a pet for the child (if none exists)
        if ($child->pet()->count() === 0) {
            Pet::create([
                'user_id' => $child->id,
                'breed_type' => BreedType::Mutt,
                'hunger_level' => 80,
                'thirst_level' => 75,
                'energy_level' => 90,
                'hygiene_level' => 85,
                'born_at' => now(),
                'is_active' => true,
            ]);
        }
    }
}
