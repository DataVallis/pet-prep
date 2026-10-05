<?php

use App\Enums\ActivityType;
use App\Enums\LifeStage;
use App\Enums\PetOrigin;
use App\Enums\RoutineType;
use App\Jobs\GeneratePetReferenceImage;
use App\Jobs\RegeneratePetStageMedia;
use App\Jobs\SubmitPetStateVideo;
use App\Models\ActivityLog;
use App\Models\BreedConfig;
use App\Models\Pet;
use App\Models\PetDailyWalk;
use App\Models\PetMedia;
use App\Models\PetMediaHistory;
use App\Models\QuietHours;
use App\Models\User;
use App\Services\CareScheduleService;
use App\Services\ChildProfileService;
use App\Services\DailyWalkService;
use App\Services\FalWebhookVerifier;
use App\Services\FamilyService;
use App\Services\LifeStageService;
use App\Services\Media\FalGateway;
use App\Services\Media\PetAppearancePrompt;
use App\Services\Media\PetMediaService;
use App\Services\PetDecayService;
use App\Services\RoutineLedgerService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| M5-R01 — pet profile (origin, age at arrival), life stages from sourced
| data, stage-dependent rules (meals / windows, step goal), meals in quiet
| hours done by the parent, stage transitions (rules at the next local
| midnight, new stage images once), prompts, backfill.
|--------------------------------------------------------------------------
| Family in Europe/Ljubljana (UTC+2 until 2026-10-25 03:00, then UTC+1).
*/

beforeEach(function () {
    seedLifeStageData();
    $this->withoutMiddleware([ThrottleRequests::class]);
});

function lsAt(string $utc): void
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
}

/**
 * A born pet of a child; quiet hours (school 08–13, bedtime 22–06) optional.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: User, 1: User, 2: Pet}
 */
function lsFamily(string $bornUtc, array $attributes = [], bool $quiet = false): array
{
    lsAt($bornUtc);
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
    $pet = disableHygieneEvents(Pet::factory()->mutt()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => Carbon::parse($bornUtc, 'UTC'),
        'arrival_age_months' => 2,
    ], $attributes)));

    return [$parent, $child, $pet->fresh()];
}

function lsRules(Pet $pet, string $date)
{
    return app(LifeStageService::class)->rulesOn($pet->fresh(), $date);
}

/** Feed windows starting on a local date as "HH:MM-HH:MM". */
function lsWindows(Pet $pet, string $date): array
{
    $pet = $pet->fresh();

    return array_map(
        fn (array $w): string => $w[0]->format('H:i').'-'.$w[1]->format('H:i'),
        app(CareScheduleService::class)->feedWindowsStartingOn($pet, $pet->breedConfig(), $date),
    );
}

/* ─────────────────────────── Age and stage ─────────────────────────── */

describe('age and life stage', function () {
    it('adds one month per week since birth to the age at arrival; an unborn pet is as old as it arrived', function () {
        [, , $pet] = lsFamily('2026-10-05 08:00:00', ['arrival_age_months' => 36]);
        $ages = app(LifeStageService::class);

        expect($ages->ageMonthsAt($pet, Carbon::parse('2026-10-12 07:59:59', 'UTC')))->toBe(36)
            ->and($ages->ageMonthsAt($pet, Carbon::parse('2026-10-12 08:00:00', 'UTC')))->toBe(37)
            // Wall clock: 10:00 local is 09:00 UTC in winter.
            ->and($ages->ageMonthsAt($pet, Carbon::parse('2026-12-28 08:59:59', 'UTC')))->toBe(36 + 11)
            ->and($ages->ageMonthsAt($pet, Carbon::parse('2026-12-28 09:00:00', 'UTC')))->toBe(36 + 12)
            ->and($pet->virtualAgeInMonths())->toBe(0); // challenge clock unchanged

        $unborn = Pet::factory()->unborn()->create(['user_id' => User::factory()->child()->create()->id, 'arrival_age_months' => 9]);
        expect($ages->ageMonthsAt($unborn, now()->addWeeks(5)))->toBe(9);
    });

    it('keeps the weekly birthday on the wall clock across the autumn DST change; rules switch at the next local midnight', function () {
        // Born Saturday 2026-10-24 18:00 CEST (16:00 UTC); clocks go back on the 25th.
        [, , $pet] = lsFamily('2026-10-24 16:00:00');
        $ages = app(LifeStageService::class);

        // 2026-10-31 18:00 CET = 17:00 UTC (a naive 168 h would say 16:00 UTC).
        expect($ages->ageMonthsAt($pet, Carbon::parse('2026-10-31 16:30:00', 'UTC')))->toBe(2)
            ->and($ages->ageMonthsAt($pet, Carbon::parse('2026-10-31 16:59:59', 'UTC')))->toBe(2)
            ->and($ages->ageMonthsAt($pet, Carbon::parse('2026-10-31 17:00:00', 'UTC')))->toBe(3)
            ->and(lsRules($pet, '2026-10-31')->ageMonths)->toBe(2)
            ->and(lsRules($pet, '2026-10-31')->mealsPerDay)->toBe(4)
            ->and(lsRules($pet, '2026-11-01')->ageMonths)->toBe(3)
            ->and(lsRules($pet, '2026-11-01')->mealsPerDay)->toBe(3);
    });

    it('resolves a birthday in the spring-forward gap after the jump', function () {
        // Born Sunday 2027-03-21 02:30 CET (01:30 UTC); 2027-03-28 02:30 does not exist → 03:30 CEST = 01:30 UTC.
        [, , $pet] = lsFamily('2027-03-21 01:30:00');
        $ages = app(LifeStageService::class);

        expect($ages->ageMonthsAt($pet, Carbon::parse('2027-03-28 01:29:59', 'UTC')))->toBe(2)
            ->and($ages->ageMonthsAt($pet, Carbon::parse('2027-03-28 01:30:00', 'UTC')))->toBe(3);
    });

    it('derives the stage from the sourced boundaries per breed (senior: mutt 108, Border Collie 118 months)', function () {
        $ages = app(LifeStageService::class);

        expect($ages->stageForAge('mutt', 2))->toBe(LifeStage::Puppy)
            ->and($ages->stageForAge('mutt', 8))->toBe(LifeStage::Puppy)
            ->and($ages->stageForAge('mutt', 9))->toBe(LifeStage::Young)
            ->and($ages->stageForAge('mutt', 35))->toBe(LifeStage::Young)
            ->and($ages->stageForAge('mutt', 36))->toBe(LifeStage::Adult)
            ->and($ages->stageForAge('mutt', 108))->toBe(LifeStage::Senior)
            ->and($ages->stageForAge('border-collie', 108))->toBe(LifeStage::Adult)
            ->and($ages->stageForAge('border-collie', 118))->toBe(LifeStage::Senior)
            ->and($ages->arrivalAgeFor('mutt', LifeStage::Puppy))->toBe(2)
            ->and($ages->arrivalAgeFor('mutt', LifeStage::Young))->toBe(9)
            ->and($ages->arrivalAgeFor('mutt', LifeStage::Adult))->toBe(36)
            ->and($ages->arrivalAgeFor('mutt', LifeStage::Senior))->toBe(108)
            ->and($ages->arrivalAgeFor('border-collie', LifeStage::Senior))->toBe(118);
    });

    it('a 12-week puppy challenge goes puppy → young in week 8 (next_stage date)', function () {
        [, , $pet] = lsFamily('2026-10-05 08:00:00'); // Monday 10:00 local

        $next = app(LifeStageService::class)->nextTransition($pet, now());
        // 7 weeks → age 9 on Monday 2026-11-23 10:00 → rules from Tuesday 2026-11-24.
        expect($next)->toBe(['stage' => LifeStage::Young, 'date' => '2026-11-24'])
            ->and(lsRules($pet, '2026-11-23')->lifeStage)->toBe(LifeStage::Puppy)
            ->and(lsRules($pet, '2026-11-24')->lifeStage)->toBe(LifeStage::Young);
    });
});

