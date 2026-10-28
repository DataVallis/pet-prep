<?php

use App\Enums\Species;
use App\Models\BreedStageParam;
use App\Models\BreedStageParamChange;
use App\Models\Pet;
use App\Models\PetContract;
use App\Models\User;
use App\Services\FamilyService;
use App\Services\LifeStageService;
use App\Services\TrainingService;
use Database\Seeders\BreedStageParamsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\getJson;

/*
|--------------------------------------------------------------------------
| David 2026-10-28: 7 minutes of school mini-game per dog and day (was 5)
|--------------------------------------------------------------------------
| A session is ~60 s now (6 cues) and a start reserves the pet's longest
| session, so 5 min gave ~4–6 sessions; 7 min gives ~6 again. The data
| migration 2027_02_08 lifts every row still at 5 to 7 (audit row each), the
| seeder / data.json give new breeds 7.
*/

const TSM_MIGRATION = '2027_02_08_120000_apply_david_training_7_minutes.php';

beforeEach(function () {
    seedLifeStageData();
});

function tsmMigrate(): void
{
    (require database_path('migrations/'.TSM_MIGRATION))->up();
}

function tsmRows()
{
    return BreedStageParam::where('key', 'training_minutes_per_day')->orderBy('id')->get();
}

describe('7 minutes a day', function () {
    it('seeds 7 minutes for every dog breed (new breeds included) from data.json; cats have no school budget', function () {
        $data = json_decode((string) file_get_contents(base_path('../docs/research/dog-data/data.json')), true, flags: JSON_THROW_ON_ERROR);
        $seeded = array_filter(BreedStageParamsSeeder::rows(), fn (array $r) => $r['key'] === 'training_minutes_per_day');

        expect($data['proposed_game_parameters']['training_minigame_minutes']['value'])->toBe(7)
            ->and(count($seeded))->toBe(count(breedSlugsOf(Species::Dog)))
            ->and(array_unique(array_column($seeded, 'value')))->toBe([7])
            ->and(tsmRows()->pluck('value')->unique()->values()->all())->toBe([7])
            ->and(tsmRows()->first()->notes)->toStartWith('Decision: potrdil David 2026-10-28 (7 min')
            ->and(collect(BreedStageParamsSeeder::catRows())->where('key', 'training_minutes_per_day')->count())->toBe(0);

        // A breed seeded later (an unattended breed run) gets 7 too: its rows are inserted by the seeder.
        $slug = tsmRows()->last()->breed_slug;
        BreedStageParam::where('breed_slug', $slug)->where('key', 'training_minutes_per_day')->delete();
        (new BreedStageParamsSeeder)->run();
        expect(BreedStageParam::where('breed_slug', $slug)->where('key', 'training_minutes_per_day')->sole()->value)->toBe(7);
    });

    it('lifts every row still at 5 to 7 with one system audit row each, leaves any other value alone, idempotent', function () {
        // Production before the decision: every row at 5; an admin had set one breed to 10.
        DB::table('breed_stage_params')->where('key', 'training_minutes_per_day')->update(['value' => json_encode(5), 'notes' => 'old']);
        $custom = tsmRows()->last();
        DB::table('breed_stage_params')->where('id', $custom->id)->update(['value' => json_encode(10)]);
        $otherKeys = DB::table('breed_stage_params')->where('key', '!=', 'training_minutes_per_day')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        LifeStageService::forgetBreed('mutt');

        tsmMigrate();

        $rows = tsmRows();
        $changed = $rows->where('id', '!=', $custom->id);
        expect($changed->pluck('value')->unique()->values()->all())->toBe([7])
            ->and($changed->pluck('notes')->unique()->values()->all())->toBe([(require database_path('migrations/'.TSM_MIGRATION))::NOTES])
            ->and($rows->firstWhere('id', $custom->id)->value)->toBe(10)
            ->and(DB::table('breed_stage_params')->where('key', '!=', 'training_minutes_per_day')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())->toBe($otherKeys);

        $audits = BreedStageParamChange::where('actor', 'system: David decision 2026-10-28')->get();
        expect($audits)->toHaveCount($changed->count())
            ->and($audits->pluck('action')->unique()->all())->toBe(['updated'])
            ->and($audits->pluck('user_id')->unique()->all())->toBe([null])
            ->and($audits->first()->old['value'])->toBe(5)
            ->and($audits->first()->new['value'])->toBe(7);

        // The game reads it at once (cache forgotten after the commit).
        [, $child, $pet] = tsmFamily();
        expect(app(TrainingService::class)->dailyBudgetSeconds($pet, '2026-10-08'))->toBe(420);

        // A second run changes nothing; the seeder keeps the tuples (audited) as they are.
        tsmMigrate();
        (new BreedStageParamsSeeder)->run();
        expect(BreedStageParamChange::where('actor', 'system: David decision 2026-10-28')->count())->toBe($audits->count())
            ->and(tsmRows()->firstWhere('id', $custom->id)->value)->toBe(10);
    });

    it('splits the 7 minutes fairly: 2 children get 210 s each (≈ 3 sessions), 1 child alone ≈ 6–7 sessions', function () {
        [$parent, $child, $pet] = tsmFamily();
        $sibling = User::factory()->child()->create(['parent_id' => $parent->id]);
        app(FamilyService::class)->addCaretaker($pet, $sibling, true);
        PetContract::create(['pet_id' => $pet->id, 'user_id' => $sibling->id, 'signature_format' => 'svg_path', 'signature' => 'M1 1 L2 2', 'signed_at' => now()]);

        app('auth')->forgetGuards();
        actingAsRole($child);
        getJson('/api/child/pet')->assertOk()
            ->assertJsonPath('training.daily_budget_seconds', 420)
            ->assertJsonPath('training.children_sharing', 2)
            ->assertJsonPath('training.my_share_seconds', 210)
            ->assertJsonPath('training.my_seconds_left', 210)
            ->assertJsonPath('training.session_seconds', 58);

        // 210 s / a 58 s reserve: at least 3 sessions per child (sessions last 33–58 s).
        expect(intdiv(210 - 58, 58) + 1)->toBe(3)
            ->and(intdiv(420 - 58, 58) + 1)->toBe(7);
    });
});

/** @return array{0: User, 1: User, 2: Pet} */
function tsmFamily(): array
{
    Carbon::setTestNow(Carbon::parse('2026-10-07 05:00:00', 'UTC'));
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    $pet = Pet::factory()->mutt()->create([
        'user_id' => $child->id, 'born_at' => now(), 'last_decay_at' => now(), 'last_step_reset_at' => now(),
        'arrival_age_months' => 2, 'training_enabled' => true,
    ]);
    disableHygieneEvents($pet);
    Carbon::setTestNow(Carbon::parse('2026-10-08 08:00:00', 'UTC'));

    return [$parent, $child, $pet->fresh()];
}
