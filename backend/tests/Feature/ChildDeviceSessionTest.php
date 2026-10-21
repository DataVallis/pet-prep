<?php

use App\Exceptions\ChildLoginException;
use App\Models\DevicePushToken;
use App\Models\Pet;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\AccountDeletionService;
use App\Services\ChildPinLoginService;
use App\Services\ChildProfileService;
use App\Services\FamilyInviteService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
| M2-11 — profile switching parent ↔ child on one phone (David 2026-10-14):
| POST /api/parent/children/{child}/device-session issues a child token like a
| PIN re-login, without a PIN; shared-phone push rows for parent + child.
*/

beforeEach(function () {
    seedBreedConfigs();
});

/** Parent + child paired through the real PIN flow (new pet, unborn). */
function dsFamily(): array
{
    $parent = User::factory()->parent()->create();
    $child = app(ChildProfileService::class)->createChild($parent, 'Maja', null);
    $pin = app(ChildPinLoginService::class)->generatePin($parent, $child->id, null)['pin'];
    dsGuest('198.51.100.60');
    test()->withoutMiddleware([ThrottleRequests::class]);
    postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'iPhone'])->assertOk();
    test()->withMiddleware([ThrottleRequests::class]);

    return [$parent, $child->fresh()];
}

function dsGuest(string $ip = '198.51.100.61'): void
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => ''])->withServerVariables(['REMOTE_ADDR' => $ip]);
}

/** A real parent bearer token. */
function dsParentToken(User $parent): string
{
    return $parent->createToken('Pixel 8', ['parent'])->plainTextToken;
}

function dsSession(string $bearer, int|string $childId, array $body = ['device_name' => 'Pixel 8']): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => 'Bearer '.$bearer]);

    return postJson("/api/parent/children/{$childId}/device-session", $body);
}

function dsAs(string $bearer): void
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => 'Bearer '.$bearer]);
}