/* ─────────────────────────── Rules per breed and stage ─────────────────────────── */

describe('rules per breed and stage', function () {
    it('derives meals, windows and step goals from the sourced data', function (string $breed, int $age, LifeStage $stage, int $meals, array $windows, int $steps) {
        [, , $pet] = lsFamily('2026-10-05 08:00:00', ['breed_type' => $breed, 'arrival_age_months' => $age]);
        $rules = lsRules($pet, '2026-10-05');

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->ageMonths)->toBe($age)
            ->and($rules->mealsPerDay)->toBe($meals)
            ->and($rules->feedWindows)->toBe($windows)
            ->and($rules->stepGoal)->toBe($steps);
    })->with([
        'mutt puppy 2 mo' => ['mutt', 2, LifeStage::Puppy, 4, [['07:00', '08:00'], ['11:00', '12:00'], ['15:00', '16:00'], ['19:00', '20:00']], 2000],
        'mutt puppy 3 mo' => ['mutt', 3, LifeStage::Puppy, 3, [['07:00', '08:00'], ['13:00', '14:00'], ['19:00', '20:00']], 3000],
        'mutt puppy 6 mo' => ['mutt', 6, LifeStage::Puppy, 2, [['06:00', '10:00'], ['17:00', '21:00']], 6000],
        'mutt young 9 mo' => ['mutt', 9, LifeStage::Young, 2, [['06:00', '10:00'], ['17:00', '21:00']], 6000],
        'mutt adult' => ['mutt', 36, LifeStage::Adult, 2, [['06:00', '10:00'], ['17:00', '21:00']], 6000],
        'mutt senior' => ['mutt', 108, LifeStage::Senior, 2, [['06:00', '10:00'], ['17:00', '21:00']], 4500],
        'BC puppy 2 mo' => ['border_collie', 2, LifeStage::Puppy, 4, [['07:00', '08:00'], ['11:00', '12:00'], ['15:00', '16:00'], ['19:00', '20:00']], 2000],
        'BC puppy 8 mo' => ['border_collie', 8, LifeStage::Puppy, 2, [['06:00', '10:00'], ['17:00', '21:00']], 8000],
        'BC young 9 mo' => ['border_collie', 9, LifeStage::Young, 2, [['06:00', '10:00'], ['17:00', '21:00']], 9000],
        'BC young 14 mo' => ['border_collie', 14, LifeStage::Young, 2, [['06:00', '10:00'], ['17:00', '21:00']], 12000],
        'BC adult' => ['border_collie', 36, LifeStage::Adult, 2, [['06:00', '10:00'], ['17:00', '21:00']], 12000],
        'BC senior' => ['border_collie', 118, LifeStage::Senior, 2, [['06:00', '10:00'], ['17:00', '21:00']], 9000],
    ]);

    it('caps the step goal per breed when breed_configs.daily_steps_cap is set (default none)', function () {
        [, , $pet] = lsFamily('2026-10-05 08:00:00', ['breed_type' => 'border_collie', 'arrival_age_months' => 36]);
        expect(BreedConfig::where('breed_slug', 'border-collie')->value('daily_steps_cap'))->toBeNull()
            ->and(lsRules($pet, '2026-10-05')->stepGoal)->toBe(12000);

        BreedConfig::where('breed_slug', 'border-collie')->update(['daily_steps_cap' => 10000]);
        expect(lsRules($pet, '2026-10-05')->stepGoal)->toBe(10000);
    });

    it('flags the rules as unverified while a used value is an UNSOURCED proposal', function () {
        [, , $pet] = lsFamily('2026-10-05 08:00:00');
        $rules = lsRules($pet, '2026-10-05');

        expect($rules->verified())->toBeFalse()
            ->and($rules->unverifiedKeys())->toContain('feed_windows', 'exercise_minutes_per_age_month')
            ->and($rules->provenance['meals_per_day'])->toBe(['source_id' => 'S18', 'verified' => true, 'confidence' => 'high']);
    });

    it('keeps the pre-M5 rules for a breed without life-stage data', function () {
        DB::table('breed_stage_params')->delete();
        Cache::flush();
        [, , $pet] = lsFamily('2026-10-05 08:00:00');
        $rules = lsRules($pet, '2026-10-05');

        expect($rules->lifeStage)->toBeNull()
            ->and($rules->feedWindows)->toBe([['06:00', '10:00'], ['17:00', '21:00']])
            ->and($rules->stepGoal)->toBe(4000);
    });
});

/* ─────────────────────────── Feeding by stage ─────────────────────────── */

