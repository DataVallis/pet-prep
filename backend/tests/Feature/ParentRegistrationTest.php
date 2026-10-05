<?php

use App\Enums\FamilyRole;
use App\Enums\UserRole;
use App\Http\Requests\RegisterParentRequest;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\FamilyService;
use App\Services\ParentRegistrationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

/*
| M2-10a — parent self-registration with e-mail + password.
| The `register` limiter is live in testing (5/min, 20/h per IP); every test
| uses its own IP unless it is about the limit.
*/

beforeEach(function () {
    Http::preventStrayRequests(); // no HIBP or any other external call
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function regPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Mama Ana',
        'email' => 'ana@example.com',
        'password' => 'Varno1Geslo',
        'password_confirmation' => 'Varno1Geslo',
        'timezone' => 'Europe/Ljubljana',
        'accept_terms' => true,
        'device_name' => 'iPhone 16',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function regPost(array $overrides = [], string $ip = '198.51.100.40'): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => ''])->withServerVariables(['REMOTE_ADDR' => $ip]);

    return postJson('/api/register', regPayload($overrides));
}

describe('POST /api/register', function () {
    it('creates a parent with their own family and signs them in (login shape, parent ability)', function () {
        $res = regPost()->assertCreated()
            ->assertJsonStructure(['token', 'abilities', 'user' => ['id', 'name', 'email', 'role'], 'pet'])
            ->assertJsonPath('abilities', ['parent'])
            ->assertJsonPath('user.role', 'parent')
            ->assertJsonPath('user.name', 'Mama Ana')
            ->assertJsonPath('user.email', 'ana@example.com')
            ->assertJsonPath('pet', null)
            ->assertJsonPath('awaiting_contract', null)
            ->assertJsonMissingPath('user.terms_version')
            ->assertJsonMissingPath('user.terms_accepted_at');

        $user = User::sole();
        expect($user->role)->toBe(UserRole::Parent)
            ->and(Hash::check('Varno1Geslo', $user->password))->toBeTrue()
            ->and($user->terms_accepted_at)->not->toBeNull()
            ->and($user->terms_version)->toBe('draft-2026-10')
            ->and($user->email_verified_at)->toBeNull();

        $member = FamilyMember::where('user_id', $user->id)->sole();
        expect($member->role)->toBe(FamilyRole::Parent)
            ->and(Family::count())->toBe(1);

        $token = PersonalAccessToken::findToken($res->json('token'));
        expect($token->abilities)->toBe(['parent'])
            ->and($token->name)->toBe('iPhone 16');
    });

    it('lets the new token call the parent dashboard (empty family) but not child routes', function () {
        $token = regPost()->assertCreated()->json('token');

        app('auth')->forgetGuards();
        getJson('/api/parent/dashboard', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('pet', null)
            ->assertJsonPath('message', 'No child profile paired yet.')
            ->assertJsonPath('timezone', 'Europe/Ljubljana')
            ->assertJsonPath('family.children', [])
            ->assertJsonPath('family.pets', []);

        app('auth')->forgetGuards();
        getJson('/api/user', ['Authorization' => "Bearer {$token}"])
            ->assertOk()->assertJsonPath('role', 'parent');

        app('auth')->forgetGuards();
        getJson('/api/child/pet', ['Authorization' => "Bearer {$token}"])->assertForbidden();
    });

    it('creates the family in the device timezone, and defaults to Europe/Ljubljana', function () {
        regPost(['email' => 'ny@example.com', 'timezone' => 'America/New_York'], '198.51.100.41')->assertCreated();
        $ny = User::where('email', 'ny@example.com')->sole();
        expect($ny->family->timezone)->toBe('America/New_York')
            ->and($ny->timezone)->toBe('America/New_York')
            ->and($ny->familyTimezone())->toBe('America/New_York');

        $payload = regPayload(['email' => 'lj@example.com']);
        unset($payload['timezone']);
        app('auth')->forgetGuards();
        test()->withServerVariables(['REMOTE_ADDR' => '198.51.100.42']);
        postJson('/api/register', $payload)->assertCreated();
        expect(User::where('email', 'lj@example.com')->sole()->family->timezone)->toBe('Europe/Ljubljana');

        regPost(['email' => 'bad@example.com', 'timezone' => 'Mars/Olympus'], '198.51.100.43')
            ->assertUnprocessable()->assertJsonValidationErrors('timezone');
    });

    it('trims and lower-cases the e-mail, and rejects a duplicate case-insensitively', function () {
        regPost(['email' => '  Ana@Example.COM '], '198.51.100.44')->assertCreated()
            ->assertJsonPath('user.email', 'ana@example.com');

        regPost(['email' => 'ANA@example.com'], '198.51.100.45')
            ->assertUnprocessable()->assertJsonValidationErrors('email')
            ->assertJsonPath('codes', ['email' => 'email_taken']);

        // A legacy mixed-case row (created before M2-10a) is a duplicate too.
        User::factory()->create(['email' => 'Legacy@Example.com', 'role' => 'parent']);
        regPost(['email' => 'legacy@example.com'], '198.51.100.46')
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        expect(User::whereRaw('lower(email) = ?', ['ana@example.com'])->count())->toBe(1);
    });

    it('lets the new parent sign in with any letter case of the e-mail', function () {
        regPost([], '198.51.100.47')->assertCreated();

        app('auth')->forgetGuards();
        postJson('/api/login', ['email' => 'Ana@Example.com', 'password' => 'Varno1Geslo'])
            ->assertOk()->assertJsonPath('user.role', 'parent')->assertJsonPath('abilities', ['parent']);
    });

    it('requires the terms to be accepted', function () {
        regPost(['accept_terms' => false], '198.51.100.48')->assertUnprocessable()->assertJsonValidationErrors('accept_terms');

        $payload = regPayload();
        unset($payload['accept_terms']);
        app('auth')->forgetGuards();
        test()->withServerVariables(['REMOTE_ADDR' => '198.51.100.48']);
        postJson('/api/register', $payload)->assertUnprocessable()->assertJsonValidationErrors('accept_terms');

        expect(User::count())->toBe(0)->and(Family::count())->toBe(0);
    });

    it('validates name, e-mail and the password policy, with a code per field', function (array $overrides, string $field, string $code) {
        regPost($overrides, '198.51.100.49')->assertUnprocessable()
            ->assertJsonValidationErrors($field)
            ->assertJsonPath("codes.{$field}", $code);
        expect(User::count())->toBe(0);
    })->with([
        'missing name' => [['name' => ''], 'name', 'name_invalid'],
        'whitespace name' => [['name' => '   '], 'name', 'name_invalid'],
        'name too long' => [['name' => str_repeat('a', RegisterNameLimit::MAX + 1)], 'name', 'name_invalid'],
        'bad e-mail' => [['email' => 'not-an-email'], 'email', 'email_invalid'],
        'short password' => [['password' => 'Kratko1x', 'password_confirmation' => 'Kratko1x'], 'password', 'password_weak'],
        'no digit' => [['password' => 'BrezStevilke', 'password_confirmation' => 'BrezStevilke'], 'password', 'password_weak'],
        'no upper case' => [['password' => 'malecrke123', 'password_confirmation' => 'malecrke123'], 'password', 'password_weak'],
        'no lower case' => [['password' => 'VELIKECRKE123', 'password_confirmation' => 'VELIKECRKE123'], 'password', 'password_weak'],
        'confirmation mismatch' => [['password_confirmation' => 'Drugo1Geslo'], 'password', 'password_mismatch'],
        'weak and mismatched' => [['password' => 'kratko', 'password_confirmation' => 'drugo'], 'password', 'password_weak'],
        'terms not accepted' => [['accept_terms' => false], 'accept_terms', 'terms_required'],
        'not a timezone' => [['timezone' => 'Mars/Olympus'], 'timezone', 'timezone_invalid'],
    ]);

    it('accepts a 60-character name with any script', function () {
        regPost(['name' => str_repeat('Ž', RegisterNameLimit::MAX)], '198.51.100.50')->assertCreated();
    });

    it('never issues a child token: role and ability are fixed to parent', function () {
        $res = regPost(['role' => 'child', 'abilities' => ['child'], 'is_superadmin' => true], '198.51.100.51')
            ->assertCreated()
            ->assertJsonPath('user.role', 'parent')
            ->assertJsonPath('abilities', ['parent']);

        $user = User::sole();
        expect($user->role)->toBe(UserRole::Parent)
            ->and($user->is_superadmin)->toBeFalse()
            ->and(PersonalAccessToken::findToken($res->json('token'))->abilities)->toBe(['parent']);
    });

    it('throttles per IP: 5 per minute, 20 per hour', function () {
        foreach (range(1, AppServiceProvider::REGISTER_PER_MINUTE) as $i) {
            regPost(['email' => "p{$i}@example.com"], '203.0.113.60')->assertCreated();
        }
        regPost(['email' => 'p6@example.com'], '203.0.113.60')
            ->assertStatus(429)->assertHeader('Retry-After');
        // Another IP is not affected.
        regPost(['email' => 'other@example.com'], '203.0.113.61')->assertCreated();

        // Hourly cap: after the minute window, 20 in total per hour.
        $sent = AppServiceProvider::REGISTER_PER_MINUTE;
        while ($sent < AppServiceProvider::REGISTER_PER_HOUR) {
            $this->travel(61)->seconds();
            for ($j = 0; $j < AppServiceProvider::REGISTER_PER_MINUTE && $sent < AppServiceProvider::REGISTER_PER_HOUR; $j++) {
                $sent++;
                regPost(['email' => "h{$sent}@example.com"], '203.0.113.60')->assertCreated();
            }
        }
        $this->travel(61)->seconds();
        regPost(['email' => 'over@example.com'], '203.0.113.60')->assertStatus(429);
    });

    it('counts failed (422) attempts against the limit too', function () {
        foreach (range(1, AppServiceProvider::REGISTER_PER_MINUTE) as $i) {
            regPost(['accept_terms' => false], '203.0.113.62')->assertUnprocessable();
        }
        regPost([], '203.0.113.62')->assertStatus(429);
        expect(User::count())->toBe(0);
    });
});

describe('device timezone aliases (PR #25)', function () {
    it('maps legacy / alias names to the canonical IANA zone instead of a 422', function (string $sent, string $stored) {
        regPost(['timezone' => $sent], '198.51.100.70')->assertCreated();
        expect(User::sole()->family->timezone)->toBe($stored)
            ->and(User::sole()->timezone)->toBe($stored);
    })->with([
        'Etc/UTC' => ['Etc/UTC', 'UTC'],
        'Etc/GMT' => ['Etc/GMT', 'UTC'],
        'Asia/Calcutta' => ['Asia/Calcutta', 'Asia/Kolkata'],
        'Europe/Kiev' => ['Europe/Kiev', 'Europe/Kyiv'],
        'Asia/Saigon' => ['Asia/Saigon', 'Asia/Ho_Chi_Minh'],
        'lower case' => ['europe/ljubljana', 'Europe/Ljubljana'],
        'padded' => ['  Europe/Vienna ', 'Europe/Vienna'],
    ]);

    it('falls back to Europe/Ljubljana (logged) for a valid but unknown zone', function () {
        Log::spy();
        regPost(['timezone' => '+02:00'], '198.51.100.71')->assertCreated();
        expect(User::sole()->family->timezone)->toBe('Europe/Ljubljana');
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'Unknown timezone alias "+02:00"'))->once();
    });

    it('normalises PUT /api/parent/settings and the quiet-hours timezone the same way', function () {
        $parent = User::factory()->create(['role' => 'parent']);
        actingAsRole($parent);

        putJson('/api/parent/settings', ['timezone' => 'Asia/Calcutta'])->assertOk();
        expect($parent->fresh()->familyTimezone())->toBe('Asia/Kolkata');

        putJson('/api/parent/quiet-hours', ['bedtime_start' => '21:00', 'bedtime_end' => '07:00', 'is_active' => true, 'timezone' => 'Europe/Kiev'])
            ->assertOk();
        expect($parent->fresh()->familyTimezone())->toBe('Europe/Kyiv');

        putJson('/api/parent/settings', ['timezone' => 'europe/vienna'])->assertOk();
        expect($parent->fresh()->familyTimezone())->toBe('Europe/Vienna');

        // No silent fallback for an explicit settings change: offsets,
        // abbreviations and garbage stay a 422 (only sign-up falls back).
        foreach (['Mars/Olympus', '+02:00', 'CEST'] as $bad) {
            putJson('/api/parent/settings', ['timezone' => $bad])->assertUnprocessable()->assertJsonValidationErrors('timezone');
        }
        expect($parent->fresh()->familyTimezone())->toBe('Europe/Vienna');
    });
});

