<?php

use App\Enums\ChallengePaidSource;
use App\Enums\ChallengeStatus;
use App\Enums\PetPlan;
use App\Events\PetUpdated;
use App\Models\ChildLoginPin;
use App\Models\Pet;
use App\Models\PetStatusPeriod;
use App\Models\User;
use App\Services\ChallengeService;
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

    it('old builds (no plan): no 422; the mutt becomes the free plan, a paid breed the challenge (P4)', function () {
        $parent = User::factory()->parent()->create();

        $legacy = mfPin($parent, [])->assertOk()->assertJsonPath('plan', 'free')->assertJsonPath('trial_available', null)->json('pin');
        expect(mfPinLogin($legacy)->plan)->toBe(PetPlan::Free);

        $mutt = mfPin($parent, ['breed' => 'mutt', 'origin' => 'bought', 'age_stage' => 'puppy'])->assertOk()->assertJsonPath('plan', 'free')->json('pin');
        expect(mfPinLogin($mutt)->plan)->toBe(PetPlan::Free);

        mfPin($parent, ['breed' => 'border_collie', 'origin' => 'bought', 'age_stage' => 'puppy'])->assertOk()->assertJsonPath('plan', 'challenge');
    });

    it('a PIN stored as challenge + mutt before M5-F03 still creates a free mutt (never a lockable challenge)', function () {
        $parent = User::factory()->parent()->create();
        $pin = mfPin($parent, ['plan' => 'free', 'breed' => 'mutt', 'origin' => 'bought', 'age_stage' => 'puppy'])->assertOk()->json('pin');
        // Simulate a PIN issued by the previous release.
        ChildLoginPin::open()->update(['plan' => 'challenge']);

        $pet = mfPinLogin($pin);
        expect($pet->plan)->toBe(PetPlan::Free)->and($pet->trial_ends_at)->toBeNull();
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

// ─────────────────────────────────────────────────────────────────────────
describe('M5-F02 data migration: unpaid mutt challenges become the free plan (P4)', function () {
    function mfConvert(Pet $pet): bool
    {
        return app(ChallengeService::class)->convertUnpaidMuttToFree($pet->id, now());
    }

    it('converts a mutt on its trial; it can never be locked afterwards', function () {
        [$parent, $pet] = mfFamilyPet(fn ($f) => $f->mutt()->trial());
        expect($pet->challengeStatus())->toBe(ChallengeStatus::Trial);

        expect(mfConvert($pet))->toBeTrue();
        $pet->refresh();
        expect($pet->plan)->toBe(PetPlan::Free)
            ->and($pet->trial_ends_at)->toBeNull()
            ->and($pet->challengeStatus())->toBeNull()
            ->and($pet->converted_to_free_at)->not->toBeNull();

        $plan = mfDashboardPlan($parent, $pet);
        expect($plan)->toMatchArray(['type' => 'free', 'status' => null, 'display_type' => 'free']);

        // Weeks later: no lock, no payment status.
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00:00', 'UTC'));
        $this->artisan('pets:process-decay')->assertSuccessful();
        expect($pet->fresh()->isPaymentLocked())->toBeFalse()->and($pet->fresh()->actionLockReason())->toBeNull();
        // Nothing to buy for it.
        app('auth')->forgetGuards();
        actingAsRole($parent);
        postJson("/api/parent/pets/{$pet->id}/challenge/activate")->assertStatus(422)->assertJsonPath('reason', 'free_plan');
    });

    it('makes a payment-locked mutt playable again; the lock period is closed and stays out of the program clock', function () {
        [, $pet] = mfFamilyPet(fn ($f) => $f->mutt()->trial());
        $child = $pet->caretakers()->first();

        // Trial over (2026-10-14 10:00 UTC), the tick locks it.
        Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00', 'UTC'));
        $this->artisan('pets:process-decay')->assertSuccessful();
        $pet->refresh();
        expect($pet->isPaymentLocked())->toBeTrue()->and($pet->frozen_at)->not->toBeNull();

        // Two days locked; neglect clock running since before the lock.
        Pet::whereKey($pet->id)->update(['hunger_zero_since' => '2026-10-14 09:00:00']);
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00', 'UTC'));
        $programBefore = $pet->fresh()->programSecondsAt(now());

        expect(mfConvert($pet))->toBeTrue();
        $pet->refresh();

        expect($pet->plan)->toBe(PetPlan::Free)
            ->and($pet->payment_locked_at)->toBeNull()
            ->and($pet->frozen_at)->toBeNull()
            ->and($pet->last_decay_at->toIso8601String())->toBe('2026-10-16T10:00:00+00:00')
            // Thawed like a payment: the neglect clock moved forward by the 2 locked days.
            ->and($pet->hunger_zero_since->toIso8601String())->toBe('2026-10-16T09:00:00+00:00');

        $period = PetStatusPeriod::where('pet_id', $pet->id)->where('kind', 'payment_lock')->sole();
        expect($period->started_at->toIso8601String())->toBe('2026-10-14T10:00:00+00:00')
            ->and($period->ended_at?->toIso8601String())->toBe('2026-10-16T10:00:00+00:00');

        // The locked days are still not program time (dog age / life stage).
        expect($pet->programSecondsAt(now()))->toBe($programBefore);
        Carbon::setTestNow(Carbon::parse('2026-10-17 10:00:00', 'UTC'));
        expect($pet->fresh()->programSecondsAt(now()))->toBe($programBefore + 86400);

        // The child can play again.
        disableHygieneEvents($pet->fresh());
        app('auth')->forgetGuards();
        actingAsRole($child);
        getJson('/api/child/pet')->assertOk()->assertJsonPath('pet.plan.type', 'free');
        postJson('/api/child/pet/water')->assertOk();
    });

    it('converts an unborn mutt challenge', function () {
        [, $pet] = mfFamilyPet(fn ($f) => $f->mutt()->trial()->unborn());

        expect(mfConvert($pet))->toBeTrue()
            ->and($pet->fresh()->plan)->toBe(PetPlan::Free)
            ->and($pet->fresh()->born_at)->toBeNull();
    });

    it('never touches paid mutts, premium breeds or free pets, and is idempotent', function () {
        [, $grandfathered] = mfFamilyPet(fn ($f) => $f->mutt());
        [, $purchased] = mfFamilyPet(fn ($f) => $f->mutt()->purchased());
        [, $admin] = mfFamilyPet(fn ($f) => $f->mutt(), ['challenge_paid_source' => 'admin']);
        [, $collie] = mfFamilyPet(fn ($f) => $f->borderCollie()->trial());
        [, $free] = mfFamilyPet(fn ($f) => $f->mutt()->freePlan());

        foreach ([$grandfathered, $purchased, $admin, $collie, $free] as $pet) {
            $before = $pet->fresh()->only(['plan', 'trial_ends_at', 'challenge_paid_at', 'challenge_paid_source', 'payment_locked_at']);
            expect(mfConvert($pet))->toBeFalse()
                ->and($pet->fresh()->only(array_keys($before)))->toEqual($before)
                ->and($pet->fresh()->converted_to_free_at)->toBeNull();
        }

        [, $trial] = mfFamilyPet(fn ($f) => $f->mutt()->trial());
        expect(mfConvert($trial))->toBeTrue()->and(mfConvert($trial))->toBeFalse();
    });

    it('the migration converts exactly the unpaid mutt challenges', function () {
        [, $trial] = mfFamilyPet(fn ($f) => $f->mutt()->trial());
        [, $unborn] = mfFamilyPet(fn ($f) => $f->mutt()->trial()->unborn());
        [, $grandfathered] = mfFamilyPet(fn ($f) => $f->mutt());
        [, $collie] = mfFamilyPet(fn ($f) => $f->borderCollie()->trial());

        $migration = require database_path('migrations/2026_10_21_120000_convert_unpaid_mutt_challenges_to_free.php');
        $migration->down();
        $migration->up();

        expect($trial->fresh()->plan)->toBe(PetPlan::Free)
            ->and($unborn->fresh()->plan)->toBe(PetPlan::Free)
            ->and($grandfathered->fresh()->plan)->toBe(PetPlan::Challenge)
            ->and($grandfathered->fresh()->challenge_paid_source)->toBe(ChallengePaidSource::Grandfathered)
            ->and($collie->fresh()->plan)->toBe(PetPlan::Challenge)
            ->and($collie->fresh()->challengeStatus())->toBe(ChallengeStatus::Trial);
    });
});
