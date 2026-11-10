<?php

use App\Enums\ChallengePaidSource;
use App\Enums\ChallengeStatus;
use App\Enums\PetPlan;
use App\Events\PetUpdated;
use App\Jobs\SendUnassignedCreditAlert;
use App\Models\ChallengeCertificate;
use App\Models\ChallengeCredit;
use App\Models\ChallengePurchaseIntent;
use App\Models\Pet;
use App\Models\PetCaretaker;
use App\Models\PetContract;
use App\Models\PetDailyRoutine;
use App\Models\PetStatusPeriod;
use App\Models\User;
use App\Services\Alerts\DiscordWebhook;
use App\Services\CareScoreService;
use App\Services\ChallengeCertificateService;
use App\Services\FamilyService;
use App\Services\Media\MediaEntitlementService;
use App\Services\PetPlanPayload;
use App\Services\UnassignedCreditMonitor;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| M6-U01 — upgrade an existing free pet (mutt / domestic cat) to the
| 12-week challenge (docs/product/FREE_PET_UPGRADE_SPEC.md §5 / §6 PR 1,
| David 2026-11-10; purchase intent §5.3 approved »da« 2026-11-10).
|--------------------------------------------------------------------------
| The free mutt below was born 2026-07-01 — more than 12 weeks before the
| purchase on 2026-10-07 12:00 Ljubljana (10:00 UTC).
*/

const FU_BORN = '2026-07-01 08:00:00';
const FU_NOW = '2026-10-07 10:00:00';
const FU_SECRET = 'fu-webhook-secret';
const FU_DISCORD = 'https://discord.com/api/webhooks/987/fu-secret';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse(FU_NOW, 'UTC'));
    seedBreedConfigs();
    config([
        'services.revenuecat.webhook_secret' => FU_SECRET,
        'services.revenuecat.accept_sandbox' => true,
        'services.revenuecat.challenge_products' => ['petprep_challenge_12w'],
        'payments.enforced' => true,
    ]);
    $this->withoutMiddleware([ThrottleRequests::class]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function fuAt(string $utc): void
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
}

/**
 * Parent + child + a born, profiled FREE pet (default: a mutt born FU_BORN).
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: User, 1: User, 2: Pet}
 */
function fuFamily(array $attributes = [], string $breed = 'mutt'): array
{
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => 'Maja']);
    $pet = Pet::factory()->freePlan()->create(array_merge([
        'user_id' => $child->id,
        'breed_type' => $breed,
        'born_at' => Carbon::parse(FU_BORN, 'UTC'),
        'arrival_age_months' => 2,
        'name' => 'Pika',
    ], $attributes));

    return [$parent, $child, disableHygieneEvents($pet)];
}

