<?php

use App\Enums\BreedType;
use App\Enums\UserRole;
use App\Models\Pet;
use App\Models\User;

use function Pest\Laravel\{assertDatabaseHas, postJson};

/*
|--------------------------------------------------------------------------
| RevenueCat Webhook Tests
|--------------------------------------------------------------------------
*/

describe('POST /api/webhooks/revenuecat', function () {
    it('unlocks Border Collie breed on successful purchase', function () {
        seedBreedConfigs();
        $parent = User::factory()->parent()->create();
        $child = User::factory()->child()->create(['parent_id' => $parent->id]);
        $pet = Pet::factory()->mutt()->create(['user_id' => $child->id]);

        $payload = [
            'event' => [
                'type' => 'NON_RENEWING_PURCHASE',
                'app_user_id' => (string) $parent->id,
                'subscriber_id' => 'rc_customer_12345',
                'product_id' => 'border_collie_unlock',
                'store' => 'APP_STORE',
            ],
        ];

        $response = postJson('/api/webhooks/revenuecat', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Border Collie breed unlocked successfully.',
                'breed_type' => 'border_collie',
            ]);

        // User's revenuecat_id should be updated
        assertDatabaseHas('users', [
            'id' => $parent->id,
            'revenuecat_id' => 'rc_customer_12345',
        ]);

        // Pet's breed should be updated to Border Collie
        assertDatabaseHas('pets', [
            'id' => $pet->id,
            'breed_type' => BreedType::BorderCollie->value,
        ]);
    });

    it('unlocks Border Collie when webhook is for the child user', function () {
        seedBreedConfigs();
        $parent = User::factory()->parent()->create();
        $child = User::factory()->child()->create(['parent_id' => $parent->id]);
        $pet = Pet::factory()->mutt()->create(['user_id' => $child->id]);

        $payload = [
            'event' => [
                'type' => 'NON_RENEWING_PURCHASE',
                'app_user_id' => (string) $child->id,
                'subscriber_id' => 'rc_customer_67890',
                'product_id' => 'border_collie_unlock',
                'store' => 'PLAY_STORE',
            ],
        ];

        $response = postJson('/api/webhooks/revenuecat', $payload);

        $response->assertStatus(200);
        assertDatabaseHas('pets', [
            'id' => $pet->id,
            'breed_type' => BreedType::BorderCollie->value,
        ]);
    });

    it('records revenuecat_id without breed change for unknown product', function () {
        seedBreedConfigs();
        $parent = User::factory()->parent()->create();

        $payload = [
            'event' => [
                'type' => 'NON_RENEWING_PURCHASE',
                'app_user_id' => (string) $parent->id,
                'subscriber_id' => 'rc_customer_99999',
                'product_id' => 'some_other_product',
                'store' => 'APP_STORE',
            ],
        ];

        $response = postJson('/api/webhooks/revenuecat', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Purchase recorded.',
                'revenuecat_id' => 'rc_customer_99999',
            ]);

        assertDatabaseHas('users', [
            'id' => $parent->id,
            'revenuecat_id' => 'rc_customer_99999',
        ]);
    });

    it('ignores non-purchase event types', function () {
        seedBreedConfigs();
        $parent = User::factory()->parent()->create();

        $payload = [
            'event' => [
                'type' => 'SUBSCRIPTION_EXPIRED',
                'app_user_id' => (string) $parent->id,
            ],
        ];

        $response = postJson('/api/webhooks/revenuecat', $payload);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Event ignored.']);
    });

    it('returns 422 when app_user_id is missing', function () {
        seedBreedConfigs();
        $payload = [
            'event' => [
                'type' => 'NON_RENEWING_PURCHASE',
            ],
        ];

        postJson('/api/webhooks/revenuecat', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['event.app_user_id']);
    });

    it('returns 404 when user is not found', function () {
        seedBreedConfigs();
        $payload = [
            'event' => [
                'type' => 'NON_RENEWING_PURCHASE',
                'app_user_id' => '999999',
                'subscriber_id' => 'rc_test',
                'product_id' => 'border_collie_unlock',
            ],
        ];

        postJson('/api/webhooks/revenuecat', $payload)
            ->assertStatus(404);
    });

    it('accepts INITIAL_PURCHASE event type', function () {
        seedBreedConfigs();
        $parent = User::factory()->parent()->create();
        $child = User::factory()->child()->create(['parent_id' => $parent->id]);
        $pet = Pet::factory()->mutt()->create(['user_id' => $child->id]);

        $payload = [
            'event' => [
                'type' => 'INITIAL_PURCHASE',
                'app_user_id' => (string) $parent->id,
                'subscriber_id' => 'rc_initial_123',
                'product_id' => 'border_collie_unlock',
            ],
        ];

        $response = postJson('/api/webhooks/revenuecat', $payload);

        $response->assertStatus(200);
        assertDatabaseHas('pets', [
            'id' => $pet->id,
            'breed_type' => BreedType::BorderCollie->value,
        ]);
    });
});
