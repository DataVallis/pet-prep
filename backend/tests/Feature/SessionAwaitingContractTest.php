<?php

use App\Console\Commands\PruneChildLoginPinsCommand;
use App\Models\ChildLoginPin;
use App\Models\Pet;
use App\Models\User;
use App\Services\ChildPinLoginService;
use App\Services\ChildProfileService;
use App\Services\FamilyService;
use App\Services\PetActivityService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Middleware\ThrottleRequests;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| M2-02 follow-up: per-child `awaiting_contract` in the session payloads
| (GET /api/user, POST /api/login) + daily prune of child login PINs.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    seedBreedConfigs();
    $this->withoutMiddleware([ThrottleRequests::class]);
});

/**
 * @return array{0: User, 1: User, 2: Pet} parent, owner child, unborn pet
 */
function sacUnbornFamily(): array
{
    $parent = User::factory()->parent()->create();
    $owner = app(ChildProfileService::class)->createChild($parent, 'Ana', null);
    $pin = app(ChildPinLoginService::class)->generatePin($parent, $owner->id, null)['pin'];
    app(ChildPinLoginService::class)->login($pin, 'phone', '198.51.100.1');

    return [$parent, $owner, $owner->currentPet()];
}

function sacSign(Pet $pet, User $child): void
{
    app(PetActivityService::class)->signContract(Pet::findOrFail($pet->id), $child, 'svg_path', 'M1 1 L2 2');
}

/**
 * Bor joins Ana's pet AFTER its birth (Ana signed), without signing himself.
 *
 * @return array{0: User, 1: User, 2: User, 3: Pet} parent, owner, joiner, pet
 */
function sacJoinedBornFamily(): array
{
    [$parent, $owner, $pet] = sacUnbornFamily();
    sacSign($pet, $owner);

    $joiner = app(ChildProfileService::class)->createChild($parent, 'Bor', null);
    $pin = app(ChildPinLoginService::class)->generatePin($parent, $joiner->id, $pet->id)['pin'];
    app(ChildPinLoginService::class)->login($pin, 'tablet', '198.51.100.2');

    return [$parent, $owner, $joiner, $pet->fresh()];
}

describe('GET /api/user — per-child awaiting_contract', function () {
    it('is true for the owner of an unborn pet (top level and in pet)', function () {
        [, $owner, $pet] = sacUnbornFamily();
        actingAs($owner, 'sanctum');

        getJson('/api/user')->assertOk()
            ->assertJsonPath('awaiting_contract', true)
            ->assertJsonPath('pet.id', $pet->id)
            ->assertJsonPath('pet.born_at', null)
            ->assertJsonPath('pet.awaiting_contract', true);
    });

    it('is true for a child who joined an already-born pet and has not signed (pet looks born)', function () {
        [, $owner, $joiner, $pet] = sacJoinedBornFamily();
        expect($pet->isUnborn())->toBeFalse();

        actingAs($joiner, 'sanctum');
        $response = getJson('/api/user')->assertOk()
            ->assertJsonPath('awaiting_contract', true)
            ->assertJsonPath('pet.id', $pet->id)
            ->assertJsonPath('pet.awaiting_contract', true);
        expect($response->json('pet.born_at'))->not->toBeNull();

        // The sibling who signed is not blocked by it.
        actingAs($owner, 'sanctum');
        getJson('/api/user')->assertOk()
            ->assertJsonPath('awaiting_contract', false)
            ->assertJsonPath('pet.awaiting_contract', false);
    });

    it('is false once the joining child signed', function () {
        [, , $joiner, $pet] = sacJoinedBornFamily();
        sacSign($pet, $joiner);

        actingAs($joiner, 'sanctum');
        getJson('/api/user')->assertOk()
            ->assertJsonPath('awaiting_contract', false)
            ->assertJsonPath('pet.awaiting_contract', false);
    });

    it('is false for a grandfathered pet without a contract and for a child without a pet', function () {
        $parent = User::factory()->parent()->create();
        $legacy = User::factory()->child()->create(['parent_id' => $parent->id]);
        Pet::factory()->create(['user_id' => $legacy->id]); // born, caretaker row backfilled by the hook
        $petless = app(ChildProfileService::class)->createChild($parent, 'Cene', null);

        actingAs($legacy, 'sanctum');
        getJson('/api/user')->assertOk()->assertJsonPath('awaiting_contract', false);

        actingAs($petless, 'sanctum');
        getJson('/api/user')->assertOk()->assertJsonPath('awaiting_contract', false)->assertJsonPath('pet', null);
    });

    it('is null for a parent and keeps the legacy pet fields', function () {
        [$parent, , $pet] = sacUnbornFamily();
        actingAs($parent, 'sanctum');

        $body = getJson('/api/user')->assertOk()
            ->assertJsonPath('awaiting_contract', null)
            ->assertJsonPath('pet.id', $pet->id)
            ->json();

        expect($body['pet'])->not->toHaveKey('awaiting_contract')
            ->and($body['pet'])->toHaveKeys(['id', 'user_id', 'family_id', 'breed_type', 'hunger_level', 'born_at', 'is_active'])
            ->and($body)->toHaveKeys(['id', 'name', 'email', 'role', 'pet', 'awaiting_contract']);
    });
});

