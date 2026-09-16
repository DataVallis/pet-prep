<?php

use App\Enums\BreedType;
use App\Enums\PetStateEnum;
use App\Models\Pet;
use App\Models\QuietHours;
use App\Models\User;
use App\Services\PetDecayService;

use function Pest\Laravel\{actingAs, assertDatabaseHas, putJson, getJson};

/*
|--------------------------------------------------------------------------
| Pet Decay & Game Loop Tests
|--------------------------------------------------------------------------
*/

describe('PetDecayService - metric decay', function () {
    it('decays hunger level for a Mutt pet over time', function () {
        seedBreedConfigs();
        $user = User::factory()->child()->create();
        $pet = Pet::factory()->mutt()->create([
            'user_id' => $user->id,
            'hunger_level' => 100,
            'thirst_level' => 100,
        ]);
        // Simulate 60 minutes since last update (bypass model timestamps)
        Pet::where('id', $pet->id)->update(['updated_at' => now()->subMinutes(60)]);
        $pet->refresh();

        $service = app(PetDecayService::class);
        $service->processPetDecay($pet);

        // Mutt hunger decay: -8%/hr → after 60 min: 100 - 8 = 92
        $pet->refresh();
        expect($pet->hunger_level)->toBeLessThan(100);
        expect($pet->hunger_level)->toBeLessThanOrEqual(93);
    });

    it('decays hunger faster for Border Collie than Mutt', function () {
        seedBreedConfigs();
        $muttUser = User::factory()->child()->create();
        $muttPet = Pet::factory()->mutt()->create([
            'user_id' => $muttUser->id,
            'hunger_level' => 50,
        ]);
        Pet::where('id', $muttPet->id)->update(['updated_at' => now()->subMinutes(120)]);

        $collieUser = User::factory()->child()->create();
        $colliePet = Pet::factory()->borderCollie()->create([
            'user_id' => $collieUser->id,
            'hunger_level' => 50,
        ]);
        Pet::where('id', $colliePet->id)->update(['updated_at' => now()->subMinutes(120)]);

        $service = app(PetDecayService::class);
        $service->processPetDecay($muttPet->refresh());
        $service->processPetDecay($colliePet->refresh());

        // Mutt: 50 - (8/60)*120 = 50 - 16 = 34
        // Collie: 50 - (12/60)*120 = 50 - 24 = 26
        $muttPet->refresh();
        $colliePet->refresh();
        expect($colliePet->hunger_level)->toBeLessThan($muttPet->hunger_level);
    });

    it('clamps decay to 0 minimum', function () {
        seedBreedConfigs();
        $user = User::factory()->child()->create();
        $pet = Pet::factory()->create([
            'user_id' => $user->id,
            'hunger_level' => 1,
            'thirst_level' => 1,
        ]);
        Pet::where('id', $pet->id)->update(['updated_at' => now()->subMinutes(120)]);
        $pet->refresh();

        $service = app(PetDecayService::class);
        $service->processPetDecay($pet);

        $pet->refresh();
        expect($pet->hunger_level)->toBeGreaterThanOrEqual(0);
        expect($pet->thirst_level)->toBeGreaterThanOrEqual(0);
    });

    it('does not decay inactive pets', function () {
        seedBreedConfigs();
        $user = User::factory()->child()->create();
        $pet = Pet::factory()->create([
            'user_id' => $user->id,
            'is_active' => false,
            'hunger_level' => 50,
        ]);
        Pet::where('id', $pet->id)->update(['updated_at' => now()->subMinutes(120)]);
        $pet->refresh();

        $service = app(PetDecayService::class);
        $result = $service->processPetDecay($pet);

        expect($result)->toBeFalse();
        expect($pet->fresh()->hunger_level)->toBe(50);
    });

    it('does not decay pets in illness lockout', function () {
        seedBreedConfigs();
        $user = User::factory()->child()->create();
        $pet = Pet::factory()->create([
            'user_id' => $user->id,
            'hunger_level' => 50,
            'illness_until' => now()->addHours(6),
            'pet_state' => 'sick',
        ]);
        Pet::where('id', $pet->id)->update(['updated_at' => now()->subMinutes(120)]);
        $pet->refresh();

        $service = app(PetDecayService::class);
        $result = $service->processPetDecay($pet);

        expect($result)->toBeFalse();
        expect($pet->fresh()->hunger_level)->toBe(50);
    });
});

