<?php

use App\Enums\BreedType;
use App\Enums\StageParamKey;
use App\Models\BreedStageParam;
use App\Models\BreedStageParamChange;
use Database\Seeders\BreedStageParamsSeeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| David's routine decisions of 2026-10-12 — the production data migration
|--------------------------------------------------------------------------
| 2026_12_01_120000_apply_david_dog_grooming_decisions moves the seven dog
| grooming rows PR #195 seeded (insert-only, so production keeps them) to
| David's decisions: the five "more than once a week" breeds 2/week
| confirmed, the Cavalier 7 → 4, the GSP 2 confirmed. Rows an admin edited
| in Filament are never touched; down() restores the old values.
*/

function ddMigration(): object
{
    return require database_path('migrations/2026_12_01_120000_apply_david_dog_grooming_decisions.php');
}

/** Put a target row back to the columns PR #195 seeded (the state in production). */
function ddSeedOld(array $target): void
{
    [$breed, $stage, $from, $key, $old] = $target;
    DB::table('breed_stage_params')->where(['breed_slug' => $breed, 'stage' => $stage, 'age_from_months' => $from, 'key' => $key])
        ->update(array_merge($old, ['value' => json_encode($old['value'])]));
}

function ddRow(array $target): BreedStageParam
{
    [$breed, $stage, $from, $key] = $target;

    return BreedStageParam::where(['breed_slug' => $breed, 'stage' => $stage, 'age_from_months' => $from, 'key' => $key])->sole();
}

it('freezes the new columns as the seeder writes them today, and the old ones as PR #195 seeded them', function () {
    $migration = ddMigration();
    $targets = $migration::targets();
    expect(array_map(fn (array $t) => $t[0], $targets))->toBe([
        'german-shepherd-dog', 'australian-shepherd', 'west-highland-white-terrier', 'bernese-mountain-dog', 'siberian-husky',
        'cavalier-king-charles-spaniel', 'german-shorthaired-pointer',
    ]);

    $rows = collect(BreedStageParamsSeeder::rows());
    foreach ($targets as [$breed, $stage, $from, $key, $old, $new]) {
        $row = $rows->first(fn (array $r) => $r['breed_slug'] === $breed && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key);
        $columns = BreedStageParamsSeeder::columns($row);
        $columns['value'] = json_decode((string) $columns['value'], true);
        expect($new)->toBe($columns, $breed)
            ->and($key)->toBe(StageParamKey::GroomingSessionsPerWeek->value)
            // Only value / verified / notes change; the source and its quote stay.
            ->and(array_diff_key($old, ['value' => 1, 'verified' => 1, 'notes' => 1]))->toBe(array_diff_key($new, ['value' => 1, 'verified' => 1, 'notes' => 1]));
    }
    $byBreed = collect($targets)->keyBy(0);
    expect($byBreed['cavalier-king-charles-spaniel'][4]['value'])->toBe(7)
        ->and($byBreed['cavalier-king-charles-spaniel'][5]['value'])->toBe(4)
        ->and($byBreed['cavalier-king-charles-spaniel'][5]['source_id'])->toBe('S108')
        ->and($byBreed['german-shepherd-dog'][4]['verified'])->toBeFalse()
        ->and($byBreed['german-shepherd-dog'][4]['notes'])->toContain(BreedStageParamsSeeder::PROPOSED_R13_DOG_GROOMING)
        ->and($byBreed['german-shepherd-dog'][5]['verified'])->toBeTrue()
        ->and($byBreed['german-shepherd-dog'][5]['notes'])->toStartWith('Decision: potrdil David 2026-10-12: 2× na teden.')
        ->and($byBreed['german-shorthaired-pointer'][5]['notes'])->toStartWith('Decision: potrdil David 2026-10-12.');
});

