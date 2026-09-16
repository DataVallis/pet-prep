<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a
| specific TestCase class, so tests may extend that class as needed.
|
*/

uses(TestCase::class, RefreshDatabase::class)->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet
| certain conditions. You may use the Expectation API to "expect" that
| a given value meets a given condition. We call these "expectations".
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have helper functions
| that you always need to register. These functions will always be available
| to your tests.
|
*/

    // Disable rate limiting in tests so functional tests aren't blocked
// by the throttle middleware (rate limits are tested separately).
beforeEach(function () {
    $this->withoutMiddleware([ThrottleRequests::class]);
});

// Helper to seed breed configs in tests
function seedBreedConfigs(): void
{
    \Illuminate\Support\Facades\DB::table('breed_configs')->insert([
        ['breed_slug' => 'mutt', 'daily_steps_required' => 4000, 'hunger_decay_rate' => 8.0, 'premium_unlock' => false, 'created_at' => now(), 'updated_at' => now()],
        ['breed_slug' => 'border-collie', 'daily_steps_required' => 10000, 'hunger_decay_rate' => 12.0, 'premium_unlock' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
}

function createParentUser(array $attributes = []): \App\Models\User
{
    return \App\Models\User::factory()->create(array_merge([
        'role' => 'parent',
    ], $attributes));
}

function createChildUser(array $attributes = []): \App\Models\User
{
    return \App\Models\User::factory()->create(array_merge([
        'role' => 'child',
    ], $attributes));
}