describe('feed windows by stage', function () {
    it('feeds a 2-month puppy in its 4 windows and switches to 3 meals the day after the weekly birthday', function () {
        // Born Monday 2026-10-05 10:00 local; age 3 from Monday 2026-10-12 10:00 → 3 meals from Tuesday.
        [, $child, $pet] = lsFamily('2026-10-05 08:00:00');

        expect(lsWindows($pet, '2026-10-11'))->toBe(['07:00-08:00', '11:00-12:00', '15:00-16:00', '19:00-20:00'])
            ->and(lsWindows($pet, '2026-10-12'))->toBe(['07:00-08:00', '11:00-12:00', '15:00-16:00', '19:00-20:00'])
            ->and(lsWindows($pet, '2026-10-13'))->toBe(['07:00-08:00', '13:00-14:00', '19:00-20:00']);

        // Monday 11:30 local, already 3 months old since 10:00 — still Monday's rules.
        lsAt('2026-10-12 09:30:00');
        actingAsRole($child);
        postJson('/api/child/pet/feed')->assertOk();

        // Tuesday 11:30: no window any more → next is 13:00.
        lsAt('2026-10-13 09:30:00');
        postJson('/api/child/pet/feed')->assertStatus(422)
            ->assertJsonPath('reason', 'outside_feed_window')
            ->assertJsonPath('next_allowed_at', '2026-10-13T13:00:00+02:00');
        lsAt('2026-10-13 11:10:00');
        postJson('/api/child/pet/feed')->assertOk();
    });

    it('counts one feed routine per window of that day\'s stage (4 → 3 across the transition)', function () {
        [, , $pet] = lsFamily('2026-10-05 08:00:00');
        lsAt('2026-10-14 08:00:00');
        $routines = app(RoutineLedgerService::class)->routinesFor(collect([$pet->fresh()]), '2026-10-12', '2026-10-13')[$pet->id];
        $feeds = collect($routines)->filter(fn ($r) => $r->type === RoutineType::Feed)->groupBy(fn ($r) => $r->localDate)->map->count()->all();

        expect($feeds)->toBe(['2026-10-12' => 4, '2026-10-13' => 3]);
    });
});

describe('meals in quiet hours are done by the parent', function () {
    it('feeds the dog at the window start, logs parent_fed_pet and never expects the meal from the child', function () {
        // 2-month puppy, school 08–13: the 11–12 window is entirely quiet → parent.
        [$parent, $child, $pet] = lsFamily('2026-10-05 04:30:00', [], quiet: true);
        Pet::whereKey($pet->id)->update(['hunger_level' => 40.0]);
        $decay = app(PetDecayService::class);

        $parentMeals = fn () => ActivityLog::where('pet_id', $pet->id)->where('activity_type', ActivityType::ParentFedPet->value)
            ->orderBy('created_at')->get()->map(fn ($r) => $r->created_at->utc()->toDateTimeString())->all();

        // The first tick catches up the birth day: yesterday's 11:00 meal (09:00 UTC).
        lsAt('2026-10-06 08:58:00'); // 10:58 local
        $decay->processPetDecay($pet);
        expect($parentMeals())->toBe(['2026-10-05 09:00:00']);
        Pet::whereKey($pet->id)->update(['hunger_level' => 40.0]);

        lsAt('2026-10-06 09:00:30'); // 11:00:30 local
        $decay->processPetDecay($pet);
        $decay->processPetDecay($pet->fresh());
        $row = ActivityLog::where('pet_id', $pet->id)->where('activity_type', ActivityType::ParentFedPet->value)->latest('created_at')->first();
        expect($parentMeals())->toBe(['2026-10-05 09:00:00', '2026-10-06 09:00:00'])
            ->and($row->actor_user_id)->toBeNull()
            ->and($row->value)->toBe(40) // hunger shown before (quiet hours: ×0.10 decay)
            ->and($pet->fresh()->displayMetric('hunger_level'))->toBe(100);

        // The child can't feed again in that window.
        lsAt('2026-10-06 09:30:00');
        actingAsRole($child);
        postJson('/api/child/pet/feed')->assertStatus(422)->assertJsonPath('reason', 'already_fed_this_window');

        // Today: 3 feed routines for the child (07, 15, 19), none at 11.
        lsAt('2026-10-06 19:00:00');
        $feeds = collect(app(RoutineLedgerService::class)->routinesFor(collect([$pet->fresh()]), '2026-10-06', '2026-10-06')[$pet->id])
            ->filter(fn ($r) => $r->type === RoutineType::Feed)
            ->map(fn ($r) => $r->opensAt->setTimezone('Europe/Ljubljana')->format('H:i'))->values()->all();
        expect($feeds)->toBe(['07:00', '15:00', '19:00']);

        // The parent and the child see who covers which meal.
        app('auth')->forgetGuards();
        actingAsRole($child);
        $profile = getJson('/api/child/pet')->assertOk()->json('pet.profile');
        expect($profile['today']['meals_per_day'])->toBe(4)
            ->and($profile['today']['meals_by_parent'])->toBe(1)
            ->and($profile['today']['meals_by_child'])->toBe(3)
            ->and(collect($profile['today']['feed_windows'])->where('parent_covered', true)->pluck('start')->all())->toBe(['11:00']);
    });

    it('does not feed a frozen (hard-stopped) dog and expects nothing in a partly quiet window from the parent', function () {
        [, , $pet] = lsFamily('2026-10-05 04:30:00', ['arrival_age_months' => 3], quiet: true);
        // 3 meals: 07–08 (child), 13–14 (school ends 13 → child), 19–20 (child).
        lsAt('2026-10-06 10:59:00');
        app(PetDecayService::class)->processPetDecay($pet);
        lsAt('2026-10-06 11:30:00');
        app(PetDecayService::class)->processPetDecay($pet->fresh());
        expect(ActivityLog::where('pet_id', $pet->id)->where('activity_type', ActivityType::ParentFedPet->value)->count())->toBe(0);

        // 2-month puppy under a hard stop over the 11:00 window: no parent meal.
        [, , $frozen] = lsFamily('2026-10-05 04:30:00', [], quiet: true);
        lsAt('2026-10-06 08:58:00');
        app(PetDecayService::class)->processPetDecay($frozen);
        $frozen->fresh()->update(['is_hard_stopped' => true]);
        lsAt('2026-10-06 09:00:30');
        app(PetDecayService::class)->processPetDecay($frozen->fresh());
        expect(ActivityLog::where('pet_id', $frozen->id)->where('activity_type', ActivityType::ParentFedPet->value)
            ->where('created_at', '>=', '2026-10-06 00:00:00')->count())->toBe(0);
    });

    it('gives a parent-fed meal after a scheduler outage the same hunger as ticks in real time', function () {
        // Two 2-month puppies, school 08–13: the 11:00 window is the parent's.
        [, , $live] = lsFamily('2026-10-05 04:30:00', [], quiet: true);
        [, , $late] = lsFamily('2026-10-05 04:30:00', [], quiet: true);
        $decay = app(PetDecayService::class);

        lsAt('2026-10-05 08:00:00'); // 10:00 local — first tick starts the clocks
        foreach ([$live, $late] as $pet) {
            $decay->processPetDecay($pet->fresh());
            Pet::whereKey($pet->id)->update(['hunger_level' => 40.0]);
        }

        // $live ticks every 10 minutes until 14:00 local; $late misses everything until then.
        for ($t = Carbon::parse('2026-10-05 08:10:00', 'UTC'); $t->lte(Carbon::parse('2026-10-05 12:00:00', 'UTC')); $t->addMinutes(10)) {
            lsAt($t->toDateTimeString());
            $decay->processPetDecay($live->fresh());
        }
        $decay->processPetDecay($late->fresh());

        $meal = fn (Pet $pet) => ActivityLog::where('pet_id', $pet->id)->where('activity_type', ActivityType::ParentFedPet->value)->sole();
        // 10:00–11:00 quiet (×0.10), then 100 %, then 11–13 quiet + 13–14 normal: 100 − (0.2 + 1) × 8 = 90.4.
        expect((float) $late->fresh()->hunger_level)->toEqualWithDelta((float) $live->fresh()->hunger_level, 0.001)
            ->and((float) $late->fresh()->hunger_level)->toEqualWithDelta(90.4, 0.001)
            ->and($meal($late)->value)->toBe($meal($live)->value)
            ->and($meal($late)->created_at->utc()->toDateTimeString())->toBe('2026-10-05 09:00:00');
    });
});

