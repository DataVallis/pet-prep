<?php

use App\Enums\RoutineType;
use App\Models\BreedStageParam;
use App\Models\BreedStageParamChange;
use App\Models\Pet;
use App\Models\User;
use App\Services\CareScheduleService;
use App\Services\LifeStageService;
use App\Services\RoutineLedgerService;
use Database\Seeders\BreedStageParamsSeeder;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| M5-R01b — David's answers to the M5-R01 open questions (2026-10-05):
| 2-hour puppy feed windows, confirmed stage boundaries / arrival ages /
| exercise minutes / senior meals (verified = true, "potrdil David
| 2026-10-05"), the one-off data migration for rows PR #37 already seeded
| in production, and legacy pets on the old rules permanently.
|--------------------------------------------------------------------------
| Family in Europe/Ljubljana (UTC+2 until 2026-10-25).
*/

const DSD_MIGRATION = '2026_10_12_120000_apply_david_stage_decisions.php';

beforeEach(function () {
    seedLifeStageData();
    $this->withoutMiddleware([ThrottleRequests::class]);
});

function dsdAt(string $utc): void
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
}

/**
 * A born pet (default: 2-month mutt puppy with a profile) of a child in a Ljubljana family.
 *
 * @return array{0: User, 1: User, 2: Pet}
 */
function dsdFamily(string $bornUtc, array $attributes = []): array
{
    dsdAt($bornUtc);
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    $pet = disableHygieneEvents(Pet::factory()->mutt()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => Carbon::parse($bornUtc, 'UTC'),
        'arrival_age_months' => 2,
    ], $attributes)));

    return [$parent, $child, $pet->fresh()];
}

/** Feed windows starting on a local date as "HH:MM-HH:MM". */
function dsdWindows(Pet $pet, string $date): array
{
    $pet = $pet->fresh();

    return array_map(
        fn (array $w): string => $w[0]->format('H:i').'-'.$w[1]->format('H:i'),
        app(CareScheduleService::class)->feedWindowsStartingOn($pet, $pet->breedConfig(), $date),
    );
}

function dsdMigrate(): void
{
    (require database_path('migrations/'.DSD_MIGRATION))->up();
}

/** The confirmed seeder rows (David 2026-10-05). */
function dsdConfirmedRows(): array
{
    return array_values(array_filter(BreedStageParamsSeeder::rows(), fn (array $r) => $r['decision'] === BreedStageParamsSeeder::CONFIRMED));
}

/**
 * Put the table into the production state after PR #37: the confirmed rows
 * unverified, PR #37 notes, 1-hour puppy windows (seeder writes, no audit).
 */
function dsdRevertToPr37(): void
{
    $pr37Windows = [
        0 => [['07:00', '08:00'], ['11:00', '12:00'], ['15:00', '16:00'], ['19:00', '20:00']],
        3 => [['07:00', '08:00'], ['13:00', '14:00'], ['19:00', '20:00']],
    ];

    foreach (dsdConfirmedRows() as $row) {
        $update = ['verified' => false, 'notes' => 'PR #37: Claude proposal, waiting for David (DECISIONS 2026-10-05).'];
        if ($row['key'] === 'feed_windows') {
            $update['value'] = json_encode($pr37Windows[$row['age_from_months']]);
            $update['data_ref'] = 'proposed_game_parameters.feed_windows';
        }
        DB::table('breed_stage_params')->where([
            'breed_slug' => $row['breed_slug'], 'stage' => $row['stage'],
            'age_from_months' => $row['age_from_months'], 'key' => $row['key'],
        ])->update($update);
    }
    LifeStageService::forgetBreed('mutt');
    LifeStageService::forgetBreed('border-collie');
}

function dsdParam(string $breed, string $stage, int $from, string $key): BreedStageParam
{
    return BreedStageParam::where(['breed_slug' => $breed, 'stage' => $stage, 'age_from_months' => $from, 'key' => $key])->sole();
}

