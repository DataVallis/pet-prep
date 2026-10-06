<?php

use App\Enums\ActivityType;
use App\Enums\HygieneEventKind;
use App\Enums\HygieneEventStatus;
use App\Enums\PetStateEnum;
use App\Enums\RoutineStatus;
use App\Enums\RoutineType;
use App\Enums\StageParamKey;
use App\Events\PetUpdated;
use App\Models\ActivityLog;
use App\Models\BreedStageParam;
use App\Models\Pet;
use App\Models\PetDailyRoutine;
use App\Models\PetHygieneEvent;
use App\Models\PetMedia;
use App\Models\QuietHours;
use App\Models\User;
use App\Services\BehaviourEventService;
use App\Services\BehaviourPayload;
use App\Services\CareScoreService;
use App\Services\LifeStageService;
use App\Services\Media\MediaEntitlementService;
use App\Services\Media\PetAppearancePrompt;
use App\Services\Media\PetMediaService;
use App\Services\PetDecayService;
use App\Services\Results\Routine;
use App\Services\RoutineLedgerService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| M5-R02 — behaviour events (David 2026-10-06): puppy accidents + "Pelji
| ven", chewing (teething / missed walk) + "Pospravi in daj igračo",
| scoring as clean routines, payload, premium behaviour videos.
|--------------------------------------------------------------------------
| Family in Europe/Ljubljana: UTC+2 until 2026-10-25 03:00 local, then
| UTC+1. Quiet hours (when on): school 08–13, bedtime 22–06 local
| = 06–11 and 20–04 UTC in October before the 25th.
*/

beforeEach(function () {
    seedLifeStageData();
});

function beAt(string $utc): void
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
}

/**
 * A born, non-legacy dog of a child (poops off, behaviour events ON).
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: User, 1: User, 2: Pet}
 */
function beFamily(string $bornUtc, int $arrivalMonths = 2, bool $quiet = true, array $attributes = []): array
{
    beAt($bornUtc);
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => 'Maja']);
    if ($quiet) {
        QuietHours::create([
            'parent_id' => $parent->id,
            'school_start' => '08:00', 'school_end' => '13:00',
            'bedtime_start' => '22:00', 'bedtime_end' => '06:00',
            'is_active' => true,
        ]);
    }
    $pet = Pet::factory()->mutt()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => Carbon::parse($bornUtc, 'UTC'),
        'last_decay_at' => Carbon::parse($bornUtc, 'UTC'),
        'last_step_reset_at' => Carbon::parse($bornUtc, 'UTC'),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes));
    // Random poops off: these tests are about behaviour events only.
    Pet::whereKey($pet->id)->update(['hygiene_scheduled_through' => '2999-12-31']);

    return [$parent, $child, $pet->fresh()];
}

function beTick(Pet $pet, string $utc): Pet
{
    beAt($utc);
    app(PetDecayService::class)->processPetDecay($pet);

    return $pet->fresh();
}

/** @return Collection<int, PetHygieneEvent> */
function beEvents(Pet $pet, ?HygieneEventKind $kind = null)
{
    return PetHygieneEvent::where('pet_id', $pet->id)
        ->when($kind !== null, fn ($q) => $q->where('kind', $kind->value))
        ->orderBy('scheduled_at')->get();
}

/** An open (applied, unresolved) mess planted at a fixed time. */
function bePlant(Pet $pet, HygieneEventKind $kind, string $atUtc): PetHygieneEvent
{
    $at = Carbon::parse($atUtc, 'UTC');

    return PetHygieneEvent::create([
        'pet_id' => $pet->id, 'kind' => $kind->value, 'local_date' => $pet->localDate($at),
        'scheduled_at' => $at, 'status' => HygieneEventStatus::Applied, 'resolved_at' => $at,
    ]);
}

/** @return list<Routine> */
function beCleanRoutines(Pet $pet, string $date): array
{
    $all = app(RoutineLedgerService::class)->routinesFor(collect([$pet->fresh()]), $date, $date)[$pet->id];

    return array_values(array_filter($all, fn (Routine $r) => $r->type === RoutineType::Clean));
}

function beChance(float $chance): void
{
    BreedStageParam::where('key', StageParamKey::ChewingChancePerDay->value)->update(['value' => json_encode($chance)]);
    foreach (['mutt', 'border-collie'] as $slug) {
        LifeStageService::forgetBreed($slug);
    }
}

// ──────────────────────────────────────────────────────────────
//  Puppy accidents + "Pelji ven"
// ──────────────────────────────────────────────────────────────

