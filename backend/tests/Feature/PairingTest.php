<?php

use App\Models\Pet;
use App\Models\User;
use App\Services\PairingService;
use App\Enums\UserRole;
use App\Enums\BreedType;

use function Pest\Laravel\{actingAs, assertDatabaseHas, postJson};

/*
|--------------------------------------------------------------------------
| Pairing Feature Tests
|--------------------------------------------------------------------------
|
| Tests covering the PIN generation, PIN expiration, and the atomic
| child-parent pairing transaction flow.
|
*/

describe('POST /api/parent/generate-pin', function () {
    it('generates a 6-digit PIN for an authenticated parent', function () {
        $parent = User::factory()->parent()->create();

        actingAs($parent, 'sanctum');

        $response = postJson('/api/parent/generate-pin');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'pin',
                'expires_at',
                'expires_in_minutes',
            ]);

        $pin = $response->json('pin');
        expect($pin)
            ->toBeString()
            ->toHaveLength(6)
            ->toMatch('/^[0-9]{6}$/');

        expect($response->json('expires_in_minutes'))->toBe(15);

        // PIN should be stored on the parent user
        assertDatabaseHas('users', [
            'id' => $parent->id,
            'pairing_pin' => $pin,
        ]);
    });

    it('rejects PIN generation from a child profile', function () {
        $child = User::factory()->child()->create();

        actingAs($child, 'sanctum');

        postJson('/api/parent/generate-pin')
            ->assertStatus(403);
    });

    it('requires authentication', function () {
        postJson('/api/parent/generate-pin')
            ->assertUnauthorized();
    });
});

describe('POST /api/child/pair', function () {
    it('pairs a child to a parent via valid PIN and creates a pet', function () {
        $parent = User::factory()->parent()->create();
        $child = User::factory()->child()->create(['parent_id' => null]);

        // Generate PIN via the service
        $service = app(PairingService::class);
        $result = $service->generatePin($parent);
        $pin = $result['pin'];

        actingAs($child, 'sanctum');

        $response = postJson('/api/child/pair', ['pin' => $pin]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'parent_id',
                'pet' => [
                    'id',
                    'breed_type',
                    'hunger_level',
                    'energy_level',
                    'hygiene_level',
                    'born_at',
                    'is_active',
                    'pet_dna' => [
                        'seed',
                        'prompt_anchor',
                        'visual_traits',
                        'reference_image_url',
                    ],
                    'current_video_url',
                ],
            ]);

        // Child should be linked to parent
        assertDatabaseHas('users', [
            'id' => $child->id,
            'parent_id' => $parent->id,
            'role' => UserRole::Child->value,
        ]);

        // Pet should be created with default Mutt breed and 100% metrics
        assertDatabaseHas('pets', [
            'user_id' => $child->id,
            'breed_type' => BreedType::Mutt->value,
            'hunger_level' => 100,
            'energy_level' => 100,
            'hygiene_level' => 100,
            'is_active' => true,
        ]);

        // Pet DNA should have been generated and stored (seed + prompt_anchor)
        $pet = Pet::where('user_id', $child->id)->first();
        expect($pet->pet_dna)->not->toBeNull();
        expect($pet->pet_dna['seed'])->toBeInt();
        expect($pet->pet_dna['prompt_anchor'])->toBeString();
        expect($pet->pet_dna['visual_traits'])->toBeArray();

        // PIN should be consumed (cleared) after successful pairing
        assertDatabaseHas('users', [
            'id' => $parent->id,
            'pairing_pin' => null,
            'pin_expires_at' => null,
        ]);
    });

    it('rejects pairing with an expired PIN', function () {
        $parent = User::factory()->parent()->create();
        $child = User::factory()->child()->create(['parent_id' => null]);

        // Generate PIN but manually expire it
        $parent->update([
            'pairing_pin' => '123456',
            'pin_expires_at' => now()->subMinutes(1),
        ]);

        actingAs($child, 'sanctum');

        postJson('/api/child/pair', ['pin' => '123456'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This code cannot be used. Ask your parent for a new code.');

        // Child should NOT be paired
        expect($child->fresh()->parent_id)->toBeNull();
    });

    it('rejects pairing with an invalid PIN that does not exist', function () {
        $child = User::factory()->child()->create(['parent_id' => null]);

        actingAs($child, 'sanctum');

        postJson('/api/child/pair', ['pin' => '999999'])
            ->assertStatus(422);
    });

    it('rejects an already-paired child', function () {
        $parent = User::factory()->parent()->create();
        $child = User::factory()->child()->create(['parent_id' => $parent->id]);

        $service = app(PairingService::class);
        $result = $service->generatePin($parent);

        actingAs($child, 'sanctum');

        postJson('/api/child/pair', ['pin' => $result['pin']])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This code cannot be used. Ask your parent for a new code.');
    });

    it('validates the PIN format', function () {
        $child = User::factory()->child()->create(['parent_id' => null]);

        actingAs($child, 'sanctum');

        // Too short
        postJson('/api/child/pair', ['pin' => '123'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pin']);

        // Non-numeric
        postJson('/api/child/pair', ['pin' => 'abcdef'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pin']);

        // Missing
        postJson('/api/child/pair', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pin']);
    });

    it('requires authentication', function () {
        postJson('/api/child/pair', ['pin' => '123456'])
            ->assertUnauthorized();
    });
});

describe('PairingService PIN generation', function () {
    it('generates a unique 6-digit PIN', function () {
        $parent = User::factory()->parent()->create();

        $service = app(PairingService::class);
        $result = $service->generatePin($parent);

        expect($result['pin'])
            ->toBeString()
            ->toHaveLength(6)
            ->toMatch('/^[0-9]{6}$/');

        expect($result['expires_at'])->toBeInstanceOf(\Illuminate\Support\Carbon::class);
        expect($result['expires_at']->isFuture())->toBeTrue();
    });

    it('sets a 15-minute expiration window', function () {
        $parent = User::factory()->parent()->create();

        $service = app(PairingService::class);
        $result = $service->generatePin($parent);

        $expectedExpiry = now()->addMinutes(15);

        // Allow a few seconds of variance
        expect($result['expires_at']->diffInSeconds($expectedExpiry))->toBeLessThan(5);
    });

    it('only allows parent profiles to generate PINs', function () {
        $child = User::factory()->child()->create();

        $service = app(PairingService::class);

        expect(fn () => $service->generatePin($child))
            ->toThrow(\DomainException::class);
    });

    it('hasValidPairingPin returns true for fresh PIN and false for expired', function () {
        $parent = User::factory()->parent()->create();

        $service = app(PairingService::class);
        $service->generatePin($parent);

        expect($parent->fresh()->hasValidPairingPin())->toBeTrue();

        // Expire the PIN
        $parent->fresh()->update(['pin_expires_at' => now()->subMinute()]);

        expect($parent->fresh()->hasValidPairingPin())->toBeFalse();
    });
});
