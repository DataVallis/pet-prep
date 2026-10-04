<?php

use App\Enums\FamilyRole;
use App\Enums\UserRole;
use App\Models\ChildLoginPin;
use App\Models\FamilyMember;
use App\Models\Pet;
use App\Models\PetCaretaker;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\ChildPinLoginService;
use App\Services\ChildProfileService;
use App\Services\FamilyInviteService;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| M2-02 / M2-03 — child profile without e-mail, PIN-only login, abilities
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    seedBreedConfigs();
    // The `pin-login` route limiter is live in testing (unlike the others);
    // only the route-throttle test below re-enables the middleware.
    $this->withoutMiddleware([ThrottleRequests::class]);
});

const PL_SVG = ['signature_format' => 'svg_path', 'signature' => 'M10 10 L20 20'];

function plParent(): User
{
    return User::factory()->parent()->create();
}

function plChild(User $parent, string $name = 'Maja', ?int $birthYear = null): User
{
    return app(ChildProfileService::class)->createChild($parent, $name, $birthYear);
}

/**
 * @return array{pin: string, mode: string}
 */
function plPin(User $parent, User $child, ?int $petId = null): array
{
    $result = app(ChildPinLoginService::class)->generatePin($parent, $child->id, $petId);

    return ['pin' => $result['pin'], 'mode' => $result['mode']];
}

/**
 * Unauthenticated request (forget any test or cached user first).
 */
function plGuest(string $ip = '198.51.100.7'): void
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => ''])->withServerVariables(['REMOTE_ADDR' => $ip]);
}

function plLogin(string $pin, string $device = 'Maja iPhone', string $ip = '198.51.100.7'): TestResponse
{
    plGuest($ip);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => $device]);
}

/**
 * Real bearer token (as the app sends it) — no test shortcut.
 */
function plBearer(string $token): void
{
    app('auth')->forgetGuards();
    test()->withToken($token);
}

function plUseReverb(): void
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
        'broadcasting.connections.reverb.options.host' => 'localhost',
    ]);
    app(BroadcastManager::class)->forgetDrivers();
    require base_path('routes/channels.php');
}

function plWrongPin(string ...$avoid): string
{
    do {
        $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    } while (in_array($pin, $avoid, true));

    return $pin;
}