describe('PetDecayService - quiet hours', function () {
    it('reduces decay by 90% during quiet hours', function () {
        seedBreedConfigs();
        $parent = User::factory()->parent()->create();
        $child = User::factory()->child()->create(['parent_id' => $parent->id]);

        QuietHours::create([
            'parent_id' => $parent->id,
            'school_start' => '00:00',
            'school_end' => '23:59',
            'is_active' => true,
        ]);

        $pet = Pet::factory()->mutt()->create([
            'user_id' => $child->id,
            'hunger_level' => 100,
        ]);
        Pet::where('id', $pet->id)->update(['updated_at' => now()->subMinutes(60)]);
        $pet->refresh();

        $service = app(PetDecayService::class);
        $service->processPetDecay($pet);

        $pet->refresh();
        expect($pet->hunger_level)->toBeGreaterThan(95);
    });

    it('does not reduce decay when quiet hours are inactive', function () {
        seedBreedConfigs();
        $parent = User::factory()->parent()->create();
        $child = User::factory()->child()->create(['parent_id' => $parent->id]);

        QuietHours::create([
            'parent_id' => $parent->id,
            'school_start' => '00:00',
            'school_end' => '23:59',
            'is_active' => false,
        ]);

        $pet = Pet::factory()->mutt()->create([
            'user_id' => $child->id,
            'hunger_level' => 100,
        ]);
        Pet::where('id', $pet->id)->update(['updated_at' => now()->subMinutes(60)]);
        $pet->refresh();

        $service = app(PetDecayService::class);
        $service->processPetDecay($pet);

        $pet->refresh();
        expect($pet->hunger_level)->toBeLessThanOrEqual(93);
    });
});

describe('PetDecayService - pet state determination', function () {
    it('sets pet state to hungry when hunger is low', function () {
        seedBreedConfigs();
        $user = User::factory()->child()->create();
        $pet = Pet::factory()->create([
            'user_id' => $user->id,
            'hunger_level' => 25,
            'thirst_level' => 100,
            'energy_level' => 100,
            'hygiene_level' => 100,
        ]);
        Pet::where('id', $pet->id)->update(['updated_at' => now()->subMinutes(60)]);
        $pet->refresh();

        $service = app(PetDecayService::class);
        $service->processPetDecay($pet);

        $pet->refresh();
        expect($pet->pet_state)->toBe(PetStateEnum::Hungry);
    });

    it('sets pet state to sick when hygiene is 0', function () {
        seedBreedConfigs();
        $user = User::factory()->child()->create();
        $pet = Pet::factory()->create([
            'user_id' => $user->id,
            'hunger_level' => 100,
            'thirst_level' => 100,
            'energy_level' => 100,
            'hygiene_level' => 0,
        ]);
        Pet::where('id', $pet->id)->update(['updated_at' => now()->subMinutes(60)]);
        $pet->refresh();

        $service = app(PetDecayService::class);
        $service->processPetDecay($pet);

        $pet->refresh();
        expect($pet->pet_state)->toBe(PetStateEnum::Sick);
    });

    it('sets pet state to playing when metrics are high', function () {
        seedBreedConfigs();
        $user = User::factory()->child()->create();
        $pet = Pet::factory()->create([
            'user_id' => $user->id,
            'hunger_level' => 80,
            'thirst_level' => 80,
            'energy_level' => 90,
            'hygiene_level' => 100,
        ]);
        Pet::where('id', $pet->id)->update(['updated_at' => now()->subMinutes(60)]);
        $pet->refresh();

        $service = app(PetDecayService::class);
        $service->processPetDecay($pet);

        $pet->refresh();
        expect($pet->pet_state)->toBe(PetStateEnum::Playing);
    });
});