describe('POST /api/parent/children/{child}/device-session', function () {
    it('issues a child token without a PIN — same body as a PIN re-login, works on child endpoints', function () {
        [$parent, $child] = dsFamily();
        $parentToken = dsParentToken($parent);

        $res = dsSession($parentToken, $child->id)->assertOk()
            ->assertJsonPath('abilities', ['child'])
            ->assertJsonPath('user.id', $child->id)
            ->assertJsonPath('user.role', 'child')
            ->assertJsonPath('mode', 'relogin')
            ->assertJsonPath('joined_existing', false)
            ->assertJsonPath('awaiting_contract', true)
            ->assertJsonMissingPath('user.email');

        // Exactly the keys of a PIN re-login of the same child.
        $pin = app(ChildPinLoginService::class)->generatePin($parent, $child->id, null);
        expect($pin['mode'])->toBe('relogin');
        dsGuest('198.51.100.62');
        $this->withoutMiddleware([ThrottleRequests::class]);
        $viaPin = postJson('/api/child/pin-login', ['pin' => $pin['pin'], 'device_name' => 'iPhone'])->assertOk();
        expect(array_keys($res->json()))->toBe(array_keys($viaPin->json()))
            ->and(array_keys($res->json('pet')))->toBe(array_keys($viaPin->json('pet')))
            ->and($res->json('pet.id'))->toBe($viaPin->json('pet.id'));

        $token = PersonalAccessToken::findToken($res->json('token'));
        expect($token->tokenable_id)->toBe($child->id)
            ->and($token->abilities)->toBe(['child'])
            ->and($token->name)->toBe(ChildPinLoginService::sharedTokenPrefix($parent).'Pixel 8');

        dsAs($res->json('token'));
        getJson('/api/child/pet')->assertOk();
        getJson('/api/parent/dashboard')->assertForbidden();
    });

    it('keeps only a model-like device label (child PII rule)', function () {
        [$parent, $child] = dsFamily();

        $res = dsSession(dsParentToken($parent), $child->id, ['device_name' => "Maja Novak's iPhone"])->assertOk();
        expect(PersonalAccessToken::findToken($res->json('token'))->name)
            ->toBe(ChildPinLoginService::sharedTokenPrefix($parent).ChildProfileService::DEFAULT_DEVICE_LABEL);
    });

    it('refuses another family\'s child, a parent id, a missing or non-numeric id (404) and a child token (403)', function () {
        [$parent, $child] = dsFamily();
        [$otherParent, $otherChild] = dsFamily();
        $token = dsParentToken($parent);

        foreach ([$otherChild->id, $otherParent->id, $parent->id, 999999, 'abc'] as $id) {
            dsSession($token, $id)->assertNotFound()->assertJsonPath('reason', 'child_not_found');
        }
        expect(PersonalAccessToken::where('tokenable_id', $otherChild->id)->count())->toBe(1);

        $childToken = $child->createToken('iPad', ['child'])->plainTextToken;
        dsSession($childToken, $child->id)->assertForbidden();
    });

    it('the service itself refuses a child of another family (defence in depth below the controller)', function () {
        [$parent] = dsFamily();
        [, $otherChild] = dsFamily();

        expect(fn () => app(ChildPinLoginService::class)->deviceSession($parent, $otherChild, 'Pixel 8'))
            ->toThrow(fn (ChildLoginException $e) => expect([$e->reason, $e->status])->toBe(['child_not_found', 404]));
        expect($otherChild->tokens()->count())->toBe(1);
    });

    it('answers 409 no_pet for a child who was never paired (no token issued)', function () {
        $parent = User::factory()->parent()->create();
        $child = app(ChildProfileService::class)->createChild($parent, 'Luka', null);

        dsSession(dsParentToken($parent), $child->id)->assertStatus(409)->assertJsonPath('reason', 'no_pet');
        expect($child->tokens()->count())->toBe(0);
    });

    it('is not one of the 3 own devices, and switching again replaces the shared session', function () {
        [$parent, $child] = dsFamily(); // 1 own phone (PIN login)
        $child->createToken('iPad', ['child']);
        $child->createToken('Galaxy', ['child']); // 3 own devices now
        $ownIds = $child->tokens()->orderBy('id')->pluck('id')->all();
        $token = dsParentToken($parent);

        $first = dsSession($token, $child->id)->assertOk()->json('token');
        // The shared session comes on top of the own phones — none of them is pushed out.
        expect($child->tokens()->count())->toBe(ChildProfileService::MAX_DEVICES + 1)
            ->and($child->tokens()->pluck('id')->all())->toContain(...$ownIds);

        $second = dsSession($token, $child->id)->assertOk()->json('token');
        expect($child->tokens()->count())->toBe(ChildProfileService::MAX_DEVICES + 1)
            ->and(PersonalAccessToken::findToken($first))->toBeNull()
            ->and(PersonalAccessToken::findToken($second))->not->toBeNull()
            ->and($child->tokens()->pluck('id')->all())->toContain(...$ownIds);
    });

    it('survives new own-phone logins of the child (fix 2026-10-21: the parent phone was signed out every 1–2 days)', function () {
        [$parent, $child] = dsFamily();
        $shared = dsSession(dsParentToken($parent), $child->id)->assertOk()->json('token');

        // The child signs in on its own phones again and again (new PIN logins, reinstalls).
        foreach (range(1, 5) as $i) {
            $child->createToken("Phone {$i}", ['child']);
            app(ChildProfileService::class)->pruneDevices($child);
        }

        expect(PersonalAccessToken::findToken($shared))->not->toBeNull()
            ->and($child->tokens()->where('name', 'not like', 'shared:p%')->count())->toBe(ChildProfileService::MAX_DEVICES);
        dsAs($shared);
        getJson('/api/user')->assertOk()->assertJsonPath('id', $child->id);
    });

    it('child logout leaves the parent signed in, and the other way round', function () {
        [$parent, $child] = dsFamily();
        $parentToken = dsParentToken($parent);
        $childToken = dsSession($parentToken, $child->id)->assertOk()->json('token');

        dsAs($childToken);
        postJson('/api/logout')->assertOk();
        dsAs($childToken);
        getJson('/api/child/pet')->assertUnauthorized();
        dsAs($parentToken);
        getJson('/api/user')->assertOk()->assertJsonPath('id', $parent->id);

        $childToken = dsSession($parentToken, $child->id)->assertOk()->json('token');
        dsAs($parentToken);
        postJson('/api/logout')->assertOk();
        dsAs($parentToken);
        getJson('/api/user')->assertUnauthorized();
        dsAs($childToken);
        getJson('/api/child/pet')->assertOk();
    });

    it('is throttled: 20 per minute per parent', function () {
        $parent = User::factory()->parent()->create();
        $child = app(ChildProfileService::class)->createChild($parent, 'Luka', null);
        $token = dsParentToken($parent);

        foreach (range(1, AppServiceProvider::CHILD_DEVICE_SESSION_PER_MINUTE) as $i) {
            dsSession($token, $child->id)->assertStatus(409);
        }
        dsSession($token, $child->id)->assertStatus(429)->assertHeader('Retry-After');

        $other = User::factory()->parent()->create();
        dsSession(dsParentToken($other), $child->id)->assertNotFound();
    });
});

