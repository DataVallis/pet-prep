<?php

use App\Enums\ActivityType;
use App\Enums\ChallengePaidSource;
use App\Enums\ChallengeStatus;
use App\Enums\PetLockReason;
use App\Enums\PetPlan;
use App\Enums\PetStatusPeriodKind;
use App\Enums\PushType;
use App\Events\PetUpdated;
use App\Jobs\SendPushNotification;
use App\Models\ActivityLog;
use App\Models\ChallengeCredit;
use App\Models\DevicePushToken;
use App\Models\Pet;
use App\Models\PetStatusPeriod;
use App\Models\PushNotification;
use App\Models\User;
use App\Services\ChallengeCreditService;
use App\Services\ChildProfileService;
use App\Services\FamilyInviteService;
use App\Services\Media\MediaEntitlementService;
use App\Services\Media\PetMediaService;
use App\Services\NotificationService;
use App\Services\Push\ExpoPushClient;
use App\Services\Push\PushCopy;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| M3-11 — plan per pet, payment lock, challenge credits
| (docs/product/PAYMENTS_SPEC.md, David 2026-10-07 P1–P4). M3-13 (David
| 2026-10-08): no free trial — new challenge pets are locked at birth; the
| "trial" tests below cover pets that still run a pre-M3-13 trial.
|--------------------------------------------------------------------------
*/

const CT_NOW = '2026-10-07 10:00:00'; // 12:00 in Ljubljana
const CT_SECRET = 'ct-webhook-secret';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse(CT_NOW, 'UTC'));
    seedBreedConfigs();
    config([
        'services.revenuecat.webhook_secret' => CT_SECRET,
        'services.revenuecat.accept_sandbox' => true,
        'services.revenuecat.challenge_products' => ['petprep_challenge_12w'],
    ]);
    $this->withoutMiddleware([ThrottleRequests::class]);
});

function ctAt(string $utc): void
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
}

/**
 * Parent + child + a pet born now. Default: an unpaid challenge that still
 * runs a pre-M3-13 7-day trial (`legacyTrial`) — the trial-end paths below
 * keep working for those pets. `unpaid` = born without a trial (M3-13).
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: User, 1: User, 2: Pet}
 */
function ctFamily(array $attributes = [], string $state = 'trial'): array
{
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    // M5-F03: a challenge on trial is a paid breed (an unpaid mutt challenge is the free plan).
    $factory = match ($state) {
        'trial' => Pet::factory()->borderCollie()->legacyTrial(),
        'unpaid' => Pet::factory()->borderCollie()->trial(),
        'free' => Pet::factory()->mutt()->freePlan(),
        default => Pet::factory()->mutt(),
    };
    $pet = $factory->create(array_merge(['user_id' => $child->id], $attributes));

    return [$parent, $child, disableHygieneEvents($pet)];
}

/** Another child + unpaid challenge pet (pre-M3-13 trial) in the same family. */
function ctSecondPet(User $parent): Pet
{
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);

    return disableHygieneEvents(Pet::factory()->borderCollie()->legacyTrial()->create(['user_id' => $child->id]));
}

/** Parent + child + an UNBORN unpaid challenge pet (paired, contract not signed). */
function ctUnbornFamily(): array
{
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    $pet = Pet::factory()->borderCollie()->trial()->unborn()->create(['user_id' => $child->id]);

    return [$parent, $child, $pet];
}

function ctSign(User $child): TestResponse
{
    app('auth')->forgetGuards();
    actingAsRole($child);

    return postJson('/api/child/contract', ['signature_format' => 'svg_path', 'signature' => 'M10 10 L20 20']);
}

function ctTick(): void
{
    test()->artisan('pets:process-decay')->assertSuccessful();
}

/**
 * @param  array<string, mixed>  $overrides
 */
function ctPurchase(User $parent, array $overrides = []): TestResponse
{
    app('auth')->forgetGuards();

    return test()->withHeaders(['Authorization' => 'Bearer '.CT_SECRET])->postJson('/api/webhooks/revenuecat', [
        'api_version' => '1.0',
        'event' => array_merge([
            'id' => (string) Str::uuid(),
            'type' => 'NON_RENEWING_PURCHASE',
            'app_user_id' => (string) $parent->id,
            'product_id' => 'petprep_challenge_12w',
            'store' => 'APP_STORE',
            'environment' => 'PRODUCTION',
            'transaction_id' => 'tx-'.Str::random(8),
            'purchased_at_ms' => now()->getTimestampMs(),
            'expiration_at_ms' => null,
        ], $overrides),
    ]);
}

function ctActivate(User $parent, Pet $pet): TestResponse
{
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson("/api/parent/pets/{$pet->id}/challenge/activate");
}

function ctBilling(User $parent): TestResponse
{
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return getJson('/api/parent/billing');
}