describe('2-hour puppy feed windows', function () {
    it('gives 4 meals 07–09, 11–13, 15–17, 19–21 and 3 meals 07–09, 13–15, 19–21; 2 meals keep the breed windows', function () {
        [, , $puppy] = dsdFamily('2026-10-05 08:00:00');
        [, , $older] = dsdFamily('2026-10-05 08:00:00', ['arrival_age_months' => 3]);
        [, , $young] = dsdFamily('2026-10-05 08:00:00', ['arrival_age_months' => 6]);

        expect(dsdWindows($puppy, '2026-10-06'))->toBe(['07:00-09:00', '11:00-13:00', '15:00-17:00', '19:00-21:00'])
            ->and(dsdWindows($older, '2026-10-06'))->toBe(['07:00-09:00', '13:00-15:00', '19:00-21:00'])
            ->and(dsdWindows($young, '2026-10-06'))->toBe(['06:00-10:00', '17:00-21:00']);
        foreach (dsdWindows($puppy, '2026-10-06') as $w) {
            [$start, $end] = explode('-', $w);
            expect((strtotime($end) - strtotime($start)) / 3600)->toEqual(2);
        }
        // The fallback for an unexpected meal count follows the same 2-hour rule (never overlapping).
        expect(app(LifeStageService::class)->derivedWindows(4))->toBe(BreedStageParamsSeeder::PUPPY_4_MEAL_WINDOWS)
            ->and(app(LifeStageService::class)->derivedWindows(3))->toBe(BreedStageParamsSeeder::PUPPY_3_MEAL_WINDOWS)
            ->and(app(LifeStageService::class)->derivedWindows(13)[0])->toBe(['07:00', '08:00']);
    });

    it('counts a feed at 08:30 for a 2-month puppy (07–09 window) and refuses one at 09:00 until 11:00', function () {
        // Born Monday 2026-10-05 10:00 local (Europe/Ljubljana, UTC+2).
        [, $child, $pet] = dsdFamily('2026-10-05 08:00:00');
        actingAsRole($child);

        dsdAt('2026-10-06 06:30:00'); // Tuesday 08:30 local
        postJson('/api/child/pet/feed')->assertOk();

        dsdAt('2026-10-06 07:00:00'); // 09:00 local — the window is [07:00, 09:00)
        postJson('/api/child/pet/feed')->assertStatus(422)
            ->assertJsonPath('reason', 'outside_feed_window')
            ->assertJsonPath('next_allowed_at', '2026-10-06T11:00:00+02:00');

        dsdAt('2026-10-06 07:30:00');
        $feeds = collect(app(RoutineLedgerService::class)->routinesFor(collect([$pet->fresh()]), '2026-10-06', '2026-10-06')[$pet->id])
            ->filter(fn ($r) => $r->type === RoutineType::Feed)->values();
        expect($feeds)->toHaveCount(4)
            ->and($feeds[0]->opensAt->setTimezone('Europe/Ljubljana')->format('H:i'))->toBe('07:00')
            ->and($feeds[0]->dueAt->setTimezone('Europe/Ljubljana')->format('H:i'))->toBe('09:00')
            ->and($feeds[0]->isDone())->toBeTrue()
            ->and($feeds[1]->isPending())->toBeTrue();
    });
});

