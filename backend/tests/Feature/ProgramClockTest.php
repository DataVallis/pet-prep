<?php

use App\Enums\ChallengePaidSource;
use App\Enums\PetStatusPeriodKind;
use App\Events\PetUpdated;
use App\Models\Pet;
use App\Models\PetStatusPeriod;
use App\Models\User;
use App\Services\ChallengeCreditService;
use App\Services\ChallengeService;
use App\Services\LifeStageService;
use App\Services\PetDecayService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\getJson;

/*
|--------------------------------------------------------------------------
| M3-11b — payment-lock time is not program time (David 2026-10-07)
|--------------------------------------------------------------------------
| While a pet waits locked for payment (`payment_lock` status period) the
| 12-week challenge week does not advance and the dog does not age; after
| payment both clocks resume where they stopped. Hard stop, illness and
| inactive periods still count.
*/

const PC_BORN = '2026-10-07 10:00:00'; // Wed, 12:00 in Ljubljana (CEST); trial ends 2026-10-14 10:00 UTC

beforeEach(function () {
    seedLifeStageData();
    Queue::fake();
});

function pcAt(string $utc): void
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
}

/**
 * Parent + child + a profiled pet (arrival 2 months) born now.
 *
 * @param  'trial'|'grandfathered'|'free'  $state
 * @return array{0: User, 1: User, 2: Pet}
 */
function pcFamily(string $state = 'trial', ?User $parent = null): array
{
    $parent ??= User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    // M5-F03: a challenge on trial is a paid breed (an unpaid mutt challenge is the free plan).
    $factory = match ($state) {
        'trial' => Pet::factory()->borderCollie()->legacyTrial(), // a pre-M3-13 7-day trial
        'free' => Pet::factory()->mutt()->freePlan(),
        default => Pet::factory()->mutt(),
    };
    $pet = $factory->create(['user_id' => $child->id, 'arrival_age_months' => 2]);

    return [$parent, $child, disableHygieneEvents($pet)];
}

/** The tick's trial step: locks every pet whose trial is over. */
function pcLockDue(): void
{
    app(ChallengeService::class)->processTrials();
}

function pcAdminUnlock(Pet $pet): void
{
    expect(app(ChallengeCreditService::class)->grantByAdmin($pet))->toBeTrue();
}

function pcAge(Pet $pet): ?int
{
    return $pet->fresh()->ageMonths();
}

