<?php

use App\Enums\BreedType;
use App\Enums\StageParamKey;
use App\Models\BreedConfig;
use App\Models\BreedStageParam;
use App\Models\Pet;
use App\Models\User;
use App\Services\BehaviourEventService;
use App\Services\CareScheduleService;
use App\Services\CatChoreService;
use App\Services\LifeStageService;
use App\Services\LitterService;
use App\Services\RoutineLedgerService;
use App\Services\WandPlayService;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| M5-T05 — care guide "Kako skrbim za …" (APP_REFINEMENT_SPEC §5)
|--------------------------------------------------------------------------
| `care_guide` in GET /api/child/pet carries only numbers + keys, read from
| the same services the simulation uses. These tests compare every number
| with the service that enforces it and change a setting to prove the guide
| follows it (it can never drift from the rules).
*/

afterEach(function () {
    Carbon::setTestNow();
});

function cgAt(string $local): void
{
    Carbon::setTestNow(Carbon::parse($local, 'Europe/Ljubljana')->utc());
}

/**
 * @param  array<string, mixed>  $attributes
 * @param  array<string, mixed>  $quiet
 * @return array{0: User, 1: User, 2: Pet}
 */
function cgFamily(BreedType $breed, int $arrival, array $attributes = [], array $quiet = ['bedtime_start' => '21:00', 'bedtime_end' => '07:00']): array
{
    seedLifeStageData();
    cgAt('2026-10-20 08:00');
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => 'Mia']);
    setQuietHours(array_merge(['parent_id' => $parent->id], $quiet));
    if ($breed !== BreedType::Mutt && $breed !== BreedType::DomesticCat) {
        $attributes += ['plan' => 'challenge', 'challenge_paid_at' => now(), 'challenge_paid_source' => 'purchase'];
    }
    $pet = disableHygieneEvents(Pet::factory()->create(array_merge([
        'breed_type' => $breed->value,
        'user_id' => $child->id,
        'born_at' => now(),
        'last_decay_at' => now(),
        'last_step_reset_at' => now(),
        'arrival_age_months' => $arrival,
        'origin' => 'adopted',
    ], $attributes)));

    return [$parent, $child, $pet->fresh()];
}

function cgGuide(User $child): array
{
    test()->actingAs($child, 'sanctum');
    $json = test()->getJson('/api/child/pet')->assertOk()->json();
    app('auth')->forgetGuards();

    return $json['care_guide'];
}

/** Multiply every stage row of $key for the breed (an admin edit — Filament wins over the seeder). */
function cgEditParam(BreedType $breed, StageParamKey $key, callable $change): void
{
    $slug = $breed->slug();
    foreach (BreedStageParam::where('breed_slug', $slug)->where('key', $key->value)->get() as $row) {
        $row->update(['value' => $change($row->value)]);
    }
    LifeStageService::forgetBreed($slug);
}