/* ─────────────────────────── Step goal by stage ─────────────────────────── */

describe('step goal by stage', function () {
    it('computes energy from the stage goal and closes the day with that day\'s goal', function () {
        [, $child, $pet] = lsFamily('2026-10-05 04:30:00'); // mutt puppy 2 months → 2,000 steps

        lsAt('2026-10-06 10:00:00');
        actingAsRole($child);
        $state = postJson('/api/child/pet/steps', ['steps_today' => 1000, 'recorded_at' => '2026-10-06T12:00:00+02:00', 'source' => 'healthkit'])->assertOk()->json('state');
        expect($state['steps']['goal'])->toBe(2000)
            ->and($state['pet']['energy_level'])->toBe(50)
            ->and($state['pet']['profile']['today']['step_goal'])->toBe(2000);

        lsAt('2026-10-06 22:30:00'); // after local midnight
        $locked = $pet->fresh();
        app(DailyWalkService::class)->closeDayIfNeeded($locked, now(), allowIllness: false);
        $locked->save();
        expect(PetDailyWalk::where('pet_id', $pet->id)->where('local_date', '2026-10-06')->value('goal'))->toBe(2000);
    });
});

/* ─────────────────────────── Stage transitions ─────────────────────────── */

describe('stage transition', function () {
    it('writes the stage at the local midnight and queues the new stage images exactly once', function () {
        Queue::fake([RegeneratePetStageMedia::class]);
        // Born Monday 10:00 local, puppy at 2 → young (9) on Monday 2026-11-23 10:00 → rules Tuesday.
        [, , $pet] = lsFamily('2026-10-05 08:00:00', ['life_stage' => 'puppy']);
        $decay = app(PetDecayService::class);

        lsAt('2026-11-23 22:58:00'); // 23:58 Monday local
        $decay->processPetDecay($pet);
        expect($pet->fresh()->life_stage)->toBe(LifeStage::Puppy);

        lsAt('2026-11-23 23:00:30'); // 00:00:30 Tuesday
        $decay->processPetDecay($pet->fresh());
        lsAt('2026-11-23 23:01:30');
        $decay->processPetDecay($pet->fresh());

        expect($pet->fresh()->life_stage)->toBe(LifeStage::Young);
        Queue::assertPushed(RegeneratePetStageMedia::class, 1);
        Queue::assertPushed(RegeneratePetStageMedia::class, fn ($job) => $job->petId === $pet->id);
    });

    it('does not queue images for the first stage assignment of a backfilled pet', function () {
        Queue::fake([RegeneratePetStageMedia::class]);
        [, , $pet] = lsFamily('2026-10-05 08:00:00', ['life_stage' => null]);

        lsAt('2026-10-06 08:00:00');
        app(PetDecayService::class)->processPetDecay($pet);

        expect($pet->fresh()->life_stage)->toBe(LifeStage::Puppy);
        Queue::assertNotPushed(RegeneratePetStageMedia::class);
    });

    it('follows a backward stage change (admin moved a boundary) without new images', function () {
        Queue::fake([RegeneratePetStageMedia::class]);
        // A 2-month puppy stored as "young" (e.g. the young boundary was 2 and an admin set it back to 9).
        [, , $pet] = lsFamily('2026-10-05 08:00:00', ['life_stage' => 'young']);

        lsAt('2026-10-06 08:00:00');
        app(PetDecayService::class)->processPetDecay($pet);

        expect($pet->fresh()->life_stage)->toBe(LifeStage::Puppy);
        Queue::assertNotPushed(RegeneratePetStageMedia::class);
    });

    it('never gives a legacy-profile pet a stage, a transition or a next stage', function () {
        Queue::fake([RegeneratePetStageMedia::class]);
        [, , $pet] = lsFamily('2026-10-05 08:00:00', ['arrival_age_months' => null]);
        $decay = app(PetDecayService::class);

        foreach (['2026-10-06 08:00:00', '2026-11-30 08:00:00', '2027-03-01 08:00:00'] as $at) {
            lsAt($at);
            $decay->processPetDecay($pet->fresh());
        }

        $pet = $pet->fresh();
        expect($pet->life_stage)->toBeNull()
            ->and($pet->isLegacyProfile())->toBeTrue()
            ->and($pet->ageMonths())->toBeNull()
            ->and(app(LifeStageService::class)->nextTransition($pet, now()))->toBeNull()
            ->and(lsRules($pet, '2027-03-01')->lifeStage)->toBeNull()
            ->and(lsRules($pet, '2027-03-01')->stepGoal)->toBe(4000)
            ->and(lsWindows($pet, '2027-03-01'))->toBe(['06:00-10:00', '17:00-21:00']);
        Queue::assertNotPushed(RegeneratePetStageMedia::class);
    });
});