/** Another child of the family with an unpaid challenge pet (Border Collie, born, payment_required). */
function fuUnpaidChallengePet(User $parent): Pet
{
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);

    return disableHygieneEvents(Pet::factory()->borderCollie()->trial()->create(['user_id' => $child->id]));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function fuPurchase(User $parent, array $overrides = []): TestResponse
{
    app('auth')->forgetGuards();

    return test()->withHeaders(['Authorization' => 'Bearer '.FU_SECRET])->postJson('/api/webhooks/revenuecat', [
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

function fuRefund(User $parent, string $transactionId): TestResponse
{
    return fuPurchase($parent, ['type' => 'CANCELLATION', 'transaction_id' => $transactionId, 'cancel_reason' => 'CUSTOMER_SUPPORT']);
}

function fuActivate(User $parent, Pet $pet): TestResponse
{
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson("/api/parent/pets/{$pet->id}/challenge/activate");
}

function fuIntent(User $user, Pet $pet): TestResponse
{
    app('auth')->forgetGuards();
    actingAsRole($user);

    return postJson("/api/parent/pets/{$pet->id}/challenge/intent");
}

function fuBilling(User $parent): array
{
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return getJson('/api/parent/billing')->assertOk()->json();
}

/** Buy (webhook) + assign (activate) — the new app's path without an intent. */
function fuUpgrade(User $parent, Pet $pet): Pet
{
    fuPurchase($parent)->assertOk();
    fuActivate($parent, $pet)->assertOk()->assertJsonPath('status', 'activated');

    return $pet->fresh();
}

/** A closed routine row (UTC instants). */
function fuRoutine(Pet $pet, string $date, string $type, int $slot, string $opensUtc, string $dueUtc, string $status, ?User $actor): void
{
    PetDailyRoutine::create([
        'pet_id' => $pet->id, 'local_date' => $date, 'routine_type' => $type, 'slot' => $slot,
        'opens_at' => Carbon::parse($opensUtc, 'UTC'), 'due_at' => Carbon::parse($dueUtc, 'UTC'), 'status' => $status,
        'done_at' => $status === 'done' ? Carbon::parse($opensUtc, 'UTC')->addMinutes(30) : null,
        'actor_user_id' => $status === 'done' ? $actor?->id : null,
    ]);
}

/** Only planted routine rows count (the ledger never computes these days). */
function fuClosedLedger(Pet $pet): void
{
    DB::table('pets')->where('id', $pet->id)->update(['routines_closed_through' => '2027-03-31', 'routines_next_close_at' => null]);
}

/* ───────────────────────────── The upgrade ───────────────────────────── */

describe('upgrade by a purchase', function () {
    it('upgrades a free mutt older than 12 weeks: same pet, week 1 of 12 from the purchase, NOT finished (David)', function () {
        [$parent, $child, $pet] = fuFamily();
        $ageBefore = $pet->ageMonths();
        $stageBefore = $pet->life_stage;
        $lookBefore = $pet->pet_look_id;
        expect($pet->hasReachedSimulationEnd())->toBeFalse(); // free: never

        // No intent (the webhook alone): a free pet is NEVER auto-assigned.
        fuPurchase($parent)->assertOk();
        expect($pet->fresh()->plan)->toBe(PetPlan::Free)
            ->and(ChallengeCredit::available()->count())->toBe(1);

        Event::fake([PetUpdated::class]);
        fuActivate($parent, $pet)->assertOk()
            ->assertJsonPath('status', 'activated')
            ->assertJsonPath('plan.type', 'challenge')
            ->assertJsonPath('plan.status', 'paid')
            ->assertJsonPath('plan.display_type', 'challenge')
            ->assertJsonPath('plan.challenge_started_at', '2026-10-07T12:00:00+02:00')
            ->assertJsonPath('credits_available', 0);

        $pet = $pet->fresh();
        expect($pet->plan)->toBe(PetPlan::Challenge)
            ->and($pet->challengeStatus())->toBe(ChallengeStatus::Paid)
            ->and($pet->challenge_paid_source)->toBe(ChallengePaidSource::Purchase)
            ->and($pet->challenge_started_at->utc()->toDateTimeString())->toBe(FU_NOW)
            ->and($pet->trial_ends_at->equalTo($pet->born_at))->toBeTrue()
            ->and($pet->born_at->utc()->toDateTimeString())->toBe(FU_BORN)
            // Age, stage, look and name stay (U1); the program clock of the age is the birth.
            ->and($pet->ageMonths())->toBe($ageBefore)
            ->and($pet->life_stage)->toBe($stageBefore)
            ->and($pet->pet_look_id)->toBe($lookBefore)
            ->and($pet->name)->toBe('Pika')
            // The 12 weeks start now: not finished although born 14 weeks ago.
            ->and($pet->virtualAgeInMonths())->toBe(0)
            ->and($pet->hasReachedSimulationEnd())->toBeFalse()
            ->and($pet->deletionLosesPurchase())->toBeTrue()
            // Already playing: no set-up screen (M5-T06 QA M1).
            ->and($pet->preparation_started_at)->toBeNull();
        expect(ChallengeCredit::first()->assigned_via)->toBe(ChallengeCredit::VIA_PARENT);
        Event::assertDispatchedTimes(PetUpdated::class, 1);
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->petId === $pet->id && $e->eventType === 'challenge_paid');

        $billing = collect(fuBilling($parent)['pets'])->firstWhere('pet_id', $pet->id);
        expect($billing['plan'])->toBe('challenge')
            ->and($billing['status'])->toBe('paid')
            ->and($billing['trial_available'])->toBeNull() // never "a free trial is available"
            ->and($billing['upgrade'])->toBeNull()
            ->and($billing['challenge_started_at'])->toBe('2026-10-07T12:00:00+02:00')
            ->and($billing['deletion_loses_purchase'])->toBeTrue();

        // The child's week counts from the purchase: week 1 of 12.
        fuAt('2026-10-09 10:00:00');
        actingAsRole($parent);
        $block = collect(getJson('/api/parent/dashboard')->assertOk()->json('family.children'))->firstWhere('id', $child->id);
        expect($block['progress']['week'])->toBe(1)
            ->and($block['progress']['days_elapsed'])->toBe(2)
            ->and($block['progress']['started_at'])->toStartWith('2026-10-07T10:00:00')
            ->and($block['progress']['completed'])->toBeFalse();

        // The tick never marks it certificate-eligible from the birth.
        test()->artisan('pets:process-decay')->assertSuccessful();
        expect($pet->fresh()->certificate_eligible)->toBeFalse();
    });

    it('upgrades a free domestic cat the same way and queues the full media set (dog + cat, P6)', function () {
        [$parent, , $cat] = fuFamily(['species' => 'cat'], 'domestic_cat');
        expect($cat->isCat())->toBeTrue()
            ->and(app(MediaEntitlementService::class)->tierFor($cat))->toBe('basic');

        $cat = fuUpgrade($parent, $cat);
        expect($cat->plan)->toBe(PetPlan::Challenge)
            ->and((int) $cat->state_media_version)->toBe(MediaEntitlementService::CURRENT_MEDIA_VERSION)
            ->and(app(MediaEntitlementService::class)->tierFor($cat))->toBe('full');

        [$parent2, , $dog] = fuFamily();
        $dog = fuUpgrade($parent2, $dog);
        expect((int) $dog->state_media_version)->toBe(MediaEntitlementService::CURRENT_MEDIA_VERSION)
            ->and(app(MediaEntitlementService::class)->tierFor($dog))->toBe('full');
    });

    it('goes back to the free plan on a refund; a new purchase starts new 12 weeks (K7)', function () {
        [$parent, , $pet] = fuFamily();
        $pet = fuUpgrade($parent, $pet);
        $tx = ChallengeCredit::first()->transaction_id;

        fuAt('2026-10-20 10:00:00');
        fuRefund($parent, $tx)->assertOk();
        $pet = $pet->fresh();
        expect($pet->plan)->toBe(PetPlan::Free)
            ->and($pet->challengeStatus())->toBeNull()
            ->and($pet->challenge_started_at)->toBeNull()
            ->and($pet->challenge_paid_at)->toBeNull()
            ->and($pet->isPaymentLocked())->toBeFalse()
            ->and(PetPlanPayload::for($pet)->toArray()['display_type'])->toBe('free')
            ->and(collect(fuBilling($parent)['pets'])->firstWhere('pet_id', $pet->id)['upgrade'])->toBe(['available' => true, 'reason' => null]);

        // K7: buy again → a new start.
        fuAt('2026-11-02 09:00:00');
        $pet = fuUpgrade($parent, $pet);
        expect($pet->plan)->toBe(PetPlan::Challenge)
            ->and($pet->converted_to_free_at)->toBeNull()
            ->and($pet->challenge_started_at->utc()->toDateTimeString())->toBe('2026-11-02 09:00:00')
            ->and($pet->virtualAgeInMonths())->toBe(0);
    });

    it('warns before a deletion that the purchase is lost (P5) — also for a pet born > 12 weeks ago', function () {
        [$parent, $child, $pet] = fuFamily();
        fuUpgrade($parent, $pet);

        app('auth')->forgetGuards();
        actingAsRole($parent);
        test()->deleteJson("/api/parent/children/{$child->id}", ['password' => 'password', 'confirm' => true])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'paid_challenge_ack_required')
            ->assertJsonPath('pets.0.pet_id', $pet->id);
        expect(collect(fuBilling($parent)['pets'])->firstWhere('pet_id', $pet->id)['deletion_loses_purchase'])->toBeTrue();

        // 12 program weeks after the PURCHASE it is finished: no warning any more.
        fuAt('2026-12-30 10:00:00');
        expect($pet->fresh()->deletionLosesPurchase())->toBeFalse();
    });

    it('gives the certificate only 84 program days after the purchase — with a payment lock and a DST change in between (David)', function () {
        Queue::fake();
        Storage::fake('certificates');
        [$parent, $child, $pet] = fuFamily();
        fuClosedLedger($pet);
        // Perfect care before the purchase (never counted) …
        for ($d = Carbon::parse('2026-09-20', 'UTC'); $d->lt(Carbon::parse('2026-10-07', 'UTC')); $d->addDay()) {
            fuRoutine($pet, $d->toDateString(), 'feed', 0, $d->toDateString().' 07:00:00', $d->toDateString().' 09:00:00', 'missed', null);
        }
        // Before the purchase the old clock (from the birth) would have ended long ago: nothing.
        app(ChallengeCertificateService::class)->processCompletions();
        expect(ChallengeCertificate::count())->toBe(0);

        $pet = fuUpgrade($parent, $pet);
        fuClosedLedger($pet);
        for ($d = Carbon::parse('2026-10-08', 'UTC'); $d->lt(Carbon::parse('2027-01-09', 'UTC')); $d->addDay()) {
            fuRoutine($pet, $d->toDateString(), 'feed', 0, $d->toDateString().' 07:00:00', $d->toDateString().' 09:00:00', 'done', $child);
        }
        // A finished 10-day payment lock after the purchase (DST ends 2026-10-25 in between):
        // lock time is not program time (M3-11b) → the end moves by exactly 10 days.
        PetStatusPeriod::create(['pet_id' => $pet->id, 'kind' => 'payment_lock',
            'started_at' => Carbon::parse('2026-11-01 08:00:00', 'UTC'), 'ended_at' => Carbon::parse('2026-11-11 08:00:00', 'UTC')]);

        // purchase 2026-10-07 10:00 UTC + 84 days + 10 days lock = 2027-01-09 10:00 UTC.
        fuAt('2027-01-09 09:59:00');
        app(ChallengeCertificateService::class)->processCompletions();
        expect(ChallengeCertificate::count())->toBe(0)
            ->and($pet->fresh()->hasReachedSimulationEnd())->toBeFalse();

        fuAt('2027-01-09 10:00:00');
        app(ChallengeCertificateService::class)->processCompletions();
        $row = ChallengeCertificate::where('pet_id', $pet->id)->where('child_user_id', $child->id)->first();
        expect($row)->not->toBeNull()
            ->and($row->started_at->utc()->toDateTimeString())->toBe(FU_NOW)
            ->and($row->completed_at->utc()->toDateTimeString())->toBe('2027-01-09 10:00:00')
            // Only the routines since the purchase count: the missed ones before it do not.
            ->and($row->care_score)->toBe(100)
            ->and($row->outcome->value)->toBe('certificate')
            ->and($pet->fresh()->hasReachedSimulationEnd())->toBeTrue()
            ->and($pet->fresh()->deletionLosesPurchase())->toBeFalse();
    });

    it('allows the purchase during an illness or a hard stop — the challenge starts at once (U6)', function () {
        [$parent, , $ill] = fuFamily(['illness_until' => Carbon::parse('2026-10-08 10:00:00', 'UTC')]);
        expect($ill->isIll())->toBeTrue();
        $ill = fuUpgrade($parent, $ill);
        expect($ill->plan)->toBe(PetPlan::Challenge)
            ->and($ill->challenge_started_at->utc()->toDateTimeString())->toBe(FU_NOW)
            ->and($ill->isIll())->toBeTrue();

        [$parent2, , $stopped] = fuFamily(['is_hard_stopped' => true]);
        $stopped = fuUpgrade($parent2, $stopped);
        expect($stopped->plan)->toBe(PetPlan::Challenge)
            ->and((bool) $stopped->is_hard_stopped)->toBeTrue();
    });

    it('is idempotent: activating an upgraded pet again uses no second purchase', function () {
        [$parent, , $pet] = fuFamily();
        fuUpgrade($parent, $pet);
        fuPurchase($parent)->assertOk(); // a second, unused purchase
        fuActivate($parent, $pet)->assertOk()->assertJsonPath('status', 'already_active')->assertJsonPath('credits_available', 1);
        expect($pet->fresh()->challenge_started_at->utc()->toDateTimeString())->toBe(FU_NOW);
    });
});

/* ───────────────────────────── Refusals ───────────────────────────── */

describe('refusals', function () {
    it('refuses a game-over, an unborn and a legacy-profile free pet with clear codes (K5)', function () {
        [$parent, , $gameOver] = fuFamily(['is_game_over' => true]);
        fuPurchase($parent)->assertOk();
        fuActivate($parent, $gameOver)->assertStatus(422)->assertJsonPath('reason', 'game_over');

        [$parent2, , $unborn] = fuFamily(['born_at' => null, 'last_decay_at' => null, 'last_step_reset_at' => null]);
        fuPurchase($parent2)->assertOk();
        fuActivate($parent2, $unborn)->assertStatus(422)->assertJsonPath('reason', 'pet_not_born');

        [$parent3, , $legacy] = fuFamily(['arrival_age_months' => null]);
        fuPurchase($parent3)->assertOk();
        fuActivate($parent3, $legacy)->assertStatus(422)->assertJsonPath('reason', 'legacy_profile');
        expect(collect(fuBilling($parent3)['pets'])->firstWhere('pet_id', $legacy->id)['upgrade'])
            ->toBe(['available' => false, 'reason' => 'legacy_profile']);

        // Nothing was used; nobody changed.
        expect(ChallengeCredit::available()->count())->toBe(3)
            ->and(Pet::where('plan', 'challenge')->count())->toBe(0);
    });
});

/* ───────────────────────────── Score / history (K1, K2) ───────────────────────────── */

describe('score and history from the purchase', function () {
    it('counts only routines due after the purchase (daily) / periods opening after it (weekly), illnesses after it (K1, K2)', function () {
        [$parent, $child, $pet] = fuFamily();
        fuClosedLedger($pet);
        // Before the purchase (10:00 UTC on 10-07): never counted.
        fuRoutine($pet, '2026-10-05', 'feed', 0, '2026-10-05 07:00:00', '2026-10-05 09:00:00', 'missed', null);
        fuRoutine($pet, '2026-10-07', 'feed', 0, '2026-10-07 07:00:00', '2026-10-07 09:00:00', 'missed', null);
        // Open at the purchase, due after it: counts.
        fuRoutine($pet, '2026-10-07', 'feed', 1, '2026-10-07 09:00:00', '2026-10-07 11:00:00', 'done', $child);
        // A weekly period that opened before the purchase: does not count …
        fuRoutine($pet, '2026-10-12', 'grooming', 0, '2026-10-05 08:00:00', '2026-10-12 08:00:00', 'missed', null);
        // … the next one does.
        fuRoutine($pet, '2026-10-19', 'grooming', 0, '2026-10-12 08:00:00', '2026-10-19 08:00:00', 'done', $child);
        PetStatusPeriod::create(['pet_id' => $pet->id, 'kind' => 'illness',
            'started_at' => Carbon::parse('2026-10-06 08:00:00', 'UTC'), 'ended_at' => Carbon::parse('2026-10-06 20:00:00', 'UTC')]);

        $pet = fuUpgrade($parent, $pet);
        PetStatusPeriod::create(['pet_id' => $pet->id, 'kind' => 'illness',
            'started_at' => Carbon::parse('2026-10-09 08:00:00', 'UTC'), 'ended_at' => Carbon::parse('2026-10-09 20:00:00', 'UTC')]);

        fuAt('2026-10-20 10:00:00');
        $scores = app(CareScoreService::class);
        $board = $scores->board(collect([$pet->fresh()]), 'Europe/Ljubljana');
        $childScore = $scores->childScore($board, $child->id, $pet->fresh());
        expect($childScore['done'])->toBe(2)
            ->and($childScore['routines'])->toBe(2)
            ->and($childScore['illnesses'])->toBe(1)
            ->and($childScore['score'])->toBe(90)
            ->and($childScore['since'])->toStartWith('2026-10-07T10:00:00');

        // K2: the pet's score on the dashboard counts from the purchase too.
        $petScore = $scores->petSummary($board, $pet->fresh())['care_score'];
        expect($petScore['done'])->toBe(2)
            ->and($petScore['expected'])->toBe(2)
            ->and($petScore['illnesses'])->toBe(1)
            ->and($petScore['score'])->toBe(90)
            ->and($petScore['since'])->toStartWith('2026-10-07T10:00:00');
    });

    it('keeps the days before the purchase visible, labelled »pred izzivom«, and lifts the 7-day limit', function () {
        [$parent, $child, $pet] = fuFamily();
        fuClosedLedger($pet);
        fuRoutine($pet, '2026-10-05', 'feed', 0, '2026-10-05 07:00:00', '2026-10-05 09:00:00', 'done', $child);
        fuUpgrade($parent, $pet);

        fuAt('2026-10-09 10:00:00');
        actingAsRole($parent);
        $block = collect(getJson('/api/parent/dashboard')->assertOk()->json('family.children'))->firstWhere('id', $child->id);
        $days = collect($block['last_7_days'])->keyBy('date');
        expect($days['2026-10-05']['pre_challenge'])->toBeTrue()
            ->and($days['2026-10-05']['done'])->toBe(1) // still shown
            ->and($days['2026-10-06']['pre_challenge'])->toBeTrue()
            ->and($days['2026-10-07']['pre_challenge'])->toBeFalse() // the purchase day is a challenge day
            ->and($days['2026-10-09']['pre_challenge'])->toBeFalse();

        $report = getJson("/api/parent/children/{$child->id}/report?days=30")->assertOk()->json();
        expect($report['history_limited'])->toBeFalse()
            ->and($report['days'])->toBe(30)
            ->and(collect($report['daily'])->firstWhere('date', '2026-10-05')['pre_challenge'])->toBeTrue();
    });

    it('starts every current caretaker at the purchase; a child who joins later starts at their contract', function () {
        [$parent, $maja, $pet] = fuFamily();
        $luka = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => 'Luka']);
        app(FamilyService::class)->addCaretaker($pet, $luka, true);
        PetContract::create(['pet_id' => $pet->id, 'user_id' => $luka->id, 'signature_format' => 'svg_path', 'signature' => 'M1 1 L2 2',
            'signed_at' => Carbon::parse('2026-08-01 08:00:00', 'UTC')]);

        $pet = fuUpgrade($parent, $pet);

        fuAt('2026-10-20 08:00:00');
        $eva = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => 'Eva']);
        app(FamilyService::class)->addCaretaker($pet, $eva, true);
        PetContract::create(['pet_id' => $pet->id, 'user_id' => $eva->id, 'signature_format' => 'svg_path', 'signature' => 'M1 1 L2 2',
            'signed_at' => Carbon::parse('2026-10-20 08:00:00', 'UTC')]);

        fuAt('2026-10-22 10:00:00');
        $scores = app(CareScoreService::class);
        $board = $scores->board(collect([$pet->fresh()]), 'Europe/Ljubljana');
        $maj = $scores->childSummary($board, $maja->id, $pet->fresh())['progress'];
        $luk = $scores->childSummary($board, $luka->id, $pet->fresh())['progress'];
        $ev = $scores->childSummary($board, $eva->id, $pet->fresh())['progress'];
        expect($maj['started_at'])->toStartWith('2026-10-07T10:00:00')
            ->and($maj['week'])->toBe(3)
            ->and($luk['started_at'])->toStartWith('2026-10-07T10:00:00')
            ->and($ev['started_at'])->toStartWith('2026-10-20T08:00:00')
            ->and($ev['week'])->toBe(1);
        // The certificate start follows the same rule.
        $cert = app(ChallengeCertificateService::class);
        $caretakers = PetCaretaker::where('pet_id', $pet->id)->get();
        $contracts = PetContract::where('pet_id', $pet->id)->get();
        expect($cert->startOf($pet->fresh(), $caretakers->firstWhere('user_id', $maja->id), $contracts)->toDateTimeString())->toBe(FU_NOW)
            ->and($cert->startOf($pet->fresh(), $caretakers->firstWhere('user_id', $eva->id), $contracts)->toDateTimeString())->toBe('2026-10-20 08:00:00');
    });
});