describe('bladder clock', function () {
    it('holds 1 hour per month of age: 2 months → 2 h, 3 months → 3 h, from the day after the weekly birthday', function () {
        [, , $two] = beFamily('2026-10-12 04:00:00', 2);
        [, , $three] = beFamily('2026-10-12 04:00:00', 3);
        $behaviour = app(BehaviourEventService::class);

        expect($behaviour->holdHoursOn($two, '2026-10-12'))->toBe(2)
            ->and($behaviour->holdHoursOn($three, '2026-10-12'))->toBe(3)
            // One real week = one month: 3 months on the day AFTER the birthday (rules at local midnight).
            ->and($behaviour->holdHoursOn($two, '2026-10-19'))->toBe(2)
            ->and($behaviour->holdHoursOn($two, '2026-10-20'))->toBe(3);
    });

    it('applies only to non-legacy puppies (legacy, young, adult → no clock)', function () {
        [, , $legacy] = beFamily('2026-10-12 04:00:00', 2, attributes: ['arrival_age_months' => null]);
        [, , $young] = beFamily('2026-10-12 04:00:00', 9);
        [, , $adult] = beFamily('2026-10-12 04:00:00', 36);
        $behaviour = app(BehaviourEventService::class);

        foreach ([$legacy, $young, $adult] as $pet) {
            expect($behaviour->holdHoursOn($pet, '2026-10-12'))->toBeNull()
                ->and($behaviour->pottyClock($pet, $pet->quietHours()))->toBeNull();
        }
    });

    it('counts only time outside quiet hours: a 06:30 take-out with school 08–13 is due at 13:30', function () {
        [, $child, $pet] = beFamily('2026-10-12 04:00:00', 2);  // born 06:00 local

        beAt('2026-10-12 04:30:00');                               // 06:30 local
        actingAsRole($child);
        postJson('/api/child/pet/take-out')->assertOk()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('state.behaviour.take_out.hold_hours', 2)
            ->assertJsonPath('state.behaviour.take_out.clock_started_at', '2026-10-12T06:30:00+02:00')
            // 1.5 h before school + 0.5 h after → 13:30 local.
            ->assertJsonPath('state.behaviour.take_out.next_due_at', '2026-10-12T13:30:00+02:00')
            ->assertJsonPath('state.behaviour.take_out.last_taken_out_at', '2026-10-12T06:30:00+02:00')
            ->assertJsonPath('state.behaviour.can_take_out', true);

        // Nothing during school, even though 2 real hours passed long ago.
        $pet = beTick($pet, '2026-10-12 11:29:00');
        expect(beEvents($pet, HygieneEventKind::Accident))->toHaveCount(0)
            ->and($pet->displayMetric('hygiene_level'))->toBe(100);

        $pet = beTick($pet, '2026-10-12 11:31:00');
        $accident = beEvents($pet, HygieneEventKind::Accident)->sole();
        expect($accident->scheduled_at->toDateTimeString())->toBe('2026-10-12 11:30:00')
            ->and($accident->status)->toBe(HygieneEventStatus::Applied)
            ->and($pet->displayMetric('hygiene_level'))->toBe(0)
            ->and($pet->hygiene_zero_since->toDateTimeString())->toBe('2026-10-12 11:30:00')
            // A dirty dog stays `sick` (old app builds know six states).
            ->and($pet->pet_state)->toBe(PetStateEnum::Sick);
        // Parent timeline: one system row at the accident time, no actor.
        $row = ActivityLog::where('pet_id', $pet->id)->where('activity_type', ActivityType::PetAccident->value)->sole();
        expect($row->actor_user_id)->toBeNull()
            ->and($row->created_at->toDateTimeString())->toBe('2026-10-12 11:30:00');
    });

    it('moves a due instant that lands on a quiet start to the end of the quiet stretch, and never creates accidents in quiet hours', function () {
        [, , $pet] = beFamily('2026-10-12 04:00:00', 2);  // clock 06:00 local, 2 h → 08:00 = school start

        expect(app(BehaviourEventService::class)->pottyClock($pet, $pet->quietHours())['due_at']->toDateTimeString())
            ->toBe('2026-10-12 11:00:00');                   // 13:00 local

        // Simulate the whole day and night in 10-minute ticks: accidents every 2 h of
        // non-quiet time from 13:00 (13, 15, 17, 19, 21 local), none in the night.
        for ($t = Carbon::parse('2026-10-12 04:10:00', 'UTC'); $t->lessThanOrEqualTo(Carbon::parse('2026-10-13 03:50:00', 'UTC')); $t->addMinutes(10)) {
            $pet = beTick($pet, $t->toDateTimeString());
        }

        $times = beEvents($pet, HygieneEventKind::Accident)->map(fn ($e) => $e->scheduled_at->toDateTimeString())->all();
        expect($times)->toBe([
            '2026-10-12 11:00:00', '2026-10-12 13:00:00', '2026-10-12 15:00:00',
            '2026-10-12 17:00:00', '2026-10-12 19:00:00',
        ]);
        $quiet = $pet->quietHours();
        foreach (beEvents($pet, HygieneEventKind::Accident) as $e) {
            expect($quiet->isQuietNow($e->scheduled_at))->toBeFalse();
        }
    });

    it('restarts the clock on take-out (any due accident first), and a repeat within a minute is unchanged', function () {
        [, $child, $pet] = beFamily('2026-10-12 04:00:00', 2, quiet: false);  // no quiet hours: due 08:00 local
        actingAsRole($child);

        beAt('2026-10-12 05:00:00');
        postJson('/api/child/pet/take-out')->assertOk()->assertJsonPath('status', 'accepted');
        beAt('2026-10-12 05:00:30');
        postJson('/api/child/pet/take-out')->assertOk()->assertJsonPath('status', 'unchanged');
        expect(ActivityLog::where('pet_id', $pet->id)->where('activity_type', 'took_out_pet')->count())->toBe(1);

        // Without the take-out the accident would have been at 06:00 UTC.
        $pet = beTick($pet, '2026-10-12 06:30:00');
        expect(beEvents($pet, HygieneEventKind::Accident))->toHaveCount(0);

        // Take-out at 07:30 after the due 07:00: the accident happened at 07:00 first.
        beAt('2026-10-12 07:30:00');
        postJson('/api/child/pet/take-out')->assertOk()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('state.pet.hygiene_level', 0)
            ->assertJsonPath('state.behaviour.active_events.0.kind', 'accident')
            ->assertJsonPath('state.behaviour.scene', 'accident')
            ->assertJsonPath('state.behaviour.take_out.next_due_at', '2026-10-12T11:30:00+02:00');
        expect(beEvents($pet, HygieneEventKind::Accident)->sole()->scheduled_at->toDateTimeString())->toBe('2026-10-12 07:00:00');
    });

    it('restarts the clock at the family-local midnight (the night is the parents\')', function () {
        [, , $pet] = beFamily('2026-10-12 17:00:00', 2, quiet: false);  // born 19:00 local

        // Local: 19:00 + 2 h = 21:00 → accident; 21:00 + 2 h = 23:00 → accident; then
        // 23:00 + 2 h would be 01:00, but the clock restarts at 00:00 → 02:00 (00:00 UTC).
        foreach (['2026-10-12 19:05:00', '2026-10-12 21:05:00', '2026-10-12 23:05:00', '2026-10-12 23:55:00', '2026-10-13 00:05:00'] as $t) {
            $pet = beTick($pet, $t);
        }
        expect(beEvents($pet, HygieneEventKind::Accident)->map(fn ($e) => $e->scheduled_at->toDateTimeString())->all())
            ->toBe(['2026-10-12 19:00:00', '2026-10-12 21:00:00', '2026-10-13 00:00:00']);
    });

    it('is DST safe: on 2026-10-25 (25-hour day) the clock follows the wall clock and real hours', function () {
        [, , $pet] = beFamily('2026-10-24 18:00:00', 2);  // 20:00 CEST, quiet 22–06 / 08–13

        // Night of the change: bedtime until 06:00 CET = 05:00 UTC; 2 h → 08:00 CET = school
        // start → 13:00 CET = 12:00 UTC (in summer the same wall time was 11:00 UTC).
        beAt('2026-10-25 05:00:00');
        expect(app(BehaviourEventService::class)->pottyClock($pet->fresh(), $pet->quietHours())['due_at']->toDateTimeString())
            ->toBe('2026-10-25 12:00:00');

        // Without quiet hours: born 23:00 CEST; 23:00 + 2 h runs over midnight, so the
        // clock restarts at 00:00 CEST (22:00 UTC on the 24th) → 00:00 UTC (02:00 CEST);
        // the next is 2 REAL hours later: 02:00 UTC = 03:00 CET (the clock went back at 03:00 CEST).
        [, , $plain] = beFamily('2026-10-24 21:00:00', 2, quiet: false);
        foreach (['2026-10-24 23:00:00', '2026-10-25 00:05:00', '2026-10-25 02:05:00'] as $t) {
            $plain = beTick($plain, $t);
        }
        expect(beEvents($plain, HygieneEventKind::Accident)->map(fn ($e) => $e->scheduled_at->toDateTimeString())->all())
            ->toBe(['2026-10-25 00:00:00', '2026-10-25 02:00:00'])
            ->and(beEvents($plain, HygieneEventKind::Accident)->map(fn ($e) => $e->local_date->toDateString())->unique()->all())
            ->toBe(['2026-10-25']);
    });
});

