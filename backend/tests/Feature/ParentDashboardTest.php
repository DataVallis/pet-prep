<?php

use App\Enums\ActivityType;
use App\Enums\BreedType;
use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\Pet;
use App\Models\QuietHours;
use App\Models\User;
use App\Services\PairingService;

use function Pest\Laravel\{actingAs, assertDatabaseHas, getJson, postJson};

/*
|--------------------------------------------------------------------------
| Parent Dashboard API Tests
|--------------------------------------------------------------------------
*/

function createParentWithChildAndPet(): array
{
    seedBreedConfigs();

    $parent = User::factory()->parent()->create();
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    $pet = Pet::factory()->mutt()->create([
        'user_id' => $child->id,
        'hunger_level' => 80,
        'thirst_level' => 80,
        'energy_level' => 80,
        'hygiene_level' => 80,
    ]);

    return [$parent, $child, $pet];
}

describe('GET /api/parent/dashboard', function () {
    it('returns combined dashboard state for a parent with paired child', function () {
        [$parent, $child, $pet] = createParentWithChildAndPet();

        // Add some activities
        ActivityLog::create([
            'pet_id' => $pet->id,
            'activity_type' => ActivityType::FedPet->value,
            'value' => 100,
        ]);

        actingAs($parent, 'sanctum');

        $response = getJson('/api/parent/dashboard');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'pet' => [
                    'id', 'breed_type', 'hunger_level', 'thirst_level',
                    'energy_level', 'hygiene_level', 'pet_state',
                    'is_active', 'is_ill', 'is_game_over', 'is_hard_stopped',
                    'escalation_level', 'virtual_age_months',
                ],
                'child' => ['id', 'name'],
                'traffic_light',
                'quiet_hours',
                'recent_activities',
                'weekly_performance',
            ]);

        expect($response->json('traffic_light'))->toBe('green');
        expect($response->json('pet.breed_type'))->toBe('mutt');
        expect($response->json('child.name'))->toBe($child->name);
        expect($response->json('recent_activities'))->toHaveCount(1);
        expect($response->json('recent_activities.0.is_positive'))->toBeTrue();
    });

    it('returns green traffic light when all metrics are healthy', function () {
        [$parent] = createParentWithChildAndPet();

        actingAs($parent, 'sanctum');

        $response = getJson('/api/parent/dashboard');
        expect($response->json('traffic_light'))->toBe('green');
    });

    it('returns amber traffic light when escalation level is 1-2', function () {
        [$parent, $child, $pet] = createParentWithChildAndPet();
        $pet->update(['escalation_level' => 1]);

        actingAs($parent, 'sanctum');

        expect(getJson('/api/parent/dashboard')->json('traffic_light'))->toBe('amber');
    });

    it('returns red traffic light when escalation level is 3', function () {
        [$parent, $child, $pet] = createParentWithChildAndPet();
        $pet->update(['escalation_level' => 3]);

        actingAs($parent, 'sanctum');

        expect(getJson('/api/parent/dashboard')->json('traffic_light'))->toBe('red');
    });

    it('returns red traffic light when pet is in game over', function () {
        [$parent, $child, $pet] = createParentWithChildAndPet();
        $pet->update(['is_game_over' => true, 'is_active' => false]);

        actingAs($parent, 'sanctum');

        expect(getJson('/api/parent/dashboard')->json('traffic_light'))->toBe('red');
    });

    it('returns red traffic light when pet is ill', function () {
        [$parent, $child, $pet] = createParentWithChildAndPet();
        $pet->update(['illness_until' => now()->addHours(6), 'pet_state' => 'sick']);

        actingAs($parent, 'sanctum');

        expect(getJson('/api/parent/dashboard')->json('traffic_light'))->toBe('red');
    });

    it('returns empty state when no child is paired', function () {
        $parent = User::factory()->parent()->create();

        actingAs($parent, 'sanctum');

        $response = getJson('/api/parent/dashboard');
        $response->assertStatus(200)
            ->assertJsonPath('pet', null)
            ->assertJsonPath('traffic_light', 'green');
    });

    it('includes weekly performance data for last 7 days', function () {
        [$parent, $child, $pet] = createParentWithChildAndPet();

        // Add an activity from 3 days ago
        ActivityLog::create([
            'pet_id' => $pet->id,
            'activity_type' => ActivityType::FedPet->value,
            'value' => 100,
            'created_at' => now()->subDays(3),
        ]);

        actingAs($parent, 'sanctum');

        $performance = getJson('/api/parent/dashboard')->json('weekly_performance');
        expect($performance)->toHaveCount(7);

        // Find the entry that has 1 completed (the one from 3 days ago)
        $withCompleted = collect($performance)->firstWhere('completed', 1);
        expect($withCompleted)->not->toBeNull();
    });

    it('rejects access from child profile', function () {
        $child = User::factory()->child()->create();

        actingAs($child, 'sanctum');

        getJson('/api/parent/dashboard')->assertStatus(403);
    });

    it('requires authentication', function () {
        getJson('/api/parent/dashboard')->assertUnauthorized();
    });
});