describe('dog', function () {
    it('takes meals, steps, water, grooming and sleep from the simulation rules', function () {
        [, $child, $pet] = cgFamily(BreedType::PembrokeWelshCorgi, 12, ['grooming_enabled' => true], [
            'bedtime_start' => '21:00', 'bedtime_end' => '07:00', 'school_start' => '08:00', 'school_end' => '13:00',
        ]);
        cgAt('2026-10-20 14:00');
        $guide = cgGuide($child);
        $pet = $pet->fresh();
        $today = $pet->localDate(now());
        $rules = app(LifeStageService::class)->rulesOn($pet, $today);
        $config = $pet->breedConfig();
        $schedule = app(CareScheduleService::class);
        $windows = $schedule->feedWindowsStartingOn($pet, $config, $today);

        expect($guide['species'])->toBe('dog')
            ->and($guide['life_stage'])->toBe($rules->lifeStage->value)
            ->and($guide['meals']['per_day'])->toBe(count($windows))
            ->and(array_map(fn ($w) => $w['start'].'-'.$w['end'], $guide['meals']['windows']))
            ->toBe(array_map(fn ($w) => $w[0]->format('H:i').'-'.$w[1]->format('H:i'), $windows))
            ->and($guide['meals']['by_child'] + $guide['meals']['by_parent'])->toBe(count($windows))
            ->and($guide['steps']['goal'])->toBe($rules->stepGoal)
            ->and($guide['steps']['exercise_minutes'])->toBe($rules->exerciseMinutes)
            ->and($guide['water'])->toBe([
                'refill_below' => CareScheduleService::refillBelow($config),
                'min_gap_minutes' => (int) $config->water_min_gap_minutes,
            ])
            ->and($guide['grooming'])->toBe(['per_week' => app(CatChoreService::class)->groomingGoalOn($pet, $today)])
            ->and($guide['grooming']['per_week'])->toBeGreaterThan(0)
            ->and($guide['sleep'])->toBe(['night' => ['start' => '21:00', 'end' => '07:00'], 'school' => ['start' => '08:00', 'end' => '13:00']])
            ->and($guide['take_out'])->toBeNull() // not a puppy
            ->and($guide['play'])->toBeNull()
            ->and($guide['litter'])->toBeNull()
            ->and($guide['scratching'])->toBeFalse()
            ->and($guide['clean_within_hours'])->toBe(intdiv(RoutineLedgerService::CLEAN_WITHIN_SECONDS, 3600));
    });

    it('follows an edit of the stage params (steps, grooming) — never a hard-coded number', function () {
        [, $child] = cgFamily(BreedType::PembrokeWelshCorgi, 12, ['grooming_enabled' => true]);
        cgAt('2026-10-20 14:00');
        $before = cgGuide($child);

        cgEditParam(BreedType::PembrokeWelshCorgi, StageParamKey::ExerciseMinutesPerDay, fn ($v) => max(1, intdiv((int) $v, 2)));
        cgEditParam(BreedType::PembrokeWelshCorgi, StageParamKey::GroomingSessionsPerWeek, fn ($v) => $v + 1);
        $after = cgGuide($child);

        expect($after['grooming']['per_week'])->toBe($before['grooming']['per_week'] + 1)
            ->and($after['steps']['exercise_minutes'])->toBeLessThan($before['steps']['exercise_minutes'])
            ->and($after['steps']['goal'])->toBeLessThan($before['steps']['goal']);
        test()->actingAs($child, 'sanctum');
        expect(test()->getJson('/api/child/pet')->json('steps.goal'))->toBe($after['steps']['goal']);
    });

    it('follows the breed water config', function () {
        [, $child, $pet] = cgFamily(BreedType::PembrokeWelshCorgi, 12);
        BreedConfig::where('breed_slug', $pet->breed_type->slug())->update(['water_refill_below' => 45, 'water_min_gap_minutes' => 150]);

        expect(cgGuide($child)['water'])->toBe(['refill_below' => 45, 'min_gap_minutes' => 150]);
    });

    it('gives a puppy its take-out hold hours of today', function () {
        [, $child, $pet] = cgFamily(BreedType::Mutt, 2, ['behaviour_events_enabled' => true]);
        cgAt('2026-10-20 10:00');
        $guide = cgGuide($child);
        $hold = app(BehaviourEventService::class)->holdHoursOn($pet->fresh(), '2026-10-20');

        expect($hold)->toBeInt()
            ->and($guide['take_out'])->toBe(['hold_hours' => $hold])
            ->and($guide['life_stage'])->toBe('puppy');
    });

    it('follows the family quiet hours (sleep) and shows none when they are off', function () {
        [$parent, $child] = cgFamily(BreedType::Mutt, 12, [], ['bedtime_start' => '20:30', 'bedtime_end' => '06:45']);
        expect(cgGuide($child)['sleep'])->toBe(['night' => ['start' => '20:30', 'end' => '06:45'], 'school' => null]);

        setQuietHours(['parent_id' => $parent->id, 'is_active' => false]);
        expect(cgGuide($child)['sleep'])->toBe(['night' => null, 'school' => null]);
    });

    it('counts quiet-hour meals as the parent\'s, like the feed rules', function () {
        [, $child, $pet] = cgFamily(BreedType::Mutt, 2, [], ['bedtime_start' => '21:00', 'bedtime_end' => '07:00', 'school_start' => '08:00', 'school_end' => '13:00']);
        $guide = cgGuide($child);
        test()->actingAs($child, 'sanctum');
        $profile = test()->getJson('/api/child/pet')->json('pet.profile.today');

        expect($guide['meals']['by_parent'])->toBe($profile['meals_by_parent'])
            ->and($guide['meals']['by_child'])->toBe($profile['meals_by_child'])
            ->and(array_column($guide['meals']['windows'], 'parent_covered'))->toBe(array_column($profile['feed_windows'], 'parent_covered'));
    });

    it('has a digestion gap only for the breeds whose source asks for one', function () {
        [, $child] = cgFamily(BreedType::CaneCorso, 24);
        $gap = BreedStageParam::where('breed_slug', 'cane-corso')->where('key', StageParamKey::MealExerciseGapMinutes->value)->value('value');
        expect(cgGuide($child)['digestion_gap_minutes'])->toBe((int) json_decode((string) json_encode($gap)));

        [, $other] = cgFamily(BreedType::Mutt, 24);
        expect(cgGuide($other)['digestion_gap_minutes'])->toBeNull();
    });
});