describe('no behaviour events', function () {
    it('never makes accidents or chewing for legacy, adult or unborn pets', function () {
        beChance(1.0);
        [, , $legacy] = beFamily('2026-10-12 04:00:00', 4, quiet: false, attributes: ['arrival_age_months' => null]);
        [, , $adult] = beFamily('2026-10-12 04:00:00', 36, quiet: false);
        [, , $unborn] = beFamily('2026-10-12 04:00:00', 4, quiet: false, attributes: ['born_at' => null]);

        foreach (['2026-10-12 10:00:00', '2026-10-12 21:00:00', '2026-10-13 10:00:00', '2026-10-13 21:00:00'] as $t) {
            beAt($t);
            app(PetDecayService::class)->processAllActivePets();
        }

        foreach ([$legacy, $adult, $unborn] as $pet) {
            expect(beEvents($pet))->toHaveCount(0)
                ->and($pet->fresh()->displayMetric('hygiene_level'))->toBe(100);
        }
    });

    it('refuses take-out for a dog that is not a (non-legacy) puppy, and 423 while locked or unborn', function () {
        [, $adultChild] = beFamily('2026-10-12 04:00:00', 36);
        actingAsRole($adultChild);
        postJson('/api/child/pet/take-out')->assertStatus(422)
            ->assertJsonPath('reason', 'take_out_not_needed')
            ->assertJsonPath('state.behaviour.take_out', null)
            ->assertJsonPath('state.behaviour.can_take_out', false);

        [, $unbornChild] = beFamily('2026-10-12 04:00:00', 2, attributes: ['born_at' => null]);
        actingAsRole($unbornChild);
        postJson('/api/child/pet/take-out')->assertStatus(423)->assertJsonPath('reason', 'contract_required');
        postJson('/api/child/pet/resolve-chewing')->assertStatus(423)->assertJsonPath('reason', 'contract_required');

        [, $child, $pet] = beFamily('2026-10-12 04:00:00', 2);
        $pet->update(['is_hard_stopped' => true]);
        actingAsRole($child);
        postJson('/api/child/pet/take-out')->assertStatus(423)->assertJsonPath('reason', 'hard_stopped');
        expect(ActivityLog::where('pet_id', $pet->id)->where('activity_type', 'took_out_pet')->exists())->toBeFalse();
    });

    it('makes no accident during a hard stop; the clock resumes from the end of the freeze', function () {
        [, , $pet] = beFamily('2026-10-12 11:00:00', 2, quiet: false);  // due 13:00 UTC

        $pet = beTick($pet, '2026-10-12 12:00:00');
        beAt('2026-10-12 12:30:00');
        Pet::find($pet->id)->update(['is_hard_stopped' => true]);
        foreach (['2026-10-12 13:00:00', '2026-10-12 13:30:00', '2026-10-12 14:00:00'] as $t) {
            $pet = beTick($pet, $t);
        }
        beAt('2026-10-12 14:00:00');
        Pet::find($pet->id)->update(['is_hard_stopped' => false]);
        $pet = beTick($pet, '2026-10-12 15:00:00');

        expect(beEvents($pet, HygieneEventKind::Accident))->toHaveCount(0)
            ->and($pet->displayMetric('hygiene_level'))->toBe(100);
        // Clock held at the last frozen tick (14:00) → due 16:00.
        $pet = beTick($pet, '2026-10-12 16:05:00');
        expect(beEvents($pet, HygieneEventKind::Accident)->sole()->scheduled_at->toDateTimeString())->toBe('2026-10-12 16:00:00');
    });
});

