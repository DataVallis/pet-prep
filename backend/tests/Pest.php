<?php

use App\Models\Pet;
use App\Models\User;
use App\Services\HygieneEventService;
use Database\Seeders\BreedConfigsSeeder;
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

// Helper to seed breed configs in tests — the real (production) seeder, so
// tests always run against the canonical tunables (M1-06).
function seedBreedConfigs(): void
{
    (new BreedConfigsSeeder)->run();
}

/**
 * Stop random hygiene events (M1-05) for a pet in tests that are about
 * something else: marks every day up to 2999 as already scheduled.
 */
function disableHygieneEvents(Pet $pet): Pet
{
    Pet::whereKey($pet->id)->update(['hygiene_scheduled_through' => '2999-12-31']);

    return $pet->refresh();
}

/**
 * Pin the hygiene-event RNG salt so schedules are reproducible in a test.
 */
function useHygieneSalt(string $salt = 'test-salt'): HygieneEventService
{
    $service = new HygieneEventService($salt);
    app()->instance(HygieneEventService::class, $service);

    return $service;
}

function createParentUser(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'parent',
    ], $attributes));
}

function createChildUser(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'child',
    ], $attributes));
}
