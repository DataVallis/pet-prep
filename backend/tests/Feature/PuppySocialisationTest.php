<?php

use App\Enums\BreedType;
use App\Enums\RoutineType;
use App\Enums\SocialisationExperience;
use App\Enums\StageParamKey;
use App\Events\PetUpdated;
use App\Models\ActivityLog;
use App\Models\Pet;
use App\Models\PetContract;
use App\Models\User;
use App\Services\ChildProfileService;
use App\Services\FamilyService;
use App\Services\LifeStageService;
use App\Services\RoutineLedgerService;
use App\Services\SocialisationService;
use Database\Seeders\BreedStageParamsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| M5-R17 — puppy socialisation (David 2026-10-12, "tedenska naloga")
|--------------------------------------------------------------------------
| While the dog is a puppy (game stage puppy, < 9 months; 1 program week =
| 1 dog month) the child gets one "new experience" per program week — a new
| person, another dog, a new sound, a new place — marked with one tap. The
| parent sees a weekly row in the report and a timeline entry. No penalty:
| never a routine, Care Score, traffic light or metric. Breeds whose
| data.json holds a verbatim A/B socialisation quote; gated per pet
| (`socialisation_enabled`, ClientFeature `socialisation`).
| Europe/Ljubljana, quiet hours 21–07, DST on 25 Oct 2026.
*/

afterEach(function () {
    Carbon::setTestNow();
});

function soAt(string $local): void
{
    Carbon::setTestNow(Carbon::parse($local, 'Europe/Ljubljana')->utc());
}

/**
 * A family with a profiled dog of $breed born at $bornLocal, arriving at $arrival months
 * (2 = a puppy: program weeks 0–6 are puppy weeks).
 *
 * @return array{0: User, 1: User, 2: Pet}
 */
function soFamily(BreedType $breed = BreedType::Rottweiler, bool $enabled = true, int $arrival = 2, string $bornLocal = '2026-10-20 08:00'): array
{
    seedLifeStageData();
    soAt($bornLocal);
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => 'Mia']);
    setQuietHours(['parent_id' => $parent->id, 'bedtime_start' => '21:00', 'bedtime_end' => '07:00']);
    $attributes = $breed === BreedType::Mutt ? [] : ['plan' => 'challenge', 'challenge_paid_at' => now(), 'challenge_paid_source' => 'purchase'];
    $dog = disableHygieneEvents(Pet::factory()->create(array_merge([
        'breed_type' => $breed->value,
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrival,
        'origin' => 'bought',
        'socialisation_enabled' => $enabled,
    ], $attributes)));

    return [$parent, $child, $dog->fresh()];
}

function soPost(User $child, string $experience)
{
    test()->actingAs($child, 'sanctum');
    $response = test()->postJson('/api/child/pet/socialisation', ['experience' => $experience]);
    app('auth')->forgetGuards();

    return $response;
}

function soState(User $child): array
{
    test()->actingAs($child, 'sanctum');
    $json = test()->getJson('/api/child/pet')->assertOk()->json();
    app('auth')->forgetGuards();

    return $json;
}

function soReport(User $parent, User $child, int $days = 7): array
{
    test()->actingAs($parent, 'sanctum');
    $json = test()->getJson("/api/parent/children/{$child->id}/report?days={$days}")->assertOk()->json();
    app('auth')->forgetGuards();

    return $json;
}

function soData(string $path): mixed
{
    static $data = null;
    $data ??= json_decode((string) file_get_contents(base_path('../docs/research/dog-data/data.json')), true, flags: JSON_THROW_ON_ERROR);
    $node = $data;
    foreach (explode('.', $path) as $segment) {
        if (! is_array($node) || ! array_key_exists($segment, $node)) {
            return null;
        }
        $node = $node[$segment];
    }

    return $node;
}

/** Create a pet through generate-pin + pin-login with the given features (like DogGroomingTest). */
function soCreateViaPin(array $body, ?array $childFeatures): Pet
{
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = app(ChildProfileService::class)->createChild($parent, 'Maja', null);
    app('auth')->forgetGuards();
    actingAsRole($parent);
    $pin = postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body))->assertOk();
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);
    $login = postJson('/api/child/pin-login', array_merge(
        ['pin' => $pin->json('pin'), 'device_name' => 'Tablet'],
        $childFeatures === null ? [] : ['features' => $childFeatures],
    ))->assertSuccessful();

    return Pet::findOrFail($login->json('pet.id'));
}