// ─────────────────────────────────────────────────────────────────────────
describe('status derivation and the trial clock', function () {
    it('runs trial → payment_required → (tick) locked → paid, one derivation for every payload', function () {
        Event::fake([PetUpdated::class]);
        [$parent, $child, $pet] = ctFamily();

        expect($pet->trial_ends_at->toIso8601String())->toBe('2026-10-14T10:00:00+00:00')
            ->and($pet->challengeStatus())->toBe(ChallengeStatus::Trial);

        ctAt('2026-10-14 09:59:59');
        expect($pet->fresh()->challengeStatus())->toBe(ChallengeStatus::Trial);

        // At the trial end the status flips before any tick: child actions wait already.
        ctAt('2026-10-14 10:00:00');
        $fresh = $pet->fresh();
        expect($fresh->challengeStatus())->toBe(ChallengeStatus::PaymentRequired)
            ->and($fresh->isPaymentLocked())->toBeFalse()
            ->and($fresh->actionLockReason())->toBe(PetLockReason::PaymentRequired);

        ctTick();
        $locked = $pet->fresh();
        expect($locked->payment_locked_at?->toIso8601String())->toBe('2026-10-14T10:00:00+00:00')
            ->and($locked->frozen_at)->not->toBeNull()
            ->and(PetStatusPeriod::where('pet_id', $pet->id)->where('kind', PetStatusPeriodKind::PaymentLock->value)->whereNull('ended_at')->exists())->toBeTrue();
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->petId === $pet->id && $e->eventType === 'payment_required'
            && $e->payload['plan'] === ['type' => 'challenge', 'status' => 'payment_required', 'trial_ends_at' => '2026-10-14T10:00:00+00:00', 'paid_at' => null, 'payments_enforced' => true, 'display_type' => 'challenge']);

        // Child: state shows the lock, actions are 423 payment_required.
        app('auth')->forgetGuards();
        actingAsRole($child);
        getJson('/api/child/pet')->assertOk()
            ->assertJsonPath('lock.reason', 'payment_required')
            ->assertJsonPath('lock.until', null)
            ->assertJsonPath('pet.plan', ['type' => 'challenge', 'status' => 'payment_required', 'trial_ends_at' => '2026-10-14T12:00:00+02:00', 'paid_at' => null, 'payments_enforced' => true, 'display_type' => 'challenge']);
        postJson('/api/child/pet/water')->assertStatus(423)->assertJsonPath('reason', 'payment_required');

        // Parent buys + activates → paid, unlocked, period closed.
        ctAt('2026-10-14 12:00:00');
        ctPurchase($parent)->assertOk()->assertJsonPath('outcome', 'granted'); // auto-assigned: the only unpaid pet
        $paid = $pet->fresh();
        expect($paid->challengeStatus())->toBe(ChallengeStatus::Paid)
            ->and($paid->challenge_paid_source)->toBe(ChallengePaidSource::Purchase)
            ->and($paid->isPaymentLocked())->toBeFalse()
            ->and($paid->frozen_at)->toBeNull()
            ->and(PetStatusPeriod::where('pet_id', $pet->id)->where('kind', 'payment_lock')->sole()->ended_at?->toIso8601String())->toBe('2026-10-14T12:00:00+00:00');
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->petId === $pet->id && $e->eventType === 'challenge_paid');

        app('auth')->forgetGuards();
        actingAsRole($child);
        getJson('/api/child/pet')->assertOk()->assertJsonPath('lock.reason', null)
            ->assertJsonPath('pet.plan.status', 'paid')
            ->assertJsonPath('pet.plan.paid_at', '2026-10-14T14:00:00+02:00');
    });

    it('a pre-M3-13 trial ends 7 family-local days after birth across the DST change', function () {
        // Born 2026-10-20 12:00 CEST; DST ends 2026-10-25 → 12:00 CET = 11:00 UTC.
        ctAt('2026-10-20 10:00:00');
        [, , $pet] = ctFamily();

        expect($pet->trial_ends_at->toIso8601String())->toBe('2026-10-27T11:00:00+00:00')
            ->and($pet->challengeStatus())->toBe(ChallengeStatus::Trial);
    });

    it('never locks a free pet nor a grandfathered one; a free pet has no status', function () {
        [, , $free] = ctFamily([], 'free');
        [, , $old] = ctFamily([], 'grandfathered');

        ctAt('2026-11-07 10:00:00');
        ctTick();

        expect($free->fresh())->challengeStatus()->toBeNull()->payment_locked_at->toBeNull()->trial_ends_at->toBeNull()
            ->and($old->fresh())->challengeStatus()->toBe(ChallengeStatus::Paid)->payment_locked_at->toBeNull();
    });

    it('never locks an unborn challenge pet: payment_required, but the child can still sign (M3-13)', function () {
        [, , $pet] = ctUnbornFamily();

        ctAt('2026-11-07 10:00:00');
        ctTick();

        $fresh = $pet->fresh();
        expect($fresh->payment_locked_at)->toBeNull()
            ->and($fresh->challengeStatus())->toBe(ChallengeStatus::PaymentRequired)
            ->and($fresh->awaitsPayment())->toBeFalse()
            ->and($fresh->actionLockReason())->toBe(PetLockReason::ContractRequired);
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('the payment lock pauses the game like a hard stop', function () {
    it('keeps every value and neglect clock still for hours, then resumes without charging the pause', function () {
        [$parent, , $pet] = ctFamily();

        // Just before the trial ends: hunger already 0 for 30 min, thirst 80.
        ctAt('2026-10-14 09:59:00');
        Pet::whereKey($pet->id)->update([
            'hunger_level' => 0, 'hunger_zero_since' => '2026-10-14 09:29:00',
            'thirst_level' => 80, 'last_decay_at' => '2026-10-14 09:59:00', 'escalation_level' => 0,
        ]);

        ctAt('2026-10-14 10:00:00');
        ctTick(); // lock first, then decay sees the freeze
        $atLock = $pet->fresh();
        expect($atLock->isPaymentLocked())->toBeTrue();
        $thirst = $atLock->thirst_level;

        // Five hours locked, ticked every hour (incl. midnight-free daytime): nothing moves.
        foreach (range(1, 5) as $h) {
            ctAt(Carbon::parse('2026-10-14 10:00:00', 'UTC')->addHours($h)->toDateTimeString());
            ctTick();
            $p = $pet->fresh();
            expect($p->thirst_level)->toBe($thirst)
                ->and($p->hunger_level)->toBe(0.0)
                ->and($p->escalation_level)->toBe(0)
                ->and($p->illness_until)->toBeNull()
                ->and($p->is_game_over)->toBeFalse()
                ->and($p->hunger_zero_since->toDateTimeString())->toBe('2026-10-14 09:29:00');
        }

        // Paid at 15:00 → the neglect clock is shifted by the 5 h pause.
        ctPurchase($parent)->assertJsonPath('outcome', 'granted');
        $resumed = $pet->fresh();
        expect($resumed->hunger_zero_since->toDateTimeString())->toBe('2026-10-14 14:29:00')
            ->and($resumed->last_decay_at->toDateTimeString())->toBe('2026-10-14 15:00:00');

        // One more hour of play decays one hour (the breed's thirst rate per hour), not six.
        ctAt('2026-10-14 16:00:00');
        ctTick();
        $perHour = (float) $pet->fresh()->breedConfig()->thirst_decay_rate;
        expect($pet->fresh()->thirst_level)->toEqualWithDelta($thirst - $perHour, 1e-6)
            // 30 min at 0 before + 1 h after = 1.5 h → phase 3 (> 1 h), not game over.
            ->and($pet->fresh()->is_game_over)->toBeFalse();
    });

    it('excuses the routines of the locked time (status period) and keeps a hard stop on after payment', function () {
        [$parent, , $pet] = ctFamily();
        ctAt('2026-10-14 10:00:00');
        ctTick();

        app('auth')->forgetGuards();
        actingAsRole($parent);
        postJson('/api/parent/hard-stop', ['pet_id' => $pet->id, 'active' => true])->assertOk();

        ctAt('2026-10-14 12:00:00');
        ctPurchase($parent)->assertJsonPath('outcome', 'granted');
        $p = $pet->fresh();
        expect($p->isPaymentLocked())->toBeFalse()
            ->and($p->isFrozen())->toBeTrue() // the hard stop still holds it
            ->and($p->frozen_at)->not->toBeNull()
            ->and(PetStatusPeriod::where('pet_id', $pet->id)->pluck('kind')->map(fn ($k) => $k instanceof PetStatusPeriodKind ? $k->value : $k)->sort()->values()->all())
            ->toBe(['hard_stop', 'payment_lock']);
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('pushes (en / sl, once)', function () {
    it('sends no "trial ends tomorrow" any more (M3-13); parent + child get one lock push when a pre-M3-13 trial ends', function () {
        config(['push.enabled' => true]);
        Queue::fake([SendPushNotification::class]);
        [$parent, $child, $pet] = ctFamily();
        $second = User::factory()->parent()->create();
        app(FamilyInviteService::class)->joinFamily($second, app(FamilyInviteService::class)->createInvite($parent)['code']);

        $billing = fn () => PushNotification::whereIn('type', ['trial_ending', 'payment_required']);

        // Trial day 6 (< 24 h before the end): formerly the reminder — now nothing.
        foreach (['2026-10-13 09:59:00', '2026-10-13 10:00:00', '2026-10-13 10:05:00'] as $at) {
            ctAt($at);
            ctTick();
        }
        expect($billing()->count())->toBe(0)
            ->and($pet->fresh()->trial_reminder_sent_at)->toBeNull();

        ctAt('2026-10-14 10:00:00');
        ctTick();
        ctAt('2026-10-14 11:00:00');
        ctTick();

        $lock = PushNotification::where('type', PushType::PaymentRequired->value)->sole();
        expect(collect($lock->recipients)->pluck('audience')->sort()->values()->all())->toBe(['child', 'parent', 'parent'])
            ->and($lock->status)->toBe(PushNotification::STATUS_QUEUED)
            ->and($lock->metric)->toBeNull() // the pet had a trial: "the free trial has ended" copy
            ->and($billing()->count())->toBe(1);
    });

    it('renders the copy in the device language (en / sl), kind to the child', function () {
        config(['push.enabled' => true]);
        Queue::fake([SendPushNotification::class]);
        [$parent, $child, $pet] = ctFamily();
        $mk = fn (User $u, string $locale) => DevicePushToken::create([
            'user_id' => $u->id, 'expo_push_token' => 'ExponentPushToken['.Str::random(22).']', 'platform' => 'ios',
            'app_version' => '1.0.0', 'locale' => $locale, 'last_seen_at' => now(),
        ]);
        $mk($parent, 'en');
        $mk($child, 'sl');

        $sent = new ArrayObject;
        Http::preventStrayRequests();
        Http::fake(['https://exp.host/--/api/v2/push/send' => function (Request $r) use ($sent) {
            foreach ($r->data() as $m) {
                $sent->append($m);
            }

            return Http::response(['data' => array_map(fn () => ['status' => 'ok', 'id' => Str::random(8)], $r->data())]);
        }]);

        ctAt('2026-10-14 10:00:00');
        ctTick();
        app(NotificationService::class)->deliver(PushNotification::sole()->id, app(ExpoPushClient::class));

        $bodies = collect($sent)->pluck('body')->sort()->values()->all();
        expect($bodies)->toBe([
            'Igra počaka na starša. Tvoj kuža je na varnem in počiva.',
            'The free trial has ended. The dog is waiting safely until you unlock the 12-week challenge in the app.',
        ])
            ->and(collect($sent)->pluck('data')->unique()->values()->all())->toBe([['type' => 'payment_required', 'pet_id' => $pet->id]])
            ->and(collect($sent)->pluck('priority')->unique()->all())->toBe(['default']);

        expect(PushCopy::body(PushType::TrialEnding, null, 'parent', 'en'))->toBe('The free trial ends tomorrow. Unlock the 12-week challenge in the app so the game can go on.')
            ->and(PushCopy::body(PushType::TrialEnding, null, 'parent', 'sl'))->toBe('Preizkus se izteče jutri. Odklenite 12-tedenski izziv v aplikaciji, da se igra nadaljuje.')
            ->and(PushCopy::body(PushType::PaymentRequired, null, 'child', 'en'))->toBe('The game is waiting for your parent. Your dog is safe and resting.');
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('credits: purchase, auto-assign, activate', function () {
    it('auto-assigns a purchase only when the family has exactly one unpaid challenge pet', function () {
        [$parent, , $a] = ctFamily();
        $b = ctSecondPet($parent);
        ctFamily([], 'free'); // other family, irrelevant

        ctPurchase($parent)->assertJsonPath('outcome', 'granted');
        $credit = ChallengeCredit::sole();
        expect($credit->assigned_at)->toBeNull()->and($credit->pet_id)->toBeNull()
            ->and($a->fresh()->challengeStatus())->toBe(ChallengeStatus::Trial)
            ->and($b->fresh()->challengeStatus())->toBe(ChallengeStatus::Trial);

        ctBilling($parent)->assertOk()->assertExactJson([
            'credits_available' => 1,
            'payments_enforced' => true,
            'pets' => [
                ['pet_id' => $a->id, 'plan' => 'challenge', 'status' => 'trial', 'trial_ends_at' => '2026-10-14T12:00:00+02:00', 'paid_at' => null, 'trial_available' => true, 'deletion_loses_purchase' => false],
                ['pet_id' => $b->id, 'plan' => 'challenge', 'status' => 'trial', 'trial_ends_at' => '2026-10-14T12:00:00+02:00', 'paid_at' => null, 'trial_available' => true, 'deletion_loses_purchase' => false],
            ],
        ]);

        // Pet A paid by the parent → the next purchase auto-assigns to B.
        ctActivate($parent, $a)->assertOk()->assertExactJson([
            'status' => 'activated', 'pet_id' => $a->id, 'credits_available' => 0,
            'plan' => ['type' => 'challenge', 'status' => 'paid', 'trial_ends_at' => '2026-10-14T12:00:00+02:00', 'paid_at' => '2026-10-07T12:00:00+02:00', 'payments_enforced' => true, 'display_type' => 'challenge'],
        ]);
        expect($credit->fresh())->pet_id->toBe($a->id)->assigned_via->toBe('parent');

        ctPurchase($parent)->assertJsonPath('outcome', 'granted');
        expect(ChallengeCredit::where('pet_id', $b->id)->sole()->assigned_via)->toBe('webhook')
            ->and($b->fresh()->challengeStatus())->toBe(ChallengeStatus::Paid);
    });

    it('activate is idempotent, uses the oldest credit, and answers no_credit / free_plan / already_paid', function () {
        [$parent, , $a] = ctFamily();
        $b = ctSecondPet($parent);
        ctPurchase($parent, ['purchased_at_ms' => now()->subHour()->getTimestampMs()]);
        ctPurchase($parent);
        $oldest = ChallengeCredit::orderBy('purchased_at')->first();

        ctActivate($parent, $a)->assertOk()->assertJsonPath('status', 'activated')->assertJsonPath('credits_available', 1);
        expect($oldest->fresh()->pet_id)->toBe($a->id);
        ctActivate($parent, $a)->assertOk()->assertJsonPath('status', 'already_active')->assertJsonPath('credits_available', 1);
        expect(ChallengeCredit::whereNotNull('pet_id')->count())->toBe(1);

        ctActivate($parent, $b)->assertOk()->assertJsonPath('status', 'activated')->assertJsonPath('credits_available', 0);
        $c = ctSecondPet($parent);
        ctActivate($parent, $c)->assertStatus(409)->assertJsonPath('reason', 'no_credit');

        $free = disableHygieneEvents(Pet::factory()->freePlan()->create(['user_id' => User::factory()->child()->create(['parent_id' => $parent->id])->id]));
        ctActivate($parent, $free)->assertStatus(422)->assertJsonPath('reason', 'free_plan');
        $old = disableHygieneEvents(Pet::factory()->create(['user_id' => User::factory()->child()->create(['parent_id' => $parent->id])->id]));
        ctActivate($parent, $old)->assertStatus(422)->assertJsonPath('reason', 'already_paid');
    });

    it('keeps credits and pets inside the family: other family 404, child 403, guest 401', function () {
        [$parent, $child, $pet] = ctFamily();
        [$stranger] = ctFamily();
        ctPurchase($stranger); // the stranger's credit is auto-assigned to the stranger's own pet

        ctActivate($stranger, $pet)->assertNotFound()->assertJsonPath('reason', 'pet_not_found');
        ctActivate($parent, $pet)->assertStatus(409)->assertJsonPath('reason', 'no_credit');
        ctBilling($parent)->assertOk()->assertJsonPath('credits_available', 0)->assertJsonCount(1, 'pets');

        app('auth')->forgetGuards();
        actingAsRole($child);
        postJson("/api/parent/pets/{$pet->id}/challenge/activate")->assertForbidden();
        getJson('/api/parent/billing')->assertForbidden();

        app('auth')->forgetGuards();
        test()->withHeaders(['Authorization' => ''])->getJson('/api/parent/billing')->assertUnauthorized();
        expect($pet->fresh()->challengeStatus())->toBe(ChallengeStatus::Trial);
    });

    it('a second parent can use a credit the other parent bought', function () {
        [$parent, , $pet] = ctFamily();
        ctSecondPet($parent); // two unpaid pets → no auto-assign
        $second = User::factory()->parent()->create();
        $invites = app(FamilyInviteService::class);
        $invites->joinFamily($second, $invites->createInvite($parent)['code']);

        ctPurchase($parent);
        ctActivate($second, $pet)->assertOk()->assertJsonPath('status', 'activated');
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('refunds', function () {
    it('puts a refunded pet back into its trial, or locks it at once after the trial', function () {
        config(['push.enabled' => true]);
        Queue::fake([SendPushNotification::class]);
        [$parent, , $pet] = ctFamily();
        ctPurchase($parent, ['transaction_id' => 'tx-1']);
        expect($pet->fresh()->challengeStatus())->toBe(ChallengeStatus::Paid);

        // Refund inside the 7 days → trial again, no lock.
        ctPurchase($parent, ['type' => 'CANCELLATION', 'transaction_id' => 'tx-1', 'cancel_reason' => 'CUSTOMER_SUPPORT'])
            ->assertJsonPath('outcome', 'revoked');
        expect($pet->fresh())->challengeStatus()->toBe(ChallengeStatus::Trial)->payment_locked_at->toBeNull()
            ->and(ChallengeCredit::sole())->revoke_reason->value->toBe('refund')->pet_id->toBe($pet->id);

        // Bought again, refunded after the trial → locked immediately (+ lock push).
        ctPurchase($parent, ['transaction_id' => 'tx-2']);
        ctAt('2026-10-20 10:00:00');
        ctPurchase($parent, ['type' => 'CANCELLATION', 'transaction_id' => 'tx-2'])->assertJsonPath('outcome', 'revoked');
        expect($pet->fresh())->challengeStatus()->toBe(ChallengeStatus::PaymentRequired)
            ->and($pet->fresh()->isPaymentLocked())->toBeTrue()
            ->and(PushNotification::where('type', 'payment_required')->count())->toBe(1);

        // An unknown transaction revokes nothing.
        ctPurchase($parent, ['type' => 'CANCELLATION', 'transaction_id' => 'tx-unknown'])->assertJsonPath('outcome', 'ignored');
    });

    it('keeps a finished challenge paid; a refund of an unused credit lowers credits_available', function () {
        [$parent, , $pet] = ctFamily();
        ctPurchase($parent, ['transaction_id' => 'tx-a']);
        ctAt('2027-01-05 10:00:00'); // 13 weeks after birth
        ctPurchase($parent, ['type' => 'CANCELLATION', 'transaction_id' => 'tx-a'])->assertJsonPath('outcome', 'revoked');
        expect($pet->fresh()->challengeStatus())->toBe(ChallengeStatus::Paid);

        ctPurchase($parent, ['transaction_id' => 'tx-b']);
        ctBilling($parent)->assertJsonPath('credits_available', 1);
        ctPurchase($parent, ['type' => 'CANCELLATION', 'transaction_id' => 'tx-b'])->assertJsonPath('outcome', 'revoked');
        ctBilling($parent)->assertJsonPath('credits_available', 0);
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('generate-pin plan', function () {
    beforeEach(function () {
        seedLifeStageData();
    });

    function ctPin(User $parent, array $body): TestResponse
    {
        $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
        app('auth')->forgetGuards();
        actingAsRole($parent);

        return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
    }

    function ctPinLogin(string $pin): TestResponse
    {
        app('auth')->forgetGuards();
        test()->withHeaders(['Authorization' => '']);

        return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet']);
    }

    it('creates a free mutt from plan free, without a trial', function () {
        $parent = User::factory()->parent()->create();
        $pin = ctPin($parent, ['plan' => 'free', 'breed' => 'mutt', 'origin' => 'bought', 'age_stage' => 'puppy'])
            ->assertOk()->assertJsonPath('plan', 'free')->json('pin');
        $pet = Pet::findOrFail(ctPinLogin($pin)->assertSuccessful()->json('pet.id'));

        expect($pet->plan)->toBe(PetPlan::Free)->and($pet->trial_ends_at)->toBeNull()->and($pet->challengeStatus())->toBeNull();
    });

    it('refuses a premium breed on the free plan; allows it on the challenge (default for old builds)', function () {
        $parent = User::factory()->parent()->create();
        ctPin($parent, ['plan' => 'free', 'breed' => 'border_collie', 'origin' => 'bought', 'age_stage' => 'puppy'])
            ->assertStatus(422)->assertJsonPath('reason', 'breed_locked');

        $pin = ctPin($parent, ['breed' => 'border_collie', 'origin' => 'bought', 'age_stage' => 'puppy'])
            ->assertOk()->assertJsonPath('plan', 'challenge')->json('pin');
        $pet = Pet::findOrFail(ctPinLogin($pin)->assertSuccessful()->json('pet.id'));
        // M3-13: no trial — unpaid from creation on (unborn until the contract).
        expect($pet->plan)->toBe(PetPlan::Challenge)->and($pet->breed_type->value)->toBe('border_collie')
            ->and($pet->challengeStatus())->toBe(ChallengeStatus::PaymentRequired)
            ->and($pet->trial_ends_at)->toBeNull();

        ctPin($parent, ['plan' => 'gold'])->assertUnprocessable()->assertJsonValidationErrors('plan');
    });

    it('a PIN without plan (old build) makes a challenge pet for a paid breed (the mutt is free — M5-F03); joining never changes the plan', function () {
        $parent = User::factory()->parent()->create();
        $pin = ctPin($parent, ['breed' => 'border_collie', 'origin' => 'bought', 'age_stage' => 'puppy'])->assertOk()->assertJsonPath('plan', 'challenge')->json('pin');
        $pet = Pet::findOrFail(ctPinLogin($pin)->assertSuccessful()->json('pet.id'));
        expect($pet->plan)->toBe(PetPlan::Challenge);

        ctPin($parent, ['pet_id' => $pet->id, 'plan' => 'free'])->assertOk()->assertJsonPath('plan', null)->assertJsonPath('mode', 'join_pet');
        expect($pet->fresh()->plan)->toBe(PetPlan::Challenge);
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('free plan rules', function () {
    it('limits the parent history of a free pet to 7 days (activities, dashboard, report)', function () {
        [$parent, $child, $free] = ctFamily([], 'free');
        $log = fn (Pet $p, string $at) => ActivityLog::withoutEvents(fn () => (new ActivityLog)->forceFill([
            'pet_id' => $p->id, 'actor_user_id' => $child->id, 'activity_type' => ActivityType::FedPet, 'value' => 100,
            'created_at' => Carbon::parse($at, 'UTC'),
        ])->save());
        $log($free, '2026-09-27 10:00:00'); // 10 days ago
        $log($free, '2026-10-05 10:00:00'); // 2 days ago

        app('auth')->forgetGuards();
        actingAsRole($parent);
        getJson("/api/parent/activities?pet_id={$free->id}")->assertOk()->assertJsonPath('meta.total', 1);
        getJson('/api/parent/dashboard')->assertOk()
            ->assertJsonCount(1, 'recent_activities')
            ->assertJsonCount(1, 'family.pets.0.timeline')
            ->assertJsonPath('family.pets.0.plan', ['type' => 'free', 'status' => null, 'trial_ends_at' => null, 'paid_at' => null, 'payments_enforced' => true, 'display_type' => 'free'])
            ->assertJsonPath('pet.plan.type', 'free')
            // No 12-week program on the free plan.
            ->assertJsonPath('family.children.0.progress', null);
        getJson("/api/parent/children/{$child->id}/report?days=30")->assertOk()
            ->assertJsonPath('days', 7)->assertJsonPath('history_limited', true);

        // A challenge pet keeps its whole history.
        [$p2, $c2, $paid] = ctFamily([], 'grandfathered');
        $log($paid, '2026-09-27 10:00:00');
        $log($paid, '2026-10-05 10:00:00');
        app('auth')->forgetGuards();
        actingAsRole($p2);
        getJson("/api/parent/activities?pet_id={$paid->id}")->assertOk()->assertJsonPath('meta.total', 2);
        getJson("/api/parent/children/{$c2->id}/report?days=30")->assertOk()->assertJsonPath('days', 30)->assertJsonPath('history_limited', false);
    });

    it('never completes the 12-week challenge on the free plan', function () {
        [, , $free] = ctFamily([], 'free');
        [, , $paid] = ctFamily([], 'grandfathered');

        ctAt('2027-01-07 10:00:00'); // 13 weeks
        ctTick();

        expect($free->fresh()->hasReachedSimulationEnd())->toBeFalse()
            ->and($free->fresh()->certificate_eligible)->toBeFalse()
            ->and($paid->fresh()->hasReachedSimulationEnd())->toBeTrue();
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('migration backfill', function () {
    it('grandfathers every existing pet as a paid challenge', function () {
        [, , $born] = ctFamily([], 'grandfathered');
        [, , $unborn] = ctFamily(['born_at' => null, 'last_decay_at' => null, 'last_step_reset_at' => null], 'grandfathered');
        $migration = require database_path('migrations/2026_10_20_120000_add_challenge_plan_and_trial.php');

        $migration->down();
        $migration->up();

        $b = DB::table('pets')->where('id', $born->id)->first();
        $u = DB::table('pets')->where('id', $unborn->id)->first();
        expect($b->plan)->toBe('challenge')
            ->and($b->challenge_paid_source)->toBe('grandfathered')
            ->and(Carbon::parse($b->challenge_paid_at)->toDateTimeString())->toBe(Carbon::parse($b->born_at)->toDateTimeString())
            ->and(Carbon::parse($b->trial_ends_at)->toDateTimeString())->toBe(Carbon::parse($b->born_at)->addDays(7)->toDateTimeString())
            ->and($b->payment_locked_at)->toBeNull()
            ->and($u->challenge_paid_source)->toBe('grandfathered')
            ->and($u->trial_ends_at)->toBeNull();

        ctAt('2026-12-07 10:00:00');
        ctTick();
        expect(Pet::find($born->id)->isPaymentLocked())->toBeFalse();
    });

    it('enforces the plan / payment constraints in the database', function () {
        [, , $free] = ctFamily([], 'free');

        // Savepoints: a failed statement must not abort the test transaction.
        expect(fn () => DB::transaction(fn () => DB::table('pets')->where('id', $free->id)->update(['challenge_paid_at' => now(), 'challenge_paid_source' => 'purchase'])))
            ->toThrow(QueryException::class);
        expect(fn () => DB::transaction(fn () => DB::table('pets')->where('id', $free->id)->update(['plan' => 'gold'])))
            ->toThrow(QueryException::class);
        expect(fn () => DB::transaction(fn () => DB::table('pets')->where('id', $free->id)->update(['challenge_paid_at' => now()])))
            ->toThrow(QueryException::class);
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('P5 — deleting a pet with a paid, unfinished challenge', function () {
    function ctDeleteChild(User $parent, User $child, array $extra = []): TestResponse
    {
        app('auth')->forgetGuards();
        actingAsRole($parent);

        return test()->deleteJson("/api/parent/children/{$child->id}", array_merge(['password' => 'password', 'confirm' => true], $extra));
    }

    it('asks for acknowledge_paid_challenge before a child deletion takes a purchased pet; the purchase stays used', function () {
        [$parent, $child, $pet] = ctFamily();
        ctPurchase($parent)->assertJsonPath('outcome', 'granted'); // auto-assigned
        ctBilling($parent)->assertJsonPath('pets.0.deletion_loses_purchase', true);

        ctDeleteChild($parent, $child)->assertStatus(422)->assertExactJson([
            'message' => 'This deletes a dog whose paid 12-week challenge is not finished. The purchase stays used. Send acknowledge_paid_challenge: true to continue.',
            'reason' => 'paid_challenge_ack_required',
            'pets' => [['pet_id' => $pet->id, 'breed_type' => 'border_collie', 'name' => null]],
        ]);
        expect(Pet::find($pet->id))->not->toBeNull()->and(User::find($child->id))->not->toBeNull();

        ctDeleteChild($parent, $child, ['acknowledge_paid_challenge' => true])->assertOk()->assertJsonPath('pets_deleted', 1);
        $credit = ChallengeCredit::sole();
        expect(Pet::find($pet->id))->toBeNull()
            ->and($credit->pet_id)->toBeNull()
            ->and($credit->assigned_at)->not->toBeNull()
            ->and($credit->revoked_at)->toBeNull();
        ctBilling($parent)->assertJsonPath('credits_available', 0);
    });

    it('needs no acknowledgement for trial, grandfathered, finished or game-over pets', function () {
        [$p1, $c1] = ctFamily();
        ctDeleteChild($p1, $c1)->assertOk();
        [$p2, $c2] = ctFamily([], 'grandfathered');
        ctDeleteChild($p2, $c2)->assertOk();
        [$p3, $c3, $over] = ctFamily();
        ctPurchase($p3);
        Pet::whereKey($over->id)->update(['is_game_over' => true, 'is_active' => false]);
        ctDeleteChild($p3, $c3)->assertOk();
        [$p4, $c4] = ctFamily();
        ctPurchase($p4);
        ctAt('2027-01-07 10:00:00'); // 13 weeks
        ctDeleteChild($p4, $c4)->assertOk();
    });

    it('asks the last parent too; a parent who leaves the family does not', function () {
        [$parent, , $pet] = ctFamily();
        ctPurchase($parent);
        $second = User::factory()->parent()->create();
        $invites = app(FamilyInviteService::class);
        $invites->joinFamily($second, $invites->createInvite($parent)['code']);

        app('auth')->forgetGuards();
        actingAsRole($second);
        postJson('/api/parent/account/delete', ['password' => 'password', 'confirm' => true])->assertOk()->assertJsonPath('scope', 'parent');

        app('auth')->forgetGuards();
        actingAsRole($parent);
        postJson('/api/parent/account/delete', ['password' => 'password', 'confirm' => true])
            ->assertStatus(422)->assertJsonPath('reason', 'paid_challenge_ack_required')->assertJsonPath('pets.0.pet_id', $pet->id);
        postJson('/api/parent/account/delete', ['password' => 'password', 'confirm' => true, 'acknowledge_paid_challenge' => true])
            ->assertOk()->assertJsonPath('family_deleted', true);
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('P6 — full AI media only for a purchased challenge', function () {
    it('gives trial, grandfathered and free pets the basic tier, a purchased one the full tier', function () {
        $tier = fn (Pet $p) => app(MediaEntitlementService::class)->tierFor($p->fresh());
        [, , $trial] = ctFamily();
        [, , $old] = ctFamily([], 'grandfathered');
        [, , $free] = ctFamily([], 'free');
        [$parent, , $bought] = ctFamily();
        ctPurchase($parent);

        expect($tier($trial))->toBe('basic')
            ->and($tier($old))->toBe('basic')
            ->and($tier($free))->toBe('basic')
            ->and($tier($bought))->toBe('full');
    });

    it('queues the missing full-set videos once the purchase is assigned (after commit)', function () {
        [$parent, , $pet] = ctFamily();
        $this->partialMock(PetMediaService::class, function ($mock) use ($pet) {
            $mock->shouldReceive('queueStateVideos')->once()->withArgs(fn (Pet $p) => $p->id === $pet->id)->andReturn(4);
        });

        ctPurchase($parent)->assertJsonPath('outcome', 'granted');
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('M3-13 — no free trial: the challenge starts with a purchase (David 2026-10-08)', function () {
    it('locks a new challenge pet at birth: payment_required, lock period from birth, one broadcast, one push', function () {
        config(['push.enabled' => true]);
        Queue::fake([SendPushNotification::class]);
        Event::fake([PetUpdated::class]);
        [$parent, $child, $pet] = ctUnbornFamily();

        ctAt('2026-10-08 10:00:00');
        ctSign($child)->assertCreated()
            ->assertJsonPath('state.lock.reason', 'payment_required')
            ->assertJsonPath('state.pet.plan.status', 'payment_required')
            ->assertJsonPath('state.pet.plan.trial_ends_at', '2026-10-08T12:00:00+02:00');

        $born = $pet->fresh();
        expect($born->born_at->toIso8601String())->toBe('2026-10-08T10:00:00+00:00')
            ->and($born->trial_ends_at->toIso8601String())->toBe('2026-10-08T10:00:00+00:00')
            ->and($born->isPaymentLocked())->toBeTrue()
            ->and($born->payment_locked_at->toIso8601String())->toBe('2026-10-08T10:00:00+00:00')
            ->and($born->frozen_at)->not->toBeNull()
            ->and(PetStatusPeriod::where('pet_id', $pet->id)->where('kind', 'payment_lock')->sole()->started_at->toIso8601String())->toBe('2026-10-08T10:00:00+00:00');
        // One state change → one broadcast (the contract's), carrying the locked plan.
        Event::assertDispatchedTimes(PetUpdated::class, 1);
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->petId === $pet->id && $e->eventType === 'signed_contract'
            && $e->payload['plan']['status'] === 'payment_required');

        // Parents get the copy without "trial"; the child the kind copy. No trial reminder.
        $push = PushNotification::sole();
        expect($push->type)->toBe(PushType::PaymentRequired)
            ->and($push->metric)->toBe('no_trial')
            ->and(collect($push->recipients)->pluck('audience')->sort()->values()->all())->toBe(['child', 'parent'])
            ->and(PushCopy::body(PushType::PaymentRequired, 'no_trial', 'parent', 'en'))->toBe('The dog is waiting safely until you unlock the 12-week challenge in the app.')
            ->and(PushCopy::body(PushType::PaymentRequired, 'no_trial', 'parent', 'sl'))->toBe('Kuža varno čaka, dokler v aplikaciji ne odklenete 12-tedenskega izziva.')
            ->and(PushCopy::body(PushType::PaymentRequired, 'no_trial', 'child', 'sl'))->toBe('Igra počaka na starša. Tvoj kuža je na varnem in počiva.');

        // The child waits; the tick neither re-locks nor pushes again; the dog does not age.
        postJson('/api/child/pet/water')->assertStatus(423)->assertJsonPath('reason', 'payment_required');
        ctAt('2026-10-11 10:00:00');
        ctTick();
        $later = $pet->fresh();
        expect(PushNotification::count())->toBe(1)
            ->and($later->programSecondsAt(now()))->toBe(0)
            ->and($later->hunger_level)->toBe(100.0);
        ctBilling($parent)->assertJsonPath('pets.0.status', 'payment_required')->assertJsonPath('pets.0.trial_available', null);
    });

    it('starts the 12-week clock at the purchase, not at the birth', function () {
        [$parent, $child, $pet] = ctUnbornFamily();
        ctAt('2026-10-08 10:00:00');
        ctSign($child)->assertCreated();

        // Three days locked, then the parent buys (auto-assigned: the only unpaid pet).
        ctAt('2026-10-11 10:00:00');
        ctPurchase($parent)->assertOk()->assertJsonPath('outcome', 'granted');
        $paid = $pet->fresh();
        expect($paid->challengeStatus())->toBe(ChallengeStatus::Paid)
            ->and($paid->isPaymentLocked())->toBeFalse()
            ->and($paid->frozen_at)->toBeNull()
            ->and($paid->last_decay_at->toIso8601String())->toBe('2026-10-11T10:00:00+00:00')
            ->and(PetStatusPeriod::where('pet_id', $pet->id)->where('kind', 'payment_lock')->sole()->ended_at->toIso8601String())->toBe('2026-10-11T10:00:00+00:00')
            ->and($paid->programSecondsAt(now()))->toBe(0)
            ->and($paid->programBirthAt(now())->toIso8601String())->toBe('2026-10-11T10:00:00+00:00');

        // One sim week after the purchase = one dog month; the 3 locked days never count.
        ctAt('2026-10-18 10:00:00');
        expect($pet->fresh()->programSecondsAt(now()))->toBe(7 * 86400)
            ->and($pet->fresh()->virtualAgeInMonths())->toBe(1);

        app('auth')->forgetGuards();
        actingAsRole($child);
        getJson('/api/child/pet')->assertOk()->assertJsonPath('lock.reason', null)->assertJsonPath('pet.plan.status', 'paid');
    });

    it('lets the parent buy before birth (paywall activate on the unborn pet); the dog is born unlocked', function () {
        config(['push.enabled' => true]);
        Queue::fake([SendPushNotification::class]);
        [$parent, $child, $pet] = ctUnbornFamily();
        ctFamily([], 'unpaid'); // another family: irrelevant
        $other = ctSecondPet($parent); // a second unpaid pet → the webhook does not auto-assign

        ctPurchase($parent)->assertJsonPath('outcome', 'granted');
        expect(ChallengeCredit::sole()->pet_id)->toBeNull();
        ctBilling($parent)->assertJsonPath('pets.0.pet_id', $pet->id)->assertJsonPath('pets.0.status', 'payment_required');

        ctActivate($parent, $pet)->assertOk()->assertJsonPath('status', 'activated')->assertJsonPath('plan.status', 'paid');

        ctSign($child)->assertCreated()->assertJsonPath('state.lock.reason', null)->assertJsonPath('state.pet.plan.status', 'paid');
        expect($pet->fresh()->isPaymentLocked())->toBeFalse()
            ->and(PushNotification::count())->toBe(0)
            ->and($other->fresh()->challengeStatus())->toBe(ChallengeStatus::Trial);
    });

    it('a refund before birth never locks the unborn pet: the child signs, the lock comes at birth (QA PR #83 M1)', function () {
        Event::fake([PetUpdated::class]);
        [$parent, $child, $pet] = ctUnbornFamily();
        ctPurchase($parent, ['transaction_id' => 'tx-u'])->assertJsonPath('outcome', 'granted'); // auto-assigned to the unborn pet
        expect($pet->fresh()->challengeStatus())->toBe(ChallengeStatus::Paid);

        ctPurchase($parent, ['type' => 'CANCELLATION', 'transaction_id' => 'tx-u'])->assertJsonPath('outcome', 'revoked');
        $refunded = $pet->fresh();
        expect($refunded->isPaymentLocked())->toBeFalse()
            ->and($refunded->challengeStatus())->toBe(ChallengeStatus::PaymentRequired)
            ->and($refunded->actionLockReason())->toBe(PetLockReason::ContractRequired)
            ->and(PetStatusPeriod::where('pet_id', $pet->id)->exists())->toBeFalse();
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->petId === $pet->id && $e->eventType === 'challenge_refunded');

        ctAt('2026-10-08 10:00:00');
        ctSign($child)->assertCreated()->assertJsonPath('state.lock.reason', 'payment_required');
        expect($pet->fresh()->isPaymentLocked())->toBeTrue()
            ->and($pet->fresh()->payment_locked_at->toIso8601String())->toBe('2026-10-08T10:00:00+00:00');
    });

    it('assigns no held credit when the contract will not proceed (hard stop) (QA PR #83 m2)', function () {
        $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
        ctPurchase($parent);
        $child = User::factory()->child()->create(['parent_id' => $parent->id]);
        $pet = Pet::factory()->borderCollie()->trial()->unborn()->create(['user_id' => $child->id]);
        Pet::whereKey($pet->id)->update(['is_hard_stopped' => true]);

        ctSign($child)->assertStatus(423)->assertJsonPath('reason', 'hard_stopped');
        expect(ChallengeCredit::sole()->pet_id)->toBeNull()
            ->and($pet->fresh()->challenge_paid_at)->toBeNull();

        Pet::whereKey($pet->id)->update(['is_hard_stopped' => false]);
        ctSign($child)->assertCreated()->assertJsonPath('state.pet.plan.status', 'paid');
        expect(ChallengeCredit::sole()->assigned_via)->toBe('birth');
    });

    it('auto-assigns a purchase to an unborn pet that is the only unpaid one (bought before the contract)', function () {
        [$parent, $child, $pet] = ctUnbornFamily();
        ctPurchase($parent)->assertJsonPath('outcome', 'granted');
        expect(ChallengeCredit::sole())->pet_id->toBe($pet->id)->assigned_via->toBe('webhook');

        ctSign($child)->assertCreated()->assertJsonPath('state.pet.plan.status', 'paid');
        expect($pet->fresh()->isPaymentLocked())->toBeFalse();
    });

    it('assigns a credit the family already holds at birth when the pet is the only unpaid one', function () {
        $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
        // Bought while the family had no unpaid challenge pet → unassigned.
        ctPurchase($parent)->assertJsonPath('outcome', 'granted');
        expect(ChallengeCredit::sole()->pet_id)->toBeNull();

        $child = User::factory()->child()->create(['parent_id' => $parent->id]);
        $pet = Pet::factory()->borderCollie()->trial()->unborn()->create(['user_id' => $child->id]);

        Event::fake([PetUpdated::class]);
        ctSign($child)->assertCreated()->assertJsonPath('state.lock.reason', null)->assertJsonPath('state.pet.plan.status', 'paid');

        $credit = ChallengeCredit::sole();
        expect($credit->pet_id)->toBe($pet->id)
            ->and($credit->assigned_via)->toBe('birth')
            ->and($pet->fresh()->challenge_paid_source)->toBe(ChallengePaidSource::Purchase)
            ->and($pet->fresh()->isPaymentLocked())->toBeFalse()
            ->and(PetStatusPeriod::where('pet_id', $pet->id)->where('kind', 'payment_lock')->exists())->toBeFalse();
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->eventType === 'challenge_paid');
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->eventType === 'signed_contract');
    });

    it('does not pick a pet for a held credit at birth when several pets are unpaid (the parent chooses)', function () {
        $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
        ctPurchase($parent);
        $child = User::factory()->child()->create(['parent_id' => $parent->id]);
        $pet = Pet::factory()->borderCollie()->trial()->unborn()->create(['user_id' => $child->id]);
        ctSecondPet($parent);

        ctSign($child)->assertCreated()->assertJsonPath('state.lock.reason', 'payment_required');
        expect(ChallengeCredit::sole()->pet_id)->toBeNull()
            ->and($pet->fresh()->isPaymentLocked())->toBeTrue();

        ctActivate($parent, $pet)->assertOk()->assertJsonPath('status', 'activated');
        expect($pet->fresh()->isPaymentLocked())->toBeFalse();
    });

    it('with payments not enforced a new challenge pet is born playable: no lock, no push, status trial', function () {
        config(['payments.enforced' => false, 'push.enabled' => true]);
        Queue::fake([SendPushNotification::class]);
        [$parent, $child, $pet] = ctUnbornFamily();
        expect($pet->challengeStatus())->toBe(ChallengeStatus::Trial);

        ctAt('2026-10-08 10:00:00');
        ctSign($child)->assertCreated()->assertJsonPath('state.lock.reason', null)
            ->assertJsonPath('state.pet.plan.status', 'trial')
            ->assertJsonPath('state.pet.plan.payments_enforced', false);

        ctAt('2026-10-15 10:00:00');
        // A week of play (cared for: needs topped up before the tick).
        Pet::whereKey($pet->id)->update(['hunger_level' => 100, 'thirst_level' => 100, 'last_decay_at' => now()]);
        ctTick();
        $fresh = $pet->fresh();
        expect($fresh->isPaymentLocked())->toBeFalse()
            ->and(PushNotification::whereIn('type', ['trial_ending', 'payment_required'])->count())->toBe(0)
            ->and($fresh->programSecondsAt(now()))->toBe(7 * 86400);

        // Turning enforcement on later locks it from that tick on — the days it
        // played stay program time (no retroactive lock back to the birth).
        config(['payments.enforced' => true]);
        ctAt('2026-10-15 10:01:00');
        ctTick();
        $locked = $pet->fresh();
        expect($locked->isPaymentLocked())->toBeTrue()
            ->and($locked->payment_locked_at->toIso8601String())->toBe('2026-10-15T10:01:00+00:00')
            ->and($locked->programSecondsAt(now()))->toBe(7 * 86400 + 60);
    });

    it('keeps a running pre-M3-13 trial until its end: playable, no lock, no push', function () {
        config(['push.enabled' => true]);
        Queue::fake([SendPushNotification::class]);
        [, $child, $pet] = ctFamily(); // born 2026-10-07 10:00 with a 7-day trial

        // Day 7, one hour before the end (cared for: needs topped up before the tick).
        ctAt('2026-10-14 09:00:00');
        Pet::whereKey($pet->id)->update(['hunger_level' => 100, 'thirst_level' => 100, 'last_decay_at' => now()]);
        ctTick();
        expect($pet->fresh()->challengeStatus())->toBe(ChallengeStatus::Trial)
            ->and($pet->fresh()->isPaymentLocked())->toBeFalse()
            ->and(PushNotification::whereIn('type', ['trial_ending', 'payment_required'])->count())->toBe(0);
        app('auth')->forgetGuards();
        actingAsRole($child);
        getJson('/api/child/pet')->assertOk()->assertJsonPath('lock.reason', null)->assertJsonPath('pet.plan.status', 'trial');

        ctAt('2026-10-14 10:00:00');
        ctTick();
        expect($pet->fresh()->isPaymentLocked())->toBeTrue()
            ->and($pet->fresh()->payment_locked_at->toIso8601String())->toBe('2026-10-14T10:00:00+00:00');
    });

    it('a game-over pet is not unlocked by a purchase; the next challenge pet also starts with a purchase', function () {
        [$parent, $child, $first] = ctFamily();
        Pet::whereKey($first->id)->update(['is_game_over' => true, 'is_active' => false]);

        ctPurchase($parent);
        ctActivate($parent, $first)->assertStatus(422)->assertJsonPath('reason', 'pet_not_active');

        // The next pet: the waiting credit pays it at birth (only unpaid pet).
        $second = Pet::factory()->borderCollie()->trial()->unborn()->create(['user_id' => $child->id]);
        ctBilling($parent)->assertJsonPath('pets.0.pet_id', $second->id)->assertJsonPath('pets.0.trial_available', null);
        ctSign($child)->assertCreated()->assertJsonPath('state.pet.plan.status', 'paid');
    });

    it('answers trial_available null at PIN time (deprecated; false would show old builds a "no free trial" note)', function () {
        seedLifeStageData();
        $parent = User::factory()->parent()->create();
        $fresh = app(ChildProfileService::class)->createChild($parent, 'Nova', null);
        app('auth')->forgetGuards();
        actingAsRole($parent);

        postJson('/api/parent/generate-pin', ['child_id' => $fresh->id, 'breed' => 'border_collie', 'origin' => 'bought', 'age_stage' => 'puppy'])->assertOk()
            ->assertJsonPath('plan', 'challenge')->assertJsonPath('trial_available', null);
        postJson('/api/parent/generate-pin', ['child_id' => $fresh->id, 'plan' => 'free'])->assertOk()
            ->assertJsonPath('plan', 'free')->assertJsonPath('trial_available', null);
    });

    it('locks an admin-created born challenge pet at the next tick, from that tick', function () {
        [, , $pet] = ctFamily([], 'unpaid'); // born at creation, no trial
        expect($pet->trial_ends_at->equalTo($pet->born_at))->toBeTrue()
            ->and($pet->challengeStatus())->toBe(ChallengeStatus::PaymentRequired)
            ->and($pet->actionLockReason())->toBe(PetLockReason::PaymentRequired);

        ctAt('2026-10-07 10:01:00');
        ctTick();
        expect($pet->fresh()->payment_locked_at->toIso8601String())->toBe('2026-10-07T10:01:00+00:00');
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('kill switch, admin unlock, no growth while locked (QA PR #67)', function () {
    it('with payments not enforced an expired trial keeps playing: no lock, no push, status trial', function () {
        config(['payments.enforced' => false]);
        Queue::fake();
        [$parent, , $pet] = ctFamily();

        ctAt('2026-10-14 10:00:00'); // trial ended (factory: born 2026-10-07 10:00)
        ctTick();
        $fresh = $pet->fresh();

        expect($fresh->isPaymentLocked())->toBeFalse()
            ->and($fresh->challengeStatus())->toBe(ChallengeStatus::Trial)
            ->and($fresh->awaitsPayment())->toBeFalse()
            ->and(PushNotification::count())->toBe(0);
        ctBilling($parent)->assertOk()
            ->assertJsonPath('payments_enforced', false)
            ->assertJsonPath('pets.0.status', 'trial');
    });

    it('a superadmin unlocks a payment-locked challenge without a credit (source admin)', function () {
        [$parent, , $pet] = ctFamily();
        ctAt('2026-10-14 10:00:00');
        ctTick();
        expect($pet->fresh()->isPaymentLocked())->toBeTrue();

        expect(app(ChallengeCreditService::class)->grantByAdmin($pet->fresh()))->toBeTrue();
        $fresh = $pet->fresh();
        expect($fresh->isPaymentLocked())->toBeFalse()
            ->and($fresh->challengeStatus())->toBe(ChallengeStatus::Paid)
            ->and($fresh->challenge_paid_source)->toBe(ChallengePaidSource::Admin)
            ->and($fresh->deletionLosesPurchase())->toBeFalse()
            ->and(ChallengeCredit::count())->toBe(0);

        // Idempotent; never for a free pet.
        expect(app(ChallengeCreditService::class)->grantByAdmin($fresh))->toBeFalse();
        [, , $free] = ctFamily([], 'free');
        expect(app(ChallengeCreditService::class)->grantByAdmin($free))->toBeFalse();
    });
});
