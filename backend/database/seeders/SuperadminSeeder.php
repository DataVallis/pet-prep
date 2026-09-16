<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperadminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Creates a default superadmin account for the Filament admin panel.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@petprep.io'],
            [
                'name' => 'PetPrep Superadmin',
                'email' => 'admin@petprep.io',
                'password' => Hash::make('Password123!'),
                'role' => UserRole::Parent->value,
                'is_superadmin' => true,
                'email_verified_at' => now(),
            ]
        );
    }
}
