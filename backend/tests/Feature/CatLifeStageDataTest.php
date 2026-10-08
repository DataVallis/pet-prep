<?php

use App\Enums\BreedType;
use App\Enums\LifeStage;
use App\Enums\Species;
use App\Enums\StageParamKey;
use App\Filament\Resources\BreedStageParamResource;
use App\Filament\Resources\BreedStageParamResource\Pages\EditBreedStageParam;
use App\Filament\Resources\BreedStageParamResource\Pages\ListBreedStageParams;
use App\Models\BreedConfig;
use App\Models\BreedStageParam;
use App\Models\Pet;
use App\Models\User;
use App\Services\ChildProfileService;
use App\Services\LifeStageService;
use Database\Seeders\BreedConfigsSeeder;
use Database\Seeders\BreedStageParamsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| M5-R06-03 — cat life-stage data (M5-R06_PLAN T10, CAT_SPEC §2–§5)
|--------------------------------------------------------------------------
|
| Every cat row of BreedStageParamsSeeder::catRows() is checked against
| docs/research/cat-data/data.json, its source ids against
| docs/research/cat-data/sources.md (same rule as the dogs in
| LifeStageDataTest). Values only — the rules reading the new keys are
| M5-R06-04 / 05. Regression: the dog rowset, the dog breed configs and the
| dog stage rules are exactly what main (9a81fc1) seeded.
|
*/

/** A value at a dotted path of the CAT data.json ("cat-data:" prefix allowed). */
function clData(string $path): array
{
    static $data = null;
    $data ??= json_decode((string) file_get_contents(base_path('../docs/research/cat-data/data.json')), true, flags: JSON_THROW_ON_ERROR);

    $node = $data;
    foreach (explode('.', str_starts_with($path, BreedStageParamsSeeder::CAT_REF) ? substr($path, strlen(BreedStageParamsSeeder::CAT_REF)) : $path) as $segment) {
        expect($node)->toHaveKey($segment);
        $node = $node[$segment];
    }

    return $node;
}

/** Source ids C1–C25 listed in cat-data/sources.md. */
function clSourceIds(): array
{
    preg_match_all('/^\| (C\d+) \|/m', (string) file_get_contents(base_path('../docs/research/cat-data/sources.md')), $m);

    return $m[1];
}

/** "Windows 07–09, 11–13, 15–17, 19–21" in a data.json note → [["07:00","09:00"], …]. */
function clWindowsFromNote(string $note): array
{
    preg_match_all('/(\d{2})–(\d{2})/u', $note, $m, PREG_SET_ORDER);

    return array_map(fn (array $w): array => [$w[1].':00', $w[2].':00'], $m);
}

function clRow(string $breed, string $stage, int $from, StageParamKey $key): ?array
{
    return collect(BreedStageParamsSeeder::catRows())
        ->first(fn ($r) => $r['breed_slug'] === $breed && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
}

function clCat(BreedType $breed, int $arrivalAge): Pet
{
    return Pet::factory()->state(['breed_type' => $breed->value])
        ->create(['user_id' => User::factory()->child()->create()->id, 'arrival_age_months' => $arrivalAge]);
}

/** Parent PIN with a profile → child pin-login (both apps declare every feature) → the created pet. */
function clPairedPet(array $profile): Pet
{
    config(['petprep.cats_enabled' => true]);
    test()->withoutMiddleware([ThrottleRequests::class]);
    $features = ['species_cat', 'behaviour_events', 'training'];
    $parent = User::factory()->parent()->create();
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);
    $pin = postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id, 'features' => $features], $profile))
        ->assertOk()->json('pin');
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return Pet::findOrFail(postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => $features])
        ->assertOk()->json('pet.id'));
}

