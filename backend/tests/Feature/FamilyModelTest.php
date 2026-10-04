<?php

use App\Enums\ActivityType;
use App\Exceptions\FamilyException;
use App\Models\ActivityLog;
use App\Models\Family;
use App\Models\FamilyInvite;
use App\Models\FamilyMember;
use App\Models\Pet;
use App\Models\PetCaretaker;
use App\Models\PetContract;
use App\Models\PetDailyStep;
use App\Models\QuietHours;
use App\Models\User;
use App\Services\FamilyInviteService;
use App\Services\FamilyService;
use App\Services\PairingService;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\getJson;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

/*
|--------------------------------------------------------------------------
| Family model (M2-01, ADR-012)
|--------------------------------------------------------------------------
|
| Several parents, several children; each child its own pet or a shared
| pet; every action attributed to the acting child; channel auth, PINs,
| invites, migration backfill.
|
*/

const FM_SVG = ['signature_format' => 'svg_path', 'signature' => 'M10 10 L20 20'];

function fmAt(string $utc): void
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
}

/**
 * Parent A + child 1 + born mutt pet (legacy path: parent_id / user_id),
 * at 08:00 Ljubljana (inside the morning feed window).
 *
 * @return array{0: User, 1: User, 2: Pet}
 */
function fmFamily(string $nowUtc = '2026-10-04 06:00:00'): array
{
    seedBreedConfigs();
    fmAt($nowUtc);

    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    $pet = disableHygieneEvents(Pet::factory()->mutt()->create(['user_id' => $child->id]));

    return [$parent, $child, $pet];
}

/**
 * A second parent account that joins $parent's family via an invite code.
 */
function fmSecondParent(User $parent): User
{
    $second = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $code = app(FamilyInviteService::class)->createInvite($parent)['code'];
    app(FamilyInviteService::class)->joinFamily($second, $code);

    return $second->refresh();
}

/**
 * A new child account that joins $pet via a join PIN from $parent and,
 * unless $sign is false, signs their own contract.
 */
function fmJoinPet(User $parent, Pet $pet, bool $sign = true): User
{
    actingAsRole($parent);
    $pin = postJson('/api/parent/generate-pin', ['pet_id' => $pet->id])->assertOk()->json('pin');

    $child = User::factory()->child()->create(['parent_id' => null]);
    actingAsRole($child);
    postJson('/api/child/pair', ['pin' => $pin])->assertCreated();

    if ($sign) {
        postJson('/api/child/contract', FM_SVG)->assertCreated();
    }

    return $child->refresh();
}

function fmReverbAuth(): void
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

function fmChannel(User $user, Pet $pet): int
{
    actingAsRole($user);

    return post('/api/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => "private-pet.{$pet->id}",
    ])->status();
}

// ──────────────────────────────────────────────────────────────
//  Legacy sync + migration backfill
// ──────────────────────────────────────────────────────────────

describe('legacy columns land in the family model', function () {
    it('gives a parent a family, adds a parent_id child and makes pets.user_id a caretaker', function () {
        [$parent, $child, $pet] = fmFamily();

        $family = $parent->family;
        expect($family)->not->toBeNull()
            ->and($family->timezone)->toBe('Europe/Ljubljana')
            ->and($child->family->id)->toBe($family->id)
            ->and(FamilyMember::where('user_id', $child->id)->value('role')->value)->toBe('child')
            ->and($pet->family_id)->toBe($family->id)
            ->and($pet->caretakers()->pluck('users.id')->all())->toBe([$child->id])
            ->and(PetCaretaker::where('pet_id', $pet->id)->value('requires_contract'))->toBeFalse();
    });

    it('copies a parent timezone change to the family', function () {
        [$parent, , $pet] = fmFamily();

        $parent->update(['timezone' => 'America/New_York']);

        expect($parent->family->fresh()->timezone)->toBe('America/New_York')
            ->and($pet->fresh()->familyTimezone())->toBe('America/New_York');
    });

    it('puts quiet hours created with parent_id into the family', function () {
        [$parent, , $pet] = fmFamily();

        $qh = QuietHours::create(['parent_id' => $parent->id, 'bedtime_start' => '22:00', 'bedtime_end' => '06:00', 'is_active' => true]);

        expect($qh->family_id)->toBe($parent->family->id)
            ->and($pet->fresh()->quietHours()?->id)->toBe($qh->id);
    });
});