describe('stage images', function () {
    beforeEach(function () {
        Http::preventStrayRequests();
        Storage::fake('pet_media');
        Cache::forget(FalWebhookVerifier::CACHE_KEY);
        Cache::forget(FalGateway::BALANCE_FLAG_KEY);
        config([
            'services.fal_ai.key' => 'test-key',
            'app.url' => 'https://api.petprep.si',
            'media.reference_image_profile' => 'nano_banana_pro',
            'media.stage_edit_profile' => 'nano_banana_pro_edit',
            'media.budget.daily_usd' => 5.0,
            'media.budget.monthly_usd' => 50.0,
        ]);
    });

    function lsStoredImage(Pet $pet, string $stage): PetMedia
    {
        $path = "{$pet->id}/reference-g1.jpg";
        Storage::disk('pet_media')->put($path, "\xFF\xD8\xFF\xE0\x00\x10JFIF".str_repeat("\x00", 256));

        return PetMedia::create([
            'pet_id' => $pet->id, 'kind' => 'image', 'state' => null, 'status' => 'ready', 'generation' => 1,
            'storage_path' => $path, 'mime' => 'image/jpeg', 'bytes' => 266, 'profile' => 'nano_banana_pro', 'life_stage' => $stage,
        ]);
    }

    it('edits the previous reference image into the new stage, keeps the old one in the history and regenerates the videos', function () {
        Queue::fake([SubmitPetStateVideo::class]);
        Http::fake([
            'fal.run/fal-ai/nano-banana-pro/edit' => Http::response(['images' => [['url' => 'https://v3.fal.media/files/dog/young.jpg']], 'description' => '']),
            'v3.fal.media/files/dog/young.jpg' => Http::response("\xFF\xD8\xFF\xE0\x00\x10JFIF".str_repeat("\x01", 300), 200, ['Content-Type' => 'image/jpeg']),
        ]);
        [, , $pet] = lsFamily('2026-10-05 08:00:00', ['life_stage' => 'young', 'media_status' => 'ready']);
        $old = lsStoredImage($pet, 'puppy');

        (new RegeneratePetStageMedia($pet->id))->handle(app(PetMediaService::class));

        Http::assertSent(fn (Request $r) => $r->url() === 'https://fal.run/fal-ai/nano-banana-pro/edit'
            && str_starts_with($r['image_urls'][0], "https://api.petprep.si/api/media/{$old->id}?")
            && str_contains($r['prompt'], 'The same dog as in the reference image')
            && str_contains($r['prompt'], 'adolescent young dog')
            && $r['aspect_ratio'] === '9:16' && isset($r['seed']));
        Http::assertNotSent(fn (Request $r) => $r->url() === 'https://fal.run/fal-ai/nano-banana-pro');

        $image = PetMedia::where('pet_id', $pet->id)->images()->sole();
        $history = PetMediaHistory::where('pet_id', $pet->id)->sole();
        expect($image->life_stage)->toBe('young')
            ->and($image->generation)->toBe(2)
            ->and($image->status)->toBe('ready')
            ->and($image->storage_path)->not->toBe($old->storage_path)
            ->and($history->life_stage)->toBe('puppy')
            ->and($history->storage_path)->toBe($old->storage_path)
            ->and(Storage::disk('pet_media')->exists($old->storage_path))->toBeTrue()
            ->and(Storage::disk('pet_media')->exists($image->storage_path))->toBeTrue();
        // Videos follow the new image only for a born pet: idle + sleeping (mutt basic set).
        Queue::assertPushed(SubmitPetStateVideo::class, 2);

        // A second run is a no-op.
        expect(app(PetMediaService::class)->startStageTransition($pet->fresh()))->toBe('up_to_date');
    });

    it('falls back to text-to-image with the same seed and the stage cue when the edit profile is off', function () {
        Queue::fake([SubmitPetStateVideo::class]);
        config(['media.profiles.image.nano_banana_pro_edit.enabled' => false]);
        Http::fake([
            'fal.run/fal-ai/nano-banana-pro' => Http::response(['images' => [['url' => 'https://v3.fal.media/files/dog/senior.jpg']]]),
            'v3.fal.media/files/dog/senior.jpg' => Http::response("\xFF\xD8\xFF\xE0\x00\x10JFIF".str_repeat("\x02", 300), 200, ['Content-Type' => 'image/jpeg']),
        ]);
        [, , $pet] = lsFamily('2026-10-05 08:00:00', ['life_stage' => 'senior', 'arrival_age_months' => 108]);
        $pet->forceFill(['pet_dna' => ['version' => 2, 'seed' => 4242, 'breed' => 'mutt', 'traits' => ['size' => 'medium-sized', 'coat_color' => 'black']]])->save();
        lsStoredImage($pet, 'adult');

        app(PetMediaService::class)->startStageTransition($pet->fresh());

        Http::assertSent(fn (Request $r) => $r->url() === 'https://fal.run/fal-ai/nano-banana-pro'
            && str_contains($r['prompt'], 'greying muzzle') && str_contains($r['prompt'], 'medium-sized') && $r['seed'] === 4242);
    });

    it('waits for a running image instead of starting a second generation', function () {
        [, , $pet] = lsFamily('2026-10-05 08:00:00', ['life_stage' => 'young']);
        PetMedia::create(['pet_id' => $pet->id, 'kind' => 'image', 'status' => 'running', 'generation' => 1]);
        Queue::fake([GeneratePetReferenceImage::class]);

        expect(app(PetMediaService::class)->startStageTransition($pet->fresh()))->toBe('busy');
        Queue::assertNotPushed(GeneratePetReferenceImage::class);
    });

    it('does not grow the image backward when the stored image shows a later stage', function () {
        Queue::fake([GeneratePetReferenceImage::class]);
        [, , $pet] = lsFamily('2026-10-05 08:00:00', ['life_stage' => 'puppy']);
        lsStoredImage($pet, 'young');

        expect(app(PetMediaService::class)->startStageTransition($pet->fresh()))->toBe('up_to_date')
            ->and(PetMediaHistory::where('pet_id', $pet->id)->exists())->toBeFalse();
        Queue::assertNotPushed(GeneratePetReferenceImage::class);
    });

    it('re-queues a lost stage transition in the daily retry and the backfill — forward only, never for legacy pets', function () {
        Queue::fake([RegeneratePetStageMedia::class, GeneratePetReferenceImage::class]);
        [, , $behind] = lsFamily('2026-10-05 08:00:00', ['life_stage' => 'young']);
        lsStoredImage($behind, 'puppy');
        [, , $ahead] = lsFamily('2026-10-05 08:00:00', ['life_stage' => 'puppy']);
        lsStoredImage($ahead, 'young');
        [, , $legacy] = lsFamily('2026-10-05 08:00:00', ['arrival_age_months' => null]);
        lsStoredImage($legacy, 'puppy');
        [, , $unborn] = lsFamily('2026-10-05 08:00:00', ['life_stage' => 'young', 'born_at' => null]);
        lsStoredImage($unborn, 'puppy');

        $this->artisan('media:retry')->assertSuccessful()
            ->expectsOutputToContain('1 life-stage image(s)');

        Queue::assertPushed(RegeneratePetStageMedia::class, 1);
        Queue::assertPushed(RegeneratePetStageMedia::class, fn ($job) => $job->petId === $behind->id);

        $media = app(PetMediaService::class);
        expect($media->planMissing($behind->fresh()))->toMatchArray(['image' => 'stage', 'videos' => []])
            ->and($media->planMissing($ahead->fresh())['image'])->toBe('ok')
            ->and($media->planMissing($legacy->fresh())['image'])->toBe('ok');
        // The backfill queues it too (deduplicated by the job's unique lock while one is pending).
        expect($media->generateMissing($behind->fresh()))->toBe(['image' => true, 'videos' => 0]);
        Queue::assertPushed(RegeneratePetStageMedia::class, fn ($job) => $job->petId === $behind->id);
    });

    it('retries a stage image that failed on the budget (its slot still points at the archived image)', function () {
        Queue::fake([GeneratePetReferenceImage::class]);
        [, , $pet] = lsFamily('2026-10-05 08:00:00', ['life_stage' => 'young', 'media_status' => 'failed', 'media_error' => 'budget_daily']);
        $slot = lsStoredImage($pet, 'puppy');
        PetMediaHistory::create(['pet_id' => $pet->id, 'kind' => 'image', 'life_stage' => 'puppy', 'generation' => 1, 'storage_path' => $slot->storage_path]);
        $slot->update(['status' => 'failed', 'error_reason' => 'budget_daily', 'life_stage' => 'young', 'generation' => 2]);

        $this->artisan('media:retry')->assertSuccessful();

        Queue::assertPushed(GeneratePetReferenceImage::class, fn ($job) => $job->petId === $pet->id);
        expect($slot->fresh()->status)->toBe('pending')
            ->and($pet->fresh()->media_status)->toBe('pending');
    });
});

