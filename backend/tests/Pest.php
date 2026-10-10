<?php

use App\Models\FamilyMember;
use App\Models\Pet;
use App\Models\QuietHours;
use App\Models\User;
use App\Services\BehaviourEventService;
use App\Services\HygieneEventService;
use Database\Seeders\BreedConfigsSeeder;
use Database\Seeders\BreedStageParamsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
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

/**
 * Authenticate as $user through Sanctum with the token ability of their role
 * (`parent` / `child`, M2-03) — what a real login issues. Replaces
 * Sanctum::actingAs($user), whose mock token throws on an unexpected
 * ability instead of answering 403.
 */
function actingAsRole(User $user): User
{
    test()->actingAs($user, 'sanctum');

    return $user;
}

// Helper to seed breed configs in tests — the real (production) seeder, so
// tests always run against the canonical tunables (M1-06). Only the
// breed_configs rows: without life-stage data (M5-R01) a pet keeps the
// pre-M5 rules (breed feed windows, daily_steps_required), which the older
// game-loop tests are written against. Life-stage tests call
// seedLifeStageData() (or seedBreedConfigs() + seedStageParams()).
function seedBreedConfigs(): void
{
    (new BreedConfigsSeeder)->seedConfigs();
}

/**
 * The sourced life-stage rows (M5-R01, BreedStageParamsSeeder) for the seeded breeds.
 */
function seedStageParams(): void
{
    (new BreedStageParamsSeeder)->run();
}

/**
 * Everything the production deploy seeds: breed configs + life-stage data.
 */
function seedLifeStageData(): void
{
    (new BreedConfigsSeeder)->run();
}

/**
 * Stop random hygiene events (M1-05) and behaviour events (M5-R02 puppy
 * accidents, chewing — they are messes too) for a pet in tests that are
 * about something else: marks every day up to 2999 as already scheduled and
 * starts the puppy's bladder clock in 2999 (a take-out restarts it).
 */
function disableHygieneEvents(Pet $pet): Pet
{
    Pet::whereKey($pet->id)->update([
        'hygiene_scheduled_through' => '2999-12-31',
        'behaviour_scheduled_through' => '2999-12-31',
        'potty_clock_started_at' => '2999-12-31 00:00:00',
    ]);

    return $pet->refresh();
}

/**
 * Set a family's quiet hours exactly to $attributes (fields left out are
 * null, is_active defaults to true) — what `QuietHours::create()` did before
 * every family got a default row (fix/quiet-hours-default, 2026-10-08).
 * Pass `parent_id` and/or `family_id` like the old create() call.
 */
function setQuietHours(array $attributes): QuietHours
{
    $familyId = $attributes['family_id']
        ?? (isset($attributes['parent_id']) ? FamilyMember::where('user_id', $attributes['parent_id'])->value('family_id') : null);
    $values = array_merge(
        ['school_start' => null, 'school_end' => null, 'bedtime_start' => null, 'bedtime_end' => null, 'is_active' => true],
        $attributes,
    );

    $row = ($familyId !== null ? QuietHours::where('family_id', $familyId)->first() : null)
        ?? (isset($attributes['parent_id']) ? QuietHours::where('parent_id', $attributes['parent_id'])->first() : null);
    if ($row === null) {
        return QuietHours::create($values);
    }

    $row->update(array_merge($values, $familyId !== null ? ['family_id' => $familyId] : []));

    return $row->refresh();
}

/**
 * A family whose parent switched quiet hours off (is_active = false): no
 * quiet time at all — tests written before every family had night quiet
 * hours (2026-10-08). Accepts the pet or any family member.
 */
function withoutQuietHours(Pet|User $subject): void
{
    $familyId = $subject instanceof Pet
        ? $subject->family_id
        : FamilyMember::where('user_id', $subject->id)->value('family_id');

    QuietHours::where('family_id', $familyId)->update(['is_active' => false]);
}

/**
 * Pin the behaviour-event RNG salt (M5-R02 chewing) so draws are reproducible.
 */
function useBehaviourSalt(string $salt = 'test-salt'): BehaviourEventService
{
    app()->forgetInstance(BehaviourEventService::class);
    $service = app()->makeWith(BehaviourEventService::class, ['seedSalt' => $salt]);
    app()->instance(BehaviourEventService::class, $service);

    return $service;
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

/*
|--------------------------------------------------------------------------
| Webhook signing helpers
|--------------------------------------------------------------------------
| A real ED25519 key pair stands in for fal.ai: its public key is served
| from a faked JWKS endpoint and every test webhook is signed with it.
*/

function falTestKeyPair(): string
{
    static $keyPair = null;

    return $keyPair ??= sodium_crypto_sign_keypair();
}

function fakeFalJwks(?string $keyPair = null): void
{
    $public = sodium_crypto_sign_publickey($keyPair ?? falTestKeyPair());
    $x = rtrim(strtr(base64_encode($public), '+/', '-_'), '=');

    Http::fake([
        'rest.fal.ai/.well-known/jwks.json' => Http::response(['keys' => [['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => $x]]]),
    ]);
}

/**
 * Send a webhook signed like fal.ai does.
 *
 * @param  array<string, mixed>  $body
 * @param  array<string, string>  $headerOverrides
 */
function sendFalWebhook(array $body, array $headerOverrides = [], ?string $keyPair = null, ?int $timestamp = null)
{
    $raw = json_encode($body);
    $requestId = (string) ($body['request_id'] ?? '');
    $userId = 'user-123';
    $timestamp = (string) ($timestamp ?? now()->timestamp);

    $message = implode("\n", [$requestId, $userId, $timestamp, hash('sha256', $raw)]);
    $signature = bin2hex(sodium_crypto_sign_detached($message, sodium_crypto_sign_secretkey($keyPair ?? falTestKeyPair())));

    $headers = array_merge([
        'X-Fal-Webhook-Request-Id' => $requestId,
        'X-Fal-Webhook-User-Id' => $userId,
        'X-Fal-Webhook-Timestamp' => $timestamp,
        'X-Fal-Webhook-Signature' => $signature,
    ], $headerOverrides);

    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return Pest\Laravel\call('POST', '/api/webhooks/fal-ai', [], [], [], $server, $raw);
}

/**
 * A real PNG (GD) of $width × $height — fal image answers must decode (M5-R11 breed portraits).
 */
function fakePngBytes(int $width = 1024, int $height = 1024): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 243, 245, 242));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/**
 * Fake a synchronous fal image call (FalGateway::run → https://fal.run/{endpoint})
 * answering one image on the fal media host, and that file's download. Combine
 * with Http::preventStrayRequests(); pass $status ≠ 200 for a fal error answer.
 */
function fakeFalImageRun(string $endpoint = 'fal-ai/nano-banana-pro', ?string $bytes = null, int $status = 200, string $file = 'portrait.png'): string
{
    $url = "https://v3.fal.media/files/test/{$file}";

    $bytes ??= fakePngBytes();

    // Closures: a fresh response per request (a shared one has its body stream read once).
    Http::fake([
        "fal.run/{$endpoint}" => fn () => $status === 200
            ? Http::response(['images' => [['url' => $url]]], 200, ['x-fal-request-id' => 'req-'.$file])
            : Http::response(['detail' => 'error'], $status),
        "v3.fal.media/files/test/{$file}" => fn () => Http::response($bytes, 200, ['Content-Type' => 'image/png']),
    ]);

    return $url;
}