describe('migration backfill', function () {
    it('turns existing parents, children, pets, quiet hours and activities into families', function () {
        $contracts = require database_path('migrations/2026_10_04_140100_make_pet_contracts_unique_per_caretaker.php');
        $family = require database_path('migrations/2026_10_04_140000_create_family_model.php');
        // M2-02 depends on families: roll it back first.
        (require database_path('migrations/2026_10_04_150000_add_pin_only_child_profiles.php'))->down();
        $contracts->down();
        $family->down();

        $user = fn (string $role, array $extra = []) => DB::table('users')->insertGetId(array_merge([
            'name' => "{$role} ".uniqid(), 'email' => uniqid().'@example.test', 'password' => 'x',
            'role' => $role, 'created_at' => now(), 'updated_at' => now(),
        ], $extra));

        $parent = $user('parent', ['timezone' => 'America/New_York']);
        $child = $user('child', ['parent_id' => $parent]);
        $lonelyParent = $user('parent');
        $orphan = $user('child'); // pet but no parent (legacy data)
        $unpaired = $user('child'); // no parent, no pet

        $born = DB::table('pets')->insertGetId(['user_id' => $child, 'breed_type' => 'mutt', 'is_active' => true, 'born_at' => now()]);
        $unborn = DB::table('pets')->insertGetId(['user_id' => $orphan, 'breed_type' => 'mutt', 'is_active' => true, 'born_at' => null]);
        $parentOwned = DB::table('pets')->insertGetId(['user_id' => $lonelyParent, 'breed_type' => 'mutt', 'is_active' => true, 'born_at' => now()]);
        DB::table('quiet_hours')->insert(['parent_id' => $parent, 'is_active' => true]);
        $fed = DB::table('activities_log')->insertGetId(['pet_id' => $born, 'activity_type' => 'fed_pet', 'value' => 50, 'created_at' => now()]);
        $warn = DB::table('activities_log')->insertGetId(['pet_id' => $born, 'activity_type' => 'ignored_warning', 'value' => 30, 'created_at' => now()]);

        $family->up();
        $contracts->up();
        (require database_path('migrations/2026_10_04_150000_add_pin_only_child_profiles.php'))->up();

        $familyOf = fn (int $id) => DB::table('family_user')->where('user_id', $id)->value('family_id');
        $f1 = $familyOf($parent);

        expect(DB::table('families')->where('id', $f1)->value('timezone'))->toBe('America/New_York')
            ->and($familyOf($child))->toBe($f1)
            ->and(DB::table('family_user')->where('user_id', $child)->value('role'))->toBe('child')
            ->and($familyOf($lonelyParent))->not->toBeNull()->not->toBe($f1)
            ->and($familyOf($orphan))->not->toBeNull()->not->toBe($f1)
            ->and($familyOf($unpaired))->toBeNull()
            ->and(DB::table('pets')->where('id', $born)->value('family_id'))->toBe($f1)
            ->and(DB::table('pets')->where('id', $unborn)->value('family_id'))->toBe($familyOf($orphan))
            ->and(DB::table('pet_caretakers')->where('pet_id', $born)->pluck('user_id')->all())->toBe([$child])
            ->and(DB::table('pet_caretakers')->where('pet_id', $born)->value('requires_contract'))->toBeFalse()
            ->and(DB::table('pet_caretakers')->where('pet_id', $unborn)->value('requires_contract'))->toBeTrue()
            ->and(DB::table('pet_caretakers')->where('pet_id', $born)->value('pet_is_active'))->toBeTrue()
            ->and(DB::table('quiet_hours')->where('parent_id', $parent)->value('family_id'))->toBe($f1)
            ->and(DB::table('activities_log')->where('id', $fed)->value('actor_user_id'))->toBe($child)
            ->and(DB::table('activities_log')->where('id', $warn)->value('actor_user_id'))->toBeNull()
            // Parent-owned legacy pet: the parent's family, no caretaker (only children care).
            ->and(DB::table('pets')->where('id', $parentOwned)->value('family_id'))->toBe($familyOf($lonelyParent))
            ->and(DB::table('pet_caretakers')->where('pet_id', $parentOwned)->exists())->toBeFalse()
            ->and(DB::table('pet_caretakers')->where('user_id', $lonelyParent)->exists())->toBeFalse();

        // The model reads the same picture: the child acts on the backfilled pet.
        $pet = Pet::findOrFail($born);
        expect($pet->familyTimezone())->toBe('America/New_York')
            ->and(User::findOrFail($child)->currentPet()?->id)->toBe($born)
            ->and($pet->actionLockReasonFor(User::findOrFail($child)))->toBeNull();
    });

    it('refuses to run (writing nothing) when a child already has two active pets', function () {
        $contracts = require database_path('migrations/2026_10_04_140100_make_pet_contracts_unique_per_caretaker.php');
        $family = require database_path('migrations/2026_10_04_140000_create_family_model.php');
        // M2-02 depends on families: roll it back first.
        (require database_path('migrations/2026_10_04_150000_add_pin_only_child_profiles.php'))->down();
        $contracts->down();
        $family->down();

        $child = DB::table('users')->insertGetId(['name' => 'c', 'email' => 'c@example.test', 'password' => 'x', 'role' => 'child']);
        DB::table('pets')->insert([
            ['user_id' => $child, 'breed_type' => 'mutt', 'is_active' => true, 'born_at' => now()],
            ['user_id' => $child, 'breed_type' => 'mutt', 'is_active' => true, 'born_at' => now()],
        ]);

        expect(fn () => $family->up())->toThrow(RuntimeException::class, "user ids: {$child}");
        expect(Schema::hasTable('families'))->toBeFalse();
    });

    it('can be rolled back and re-applied', function () {
        [$parent, , $pet] = fmFamily();
        $contracts = require database_path('migrations/2026_10_04_140100_make_pet_contracts_unique_per_caretaker.php');
        $family = require database_path('migrations/2026_10_04_140000_create_family_model.php');

        // M2-02 depends on families: roll it back first.
        (require database_path('migrations/2026_10_04_150000_add_pin_only_child_profiles.php'))->down();
        $contracts->down();
        $family->down();
        expect(Schema::hasTable('families'))->toBeFalse()
            ->and(Schema::hasColumn('pets', 'family_id'))->toBeFalse();

        $family->up();
        $contracts->up();
        (require database_path('migrations/2026_10_04_150000_add_pin_only_child_profiles.php'))->up();
        expect(DB::table('pets')->where('id', $pet->id)->value('family_id'))
            ->toBe(DB::table('family_user')->where('user_id', $parent->id)->value('family_id'));
    });
});