describe('data migration for the rows PR #37 seeded', function () {
    it('applies exactly the confirmed rows: 2-hour windows, verified, decision in the notes, one system audit row each', function () {
        dsdRevertToPr37();
        $before = DB::table('breed_stage_params')->orderBy('id')->get()->keyBy('id');
        $targets = count(dsdConfirmedRows());

        dsdMigrate();

        expect(dsdParam('mutt', 'puppy', 0, 'feed_windows')->value)->toBe(BreedStageParamsSeeder::PUPPY_4_MEAL_WINDOWS)
            ->and(dsdParam('border-collie', 'puppy', 3, 'feed_windows')->value)->toBe(BreedStageParamsSeeder::PUPPY_3_MEAL_WINDOWS)
            // Open proposals: M5-R02's teething chewing chance and the M5-R03 training effects
            // (Claude, waiting for David; the training numbers were confirmed in M5-R03b).
            ->and(BreedStageParam::where('verified', false)->pluck('key')->unique()->values()->all())->toEqualCanonicalizing([
                'chewing_chance_per_day', 'potty_training_accident_reduction', 'place_training_chewing_reduction',
            ]);

        // Every confirmed row now equals a fresh seed of the same tuple (value + provenance).
        foreach (dsdConfirmedRows() as $row) {
            $stored = DB::table('breed_stage_params')->where([
                'breed_slug' => $row['breed_slug'], 'stage' => $row['stage'],
                'age_from_months' => $row['age_from_months'], 'key' => $row['key'],
            ])->first();
            $expected = BreedStageParamsSeeder::columns($row);
            expect(json_decode($stored->value, true))->toBe($row['value'])
                ->and($stored->verified)->toBeTrue()
                ->and($stored->notes)->toBe($expected['notes'])
                ->and($stored->source_id)->toBe($expected['source_id'])
                ->and($stored->confidence)->toBe($expected['confidence'])
                ->and($stored->data_ref)->toBe($expected['data_ref']);
        }

        // Rows outside David's answers are untouched.
        $after = DB::table('breed_stage_params')->orderBy('id')->get()->keyBy('id');
        $changedIds = BreedStageParamChange::pluck('breed_stage_param_id')->all();
        foreach ($before as $id => $row) {
            if (! in_array($id, $changedIds, true)) {
                expect((array) $after[$id])->toBe((array) $row);
            }
        }

        $changes = BreedStageParamChange::orderBy('id')->get();
        expect($changes)->toHaveCount($targets)
            ->and($changes->pluck('action')->unique()->all())->toBe(['updated'])
            ->and($changes->pluck('user_id')->unique()->all())->toBe([null])
            ->and($changes->pluck('actor')->unique()->all())->toBe(['system: David decision 2026-10-05']);
        $window = $changes->firstWhere(fn ($c) => $c->breed_slug === 'mutt' && $c->key === 'feed_windows' && $c->age_from_months === 0);
        expect($window->old['value'])->toBe([['07:00', '08:00'], ['11:00', '12:00'], ['15:00', '16:00'], ['19:00', '20:00']])
            ->and($window->new['value'])->toBe(BreedStageParamsSeeder::PUPPY_4_MEAL_WINDOWS)
            ->and($window->old['verified'])->toBeFalse()
            ->and($window->new['verified'])->toBeTrue();
        $boundary = $changes->firstWhere(fn ($c) => $c->breed_slug === 'mutt' && $c->key === 'starts_at_months' && $c->stage === 'young');
        expect($boundary->new)->not->toHaveKey('value'); // the confirmed value itself did not change
    });

    it('is idempotent: a second run (and the seeder afterwards) changes nothing', function () {
        dsdRevertToPr37();
        dsdMigrate();
        $snapshot = DB::table('breed_stage_params')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $audits = BreedStageParamChange::count();

        dsdMigrate();
        (new BreedStageParamsSeeder)->run();

        expect(DB::table('breed_stage_params')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())->toBe($snapshot)
            ->and(BreedStageParamChange::count())->toBe($audits);
    });

    it('writes nothing on a database the new seeder filled (fresh install)', function () {
        dsdMigrate();

        expect(BreedStageParamChange::count())->toBe(0)
            ->and(dsdParam('mutt', 'puppy', 0, 'feed_windows')->value)->toBe(BreedStageParamsSeeder::PUPPY_4_MEAL_WINDOWS);
    });

    it('never overwrites a row an admin edited, re-keyed or deleted, and skips a row changed outside Filament', function () {
        dsdRevertToPr37();
        $admin = User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
        actingAs($admin);

        // Filament edit of the mutt 4-meal windows (audited, user = admin).
        $custom = [['06:30', '07:30'], ['11:00', '12:00'], ['15:00', '16:00'], ['19:00', '20:00']];
        dsdParam('mutt', 'puppy', 0, 'feed_windows')->update(['value' => $custom]);
        // Filament delete of the Border Collie senior minutes.
        dsdParam('border-collie', 'senior', 0, 'exercise_minutes_per_day')->delete();
        // Re-key: the mutt 3-meal windows moved to month 4 — the original tuple counts as touched.
        dsdParam('mutt', 'puppy', 3, 'feed_windows')->update(['age_from_months' => 4]);
        // A value changed with raw SQL (no audit row): neither the PR #37 value nor the confirmed one.
        DB::table('breed_stage_params')->where(['breed_slug' => 'border-collie', 'stage' => 'young', 'key' => 'starts_at_months'])->update(['value' => json_encode(10)]);
        app('auth')->forgetGuards();
        $adminAudits = BreedStageParamChange::count();

        Log::spy();
        dsdMigrate();

        $edited = dsdParam('mutt', 'puppy', 0, 'feed_windows');
        $outside = dsdParam('border-collie', 'young', 0, 'starts_at_months');
        $applied = dsdParam('border-collie', 'puppy', 0, 'feed_windows');
        expect($edited->value)->toBe($custom)
            ->and($edited->verified)->toBeFalse()
            ->and(dsdParam('mutt', 'puppy', 4, 'feed_windows')->verified)->toBeFalse()
            ->and(BreedStageParam::where(['breed_slug' => 'border-collie', 'stage' => 'senior', 'key' => 'exercise_minutes_per_day'])->exists())->toBeFalse()
            ->and($outside->value)->toBe(10)
            ->and($outside->verified)->toBeFalse()
            // Everything else was applied.
            ->and($applied->value)->toBe(BreedStageParamsSeeder::PUPPY_4_MEAL_WINDOWS)
            ->and($applied->verified)->toBeTrue()
            ->and(BreedStageParamChange::count())->toBe($adminAudits + count(dsdConfirmedRows()) - 4)
            ->and(BreedStageParamChange::whereNotNull('actor')->where('breed_slug', 'mutt')->where('key', 'feed_windows')->count())->toBe(0);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'changed outside Filament') && $ctx['tuple'] === 'border-collie|young|0|starts_at_months')->once();

        // The seeder still never resurrects the deleted value.
        (new BreedStageParamsSeeder)->run();
        expect(BreedStageParam::where(['breed_slug' => 'border-collie', 'stage' => 'senior', 'key' => 'exercise_minutes_per_day'])->exists())->toBeFalse();
    });

    it('refreshes the cached rules and the API reports data_verified for a stage pet', function () {
        dsdRevertToPr37();
        [, $child, $pet] = dsdFamily('2026-10-05 08:00:00');
        dsdAt('2026-10-06 06:30:00');
        // Rules cached with the PR #37 rows.
        expect(app(LifeStageService::class)->rulesOn($pet, '2026-10-06')->feedWindows[0])->toBe(['07:00', '08:00']);

        actingAsRole($child);
        $before = getJson('/api/child/pet')->assertOk()->json('pet.profile');
        expect($before['data_verified'])->toBeFalse()
            ->and($before['unverified'])->toContain('feed_windows', 'arrival_age_months');

        dsdMigrate();

        $profile = getJson('/api/child/pet')->assertOk()->json('pet.profile');
        expect($profile['data_verified'])->toBeTrue()
            ->and($profile['unverified'])->toBe([])
            ->and(collect($profile['today']['feed_windows'])->map(fn ($w) => $w['start'].'-'.$w['end'])->all())
            ->toBe(['07:00-09:00', '11:00-13:00', '15:00-17:00', '19:00-21:00']);
        postJson('/api/child/pet/feed')->assertOk(); // 08:30 local
    });
});

