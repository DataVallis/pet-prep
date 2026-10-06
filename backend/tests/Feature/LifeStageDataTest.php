<?php

use App\Enums\StageParamKey;
use App\Filament\Resources\BreedStageParamResource\Pages\EditBreedStageParam;
use App\Filament\Resources\BreedStageParamResource\Pages\ListBreedStageParams;
use App\Models\BreedStageParam;
use App\Models\BreedStageParamChange;
use App\Models\Pet;
use App\Models\User;
use App\Services\LifeStageService;
use Database\Seeders\BreedStageParamsSeeder;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| M5-R01 — sourced life-stage data: import provenance against
| docs/research/dog-data/data.json, insert-only seeding, audited edits,
| Filament (unverified rows visible as UNSOURCED).
|--------------------------------------------------------------------------
*/

/** A value at a dotted path of data.json. */
function ldData(string $path): array
{
    static $data = null;
    $data ??= json_decode((string) file_get_contents(base_path('../docs/research/dog-data/data.json')), true, flags: JSON_THROW_ON_ERROR);

    $node = $data;
    foreach (explode('.', $path) as $segment) {
        expect($node)->toHaveKey($segment);
        $node = $node[$segment];
    }

    return $node;
}

/** Source ids S1–S47 listed in sources.md. */
function ldSourceIds(): array
{
    preg_match_all('/^\| (S\d+) \|/m', (string) file_get_contents(base_path('../docs/research/dog-data/sources.md')), $m);

    return $m[1];
}