it('applies the decisions to the seeded rows with one audit row each, skips admin-edited rows, and down() restores the old values', function () {
    seedLifeStageData();
    $migration = ddMigration();
    $targets = collect($migration::targets())->keyBy(0);
    foreach ($targets as $t) {
        ddSeedOld($t);
    }
    // An admin changed the Husky's row in Filament (audit row) — it must stay as it is.
    $husky = $targets['siberian-husky'];
    DB::table('breed_stage_params')->where('id', ddRow($husky)->id)->update(['value' => json_encode(3)]);
    BreedStageParamChange::create([
        'breed_stage_param_id' => ddRow($husky)->id, 'breed_slug' => 'siberian-husky', 'stage' => 'all', 'age_from_months' => 0,
        'key' => StageParamKey::GroomingSessionsPerWeek->value, 'action' => 'updated', 'user_id' => null,
        'old' => ['value' => 2], 'new' => ['value' => 3],
    ]);
    // A row changed outside Filament (no audit row) is skipped too.
    $westie = $targets['west-highland-white-terrier'];
    DB::table('breed_stage_params')->where('id', ddRow($westie)->id)->update(['value' => json_encode(5)]);

    $migration->up();

    expect(ddRow($targets['cavalier-king-charles-spaniel'])->value)->toBe(4)
        ->and(ddRow($targets['cavalier-king-charles-spaniel'])->verified)->toBeTrue()
        ->and(ddRow($targets['german-shepherd-dog'])->value)->toBe(2)
        ->and(ddRow($targets['german-shepherd-dog'])->verified)->toBeTrue()
        ->and(ddRow($targets['german-shepherd-dog'])->notes)->toStartWith('Decision: potrdil David 2026-10-12: 2× na teden.')
        ->and(ddRow($targets['german-shorthaired-pointer'])->notes)->toStartWith('Decision: potrdil David 2026-10-12.')
        ->and(ddRow($husky)->value)->toBe(3)
        ->and(ddRow($husky)->verified)->toBeFalse()
        ->and(ddRow($westie)->value)->toBe(5)
        ->and(ddRow($westie)->verified)->toBeFalse();
    $audits = BreedStageParamChange::where('actor', $migration::ACTOR)->get();
    expect($audits->pluck('breed_slug')->sort()->values()->all())
        ->toBe(['australian-shepherd', 'bernese-mountain-dog', 'cavalier-king-charles-spaniel', 'german-shepherd-dog', 'german-shorthaired-pointer'])
        ->and($audits->firstWhere('breed_slug', 'cavalier-king-charles-spaniel')->new)->toMatchArray(['value' => 4]);

    // Idempotent: a second run writes nothing.
    $migration->up();
    expect(BreedStageParamChange::where('actor', $migration::ACTOR)->count())->toBe(5);
    // The seeder (insert-only) never brings the old values back.
    (new BreedStageParamsSeeder)->run();
    expect(ddRow($targets['cavalier-king-charles-spaniel'])->value)->toBe(4);

    // down(): the old values on the rows this migration changed; nothing deleted, admin rows untouched.
    $auditCount = BreedStageParamChange::count();
    $migration->down();
    expect(ddRow($targets['cavalier-king-charles-spaniel'])->value)->toBe(7)
        ->and(ddRow($targets['german-shepherd-dog'])->verified)->toBeFalse()
        ->and(ddRow($targets['german-shepherd-dog'])->notes)->toBe($targets['german-shepherd-dog'][4]['notes'])
        ->and(ddRow($husky)->value)->toBe(3)
        ->and(ddRow($westie)->value)->toBe(5)
        ->and(BreedStageParamChange::count())->toBe($auditCount + 5)
        ->and(BreedStageParamChange::where('actor', $migration::ROLLBACK_ACTOR)->count())->toBe(5);

    // up() again re-applies (only its own audit rows on those tuples).
    $migration->up();
    expect(ddRow($targets['cavalier-king-charles-spaniel'])->value)->toBe(4);
});

it('leaves a fresh database to the seeder (no rows yet → nothing to do)', function () {
    $migration = ddMigration();
    $migration->up();
    expect(BreedStageParamChange::count())->toBe(0);

    seedLifeStageData();
    $cavalier = ddRow(collect($migration::targets())->keyBy(0)['cavalier-king-charles-spaniel']);
    expect($cavalier->value)->toBe(4)->and($cavalier->verified)->toBeTrue();
    expect(BreedStageParamsSeeder::dogGrooming(BreedType::CavalierKingCharlesSpaniel)[0])->toBe(4);
});