/* ───────────────────────────── Purchase intent (§5.3) ───────────────────────────── */

describe('purchase intent', function () {
    it('is parent-only, own family only, and follows the activation rules', function () {
        [$parent, $child, $pet] = fuFamily();
        fuIntent($parent, $pet)->assertCreated()->assertJsonPath('pet_id', $pet->id)
            ->assertJsonPath('expires_at', Carbon::parse(FU_NOW, 'UTC')->addMinutes(30)->toIso8601String());
        fuIntent($child, $pet)->assertForbidden();
        [$stranger] = fuFamily();
        fuIntent($stranger, $pet)->assertNotFound()->assertJsonPath('reason', 'pet_not_found');

        [$p2, , $legacy] = fuFamily(['arrival_age_months' => null]);
        fuIntent($p2, $legacy)->assertStatus(422)->assertJsonPath('reason', 'legacy_profile');
        [$p3, , $over] = fuFamily(['is_game_over' => true]);
        fuIntent($p3, $over)->assertStatus(422)->assertJsonPath('reason', 'game_over');
        $paid = disableHygieneEvents(Pet::factory()->borderCollie()->purchased()->create(['user_id' => User::factory()->child()->create(['parent_id' => $p3->id])->id]));
        fuIntent($p3, $paid)->assertStatus(422)->assertJsonPath('reason', 'already_paid');
        // An unpaid challenge pet (also the old purchase flow) is allowed.
        fuIntent($p3, fuUnpaidChallengePet($p3))->assertCreated();
    });

    it('assigns the purchase to the intent\'s free pet even when another unpaid challenge pet waits (K4)', function () {
        [$parent, , $mutt] = fuFamily();
        $collie = fuUnpaidChallengePet($parent);
        expect($collie->challengeStatus())->toBe(ChallengeStatus::PaymentRequired);

        fuIntent($parent, $mutt)->assertCreated();
        fuAt('2026-10-07 10:05:00');
        fuPurchase($parent)->assertOk();

        $mutt = $mutt->fresh();
        expect($mutt->plan)->toBe(PetPlan::Challenge)
            ->and($mutt->challenge_paid_source)->toBe(ChallengePaidSource::Purchase)
            ->and($mutt->challenge_started_at->utc()->toDateTimeString())->toBe('2026-10-07 10:05:00')
            ->and($collie->fresh()->challengeStatus())->toBe(ChallengeStatus::PaymentRequired);
        $credit = ChallengeCredit::first();
        $intent = ChallengePurchaseIntent::first();
        expect($credit->pet_id)->toBe($mutt->id)
            ->and($credit->assigned_via)->toBe(ChallengeCredit::VIA_INTENT)
            ->and($intent->consumed_by)->toBe('webhook')
            ->and($intent->challenge_credit_id)->toBe($credit->id);

        // The new app still calls activate afterwards: idempotent.
        fuActivate($parent, $mutt)->assertOk()->assertJsonPath('status', 'already_active');
        expect($collie->fresh()->challengeStatus())->toBe(ChallengeStatus::PaymentRequired);
    });

    it('is single use and expires after 30 minutes — then the webhook works exactly as today', function () {
        [$parent, , $mutt] = fuFamily();
        $collie = fuUnpaidChallengePet($parent);

        fuIntent($parent, $mutt)->assertCreated();
        fuAt('2026-10-07 10:31:00'); // bought 31 min after the intent
        fuPurchase($parent)->assertOk();
        expect($mutt->fresh()->plan)->toBe(PetPlan::Free)
            ->and($collie->fresh()->challengeStatus())->toBe(ChallengeStatus::Paid) // old rule: the only unpaid challenge pet
            ->and(ChallengeCredit::first()->assigned_via)->toBe(ChallengeCredit::VIA_WEBHOOK);

        // Single use: the consumed intent never assigns a second purchase.
        [$p2, , $m2] = fuFamily();
        fuIntent($p2, $m2)->assertCreated();
        fuPurchase($p2)->assertOk();
        expect($m2->fresh()->plan)->toBe(PetPlan::Challenge);
        $m3 = disableHygieneEvents(Pet::factory()->freePlan()->create([
            'user_id' => User::factory()->child()->create(['parent_id' => $p2->id])->id, 'born_at' => now(), 'arrival_age_months' => 2,
        ]));
        fuPurchase($p2)->assertOk();
        expect($m3->fresh()->plan)->toBe(PetPlan::Free)
            ->and(ChallengeCredit::where('family_id', $m2->family_id)->available()->count())->toBe(1);
    });

    it('lets a newer intent replace the parent\'s older one; activation settles it', function () {
        [$parent, , $mutt] = fuFamily();
        $collie = fuUnpaidChallengePet($parent);
        fuIntent($parent, $mutt)->assertCreated();
        fuIntent($parent, $collie)->assertCreated();
        expect(ChallengePurchaseIntent::whereNull('consumed_at')->count())->toBe(1)
            ->and(ChallengePurchaseIntent::where('consumed_by', 'superseded')->value('pet_id'))->toBe($mutt->id);

        fuPurchase($parent)->assertOk();
        expect($collie->fresh()->challengeStatus())->toBe(ChallengeStatus::Paid)
            ->and($mutt->fresh()->plan)->toBe(PetPlan::Free);

        // An intent followed by activation (webhook arrived without one — e.g. another phone).
        fuIntent($parent, $mutt)->assertCreated();
        fuPurchase($parent, ['app_user_id' => '$RCAnonymousID:abc', 'aliases' => [(string) $parent->id]])->assertOk();
        expect($mutt->fresh()->plan)->toBe(PetPlan::Challenge) // aliases name the parent → the intent applies
            ->and(ChallengePurchaseIntent::whereNull('consumed_at')->count())->toBe(0);
    });
});