// ──────────────────────────────────────────────────────────────
//  Second parent: invite + join
// ──────────────────────────────────────────────────────────────

describe('POST /api/parent/invite-parent + join-family', function () {
    it('lets a second parent join and see the same dashboard', function () {
        [$parent, $child, $pet] = fmFamily();

        actingAsRole($parent);
        $invite = postJson('/api/parent/invite-parent')->assertCreated();
        $code = $invite->json('code');
        expect($code)->toMatch('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$/')
            ->and($invite->json('expires_in_hours'))->toBe(24)
            ->and(Carbon::parse($invite->json('expires_at'))->equalTo(now()->addHours(24)))->toBeTrue();

        $second = User::factory()->parent()->create();
        $oldFamilyId = $second->family->id;
        actingAsRole($second);
        postJson('/api/parent/join-family', ['code' => strtolower(" {$code} ")])
            ->assertOk()
            ->assertJsonPath('family.id', $parent->family->id)
            ->assertJsonCount(2, 'family.parents');

        expect(Family::find($oldFamilyId))->toBeNull(); // empty family removed

        $mine = getJson('/api/parent/dashboard')->assertOk();
        actingAsRole($parent);
        $theirs = getJson('/api/parent/dashboard')->assertOk();

        foreach (['pet.id', 'child.id', 'family.id', 'family.pets', 'family.children', 'traffic_light'] as $path) {
            expect($mine->json($path))->toBe($theirs->json($path));
        }
        expect($mine->json('pet.id'))->toBe($pet->id)
            ->and($mine->json('child.id'))->toBe($child->id)
            ->and(collect($mine->json('family.parents'))->pluck('id')->all())->toBe([$parent->id, $second->id])
            ->and(collect($mine->json('family.parents'))->firstWhere('id', $second->id)['is_me'])->toBeTrue()
            ->and(collect($theirs->json('family.parents'))->firstWhere('id', $second->id)['is_me'])->toBeFalse();
    });

    it('refuses a used code, an expired code and an unknown code', function () {
        [$parent] = fmFamily();
        $code = app(FamilyInviteService::class)->createInvite($parent)['code'];

        actingAsRole(User::factory()->parent()->create());
        postJson('/api/parent/join-family', ['code' => $code])->assertOk();

        actingAsRole(User::factory()->parent()->create());
        postJson('/api/parent/join-family', ['code' => $code])
            ->assertStatus(422)->assertJsonPath('reason', 'code_used');
        postJson('/api/parent/join-family', ['code' => 'ZZZZZZZZ'])
            ->assertStatus(422)->assertJsonPath('reason', 'invalid_code');

        $late = app(FamilyInviteService::class)->createInvite($parent)['code'];
        $this->travel(24)->hours();
        postJson('/api/parent/join-family', ['code' => $late])
            ->assertStatus(422)->assertJsonPath('reason', 'code_expired');
    });

    it('accepts a code one second before it expires', function () {
        [$parent] = fmFamily();
        $code = app(FamilyInviteService::class)->createInvite($parent)['code'];
        $this->travel(24 * 3600 - 1)->seconds();

        actingAsRole(User::factory()->parent()->create());
        postJson('/api/parent/join-family', ['code' => $code])->assertOk();
    });

    it('revokes the previous unused code when the same parent creates a new one', function () {
        [$parent] = fmFamily();
        $first = app(FamilyInviteService::class)->createInvite($parent)['code'];
        $second = app(FamilyInviteService::class)->createInvite($parent)['code'];

        actingAsRole(User::factory()->parent()->create());
        postJson('/api/parent/join-family', ['code' => $first])
            ->assertStatus(422)->assertJsonPath('reason', 'code_expired');
        postJson('/api/parent/join-family', ['code' => $second])->assertOk();
    });

    it('locks out after 5 wrong codes for 15 minutes, even for a valid code', function () {
        [$parent] = fmFamily();
        $code = app(FamilyInviteService::class)->createInvite($parent)['code'];
        actingAsRole(User::factory()->parent()->create());

        foreach (range(1, 5) as $i) {
            postJson('/api/parent/join-family', ['code' => "WRONG{$i}AA"])->assertStatus(422);
        }
        postJson('/api/parent/join-family', ['code' => $code])
            ->assertStatus(429)->assertJsonPath('reason', 'too_many_attempts');

        $this->travel(16)->minutes();
        postJson('/api/parent/join-family', ['code' => $code])->assertOk();
    });

    it('is throttled at the route level too', function () {
        $middleware = fn (string $uri) => Route::getRoutes()->match(request()->create($uri, 'POST'))->gatherMiddleware();

        expect($middleware('/api/parent/invite-parent'))->toContain('throttle:family-invites')
            ->and($middleware('/api/parent/join-family'))->toContain('throttle:pairing');
    });

    it('refuses with 409 when the joining parent already has children or pets', function () {
        [$parent] = fmFamily();
        [$other] = fmFamily();
        $code = app(FamilyInviteService::class)->createInvite($parent)['code'];

        actingAsRole($other);
        postJson('/api/parent/join-family', ['code' => $code])
            ->assertStatus(409)->assertJsonPath('reason', 'family_not_empty');

        expect(FamilyInvite::where('code', $code)->value('used_at'))->toBeNull();
    });

    it('refuses with 409 when the parent is already in that family', function () {
        [$parent] = fmFamily();
        $code = app(FamilyInviteService::class)->createInvite($parent)['code'];

        actingAsRole($parent);
        postJson('/api/parent/join-family', ['code' => $code])
            ->assertStatus(409)->assertJsonPath('reason', 'already_member');
    });

    it('is parents only', function () {
        [$parent, $child] = fmFamily();
        $code = app(FamilyInviteService::class)->createInvite($parent)['code'];

        actingAsRole($child);
        postJson('/api/parent/invite-parent')->assertForbidden();
        postJson('/api/parent/join-family', ['code' => $code])->assertForbidden();
    });

    it('validates the code field', function () {
        actingAsRole(User::factory()->parent()->create());
        postJson('/api/parent/join-family', [])->assertStatus(422)->assertJsonValidationErrors('code');
        postJson('/api/parent/join-family', ['code' => 'ab$%cd!!'])->assertStatus(422)->assertJsonValidationErrors('code');
    });
});