// ──────────────────────────────────────────────────────────────
//  Chewing
// ──────────────────────────────────────────────────────────────

describe('chewing', function () {
    it('draws the teething chance from a seeded RNG: deterministic per salt, about the configured rate', function () {
        [, , $pet] = beFamily('2026-10-12 04:00:00', 3);
        $a = useBehaviourSalt('salt-a');
        $hits = 0;
        $first = [];
        for ($d = 0; $d < 600; $d++) {
            $date = Carbon::parse('2027-01-01')->addDays($d)->toDateString();
            $roll = $a->randomizerFor($pet, $date)->nextFloat();
            $hits += $roll < 0.5 ? 1 : 0;
            if ($d < 30) {
                $first[] = $roll;
            }
        }
        expect($hits)->toBeGreaterThan(240)->toBeLessThan(360);

        $again = array_map(fn (int $d) => useBehaviourSalt('salt-a')->randomizerFor($pet, Carbon::parse('2027-01-01')->addDays($d)->toDateString())->nextFloat(), range(0, 29));
        $other = array_map(fn (int $d) => useBehaviourSalt('salt-b')->randomizerFor($pet, Carbon::parse('2027-01-01')->addDays($d)->toDateString())->nextFloat(), range(0, 29));
        expect($again)->toBe($first)->and($other)->not->toBe($first);
    });

    it('stores the teething chance as an unverified proposal (0.5) and gates on teething months 3–6', function () {
        $row = BreedStageParam::where('breed_slug', 'mutt')->where('key', StageParamKey::ChewingChancePerDay->value)->sole();
        expect($row->value)->toBe(0.5)->and($row->verified)->toBeFalse();

        [, , $two] = beFamily('2026-10-12 04:00:00', 2);
        [, , $three] = beFamily('2026-10-12 04:00:00', 3);
        [, , $five] = beFamily('2026-10-12 04:00:00', 5);
        $behaviour = app(BehaviourEventService::class);
        expect($behaviour->isTeethingOn($two, '2026-10-12'))->toBeFalse()
            ->and($behaviour->isTeethingOn($three, '2026-10-12'))->toBeTrue()
            ->and($behaviour->isTeethingOn($five, '2026-10-12'))->toBeTrue()
            ->and($behaviour->isTeethingOn($five, '2026-10-20'))->toBeFalse()   // 6 months from the day after the birthday
            ->and($behaviour->teethingChanceOn($three, '2026-10-12'))->toBe(0.5);
    });

    it('chews at most once per teething day at a random minute outside quiet hours (chance 1), never when not teething', function () {
        beChance(1.0);
        [, , $teething] = beFamily('2026-10-12 02:00:00', 3);
        [, , $baby] = beFamily('2026-10-12 02:00:00', 2);

        for ($t = Carbon::parse('2026-10-12 02:30:00', 'UTC'); $t->lessThanOrEqualTo(Carbon::parse('2026-10-14 21:30:00', 'UTC')); $t->addHour()) {
            beAt($t->toDateTimeString());
            foreach ([$teething, $baby] as $pet) {
                app(PetDecayService::class)->processPetDecay($pet);
                // Keep the bladder clock out of the way.
                Pet::whereKey($pet->id)->update(['potty_clock_started_at' => '2999-12-31 00:00:00']);
            }
        }

        $chewing = beEvents($teething, HygieneEventKind::Chewing);
        expect($chewing->map(fn ($e) => $e->local_date->toDateString())->all())->toBe(['2026-10-12', '2026-10-13', '2026-10-14']);
        foreach ($chewing as $e) {
            expect($teething->quietHours()->isQuietNow($e->scheduled_at))->toBeFalse();
        }
        // The 2-month puppy is not teething: nothing on its birth day or the next day.
        // (On the 14th it chews because nobody walked it on the 13th — the walk rule.)
        expect(beEvents($baby, HygieneEventKind::Chewing)->map(fn ($e) => $e->local_date->toDateString())->all())->toBe(['2026-10-14'])
            ->and(app(BehaviourEventService::class)->chewingReasonOn($baby->fresh(), '2026-10-14', now()))->toBe('walk_missed')
            ->and(ActivityLog::where('pet_id', $teething->id)->where('activity_type', 'pet_chewed')->count())
            ->toBe($chewing->where('status', HygieneEventStatus::Applied)->count());
    });

    it('chews once the day after a missed walk goal (any non-legacy dog), not after a reached goal', function () {
        beChance(0.0);
        [, , $lazy] = beFamily('2026-10-10 10:00:00', 36);   // adult mutt, goal 6,000
        [, , $walked] = beFamily('2026-10-10 10:00:00', 36);

        // 12 Oct: 1,000 steps vs 6,000 steps.
        beAt('2026-10-12 18:00:00');
        foreach ([[$lazy, 1000], [$walked, 6000]] as [$pet, $steps]) {
            Pet::whereKey($pet->id)->update([
                'daily_step_count' => $steps, 'energy_level' => min(100, $steps / 60),
                'last_step_reset_at' => Carbon::parse('2026-10-11 22:00:00', 'UTC'),
                'last_decay_at' => Carbon::parse('2026-10-12 18:00:00', 'UTC'),
                'behaviour_scheduled_through' => '2026-10-12',
            ]);
        }

        // Local midnight 13 Oct: the day closes, the chewing of the 13th is decided.
        beAt('2026-10-12 22:01:00');
        foreach ([$lazy, $walked] as $pet) {
            app(PetDecayService::class)->processPetDecay($pet);
        }

        $event = beEvents($lazy, HygieneEventKind::Chewing)->sole();
        expect($event->local_date->toDateString())->toBe('2026-10-13')
            ->and($event->status)->toBe(HygieneEventStatus::Pending)
            ->and($lazy->quietHours()->isQuietNow($event->scheduled_at))->toBeFalse()
            ->and(app(BehaviourEventService::class)->chewingReasonOn($lazy->fresh(), '2026-10-13', now()))->toBe('walk_missed')
            ->and(beEvents($walked, HygieneEventKind::Chewing))->toHaveCount(0);

        // It happens at its time: hygiene 0, timeline row.
        $lazy = beTick($lazy, $event->scheduled_at->copy()->addMinute()->toDateTimeString());
        expect($lazy->displayMetric('hygiene_level'))->toBe(0)
            ->and($event->fresh()->status)->toBe(HygieneEventStatus::Applied)
            ->and(ActivityLog::where('pet_id', $lazy->id)->where('activity_type', 'pet_chewed')->count())->toBe(1);
    });

    it('does not chew for a legacy dog after a missed walk', function () {
        [, , $legacy] = beFamily('2026-10-10 10:00:00', 36, attributes: ['arrival_age_months' => null]);
        beAt('2026-10-12 18:00:00');
        Pet::whereKey($legacy->id)->update([
            'daily_step_count' => 100, 'last_step_reset_at' => Carbon::parse('2026-10-11 22:00:00', 'UTC'),
            'last_decay_at' => Carbon::parse('2026-10-12 18:00:00', 'UTC'),
        ]);
        beTick($legacy, '2026-10-12 22:01:00');

        expect(beEvents($legacy, HygieneEventKind::Chewing))->toHaveCount(0);
    });
});