/* ─────────────────────────── Prompts ─────────────────────────── */

describe('appearance prompt by stage and origin', function () {
    it('describes the stage and the origin from pet data only — never a name', function (string $stage, string $origin, array $contains, array $absent) {
        [, $child, $pet] = lsFamily('2026-10-05 08:00:00', [
            'life_stage' => $stage,
            'origin' => $origin,
            'pet_dna' => ['version' => 2, 'seed' => 42, 'breed' => 'mutt', 'traits' => ['size' => 'medium-sized', 'coat_length' => 'short smooth', 'coat_color' => 'tan']],
        ]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->fresh());

        foreach ($contains as $text) {
            expect($prompt)->toContain($text);
        }
        foreach ($absent as $text) {
            expect($prompt)->not->toContain($text);
        }
        expect($prompt)->not->toContain($child->name)->not->toContain('Maja');
    })->with([
        'bought puppy' => ['puppy', 'bought', ['medium-sized', 'puppy proportions', 'big paws', 'fluffy puppy coat'], ['shelter', 'grey']],
        'adopted senior' => ['senior', 'adopted', ['greying muzzle', 'adopted dog from an animal shelter', 'Not sad', 'neutral'], ['puppy']],
        'adopted adult' => ['adult', 'adopted', ['fully grown adult', 'healthy, clean and well cared for', 'no kennel bars'], ['sad dog']],
    ]);

    it('asks the edit model to keep the identity at a stage change', function () {
        [, , $pet] = lsFamily('2026-10-05 08:00:00', ['origin' => 'adopted', 'pet_dna' => ['version' => 2, 'seed' => 1, 'breed' => 'mutt', 'traits' => ['coat_color' => 'black']]]);
        $prompt = app(PetAppearancePrompt::class)->stageEditPrompt($pet->fresh(), LifeStage::Young);

        expect($prompt)->toContain('same individual dog')->toContain('identical coat colours')->toContain('adolescent')->toContain('Not sad');
    });
});

/* ─────────────────────────── Pet creation ─────────────────────────── */

