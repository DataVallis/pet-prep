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
use App\Services\ChallengeService;
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
| M3-11 — plan per pet, 7-day trial, payment lock, challenge credits
| (docs/product/PAYMENTS_SPEC.md, David 2026-10-07 P1–P4)
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
 * Parent + child + a pet born now (unpaid challenge by default).
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: User, 1: User, 2: Pet}
 */
function ctFamily(array $attributes = [], string $state = 'trial'): array
{
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    $factory = Pet::factory()->mutt();
    $factory = match ($state) {
        'trial' => $factory->trial(),
        'free' => $factory->freePlan(),
        default => $factory,
    };
    $pet = $factory->create(array_merge(['user_id' => $child->id], $attributes));

    return [$parent, $child, disableHygieneEvents($pet)];
}

/** Another child + unpaid challenge pet in the same family. */
function ctSecondPet(User $parent): Pet
{
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);

    return disableHygieneEvents(Pet::factory()->mutt()->trial()->create(['user_id' => $child->id]));
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
            && $e->payload['plan'] === ['type' => 'challenge', 'status' => 'payment_required', 'trial_ends_at' => '2026-10-14T10:00:00+00:00', 'paid_at' => null, 'payments_enforced' => true]);

        // Child: state shows the lock, actions are 423 payment_required.
        app('auth')->forgetGuards();
        actingAsRole($child);
        getJson('/api/child/pet')->assertOk()
            ->assertJsonPath('lock.reason', 'payment_required')
            ->assertJsonPath('lock.until', null)
            ->assertJsonPath('pet.plan', ['type' => 'challenge', 'status' => 'payment_required', 'trial_ends_at' => '2026-10-14T12:00:00+02:00', 'paid_at' => null, 'payments_enforced' => true]);
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

    it('starts the trial at birth (the contract), 7 family-local days later across the DST change', function () {
        $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
        $child = User::factory()->child()->create(['parent_id' => $parent->id]);
        $pet = Pet::factory()->mutt()->trial()->unborn()->create(['user_id' => $child->id]);

        expect($pet->trial_ends_at)->toBeNull()
            ->and($pet->challengeStatus())->toBe(ChallengeStatus::Trial);

        // Born 2026-10-20 12:00 CEST; DST ends 2026-10-25 → 12:00 CET = 11:00 UTC.
        ctAt('2026-10-20 10:00:00');
        actingAsRole($child);
        postJson('/api/child/contract', ['signature_format' => 'svg_path', 'signature' => 'M10 10 L20 20'])->assertCreated()
            ->assertJsonPath('state.pet.plan.trial_ends_at', '2026-10-27T12:00:00+01:00');

        expect($pet->fresh()->trial_ends_at->toIso8601String())->toBe('2026-10-27T11:00:00+00:00');
    });

    it('never locks a free pet nor a grandfathered one; a free pet has no status', function () {
        [, , $free] = ctFamily([], 'free');
        [, , $old] = ctFamily([], 'grandfathered');

        ctAt('2026-11-07 10:00:00');
        ctTick();

        expect($free->fresh())->challengeStatus()->toBeNull()->payment_locked_at->toBeNull()->trial_ends_at->toBeNull()
            ->and($old->fresh())->challengeStatus()->toBe(ChallengeStatus::Paid)->payment_locked_at->toBeNull();
    });

    it('does not lock an unborn challenge pet (the trial has not started)', function () {
        [, , $pet] = ctFamily(['born_at' => null, 'last_decay_at' => null, 'last_step_reset_at' => null]);

        ctAt('2026-11-07 10:00:00');
        ctTick();

        expect($pet->fresh())->payment_locked_at->toBeNull()
            ->and($pet->fresh()->challengeStatus())->toBe(ChallengeStatus::Trial);
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

        // One more hour of play decays one hour (mutt thirst 10 %/h), not six.
        ctAt('2026-10-14 16:00:00');
        ctTick();
        expect($pet->fresh()->thirst_level)->toEqualWithDelta($thirst - 10.0, 1e-6)
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
    it('sends the parent one "trial ends tomorrow" on trial day 6 and parent + child one lock push', function () {
        config(['push.enabled' => true]);
        Queue::fake([SendPushNotification::class]);
        [$parent, $child, $pet] = ctFamily();
        $second = User::factory()->parent()->create();
        app(FamilyInviteService::class)->joinFamily($second, app(FamilyInviteService::class)->createInvite($parent)['code']);

        $billing = fn () => PushNotification::whereIn('type', ['trial_ending', 'payment_required']);

        ctAt('2026-10-13 09:59:00'); // > 24 h before the end: nothing yet
        ctTick();
        expect($billing()->count())->toBe(0);

        ctAt('2026-10-13 10:00:00');
        ctTick();
        ctAt('2026-10-13 10:05:00');
        ctTick();

        $reminder = PushNotification::where('type', PushType::TrialEnding->value)->sole();
        expect(collect($reminder->recipients)->pluck('audience')->unique()->all())->toBe(['parent'])
            ->and(collect($reminder->recipients)->pluck('user_id')->sort()->values()->all())->toBe(collect([$parent->id, $second->id])->sort()->values()->all())
            ->and($pet->fresh()->trial_reminder_sent_at)->not->toBeNull();

        ctAt('2026-10-14 10:00:00');
        ctTick();
        ctAt('2026-10-14 11:00:00');
        ctTick();

        $lock = PushNotification::where('type', PushType::PaymentRequired->value)->sole();
        expect(collect($lock->recipients)->pluck('audience')->sort()->values()->all())->toBe(['child', 'parent', 'parent'])
            ->and($lock->status)->toBe(PushNotification::STATUS_QUEUED)
            ->and($billing()->count())->toBe(2);
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
            'plan' => ['type' => 'challenge', 'status' => 'paid', 'trial_ends_at' => '2026-10-14T12:00:00+02:00', 'paid_at' => '2026-10-07T12:00:00+02:00', 'payments_enforced' => true],
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
        expect($pet->plan)->toBe(PetPlan::Challenge)->and($pet->breed_type->value)->toBe('border_collie')
            ->and($pet->challengeStatus())->toBe(ChallengeStatus::Trial);

        ctPin($parent, ['plan' => 'gold'])->assertUnprocessable()->assertJsonValidationErrors('plan');
    });

    it('a PIN without plan (old build, no profile) still makes a challenge pet; joining never changes the plan', function () {
        $parent = User::factory()->parent()->create();
        $pin = ctPin($parent, [])->assertOk()->assertJsonPath('plan', 'challenge')->json('pin');
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
            ->assertJsonPath('family.pets.0.plan', ['type' => 'free', 'status' => null, 'trial_ends_at' => null, 'paid_at' => null, 'payments_enforced' => true])
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
            'pets' => [['pet_id' => $pet->id, 'breed_type' => 'mutt']],
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
describe('P7 — one free trial per child; game over is not unlocked by a purchase', function () {
    it('starts a second challenge pet of the same child as payment_required at birth', function () {
        config(['push.enabled' => true]);
        Queue::fake([SendPushNotification::class]);
        [$parent, $child, $first] = ctFamily();
        // The first challenge ended in game over during its trial.
        Pet::whereKey($first->id)->update(['is_game_over' => true, 'is_active' => false]);

        // Activating the game-over pet is refused; the purchase waits.
        ctPurchase($parent);
        ctActivate($parent, $first)->assertStatus(422)->assertJsonPath('reason', 'pet_not_active');

        // (A paired child's PIN is a re-login, so generate-pin answers trial_available
        // null here; the next pet is created directly — see the open question in the report.)
        expect(app(ChallengeService::class)->childHadTrial($child))->toBeTrue();

        $second = Pet::factory()->mutt()->trial()->unborn()->create(['user_id' => $child->id]);
        ctBilling($parent)->assertJsonPath('pets.0.pet_id', $second->id)->assertJsonPath('pets.0.trial_available', false);

        ctAt('2026-10-08 10:00:00');
        app('auth')->forgetGuards();
        actingAsRole($child);
        postJson('/api/child/contract', ['signature_format' => 'svg_path', 'signature' => 'M10 10 L20 20'])->assertCreated()
            ->assertJsonPath('state.lock.reason', 'payment_required')
            ->assertJsonPath('state.pet.plan.status', 'payment_required')
            ->assertJsonPath('state.pet.plan.trial_ends_at', '2026-10-08T12:00:00+02:00');

        ctTick();
        expect($second->fresh()->isPaymentLocked())->toBeTrue()
            ->and(PushNotification::where('type', 'payment_required')->sole()->metric)->toBe('no_trial')
            ->and(PushCopy::body(PushType::PaymentRequired, 'no_trial', 'parent', 'en'))->toBe('The dog is waiting safely until you unlock the 12-week challenge in the app.')
            ->and(PushCopy::body(PushType::PaymentRequired, 'no_trial', 'child', 'sl'))->toBe('Igra počaka na starša. Tvoj kuža je na varnem in počiva.');

        // The waiting credit pays the new pet.
        ctActivate($parent, $second)->assertOk()->assertJsonPath('status', 'activated');
        expect($second->fresh()->isPaymentLocked())->toBeFalse();
    });

    it('keeps the trial for a child whose earlier pet was grandfathered', function () {
        [, $child, $old] = ctFamily([], 'grandfathered');
        Pet::whereKey($old->id)->update(['is_game_over' => true, 'is_active' => false]);
        $next = Pet::factory()->mutt()->trial()->unborn()->create(['user_id' => $child->id]);

        actingAsRole($child);
        postJson('/api/child/contract', ['signature_format' => 'svg_path', 'signature' => 'M10 10 L20 20'])->assertCreated()
            ->assertJsonPath('state.pet.plan.status', 'trial')
            ->assertJsonPath('state.pet.plan.trial_ends_at', '2026-10-14T12:00:00+02:00');
        expect(app(ChallengeService::class)->childHadTrial($child, $next->id))->toBeFalse();
    });

    it('tells the parent at PIN time whether the new challenge pet gets the free trial', function () {
        seedLifeStageData();
        $parent = User::factory()->parent()->create();
        $fresh = app(ChildProfileService::class)->createChild($parent, 'Nova', null);
        app('auth')->forgetGuards();
        actingAsRole($parent);

        postJson('/api/parent/generate-pin', ['child_id' => $fresh->id])->assertOk()
            ->assertJsonPath('plan', 'challenge')->assertJsonPath('trial_available', true);
        postJson('/api/parent/generate-pin', ['child_id' => $fresh->id, 'plan' => 'free'])->assertOk()
            ->assertJsonPath('plan', 'free')->assertJsonPath('trial_available', null);
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

describe('payment-lock time does not count toward the 12 weeks (David 2026-10-07)', function () {
    it('moves the end of the challenge by the time spent locked', function () {
        [, , $pet] = ctFamily(); // born 2026-10-07 10:00 UTC
        ctAt('2026-10-14 10:00:00');
        ctTick(); // locked at the end of the trial
        expect($pet->fresh()->isPaymentLocked())->toBeTrue();

        ctAt('2026-10-24 10:00:00'); // 10 days locked, then a purchase
        expect(app(ChallengeCreditService::class)->grantByAdmin($pet->fresh()))->toBeTrue();
        $paid = $pet->fresh();
        expect($paid->paymentLockedSeconds())->toBe(10 * 86400);

        // 12 weeks after birth: not over — 10 days of the program are still to play.
        ctAt('2026-12-30 10:00:00');
        expect($paid->fresh()->hasReachedSimulationEnd())->toBeFalse();
        ctAt('2027-01-09 10:00:00');
        expect($paid->fresh()->hasReachedSimulationEnd())->toBeTrue();
    });
});