// ──────────────────────────────────────────────────────────────
//  Resolving + scoring
// ──────────────────────────────────────────────────────────────

describe('resolve flow and routines', function () {
    it('tidies up chewing with resolve-chewing: hygiene back, clean routine of kind chewing done by the child within 2 h', function () {
        [, $child, $pet] = beFamily('2026-10-12 04:00:00', 36);
        bePlant($pet, HygieneEventKind::Chewing, '2026-10-12 12:00:00');   // 14:00 local
        Pet::whereKey($pet->id)->update(['hygiene_level' => 0, 'hygiene_zero_since' => '2026-10-12 12:00:00', 'last_decay_at' => '2026-10-12 12:00:00']);
        actingAsRole($child);

        beAt('2026-10-12 12:30:00');
        getJson('/api/child/pet')->assertOk()
            ->assertJsonPath('behaviour.scene', 'chewing')
            ->assertJsonPath('behaviour.active_events.0.kind', 'chewing')
            ->assertJsonPath('behaviour.active_events.0.started_at', '2026-10-12T14:00:00+02:00')
            ->assertJsonPath('behaviour.active_events.0.due_at', '2026-10-12T16:00:00+02:00')
            ->assertJsonPath('behaviour.can_resolve_chewing', true)
            ->assertJsonPath('feeding.can_feed', false);

        // The cleaning game does not tidy up a chewed slipper.
        postJson('/api/child/pet/clean')->assertOk()->assertJsonPath('status', 'unchanged')
            ->assertJsonPath('state.pet.hygiene_level', 0);
        postJson('/api/child/pet/feed')->assertStatus(422)->assertJsonPath('reason', 'needs_cleaning');

        beAt('2026-10-12 13:00:00');
        postJson('/api/child/pet/resolve-chewing')->assertOk()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('state.pet.hygiene_level', 100)
            ->assertJsonPath('state.behaviour.active_events', [])
            ->assertJsonPath('state.behaviour.scene', null);
        postJson('/api/child/pet/resolve-chewing')->assertOk()->assertJsonPath('status', 'unchanged');

        $routine = collect(beCleanRoutines($pet, '2026-10-12'))->sole();
        expect($routine->eventKind)->toBe(HygieneEventKind::Chewing)
            ->and($routine->status)->toBe(RoutineStatus::Done)
            ->and($routine->actorUserId)->toBe($child->id)
            ->and(ActivityLog::where('pet_id', $pet->id)->where('activity_type', 'resolved_chewing')->sole()->actor_user_id)->toBe($child->id);
    });

    it('counts an accident like a poop: cleaned in 2 h outside quiet hours = done; too late = missed; closed with its kind', function () {
        [, $child, $pet] = beFamily('2026-10-12 04:00:00', 2);
        $pet = beTick($pet, '2026-10-12 11:01:00');        // accident at 11:00 UTC (13:00 local)
        expect(beEvents($pet, HygieneEventKind::Accident))->toHaveCount(1);

        // 2 h outside quiet hours → due 13:00 UTC (15:00 local).
        $routine = collect(beCleanRoutines($pet, '2026-10-12'))->sole();
        expect($routine->dueAt->toDateTimeString())->toBe('2026-10-12 13:00:00')
            ->and($routine->status)->toBe(RoutineStatus::Pending)
            ->and($routine->eventKind)->toBe(HygieneEventKind::Accident);

        actingAsRole($child);
        beAt('2026-10-12 12:40:00');
        postJson('/api/child/pet/clean')->assertOk()->assertJsonPath('status', 'accepted')
            ->assertJsonPath('state.pet.hygiene_level', 100);
        $done = collect(beCleanRoutines($pet, '2026-10-12'))->first(fn (Routine $r) => $r->eventKind === HygieneEventKind::Accident);
        expect($done->status)->toBe(RoutineStatus::Done)->and($done->actorUserId)->toBe($child->id);

        // Next accident 2 h after the first (13:00 UTC); nobody cleans → missed after 15:00 UTC.
        $pet = beTick($pet, '2026-10-12 13:05:00');
        beAt('2026-10-12 15:30:00');
        $routines = collect(beCleanRoutines($pet, '2026-10-12'));
        expect($routines->pluck('status')->all())->toContain(RoutineStatus::Missed);

        // Closed days keep the kind.
        beAt('2026-10-14 03:00:00');
        app(RoutineLedgerService::class)->closePet($pet->id);
        expect(PetDailyRoutine::where('pet_id', $pet->id)->where('routine_type', 'clean')->pluck('event_kind')->map->value->unique()->all())
            ->toBe(['accident']);
        // Missed items carry the kind for the parent.
        $board = app(CareScoreService::class)->board(Pet::whereKey($pet->id)->get(), 'Europe/Ljubljana');
        $missed = collect($board['routines'][$pet->id])->first(fn (Routine $r) => $r->isMissed() && $r->type === RoutineType::Clean);
        expect(app(CareScoreService::class)->missedItem($board, $missed)['kind'])->toBe('accident');
    });

    it('keeps hygiene at 0 while another kind is still open (accident cleaned, chewing open)', function () {
        [, $child, $pet] = beFamily('2026-10-12 04:00:00', 3);
        bePlant($pet, HygieneEventKind::Accident, '2026-10-12 12:00:00');
        bePlant($pet, HygieneEventKind::Chewing, '2026-10-12 12:10:00');
        Pet::whereKey($pet->id)->update(['hygiene_level' => 0, 'hygiene_zero_since' => '2026-10-12 12:00:00', 'last_decay_at' => '2026-10-12 12:10:00', 'potty_clock_started_at' => '2026-10-12 12:00:00']);
        actingAsRole($child);

        beAt('2026-10-12 12:20:00');
        postJson('/api/child/pet/clean')->assertOk()->assertJsonPath('status', 'accepted')
            ->assertJsonPath('state.pet.hygiene_level', 0)
            ->assertJsonPath('state.behaviour.active_events.0.kind', 'chewing');
        postJson('/api/child/pet/resolve-chewing')->assertOk()->assertJsonPath('state.pet.hygiene_level', 100);
        expect(Pet::find($pet->id)->hygiene_zero_since)->toBeNull();
    });

    it('lets the vet close open messes at the end of an illness (no stale active events on a clean dog)', function () {
        [, , $pet] = beFamily('2026-10-12 04:00:00', 36);
        $event = bePlant($pet, HygieneEventKind::Chewing, '2026-10-12 05:00:00');
        Pet::whereKey($pet->id)->update(['hygiene_level' => 0, 'illness_until' => '2026-10-12 20:00:00', 'last_decay_at' => '2026-10-12 06:00:00']);

        $pet = beTick($pet, '2026-10-12 20:05:00');
        expect($pet->displayMetric('hygiene_level'))->toBe(100)
            ->and($event->fresh()->cleaned_at->toDateTimeString())->toBe('2026-10-12 20:00:00')
            ->and(BehaviourPayload::for($pet)->activeEvents)->toBe([]);
    });
});