describe('cat import provenance (cat-data/data.json)', function () {
    it('takes every cat value from cat-data/data.json with a listed C source or a David decision; UNSOURCED (D) would stay unverified', function () {
        $known = clSourceIds();
        expect($known)->toHaveCount(25)->toContain('C1', 'C13', 'C25');

        $rows = BreedStageParamsSeeder::catRows();
        expect($rows)->not->toBeEmpty();

        foreach ($rows as $row) {
            $label = "{$row['breed_slug']}.{$row['stage']}.{$row['age_from_months']}.{$row['key']}";
            expect(BreedType::fromSlug($row['breed_slug'])?->species())->toBe(Species::Cat, $label)
                ->and($row['ref'])->toStartWith(BreedStageParamsSeeder::CAT_REF, "{$label} must point into cat-data");

            foreach (array_filter(explode(',', (string) $row['source_id'])) as $id) {
                expect($id)->toStartWith('C')->and($known)->toContain($id);
            }

            $entry = clData($row['ref']);
            $unsourced = str_contains((string) ($entry['notes'] ?? ''), 'UNSOURCED');

            // Feed windows point at the meal entry whose notes list them (clock
            // times are the dogs' window rule, not literature): no source, low.
            if ($row['key'] === StageParamKey::FeedWindows->value) {
                expect($row['source_id'])->toBeNull()->and($row['confidence'])->toBe('low')
                    ->and($row['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_CAT, "{$label}: windows decision = CAT_SPEC approval")
                    ->and($row['value'])->toBe(clWindowsFromNote((string) $entry['notes']), $label);
            } elseif ($row['source_id'] !== null) {
                // A cited source is the one data.json names for the value.
                expect($row['source_id'])->toBe($entry['source_id'], "{$label}: source differs from data.json");
            } else {
                expect($entry['source_id'] ?? null)->toBeNull("{$label}: data.json has a source the row drops");
            }
            if ($row['key'] !== StageParamKey::FeedWindows->value) {
                expect($row['confidence'])->toBe($entry['confidence'], "{$label}: confidence");
            }

            if ($unsourced) {
                expect($row['verified'])->toBeFalse("{$label} is UNSOURCED (D) in data.json")
                    ->and($row['decision'])->toBeNull()
                    ->and((string) $row['notes'])->toContain('UNSOURCED — proposal (D)');
            }
            if ($row['decision'] !== null && $row['key'] !== StageParamKey::FeedWindows->value) {
                // David's cat decisions are recorded in data.json (CAT_SPEC Q1–Q10 / plan answers).
                expect((string) ($entry['decision'] ?? ''))->toStartWith($row['decision'], "{$label}: data.json has no such decision");
            }
            if ($row['verified']) {
                expect($row['source_id'] !== null || $row['decision'] !== null)->toBeTrue("{$label}: verified needs a source or a decision");
            }
            if ($row['quote'] !== null && ($entry['quote'] ?? null) !== null) {
                expect($entry['quote'])->toContain(explode(' / ', $row['quote'])[0]);
            }
            expect(StageParamKey::from($row['key'])->validate($row['value']))->toBeNull($label);
        }
    });

    it('matches the numbers of cat-data/data.json (stages 12 / 84 / 120, arrival 2 / 3 / 12 / 84 / 120, meals, windows, sleep)', function () {
        foreach ([BreedType::DomesticCat, BreedType::MaineCoon] as $breed) {
            $s = $breed->slug();
            $v = fn (string $stage, int $from, StageParamKey $key) => ($r = clRow($s, $stage, $from, $key)) !== null ? $r['value'] : 'MISSING';

            // Stage boundaries (C1 + decisions).
            expect($v('puppy', 0, StageParamKey::StartsAtMonths))->toBe(0)
                ->and($v('young', 0, StageParamKey::StartsAtMonths))->toBe(12)
                ->and($v('adult', 0, StageParamKey::StartsAtMonths))->toBe(84)
                ->and($v('senior', 0, StageParamKey::StartsAtMonths))->toBe(120)
                ->and(clData('general.life_stages.young_adult')['decision'])->toContain('young stage starts at 12 months')
                ->and(clData('general.life_stages.mature_adult')['decision'])->toContain('adult stage starts at 84 months')
                ->and(clData('general.life_stages.senior')['decision'])->toContain('senior stage starts at 120 months');

            // Arrival ages (C13 + decisions).
            expect($v('puppy', 0, StageParamKey::ArrivalAgeMonths))->toBe($breed === BreedType::MaineCoon
                ? clData('maine_coon.arrival_age_kitten')['value'] : clData('general.arrival_age.kitten_min')['value'])
                ->and($v('young', 0, StageParamKey::ArrivalAgeMonths))->toBe(clData('general.arrival_age.young')['value'])
                ->and($v('adult', 0, StageParamKey::ArrivalAgeMonths))->toBe(clData('general.arrival_age.adult')['value'])
                ->and($v('senior', 0, StageParamKey::ArrivalAgeMonths))->toBe(clData('general.arrival_age.senior')['value']);

            // Meals per day + feed windows of each band (windows from the data.json notes).
            foreach ([[0, 'kitten_6_12_weeks'], [3, 'kitten_3_6_months'], [6, 'kitten_6_12_months']] as [$from, $ref]) {
                expect($v('puppy', $from, StageParamKey::MealsPerDay))->toBe(clData("general.meals_per_day.{$ref}")['value']);
            }
            expect($v('puppy', 0, StageParamKey::FeedWindows))->toBe(clWindowsFromNote(clData('general.meals_per_day.kitten_6_12_weeks')['notes']))
                ->and($v('puppy', 3, StageParamKey::FeedWindows))->toBe(clWindowsFromNote(clData('general.meals_per_day.kitten_3_6_months')['notes']))
                // Two meals: no row — the breed config windows are the data.json windows.
                ->and(clRow($s, 'puppy', 6, StageParamKey::FeedWindows))->toBeNull()
                ->and(collect(BreedConfigsSeeder::configs())->firstWhere('breed_slug', $s)['feed_windows'])
                ->toBe(clWindowsFromNote(clData('general.meals_per_day.kitten_6_12_months')['notes']))
                ->and($v('young', 0, StageParamKey::MealsPerDay))->toBe(clData('general.meals_per_day.adult')['value'])
                ->and($v('adult', 0, StageParamKey::MealsPerDay))->toBe(clData('general.meals_per_day.adult')['value'])
                ->and($v('senior', 0, StageParamKey::MealsPerDay))->toBe(clData('general.meals_per_day.senior')['value']);

            // Sleep (C12): adult 12–16 h, kitten "up to 20" (no range → null), no senior entry → no row.
            expect($v('adult', 0, StageParamKey::SleepHours))->toBe(array_map('intval', explode('–', clData('general.sleep.adult')['value'])))
                ->and($v('puppy', 0, StageParamKey::SleepHours))->toBeNull()
                ->and(clData('general.sleep.kitten')['value'])->toBe('up to 20')
                ->and(clRow($s, 'senior', 0, StageParamKey::SleepHours))->toBeNull();

            // No walk: cats get no exercise / step / training / puppy behaviour rows.
            $keys = collect(BreedStageParamsSeeder::catRows())->where('breed_slug', $s)->pluck('key')->unique();
            foreach ([StageParamKey::ExerciseMinutesPerDay, StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::StepsPerExerciseMinute,
                StageParamKey::AccidentHoldHoursPerAgeMonth, StageParamKey::ChewingChancePerDay, StageParamKey::TrainingStartingProgress,
                StageParamKey::TrainingMinutesPerDay] as $absent) {
                expect($keys)->not->toContain($absent->value);
            }

            expect($v('all', 0, StageParamKey::LifespanYears))->toBe(clData(str_replace('-', '_', $s).'.lifespan.expectancy_at_birth')['value']);
        }
    });

    it('stores the seven new cat keys with the data.json values (values only, no rules yet)', function () {
        foreach ([BreedType::DomesticCat, BreedType::MaineCoon] as $breed) {
            $s = $breed->slug();
            $v = fn (string $stage, StageParamKey $key) => ($r = clRow($s, $stage, 0, $key)) !== null ? $r['value'] : 'MISSING';

            expect($v('puppy', StageParamKey::PlaySessionsPerDay))->toBe(clData('general.play.game_sessions_kitten')['value'])
                ->and($v('puppy', StageParamKey::LitterUsesPerDay))->toBe(clData('general.litter.game_uses_per_day_kitten')['value']);
            foreach (['young', 'adult', 'senior'] as $stage) {
                expect($v($stage, StageParamKey::PlaySessionsPerDay))->toBe(clData('general.play.game_sessions_adult')['value'])
                    ->and($v($stage, StageParamKey::LitterUsesPerDay))->toBe(clData('general.litter.game_uses_per_day_adult')['value']);
            }
            expect($v('all', StageParamKey::PlayMinGapMinutes))->toBe(clData('general.play.game_min_gap')['value'])
                ->and($v('all', StageParamKey::LitterScoopDeadlineHours))->toBe(clData('general.litter.game_scoop_deadline')['value'])
                ->and($v('all', StageParamKey::LitterFullChangeDays))->toBe(clData('general.litter.full_change')['value'])
                ->and($v('all', StageParamKey::ScratchingAfterMissedPlay))->toBeTrue()
                ->and(clData('general.play.missed_play_illness')['value'])->toBeFalse();
        }

        // Grooming: Maine Coon only (CAT_SPEC Q8); the domestic cat has no row = no routine.
        expect(clRow('maine-coon', 'all', 0, StageParamKey::GroomingSessionsPerWeek)['value'])->toBe(clData('maine_coon.grooming.game_sessions_per_week')['value'])
            ->and(clRow('domestic-cat', 'all', 0, StageParamKey::GroomingSessionsPerWeek))->toBeNull();

        // Stage vs breed level.
        expect(StageParamKey::PlaySessionsPerDay->isBreedLevel())->toBeFalse()
            ->and(StageParamKey::LitterUsesPerDay->isBreedLevel())->toBeFalse();
        foreach ([StageParamKey::PlayMinGapMinutes, StageParamKey::LitterScoopDeadlineHours, StageParamKey::LitterFullChangeDays,
            StageParamKey::GroomingSessionsPerWeek, StageParamKey::ScratchingAfterMissedPlay] as $key) {
            expect($key->isBreedLevel())->toBeTrue($key->value);
        }

        // Shape checks of the new keys.
        expect(StageParamKey::ScratchingAfterMissedPlay->validate(1))->not->toBeNull()
            ->and(StageParamKey::GroomingSessionsPerWeek->validate(8))->not->toBeNull()
            ->and(StageParamKey::LitterScoopDeadlineHours->validate(0))->not->toBeNull()
            ->and(StageParamKey::LitterScoopDeadlineHours->validate(2.5))->toBeNull()
            ->and(StageParamKey::PlayMinGapMinutes->validate(-1))->not->toBeNull();
    });

    it('seeds the cat rows into the database with provenance; every cat row is verified since David confirmed the play gap (M5-R06-04)', function () {
        seedLifeStageData();

        $cats = BreedStageParam::whereIn('breed_slug', ['domestic-cat', 'maine-coon']);
        expect((clone $cats)->count())->toBe(count(BreedStageParamsSeeder::catRows()))
            ->and((clone $cats)->where('verified', false)->pluck('key')->unique()->values()->all())->toBe([]);
        $gap = BreedStageParam::where(['breed_slug' => 'maine-coon', 'key' => StageParamKey::PlayMinGapMinutes->value])->sole();
        expect($gap->value)->toBe(120)->and($gap->verified)->toBeTrue()
            ->and($gap->notes)->toStartWith('Decision: '.BreedStageParamsSeeder::CONFIRMED_CAT_PLAY.'.');

        $young = BreedStageParam::where(['breed_slug' => 'maine-coon', 'stage' => 'young', 'key' => 'starts_at_months'])->sole();
        expect($young->value)->toBe(12)
            ->and($young)->source_id->toBe('C1')->confidence->toBe('high')->verified->toBeTrue()
            ->data_ref->toBe('cat-data:general.life_stages.young_adult')
            ->and($young->notes)->toStartWith('Decision: potrdil David 2026-10-08 13:47.');

        $scratch = BreedStageParam::where(['breed_slug' => 'domestic-cat', 'key' => 'scratching_after_missed_play'])->sole();
        expect($scratch->value)->toBeTrue();

        // Insert-only + idempotent: a second run adds nothing.
        $count = BreedStageParam::count();
        (new BreedStageParamsSeeder)->run();
        expect(BreedStageParam::count())->toBe($count);
    });

    it('feeds the cat life-stage rules: kitten 4 → 3 → 2 meals, grown cat 2 meals, no step goal, sourced (data_verified)', function () {
        seedLifeStageData();
        $ls = app(LifeStageService::class);

        $kitten = clCat(BreedType::DomesticCat, 2);
        $rules = $ls->rulesOn($kitten, $kitten->localDate(now()));
        expect($rules->lifeStage)->toBe(LifeStage::Puppy)
            ->and($rules->mealsPerDay)->toBe(4)
            ->and($rules->feedWindows)->toBe(BreedStageParamsSeeder::PUPPY_4_MEAL_WINDOWS)
            ->and($rules->stepGoal)->toBe(0)
            ->and($rules->exerciseMinutes)->toBeNull()
            ->and($rules->verified())->toBeTrue();

        $mc = clCat(BreedType::MaineCoon, 3);
        expect($ls->rulesOn($mc, $mc->localDate(now())))->mealsPerDay->toBe(3)->stepGoal->toBe(0)
            ->and($ls->rulesOn($mc, $mc->localDate(now()))->feedWindows)->toBe(BreedStageParamsSeeder::PUPPY_3_MEAL_WINDOWS);

        foreach ([[6, LifeStage::Puppy], [12, LifeStage::Young], [84, LifeStage::Adult], [120, LifeStage::Senior]] as [$age, $stage]) {
            $cat = clCat(BreedType::DomesticCat, $age);
            $rules = $ls->rulesOn($cat, $cat->localDate(now()));
            expect($rules->lifeStage)->toBe($stage, "{$age} months")
                ->and($rules->mealsPerDay)->toBe(2)
                ->and($rules->feedWindows)->toBe(BreedConfigsSeeder::CAT_FEED_WINDOWS)
                ->and($rules->stepGoal)->toBe(0)
                ->and($rules->verified())->toBeTrue("{$age} months");
        }

        // Arrival ages per stage come from the data (Maine Coon kitten 3 months).
        expect($ls->arrivalAgeFor('domestic-cat', LifeStage::Puppy))->toBe(2)
            ->and($ls->arrivalAgeFor('maine-coon', LifeStage::Puppy))->toBe(3)
            ->and($ls->arrivalAgeFor('maine-coon', LifeStage::Adult))->toBe(84)
            ->and($ls->arrivalAgeFor('domestic-cat', LifeStage::Senior))->toBe(120)
            ->and($ls->stageStarts('maine-coon'))->toBe(['puppy' => 0, 'young' => 12, 'adult' => 84, 'senior' => 120]);
    });

    it('keeps the cat breed_configs equal to data.json (no data migration needed: R06-01 rows already hold these values)', function () {
        seedBreedConfigs();

        foreach (['domestic-cat', 'maine-coon'] as $slug) {
            $c = BreedConfig::where('breed_slug', $slug)->sole();
            expect($c->feed_windows)->toBe(clWindowsFromNote(clData('general.meals_per_day.kitten_6_12_months')['notes']))
                ->and($c->water_times_per_day)->toBe(clData('general.water.game_times_per_day')['value'])
                ->and($c->water_min_gap_minutes)->toBe(clData('general.water.game_min_gap')['value'])
                ->and($c->hunger_decay_rate)->toBe((float) clData('general.game_decay.hunger')['value'])
                ->and($c->thirst_decay_rate)->toBe((float) clData('general.game_decay.thirst')['value'])
                // No poop events (a litter use is not a mess, CAT_SPEC §4) and no steps (CAT_SPEC Q1).
                ->and($c->poops_per_day)->toBe(0)
                ->and($c->daily_steps_required)->toBe(0)
                ->and($c->species)->toBe(Species::Cat);
        }
    });
});

describe('LifeStage by species', function () {
    it('names the stages per species in English and Slovenian (CAT_SPEC §2), lang files in sync', function () {
        expect(LifeStage::Puppy->label(Species::Cat, 'sl'))->toBe('Mucek')
            ->and(LifeStage::Young->label(Species::Cat, 'sl'))->toBe('Mlada mačka')
            ->and(LifeStage::Adult->label(Species::Cat, 'sl'))->toBe('Zrela mačka')
            ->and(LifeStage::Senior->label(Species::Cat, 'sl'))->toBe('Starejša mačka')
            ->and(LifeStage::Puppy->label(Species::Cat, 'en'))->toBe('Kitten')
            ->and(LifeStage::Adult->label(Species::Cat, 'en'))->toBe('Mature cat')
            ->and(LifeStage::Puppy->label(locale: 'sl'))->toBe('Mladiček')
            ->and(LifeStage::Young->label(Species::Dog, 'en'))->toBe('Young dog');

        $keys = fn (string $locale): array => array_keys(Arr::dot(require lang_path("{$locale}/life_stages.php")));
        expect($keys('sl'))->toBe($keys('en'));
        foreach (Species::cases() as $species) {
            foreach (LifeStage::ordered() as $stage) {
                expect($stage->label($species, 'en'))->not->toContain('life_stages.');
            }
        }
    });

    it('gives cats their own prompt cues and leaves the dog cues exactly as before', function () {
        // Dog cues frozen from main (9a81fc1) — default and explicit species.
        $dog = [
            'puppy' => 'a young puppy with clear puppy proportions: a rounded head that is large for the body, a short muzzle, big paws, short legs and a soft, fluffy puppy coat',
            'young' => 'an adolescent young dog, almost adult-sized but still slightly lanky and leggy, with the adult coat coming in',
            'adult' => 'a fully grown adult dog in its prime, in good healthy condition',
            'senior' => 'a healthy senior dog with a greying muzzle and some grey around the eyes, a calm, relaxed posture and a slightly softer body',
        ];
        foreach (LifeStage::ordered() as $stage) {
            expect($stage->promptCue())->toBe($dog[$stage->value])
                ->and($stage->promptCue(Species::Dog))->toBe($dog[$stage->value])
                ->and($stage->promptCue(Species::Cat))->toMatch('/\b(cat|kitten)\b/')
                ->and($stage->promptCue(Species::Cat))->not->toContain('dog')
                ->and($stage->promptCue(Species::Cat))->not->toContain('puppy');
        }
        expect(LifeStage::Puppy->promptCue(Species::Cat))->toContain('kitten');
    });
});

describe('Filament life-stage data for cats', function () {
    it('filters the stage params by species and accepts C source ids', function () {
        seedLifeStageData();
        actingAs(User::factory()->create(['role' => 'parent', 'is_superadmin' => true]));

        $catRows = BreedStageParam::whereIn('breed_slug', ['domestic-cat', 'maine-coon'])->get();
        $dogRows = BreedStageParam::whereIn('breed_slug', ['mutt', 'border-collie'])->get();

        Livewire::test(ListBreedStageParams::class)
            ->set('tableRecordsPerPage', 'all')
            ->filterTable('species', 'cat')
            ->assertCanSeeTableRecords($catRows)
            ->assertCanNotSeeTableRecords($dogRows)
            ->filterTable('species', 'dog')
            ->assertCanSeeTableRecords($dogRows)
            ->assertCanNotSeeTableRecords($catRows);

        expect(preg_match(BreedStageParamResource::SOURCE_ID_PATTERN, 'C2,C3'))->toBe(1)
            ->and(preg_match(BreedStageParamResource::SOURCE_ID_PATTERN, 'S11,S15'))->toBe(1)
            ->and(preg_match(BreedStageParamResource::SOURCE_ID_PATTERN, 'X1'))->toBe(0);

        // A cat row (C ids) saves through the edit form; a bool key validates.
        $param = BreedStageParam::where(['breed_slug' => 'maine-coon', 'stage' => 'all', 'key' => 'scratching_after_missed_play'])->sole();
        Livewire::test(EditBreedStageParam::class, ['record' => $param->getRouteKey()])
            ->fillForm(['value' => '1'])
            ->call('save')
            ->assertHasFormErrors(['value']);
        Livewire::test(EditBreedStageParam::class, ['record' => $param->getRouteKey()])
            ->fillForm(['value' => 'false'])
            ->call('save')
            ->assertHasNoFormErrors();
        expect($param->fresh()->value)->toBeFalse()->and($param->fresh()->source_id)->toBe('C8,C22');
    });
});

describe('pairing a cat with a profile (QA M1)', function () {
    it('creates kittens with the sourced arrival age and stage, without dog training or dog behaviour events; dogs unchanged', function () {
        seedLifeStageData();

        $kitten = clPairedPet(['species' => 'cat', 'breed' => 'domestic_cat', 'origin' => 'adopted', 'age_stage' => 'puppy', 'plan' => 'free']);
        expect($kitten->breed_type)->toBe(BreedType::DomesticCat)
            ->and($kitten->arrival_age_months)->toBe(2)
            ->and($kitten->life_stage)->toBe(LifeStage::Puppy)
            ->and($kitten->training_enabled)->toBeFalse()
            ->and($kitten->behaviour_events_enabled)->toBeFalse();

        $coon = clPairedPet(['species' => 'cat', 'breed' => 'maine_coon', 'origin' => 'bought', 'age_stage' => 'puppy', 'plan' => 'challenge']);
        expect($coon->breed_type)->toBe(BreedType::MaineCoon)
            ->and($coon->arrival_age_months)->toBe(3)
            ->and($coon->life_stage)->toBe(LifeStage::Puppy)
            ->and($coon->training_enabled)->toBeFalse()
            ->and($coon->behaviour_events_enabled)->toBeFalse();

        $grown = clPairedPet(['species' => 'cat', 'breed' => 'domestic_cat', 'origin' => 'adopted', 'age_stage' => 'adult', 'plan' => 'free']);
        expect($grown->arrival_age_months)->toBe(84)->and($grown->life_stage)->toBe(LifeStage::Adult)
            ->and($grown->training_enabled)->toBeFalse();

        // Dogs: same profile + features → training and behaviour events stay on.
        $puppy = clPairedPet(['species' => 'dog', 'breed' => 'mutt', 'origin' => 'bought', 'age_stage' => 'puppy', 'plan' => 'free']);
        expect($puppy->arrival_age_months)->toBe(2)
            ->and($puppy->life_stage)->toBe(LifeStage::Puppy)
            ->and($puppy->training_enabled)->toBeTrue()
            ->and($puppy->behaviour_events_enabled)->toBeTrue();
    });
});

describe('migration 2026_10_25_120000_add_cat_stage_param_keys', function () {
    it('down() removes only the rows of the new keys and restores the old check; up() again lets the seeder re-add them', function () {
        seedLifeStageData();
        $migration = require database_path('migrations/2026_10_25_120000_add_cat_stage_param_keys.php');
        $newKeys = ['play_sessions_per_day', 'play_min_gap_minutes', 'litter_uses_per_day', 'litter_scoop_deadline_hours',
            'litter_full_change_days', 'grooming_sessions_per_week', 'scratching_after_missed_play'];
        $others = DB::table('breed_stage_params')->whereNotIn('key', $newKeys)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        expect(DB::table('breed_stage_params')->whereIn('key', $newKeys)->count())->toBeGreaterThan(0);

        $migration->down();
        // savepoint: the refused insert must not abort the test transaction
        expect(DB::table('breed_stage_params')->whereIn('key', $newKeys)->count())->toBe(0)
            ->and(DB::table('breed_stage_params')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())->toBe($others)
            ->and(fn () => DB::transaction(fn () => DB::table('breed_stage_params')->insert(['breed_slug' => 'domestic-cat', 'stage' => 'all', 'age_from_months' => 0,
                'key' => 'litter_full_change_days', 'value' => '7', 'confidence' => 'low', 'verified' => false])))->toThrow(QueryException::class);

        $migration->up();
        (new BreedStageParamsSeeder)->run();
        expect(BreedStageParam::count())->toBe(count(BreedStageParamsSeeder::allRows()));
    });
});

describe('regression: dogs unchanged by M5-R06-03', function () {
    it('keeps the dog rowset, the dog breed configs and the cat breed configs identical to main (9a81fc1)', function () {
        $hash = fn (mixed $v): string => hash('sha256', json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $configs = collect(BreedConfigsSeeder::configs());

        // Fingerprints computed from main's seeders before this change.
        expect($hash(BreedStageParamsSeeder::rows()))->toBe('28d500305a87d3ce98d4c3de9dc470d49ef60c7773a1c934daf081d2143ef199')
            ->and($hash($configs->where('species', 'dog')->values()->all()))->toBe('e159cb38c1e2127ea7b4b2f35ce3ba9f93601b190d20af8a0266ea2180b66450')
            ->and($hash($configs->where('species', 'cat')->values()->all()))->toBe('4470d9b9e1f44d9ce4db857683834f71b2a100d37df70bac30039e3a19f48d72')
            ->and(collect(BreedStageParamsSeeder::catRows())->pluck('breed_slug')->unique()->values()->all())->toBe(['domestic-cat', 'maine-coon']);
    });

    it('seeds exactly the dog rows main seeded and gives dogs the same stage rules', function () {
        seedLifeStageData();

        $stored = DB::table('breed_stage_params')->whereIn('breed_slug', BreedConfig::query()->select('breed_slug')->where('species', 'dog'))
            ->orderBy('id')->get(['breed_slug', 'stage', 'age_from_months', 'key', 'value', 'unit', 'source_id', 'confidence', 'verified', 'quote', 'notes', 'data_ref'])
            ->map(fn ($r) => array_merge((array) $r, ['value' => json_decode((string) $r->value, true)]))->all();
        $expected = array_map(fn (array $row): array => array_merge(
            ['breed_slug' => $row['breed_slug'], 'stage' => $row['stage'], 'age_from_months' => $row['age_from_months'], 'key' => $row['key']],
            BreedStageParamsSeeder::columns($row),
            ['value' => $row['value']],
        ), BreedStageParamsSeeder::rows());
        expect($stored)->toEqual($expected); // JSON round trip turns 1.0 into 1

        // Spec numbers (PRODUCT_SPEC §4/§5, REALISM_SPEC): meals and step goals per arrival age.
        $ls = app(LifeStageService::class);
        foreach ([
            ['mutt', 2, LifeStage::Puppy, 4, 2000], ['mutt', 9, LifeStage::Young, 2, 6000], ['mutt', 36, LifeStage::Adult, 2, 6000],
            ['mutt', 108, LifeStage::Senior, 2, 4500], ['border_collie', 2, LifeStage::Puppy, 4, 2000], ['border_collie', 9, LifeStage::Young, 2, 9000],
            ['border_collie', 36, LifeStage::Adult, 2, 12000], ['border_collie', 118, LifeStage::Senior, 2, 9000],
        ] as [$breed, $age, $stage, $meals, $goal]) {
            $pet = Pet::factory()->state(['breed_type' => $breed])->create(['user_id' => User::factory()->child()->create()->id, 'arrival_age_months' => $age]);
            $rules = $ls->rulesOn($pet, $pet->localDate(now()));
            expect($rules->lifeStage)->toBe($stage, "{$breed} {$age}")
                ->and($rules->mealsPerDay)->toBe($meals, "{$breed} {$age}")
                ->and($rules->stepGoal)->toBe($goal, "{$breed} {$age}");
        }
    });
});