// ──────────────────────────────────────────────────────────────
//  Child PIN: new pet vs existing pet
// ──────────────────────────────────────────────────────────────

describe('POST /api/parent/generate-pin with pet_id', function () {
    it('creates a new pet when pet_id is omitted (second child, own pet)', function () {
        [$parent, , $pet] = fmFamily();
        actingAsRole($parent);
        $pin = postJson('/api/parent/generate-pin')->assertOk()->assertJsonPath('pet_id', null)->json('pin');

        $child2 = User::factory()->child()->create(['parent_id' => null]);
        actingAsRole($child2);
        $response = postJson('/api/child/pair', ['pin' => $pin])->assertCreated()
            ->assertJsonPath('joined_existing', false)
            ->assertJsonPath('pet.awaiting_contract', true)
            ->assertJsonPath('family_id', $parent->family->id);

        $newPet = Pet::findOrFail($response->json('pet.id'));
        expect($newPet->id)->not->toBe($pet->id)
            ->and($newPet->family_id)->toBe($parent->family->id)
            ->and($newPet->isUnborn())->toBeTrue()
            ->and($newPet->caretakers()->pluck('users.id')->all())->toBe([$child2->id])
            ->and(Pet::where('family_id', $parent->family->id)->count())->toBe(2);
    });

    it('joins an existing pet: caretaker added, no new pet, no rebirth, primary owner unchanged', function () {
        [$parent, $child1, $pet] = fmFamily();
        $bornAt = $pet->born_at->toIso8601String();

        actingAsRole($parent);
        $pin = postJson('/api/parent/generate-pin', ['pet_id' => $pet->id])->assertOk()
            ->assertJsonPath('pet_id', $pet->id)->json('pin');

        $child2 = User::factory()->child()->create(['parent_id' => null]);
        actingAsRole($child2);
        postJson('/api/child/pair', ['pin' => $pin])->assertCreated()
            ->assertJsonPath('joined_existing', true)
            ->assertJsonPath('pet.id', $pet->id)
            ->assertJsonPath('pet.awaiting_contract', true)
            ->assertJsonPath('pet.born_at', $bornAt);

        $pet->refresh();
        expect(Pet::count())->toBe(1)
            ->and($pet->user_id)->toBe($child1->id)
            ->and($pet->born_at->toIso8601String())->toBe($bornAt)
            ->and($pet->caretakers()->pluck('users.id')->all())->toBe([$child1->id, $child2->id])
            ->and($child2->fresh()->family->id)->toBe($parent->family->id)
            ->and($child2->fresh()->currentPet()->id)->toBe($pet->id)
            ->and($parent->fresh()->pairing_pet_id)->toBeNull();
    });

    it('lets the second parent generate a join PIN too', function () {
        [$parent, , $pet] = fmFamily();
        $second = fmSecondParent($parent);

        $child2 = fmJoinPet($second, $pet);
        expect($pet->caretakers()->count())->toBe(2)
            ->and($child2->parent_id)->toBe($second->id); // deprecated mirror = PIN issuer
    });

    it('refuses a pet of another family, a game-over pet and a pet that is gone by pairing time', function () {
        [$parent, , $pet] = fmFamily();
        [, , $foreign] = fmFamily();
        actingAsRole($parent);

        postJson('/api/parent/generate-pin', ['pet_id' => $foreign->id])
            ->assertStatus(422)->assertJsonPath('reason', 'pet_not_joinable');
        postJson('/api/parent/generate-pin', ['pet_id' => 999999])
            ->assertStatus(422)->assertJsonPath('reason', 'pet_not_joinable');
        postJson('/api/parent/generate-pin', ['pet_id' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('pet_id');

        $pin = postJson('/api/parent/generate-pin', ['pet_id' => $pet->id])->assertOk()->json('pin');
        $pet->update(['is_game_over' => true, 'is_active' => false]);

        $child2 = User::factory()->child()->create(['parent_id' => null]);
        actingAsRole($child2);
        postJson('/api/child/pair', ['pin' => $pin])->assertStatus(422);
        expect($child2->fresh()->parent_id)->toBeNull()
            ->and(FamilyMember::where('user_id', $child2->id)->exists())->toBeFalse();

        actingAsRole($parent);
        postJson('/api/parent/generate-pin', ['pet_id' => $pet->id])
            ->assertStatus(422)->assertJsonPath('reason', 'pet_not_joinable');
    });
});

// ──────────────────────────────────────────────────────────────
//  Shared pet: attribution, contracts, steps, stats
// ──────────────────────────────────────────────────────────────

describe('shared pet', function () {
    it('attributes every action to the child who did it', function () {
        [$parent, $child1, $pet] = fmFamily();
        $child2 = fmJoinPet($parent, $pet);

        actingAsRole($child1);
        postJson('/api/child/pet/feed')->assertOk()->assertJsonPath('status', 'accepted');
        actingAsRole($child2);
        postJson('/api/child/pet/water')->assertOk()->assertJsonPath('status', 'accepted');
        // One feed per window for the dog, whoever does it.
        postJson('/api/child/pet/feed')->assertStatus(422)->assertJsonPath('reason', 'already_fed_this_window');

        $actor = fn (ActivityType $t) => ActivityLog::where('pet_id', $pet->id)->where('activity_type', $t->value)->pluck('actor_user_id')->all();
        expect($actor(ActivityType::FedPet))->toBe([$child1->id])
            ->and($actor(ActivityType::WateredPet))->toBe([$child2->id])
            ->and($actor(ActivityType::SignedContract))->toBe([$child2->id]);
    });

    it('requires each joining child to sign their own contract; the pet is born once', function () {
        [$parent, $child1, $pet] = fmFamily();
        $bornAt = $pet->born_at->toIso8601String();
        $child2 = fmJoinPet($parent, $pet, sign: false);

        actingAsRole($child2);
        getJson('/api/child/pet')->assertOk()
            ->assertJsonPath('lock.reason', 'contract_required')
            ->assertJsonPath('pet.awaiting_contract', true)
            ->assertJsonPath('contract.signed', false);
        postJson('/api/child/pet/water')->assertStatus(423)->assertJsonPath('reason', 'contract_required');

        // The other child keeps playing.
        actingAsRole($child1);
        getJson('/api/child/pet')->assertJsonPath('lock.reason', null)->assertJsonPath('pet.awaiting_contract', false);
        postJson('/api/child/pet/water')->assertOk();

        $this->travel(5)->minutes();
        actingAsRole($child2);
        postJson('/api/child/contract', FM_SVG)->assertCreated()
            ->assertJsonPath('state.contract.signed', true)
            ->assertJsonPath('state.lock.reason', null);
        postJson('/api/child/contract', FM_SVG)->assertStatus(409)->assertJsonPath('reason', 'contract_already_signed');

        expect($pet->fresh()->born_at->toIso8601String())->toBe($bornAt)
            ->and(PetContract::where('pet_id', $pet->id)->pluck('user_id')->all())->toBe([$child2->id]);

        $this->travel(4)->hours(); // past the 3 h water gap
        postJson('/api/child/pet/water')->assertOk();
    });

    it('births an unborn shared pet at the first contract only', function () {
        seedBreedConfigs();
        fmAt('2026-10-04 06:00:00');
        $parent = User::factory()->parent()->create();
        actingAsRole($parent);
        $pin = postJson('/api/parent/generate-pin')->json('pin');
        $child1 = User::factory()->child()->create(['parent_id' => null]);
        actingAsRole($child1);
        $petId = postJson('/api/child/pair', ['pin' => $pin])->assertCreated()->json('pet.id');
        $pet = Pet::findOrFail($petId);

        $child2 = fmJoinPet($parent, $pet, sign: false);

        $this->travel(10)->minutes();
        actingAsRole($child2);
        postJson('/api/child/contract', FM_SVG)->assertCreated();
        $bornAt = $pet->fresh()->born_at;
        expect($bornAt->equalTo(now()->startOfSecond()))->toBeTrue();

        actingAsRole($child1);
        postJson('/api/child/pet/feed')->assertStatus(423)->assertJsonPath('reason', 'contract_required');

        $this->travel(10)->minutes();
        postJson('/api/child/contract', FM_SVG)->assertCreated();
        expect($pet->fresh()->born_at->equalTo($bornAt))->toBeTrue()
            ->and(PetContract::where('pet_id', $pet->id)->count())->toBe(2);
        postJson('/api/child/pet/feed')->assertOk();
    });

    it('counts steps per child and sums them for the daily walk', function () {
        [$parent, $child1, $pet] = fmFamily('2026-10-04 08:00:00'); // 10:00 local
        $child2 = fmJoinPet($parent, $pet);
        $at = '2026-10-04T10:00:00+02:00';

        actingAsRole($child1);
        postJson('/api/child/pet/steps', ['steps_today' => 1500, 'source' => 'healthkit', 'recorded_at' => $at])->assertOk()
            ->assertJsonPath('steps_today', 1500)
            ->assertJsonPath('state.steps.my_steps_today', 1500);

        actingAsRole($child2);
        postJson('/api/child/pet/steps', ['steps_today' => 2000, 'source' => 'healthkit', 'recorded_at' => $at])->assertOk()
            ->assertJsonPath('accepted_steps', 2000)
            ->assertJsonPath('steps_today', 3500)
            ->assertJsonPath('state.steps.my_steps_today', 2000);
        // Idempotent per child: the same count again changes nothing.
        postJson('/api/child/pet/steps', ['steps_today' => 2000, 'source' => 'healthkit', 'recorded_at' => $at])->assertOk()
            ->assertJsonPath('status', 'unchanged')
            ->assertJsonPath('steps_today', 3500);

        // Child 1's next sync (5 min later) completes the 4,000 goal → walk
        // attributed to child 1.
        $this->travel(5)->minutes();
        actingAsRole($child1);
        postJson('/api/child/pet/steps', ['steps_today' => 2100, 'source' => 'healthkit', 'recorded_at' => '2026-10-04T10:05:00+02:00'])->assertOk()
            ->assertJsonPath('steps_today', 4100)
            ->assertJsonPath('energy_level', 100);

        expect(PetDailyStep::where('pet_id', $pet->id)->orderBy('user_id')->pluck('steps', 'user_id')->all())
            ->toBe([$child1->id => 2100, $child2->id => 2000])
            ->and(ActivityLog::where('pet_id', $pet->id)->where('activity_type', 'walked_pet')->pluck('actor_user_id')->all())
            ->toBe([$child1->id]);
    });

    it('applies the anti-cheat cap per child', function () {
        [$parent, $child1, $pet] = fmFamily('2026-10-03 22:10:00'); // 00:10 local
        $child2 = fmJoinPet($parent, $pet);
        $at = '2026-10-04T00:10:00+02:00';

        // 10 minutes since local midnight → at most 2,000 steps per child.
        actingAsRole($child1);
        postJson('/api/child/pet/steps', ['steps_today' => 2000, 'source' => 'healthkit', 'recorded_at' => $at])->assertJsonPath('status', 'accepted');
        actingAsRole($child2);
        postJson('/api/child/pet/steps', ['steps_today' => 3000, 'source' => 'healthkit', 'recorded_at' => $at])
            ->assertJsonPath('status', 'capped')
            ->assertJsonPath('accepted_steps', 2000)
            ->assertJsonPath('steps_today', 4000);
    });

    it('gives steps recorded before per-child rows to the primary caretaker', function () {
        [$parent, $child1, $pet] = fmFamily('2026-10-04 08:00:00');
        Pet::whereKey($pet->id)->update(['daily_step_count' => 1000, 'last_step_sync_at' => '2026-10-04 07:00:00']);
        $child2 = fmJoinPet($parent, $pet);
        $at = '2026-10-04T10:00:00+02:00';

        actingAsRole($child2);
        postJson('/api/child/pet/steps', ['steps_today' => 500, 'source' => 'healthkit', 'recorded_at' => $at])->assertJsonPath('steps_today', 1500);
        actingAsRole($child1);
        postJson('/api/child/pet/steps', ['steps_today' => 1200, 'source' => 'healthkit', 'recorded_at' => $at])
            ->assertJsonPath('accepted_steps', 200)
            ->assertJsonPath('steps_today', 1700);
    });

    it('shows different per-child stats on the parent dashboard', function () {
        [$parent, $child1, $pet] = fmFamily('2026-10-04 07:00:00'); // 09:00 local, feed window
        $child2 = fmJoinPet($parent, $pet);
        $at = '2026-10-04T09:00:00+02:00';

        actingAsRole($child1);
        postJson('/api/child/pet/feed')->assertOk();
        postJson('/api/child/pet/steps', ['steps_today' => 800, 'source' => 'healthkit', 'recorded_at' => $at])->assertOk();
        actingAsRole($child2);
        postJson('/api/child/pet/water')->assertOk();
        postJson('/api/child/pet/clean')->assertOk(); // unchanged (already clean) → no row
        postJson('/api/child/pet/steps', ['steps_today' => 3300, 'source' => 'healthkit', 'recorded_at' => $at])->assertOk(); // 800 + 3300 ≥ 4000

        actingAsRole($parent);
        $dash = getJson('/api/parent/dashboard')->assertOk();
        $kids = collect($dash->json('family.children'))->keyBy('id');

        expect($kids[$child1->id]['pet_id'])->toBe($pet->id)
            ->and($kids[$child1->id]['stats'])->toMatchArray(['fed' => 1, 'watered' => 0, 'steps' => 800, 'actions_total' => 1, 'walk_goals' => 0])
            ->and($kids[$child2->id]['stats'])->toMatchArray(['fed' => 0, 'watered' => 1, 'steps' => 3300, 'actions_total' => 2, 'walk_goals' => 1])
            ->and($kids[$child2->id]['contract_signed'])->toBeTrue()
            ->and($kids[$child1->id]['contract_signed'])->toBeFalse() // grandfathered pet, never signed
            ->and($dash->json('family.pets.0.caretakers'))->toHaveCount(2)
            ->and(collect($dash->json('recent_activities'))->pluck('actor_user_id')->filter()->unique()->sort()->values()->all())
            ->toBe([$child1->id, $child2->id]);
    });
});

// ──────────────────────────────────────────────────────────────
//  Authorization: policies + channel
// ──────────────────────────────────────────────────────────────

describe('authorization in a family', function () {
    it('authorizes the channel for both caretakers and both parents, nobody else', function () {
        fmReverbAuth();
        [$parent, $child1, $pet] = fmFamily();
        $second = fmSecondParent($parent);
        $child2 = fmJoinPet($parent, $pet);

        // A sibling with their own pet, and another family.
        actingAsRole($parent);
        $pin = postJson('/api/parent/generate-pin')->json('pin');
        $sibling = User::factory()->child()->create(['parent_id' => null]);
        actingAsRole($sibling);
        $siblingPet = Pet::findOrFail(postJson('/api/child/pair', ['pin' => $pin])->json('pet.id'));
        [$otherParent, $otherChild] = fmFamily();

        foreach ([$child1, $child2, $parent, $second] as $allowed) {
            expect(fmChannel($allowed, $pet))->toBe(200);
        }
        foreach ([$sibling, $otherParent, $otherChild] as $denied) {
            expect(fmChannel($denied, $pet))->toBe(403);
        }
        // Both parents see the sibling's pet; the shared pet's children don't.
        expect(fmChannel($second, $siblingPet))->toBe(200)
            ->and(fmChannel($child1, $siblingPet))->toBe(403);
    });

    it('lets any parent of the family stop the right pet, never another family\'s', function () {
        [$parent, , $pet] = fmFamily();
        $second = fmSecondParent($parent);
        [, , $foreign] = fmFamily();

        actingAsRole($second);
        postJson('/api/parent/hard-stop', ['pet_id' => $pet->id])->assertOk()
            ->assertJsonPath('pet_id', $pet->id)
            ->assertJsonPath('is_hard_stopped', true);
        postJson('/api/parent/hard-stop', ['pet_id' => $foreign->id])->assertNotFound();
        expect($foreign->fresh()->is_hard_stopped)->toBeFalse();

        getJson("/api/parent/activities?pet_id={$foreign->id}")->assertNotFound();
        getJson("/api/parent/activities?pet_id={$pet->id}")->assertOk();
    });

    it('shares quiet hours and timezone between both parents', function () {
        [$parent, , $pet] = fmFamily();
        $second = fmSecondParent($parent);

        actingAsRole($parent);
        putJson('/api/parent/quiet-hours', ['bedtime_start' => '21:00', 'bedtime_end' => '07:00', 'is_active' => true])->assertOk();

        actingAsRole($second);
        getJson('/api/parent/quiet-hours')->assertOk()->assertJsonPath('quiet_hours.bedtime_start', '21:00');
        putJson('/api/parent/quiet-hours', ['bedtime_start' => '20:30', 'bedtime_end' => '07:00', 'is_active' => true])->assertOk();
        putJson('/api/parent/settings', ['timezone' => 'Europe/London'])->assertOk();

        expect(QuietHours::count())->toBe(1)
            ->and($pet->fresh()->quietHours()->bedtime_start)->toBe('20:30:00')
            ->and($pet->fresh()->familyTimezone())->toBe('Europe/London')
            ->and($parent->fresh()->timezone)->toBe('Europe/London')
            ->and($second->fresh()->timezone)->toBe('Europe/London');
    });
});

// ──────────────────────────────────────────────────────────────
//  Invariants + recipients
// ──────────────────────────────────────────────────────────────

describe('invariants', function () {
    it('never lets a child care for two active pets (service and database)', function () {
        [$parent, $child1, $pet] = fmFamily();
        $second = Pet::factory()->create(['user_id' => User::factory()->child()->create(['parent_id' => $pet->user->parent_id])->id]); // sibling's pet

        expect(fn () => app(FamilyService::class)->addCaretaker($second, $child1))
            ->toThrow(FamilyException::class, 'already cares for an active pet');

        expect(fn () => DB::transaction(fn () => PetCaretaker::create(['pet_id' => $second->id, 'user_id' => $child1->id])))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('frees the child once the pet is no longer active (trigger keeps the copy in sync)', function () {
        [, $child1, $pet] = fmFamily();
        $second = Pet::factory()->create(['user_id' => User::factory()->child()->create(['parent_id' => $pet->user->parent_id])->id]); // sibling's pet

        // Game over writes is_active quietly; the trigger still follows.
        $pet->forceFill(['is_game_over' => true, 'is_active' => false])->saveQuietly();
        expect(PetCaretaker::where('pet_id', $pet->id)->value('pet_is_active'))->toBeFalse();

        app(FamilyService::class)->addCaretaker($second, $child1);
        expect($child1->fresh()->currentPet()->id)->toBe($second->id);

        // Re-activating the first pet would give the child two active pets.
        expect(fn () => DB::transaction(fn () => Pet::whereKey($pet->id)->update(['is_active' => true])))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('never makes a parent a caretaker and keeps parents off the child API', function () {
        [$parent, , $pet] = fmFamily();

        expect(fn () => app(FamilyService::class)->addCaretaker($pet, $parent))
            ->toThrow(FamilyException::class, 'Only a child');

        actingAsRole($parent);
        postJson('/api/child/pet/feed')->assertForbidden();
    });

    it('resolves notification recipients: all parents, all caretakers', function () {
        [$parent, $child1, $pet] = fmFamily();
        $second = fmSecondParent($parent);
        $child2 = fmJoinPet($parent, $pet);

        $families = app(FamilyService::class);
        expect($families->parentRecipients($pet)->pluck('id')->all())->toBe([$parent->id, $second->id])
            ->and($families->caretakerRecipients($pet)->pluck('id')->sort()->values()->all())->toBe([$child1->id, $child2->id]);
    });
});

// ──────────────────────────────────────────────────────────────
//  Review fixes (PR #14): deletes, races, deploy window, input
// ──────────────────────────────────────────────────────────────

describe('family deletion never takes child data with it', function () {
    it('restricts deleting a family that still has pets or members', function () {
        [, , $pet] = fmFamily();
        $familyId = $pet->family_id;

        expect(fn () => DB::transaction(fn () => DB::table('families')->where('id', $familyId)->delete()))
            ->toThrow(QueryException::class);

        // Members alone block it too.
        $lonely = User::factory()->parent()->create();
        $lonelyFamily = $lonely->family->id;
        expect(fn () => DB::transaction(fn () => DB::table('families')->where('id', $lonelyFamily)->delete()))
            ->toThrow(QueryException::class);

        expect(Pet::find($pet->id))->not->toBeNull()
            ->and(FamilyMember::where('family_id', $familyId)->count())->toBe(2);
    });

    it('rolls the join back when a pet lands in the old family between the check and the delete', function () {
        [$parent] = fmFamily();
        $joiner = User::factory()->parent()->create();
        $oldFamilyId = $joiner->family->id;
        $code = app(FamilyInviteService::class)->createInvite($parent)['code'];
        $kid = User::factory()->child()->create(['parent_id' => null]);

        // Simulate a pairing that commits right after the membership row is
        // removed (what the user-row + family-row locks prevent in reality).
        $injected = false;
        DB::listen(function ($query) use (&$injected, $kid, $oldFamilyId) {
            if (! $injected && str_starts_with($query->sql, 'delete from "family_user"')) {
                $injected = true;
                DB::table('pets')->insert([
                    'user_id' => $kid->id, 'family_id' => $oldFamilyId, 'breed_type' => 'mutt', 'is_active' => true,
                ]);
            }
        });

        actingAsRole($joiner);
        postJson('/api/parent/join-family', ['code' => $code])
            ->assertStatus(409)->assertJsonPath('reason', 'family_not_empty');

        expect($injected)->toBeTrue()
            ->and(Family::find($oldFamilyId))->not->toBeNull()
            ->and(FamilyMember::where('user_id', $joiner->id)->value('family_id'))->toBe($oldFamilyId)
            ->and(FamilyInvite::where('code', $code)->value('used_at'))->toBeNull();
    });

    it('keeps the old family and hands its quiet hours to the remaining parent', function () {
        [$parentA] = fmFamily();
        $b = User::factory()->parent()->create();
        $c = fmSecondParent($b); // C joins B's (empty) family
        actingAsRole($b);
        putJson('/api/parent/quiet-hours', ['bedtime_start' => '21:00', 'bedtime_end' => '07:00', 'is_active' => true])->assertOk();
        $oldFamilyId = $b->fresh()->family->id;

        $code = app(FamilyInviteService::class)->createInvite($parentA)['code'];
        postJson('/api/parent/join-family', ['code' => $code])->assertOk();

        expect(Family::find($oldFamilyId))->not->toBeNull()
            ->and(QuietHours::where('family_id', $oldFamilyId)->value('parent_id'))->toBe($c->id)
            ->and($b->fresh()->family->id)->toBe($parentA->family->id);
    });
});

describe('deploy window and input hardening', function () {
    it('adopts a quiet-hours row the parent created without a family instead of failing', function () {
        [$parent] = fmFamily();
        $row = QuietHours::create(['parent_id' => $parent->id, 'bedtime_start' => '22:00', 'bedtime_end' => '06:00', 'is_active' => true]);
        DB::table('quiet_hours')->where('id', $row->id)->update(['family_id' => null]); // written by old code

        actingAsRole($parent);
        putJson('/api/parent/quiet-hours', ['bedtime_start' => '21:30', 'bedtime_end' => '06:30', 'is_active' => true])
            ->assertOk()->assertJsonPath('quiet_hours.bedtime_start', '21:30');

        expect(QuietHours::count())->toBe(1)
            ->and(QuietHours::first()->family_id)->toBe($parent->family->id);
    });

    it('refuses a second pairing of a child whose model is stale (422, not a 500)', function () {
        [$parent] = fmFamily();
        [$other] = fmFamily();
        $kid = User::factory()->child()->create(['parent_id' => null]);
        $pin = app(PairingService::class)->generatePin($parent)['pin'];

        // Another request paired the child meanwhile; this model still says unpaired.
        DB::table('users')->where('id', $kid->id)->update(['parent_id' => $other->id]);
        expect($kid->parent_id)->toBeNull();

        actingAsRole($kid);
        postJson('/api/child/pair', ['pin' => $pin])
            ->assertStatus(422)->assertJsonPath('message', 'This code cannot be used. Ask your parent for a new code.');
        expect(Pet::where('user_id', $kid->id)->exists())->toBeFalse();
    });

    it('answers 422 for a non-scalar pet_id', function () {
        [$parent] = fmFamily();
        actingAsRole($parent);

        getJson('/api/parent/activities?pet_id[]=1')->assertStatus(422)->assertJsonValidationErrors('pet_id');
        postJson('/api/parent/hard-stop', ['pet_id' => [1]])->assertStatus(422)->assertJsonValidationErrors('pet_id');
        postJson('/api/parent/generate-pin', ['pet_id' => ['x' => 1]])->assertStatus(422)->assertJsonValidationErrors('pet_id');
    });

    it('never creates a family on GET', function () {
        $parent = User::factory()->parent()->create();
        $familyId = $parent->family->id;
        FamilyMember::where('user_id', $parent->id)->delete();
        Family::whereKey($familyId)->delete();
        $families = Family::count();

        actingAsRole($parent->fresh());
        getJson('/api/parent/dashboard')->assertOk()
            ->assertJsonPath('pet', null)->assertJsonPath('family', null);
        getJson('/api/parent/quiet-hours')->assertOk()->assertJsonPath('quiet_hours', null);
        getJson('/api/parent/activities')->assertOk()->assertJsonPath('meta.total', 0);
        getJson('/api/parent/activities?pet_id=5')->assertNotFound();

        expect(Family::count())->toBe($families)
            ->and(FamilyMember::where('user_id', $parent->id)->exists())->toBeFalse();
    });

    it('never makes a parent owner a caretaker (legacy write path)', function () {
        $parent = User::factory()->parent()->create();
        $pet = Pet::factory()->create(['user_id' => $parent->id]);

        expect($pet->family_id)->toBe($parent->family->id)
            ->and(PetCaretaker::where('pet_id', $pet->id)->exists())->toBeFalse();
    });
});