// ──────────────────────────────────────────────────────────────
//  API contract, authz, broadcasts, parent dashboard
// ──────────────────────────────────────────────────────────────

describe('API', function () {
    it('answers 403 to a parent token and never touches another family\'s pet', function () {
        [$parent, , $pet] = beFamily('2026-10-12 04:00:00', 2);
        [, $otherChild, $otherPet] = beFamily('2026-10-12 04:00:00', 2);
        $before = $pet->fresh()->potty_clock_started_at;

        actingAsRole($parent);
        postJson('/api/child/pet/take-out')->assertStatus(403);
        postJson('/api/child/pet/resolve-chewing')->assertStatus(403);

        app('auth')->forgetGuards();
        actingAsRole($otherChild);
        beAt('2026-10-12 05:00:00');
        postJson('/api/child/pet/take-out')->assertOk()->assertJsonPath('state.pet.id', $otherPet->id);
        expect($pet->fresh()->potty_clock_started_at?->toDateTimeString())->toBe($before?->toDateTimeString())
            ->and(ActivityLog::where('pet_id', $pet->id)->where('activity_type', 'took_out_pet')->exists())->toBeFalse();
    });

    it('broadcasts once per take-out / resolve and carries the behaviour summary', function () {
        Event::fake([PetUpdated::class]);
        [, $child, $pet] = beFamily('2026-10-12 04:00:00', 3);
        actingAsRole($child);

        beAt('2026-10-12 04:30:00');
        postJson('/api/child/pet/take-out')->assertOk();
        Event::assertDispatchedTimes(PetUpdated::class, 1);
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->petId === $pet->id && $e->eventType === 'took_out_pet');

        $payload = PetUpdated::payloadFor($pet->fresh(), 'took_out_pet');
        expect($payload['behaviour']['take_out']['hold_hours'])->toBe(3)
            ->and($payload['behaviour']['active_events'])->toBe([])
            ->and(json_encode($payload))->not->toContain('Maja');
    });

    it('shows behaviour, take-outs and behaviour events to the parent', function () {
        [$parent, $child, $pet] = beFamily('2026-10-12 04:00:00', 2, quiet: false);
        actingAsRole($child);
        beAt('2026-10-12 04:30:00');
        postJson('/api/child/pet/take-out')->assertOk();
        beTick($pet, '2026-10-12 06:31:00');            // accident at 06:30

        app('auth')->forgetGuards();
        actingAsRole($parent);
        $res = getJson('/api/parent/dashboard')->assertOk();
        $petJson = collect($res->json('family.pets'))->firstWhere('id', $pet->id);
        expect($petJson['behaviour']['scene'])->toBe('accident')
            ->and($petJson['behaviour']['take_out']['hold_hours'])->toBe(2)
            ->and(collect($petJson['timeline'])->pluck('activity_type')->all())->toContain('took_out_pet', 'pet_accident')
            ->and(collect($petJson['timeline'])->firstWhere('activity_type', 'pet_accident')['is_positive'])->toBeFalse()
            ->and(collect($res->json('family.children'))->firstWhere('id', $child->id)['stats']['taken_out'])->toBe(1);
    });

    it('keeps the free mutt payload unchanged: media states idle + sleeping, pet_state one of the six', function () {
        [, $child, $pet] = beFamily('2026-10-12 04:00:00', 2);
        actingAsRole($child);

        getJson('/api/child/pet')->assertOk()
            ->assertJsonPath('pet.media.states', ['idle', 'sleeping'])
            ->assertJsonPath('pet.pet_state', 'idle')
            ->assertJsonPath('behaviour.take_out.hold_hours', 2)
            ->assertJsonPath('behaviour.active_events', [])
            ->assertJsonPath('behaviour.scene', null);
    });
});