describe('breeds (verbatim A/B socialisation quote in data.json)', function () {
    it('seeds one puppy-stage row (1 per week, David\'s decision) per sourced breed, traced to data.json', function () {
        $breeds = ['labrador_retriever', 'german_shepherd', 'beagle', 'standard_poodle', 'australian_shepherd', 'havanese',
            'siberian_husky', 'rottweiler', 'bulldog', 'cane_corso', 'pembroke_welsh_corgi', 'dobermann'];
        $rows = collect(BreedStageParamsSeeder::rows())->where('key', StageParamKey::SocialisationTasksPerWeek->value);

        expect($rows->pluck('breed_slug')->sort()->values()->all())
            ->toBe(collect($breeds)->map(fn (string $b) => BreedType::from($b)->slug())->sort()->values()->all());

        foreach ($breeds as $breed) {
            $row = $rows->firstWhere('breed_slug', BreedType::from($breed)->slug());
            $entry = soData("proposed_game_parameters.{$breed}.socialisation");
            expect($row)->toMatchArray([
                'stage' => 'puppy', 'age_from_months' => 0, 'value' => 1, 'verified' => true,
                'ref' => "proposed_game_parameters.{$breed}.socialisation",
                'decision' => BreedStageParamsSeeder::CONFIRMED_R17_SOCIALISATION,
            ])
                ->and($entry['value'])->toBe(1)
                ->and($entry['source_id'])->toBe($row['source_id'])
                ->and($entry['quote'])->toBe($row['quote'])
                ->and($entry['decision'])->toContain('M5-R17')
                ->and(mb_strtolower($row['quote']))->toContain('socialis');
        }
    });

    it('never invents a quote: each one stands verbatim in the breed\'s own research entry', function () {
        $paths = [
            'labrador_retriever' => 'labrador_retriever.suitability.pdsa_family.quote',
            'german_shepherd' => 'german_shepherd.behaviour.guarding.quote',
            'beagle' => 'beagle.behaviour.family.quote',
            'standard_poodle' => 'standard_poodle.behaviour.family.quote',
            'australian_shepherd' => 'australian_shepherd.behaviour.other_pets.notes',
            'havanese' => 'havanese.behaviour.family.notes',
            'siberian_husky' => 'siberian_husky.behaviour.other_pets.notes',
            'rottweiler' => 'rottweiler.behaviour.experienced_owner.notes',
            'bulldog' => 'bulldog.behaviour.other_pets.quote',
            'cane_corso' => 'cane_corso.behaviour.socialisation.quote',
            'pembroke_welsh_corgi' => 'pembroke_welsh_corgi.behaviour.herding_children.notes',
            'dobermann' => 'dobermann.behaviour.socialisation.quote',
        ];
        foreach ($paths as $breed => $path) {
            expect((string) soData($path))->toContain(BreedStageParamsSeeder::DOG_SOCIALISATION[$breed][1]);
        }
    });

    it('gives the mutt, the Border Collie and the breeds without a quote no row', function () {
        $slugs = collect(BreedStageParamsSeeder::rows())->where('key', StageParamKey::SocialisationTasksPerWeek->value)->pluck('breed_slug');
        foreach ([BreedType::Mutt, BreedType::BorderCollie, BreedType::FrenchBulldog, BreedType::CavalierKingCharlesSpaniel, BreedType::Dachshund,
            BreedType::WestHighlandWhiteTerrier, BreedType::BerneseMountainDog, BreedType::GermanShorthairedPointer, BreedType::YorkshireTerrier] as $breed) {
            expect($slugs)->not->toContain($breed->slug());
        }
        // No cat row either (dogs only).
        expect(collect(BreedStageParamsSeeder::allRows())->where('key', StageParamKey::SocialisationTasksPerWeek->value)->count())->toBe(12);
    });

    it('validates the value 0–7 whole tasks per week', function () {
        expect(StageParamKey::SocialisationTasksPerWeek->validate(1))->toBeNull()
            ->and(StageParamKey::SocialisationTasksPerWeek->validate(8))->not->toBeNull()
            ->and(StageParamKey::SocialisationTasksPerWeek->validate(1.5))->not->toBeNull()
            ->and(StageParamKey::SocialisationTasksPerWeek->isBreedLevel())->toBeFalse();
    });
});