describe('pet creation with origin and age stage', function () {
    function lsCreateViaPin(User $parent, array $body): array
    {
        $child = app(ChildProfileService::class)->createChild($parent, 'Maja', null);
        actingAsRole($parent);
        $pin = postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body))->assertOk();

        app('auth')->forgetGuards();
        test()->withHeaders(['Authorization' => '']);
        $login = postJson('/api/child/pin-login', ['pin' => $pin->json('pin'), 'device_name' => 'Tablet'])->assertSuccessful();

        return [$pin->json(), $login->json(), $child];
    }

    it('creates an adopted adult mutt that arrives at 36 months', function () {
        lsAt('2026-10-05 08:00:00');
        $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);

        [$pin, $login] = lsCreateViaPin($parent, ['breed' => 'mutt', 'origin' => 'adopted', 'age_stage' => 'adult']);
        $pet = Pet::findOrFail($login['pet']['id']);

        expect($pin['pet_profile'])->toBe(['breed' => 'mutt', 'origin' => 'adopted', 'age_stage' => 'adult'])
            ->and($pet->origin)->toBe(PetOrigin::Adopted)
            ->and($pet->arrival_age_months)->toBe(36)
            ->and($pet->life_stage)->toBe(LifeStage::Adult)
            ->and($pet->isUnborn())->toBeTrue()
            ->and($login['pet']['profile']['origin'])->toBe('adopted')
            ->and($login['pet']['profile']['age_months'])->toBe(36)
            ->and($login['pet']['profile']['life_stage'])->toBe('adult')
            ->and($login['pet']['profile']['today']['meals_per_day'])->toBe(2)
            ->and($login['pet']['profile']['today']['step_goal'])->toBe(6000)
            ->and($login['pet']['profile']['next_stage'])->toBeNull();
    });

    it('creates a legacy-profile pet when no profile field is sent (old app builds keep the pre-M5 rules)', function () {
        lsAt('2026-10-05 08:00:00');
        $parent = User::factory()->parent()->create();

        [$pin, $login, $child] = lsCreateViaPin($parent, []);
        $pet = Pet::findOrFail($login['pet']['id']);

        expect($pin['pet_profile'])->toBeNull()
            ->and(DB::table('child_login_pins')->where('child_user_id', $child->id)->value('pet_options'))->toBeNull()
            ->and($pet->breed_type->value)->toBe('mutt')
            ->and($pet->isLegacyProfile())->toBeTrue()
            ->and($pet->origin)->toBeNull()
            ->and($pet->life_stage)->toBeNull()
            ->and($login['pet']['profile']['legacy'])->toBeTrue()
            ->and($login['pet']['profile']['today']['meals_per_day'])->toBe(2)
            ->and($login['pet']['profile']['today']['step_goal'])->toBe(4000);
    });

    it('creates a bought mutt puppy with stage rules when the full profile is sent (breed optional → mutt)', function () {
        lsAt('2026-10-05 08:00:00');
        $parent = User::factory()->parent()->create();

        [$pin, $login] = lsCreateViaPin($parent, ['origin' => 'bought', 'age_stage' => 'puppy']);
        $pet = Pet::findOrFail($login['pet']['id']);

        expect($pin['pet_profile'])->toBe(['breed' => 'mutt', 'origin' => 'bought', 'age_stage' => 'puppy'])
            ->and($pet->breed_type->value)->toBe('mutt')
            ->and($pet->origin)->toBe(PetOrigin::Bought)
            ->and($pet->arrival_age_months)->toBe(2)
            ->and($pet->life_stage)->toBe(LifeStage::Puppy)
            ->and($login['pet']['profile']['legacy'])->toBeFalse()
            ->and($login['pet']['profile']['today']['meals_per_day'])->toBe(4);
    });

    it('validates the choice: unknown values, profile with pet_id, premium breed', function () {
        $parent = User::factory()->parent()->create();
        $child = app(ChildProfileService::class)->createChild($parent, 'Luka', null);
        actingAsRole($parent);

        postJson('/api/parent/generate-pin', ['child_id' => $child->id, 'origin' => 'stolen', 'age_stage' => 'puppy'])->assertUnprocessable()->assertJsonValidationErrors('origin');
        postJson('/api/parent/generate-pin', ['child_id' => $child->id, 'origin' => 'bought', 'age_stage' => 'baby'])->assertUnprocessable()->assertJsonValidationErrors('age_stage');
        postJson('/api/parent/generate-pin', ['child_id' => $child->id, 'breed' => 'poodle', 'origin' => 'bought', 'age_stage' => 'puppy'])->assertUnprocessable()->assertJsonValidationErrors('breed');
        postJson('/api/parent/generate-pin', ['child_id' => $child->id, 'pet_id' => 5, 'origin' => 'bought', 'age_stage' => 'adult'])->assertUnprocessable()->assertJsonValidationErrors('age_stage');
        // All or nothing: any profile field requires origin AND age_stage.
        postJson('/api/parent/generate-pin', ['child_id' => $child->id, 'age_stage' => 'adult'])->assertUnprocessable()->assertJsonValidationErrors('origin')->assertJsonMissingValidationErrors('age_stage');
        postJson('/api/parent/generate-pin', ['child_id' => $child->id, 'origin' => 'adopted'])->assertUnprocessable()->assertJsonValidationErrors('age_stage');
        postJson('/api/parent/generate-pin', ['child_id' => $child->id, 'breed' => 'mutt'])->assertUnprocessable()->assertJsonValidationErrors(['origin', 'age_stage']);
        postJson('/api/parent/generate-pin', ['child_id' => $child->id, 'breed' => 'border_collie', 'origin' => 'bought', 'age_stage' => 'puppy'])->assertStatus(422)->assertJsonPath('reason', 'breed_locked');
        // Every age / origin is free for the mutt.
        postJson('/api/parent/generate-pin', ['child_id' => $child->id, 'breed' => 'mutt', 'origin' => 'adopted', 'age_stage' => 'senior'])->assertOk()
            ->assertJsonPath('pet_profile.age_stage', 'senior');
    });

    it('creates a legacy-profile pet from a PIN issued before M5-R01 (no profile on the PIN)', function () {
        lsAt('2026-10-05 08:00:00');
        $parent = User::factory()->parent()->create();
        $child = app(ChildProfileService::class)->createChild($parent, 'Maja', null);
        actingAsRole($parent);
        // A PIN row from before the deploy: no pet_options column value.
        $pin = postJson('/api/parent/generate-pin', ['child_id' => $child->id, 'origin' => 'bought', 'age_stage' => 'puppy'])->assertOk()->json('pin');
        DB::table('child_login_pins')->where('child_user_id', $child->id)->update(['pet_options' => null]);

        app('auth')->forgetGuards();
        test()->withHeaders(['Authorization' => '']);
        $login = postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet'])->assertSuccessful()->json();
        $pet = Pet::findOrFail($login['pet']['id']);

        expect($pet->isLegacyProfile())->toBeTrue()
            ->and($pet->origin)->toBeNull()
            ->and($pet->life_stage)->toBeNull()
            ->and($login['pet']['profile']['legacy'])->toBeTrue()
            ->and($login['pet']['profile']['age_months'])->toBeNull()
            ->and($login['pet']['profile']['today']['step_goal'])->toBe(4000);
    });

    it('refuses profile fields on the deprecated generate-pin without child_id', function () {
        $parent = User::factory()->parent()->create();
        actingAsRole($parent);

        postJson('/api/parent/generate-pin', ['age_stage' => 'adult'])->assertUnprocessable()->assertJsonValidationErrors('age_stage');
        postJson('/api/parent/generate-pin', ['breed' => 'mutt', 'origin' => 'adopted'])->assertUnprocessable()->assertJsonValidationErrors(['breed', 'origin']);
    });
});

/* ─────────────────────────── API + backfill ─────────────────────────── */