// ─────────────────────────────────────────────────────────────────────────
describe('POST /api/parent/children', function () {
    it('creates a child profile without e-mail or password in the parent family', function () {
        $parent = plParent();
        actingAs($parent, 'sanctum');

        $response = postJson('/api/parent/children', ['display_name' => '  Maja   Mala ', 'birth_year' => 2017])
            ->assertCreated()
            ->assertJsonPath('child.display_name', 'Maja Mala')
            ->assertJsonPath('child.birth_year', 2017)
            ->assertJsonPath('child.family_id', $parent->family->id)
            ->assertJsonPath('child.pet_id', null)
            ->assertJsonPath('child.devices', 0);

        expect($response->json('child'))->not->toHaveKey('email');

        $child = User::findOrFail($response->json('child.id'));
        expect($child->email)->toBeNull()
            ->and($child->password)->toBeNull()
            ->and($child->role)->toBe(UserRole::Child)
            ->and($child->birth_year)->toBe(2017)
            ->and($child->parent_id)->toBe($parent->id)
            ->and(FamilyMember::where('user_id', $child->id)->first())
            ->family_id->toBe($parent->family->id)
            ->role->toBe(FamilyRole::Child);
    });

    it('allows several PIN-only children (NULL e-mails do not collide) and an optional birth year', function () {
        $parent = plParent();
        actingAs($parent, 'sanctum');

        postJson('/api/parent/children', ['display_name' => 'Ana'])->assertCreated()->assertJsonPath('child.birth_year', null);
        postJson('/api/parent/children', ['display_name' => 'Žan'])->assertCreated();

        expect(User::whereNull('email')->count())->toBe(2);
    });

    it('validates the nickname and birth year', function (array $body, string $field) {
        actingAs(plParent(), 'sanctum');

        postJson('/api/parent/children', $body)->assertUnprocessable()->assertJsonValidationErrors($field);
    })->with([
        'missing name' => [[], 'display_name'],
        'blank name' => [['display_name' => '   '], 'display_name'],
        'too long' => [['display_name' => str_repeat('a', 31)], 'display_name'],
        'e-mail as name' => [['display_name' => 'maja@example.com'], 'display_name'],
        'html' => [['display_name' => '<b>Maja</b>'], 'display_name'],
        'array name' => [['display_name' => ['Maja']], 'display_name'],
        'adult' => [['display_name' => 'Maja', 'birth_year' => (int) date('Y') - 19], 'birth_year'],
        'future' => [['display_name' => 'Maja', 'birth_year' => (int) date('Y') + 1], 'birth_year'],
        'not a number' => [['display_name' => 'Maja', 'birth_year' => 'twenty'], 'birth_year'],
    ]);

    it('accepts a 30-character nickname in any script', function () {
        actingAs(plParent(), 'sanctum');

        postJson('/api/parent/children', ['display_name' => str_repeat('č', 30)])->assertCreated();
    });

    it('refuses children, guests and the 11th child', function () {
        $parent = plParent();
        $legacyChild = User::factory()->child()->create(['parent_id' => $parent->id]);

        actingAs($legacyChild, 'sanctum');
        postJson('/api/parent/children', ['display_name' => 'X'])->assertForbidden();

        plGuest();
        postJson('/api/parent/children', ['display_name' => 'X'])->assertUnauthorized();

        // 1 legacy child + 9 profiles = 10.
        for ($i = 0; $i < 9; $i++) {
            plChild($parent, "Kid{$i}");
        }
        actingAs($parent, 'sanctum');
        postJson('/api/parent/children', ['display_name' => 'Eleven'])
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'too_many_children');
    });

    it('lists the profile on the family dashboard as a PIN login with its devices', function () {
        $parent = plParent();
        $child = plChild($parent, 'Maja', 2016);
        $legacy = User::factory()->child()->create(['parent_id' => $parent->id]);
        Pet::factory()->create(['user_id' => $legacy->id]);
        $legacy->createToken('old phone');

        actingAs($parent, 'sanctum');
        $children = collect(getJson('/api/parent/dashboard')->assertOk()->json('family.children'))->keyBy('id');

        expect($children[$child->id])->toMatchArray(['name' => 'Maja', 'birth_year' => 2016, 'login' => 'pin', 'devices' => 0, 'pet_id' => null])
            ->and($children[$legacy->id])->toMatchArray(['login' => 'email', 'devices' => 1]);
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('POST /api/parent/generate-pin {child_id}', function () {
    it('issues a one-time PIN for a new pet and stores only its HMAC', function () {
        $this->freezeTime();
        $parent = plParent();
        $child = plChild($parent);
        actingAs($parent, 'sanctum');

        $response = postJson('/api/parent/generate-pin', ['child_id' => $child->id])
            ->assertOk()
            ->assertJsonPath('child_id', $child->id)
            ->assertJsonPath('pet_id', null)
            ->assertJsonPath('mode', 'new_pet')
            ->assertJsonPath('expires_in_minutes', 15)
            ->assertHeaderMissing('Deprecation');

        $pin = $response->json('pin');
        expect($pin)->toMatch('/^[0-9]{6}$/');

        $row = ChildLoginPin::sole();
        expect($row->pin_hash)->toBe(hash_hmac('sha256', $pin, config('app.key')))
            ->and($row->child_user_id)->toBe($child->id)
            ->and($row->created_by)->toBe($parent->id)
            ->and($row->expires_at->equalTo(now()->addMinutes(15)->startOfSecond()))->toBeTrue()
            ->and(DB::table('child_login_pins')->where('pin_hash', $pin)->exists())->toBeFalse()
            // The legacy parent-row PIN is untouched.
            ->and($parent->fresh()->pairing_pin)->toBeNull();
    });

    it('issues a join PIN for a shared pet and a re-login PIN for a paired child', function () {
        $parent = plParent();
        $first = plChild($parent, 'Ana');
        $second = plChild($parent, 'Bor');
        $login = plLogin(plPin($parent, $first)['pin'])->assertOk();
        $petId = $login->json('pet.id');

        actingAs($parent, 'sanctum');
        postJson('/api/parent/generate-pin', ['child_id' => $second->id, 'pet_id' => $petId])
            ->assertOk()->assertJsonPath('mode', 'join_pet')->assertJsonPath('pet_id', $petId);
        postJson('/api/parent/generate-pin', ['child_id' => $first->id])
            ->assertOk()->assertJsonPath('mode', 'relogin');
        // Naming the child's own pet is a re-login too.
        postJson('/api/parent/generate-pin', ['child_id' => $first->id, 'pet_id' => $petId])
            ->assertOk()->assertJsonPath('mode', 'relogin');
    });

    it('refuses a child of another family, a missing child and a bad id', function () {
        $parent = plParent();
        $foreign = plChild(plParent());
        actingAs($parent, 'sanctum');

        postJson('/api/parent/generate-pin', ['child_id' => $foreign->id])->assertNotFound()->assertJsonPath('reason', 'child_not_found');
        postJson('/api/parent/generate-pin', ['child_id' => 999999])->assertNotFound()->assertJsonPath('reason', 'child_not_found');
        // A parent id is not a child id.
        postJson('/api/parent/generate-pin', ['child_id' => $parent->id])->assertNotFound();
        postJson('/api/parent/generate-pin', ['child_id' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('child_id');
        postJson('/api/parent/generate-pin', ['child_id' => [1]])->assertUnprocessable();

        expect(ChildLoginPin::count())->toBe(0);
    });

    it('refuses pets that cannot be joined and moving a paired child to another pet', function () {
        $parent = plParent();
        $ana = plChild($parent, 'Ana');
        $bor = plChild($parent, 'Bor');
        $cene = plChild($parent, 'Cene');
        $anaPet = plLogin(plPin($parent, $ana)['pin'])->json('pet.id');
        $borPet = plLogin(plPin($parent, $bor)['pin'])->json('pet.id');
        $foreignPet = Pet::factory()->create(['user_id' => User::factory()->child()->create(['parent_id' => plParent()->id])->id]);
        $gameOver = Pet::factory()->create(['user_id' => User::factory()->child()->create(['parent_id' => $parent->id])->id, 'is_game_over' => true, 'is_active' => false]);

        actingAs($parent, 'sanctum');
        postJson('/api/parent/generate-pin', ['child_id' => $cene->id, 'pet_id' => $foreignPet->id])->assertUnprocessable()->assertJsonPath('reason', 'pet_not_joinable');
        postJson('/api/parent/generate-pin', ['child_id' => $cene->id, 'pet_id' => $gameOver->id])->assertUnprocessable()->assertJsonPath('reason', 'pet_not_joinable');
        postJson('/api/parent/generate-pin', ['child_id' => $ana->id, 'pet_id' => $borPet])->assertUnprocessable()->assertJsonPath('reason', 'already_paired');

        expect($anaPet)->not->toBe($borPet);
    });

    it('lets the second parent of the family issue the PIN', function () {
        $parent = plParent();
        $child = plChild($parent);
        $second = plParent();
        $code = app(FamilyInviteService::class)->createInvite($parent)['code'];
        app(FamilyInviteService::class)->joinFamily($second, $code);

        actingAs($second, 'sanctum');
        postJson('/api/parent/generate-pin', ['child_id' => $child->id])->assertOk()->assertJsonPath('mode', 'new_pet');
    });

    it('keeps the legacy flow without child_id, marked deprecated', function () {
        $parent = plParent();
        actingAs($parent, 'sanctum');

        $response = postJson('/api/parent/generate-pin')
            ->assertOk()
            ->assertHeader('Deprecation', 'true')
            ->assertJsonPath('child_id', null)
            ->assertJsonPath('mode', 'new_pet');

        expect($parent->fresh()->pairing_pin)->toBe($response->json('pin'))
            ->and(ChildLoginPin::count())->toBe(0);
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('POST /api/child/pin-login', function () {
    it('pairs a new child: unborn pet, child token, no e-mail in the response', function () {
        $parent = plParent();
        $child = plChild($parent);
        $pin = plPin($parent, $child)['pin'];

        $response = plLogin($pin, 'Maja iPad')
            ->assertOk()
            ->assertJsonPath('abilities', ['child'])
            ->assertJsonPath('user', ['id' => $child->id, 'name' => 'Maja', 'role' => 'child'])
            ->assertJsonPath('mode', 'new_pet')
            ->assertJsonPath('joined_existing', false)
            ->assertJsonPath('family_id', $parent->family->id)
            ->assertJsonPath('awaiting_contract', true)
            ->assertJsonPath('pet.born_at', null)
            ->assertJsonPath('pet.awaiting_contract', true);

        $pet = Pet::findOrFail($response->json('pet.id'));
        expect($pet->family_id)->toBe($parent->family->id)
            ->and($pet->isUnborn())->toBeTrue()
            ->and($pet->hasCaretaker($child))->toBeTrue()
            ->and(json_encode($response->json()))->not->toContain('email')
            ->and(ChildLoginPin::sole()->consumed_at)->not->toBeNull();

        $token = PersonalAccessToken::sole();
        expect($token->tokenable_id)->toBe($child->id)
            ->and($token->abilities)->toBe(['child'])
            ->and($token->name)->toBe('Maja iPad');
    });

    it('accepts the PIN with the spaces shown on the parent screen', function () {
        $parent = plParent();
        $pin = plPin($parent, plChild($parent))['pin'];

        plLogin(substr($pin, 0, 3).' '.substr($pin, 3))->assertOk();
    });

    it('then runs the contract flow with the PIN token: locked until signed, born at signing', function () {
        $parent = plParent();
        $child = plChild($parent);
        $token = plLogin(plPin($parent, $child)['pin'])->json('token');

        plBearer($token);
        getJson('/api/user')->assertOk()->assertJsonPath('id', $child->id)->assertJsonPath('email', null)->assertJsonPath('role', 'child');
        getJson('/api/child/pet')->assertOk()->assertJsonPath('lock.reason', 'contract_required');
        postJson('/api/child/pet/clean')->assertStatus(423)->assertJsonPath('reason', 'contract_required');

        postJson('/api/child/contract', PL_SVG)->assertCreated();

        $pet = $child->currentPet();
        expect($pet->isUnborn())->toBeFalse();
        getJson('/api/child/pet')->assertOk()->assertJsonPath('lock.is_locked', false)->assertJsonPath('pet.awaiting_contract', false);
    });

    it('joins a shared pet without re-birth; the joining child must sign their own contract', function () {
        $parent = plParent();
        $ana = plChild($parent, 'Ana');
        $bor = plChild($parent, 'Bor');
        $anaToken = plLogin(plPin($parent, $ana)['pin'])->json('token');
        plBearer($anaToken);
        postJson('/api/child/contract', PL_SVG)->assertCreated();
        $pet = $ana->currentPet();
        $bornAt = $pet->born_at;

        $join = plLogin(plPin($parent, $bor, $pet->id)['pin'], 'Bor tablet')
            ->assertOk()
            ->assertJsonPath('mode', 'join_pet')
            ->assertJsonPath('joined_existing', true)
            ->assertJsonPath('pet.id', $pet->id)
            ->assertJsonPath('awaiting_contract', true);

        expect($pet->fresh()->born_at->equalTo($bornAt))->toBeTrue()
            ->and(PetCaretaker::where('pet_id', $pet->id)->count())->toBe(2);

        plBearer($join->json('token'));
        postJson('/api/child/pet/clean')->assertStatus(423)->assertJsonPath('reason', 'contract_required');
        plBearer($anaToken);
        postJson('/api/child/pet/clean')->assertOk();
    });

    it('re-login on a new device: same child and pet, nothing re-paired, old device still signed in', function () {
        $parent = plParent();
        $child = plChild($parent);
        $first = plLogin(plPin($parent, $child)['pin'], 'phone')->json();
        plBearer($first['token']);
        postJson('/api/child/contract', PL_SVG)->assertCreated();
        $pet = Pet::findOrFail($first['pet']['id']);
        $petsBefore = Pet::count();

        $again = plLogin(plPin($parent, $child)['pin'], 'tablet')
            ->assertOk()
            ->assertJsonPath('mode', 'relogin')
            ->assertJsonPath('joined_existing', false)
            ->assertJsonPath('pet.id', $pet->id)
            ->assertJsonPath('awaiting_contract', false);

        expect(Pet::count())->toBe($petsBefore)
            ->and(PetCaretaker::where('user_id', $child->id)->count())->toBe(1)
            ->and($pet->fresh()->born_at->equalTo($pet->born_at))->toBeTrue()
            ->and($again->json('token'))->not->toBe($first['token']);

        plBearer($first['token']);
        getJson('/api/child/pet')->assertOk();
        plBearer($again->json('token'));
        getJson('/api/child/pet')->assertOk()->assertJsonPath('pet.id', $pet->id);
    });

    it('signs a legacy e-mail child in on a new device too (relogin)', function () {
        $parent = plParent();
        $legacy = User::factory()->child()->create(['parent_id' => $parent->id]);
        $pet = Pet::factory()->create(['user_id' => $legacy->id]);

        plLogin(plPin($parent, $legacy)['pin'])->assertOk()->assertJsonPath('mode', 'relogin')->assertJsonPath('pet.id', $pet->id);
    });

    it('keeps at most 3 devices per child: the oldest token is revoked', function () {
        $parent = plParent();
        $child = plChild($parent);

        $tokens = [];
        foreach (['one', 'two', 'three', 'four'] as $device) {
            $tokens[] = plLogin(plPin($parent, $child)['pin'], $device)->assertOk()->json('token');
        }

        expect($child->tokens()->orderBy('id')->pluck('name')->all())->toBe(['two', 'three', 'four']);

        plBearer($tokens[0]);
        getJson('/api/child/pet')->assertUnauthorized();
        plBearer($tokens[3]);
        getJson('/api/child/pet')->assertOk();
    });

    it('gives the same answer for a wrong, an expired, a used and a replaced PIN (no enumeration)', function () {
        $parent = plParent();
        $child = plChild($parent);
        $other = plChild($parent, 'Bor');

        $used = plPin($parent, $child)['pin'];
        plLogin($used)->assertOk();

        $replaced = plPin($parent, $other)['pin'];
        plPin($parent, $other); // a new PIN for Bor revokes the previous one

        $expired = plPin($parent, plChild($parent, 'Cene'))['pin'];
        $this->travel(15)->minutes();
        $this->travel(1)->seconds();

        $bodies = [];
        foreach ([plWrongPin($used), $expired, $used, $replaced] as $i => $pin) {
            $bodies[] = plLogin($pin, 'x', "203.0.113.{$i}")->assertUnprocessable()->json();
        }

        expect($bodies[0])->toBe(['message' => 'This code is not valid. Ask your parent for a new code.', 'reason' => 'invalid_pin'])
            ->and($bodies[1])->toBe($bodies[0])
            ->and($bodies[2])->toBe($bodies[0])
            ->and($bodies[3])->toBe($bodies[0]);
    });

    it('treats an expiry exactly at 15 minutes as expired', function () {
        $parent = plParent();
        $pin = plPin($parent, plChild($parent))['pin'];

        $this->travel(14)->minutes();
        $this->travel(59)->seconds();
        expect(ChildLoginPin::sole()->isUsable())->toBeTrue();
        $this->travel(1)->seconds();

        plLogin($pin)->assertUnprocessable()->assertJsonPath('reason', 'invalid_pin');
    });

    it('validates the input without counting it as a failed attempt', function (array $body, string $field) {
        plGuest();
        postJson('/api/child/pin-login', $body)->assertUnprocessable()->assertJsonValidationErrors($field);

        expect(RateLimiter::attempts('child-pin-login:failures:ip:198.51.100.7'))->toBe(0);
    })->with([
        'missing pin' => [['device_name' => 'x'], 'pin'],
        'five digits' => [['pin' => '12345', 'device_name' => 'x'], 'pin'],
        'letters' => [['pin' => '12a456', 'device_name' => 'x'], 'pin'],
        'array' => [['pin' => ['123456'], 'device_name' => 'x'], 'pin'],
        'missing device' => [['pin' => '123456'], 'device_name'],
        'long device' => [['pin' => '123456', 'device_name' => str_repeat('d', 101)], 'device_name'],
    ]);

    it('locks an IP out after 10 failed attempts, even for a correct PIN, and lets other IPs in', function () {
        $parent = plParent();
        $pin = plPin($parent, plChild($parent))['pin'];
        $otherPin = plPin($parent, plChild($parent, 'Bor'))['pin'];

        for ($i = 0; $i < ChildPinLoginService::MAX_IP_FAILURES; $i++) {
            plLogin(plWrongPin($pin, $otherPin), 'x', '203.0.113.9')->assertUnprocessable();
        }

        $locked = plLogin($pin, 'x', '203.0.113.9')
            ->assertStatus(429)
            ->assertJsonPath('reason', 'too_many_attempts')
            ->assertHeader('Retry-After');
        expect($locked->json('retry_after'))->toBeGreaterThan(0)->toBeLessThanOrEqual(900)
            ->and(ChildLoginPin::open()->count())->toBe(2);

        plLogin($otherPin, 'x', '203.0.113.10')->assertOk();
    });

    it('unlocks the IP after the 15-minute window; a success does not reset the counter', function () {
        $parent = plParent();
        $child = plChild($parent);

        // No open PIN exists yet, so any value is wrong.
        for ($i = 0; $i < ChildPinLoginService::MAX_IP_FAILURES - 1; $i++) {
            plLogin('000000', 'x', '203.0.113.20')->assertUnprocessable();
        }
        $pin = plPin($parent, $child)['pin'];
        plLogin($pin, 'x', '203.0.113.20')->assertOk();
        plLogin(plWrongPin($pin), 'x', '203.0.113.20')->assertUnprocessable();
        plLogin(plWrongPin($pin), 'x', '203.0.113.20')->assertStatus(429);

        $this->travel(ChildPinLoginService::FAILURE_WINDOW_SECONDS + 1)->seconds();
        plLogin(plPin($parent, $child)['pin'], 'x', '203.0.113.20')->assertOk();
    });

    it('pauses PIN login for everyone after 100 failed attempts across all IPs', function () {
        Log::spy();
        $parent = plParent();
        $pin = plPin($parent, plChild($parent))['pin'];

        for ($i = 0; $i < ChildPinLoginService::MAX_GLOBAL_FAILURES - 1; $i++) {
            RateLimiter::hit('child-pin-login:failures:global', ChildPinLoginService::FAILURE_WINDOW_SECONDS);
        }
        // The 100th failure (a real request from a fresh IP) trips the guard.
        plLogin(plWrongPin($pin), 'x', '192.0.2.1')->assertUnprocessable();

        plLogin($pin, 'x', '192.0.2.200')->assertStatus(429)->assertJsonPath('reason', 'too_many_attempts');
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'global failed-attempt limit'))->once();

        $this->travel(ChildPinLoginService::FAILURE_WINDOW_SECONDS + 1)->seconds();
        // (The first PIN expired meanwhile — 15 min.)
        plLogin(plPin($parent, User::where('name', 'Maja')->sole())['pin'], 'x', '192.0.2.200')->assertOk();
    });

    it('counts failures per real client IP behind Caddy, and ignores X-Forwarded-For from public peers', function () {
        // Caddy on the Docker network (private range) forwards the client.
        app('auth')->forgetGuards();
        $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.5'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.77'])
            ->postJson('/api/child/pin-login', ['pin' => '000000', 'device_name' => 'x'])
            ->assertUnprocessable();

        // A direct public hit can't pick its own "IP".
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.88'])
            ->postJson('/api/child/pin-login', ['pin' => '000000', 'device_name' => 'x'])
            ->assertUnprocessable();

        expect(RateLimiter::attempts('child-pin-login:failures:ip:203.0.113.77'))->toBe(1)
            ->and(RateLimiter::attempts('child-pin-login:failures:ip:172.18.0.5'))->toBe(0)
            ->and(RateLimiter::attempts('child-pin-login:failures:ip:198.51.100.99'))->toBe(1)
            ->and(RateLimiter::attempts('child-pin-login:failures:ip:203.0.113.88'))->toBe(0);
    });

    it('is throttled per IP on the route (10 per minute), before validation', function () {
        $this->withMiddleware(ThrottleRequests::class);
        $route = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === 'api/child/pin-login');
        expect($route->gatherMiddleware())->toContain('throttle:pin-login')
            ->not->toContain('auth:sanctum');

        for ($i = 0; $i < AppServiceProvider::PIN_LOGIN_PER_MINUTE; $i++) {
            plLogin('bad', 'x', '203.0.113.50')->assertUnprocessable();
        }
        plLogin('bad', 'x', '203.0.113.50')->assertStatus(429);
        plLogin('bad', 'x', '203.0.113.51')->assertUnprocessable();
    });

    it('revokes a matched PIN that can no longer do its job (shared pet ended) — pin_not_usable', function () {
        $parent = plParent();
        $ana = plChild($parent, 'Ana');
        $bor = plChild($parent, 'Bor');
        plLogin(plPin($parent, $ana)['pin']);
        $pet = $ana->currentPet();
        $pin = plPin($parent, $bor, $pet->id)['pin'];
        Pet::whereKey($pet->id)->update(['is_game_over' => true, 'is_active' => false]);

        plLogin($pin)->assertUnprocessable()->assertJsonPath('reason', 'pin_not_usable');
        plLogin($pin)->assertUnprocessable()->assertJsonPath('reason', 'invalid_pin');

        expect($bor->tokens()->count())->toBe(0)
            ->and(PetCaretaker::where('user_id', $bor->id)->exists())->toBeFalse()
            ->and(RateLimiter::attempts('child-pin-login:failures:ip:198.51.100.7'))->toBe(1);
    });

    it('uses a PIN only once under a race: the second consumer sees invalid_pin', function () {
        $parent = plParent();
        $child = plChild($parent);
        $pin = plPin($parent, $child)['pin'];

        // Simulate a concurrent request consuming the PIN between the
        // unlocked lookup and the locked re-read.
        $consumed = false;
        DB::listen(function ($query) use (&$consumed) {
            if (! $consumed && str_contains($query->sql, 'from "families"') && str_contains($query->sql, 'for update')) {
                $consumed = true;
                DB::table('child_login_pins')->update(['consumed_at' => now()]);
            }
        });

        plLogin($pin)->assertUnprocessable()->assertJsonPath('reason', 'invalid_pin');
        expect($child->tokens()->count())->toBe(0)->and(Pet::count())->toBe(0);
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('token abilities (M2-03)', function () {
    it('issues parent / child abilities on e-mail login', function () {
        $parent = User::factory()->parent()->create(['email' => 'p@example.test']);
        $legacy = User::factory()->child()->create(['email' => 'c@example.test', 'parent_id' => $parent->id]);

        plGuest();
        postJson('/api/login', ['email' => 'p@example.test', 'password' => 'password'])->assertOk()->assertJsonPath('abilities', ['parent']);
        postJson('/api/login', ['email' => 'c@example.test', 'password' => 'password'])->assertOk()->assertJsonPath('abilities', ['child']);

        expect($parent->tokens()->sole()->abilities)->toBe(['parent'])
            ->and($legacy->tokens()->sole()->abilities)->toBe(['child']);
    });

    it('rejects e-mail login for a child without a password, like wrong credentials', function () {
        $parent = plParent();
        $child = plChild($parent);
        $child->forceFill(['email' => 'kid@example.test'])->saveQuietly();

        plGuest();
        postJson('/api/login', ['email' => 'kid@example.test', 'password' => ''])->assertUnprocessable();
        postJson('/api/login', ['email' => 'kid@example.test', 'password' => 'anything'])->assertUnauthorized()->assertJsonPath('message', 'Invalid credentials.');
    });

    it('a child token cannot call parent routes and a parent token cannot call child routes', function () {
        $parent = User::factory()->parent()->create(['email' => 'p@example.test']);
        $child = plChild($parent);
        $childToken = plLogin(plPin($parent, $child)['pin'])->json('token');
        plGuest();
        $parentToken = postJson('/api/login', ['email' => 'p@example.test', 'password' => 'password'])->json('token');

        plBearer($childToken);
        getJson('/api/parent/dashboard')->assertForbidden();
        postJson('/api/parent/generate-pin', ['child_id' => $child->id])->assertForbidden();
        postJson('/api/parent/children', ['display_name' => 'X'])->assertForbidden();
        deleteJson("/api/parent/children/{$child->id}/tokens")->assertForbidden();
        getJson('/api/child/pet')->assertOk();

        plBearer($parentToken);
        getJson('/api/child/pet')->assertForbidden();
        postJson('/api/child/pair', ['pin' => '123456'])->assertForbidden();
        postJson('/api/child/contract', PL_SVG)->assertForbidden();
        getJson('/api/parent/dashboard')->assertOk();

        // Shared routes work for both.
        getJson('/api/user')->assertOk()->assertJsonPath('role', 'parent');
    });

    it('keeps legacy ["*"] tokens working; the policies still separate the roles', function () {
        $parent = plParent();
        $child = User::factory()->child()->create(['parent_id' => $parent->id]);
        Pet::factory()->create(['user_id' => $child->id]);
        $parentToken = $parent->createToken('old build')->plainTextToken;
        $childToken = $child->createToken('old build')->plainTextToken;

        expect($parent->tokens()->sole()->abilities)->toBe(['*']);

        plBearer($parentToken);
        getJson('/api/parent/dashboard')->assertOk();
        getJson('/api/child/pet')->assertForbidden(); // PetPolicy::useChildApi

        plBearer($childToken);
        getJson('/api/child/pet')->assertOk();
        getJson('/api/parent/dashboard')->assertForbidden(); // UserPolicy::manageFamily
    });

    it('authorizes the private pet channel with a PIN child token', function () {
        plUseReverb();
        $parent = plParent();
        $child = plChild($parent);
        $token = plLogin(plPin($parent, $child)['pin'])->json('token');
        $pet = $child->currentPet();
        $other = Pet::factory()->create(['user_id' => User::factory()->child()->create(['parent_id' => plParent()->id])->id]);

        plBearer($token);
        postJson('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-pet.{$pet->id}"])
            ->assertOk()->assertJsonStructure(['auth']);
        postJson('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-pet.{$other->id}"])
            ->assertForbidden();
    });

    it('logs a PIN child out by deleting only the current token', function () {
        $parent = plParent();
        $child = plChild($parent);
        $a = plLogin(plPin($parent, $child)['pin'], 'a')->json('token');
        $b = plLogin(plPin($parent, $child)['pin'], 'b')->json('token');

        plBearer($a);
        postJson('/api/logout')->assertOk();
        plBearer($a);
        getJson('/api/child/pet')->assertUnauthorized();
        plBearer($b);
        getJson('/api/child/pet')->assertOk();
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('DELETE /api/parent/children/{child}/tokens', function () {
    it('signs the child out everywhere and revokes the open PIN', function () {
        $parent = plParent();
        $child = plChild($parent);
        $a = plLogin(plPin($parent, $child)['pin'], 'a')->json('token');
        plLogin(plPin($parent, $child)['pin'], 'b');
        $open = plPin($parent, $child)['pin'];

        actingAs($parent, 'sanctum');
        deleteJson("/api/parent/children/{$child->id}/tokens")
            ->assertOk()
            ->assertJson(['revoked_tokens' => 2, 'revoked_pins' => 1]);

        plBearer($a);
        getJson('/api/child/pet')->assertUnauthorized();
        plLogin($open)->assertUnprocessable()->assertJsonPath('reason', 'invalid_pin');
        // A new PIN signs the child back in to the same pet.
        plLogin(plPin($parent, $child)['pin'])->assertOk()->assertJsonPath('mode', 'relogin');
    });

    it('is scoped to the family: another family, a missing or a non-numeric child is a 404', function () {
        $parent = plParent();
        $foreignParent = plParent();
        $foreign = plChild($foreignParent);
        plLogin(plPin($foreignParent, $foreign)['pin']);

        actingAs($parent, 'sanctum');
        deleteJson("/api/parent/children/{$foreign->id}/tokens")->assertNotFound()->assertJsonPath('reason', 'child_not_found');
        deleteJson('/api/parent/children/999999/tokens')->assertNotFound();
        deleteJson('/api/parent/children/abc/tokens')->assertNotFound();
        deleteJson("/api/parent/children/{$parent->id}/tokens")->assertNotFound();

        expect($foreign->tokens()->count())->toBe(1);
    });

    it('works for the second parent and for a legacy e-mail child', function () {
        $parent = plParent();
        $legacy = User::factory()->child()->create(['parent_id' => $parent->id]);
        $legacy->createToken('old');
        $second = plParent();
        app(FamilyInviteService::class)->joinFamily($second, app(FamilyInviteService::class)->createInvite($parent)['code']);

        actingAs($second, 'sanctum');
        deleteJson("/api/parent/children/{$legacy->id}/tokens")->assertOk()->assertJsonPath('revoked_tokens', 1);
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('migration', function () {
    it('makes e-mail and password nullable, keeps e-mails unique and refuses a lossy rollback', function () {
        $parent = plParent();

        // Savepoint: the violation must not abort the test transaction.
        expect(fn () => DB::transaction(fn () => User::factory()->parent()->create(['email' => $parent->email])))
            ->toThrow(UniqueConstraintViolationException::class);

        $migration = require database_path('migrations/2026_10_04_150000_add_pin_only_child_profiles.php');
        plChild($parent);
        expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'PIN-only');
    });

    it('rolls back and re-applies cleanly while every user has credentials', function () {
        User::factory()->parent()->create(['password' => Hash::make('x')]);
        $migration = require database_path('migrations/2026_10_04_150000_add_pin_only_child_profiles.php');

        $migration->down();
        expect(Schema::hasTable('child_login_pins'))->toBeFalse()
            ->and(Schema::hasColumn('users', 'birth_year'))->toBeFalse();

        $migration->up();
        expect(Schema::hasTable('child_login_pins'))->toBeTrue();
    });
});