// ──────────────────────────────────────────────────────────────
//  Premium behaviour videos
// ──────────────────────────────────────────────────────────────

describe('behaviour videos', function () {
    it('gives the premium set accident + chewing where the event can happen; free and legacy sets unchanged', function () {
        $entitlements = app(MediaEntitlementService::class);
        $states = fn (Pet $p) => array_map(fn (PetStateEnum $s) => $s->value, $entitlements->videoStatesFor($p));

        [, , $bcPuppy] = beFamily('2026-10-12 04:00:00', 2, attributes: ['breed_type' => 'border_collie', 'life_stage' => 'puppy']);
        [, , $bcYoung] = beFamily('2026-10-12 04:00:00', 9, attributes: ['breed_type' => 'border_collie', 'life_stage' => 'young']);
        [, , $bcLegacy] = beFamily('2026-10-12 04:00:00', 2, attributes: ['breed_type' => 'border_collie', 'arrival_age_months' => null]);
        [, , $mutt] = beFamily('2026-10-12 04:00:00', 2, attributes: ['life_stage' => 'puppy']);

        expect($states($bcPuppy))->toBe(['idle', 'sleeping', 'low_energy', 'hungry', 'sick', 'playing', 'accident', 'chewing'])
            ->and($states($bcYoung))->toBe(['idle', 'sleeping', 'low_energy', 'hungry', 'sick', 'playing', 'chewing'])
            ->and($states($bcLegacy))->toBe(['idle', 'sleeping', 'low_energy', 'hungry', 'sick', 'playing'])
            ->and($states($mutt))->toBe(['idle', 'sleeping']);
    });

    it('plans the two new videos from the current stage image (generated once per stage, not per event)', function () {
        [, , $bc] = beFamily('2026-10-12 04:00:00', 2, attributes: ['breed_type' => 'border_collie', 'life_stage' => 'puppy']);
        PetMedia::create(['pet_id' => $bc->id, 'kind' => 'image', 'status' => 'ready', 'generation' => 1, 'life_stage' => 'puppy', 'storage_path' => $bc->id.'/reference-g1.jpg']);
        foreach (['idle', 'sleeping', 'low_energy', 'hungry', 'sick', 'playing'] as $state) {
            PetMedia::create(['pet_id' => $bc->id, 'kind' => 'video', 'state' => $state, 'status' => 'ready', 'generation' => 1, 'source_generation' => 1, 'storage_path' => "{$bc->id}/{$state}-g1.mp4"]);
        }

        expect(app(PetMediaService::class)->planMissing($bc->fresh())['videos'])->toBe(['accident', 'chewing']);

        // The two behaviour prompts describe the dog only — no people, no names.
        foreach ([PetStateEnum::Accident, PetStateEnum::Chewing] as $state) {
            $prompt = app(PetAppearancePrompt::class)->videoPrompt('border_collie', $state);
            expect($prompt)->toContain($state->promptModifier())->not->toContain('Maja');
        }
    });

    it('accepts the new states in the pet_media and pets CHECK constraints', function () {
        [, , $pet] = beFamily('2026-10-12 04:00:00', 2);
        PetMedia::create(['pet_id' => $pet->id, 'kind' => 'video', 'state' => 'chewing', 'status' => 'pending', 'generation' => 1]);
        DB::table('pets')->where('id', $pet->id)->update(['pet_state' => 'accident']);

        expect(PetMedia::where('pet_id', $pet->id)->where('state', 'chewing')->exists())->toBeTrue();
        expect(fn () => DB::table('pet_hygiene_events')->insert([
            'pet_id' => $pet->id, 'kind' => 'barking', 'local_date' => '2026-10-12',
            'scheduled_at' => now(), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });
});