describe('push devices on a shared family phone', function () {
    it('keeps a parent row and a child row for the same install; each goes with its own logout', function () {
        [$parent, $child] = dsFamily();
        $parentToken = dsParentToken($parent);
        $childToken = dsSession($parentToken, $child->id)->assertOk()->json('token');
        $expo = 'ExponentPushToken[sharedFamilyPhone0000]';

        dsAs($parentToken);
        postJson('/api/devices', ['expo_push_token' => $expo, 'platform' => 'android'])->assertOk();
        dsAs($childToken);
        postJson('/api/devices', ['expo_push_token' => $expo, 'platform' => 'android'])->assertOk();
        dsAs($parentToken);
        postJson('/api/devices', ['expo_push_token' => $expo, 'platform' => 'android'])->assertOk(); // app restart: refresh only

        expect(DevicePushToken::where('expo_push_token', $expo)->orderBy('user_id')->pluck('user_id')->all())
            ->toBe([$parent->id, $child->id]);

        dsAs($childToken);
        postJson('/api/logout')->assertOk();
        expect(DevicePushToken::where('expo_push_token', $expo)->pluck('user_id')->all())->toBe([$parent->id]);
    });

    it('moves the install away from another family and from rows without a live session', function () {
        [$parent, $child] = dsFamily();
        $childToken = dsSession(dsParentToken($parent), $child->id)->assertOk()->json('token');
        $expo = 'ExponentPushToken[resoldPhone000000000]';
        dsAs($childToken);
        postJson('/api/devices', ['expo_push_token' => $expo, 'platform' => 'ios'])->assertOk();

        // A stranger signs in on the same phone: the child's row is gone.
        $stranger = User::factory()->parent()->create();
        dsAs(dsParentToken($stranger));
        postJson('/api/devices', ['expo_push_token' => $expo, 'platform' => 'ios'])->assertOk();
        expect(DevicePushToken::where('expo_push_token', $expo)->pluck('user_id')->all())->toBe([$stranger->id]);

        // A family member's row without a session (legacy / in-memory token) is moved too.
        DevicePushToken::where('expo_push_token', $expo)->delete();
        DevicePushToken::create(['user_id' => $child->id, 'expo_push_token' => $expo, 'platform' => 'ios', 'last_seen_at' => now()]);
        dsAs(dsParentToken($parent));
        postJson('/api/devices', ['expo_push_token' => $expo, 'platform' => 'ios'])->assertOk();
        expect(DevicePushToken::where('expo_push_token', $expo)->pluck('user_id')->all())->toBe([$parent->id]);
    });
});

/** A second parent who joined $parent's family with an invite code. */
function dsSecondParent(User $parent): User
{
    $second = User::factory()->parent()->create();
    $invites = app(FamilyInviteService::class);
    $invites->joinFamily($second, $invites->createInvite($parent)['code']);

    return $second->refresh();
}