describe('payment lock pauses the program clock', function () {
    it('runs the 12-week clock and the dog age as if the 10 locked days never happened', function () {
        pcAt(PC_BORN);
        [$parent, , $pet] = pcFamily();

        pcAt('2026-10-14 10:00:00');
        pcLockDue();
        expect($pet->fresh()->isPaymentLocked())->toBeTrue()
            ->and($pet->fresh()->virtualAgeInMonths())->toBe(1)
            ->and(pcAge($pet))->toBe(3);

        // A twin that was born 10 days later and never locked.
        pcAt('2026-10-17 10:00:00');
        [, , $twin] = pcFamily('grandfathered', $parent);

        pcAt('2026-10-24 10:00:00');
        pcAdminUnlock($pet);
        expect($pet->fresh()->isPaymentLocked())->toBeFalse()
            ->and($pet->fresh()->programSecondsPausedBefore(now()))->toBe(10 * 86400);

        foreach (['2026-10-24 10:00:00', '2026-10-30 09:00:00', '2026-10-31 12:00:00', '2026-11-20 15:00:00', '2026-12-30 10:00:00', '2027-01-08 10:00:00'] as $t) {
            pcAt($t);
            expect($pet->fresh()->virtualAgeInMonths())->toBe($twin->fresh()->virtualAgeInMonths(), "week at {$t}")
                ->and(pcAge($pet))->toBe(pcAge($twin), "dog age at {$t}");
        }

        pcAt('2026-10-28 10:00:00'); // 21 real days, 11 program days
        expect($pet->fresh()->virtualAgeInMonths())->toBe(1)
            ->and(pcAge($pet))->toBe(3);

        // 12 real weeks would end on 2026-12-30 10:00 UTC; 10 days later instead.
        pcAt('2026-12-30 10:00:00');
        expect($pet->fresh()->hasReachedSimulationEnd())->toBeFalse();
        pcAt('2027-01-09 09:59:59');
        expect($pet->fresh()->hasReachedSimulationEnd())->toBeFalse()
            ->and($pet->fresh()->virtualAgeInMonths())->toBe(11);
        pcAt('2027-01-09 10:00:00');
        expect($pet->fresh()->hasReachedSimulationEnd())->toBeTrue()
            ->and($pet->fresh()->virtualAgeInMonths())->toBe(12);
    });

    it('does not advance the week or the age while the pet is still locked', function () {
        pcAt(PC_BORN);
        [, , $pet] = pcFamily();
        $pet = $pet->fresh();
        expect($pet->virtualAgeInMonths())->toBe(0); // memoised spans: none yet

        // Lock on the same instance: the hooks forget the memoised spans.
        pcAt('2026-10-14 10:00:00');
        $pet->forceFill(['payment_locked_at' => now()])->save();

        foreach (['2026-10-14 10:00:00', '2026-10-21 10:00:01', '2026-11-14 10:00:00', '2027-02-01 00:00:00'] as $t) {
            pcAt($t);
            expect($pet->virtualAgeInMonths())->toBe(1, "week at {$t}")
                ->and($pet->ageMonths())->toBe(3, "dog age at {$t}")
                ->and($pet->programSecondsAt(now()))->toBe(7 * 86400)
                ->and($pet->hasReachedSimulationEnd())->toBeFalse();
        }
    });

    it('adds up several lock periods (purchase, refund re-lock, admin unlock)', function () {
        pcAt(PC_BORN);
        [, , $pet] = pcFamily();

        pcAt('2026-10-14 10:00:00');
        pcLockDue();

        pcAt('2026-10-24 10:00:00'); // 10 days locked → paid by a purchase
        DB::transaction(function () use ($pet) {
            $locked = Pet::whereKey($pet->id)->lockForUpdate()->first();
            app(ChallengeService::class)->markPaid($locked, ChallengePaidSource::Purchase, now());
        });

        pcAt('2026-11-03 10:00:00'); // refunded after the trial → locked again at once
        DB::transaction(function () use ($pet) {
            $locked = Pet::whereKey($pet->id)->lockForUpdate()->first();
            expect(app(ChallengeService::class)->markRefunded($locked, now()))->toBeTrue();
        });
        expect($pet->fresh()->isPaymentLocked())->toBeTrue();

        pcAt('2026-11-08 10:00:00'); // 5 more days locked → admin unlock
        pcAdminUnlock($pet);

        expect(PetStatusPeriod::where('pet_id', $pet->id)->where('kind', PetStatusPeriodKind::PaymentLock->value)->count())->toBe(2);

        pcAt('2026-11-10 10:00:00'); // 34 real days, 19 program days
        $fresh = $pet->fresh();
        expect($fresh->programSecondsPausedBefore(now()))->toBe(15 * 86400)
            ->and($fresh->programSecondsAt(now()))->toBe(19 * 86400)
            ->and($fresh->virtualAgeInMonths())->toBe(2)
            ->and($fresh->ageMonths())->toBe(4);

        pcAt('2027-01-14 09:59:59');
        expect($pet->fresh()->hasReachedSimulationEnd())->toBeFalse();
        pcAt('2027-01-14 10:00:00');
        expect($pet->fresh()->hasReachedSimulationEnd())->toBeTrue();
    });

    it('never moves the age of a date before the lock, and a locked day keeps its age after payment', function () {
        pcAt(PC_BORN);
        [, , $pet] = pcFamily();
        $lifeStages = app(LifeStageService::class);
        $on = fn (string $date) => $lifeStages->ageMonthsOn($pet->fresh(), $date);

        pcAt('2026-10-14 09:00:00');
        $before = ['2026-10-13' => $on('2026-10-13'), '2026-10-14' => $on('2026-10-14')];
        expect($before)->toBe(['2026-10-13' => 2, '2026-10-14' => 2]);

        pcAt('2026-10-14 10:00:00');
        pcLockDue();
        pcAt('2026-10-21 10:00:00');
        $lockedDay = $on('2026-10-20');
        expect($lockedDay)->toBe(3);

        pcAt('2026-10-24 10:00:00');
        pcAdminUnlock($pet);

        pcAt('2026-11-05 10:00:00');
        expect($on('2026-10-13'))->toBe(2)
            ->and($on('2026-10-14'))->toBe(2)
            ->and($on('2026-10-15'))->toBe(3)
            ->and($on('2026-10-20'))->toBe($lockedDay)
            ->and($on('2026-10-25'))->toBe(3)
            // Effective birth 2026-10-17 12:00 local: two program weeks on 10-31 12:00.
            ->and($on('2026-10-31'))->toBe(3)
            ->and($on('2026-11-01'))->toBe(4); // unlocked it would be 5 (three real weeks)
    });

    it('still counts hard-stop time, and changes nothing with the kill switch off or on the free plan', function () {
        pcAt(PC_BORN);
        [, , $paused] = pcFamily('grandfathered');
        [, , $free] = pcFamily('free');

        pcAt('2026-10-14 10:00:00');
        $paused->fresh()->forceFill(['is_hard_stopped' => true])->save();
        pcAt('2026-10-24 10:00:00');
        $paused->fresh()->forceFill(['is_hard_stopped' => false])->save();
        expect(PetStatusPeriod::where('pet_id', $paused->id)->where('kind', PetStatusPeriodKind::HardStop->value)->exists())->toBeTrue();

        pcAt('2026-10-28 12:00:00'); // three weeks (the local birthday hour is 11:00 UTC after the DST change)
        expect($paused->fresh()->virtualAgeInMonths())->toBe(3)
            ->and(pcAge($paused))->toBe(5)
            ->and($free->fresh()->virtualAgeInMonths())->toBe(3)
            ->and(pcAge($free))->toBe(5);

        // Kill switch off: no lock after the trial → the clock simply runs.
        config(['payments.enforced' => false]);
        pcAt(PC_BORN);
        [, , $trial] = pcFamily();
        pcAt('2026-10-14 10:00:00');
        pcLockDue();
        expect($trial->fresh()->isPaymentLocked())->toBeFalse();
        pcAt('2026-10-28 12:00:00');
        expect($trial->fresh()->virtualAgeInMonths())->toBe(3)
            ->and(pcAge($trial))->toBe(5);
    });
});

