<?php

use App\Enums\ChallengePaidSource;
use App\Enums\ChallengeStatus;
use App\Enums\PetPlan;
use App\Models\ChallengeCredit;
use App\Models\Pet;
use App\Models\User;
use App\Services\ChildProfileService;
use App\Services\PetPlanPayload;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| M6-U02 — »mešanček + izziv« for new apps (FREE_PET_UPGRADE_SPEC U4 / K3 /
| §5.6, David 2026-11-10)
|--------------------------------------------------------------------------
| A parent build that declares the client feature `free_breed_challenge` may
| start the 12-week challenge with the species' FREE breed (mutt, domestic
| cat). That pet is a challenge pet: it waits for the purchase at birth (K3),
| exactly like a paid-breed challenge pet. Old builds keep M5-F03 byte for byte
| (MuttFreePlanTest): 422 `challenge_requires_paid_breed`, a stored challenge +
| free breed silently becomes the free plan.
*/

const FBC_SECRET = 'fbc-webhook-secret';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-11 10:00:00', 'UTC'));
    seedBreedConfigs();
    seedLifeStageData();
    config([
        'services.revenuecat.webhook_secret' => FBC_SECRET,
        'services.revenuecat.accept_sandbox' => true,
        'services.revenuecat.challenge_products' => ['petprep_challenge_12w'],
        'payments.enforced' => true,
    ]);
    $this->withoutMiddleware([ThrottleRequests::class]);
});

afterEach(fn () => Carbon::setTestNow());

/** @param array<string, mixed> $body */
function fbcPin(User $parent, array $body, ?User $child = null): TestResponse
{
    $child ??= app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

/** @param list<string> $features the CHILD device's features */
function fbcLogin(string $pin, array $features = []): Pet
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return Pet::findOrFail(postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => $features])
        ->assertSuccessful()->json('pet.id'));
}

function fbcSign(Pet $pet): TestResponse
{
    $child = User::findOrFail($pet->user_id);
    app('auth')->forgetGuards();
    actingAsRole($child);

    return postJson('/api/child/contract', ['signature_format' => 'svg_path', 'signature' => 'M10 10 L20 20']);
}

function fbcPurchase(User $parent): TestResponse
{
    app('auth')->forgetGuards();

    return test()->withHeaders(['Authorization' => 'Bearer '.FBC_SECRET])->postJson('/api/webhooks/revenuecat', [
        'api_version' => '1.0',
        'event' => [
            'id' => (string) Str::uuid(),
            'type' => 'NON_RENEWING_PURCHASE',
            'app_user_id' => (string) $parent->id,
            'product_id' => 'petprep_challenge_12w',
            'store' => 'APP_STORE',
            'environment' => 'PRODUCTION',
            'transaction_id' => 'tx-'.Str::random(8),
            'purchased_at_ms' => now()->getTimestampMs(),
            'expiration_at_ms' => null,
        ],
    ]);
}

/** @param list<string> $features */
function fbcCatalogue(User $parent, array $features): array
{
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return collect(getJson('/api/breeds?'.http_build_query(['features' => $features]))->assertOk()->json('breeds'))
        ->keyBy('breed')->all();
}

const FBC_MUTT_CHALLENGE = ['plan' => 'challenge', 'breed' => 'mutt', 'origin' => 'adopted', 'age_stage' => 'puppy', 'features' => ['free_breed_challenge']];