describe('import provenance', function () {
    it('takes every value from data.json with a listed source id; UNSOURCED values stay unverified', function () {
        $known = ldSourceIds();
        expect($known)->toContain('S1', 'S18', 'S45');

        foreach (BreedStageParamsSeeder::rows() as $row) {
            $label = "{$row['breed_slug']}.{$row['stage']}.{$row['age_from_months']}.{$row['key']}";

            foreach (array_filter(explode(',', (string) $row['source_id'])) as $id) {
                expect($known)->toContain($id);
            }

            if ($row['ref'] === null) {
                // A value without a research entry is verified only as a recorded decision.
                expect($row['verified'] && $row['decision'] === null)->toBeFalse("{$label} has no data.json entry, so it must be unverified");

                continue;
            }

            $entry = ldData($row['ref']);
            $unsourced = str_contains((string) ($entry['notes'] ?? ''), 'UNSOURCED') || ($entry['source_id'] ?? null) === null && ! isset($entry['derived_from']);

            if ($unsourced && $row['decision'] === null) {
                expect($row['verified'])->toBeFalse("{$label} is UNSOURCED in data.json");
            }
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED) {
                // David's 2026-10-05 answers are recorded in data.json too.
                expect((string) ($entry['decision'] ?? ''))->toStartWith('potrdil David 2026-10-05', "{$label}: data.json has no decision");
            }
            if ($row['verified'] && $row['source_id'] !== null && ($entry['source_id'] ?? null) !== null) {
                // The cited source is the one data.json names for that value (or one of its derivations).
                expect(array_merge([$entry['source_id']], $entry['derived_from'] ?? []))->toContain(explode(',', $row['source_id'])[0]);
            }
            if ($row['verified'] && isset($entry['derived_from'])) {
                expect(explode(',', $row['source_id']))->toEqualCanonicalizing($entry['derived_from']);
            }
        }
    });

    it('matches the sourced numbers of data.json', function () {
        $rows = collect(BreedStageParamsSeeder::rows());
        $value = fn (string $breed, string $stage, int $from, StageParamKey $key) => $rows
            ->first(fn ($r) => $r['breed_slug'] === $breed && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value)['value'];

        // Meals (S18 / S19 / S14).
        expect($value('mutt', 'puppy', 0, StageParamKey::MealsPerDay))->toBe(ldData('general_by_size.feeding_meals_per_day.8_12_weeks')['value'])
            ->and($value('mutt', 'puppy', 3, StageParamKey::MealsPerDay))->toBe(ldData('general_by_size.feeding_meals_per_day.3_6_months')['value'])
            ->and($value('mutt', 'puppy', 6, StageParamKey::MealsPerDay))->toBe(ldData('general_by_size.feeding_meals_per_day.6_12_months')['value'])
            ->and($value('border-collie', 'adult', 0, StageParamKey::MealsPerDay))->toBe(ldData('general_by_size.feeding_meals_per_day.adult')['value']);
        // Exercise: Border Collie "> 120" min (S5), conversion 100 steps/min (S45).
        expect($value('border-collie', 'adult', 0, StageParamKey::ExerciseMinutesPerDay))->toBe(120)
            ->and(ldData('border_collie.exercise.adult')['value'])->toBe('> 120')
            ->and($value('mutt', 'all', 0, StageParamKey::StepsPerExerciseMinute))->toBe(ldData('general_by_size.exercise.steps_conversion')['value'])
            ->and($value('mutt', 'adult', 0, StageParamKey::ExerciseMinutesPerDay))->toBe(ldData('medium_mixed_breed.exercise.adult_game_target')['value']);
        // Weights, lifespan, senior boundary (0.75 × lifespan).
        expect($value('border-collie', 'all', 0, StageParamKey::AdultWeightKg))->toBe(ldData('border_collie.adult_weight.akc')['value'])
            ->and($value('mutt', 'all', 0, StageParamKey::AdultWeightKg))->toBe(ldData('medium_mixed_breed.assumed_adult_weight')['value'])
            ->and($value('border-collie', 'all', 0, StageParamKey::LifespanYears))->toBe(ldData('border_collie.lifespan.median_uk')['value'])
            ->and($value('border-collie', 'senior', 0, StageParamKey::StartsAtMonths))->toBe((int) round(ldData('border_collie.lifespan.senior_from')['value'] * 12))
            ->and($value('mutt', 'senior', 0, StageParamKey::StartsAtMonths))->toBe((int) round(ldData('medium_mixed_breed.lifespan.senior_from')['value'] * 12))
            ->and($value('border-collie', 'all', 0, StageParamKey::CorenRank))->toBe(ldData('border_collie.trainability.coren_rank')['value'])
            ->and($value('mutt', 'all', 0, StageParamKey::CorenRank))->toBeNull();
        // David's game values (2026-10-05): 2-hour windows, arrival ages, senior minutes.
        expect($value('mutt', 'puppy', 0, StageParamKey::FeedWindows))->toBe(ldData('proposed_game_parameters.feed_window_times')['value']['4_meals'])
            ->and($value('border-collie', 'puppy', 3, StageParamKey::FeedWindows))->toBe(ldData('proposed_game_parameters.feed_window_times')['value']['3_meals'])
            ->and($value('mutt', 'adult', 0, StageParamKey::ArrivalAgeMonths))->toBe(ldData('proposed_game_parameters.arrival_age_months')['value']['adult'])
            ->and($value('border-collie', 'senior', 0, StageParamKey::ArrivalAgeMonths))->toBe(ldData('proposed_game_parameters.arrival_age_months')['value']['senior']['border_collie'])
            ->and($value('mutt', 'senior', 0, StageParamKey::ExerciseMinutesPerDay))->toBe(ldData('proposed_game_parameters.senior_exercise_minutes')['value']['medium_mixed_breed'])
            ->and($value('border-collie', 'senior', 0, StageParamKey::ExerciseMinutesPerDay))->toBe(ldData('proposed_game_parameters.senior_exercise_minutes')['value']['border_collie']);
        // Every value passes its key's shape check.
        foreach (BreedStageParamsSeeder::rows() as $row) {
            expect(StageParamKey::from($row['key'])->validate($row['value']))->toBeNull();
        }
    });

    it('imports insert-only: an existing (edited) row is never overwritten, never-touched missing rows are added', function () {
        seedLifeStageData();
        $count = BreedStageParam::count();
        expect($count)->toBe(count(BreedStageParamsSeeder::rows()));

        BreedStageParam::where(['breed_slug' => 'mutt', 'stage' => 'adult', 'key' => 'exercise_minutes_per_day'])->update(['value' => json_encode(75)]);
        // Removed without an audit row (= a value the seeder adds for the first time, e.g. a new key).
        BreedStageParam::where(['breed_slug' => 'mutt', 'stage' => 'senior', 'key' => 'sleep_hours'])->delete();

        (new BreedStageParamsSeeder)->run();

        expect(BreedStageParam::count())->toBe($count)
            ->and(BreedStageParam::where(['breed_slug' => 'mutt', 'stage' => 'adult', 'key' => 'exercise_minutes_per_day'])->value('value'))->toBe(75)
            ->and(BreedStageParamChange::count())->toBe(0); // the seeder writes no audit rows
    });

    it('stores provenance on every row (source id, confidence, verified, data.json path)', function () {
        seedLifeStageData();

        $meals = BreedStageParam::where(['breed_slug' => 'mutt', 'stage' => 'puppy', 'age_from_months' => 0, 'key' => 'meals_per_day'])->sole();
        $windows = BreedStageParam::where(['breed_slug' => 'mutt', 'stage' => 'puppy', 'age_from_months' => 0, 'key' => 'feed_windows'])->sole();

        expect($meals)->source_id->toBe('S18')->confidence->toBe('high')->verified->toBeTrue()
            ->data_ref->toBe('general_by_size.feeding_meals_per_day.8_12_weeks')
            ->quote->toBe('Puppies eight to 12 weeks old need four meals a day.')
            ->and($windows)->source_id->toBeNull()->verified->toBeTrue()->data_ref->toBe('proposed_game_parameters.feed_window_times')
            ->and($windows->notes)->toStartWith('Decision: potrdil David 2026-10-05.')
            ->and($windows->notes)->toContain('no literature number');
        // Since David's answers (2026-10-05) no imported value is an open proposal —
        // except the M5-R02 teething chewing chance and the M5-R03 training
        // effects (Claude, waiting for David). The training multiplier, the
        // individual variation, the M5-R03b numbers (minutes, progress, decay)
        // and the starting progress are David's decisions (verified).
        expect(BreedStageParam::where('verified', false)->pluck('key')->unique()->values()->all())
            ->toEqualCanonicalizing([
                StageParamKey::ChewingChancePerDay->value,
                StageParamKey::PottyTrainingAccidentReduction->value,
                StageParamKey::PlaceTrainingChewingReduction->value,
            ]);
    });

    it('marks David\'s 2026-10-05 answers verified as decisions and keeps the underlying source ids — data_verified turns true', function () {
        seedLifeStageData();
        $confirmed = fn (string $breed, string $stage, string $key) => BreedStageParam::where(['breed_slug' => $breed, 'stage' => $stage, 'key' => $key])->orderBy('age_from_months')->get();

        foreach (['mutt', 'border-collie'] as $breed) {
            foreach ([['young', 'starts_at_months'], ['adult', 'starts_at_months'], ['senior', 'starts_at_months'], ['puppy', 'arrival_age_months'],
                ['adult', 'arrival_age_months'], ['senior', 'meals_per_day'], ['puppy', 'feed_windows'], ['puppy', 'exercise_minutes_per_age_month'],
                ['young', 'exercise_minutes_per_age_month'], ['senior', 'exercise_minutes_per_day']] as [$stage, $key]) {
                foreach ($confirmed($breed, $stage, $key) as $row) {
                    expect($row->verified)->toBeTrue("{$breed}.{$stage}.{$key}")
                        ->and($row->notes)->toStartWith('Decision: potrdil David 2026-10-05.');
                }
            }
        }
        // The evidence stays the literature's (never replaced by the decision).
        expect($confirmed('mutt', 'senior', 'starts_at_months')->sole())->source_id->toBe('S11,S15')->confidence->toBe('medium')
            ->and($confirmed('mutt', 'puppy', 'arrival_age_months')->sole())->source_id->toBe('S36')
            ->and($confirmed('mutt', 'puppy', 'exercise_minutes_per_age_month')->sole())->source_id->toBe('S24')->confidence->toBe('low')
            ->and($confirmed('mutt', 'adult', 'exercise_minutes_per_day')->sole()->source_id)->toBeNull()
            ->and($confirmed('mutt', 'adult', 'exercise_minutes_per_day')->sole()->value)->toBe(60)
            ->and($confirmed('mutt', 'senior', 'exercise_minutes_per_day')->sole()->value)->toBe(45)
            ->and($confirmed('border-collie', 'senior', 'exercise_minutes_per_day')->sole()->value)->toBe(90)
            // David's earlier decision (adult 2 meals) keeps its own marker.
            ->and($confirmed('mutt', 'adult', 'meals_per_day')->sole()->notes)->toStartWith('Decision: David 2026-10-05.');

        foreach ([['border_collie', 36], ['mutt', 2], ['mutt', 9], ['mutt', 108]] as [$breed, $age]) {
            $pet = Pet::factory()->state(['breed_type' => $breed])->create(['user_id' => User::factory()->child()->create()->id, 'arrival_age_months' => $age]);
            $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));
            expect($rules->verified())->toBeTrue("{$breed} {$age} months")
                ->and($rules->unverifiedKeys())->toBe([]);
        }
    });
});