describe('PetDecayService - zero metric tracking', function () {
    it('tracks when hunger first hits 0', function () {
        seedBreedConfigs();
        $user = User::factory()->child()->create();
        $pet = Pet::factory()->create([
            'user_id' => $user->id,
            'hunger_level' => 5,
            'thirst_level' => 100,
        ]);
        Pet::where('id', $pet->id)->update(['updated_at' => now()->subMinutes(60)]);
        $pet->refresh();

        $service = app(PetDecayService::class);
        $service->processPetDecay($pet);

        $pet->refresh();
        expect($pet->hunger_level)->toBe(0);
        expect($pet->hunger_zero_since)->not->toBeNull();
    });

    it('clears zero-since tracking when metric recovers above 0', function () {
        seedBreedConfigs();
        $user = User::factory()->child()->create();
        $pet = Pet::factory()->create([
            'user_id' => $user->id,
            'hunger_level' => 50,
            'thirst_level' => 100,
            'hunger_zero_since' => now()->subHour(),
        ]);
        Pet::where('id', $pet->id)->update(['updated_at' => now()->subMinutes(1)]);
        $pet->refresh();

        $service = app(PetDecayService::class);
        $service->processPetDecay($pet);

        $pet->refresh();
        expect($pet->hunger_zero_since)->toBeNull();
    });
});

describe('PetDecayService - step count reset', function () {
    it('resets daily step count at midnight', function () {
        seedBreedConfigs();
        $user = User::factory()->child()->create();
        $pet = Pet::factory()->create([
            'user_id' => $user->id,
            'daily_step_count' => 5000,
            'last_step_reset_at' => now()->subDay(),
        ]);
        Pet::where('id', $pet->id)->update(['updated_at' => now()->subMinutes(60)]);
        $pet->refresh();

        $service = app(PetDecayService::class);
        $service->processPetDecay($pet);

        $pet->refresh();
        expect($pet->daily_step_count)->toBe(0);
        expect($pet->last_step_reset_at)->not->toBeNull();
    });
});

describe('Quiet Hours API', function () {
    it('allows parent to set quiet hours', function () {
        $parent = User::factory()->parent()->create();

        actingAs($parent, 'sanctum');

        putJson('/api/parent/quiet-hours', [
            'school_start' => '08:00',
            'school_end' => '13:00',
            'bedtime_start' => '22:00',
            'bedtime_end' => '06:00',
            'is_active' => true,
        ])
            ->assertStatus(200)
            ->assertJson([
                'message' => 'Quiet hours updated successfully.',
                'quiet_hours' => [
                    'school_start' => '08:00',
                    'school_end' => '13:00',
                    'bedtime_start' => '22:00',
                    'bedtime_end' => '06:00',
                ],
            ]);

        assertDatabaseHas('quiet_hours', [
            'parent_id' => $parent->id,
            'school_start' => '08:00',
            'school_end' => '13:00',
        ]);
    });

    it('allows parent to get their quiet hours', function () {
        $parent = User::factory()->parent()->create();
        QuietHours::create([
            'parent_id' => $parent->id,
            'school_start' => '08:00',
            'school_end' => '13:00',
            'bedtime_start' => '22:00',
            'bedtime_end' => '06:00',
        ]);

        actingAs($parent, 'sanctum');

        getJson('/api/parent/quiet-hours')
            ->assertStatus(200)
            ->assertJsonPath('quiet_hours.school_start', '08:00');
    });

    it('rejects quiet hours management from child profile', function () {
        $child = User::factory()->child()->create();

        actingAs($child, 'sanctum');

        putJson('/api/parent/quiet-hours', [
            'school_start' => '08:00',
            'school_end' => '13:00',
        ])->assertStatus(403);
    });

    it('validates time format', function () {
        $parent = User::factory()->parent()->create();

        actingAs($parent, 'sanctum');

        putJson('/api/parent/quiet-hours', [
            'school_start' => 'invalid',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['school_start']);
    });

    it('updates existing quiet hours instead of creating duplicates', function () {
        $parent = User::factory()->parent()->create();

        actingAs($parent, 'sanctum');

        // First update
        putJson('/api/parent/quiet-hours', [
            'school_start' => '08:00',
            'school_end' => '13:00',
        ])->assertStatus(200);

        // Second update (should update, not create new)
        putJson('/api/parent/quiet-hours', [
            'school_start' => '09:00',
            'school_end' => '14:00',
        ])->assertStatus(200);

        expect(QuietHours::where('parent_id', $parent->id)->count())->toBe(1);
        expect(QuietHours::where('parent_id', $parent->id)->first()->school_start)->toBe('09:00:00');
    });
});