describe('QA round 2 (David 2026-10-14)', function () {
    it('each parent of the family has their own shared session; the second does not replace the first', function () {
        [$parent, $child] = dsFamily();
        $second = dsSecondParent($parent);

        $a = dsSession(dsParentToken($parent), $child->id)->assertOk()->json('token');
        $b = dsSession(dsParentToken($second), $child->id)->assertOk()->json('token');

        expect(PersonalAccessToken::findToken($a))->not->toBeNull()
            ->and(PersonalAccessToken::findToken($b))->not->toBeNull()
            ->and(PersonalAccessToken::findToken($b)->name)->toStartWith(ChildPinLoginService::sharedTokenPrefix($second));
    });

    it('a parent who leaves (deletes their account, family stays) takes their shared child sessions along — nobody else\'s', function () {
        [$parent, $child] = dsFamily();
        $second = dsSecondParent($parent);
        $own = $child->tokens()->value('id');
        $first = dsSession(dsParentToken($parent), $child->id)->assertOk()->json('token');
        $leaving = dsSession(dsParentToken($second), $child->id)->assertOk()->json('token');

        expect(app(AccountDeletionService::class)->deleteParentAccount($second)['scope'])->toBe('parent');

        expect(PersonalAccessToken::findToken($leaving))->toBeNull()
            ->and(PersonalAccessToken::findToken($first))->not->toBeNull()
            ->and(PersonalAccessToken::find($own))->not->toBeNull()
            ->and(User::find($child->id))->not->toBeNull();
    });

    it('the whole family deletion removes the child with every session', function () {
        [$parent, $child] = dsFamily();
        $shared = dsSession(dsParentToken($parent), $child->id)->assertOk()->json('token');

        app(AccountDeletionService::class)->deleteParentAccount($parent);

        expect(PersonalAccessToken::findToken($shared))->toBeNull()->and(User::find($child->id))->toBeNull();
    });

    it('"sign out all devices" (DELETE children/{child}/tokens) also ends shared sessions', function () {
        [$parent, $child] = dsFamily();
        $parentToken = dsParentToken($parent);
        $shared = dsSession($parentToken, $child->id)->assertOk()->json('token');

        dsAs($parentToken);
        deleteJson("/api/parent/children/{$child->id}/tokens")->assertOk();

        expect(PersonalAccessToken::findToken($shared))->toBeNull()
            ->and($child->tokens()->count())->toBe(0);
    });

    it('superadmin: like any parent inside their own family, 404 for another family', function () {
        [$parent, $child] = dsFamily();
        $parent->forceFill(['is_superadmin' => true])->save();
        [, $otherChild] = dsFamily();
        $token = dsParentToken($parent);

        dsSession($token, $child->id)->assertOk();
        dsSession($token, $otherChild->id)->assertNotFound()->assertJsonPath('reason', 'child_not_found');
    });

    it('a cat needs species_cat: 422 app_update_required without it, 200 with it', function () {
        [$parent, $child] = dsFamily();
        Pet::whereKey($child->currentPet()->id)->update(['species' => 'cat', 'breed_type' => 'maine_coon']);
        $token = dsParentToken($parent);

        dsSession($token, $child->id, ['device_name' => 'iPhone', 'features' => ['behaviour_events']])
            ->assertStatus(422)->assertJsonPath('reason', 'app_update_required');
        expect($child->tokens()->count())->toBe(1);

        dsSession($token, $child->id, ['device_name' => 'iPhone', 'features' => ['species_cat']])->assertOk();
    });

    it('migration down() keeps exactly one row per install (newest; NULL timestamps count as oldest), up() restores per-user rows', function () {
        [$parent, $child] = dsFamily();
        $expo = 'ExponentPushToken[migrationDown000000]';
        $old = DevicePushToken::create(['user_id' => $parent->id, 'expo_push_token' => $expo, 'platform' => 'ios', 'last_seen_at' => now()->subDay()]);
        $new = DevicePushToken::create(['user_id' => $child->id, 'expo_push_token' => $expo, 'platform' => 'ios', 'last_seen_at' => now()]);
        $nullA = DevicePushToken::create(['user_id' => $parent->id, 'expo_push_token' => 'ExponentPushToken[nullTimes000000000]', 'platform' => 'ios']);
        $nullB = DevicePushToken::create(['user_id' => $child->id, 'expo_push_token' => 'ExponentPushToken[nullTimes000000000]', 'platform' => 'ios']);
        DevicePushToken::whereIn('id', [$nullA->id, $nullB->id])->update(['last_seen_at' => null, 'created_at' => null]);

        $migration = require database_path('migrations/2026_12_11_120000_device_push_tokens_unique_per_user.php');
        $migration->down();

        expect(DevicePushToken::where('expo_push_token', $expo)->pluck('id')->all())->toBe([$new->id])
            ->and(DevicePushToken::where('expo_push_token', 'ExponentPushToken[nullTimes000000000]')->pluck('id')->all())->toBe([$nullB->id]);

        $migration->up();
        DevicePushToken::create(['user_id' => $parent->id, 'expo_push_token' => $expo, 'platform' => 'ios']);
        expect(DevicePushToken::where('expo_push_token', $expo)->count())->toBe(2);
        expect($old->exists)->toBeTrue();
    });
});