describe('e-mail uniqueness in the database (PR #25)', function () {
    it('refuses two e-mails that differ only in case (lower(email) unique index)', function () {
        User::factory()->create(['email' => 'same@example.com', 'role' => 'parent']);

        expect(fn () => User::factory()->create(['email' => 'Same@Example.com', 'role' => 'parent']))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('answers 422 email_taken when a concurrent sign-up wins after validation passed', function () {
        // Simulate the race: validation has passed, then — before our
        // transaction — another request stores the same address.
        app()->bind(ParentRegistrationService::class, fn ($app) => new class($app->make(FamilyService::class)) extends ParentRegistrationService
        {
            public function register(string $name, string $email, string $password, string $timezone, string $deviceName): array
            {
                DB::table('users')->insert([
                    'name' => 'Racer', 'email' => 'ana@example.com', 'password' => 'x',
                    'role' => 'parent', 'timezone' => 'Europe/Ljubljana', 'created_at' => now(), 'updated_at' => now(),
                ]);

                return parent::register($name, $email, $password, $timezone, $deviceName);
            }
        });

        regPost([], '198.51.100.72')->assertUnprocessable()
            ->assertJsonPath('codes', ['email' => 'email_taken'])
            ->assertJsonValidationErrors('email');

        expect(User::count())->toBe(1)
            ->and(User::sole()->name)->toBe('Racer')
            ->and(Family::count())->toBe(0)
            ->and(PersonalAccessToken::count())->toBe(0);
    });

    it('rethrows a unique violation that is not about the e-mail', function () {
        DB::statement('CREATE UNIQUE INDEX test_users_name_unique ON users (name)');
        User::factory()->create(['email' => 'first@example.com', 'name' => 'Mama Ana', 'role' => 'parent']);

        expect(fn () => app(ParentRegistrationService::class)->register('Mama Ana', 'other@example.com', 'Varno1Geslo', 'Europe/Ljubljana', 'x'))
            ->toThrow(UniqueConstraintViolationException::class, 'test_users_name_unique');
    });

    it('migration: unique lower(email) index without duplicates, plain index + logged ids with them', function () {
        $migration = require database_path('migrations/2026_10_08_120100_add_lower_email_index_to_users_table.php');
        $indexes = fn (): array => collect(DB::select("SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'users' AND indexname LIKE 'users_email_lower%'"))
            ->pluck('indexdef', 'indexname')->all();

        expect($indexes())->toHaveKey('users_email_lower_unique');

        $migration->down();
        $a = User::factory()->create(['email' => 'dup@example.com', 'role' => 'parent']);
        $b = User::factory()->create(['email' => 'DUP@example.com', 'role' => 'parent']);

        Log::spy();
        $migration->up();
        expect($indexes())->toHaveKey('users_email_lower_index')->not->toHaveKey('users_email_lower_unique')
            ->and($indexes()['users_email_lower_index'])->not->toContain('UNIQUE');
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, "{{$a->id},{$b->id}}"))->once();

        $migration->down();
        $b->delete();
        $migration->up();
        expect($indexes())->toHaveKey('users_email_lower_unique')->not->toHaveKey('users_email_lower_index');
    });
});

/** Mirrors RegisterParentRequest::MAX_NAME_LENGTH for dataset readability. */
final class RegisterNameLimit
{
    public const MAX = RegisterParentRequest::MAX_NAME_LENGTH;
}