describe('the gate (ClientFeature socialisation → pets.socialisation_enabled)', function () {
    it('enables socialisation only for a profiled dog when both apps declared `socialisation`', function () {
        seedLifeStageData();
        $profile = ['breed' => 'rottweiler', 'origin' => 'bought', 'age_stage' => 'puppy', 'plan' => 'challenge'];

        expect(soCreateViaPin([...$profile, 'features' => ['socialisation']], ['socialisation'])->socialisation_enabled)->toBeTrue()
            ->and(soCreateViaPin([...$profile, 'features' => ['socialisation']], ['training'])->socialisation_enabled)->toBeFalse()
            ->and(soCreateViaPin([...$profile, 'features' => ['training']], ['socialisation'])->socialisation_enabled)->toBeFalse()
            ->and(soCreateViaPin([...$profile, 'features' => ['socialisation']], null)->socialisation_enabled)->toBeFalse()
            // No profile → legacy pet, features ignored.
            ->and(soCreateViaPin(['features' => ['socialisation']], ['socialisation'])->socialisation_enabled)->toBeFalse();
    });

    it('gives an existing dog (older app), a breed without a row and a grown dog no task', function () {
        [, $child] = soFamily(BreedType::Rottweiler, enabled: false);
        soAt('2026-10-21 10:00');
        expect(soState($child)['socialisation'])->toBeNull();
        soPost($child, 'dog')->assertStatus(422)->assertJsonPath('reason', 'socialisation_not_available');

        [, $mutt] = soFamily(BreedType::Mutt);
        soAt('2026-10-21 10:00');
        expect(soState($mutt)['socialisation'])->toBeNull();
        soPost($mutt, 'dog')->assertStatus(422)->assertJsonPath('reason', 'socialisation_not_available');

        // Arrived as a young dog (12 months): never a puppy week.
        [, $young] = soFamily(BreedType::Rottweiler, arrival: 12);
        soAt('2026-10-21 10:00');
        expect(soState($young)['socialisation'])->toBeNull();
        soPost($young, 'person')->assertStatus(422)->assertJsonPath('reason', 'socialisation_not_available');
        expect(ActivityLog::where('activity_type', 'socialised_pet')->count())->toBe(0);
    });
});