describe('frozen migration data and rollback', function () {
    it('freezes exactly the seeder\'s 28 confirmed rows (tuple + columns) as of today', function () {
        $migration = require database_path('migrations/'.DSD_MIGRATION);
        $fromSeeder = array_map(function (array $row): array {
            $columns = BreedStageParamsSeeder::columns($row);
            $columns['value'] = $row['value'];

            return [$row['breed_slug'], $row['stage'], $row['age_from_months'], $row['key'], $columns];
        }, dsdConfirmedRows());

        expect($migration::TARGETS)->toHaveCount(28)
            ->and($migration::TARGETS)->toBe($fromSeeder)
            ->and($migration->withinTransaction)->toBeFalse();
    });

    it('keeps the actor column and its audit rows on rollback, and a re-run changes nothing', function () {
        dsdRevertToPr37();
        dsdMigrate();
        $snapshot = DB::table('breed_stage_params')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $audits = DB::table('breed_stage_param_changes')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        expect(collect($audits)->pluck('actor')->unique()->all())->toBe(['system: David decision 2026-10-05']);

        // Roll back only the decision migration (newer migrations, e.g. M5-R02 / M5-R03, stay —
        // with --path the others in the step window are skipped as "not found"). The window
        // reaches back to the decision migration however many migrations come after it.
        $steps = DB::table('migrations')->where('migration', '>=', str_replace('.php', '', DSD_MIGRATION))->count();
        expect(Artisan::call('migrate:rollback', ['--step' => $steps, '--path' => 'database/migrations/'.DSD_MIGRATION]))->toBe(0);
        expect(DB::table('migrations')->where('migration', str_replace('.php', '', DSD_MIGRATION))->exists())->toBeFalse()
            ->and(Schema::hasColumn('breed_stage_param_changes', 'actor'))->toBeTrue()
            ->and(DB::table('breed_stage_param_changes')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())->toBe($audits);

        expect(Artisan::call('migrate'))->toBe(0);
        expect(DB::table('migrations')->where('migration', str_replace('.php', '', DSD_MIGRATION))->exists())->toBeTrue()
            ->and(DB::table('breed_stage_params')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())->toBe($snapshot)
            ->and(DB::table('breed_stage_param_changes')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())->toBe($audits);
    });
});