describe('cat', function () {
    it('has wand play, litter and scratching instead of steps and take-out', function () {
        [, $child, $cat] = cgFamily(BreedType::MaineCoon, 12);
        cgAt('2026-10-20 14:00');
        $guide = cgGuide($child);
        $cat = $cat->fresh();
        $today = $cat->localDate(now());
        $wand = app(WandPlayService::class);
        $litter = app(LitterService::class);

        expect($guide['species'])->toBe('cat')
            ->and($guide['steps'])->toBeNull()
            ->and($guide['take_out'])->toBeNull()
            ->and($guide['play'])->toBe(['sessions_per_day' => $wand->goalOn($cat, $today), 'min_gap_minutes' => $wand->minGapMinutes($cat, $today)])
            ->and($guide['play']['sessions_per_day'])->toBeGreaterThan(0)
            ->and($guide['litter']['change_every_days'])->toBe($litter->changeDays($cat, $today))
            ->and((float) $guide['litter']['scoop_within_hours'])->toBe($litter->scoopDeadlineHours($cat, $today))
            ->and($guide['grooming'])->toBe(['per_week' => app(CatChoreService::class)->groomingGoalOn($cat, $today)])
            ->and($guide['scratching'])->toBeTrue()
            ->and($guide['digestion_gap_minutes'])->toBeNull();
    });

    it('follows an edit of the cat play params', function () {
        [, $child] = cgFamily(BreedType::DomesticCat, 12);
        $before = cgGuide($child)['play'];
        cgEditParam(BreedType::DomesticCat, StageParamKey::PlaySessionsPerDay, fn ($v) => $v + 1);

        expect(cgGuide($child)['play']['sessions_per_day'])->toBe($before['sessions_per_day'] + 1)
            ->and(cgGuide($child)['grooming'])->toBeNull(); // the domestic cat has no brushing routine
    });
});

/*
| M5-T05b — the same guide for the parent: GET /api/parent/dashboard carries the
| very `care_guide` block per pet (CareGuidePayload, same numbers as the child
| state); the app words it for the parent. Family-scoped like every parent GET.
*/
function cgParentGuide(User $parent, Pet $pet): ?array
{
    test()->actingAs($parent, 'sanctum');
    $pets = test()->getJson('/api/parent/dashboard')->assertOk()->json('family.pets') ?? [];
    app('auth')->forgetGuards();

    return collect($pets)->firstWhere('id', $pet->id)['care_guide'] ?? null;
}

describe('parent dashboard (M5-T05b)', function () {
    it('gives the parent exactly the child guide of a puppy (meals, steps, take-out, sleep)', function () {
        [$parent, $child, $pet] = cgFamily(BreedType::PembrokeWelshCorgi, 2, ['behaviour_events_enabled' => true, 'grooming_enabled' => true], [
            'bedtime_start' => '21:00', 'bedtime_end' => '07:00', 'school_start' => '08:00', 'school_end' => '13:00',
        ]);
        cgAt('2026-10-20 14:00');

        $parentGuide = cgParentGuide($parent, $pet);

        expect($parentGuide)->not->toBeNull()
            ->and($parentGuide)->toBe(cgGuide($child))
            ->and($parentGuide['take_out']['hold_hours'])->toBe(app(BehaviourEventService::class)->holdHoursOn($pet->fresh(), $pet->fresh()->localDate(now())))
            ->and($parentGuide['meals']['by_parent'])->toBeGreaterThanOrEqual(0)
            ->and($parentGuide['sleep']['school'])->toBe(['start' => '08:00', 'end' => '13:00']);
    });

    it('gives the parent exactly the child guide of a cat', function () {
        [$parent, $child, $cat] = cgFamily(BreedType::MaineCoon, 12);
        cgAt('2026-10-20 14:00');

        $parentGuide = cgParentGuide($parent, $cat);

        expect($parentGuide)->toBe(cgGuide($child))
            ->and($parentGuide['species'])->toBe('cat')
            ->and($parentGuide['play']['sessions_per_day'])->toBeGreaterThan(0);
    });

    it('follows a rule change on the next read, like the child guide', function () {
        [$parent, , $pet] = cgFamily(BreedType::PembrokeWelshCorgi, 12);
        BreedConfig::where('breed_slug', $pet->breed_type->slug())->update(['water_refill_below' => 45, 'water_min_gap_minutes' => 150]);

        expect(cgParentGuide($parent, $pet)['water'])->toBe(['refill_below' => 45, 'min_gap_minutes' => 150]);
    });

    it('never shows another family\'s pet (dashboard) and refuses its pet_id (404)', function () {
        [, , $pet] = cgFamily(BreedType::PembrokeWelshCorgi, 12);
        [$otherParent] = cgFamily(BreedType::Mutt, 12);

        expect(cgParentGuide($otherParent, $pet))->toBeNull();
        test()->actingAs($otherParent, 'sanctum');
        test()->getJson("/api/parent/activities?pet_id={$pet->id}")->assertNotFound();
    });
});