describe('the weekly task (child)', function () {
    it('shows this week\'s open task to a puppy\'s child', function () {
        [, $child] = soFamily();
        soAt('2026-10-21 10:00');

        expect(soState($child)['socialisation'])->toBe([
            'goal_per_week' => 1,
            'done_this_week' => 0,
            'done' => false,
            'week' => 1,
            'week_started_at' => '2026-10-20T08:00:00+02:00',
            'week_ends_at' => '2026-10-27T08:00:00+01:00',
            'experience' => null,
            'experiences' => ['person', 'dog', 'sound', 'place'],
            'next_allowed_at' => null,
            'blocked_reason' => null,
            'can_record' => true,
        ]);
    });

    it('records one experience per program week: one row, one broadcast, then week_done until the next week', function () {
        [, $child, $dog] = soFamily();
        soAt('2026-10-21 10:00');
        Event::fake([PetUpdated::class]);

        $response = soPost($child, 'sound')->assertOk()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('socialisation.experience', 'sound')
            ->assertJsonPath('state.socialisation.done', true)
            ->assertJsonPath('state.socialisation.experience', 'sound')
            ->assertJsonPath('state.socialisation.blocked_reason', 'socialisation_week_done')
            ->assertJsonPath('state.socialisation.next_allowed_at', '2026-10-27T08:00:00+01:00')
            ->assertJsonPath('state.socialisation.can_record', false);
        expect($response->json('state.pet.hunger_level'))->toBeInt();

        $rows = ActivityLog::where('pet_id', $dog->id)->where('activity_type', 'socialised_pet')->get();
        expect($rows)->toHaveCount(1)
            ->and((int) $rows[0]->value)->toBe(SocialisationExperience::Sound->code())
            ->and((int) $rows[0]->actor_user_id)->toBe($child->id);
        Event::assertDispatchedTimes(PetUpdated::class, 1);
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->petId === $dog->id && $e->eventType === 'socialised_pet');

        // A second one the same week (another tap, another day) is refused.
        soAt('2026-10-24 10:00');
        soPost($child, 'place')->assertStatus(422)
            ->assertJsonPath('reason', 'socialisation_week_done')
            ->assertJsonPath('next_allowed_at', '2026-10-27T08:00:00+01:00');
        expect(ActivityLog::where('activity_type', 'socialised_pet')->count())->toBe(1);

        // The next program week (from the weekly birthday on): a new task.
        soAt('2026-10-27 09:00');
        expect(soState($child)['socialisation'])->toMatchArray(['week' => 2, 'done' => false, 'experience' => null, 'can_record' => true]);
        soPost($child, 'person')->assertOk();
    });

    it('lets a sibling see the week as done (one task per pet, not per child)', function () {
        [$parent, $child, $dog] = soFamily();
        $sibling = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => 'Tim']);
        app(FamilyService::class)->addCaretaker($dog, $sibling, true);
        PetContract::create(['pet_id' => $dog->id, 'user_id' => $sibling->id, 'signature_format' => 'svg_path', 'signature' => 'M1 1 L2 2', 'signed_at' => now()]);
        soAt('2026-10-21 10:00');

        soPost($sibling, 'dog')->assertOk();
        expect(soState($child)['socialisation'])->toMatchArray(['done' => true, 'experience' => 'dog', 'blocked_reason' => 'socialisation_week_done']);
        soPost($child, 'person')->assertStatus(422)->assertJsonPath('reason', 'socialisation_week_done');
    });

    it('refuses in quiet hours (the puppy sleeps) until they end', function () {
        [, $child] = soFamily();
        soAt('2026-10-21 22:30');
        expect(soState($child)['socialisation'])->toMatchArray(['blocked_reason' => 'socialisation_quiet_hours', 'can_record' => false]);
        soPost($child, 'sound')->assertStatus(422)
            ->assertJsonPath('reason', 'socialisation_quiet_hours')
            ->assertJsonPath('next_allowed_at', '2026-10-22T07:00:00+02:00');
        soAt('2026-10-22 07:00');
        soPost($child, 'sound')->assertOk();
    });

    it('validates the experience', function () {
        [, $child] = soFamily();
        soAt('2026-10-21 10:00');
        soPost($child, 'cat')->assertStatus(422)->assertJsonValidationErrors('experience');
        test()->actingAs($child, 'sanctum');
        test()->postJson('/api/child/pet/socialisation', [])->assertStatus(422)->assertJsonValidationErrors('experience');
    });

    it('is locked while the pet is locked (423), like every child action', function () {
        [, $child, $dog] = soFamily();
        soAt('2026-10-21 10:00');
        $dog->update(['is_hard_stopped' => true]);
        soPost($child, 'dog')->assertStatus(423)->assertJsonPath('reason', 'hard_stopped');
        expect(ActivityLog::where('activity_type', 'socialised_pet')->count())->toBe(0);
    });

    it('is a parent-proof child endpoint (403 for a parent token)', function () {
        [$parent] = soFamily();
        soAt('2026-10-21 10:00');
        test()->actingAs($parent, 'sanctum');
        test()->postJson('/api/child/pet/socialisation', ['experience' => 'dog'])->assertForbidden();
    });

    it('ends with the puppy stage: weeks 1–7 have a task (ages 2–8 months), week 8 (9 months, young) none', function () {
        [, $child, $dog] = soFamily();
        $service = app(SocialisationService::class);
        expect(app(LifeStageService::class)->stageStarts(BreedType::Rottweiler->slug())['young'] ?? null)->toBe(9)
            ->and(array_map(fn (int $i) => $service->goalForWeek($dog, $i), range(0, 8)))->toBe([1, 1, 1, 1, 1, 1, 1, 0, 0]);

        // Last puppy week: done → no next week.
        soAt('2026-12-02 10:00');
        expect(soState($child)['socialisation']['week'])->toBe(7);
        soPost($child, 'place')->assertOk()->assertJsonPath('state.socialisation.next_allowed_at', null);

        soAt('2026-12-08 09:00');
        expect(soState($child)['socialisation'])->toBeNull();
        soPost($child, 'place')->assertStatus(422)->assertJsonPath('reason', 'socialisation_not_available');
    });

    it('carries the task in the PetUpdated payload (pet-level can_record) and null for a cat-free dog without it', function () {
        [, , $dog] = soFamily();
        soAt('2026-10-21 10:00');
        expect(PetUpdated::payloadFor($dog->fresh())['socialisation'])->toMatchArray(['week' => 1, 'done' => false, 'can_record' => true]);

        [, , $other] = soFamily(BreedType::Rottweiler, enabled: false);
        expect(PetUpdated::payloadFor($other->fresh())['socialisation'])->toBeNull();
    });
});