describe('GET /api/parent/activities', function () {
    it('returns paginated activity logs', function () {
        [$parent, $child, $pet] = createParentWithChildAndPet();

        // Add multiple activities
        ActivityLog::create(['pet_id' => $pet->id, 'activity_type' => ActivityType::FedPet->value, 'value' => 100]);
        ActivityLog::create(['pet_id' => $pet->id, 'activity_type' => ActivityType::WalkedPet->value, 'value' => 5000]);
        ActivityLog::create(['pet_id' => $pet->id, 'activity_type' => ActivityType::IgnoredWarning->value, 'value' => 10]);

        actingAs($parent, 'sanctum');

        $response = getJson('/api/parent/activities');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'total']]);

        expect($response->json('data'))->toHaveCount(3);
        expect($response->json('data.0.is_positive'))->toBeFalse(); // ignored_warning is negative
        expect($response->json('data.1.is_positive'))->toBeTrue(); // walked_pet is positive
    });

    it('supports pagination via per_page parameter', function () {
        [$parent, $child, $pet] = createParentWithChildAndPet();

        for ($i = 0; $i < 25; $i++) {
            ActivityLog::create(['pet_id' => $pet->id, 'activity_type' => ActivityType::FedPet->value, 'value' => 100]);
        }

        actingAs($parent, 'sanctum');

        $response = getJson('/api/parent/activities?per_page=10');
        expect($response->json('data'))->toHaveCount(10);
        expect($response->json('meta.total'))->toBe(25);
    });

    it('rejects access from child profile', function () {
        $child = User::factory()->child()->create();

        actingAs($child, 'sanctum');

        getJson('/api/parent/activities')->assertStatus(403);
    });
});

describe('POST /api/parent/hard-stop', function () {
    it('toggles hard stop on and broadcasts update', function () {
        [$parent, $child, $pet] = createParentWithChildAndPet();

        actingAs($parent, 'sanctum');

        $response = postJson('/api/parent/hard-stop');

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Hard stop activated. Child app locked.',
                'is_hard_stopped' => true,
            ]);

        assertDatabaseHas('pets', [
            'id' => $pet->id,
            'is_hard_stopped' => true,
        ]);
    });

    it('toggles hard stop off when already active', function () {
        [$parent, $child, $pet] = createParentWithChildAndPet();
        $pet->update(['is_hard_stopped' => true]);

        actingAs($parent, 'sanctum');

        $response = postJson('/api/parent/hard-stop');

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Hard stop deactivated. Child app unlocked.',
                'is_hard_stopped' => false,
            ]);

        assertDatabaseHas('pets', [
            'id' => $pet->id,
            'is_hard_stopped' => false,
        ]);
    });

    it('rejects access from child profile', function () {
        $child = User::factory()->child()->create();

        actingAs($child, 'sanctum');

        postJson('/api/parent/hard-stop')->assertStatus(403);
    });

    it('returns 404 when no child is paired', function () {
        $parent = User::factory()->parent()->create();

        actingAs($parent, 'sanctum');

        postJson('/api/parent/hard-stop')->assertStatus(404);
    });

    it('requires authentication', function () {
        postJson('/api/parent/hard-stop')->assertUnauthorized();
    });
});