describe('edits', function () {
    it('never resurrects a value an admin deleted (Filament delete action) or re-keyed', function () {
        seedLifeStageData();
        $admin = User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
        actingAs($admin);
        $count = BreedStageParam::count();

        // Delete through the Filament edit page header action (model delete → audit row with the tuple).
        $sleep = BreedStageParam::where(['breed_slug' => 'mutt', 'stage' => 'senior', 'key' => 'sleep_hours'])->sole();
        Livewire::test(EditBreedStageParam::class, ['record' => $sleep->getRouteKey()])
            ->callAction(DeleteAction::class);
        // Re-key through the model: puppy meals band 3 → 4 months.
        BreedStageParam::where(['breed_slug' => 'mutt', 'stage' => 'puppy', 'age_from_months' => 3, 'key' => 'meals_per_day'])->sole()
            ->update(['age_from_months' => 4]);
        // Move a Border Collie value to the mutt (breed re-key).
        BreedStageParam::where(['breed_slug' => 'border-collie', 'stage' => 'all', 'key' => 'lifespan_years'])->sole()->delete();
        BreedStageParam::where(['breed_slug' => 'mutt', 'stage' => 'all', 'key' => 'lifespan_years'])->sole()
            ->update(['breed_slug' => 'border-collie', 'value' => 13.5]);

        expect(BreedStageParamChange::where('action', 'deleted')->where('key', 'sleep_hours')->sole())
            ->breed_slug->toBe('mutt')->stage->toBe('senior')->age_from_months->toBe(0)->user_id->toBe($admin->id);

        (new BreedStageParamsSeeder)->run();
        (new BreedStageParamsSeeder)->run();

        expect(BreedStageParam::where(['breed_slug' => 'mutt', 'stage' => 'senior', 'key' => 'sleep_hours'])->exists())->toBeFalse()
            ->and(BreedStageParam::where(['breed_slug' => 'mutt', 'stage' => 'puppy', 'key' => 'meals_per_day'])->pluck('age_from_months')->sort()->values()->all())->toBe([0, 4, 6])
            ->and(BreedStageParam::where(['breed_slug' => 'mutt', 'stage' => 'all', 'key' => 'lifespan_years'])->exists())->toBeFalse()
            ->and(BreedStageParam::where(['breed_slug' => 'border-collie', 'stage' => 'all', 'key' => 'lifespan_years'])->value('value'))->toBe(13.5)
            ->and(BreedStageParam::count())->toBe($count - 2);
    });

    it('audits who changed which value (old → new) and refreshes the cached rules', function () {
        seedLifeStageData();
        $admin = User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
        $pet = Pet::factory()->mutt()->create(['user_id' => User::factory()->child()->create()->id, 'arrival_age_months' => 36]);
        expect(app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()))->stepGoal)->toBe(6000);

        actingAs($admin);
        $param = BreedStageParam::where(['breed_slug' => 'mutt', 'stage' => 'adult', 'key' => 'exercise_minutes_per_day'])->sole();
        $param->update(['value' => 70, 'verified' => false, 'notes' => 'Trial value']);

        $change = BreedStageParamChange::sole();
        expect($change)->action->toBe('updated')->user_id->toBe($admin->id)->actor->toBeNull()->key->toBe('exercise_minutes_per_day')
            ->and($change->old['value'])->toBe(60)->and($change->new['value'])->toBe(70)
            ->and($change->old['verified'])->toBeTrue()
            ->and($param->fresh()->updated_by)->toBe($admin->id)
            ->and(app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()))->stepGoal)->toBe(7000);
    });

    it('lists the data in Filament with unverified rows marked and validates edited values per key', function () {
        seedLifeStageData();
        actingAs(User::factory()->create(['role' => 'parent', 'is_superadmin' => true]));
        // Every imported value is verified since 2026-10-05: plant one open proposal (no audit row).
        DB::table('breed_stage_params')->where(['breed_slug' => 'mutt', 'stage' => 'senior', 'key' => 'sleep_hours'])->update(['verified' => false]);

        Livewire::test(ListBreedStageParams::class)
            ->assertOk()
            // 12 sleep_hours rows (2 breeds × 6) — all on one page, so the
            // planted mutt senior row is visible whatever the page size.
            ->set('tableRecordsPerPage', 50)
            ->searchTable('sleep_hours')
            ->assertSee('UNSOURCED — proposal')
            ->searchTable('feed_windows')
            ->assertSee('verified — decision')
            ->searchTable('meals_per_day')
            ->assertSee('S18');

        $param = BreedStageParam::where(['breed_slug' => 'mutt', 'stage' => 'puppy', 'age_from_months' => 0, 'key' => 'feed_windows'])->sole();
        Livewire::test(EditBreedStageParam::class, ['record' => $param->getRouteKey()])
            ->assertFormSet(['value' => '[["07:00","09:00"],["11:00","13:00"],["15:00","17:00"],["19:00","21:00"]]'])
            ->fillForm(['value' => '[["7am","8am"]]'])
            ->call('save')
            ->assertHasFormErrors(['value']);

        Livewire::test(EditBreedStageParam::class, ['record' => $param->getRouteKey()])
            ->fillForm(['value' => '[["06:30","07:30"],["11:00","12:00"],["15:00","16:00"],["19:00","20:00"]]'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($param->fresh()->value[0])->toBe(['06:30', '07:30'])
            ->and(BreedStageParamChange::where('breed_stage_param_id', $param->id)->value('action'))->toBe('updated');
        expect(DB::table('breed_stage_param_changes')->count())->toBe(1);
    });
});
