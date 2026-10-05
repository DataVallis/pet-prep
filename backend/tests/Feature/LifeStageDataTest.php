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

/** Source ids S1–S46 listed in sources.md. */
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
                // Claude proposal without a research entry (representative arrival ages).
                expect($row['verified'])->toBeFalse("{$label} has no data.json entry, so it must be unverified");

                continue;
            }

            $entry = ldData($row['ref']);
            $unsourced = str_contains((string) ($entry['notes'] ?? ''), 'UNSOURCED') || ($entry['source_id'] ?? null) === null && ! isset($entry['derived_from']);

            if ($unsourced && $row['decision'] === null) {
                expect($row['verified'])->toBeFalse("{$label} is UNSOURCED in data.json");
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
        // Every value passes its key's shape check.
        foreach (BreedStageParamsSeeder::rows() as $row) {
            expect(StageParamKey::from($row['key'])->validate($row['value']))->toBeNull();
        }
    });

    it('imports insert-only: an existing (edited) row is never overwritten, missing rows are added', function () {
        seedLifeStageData();
        $count = BreedStageParam::count();
        expect($count)->toBe(count(BreedStageParamsSeeder::rows()));

        BreedStageParam::where(['breed_slug' => 'mutt', 'stage' => 'adult', 'key' => 'exercise_minutes_per_day'])->update(['value' => json_encode(75)]);
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
            ->and($windows)->source_id->toBeNull()->verified->toBeFalse()
            ->and($windows->notes)->toContain('Claude proposal');
        expect(BreedStageParam::where('verified', false)->pluck('key')->unique()->sort()->values()->all())
            ->toBe(['arrival_age_months', 'exercise_minutes_per_age_month', 'exercise_minutes_per_day', 'feed_windows']);
    });
});

describe('edits', function () {
    it('audits who changed which value (old → new) and refreshes the cached rules', function () {
        seedLifeStageData();
        $admin = User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
        $pet = Pet::factory()->mutt()->create(['user_id' => User::factory()->child()->create()->id, 'arrival_age_months' => 36]);
        expect(app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()))->stepGoal)->toBe(6000);

        actingAs($admin);
        $param = BreedStageParam::where(['breed_slug' => 'mutt', 'stage' => 'adult', 'key' => 'exercise_minutes_per_day'])->sole();
        $param->update(['value' => 70, 'verified' => true, 'notes' => 'David approved']);

        $change = BreedStageParamChange::sole();
        expect($change)->action->toBe('updated')->user_id->toBe($admin->id)->key->toBe('exercise_minutes_per_day')
            ->and($change->old['value'])->toBe(60)->and($change->new['value'])->toBe(70)
            ->and($change->old['verified'])->toBeFalse()
            ->and($param->fresh()->updated_by)->toBe($admin->id)
            ->and(app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()))->stepGoal)->toBe(7000);
    });

    it('lists the data in Filament with unverified rows marked and validates edited values per key', function () {
        seedLifeStageData();
        actingAs(User::factory()->create(['role' => 'parent', 'is_superadmin' => true]));

        Livewire::test(ListBreedStageParams::class)
            ->assertOk()
            ->assertSee('UNSOURCED — proposal')
            ->searchTable('meals_per_day')
            ->assertSee('S18');

        $param = BreedStageParam::where(['breed_slug' => 'mutt', 'stage' => 'puppy', 'age_from_months' => 0, 'key' => 'feed_windows'])->sole();
        Livewire::test(EditBreedStageParam::class, ['record' => $param->getRouteKey()])
            ->assertFormSet(['value' => '[["07:00","08:00"],["11:00","12:00"],["15:00","16:00"],["19:00","20:00"]]'])
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