describe('legacy pets stay on the old rules permanently', function () {
    it('keeps breed windows and the breed step goal before and after the migration, also long after the challenge', function () {
        dsdRevertToPr37();
        [$parent, $child, $legacy] = dsdFamily('2026-10-05 08:00:00', ['arrival_age_months' => null]);
        $rules = fn (string $date) => app(LifeStageService::class)->rulesOn($legacy->fresh(), $date);
        $before = $rules('2026-10-06');

        dsdMigrate();

        foreach (['2026-10-06', '2026-12-28', '2027-06-01', '2028-01-01'] as $date) {
            $after = $rules($date);
            expect($after->lifeStage)->toBeNull()
                ->and($after->ageMonths)->toBeNull()
                ->and($after->feedWindows)->toBe([['06:00', '10:00'], ['17:00', '21:00']])
                ->and($after->feedWindows)->toBe($before->feedWindows)
                ->and($after->stepGoal)->toBe($before->stepGoal)
                ->and($after->provenance)->toBe([]);
        }

        // 09:30 local: outside every 2-hour puppy window, inside the legacy 06–10 window.
        dsdAt('2026-10-06 07:30:00');
        actingAsRole($child);
        postJson('/api/child/pet/feed')->assertOk();
        $profile = getJson('/api/child/pet')->assertOk()->json('pet.profile');
        expect($profile['legacy'])->toBeTrue()
            ->and($profile['life_stage'])->toBeNull()
            ->and(Pet::find($legacy->id)->isLegacyProfile())->toBeTrue();
    });
});