describe('POST /api/login — per-child awaiting_contract', function () {
    it('carries the flag for a legacy e-mail child who joined a born pet unsigned', function () {
        [$parent, $owner, $pet] = sacUnbornFamily();
        sacSign($pet, $owner);
        $legacy = User::factory()->child()->create(['email' => 'kid@example.test', 'parent_id' => $parent->id]);
        app(FamilyService::class)->addCaretaker($pet->fresh(), $legacy, requiresContract: true);

        app('auth')->forgetGuards();
        postJson('/api/login', ['email' => 'kid@example.test', 'password' => 'password'])->assertOk()
            ->assertJsonPath('awaiting_contract', true)
            ->assertJsonPath('pet.id', $pet->id)
            ->assertJsonPath('pet.awaiting_contract', true);

        sacSign($pet, $legacy);
        postJson('/api/login', ['email' => 'kid@example.test', 'password' => 'password'])->assertOk()
            ->assertJsonPath('awaiting_contract', false);
    });

    it('is null for a parent login', function () {
        User::factory()->parent()->create(['email' => 'p@example.test']);

        postJson('/api/login', ['email' => 'p@example.test', 'password' => 'password'])->assertOk()
            ->assertJsonPath('awaiting_contract', null)
            ->assertJsonPath('pet', null);
    });
});

describe('pins:prune', function () {
    it('deletes PINs that expired more than 7 days ago — used, revoked or unused — and keeps the rest', function () {
        [$parent] = sacUnbornFamily(); // one consumed PIN, expiring now + 15 min
        $child = app(ChildProfileService::class)->createChild($parent, 'Dan', null);
        $make = fn (array $extra) => ChildLoginPin::create(array_merge([
            'family_id' => $parent->family->id,
            'child_user_id' => $child->id,
            'created_by' => $parent->id,
            'pin_hash' => bin2hex(random_bytes(32)),
            'expires_at' => now()->subDays(8),
        ], $extra));

        $oldUnused = $make([]);
        $oldUsed = $make(['consumed_at' => now()->subDays(8)]);
        $oldRevoked = $make(['revoked_at' => now()->subDays(8)]);
        $recentExpired = $make(['expires_at' => now()->subDays(6)]);
        $open = app(ChildPinLoginService::class)->generatePin($parent, $child->id, null);

        $this->artisan('pins:prune')
            ->expectsOutputToContain('Pruned 3 child login PIN(s) older than 7 day(s).')
            ->assertSuccessful();

        expect(ChildLoginPin::whereKey([$oldUnused->id, $oldUsed->id, $oldRevoked->id])->count())->toBe(0)
            ->and(ChildLoginPin::whereKey($recentExpired->id)->exists())->toBeTrue()
            ->and(ChildLoginPin::count())->toBe(3); // consumed (recent) + recently expired + open

        // The open PIN still works after the prune.
        app(ChildPinLoginService::class)->login($open['pin'], 'phone', '198.51.100.3');
    });

    it('honours --days and refuses a value below 1', function () {
        [$parent, $owner] = sacUnbornFamily();
        ChildLoginPin::query()->update(['expires_at' => now()->subDays(2)]);

        $this->artisan('pins:prune', ['--days' => 3])->assertSuccessful();
        expect(ChildLoginPin::count())->toBe(1);

        $this->artisan('pins:prune', ['--days' => 1])->assertSuccessful();
        expect(ChildLoginPin::count())->toBe(0);

        $this->artisan('pins:prune', ['--days' => 0])->assertFailed();
        expect($owner->id)->toBeInt();
    });

    it('is scheduled daily', function () {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'pins:prune'));

        expect($event)->not->toBeNull()
            ->and($event->expression)->toBe('17 3 * * *')
            ->and(PruneChildLoginPinsCommand::RETENTION_DAYS)->toBe(7);
    });
});