describe('lock start, other freezes, certificate, queries (QA minors)', function () {
    it('excludes the gap between the trial end and a late lock tick (scheduler down 3 h)', function () {
        pcAt(PC_BORN);
        [, , $pet] = pcFamily();

        pcAt('2026-10-14 13:00:00'); // trial ended at 10:00, first tick only now
        pcLockDue();
        $locked = $pet->fresh();
        expect($locked->payment_locked_at->toIso8601String())->toBe('2026-10-14T10:00:00+00:00')
            ->and(PetStatusPeriod::where('pet_id', $pet->id)->where('kind', 'payment_lock')->sole()->started_at->toIso8601String())->toBe('2026-10-14T10:00:00+00:00')
            ->and($locked->programSecondsAt(now()))->toBe(7 * 86400);

        pcAt('2026-10-24 10:00:00');
        pcAdminUnlock($pet);
        expect($pet->fresh()->programSecondsPausedBefore(now()))->toBe(10 * 86400);
    });

    it('still counts illness and inactive time', function () {
        pcAt(PC_BORN);
        [, , $pet] = pcFamily('grandfathered');

        pcAt('2026-10-10 10:00:00');
        $pet->fresh()->forceFill(['illness_until' => now()->addHours(12), 'frozen_at' => now()])->save();
        pcAt('2026-10-12 10:00:00');
        $pet->fresh()->forceFill(['is_active' => false])->save();
        pcAt('2026-10-20 10:00:00');
        $pet->fresh()->forceFill(['is_active' => true])->save();

        expect(PetStatusPeriod::where('pet_id', $pet->id)->pluck('kind')->map->value->sort()->values()->all())->toBe(['illness', 'inactive']);

        pcAt('2026-10-28 12:00:00'); // three real weeks
        expect($pet->fresh()->virtualAgeInMonths())->toBe(3)
            ->and(pcAge($pet))->toBe(5)
            ->and($pet->fresh()->programSecondsPausedBefore(now()))->toBe(0);
    });

    it('broadcasts the paused clock in PetUpdated after the lock and after the payment', function () {
        Event::fake([PetUpdated::class]);
        pcAt(PC_BORN);
        [, , $pet] = pcFamily();

        pcAt('2026-10-14 10:00:00');
        pcLockDue();
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->petId === $pet->id
            && $e->eventType === 'payment_required' && $e->payload['virtual_age_months'] === 1 && $e->payload['age_months'] === 3);

        pcAt('2026-11-04 10:00:00'); // three weeks locked
        pcAdminUnlock($pet);
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->petId === $pet->id
            && $e->eventType === 'challenge_paid' && $e->payload['virtual_age_months'] === 1 && $e->payload['age_months'] === 3);

        pcAt('2026-11-12 10:00:00'); // 15 program days
        $payload = PetUpdated::payloadFor($pet->fresh());
        expect($payload['virtual_age_months'])->toBe(2)
            ->and($payload['age_months'])->toBe(4);
    });

    it('delays certificate eligibility in the decay tick by the locked time', function () {
        pcAt(PC_BORN);
        [, , $pet] = pcFamily();
        $decay = app(PetDecayService::class);

        pcAt('2026-10-14 10:00:00');
        pcLockDue();
        pcAt('2026-10-24 10:00:00');
        pcAdminUnlock($pet);

        pcAt('2026-12-30 10:00:00'); // 12 real weeks
        $decay->processPetDecay($pet->fresh());
        expect($pet->fresh()->certificate_eligible)->toBeFalse();

        pcAt('2027-01-09 09:59:00');
        $decay->processPetDecay($pet->fresh());
        expect($pet->fresh()->certificate_eligible)->toBeFalse();

        pcAt('2027-01-09 10:00:00'); // 12 program weeks
        $decay->processPetDecay($pet->fresh());
        expect($pet->fresh()->certificate_eligible)->toBeTrue();
    });

    it('reads no lock history for a pet that provably was never locked', function () {
        pcAt(PC_BORN);
        [, , $trial] = pcFamily();
        [, , $free] = pcFamily('free');
        [, , $paid] = pcFamily('grandfathered');
        pcAt('2026-10-10 10:00:00');

        DB::enableQueryLog();
        $trial->fresh()->virtualAgeInMonths();
        $free->fresh()->virtualAgeInMonths();
        $periodQueries = fn () => collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'pet_status_periods'))->count();
        expect($periodQueries())->toBe(0);

        // A paid pet may have past locks: one query, memoised per instance.
        $p = $paid->fresh();
        $p->virtualAgeInMonths();
        $p->ageMonths();
        expect($periodQueries())->toBe(1);
        DB::disableQueryLog();
    });
});