/* ───────────────────────────── Old apps: no intent ───────────────────────────── */

describe('regression — old apps (no intent)', function () {
    it('auto-assigns exactly as before: the only unpaid challenge pet, never a free pet', function () {
        [$parent, , $mutt] = fuFamily();
        $collie = fuUnpaidChallengePet($parent);

        fuPurchase($parent)->assertOk();
        expect($collie->fresh()->challengeStatus())->toBe(ChallengeStatus::Paid)
            ->and($collie->fresh()->challenge_started_at)->toBeNull()
            ->and($mutt->fresh()->plan)->toBe(PetPlan::Free)
            ->and(ChallengeCredit::first()->assigned_via)->toBe(ChallengeCredit::VIA_WEBHOOK);

        // A family with only a free pet: the purchase waits (the new app's prompt / activate).
        [$p2, , $only] = fuFamily();
        fuPurchase($p2)->assertOk();
        expect($only->fresh()->plan)->toBe(PetPlan::Free)
            ->and(fuBilling($p2)['credits_available'])->toBe(1);
    });

    it('leaves an existing paid challenge pet unchanged (challenge clock = birth, new keys only)', function () {
        $parent = User::factory()->parent()->create();
        $child = User::factory()->child()->create(['parent_id' => $parent->id]);
        $pet = disableHygieneEvents(Pet::factory()->borderCollie()->purchased()->create([
            'user_id' => $child->id, 'born_at' => Carbon::parse('2026-08-01 08:00:00', 'UTC'),
        ]));
        expect($pet->challenge_started_at)->toBeNull()
            ->and($pet->virtualAgeInMonths())->toBe(intdiv($pet->programSecondsAt(now()), 7 * 86400))
            ->and($pet->virtualAgeInMonths())->toBe(9)
            ->and(PetPlanPayload::for($pet)->toArray()['challenge_started_at'])->toBeNull();

        $billing = collect(fuBilling($parent)['pets'])->firstWhere('pet_id', $pet->id);
        expect($billing['challenge_started_at'])->toBeNull()->and($billing['upgrade'])->toBeNull();

        $board = app(CareScoreService::class)->board(collect([$pet]), 'Europe/Ljubljana');
        expect($board['challenge_starts'][$pet->id])->toBe($board['starts'][$pet->id])
            ->and(app(CareScoreService::class)->childSummary($board, $child->id, $pet)['progress']['started_at'])->toStartWith('2026-08-01T08:00:00');
    });
});