describe('the parent (report row + timeline, never scored)', function () {
    it('shows each puppy week as done / missed / open in the report and the experience in the timeline', function () {
        [$parent, $child, $dog] = soFamily();
        soAt('2026-10-22 16:00');
        soPost($child, 'dog')->assertOk();
        // Week 2 (27 Oct – 3 Nov): nothing. Week 3: open.
        soAt('2026-11-04 10:00');

        $report = soReport($parent, $child, 30);
        expect($report['socialisation'])->toMatchArray([
            'goal_per_week' => 1, 'expected' => 3, 'done' => 1, 'done_by_child' => 1, 'missed' => 1, 'open' => 1, 'scored' => false,
        ])
            ->and(array_map(fn (array $w) => [$w['week'], $w['status'], $w['experiences'], $w['by_child']], $report['socialisation']['weeks']))->toBe([
                [1, 'done', ['dog'], true],
                [2, 'missed', [], false],
                [3, 'open', [], false],
            ])
            ->and($report['socialisation']['weeks'][1])->toMatchArray(['started_at' => '2026-10-27T08:00:00+01:00', 'ends_at' => '2026-11-03T08:00:00+01:00']);
        // The 7-day report only shows the weeks that reach into its period.
        expect(array_column(soReport($parent, $child, 7)['socialisation']['weeks'], 'week'))->toBe([2, 3]);

        test()->actingAs($parent, 'sanctum');
        $timeline = test()->getJson('/api/parent/dashboard')->assertOk()->json('family.pets.0.timeline');
        $entry = collect($timeline)->firstWhere('activity_type', 'socialised_pet');
        expect($entry)->toMatchArray(['value' => SocialisationExperience::Dog->code(), 'actor_nickname' => 'Mia', 'is_positive' => true]);
    });

    it('changes nothing in the Care Score, the routines or the traffic light — done or missed', function () {
        [$parent, $child, $dog] = soFamily();
        soAt('2026-10-28 12:00'); // week 1 is over without the experience → missed
        $missed = soReport($parent, $child, 7);
        expect($missed['socialisation']['missed'])->toBe(1);

        // The same moment with week 1 done instead.
        DB::table('activities_log')->insert([
            'pet_id' => $dog->id, 'actor_user_id' => $child->id, 'activity_type' => 'socialised_pet',
            'value' => SocialisationExperience::Person->code(), 'created_at' => Carbon::parse('2026-10-22 10:00', 'Europe/Ljubljana')->utc(),
        ]);
        $done = soReport($parent, $child, 7);
        expect($done['socialisation']['missed'])->toBe(0)->and($done['socialisation']['done'])->toBe(1);

        foreach (['care_score', 'period_score', 'traffic_light', 'by_type', 'daily', 'missed', 'progress'] as $key) {
            expect($done[$key])->toBe($missed[$key], $key);
        }
        expect(array_keys($done['by_type']))->toBe(array_map(fn (RoutineType $t) => $t->value, RoutineType::cases()))
            ->and(collect(RoutineType::cases())->map->value->all())->not->toContain('socialisation');
        $routines = app(RoutineLedgerService::class)->routinesFor(collect([$dog->fresh()]), '2026-10-20', '2026-10-28')[$dog->id] ?? [];
        expect(collect($routines)->map(fn ($r) => $r->type->value)->unique()->values()->all())->not->toContain('socialised_pet');
    });

    it('has no socialisation row for a pet without the task', function () {
        [$parent, $child] = soFamily(BreedType::Rottweiler, enabled: false);
        soAt('2026-10-28 12:00');
        expect(soReport($parent, $child)['socialisation'])->toBeNull();
    });
});

describe('migration 2026_11_30_120000_add_puppy_socialisation', function () {
    it('adds the column and the two CHECK values; down() removes them, up() adds them again', function () {
        $migration = require database_path('migrations/2026_11_30_120000_add_puppy_socialisation.php');
        [, , $dog] = soFamily(BreedType::Rottweiler, enabled: false);
        $constraint = fn (string $name): string => (string) DB::selectOne('SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = ?', [$name])->def;

        expect(Schema::hasColumn('pets', 'socialisation_enabled'))->toBeTrue()
            ->and($constraint('breed_stage_params_key_check'))->toContain('socialisation_tasks_per_week')
            ->and($constraint('activities_log_activity_type_check'))->toContain('socialised_pet')
            // Widening kept every older value.
            ->and($constraint('activities_log_activity_type_check'))->toContain('groomed_pet')
            ->and($constraint('breed_stage_params_key_check'))->toContain('grooming_sessions_per_week');

        $migration->down();
        expect(Schema::hasColumn('pets', 'socialisation_enabled'))->toBeFalse()
            ->and($constraint('breed_stage_params_key_check'))->not->toContain('socialisation_tasks_per_week')
            ->and($constraint('activities_log_activity_type_check'))->not->toContain('socialised_pet')
            ->and($constraint('activities_log_activity_type_check'))->toContain('groomed_pet');

        $migration->up();
        expect(Schema::hasColumn('pets', 'socialisation_enabled'))->toBeTrue()
            ->and(DB::table('pets')->where('id', $dog->id)->value('socialisation_enabled'))->toBeFalse()
            ->and($constraint('activities_log_activity_type_check'))->toContain('socialised_pet');
    });
});