describe('API payloads', function () {
    it('shows the paused clock in the child state and the parent dashboard', function () {
        pcAt(PC_BORN);
        [$parent, $child, $pet] = pcFamily();

        pcAt('2026-10-14 10:00:00');
        pcLockDue();

        // Still locked three weeks later: week and age stand.
        pcAt('2026-11-04 10:00:00');
        actingAsRole($child);
        getJson('/api/child/pet')->assertOk()
            ->assertJsonPath('lock.reason', 'payment_required')
            ->assertJsonPath('pet.virtual_age_months', 1)
            ->assertJsonPath('pet.age_months', 3);

        app('auth')->forgetGuards();
        actingAsRole($parent);
        getJson('/api/parent/dashboard')->assertOk()
            ->assertJsonPath('pet.virtual_age_months', 1)
            ->assertJsonPath('family.pets.0.profile.age_months', 3)
            ->assertJsonPath('family.children.0.progress.days_elapsed', 7)
            ->assertJsonPath('family.children.0.progress.week', 2);

        // Paid on 11-04: resumes where it stopped. 11-12 = 36 real days, 15 program days.
        pcAdminUnlock($pet);
        pcAt('2026-11-12 10:00:00');

        app('auth')->forgetGuards();
        actingAsRole($child);
        getJson('/api/child/pet')->assertOk()
            ->assertJsonPath('lock.reason', null)
            ->assertJsonPath('pet.virtual_age_months', 2)
            ->assertJsonPath('pet.age_months', 4);

        app('auth')->forgetGuards();
        actingAsRole($parent);
        getJson('/api/parent/dashboard')->assertOk()
            ->assertJsonPath('pet.virtual_age_months', 2)
            ->assertJsonPath('family.pets.0.profile.age_months', 4)
            ->assertJsonPath('family.children.0.progress.days_elapsed', 15)
            ->assertJsonPath('family.children.0.progress.week', 3)
            ->assertJsonPath('family.children.0.progress.completed', false);
    });
});