describe('profile in the API and backfill', function () {
    it('shows origin, age, stage and today\'s rules to the child and the parent', function () {
        [$parent, $child, $pet] = lsFamily('2026-10-05 08:00:00', ['origin' => 'adopted'], quiet: true);
        lsAt('2026-10-13 10:00:00'); // Tuesday, 1 week + 1 day → age 3
        actingAsRole($child);
        $state = getJson('/api/child/pet')->assertOk()->json();

        expect($state['pet']['age_months'])->toBe(3)
            ->and($state['pet']['virtual_age_months'])->toBe(1)
            ->and($state['pet']['origin'])->toBe('adopted')
            ->and($state['pet']['life_stage'])->toBe('puppy')
            ->and($state['pet']['profile']['today']['meals_per_day'])->toBe(3)
            ->and($state['pet']['profile']['today']['sleep_hours'])->toBe(['min' => 14, 'max' => 16])
            ->and($state['pet']['profile']['next_stage'])->toBe(['life_stage' => 'young', 'from_date' => '2026-11-24'])
            ->and($state['steps']['goal'])->toBe(3000)
            ->and($state['feeding']['windows'])->toBe([['start' => '07:00', 'end' => '08:00'], ['start' => '13:00', 'end' => '14:00'], ['start' => '19:00', 'end' => '20:00']]);

        app('auth')->forgetGuards();
        actingAsRole($parent);
        $dash = getJson('/api/parent/dashboard')->assertOk()->json();
        expect($dash['family']['pets'][0]['profile']['life_stage'])->toBe('puppy')
            ->and($dash['pet']['profile']['age_months'])->toBe(3)
            ->and(json_encode($dash['family']['pets'][0]['profile']))->not->toContain('Maja');
    });

    it('leaves pets created before the migration on a legacy profile (all profile columns null)', function () {
        $child = User::factory()->child()->create();
        $family = app(FamilyService::class)->ensureFamilyFor($child);
        $id = DB::table('pets')->insertGetId([
            'user_id' => $child->id, 'family_id' => $family->id, 'breed_type' => 'mutt', 'pet_state' => 'idle',
            'hunger_level' => 100, 'thirst_level' => 100, 'energy_level' => 100, 'hygiene_level' => 100,
            'born_at' => now(), 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $row = DB::table('pets')->where('id', $id)->first(['origin', 'arrival_age_months', 'life_stage']);

        expect($row->origin)->toBeNull()
            ->and($row->arrival_age_months)->toBeNull()
            ->and($row->life_stage)->toBeNull()
            ->and(Pet::findOrFail($id)->isLegacyProfile())->toBeTrue();
        // A stage needs a profile (DB CHECK).
        expect(fn () => DB::table('pets')->where('id', $id)->update(['life_stage' => 'puppy']))->toThrow(QueryException::class);
    });
});

/* ─────────────────────────── Grandfathering (PR #37 review B1) ─────────────────────────── */

describe('existing pets keep the pre-M5 rules', function () {
    it('a deploy (migration + stage data) changes neither today\'s live ledger nor closed routine / walk rows of an existing pet', function () {
        // Before the deploy: no life-stage data yet; the pet has no profile (as every pre-M5 pet).
        DB::table('breed_stage_params')->delete();
        Cache::flush();
        [$parent, $child, $pet] = lsFamily('2026-10-05 08:00:00', ['arrival_age_months' => null], quiet: true);
        $decay = app(PetDecayService::class);
        $ledger = app(RoutineLedgerService::class);

        // Two days of care: feeds at 06:30 / 17:30 local, steps on the 6th.
        foreach (['2026-10-06 04:30:00', '2026-10-06 15:30:00', '2026-10-07 04:30:00'] as $fedAt) {
            DB::table('activities_log')->insert(['pet_id' => $pet->id, 'actor_user_id' => $child->id, 'activity_type' => 'fed_pet', 'value' => 100, 'created_at' => $fedAt]);
        }
        for ($t = Carbon::parse('2026-10-05 09:00:00', 'UTC'); $t->lte(Carbon::parse('2026-10-07 08:00:00', 'UTC')); $t->addHour()) {
            lsAt($t->toDateTimeString());
            if ($t->toDateTimeString() === '2026-10-06 16:00:00') {
                actingAsRole($child);
                postJson('/api/child/pet/steps', ['steps_today' => 4500, 'recorded_at' => '2026-10-06T18:00:00+02:00', 'source' => 'healthkit'])->assertOk();
            }
            $decay->processPetDecay($pet->fresh());
            $ledger->closeDueDays(now());
        }

        // Wednesday 2026-10-07 10:00 local (school): snapshot.
        $routineRows = fn () => DB::table('pet_daily_routines')->where('pet_id', $pet->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $walkRows = fn () => DB::table('pet_daily_walks')->where('pet_id', $pet->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $today = fn () => collect($ledger->routinesFor(collect([$pet->fresh()]), '2026-10-07', '2026-10-07')[$pet->id])
            ->map(fn ($r) => [$r->type->value, $r->slot, $r->opensAt->toIso8601String(), $r->dueAt->toIso8601String(), $r->status->value, $r->goal])->all();
        $state = function () use ($child) {
            app('auth')->forgetGuards();
            actingAsRole($child);

            return getJson('/api/child/pet')->assertOk()->json();
        };

        $before = ['routines' => $routineRows(), 'walks' => $walkRows(), 'today' => $today(), 'state' => $state()];
        expect($before['routines'])->not->toBeEmpty()
            ->and($before['walks'])->not->toBeEmpty()
            ->and($before['state']['feeding']['windows'])->toBe([['start' => '06:00', 'end' => '10:00'], ['start' => '17:00', 'end' => '21:00']]);

        // The deploy: stage data arrives (BreedConfigsSeeder::run()), caches are cold, the tick runs.
        seedLifeStageData();
        Cache::flush();
        $decay->processPetDecay($pet->fresh());
        $ledger->closeDueDays(now());

        $after = ['routines' => $routineRows(), 'walks' => $walkRows(), 'today' => $today(), 'state' => $state()];
        expect($after['routines'])->toBe($before['routines'])
            ->and($after['walks'])->toBe($before['walks'])
            ->and($after['today'])->toBe($before['today'])
            ->and($after['state']['feeding'])->toBe($before['state']['feeding'])
            ->and($after['state']['steps']['goal'])->toBe(4000)
            ->and($after['state']['pet']['profile']['legacy'])->toBeTrue()
            ->and($after['state']['pet']['life_stage'])->toBeNull()
            ->and($after['state']['pet']['age_months'])->toBeNull()
            ->and($after['state']['pet']['origin'])->toBeNull();

        // Later: no puppy windows, no parent meal in the 11:00 school window, no stage, ever.
        foreach (['2026-10-07 09:30:00', '2026-10-08 08:00:00', '2026-12-01 08:00:00'] as $at) {
            lsAt($at);
            $decay->processPetDecay($pet->fresh());
            $ledger->closeDueDays(now());
        }
        expect(ActivityLog::where('pet_id', $pet->id)->where('activity_type', ActivityType::ParentFedPet->value)->exists())->toBeFalse()
            ->and($pet->fresh()->life_stage)->toBeNull()
            ->and(lsWindows($pet, '2026-12-01'))->toBe(['06:00-10:00', '17:00-21:00'])
            ->and(DB::table('pet_daily_walks')->where('pet_id', $pet->id)->pluck('goal')->unique()->values()->all())->toBe([4000]);
    });
});