describe('new app: the mutt with the challenge', function () {
    it('is offered in the catalogue only to a build that declares free_breed_challenge', function () {
        $parent = User::factory()->parent()->create();

        expect(fbcCatalogue($parent, [])['mutt']['challenge_allowed'])->toBeFalse()
            ->and(fbcCatalogue($parent, ['free_breed_challenge'])['mutt']['challenge_allowed'])->toBeTrue()
            ->and(fbcCatalogue($parent, ['free_breed_challenge'])['mutt']['free_plan_allowed'])->toBeTrue()
            // a paid breed is unchanged either way
            ->and(fbcCatalogue($parent, ['free_breed_challenge'])['border_collie']['challenge_allowed'])->toBeTrue()
            ->and(fbcCatalogue($parent, ['free_breed_challenge'])['border_collie']['free_plan_allowed'])->toBeFalse();
    });

    it('creates an unborn mutt challenge that is payment-locked at birth (K3) and unlocked by the purchase', function () {
        $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
        $pin = fbcPin($parent, FBC_MUTT_CHALLENGE)->assertOk()->assertJsonPath('plan', 'challenge')->json('pin');

        // The CHILD's device does not need the feature — the parent chose the plan.
        $pet = fbcLogin($pin);
        expect($pet->plan)->toBe(PetPlan::Challenge)
            ->and($pet->breed_type->value)->toBe('mutt')
            ->and($pet->isUnborn())->toBeTrue()
            ->and($pet->challengeStatus())->toBe(ChallengeStatus::PaymentRequired);

        // Birth: locked like a paid-breed challenge pet waiting for payment.
        fbcSign($pet)->assertSuccessful();
        $pet = $pet->fresh();
        expect($pet->isPaymentLocked())->toBeTrue()
            ->and($pet->plan)->toBe(PetPlan::Challenge)
            ->and(PetPlanPayload::for($pet)->displayType)->toBe('challenge')
            ->and(PetPlanPayload::displaysAsFree($pet))->toBeFalse();

        // The family's only unpaid challenge pet: the webhook assigns the purchase (old rule).
        fbcPurchase($parent)->assertOk();
        $pet = $pet->fresh();
        expect($pet->challenge_paid_at)->not->toBeNull()
            ->and($pet->challenge_paid_source)->toBe(ChallengePaidSource::Purchase)
            ->and($pet->isPaymentLocked())->toBeFalse()
            ->and($pet->challengeStatus())->toBe(ChallengeStatus::Paid)
            ->and(ChallengeCredit::where('pet_id', $pet->id)->count())->toBe(1)
            // a challenge pet from the start: not an »upgrade« (no separate challenge clock)
            ->and($pet->challenge_started_at)->toBeNull();
    });

    it('takes the purchase held before birth at the contract (M3-13), like a paid breed', function () {
        $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
        $pet = fbcLogin(fbcPin($parent, FBC_MUTT_CHALLENGE)->assertOk()->json('pin'));

        fbcPurchase($parent)->assertOk(); // the webhook pays the unborn challenge pet
        fbcSign($pet)->assertSuccessful();

        $pet = $pet->fresh();
        expect($pet->challengeStatus())->toBe(ChallengeStatus::Paid)
            ->and($pet->isPaymentLocked())->toBeFalse();
    });

    it('is a challenge pet for the purchase intent and activation (not an upgradeable free pet)', function () {
        $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
        $pet = fbcLogin(fbcPin($parent, FBC_MUTT_CHALLENGE)->assertOk()->json('pin'));
        fbcSign($pet)->assertSuccessful();

        app('auth')->forgetGuards();
        actingAsRole($parent);
        $dashboardPet = collect(getJson('/api/parent/dashboard')->assertOk()->json('family.pets'))->firstWhere('id', $pet->id);
        expect($dashboardPet['upgrade'])->toBeNull()
            ->and($dashboardPet['upgrade_reminder'])->toBeNull()
            ->and($dashboardPet['plan']['status'])->toBe('payment_required');

        postJson("/api/parent/pets/{$pet->id}/challenge/intent")->assertSuccessful();
        fbcPurchase($parent)->assertOk();
        expect($pet->fresh()->challengeStatus())->toBe(ChallengeStatus::Paid);
    });

    it('offers the plain free mutt too (sandbox) — plan free with the feature stays free', function () {
        $parent = User::factory()->parent()->create();
        $pet = fbcLogin(fbcPin($parent, ['plan' => 'free'] + FBC_MUTT_CHALLENGE)->assertOk()->assertJsonPath('plan', 'free')->json('pin'));

        expect($pet->plan)->toBe(PetPlan::Free);
    });

    it('works for the domestic cat when cats are available', function () {
        config(['petprep.cats_enabled' => true]);
        $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
        $pin = fbcPin($parent, [
            'species' => 'cat', 'breed' => 'domestic_cat', 'origin' => 'adopted', 'age_stage' => 'young',
            'plan' => 'challenge', 'features' => ['species_cat', 'free_breed_challenge'],
        ])->assertOk()->assertJsonPath('plan', 'challenge')->json('pin');

        $pet = fbcLogin($pin, ['species_cat']);
        expect($pet->plan)->toBe(PetPlan::Challenge)
            ->and($pet->breed_type->value)->toBe('domestic_cat');
        fbcSign($pet)->assertSuccessful();
        expect($pet->fresh()->isPaymentLocked())->toBeTrue();
    });
});

describe('old apps keep M5-F03 exactly', function () {
    it('refuses plan challenge with the mutt without the feature (422)', function () {
        $parent = User::factory()->parent()->create();

        fbcPin($parent, ['plan' => 'challenge', 'breed' => 'mutt', 'origin' => 'adopted', 'age_stage' => 'puppy', 'features' => ['training']])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'challenge_requires_paid_breed');
    });

    it('refuses the challenge without a profile even with the feature (legacy mutt)', function () {
        $parent = User::factory()->parent()->create();

        fbcPin($parent, ['plan' => 'challenge', 'features' => ['free_breed_challenge']])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'challenge_requires_paid_breed');
    });

    it('an old build without a plan still gets a free mutt', function () {
        $parent = User::factory()->parent()->create();
        $pet = fbcLogin(fbcPin($parent, ['breed' => 'mutt', 'origin' => 'adopted', 'age_stage' => 'puppy'])->assertOk()->json('pin'));

        expect($pet->plan)->toBe(PetPlan::Free);
    });

    it('a free-breed challenge created any other way still becomes the free plan (Pet::creating)', function () {
        $parent = User::factory()->parent()->create();
        $child = User::factory()->child()->create(['parent_id' => $parent->id]);
        $pet = Pet::factory()->create(['user_id' => $child->id, 'breed_type' => 'mutt', 'plan' => PetPlan::Challenge, 'challenge_paid_at' => null, 'challenge_paid_source' => null]);

        expect($pet->fresh()->plan)->toBe(PetPlan::Free);
    });
});