/* ───────────────────────────── Safety net ───────────────────────────── */

describe('safety net — unassigned purchase alert', function () {
    it('posts one Discord alert when a purchase stays unassigned > 10 min in a family with a waiting pet or an intent', function () {
        config(['services.discord.alerts_webhook_url' => FU_DISCORD]);
        Http::fake(['discord.com/api/webhooks/*' => Http::response('', 204)]);

        // Two unpaid challenge pets → the webhook cannot choose (old rule) → unassigned.
        [$parent] = fuFamily();
        fuUnpaidChallengePet($parent);
        fuUnpaidChallengePet($parent);
        fuPurchase($parent)->assertOk();
        // A family with only a free pet and no intent: holding a purchase is allowed — no alert.
        [$quiet] = fuFamily();
        fuPurchase($quiet)->assertOk();

        fuAt('2026-10-07 10:09:00');
        expect(app(UnassignedCreditMonitor::class)->check())->toBe([]);

        fuAt('2026-10-07 10:11:00');
        test()->artisan('billing:unassigned-credits-check')->assertSuccessful();
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => str_contains((string) $r['embeds'][0]['title'], '1')
            && ! str_contains(json_encode($r->data()), 'Maja'));
        $alerted = ChallengeCredit::whereNotNull('unassigned_alerted_at')->get();
        expect($alerted)->toHaveCount(1)
            ->and($alerted->first()->family_id)->toBe(app(FamilyService::class)->familyOf($parent)->id);

        // Once per purchase.
        fuAt('2026-10-07 10:30:00');
        test()->artisan('billing:unassigned-credits-check')->assertSuccessful();
        Http::assertSentCount(1);
    });

    it('alerts for a family that chose a pet (intent) when the purchase still found no pet; a failed post retries', function () {
        config(['services.discord.alerts_webhook_url' => FU_DISCORD]);
        Http::fake(['discord.com/api/webhooks/*' => Http::sequence()->push(['message' => 'down'], 500)->push('', 204)]);

        [$parent, , $mutt] = fuFamily();
        fuIntent($parent, $mutt)->assertCreated();
        // The pet went to the shelter before the purchase arrived: the intent cannot be used.
        Pet::whereKey($mutt->id)->update(['is_game_over' => true]);
        fuPurchase($parent)->assertOk();
        expect(ChallengeCredit::available()->count())->toBe(1);

        fuAt('2026-10-07 10:15:00');
        Queue::fake([SendUnassignedCreditAlert::class]);
        expect(app(UnassignedCreditMonitor::class)->check())->toHaveCount(1);
        Queue::assertPushed(SendUnassignedCreditAlert::class, 1);
        // The post fails → the claim is released → the next run tries again.
        (new SendUnassignedCreditAlert([ChallengeCredit::first()->id], [1], ['PRODUCTION']))->handle(app(DiscordWebhook::class));
        expect(ChallengeCredit::first()->unassigned_alerted_at)->toBeNull();
    });
});
