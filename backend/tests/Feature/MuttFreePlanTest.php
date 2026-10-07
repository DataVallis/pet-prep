<?php

use App\Enums\ChallengePaidSource;
use App\Enums\ChallengeStatus;
use App\Enums\PetPlan;
use App\Events\PetUpdated;
use App\Models\Pet;
use App\Models\User;
use App\Services\ChildProfileService;
use App\Services\PetPlanPayload;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| M5-F02 / M5-F03 — the mutt is the free dog (David 2026-10-07)
|--------------------------------------------------------------------------
|
| F02: a mutt is never shown to a parent as "Paid" — the M3-11 backfill made
| every existing pet (mutts too) a `grandfathered` paid challenge. The plan
| payload adds `display_type`: `free` for a free pet and for a mutt whose
| challenge nobody bought; the pet's real plan, program clock and
| entitlements stay untouched.
|
| F03: the 12-week challenge needs a paid breed — a new pet with an explicit
| `plan: challenge` and the mutt (or no breed → mutt) → 422
| `challenge_requires_paid_breed`.
|
*/

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-07 10:00:00', 'UTC'));
    seedBreedConfigs();
    seedLifeStageData();
    $this->withoutMiddleware([ThrottleRequests::class]);
});

/** @param array<string, mixed> $body */
function mfPin(User $parent, array $body, ?User $child = null): TestResponse
{
    $child ??= app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function mfPinLogin(string $pin): Pet
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return Pet::findOrFail(postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet'])->assertSuccessful()->json('pet.id'));
}

/**
 * @param  array<string, mixed>  $attributes
 * @return array{0: User, 1: Pet}
 */
function mfFamilyPet(callable $factory, array $attributes = []): array
{
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    $pet = $factory(Pet::factory())->create(array_merge(['user_id' => $child->id], $attributes));

    return [$parent, disableHygieneEvents($pet)];
}

/** @return array<string, mixed> */
function mfDashboardPlan(User $parent, Pet $pet): array
{
    app('auth')->forgetGuards();
    actingAsRole($parent);
    $family = getJson('/api/parent/dashboard')->assertOk()->json('family');

    return collect($family['pets'])->firstWhere('id', $pet->id)['plan'];
}

// ─────────────────────────────────────────────────────────────────────────
describe('M5-F03 generate-pin: the challenge needs a paid breed', function () {
    it('refuses plan challenge with the mutt', function () {
        $parent = User::factory()->parent()->create();

        mfPin($parent, ['plan' => 'challenge', 'breed' => 'mutt', 'origin' => 'bought', 'age_stage' => 'puppy'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'challenge_requires_paid_breed');
    });

    it('refuses plan challenge without a breed (profile → mutt default)', function () {
        $parent = User::factory()->parent()->create();

        mfPin($parent, ['plan' => 'challenge', 'origin' => 'adopted', 'age_stage' => 'adult'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'challenge_requires_paid_breed');
    });

    it('refuses plan challenge without any profile (legacy mutt)', function () {
        $parent = User::factory()->parent()->create();

        mfPin($parent, ['plan' => 'challenge'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'challenge_requires_paid_breed');
    });

    it('creates no PIN when it refuses', function () {
        $parent = User::factory()->parent()->create();
        $child = app(ChildProfileService::class)->createChild($parent, 'Maja', null);
        $old = mfPin($parent, ['plan' => 'free', 'breed' => 'mutt', 'origin' => 'bought', 'age_stage' => 'puppy'], $child)->assertOk()->json('pin');

        mfPin($parent, ['plan' => 'challenge', 'breed' => 'mutt', 'origin' => 'bought', 'age_stage' => 'puppy'], $child)->assertStatus(422);

        // The previous PIN is not revoked by the refused request.
        expect(mfPinLogin($old)->plan)->toBe(PetPlan::Free);
    });

    it('creates a Border Collie challenge on the trial', function () {
        $parent = User::factory()->parent()->create();
        $pin = mfPin($parent, ['plan' => 'challenge', 'breed' => 'border_collie', 'origin' => 'bought', 'age_stage' => 'puppy'])
            ->assertOk()->assertJsonPath('plan', 'challenge')->json('pin');
        $pet = mfPinLogin($pin);

        expect($pet->plan)->toBe(PetPlan::Challenge)
            ->and($pet->breed_type->value)->toBe('border_collie')
            ->and($pet->challengeStatus())->toBe(ChallengeStatus::Trial);
    });

    it('creates a free mutt from plan free', function () {
        $parent = User::factory()->parent()->create();
        $pin = mfPin($parent, ['plan' => 'free', 'breed' => 'mutt', 'origin' => 'adopted', 'age_stage' => 'senior'])
            ->assertOk()->assertJsonPath('plan', 'free')->json('pin');

        expect(mfPinLogin($pin)->plan)->toBe(PetPlan::Free);
    });

    it('keeps old builds working: no plan → challenge default, also with the mutt (unchanged)', function () {
        $parent = User::factory()->parent()->create();

        mfPin($parent, [])->assertOk()->assertJsonPath('plan', 'challenge');
        mfPin($parent, ['breed' => 'mutt', 'origin' => 'bought', 'age_stage' => 'puppy'])->assertOk()->assertJsonPath('plan', 'challenge');
    });

    it('ignores the plan when joining a pet or re-logging in', function () {
        [$parent, $pet] = mfFamilyPet(fn ($f) => $f->mutt());
        $first = $pet->caretakers()->first();

        // Join an existing mutt: the plan of the request is ignored (no 422).
        mfPin($parent, ['pet_id' => $pet->id, 'plan' => 'challenge'])->assertOk()->assertJsonPath('mode', 'join_pet');
        // Re-login of a paired child: no new pet → no plan check.
        mfPin($parent, ['plan' => 'challenge'], $first)->assertOk()->assertJsonPath('mode', 'relogin');
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('M5-F02 plan payload: a mutt is shown as free', function () {
    it('shows a grandfathered mutt as free and keeps its real plan, clock and entitlements', function () {
        [$parent, $pet] = mfFamilyPet(fn ($f) => $f->mutt()); // factory default = grandfathered paid challenge
        $bornAt = $pet->born_at->toIso8601String();

        expect($pet->challenge_paid_source)->toBe(ChallengePaidSource::Grandfathered);

        $plan = mfDashboardPlan($parent, $pet);
        expect($plan['display_type'])->toBe('free')
            // The real plan is unchanged (nothing locks, the 12-week programme keeps running).
            ->and($plan['type'])->toBe('challenge')
            ->and($plan['status'])->toBe('paid');

        $pet->refresh();
        expect($pet->plan)->toBe(PetPlan::Challenge)
            ->and($pet->challengeStatus())->toBe(ChallengeStatus::Paid)
            ->and($pet->isFreePlan())->toBeFalse()
            ->and($pet->born_at->toIso8601String())->toBe($bornAt)
            ->and($pet->payment_locked_at)->toBeNull();
    });

    it('shows an admin-unlocked mutt as free', function () {
        [$parent, $pet] = mfFamilyPet(fn ($f) => $f->mutt(), ['challenge_paid_source' => 'admin']);

        expect(mfDashboardPlan($parent, $pet)['display_type'])->toBe('free');
    });

    it('shows a free-plan mutt as free', function () {
        [$parent, $pet] = mfFamilyPet(fn ($f) => $f->mutt()->freePlan());

        $plan = mfDashboardPlan($parent, $pet);
        expect($plan['type'])->toBe('free')->and($plan['display_type'])->toBe('free');
    });

    it('keeps a grandfathered Border Collie as a paid challenge', function () {
        [$parent, $pet] = mfFamilyPet(fn ($f) => $f->borderCollie());

        $plan = mfDashboardPlan($parent, $pet);
        expect($plan['type'])->toBe('challenge')->and($plan['status'])->toBe('paid')->and($plan['display_type'])->toBe('challenge');
    });

    it('keeps a purchased challenge as a challenge (honest: somebody paid), also for a mutt', function () {
        [$parent, $pet] = mfFamilyPet(fn ($f) => $f->mutt()->purchased());

        expect(mfDashboardPlan($parent, $pet)['display_type'])->toBe('challenge');
    });

    it('keeps an unpaid mutt challenge (created before M5-F03) on its trial', function () {
        [$parent, $pet] = mfFamilyPet(fn ($f) => $f->mutt()->trial());

        $plan = mfDashboardPlan($parent, $pet);
        expect($plan['status'])->toBe('trial')->and($plan['display_type'])->toBe('challenge');
    });

    it('sends display_type in the child state and the broadcast too', function () {
        [, $pet] = mfFamilyPet(fn ($f) => $f->mutt());
        $child = $pet->caretakers()->first();

        app('auth')->forgetGuards();
        actingAsRole($child);
        getJson('/api/child/pet')->assertOk()->assertJsonPath('pet.plan.display_type', 'free');

        expect(PetUpdated::fromPet($pet->fresh(), 'test')->broadcastWith()['plan']['display_type'])->toBe('free')
            ->and(PetPlanPayload::for($pet->fresh())->toArray())->toHaveKey('display_type', 'free');
    });
});
