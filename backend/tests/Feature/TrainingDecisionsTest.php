<?php

use App\Enums\StageParamKey;
use App\Enums\TrainingCommand;
use App\Events\PetUpdated;
use App\Models\BreedConfig;
use App\Models\BreedStageParam;
use App\Models\BreedStageParamChange;
use App\Models\Pet;
use App\Models\PetContract;
use App\Models\PetTrainingSession;
use App\Models\PetTrainingSkill;
use App\Models\User;
use App\Services\ChildProfileService;
use App\Services\FamilyService;
use App\Services\LifeStageService;
use App\Services\TrainingService;
use Database\Seeders\BreedStageParamsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| M5-R03b — David's training decisions (2026-10-06):
|  1. 5 min / day, +1 per praise, −2 per missed day → verified (seeder +
|     one-off data migration for the rows PR #53 seeded in production);
|  2. a dog arriving young / adult / senior starts with sit 50 %, potty 70 %,
|     come 30 %, place 0 % (puppy 0 %), from `training_starting_progress`;
|  3. the daily budget is split equally between the children who can train.
| David's answers of 2026-10-07: an unsigned child does not count in the
| fair share, shares are recalculated at once when a child signs mid-day
| (both already the behaviour), and the two effects (potty 0.75, place 0.5)
| are confirmed → verified (seeder + one-off data migration).
|--------------------------------------------------------------------------
| Family in Europe/Ljubljana (UTC+2 in early October 2026).
*/

const TD_MIGRATION = '2026_10_15_120000_apply_david_training_decisions.php';

const TD_CONFIRMED_KEYS = ['training_minutes_per_day', 'training_progress_per_success', 'training_decay_per_missed_day'];

const TD_EFFECTS_MIGRATION = '2026_10_16_120000_apply_david_training_effect_decisions.php';

const TD_EFFECT_KEYS = ['potty_training_accident_reduction', 'place_training_chewing_reduction'];

beforeEach(function () {
    seedLifeStageData();
});

function tdAt(string $utc): void
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
}

function tdMigrate(): void
{
    (require database_path('migrations/'.TD_MIGRATION))->up();
}

function tdParam(string $breed, string $stage, int $from, string $key): BreedStageParam
{
    return BreedStageParam::where(['breed_slug' => $breed, 'stage' => $stage, 'age_from_months' => $from, 'key' => $key])->sole();
}

function tdMigrateEffects(): void
{
    (require database_path('migrations/'.TD_EFFECTS_MIGRATION))->up();
}

/** @return list<array<string, mixed>> */
function tdEffectRows(): array
{
    // The data migration covered the breeds of that time (M5-R10 Labrador rows are seeded verified).
    return array_values(array_filter(BreedStageParamsSeeder::rows(), fn (array $r) => in_array($r['breed_slug'], ['mutt', 'border-collie'], true) && $r['stage'] === 'all' && in_array($r['key'], TD_EFFECT_KEYS, true)));
}

/** The production state after PR #59: the two effects seeded as unverified proposals. */
function tdRevertEffectsToPr59(): void
{
    $notes = [
        'potty_training_accident_reduction' => 'UNSOURCED proposal (Claude, M5-R03, waiting for David): a house-trained puppy learns to ask to go out (S47); the size of the effect is ours. Bladder hold (S30 / S31) unchanged.',
        'place_training_chewing_reduction' => 'UNSOURCED proposal (Claude, M5-R03, waiting for David): guidance teaches a puppy to chew its toys (S33); the size of the effect is ours. Chewing after a missed walk stays certain.',
    ];
    foreach ($notes as $key => $note) {
        // Production at that time had only the mutt and the Border Collie (M5-R10 added the Labrador).
        DB::table('breed_stage_params')->whereIn('breed_slug', ['mutt', 'border-collie'])->where(['stage' => 'all', 'key' => $key])->update(['verified' => false, 'notes' => $note]);
    }
    LifeStageService::forgetBreed('mutt');
    LifeStageService::forgetBreed('border-collie');
}

/** @return list<array<string, mixed>> */
function tdConfirmedRows(): array
{
    // The data migration covered the breeds of that time (M5-R10 Labrador rows are seeded verified).
    // David 2026-10-28 raised the minutes to 7 (2027_02_08 migration): the 2026-10-06 migration
    // froze the 5-minute row of that time, so the seeder's minutes row is mapped back to it.
    return array_values(array_map(
        fn (array $r): array => $r['key'] === 'training_minutes_per_day'
            ? ['value' => 5, 'decision' => BreedStageParamsSeeder::CONFIRMED_R03, 'notes' => TD_MINUTES_NOTES_2026_10_06] + $r
            : $r,
        array_filter(BreedStageParamsSeeder::rows(), fn (array $r) => in_array($r['breed_slug'], ['mutt', 'border-collie'], true) && $r['stage'] === 'all' && in_array($r['key'], TD_CONFIRMED_KEYS, true)),
    ));
}

/** The seeder's notes for the 5-minute row until David's 7-minute decision (2026-10-28). */
const TD_MINUTES_NOTES_2026_10_06 = 'Game value (no literature number): S36 / S37 say 5–10 min sessions and ≤ 15 min a day for puppies; 5 min = the dog\'s daily budget (≈ 6 sessions of 50 s), same for every stage, split equally between the children who care for the dog (M5-R03b).';

/** The production state after PR #53: the three numbers seeded as unverified proposals. */
function tdRevertToPr53(): void
{
    $notes = [
        'training_minutes_per_day' => 'UNSOURCED proposal (Claude, M5-R03, waiting for David): S36 / S37 say 5–10 min sessions and ≤ 15 min a day for puppies; 5 min = the daily budget (≈ 6 sessions of 50 s), same for every stage.',
        'training_progress_per_success' => 'UNSOURCED proposal (Claude, M5-R03, waiting for David): game balance — about 16 good sessions per command for a mixed breed.',
        'training_decay_per_missed_day' => 'UNSOURCED proposal (Claude, M5-R03, waiting for David): no source gives a forgetting rate.',
    ];
    foreach ($notes as $key => $note) {
        // Production at that time had only the mutt and the Border Collie (M5-R10 added the Labrador).
        DB::table('breed_stage_params')->whereIn('breed_slug', ['mutt', 'border-collie'])->where(['stage' => 'all', 'key' => $key])->update(['verified' => false, 'notes' => $note]);
    }
    // At that time the budget was 5 minutes (7 since David 2026-10-28).
    DB::table('breed_stage_params')->whereIn('breed_slug', ['mutt', 'border-collie'])->where(['stage' => 'all', 'key' => 'training_minutes_per_day'])->update(['value' => json_encode(5)]);
    LifeStageService::forgetBreed('mutt');
    LifeStageService::forgetBreed('border-collie');
}

/**
 * A born dog with training (factory, like TrainingTest), random messes off.
 *
 * @return array{0: User, 1: User, 2: Pet}
 */
function tdFamily(string $bornUtc = '2026-10-07 05:00:00', int $arrival = 2): array
{
    tdAt($bornUtc);
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    $pet = Pet::factory()->mutt()->create([
        'user_id' => $child->id,
        'born_at' => Carbon::parse($bornUtc, 'UTC'),
        'last_decay_at' => Carbon::parse($bornUtc, 'UTC'),
        'last_step_reset_at' => Carbon::parse($bornUtc, 'UTC'),
        'arrival_age_months' => $arrival,
        'training_enabled' => true,
    ]);
    disableHygieneEvents($pet);

    return [$parent, $child, $pet->fresh()];
}

/** Another child caring for the pet; signs their contract at $signedUtc (null = not signed yet). */
function tdSibling(User $parent, Pet $pet, ?string $signedUtc = '2026-10-07 06:00:00'): User
{
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    app(FamilyService::class)->addCaretaker($pet, $child, true);
    if ($signedUtc !== null) {
        PetContract::create([
            'pet_id' => $pet->id, 'user_id' => $child->id,
            'signature_format' => 'svg_path', 'signature' => 'M1 1 L2 2',
            'signed_at' => Carbon::parse($signedUtc, 'UTC'),
        ]);
    }

    return $child;
}

function tdStart(User $child, string $command = 'sit'): TestResponse
{
    app('auth')->forgetGuards();
    actingAsRole($child);

    return postJson('/api/child/pet/training/start', ['command' => $command]);
}

/** @param list<int> $taps */
function tdFinish(User $child, string $sessionId, array $taps = []): TestResponse
{
    app('auth')->forgetGuards();
    actingAsRole($child);

    return postJson('/api/child/pet/training/finish', ['session_id' => $sessionId, 'taps' => $taps]);
}

/** Start at $utc and finish (no taps) at the scheduled end. */
function tdSession(User $child, string $utc): TestResponse
{
    tdAt($utc);
    $session = tdStart($child)->assertOk()->json('session');
    Carbon::setTestNow(Carbon::parse($session['ends_at'])->utc());

    return tdFinish($child, $session['id'])->assertOk();
}

/** Whole seconds a started session counts (its real length). */
function tdSeconds(array $session): int
{
    return intdiv((int) $session['duration_ms'] + 999, 1000);
}

/**
 * Sessions (2 min apart from $fromUtc) while one more fits $limit seconds — the dog's
 * (or, with $own, that child's) seconds used + the 58 s a start reserves. Returns the
 * seconds used by the dog / $own.
 */
function tdTrainUntilFull(User $child, Pet $pet, string $fromUtc, int $limit, ?User $own = null): int
{
    $service = app(TrainingService::class);
    $used = fn (): int => $service->usedSecondsOn($pet->fresh(), '2026-10-08', $own);
    for ($i = 0; $i < 16 && $used() + 58 <= $limit; $i++) {
        tdSession($child, Carbon::parse($fromUtc, 'UTC')->addMinutes(2 * $i)->toDateTimeString());
    }

    return $used();
}

function tdState(User $child): TestResponse
{
    app('auth')->forgetGuards();
    actingAsRole($child);

    return getJson('/api/child/pet')->assertOk();
}

/** Parent → generate-pin (profile + `training`) → child pin-login (+ `training`): the pet a current app creates. */
function tdCreateViaPin(string $origin, string $stage, bool $training = true): array
{
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = app(ChildProfileService::class)->createChild($parent, 'Maja', null);
    app('auth')->forgetGuards();
    actingAsRole($parent);
    $features = $training ? ['training'] : [];
    $pin = postJson('/api/parent/generate-pin', ['child_id' => $child->id, 'origin' => $origin, 'age_stage' => $stage, 'features' => $features])->assertOk();
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);
    $login = postJson('/api/child/pin-login', ['pin' => $pin->json('pin'), 'device_name' => 'Tablet', 'features' => $features])->assertSuccessful();
    test()->withHeaders(['Authorization' => '']);

    return [$child, Pet::findOrFail($login->json('pet.id'))];
}

/** @return array<string, float> progress by command (rows only) */
function tdSkills(Pet $pet): array
{
    return PetTrainingSkill::where('pet_id', $pet->id)->orderBy('command')->pluck('progress', 'command')->map(fn ($p) => (float) $p)->all();
}

// ──────────────────────────────────────────────────────────────
//  1. Confirmed numbers
// ──────────────────────────────────────────────────────────────

describe('confirmed numbers (5 min → 7 min since 2026-10-28, +1, −2)', function () {
    it('seeds them verified with David\'s decision', function () {
        $data = json_decode((string) file_get_contents(base_path('../docs/research/dog-data/data.json')), true, flags: JSON_THROW_ON_ERROR);

        foreach (['mutt', 'border-collie'] as $breed) {
            $minutes = tdParam($breed, 'all', 0, 'training_minutes_per_day');
            $gain = tdParam($breed, 'all', 0, 'training_progress_per_success');
            $decay = tdParam($breed, 'all', 0, 'training_decay_per_missed_day');
            expect($minutes->value)->toBe(7)->and($gain->value)->toEqual(1.0)->and($decay->value)->toEqual(2.0);
            foreach ([$minutes, $gain, $decay] as $row) {
                expect($row->verified)->toBeTrue()
                    ->and($row->notes)->toStartWith($row === $minutes ? 'Decision: potrdil David 2026-10-28 (7 min' : 'Decision: potrdil David 2026-10-06.')
                    ->and($row->confidence)->toBe('low');
            }
            expect($minutes->source_id)->toBe('S36,S37')->and($gain->source_id)->toBeNull();

        }

        foreach (['training_progress_per_success', 'training_decay_per_missed_day'] as $entry) {
            expect($data['proposed_game_parameters'][$entry]['decision'])->toStartWith('potrdil David 2026-10-06');
        }
        expect($data['proposed_game_parameters']['training_minigame_minutes']['decision'])->toStartWith('potrdil David 2026-10-28: 7 minutes')
            ->and($data['proposed_game_parameters']['training_minigame_minutes']['value'])->toBe(7);
        // The service reads them: 420 s budget.
        [, , $pet] = tdFamily();
        expect(app(TrainingService::class)->dailyBudgetSeconds($pet, '2026-10-08'))->toBe(420);
    });

    it('freezes exactly the seeder\'s 6 confirmed rows (tuple + columns) in the migration', function () {
        $migration = require database_path('migrations/'.TD_MIGRATION);
        $fromSeeder = array_map(function (array $row): array {
            $columns = BreedStageParamsSeeder::columns($row);
            $columns['value'] = $row['value'];

            return [$row['breed_slug'], $row['stage'], $row['age_from_months'], $row['key'], $columns];
        }, tdConfirmedRows());

        expect($migration::TARGETS)->toHaveCount(6)
            ->and($migration::TARGETS)->toBe($fromSeeder)
            ->and($migration->withinTransaction)->toBeFalse();
    });

    it('flips the PR #53 production rows to verified with one system audit row each, nothing else', function () {
        tdRevertToPr53();
        $before = DB::table('breed_stage_params')->orderBy('id')->get()->keyBy('id');

        tdMigrate();

        foreach (tdConfirmedRows() as $row) {
            $stored = tdParam($row['breed_slug'], 'all', 0, $row['key']);
            expect($stored->verified)->toBeTrue()
                ->and($stored->notes)->toBe(BreedStageParamsSeeder::columns($row)['notes'])
                ->and((float) $stored->value)->toBe((float) $row['value']);
        }
        expect(BreedStageParam::whereIn('breed_slug', BreedConfig::query()->select('breed_slug')->where('species', 'dog'))->where('verified', false)
            // M5-R10-12 / M5-R10-23 / M5-R10-32 / M5-R10-57: the Bernese (76), Cane Corso (84), Miniature American Shepherd (108) Samoyed (90), Scottish Terrier (90, M5-R10-58) Bloodhound (61, M5-R10-62) and Akita (89, M5-R10-63) senior boundaries are separate open proposals — LifeStageDataTest / their breed tests.
            ->whereNotIn('breed_slug', ['bernese-mountain-dog', 'cane-corso', 'miniature-american-shepherd', 'chihuahua', 'samoyed', 'scottish-terrier', 'bloodhound', 'akita', 'saint-bernard', 'bull-terrier']) // dogs; cats: CatLifeStageDataTest
            // M5-R13: the five "more than once a week" brushing rows (2/week) are Claude's proposals — DogGroomingTest / LifeStageDataTest.
            ->where('key', '!=', 'grooming_sessions_per_week')
            ->pluck('key')->unique()->values()->all())->toBe(['chewing_chance_per_day']);

        $changes = BreedStageParamChange::orderBy('id')->get();
        expect($changes)->toHaveCount(6)
            ->and($changes->pluck('action')->unique()->all())->toBe(['updated'])
            ->and($changes->pluck('user_id')->unique()->all())->toBe([null])
            ->and($changes->pluck('actor')->unique()->all())->toBe(['system: David decision 2026-10-06'])
            ->and($changes->pluck('key')->unique()->sort()->values()->all())->toBe(collect(TD_CONFIRMED_KEYS)->sort()->values()->all());
        $minutes = $changes->firstWhere(fn ($c) => $c->breed_slug === 'mutt' && $c->key === 'training_minutes_per_day');
        expect($minutes->old['verified'])->toBeFalse()
            ->and($minutes->new['verified'])->toBeTrue()
            ->and($minutes->new)->not->toHaveKey('value'); // the number itself did not change

        // Rows outside the decision are untouched.
        $after = DB::table('breed_stage_params')->orderBy('id')->get()->keyBy('id');
        $changedIds = $changes->pluck('breed_stage_param_id')->all();
        foreach ($before as $id => $row) {
            if (! in_array($id, $changedIds, true)) {
                expect((array) $after[$id])->toBe((array) $row);
            }
        }
    });

    it('is idempotent and writes nothing on a fresh install', function () {
        tdMigrate();
        expect(BreedStageParamChange::count())->toBe(0);

        tdRevertToPr53();
        tdMigrate();
        $snapshot = DB::table('breed_stage_params')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $audits = BreedStageParamChange::count();

        tdMigrate();
        (new BreedStageParamsSeeder)->run();

        expect(DB::table('breed_stage_params')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())->toBe($snapshot)
            ->and(BreedStageParamChange::count())->toBe($audits);
    });

    it('never overwrites a value an admin changed, and skips a row changed outside Filament', function () {
        tdRevertToPr53();
        $admin = User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
        actingAs($admin);
        // Filament edit (audited): the mutt gets 7 minutes a day.
        tdParam('mutt', 'all', 0, 'training_minutes_per_day')->update(['value' => 7]);
        // Raw SQL (no audit): the Border Collie loses 3 points a day.
        DB::table('breed_stage_params')->where(['breed_slug' => 'border-collie', 'key' => 'training_decay_per_missed_day'])->update(['value' => json_encode(3.0)]);
        app('auth')->forgetGuards();
        $adminAudits = BreedStageParamChange::count();

        Log::spy();
        tdMigrate();

        $edited = tdParam('mutt', 'all', 0, 'training_minutes_per_day');
        $outside = tdParam('border-collie', 'all', 0, 'training_decay_per_missed_day');
        expect($edited->value)->toBe(7)->and($edited->verified)->toBeFalse()
            ->and((float) $outside->value)->toBe(3.0)->and($outside->verified)->toBeFalse()
            ->and(tdParam('border-collie', 'all', 0, 'training_minutes_per_day')->verified)->toBeTrue()
            ->and(tdParam('mutt', 'all', 0, 'training_decay_per_missed_day')->verified)->toBeTrue()
            ->and(BreedStageParamChange::count())->toBe($adminAudits + 4)
            ->and(BreedStageParamChange::where('actor', 'system: David decision 2026-10-06')->where('breed_slug', 'mutt')->where('key', 'training_minutes_per_day')->exists())->toBeFalse();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'changed outside Filament')
            && $ctx['tuple'] === 'border-collie|all|0|training_decay_per_missed_day')->once();

        // The admin's 7 minutes are what the game uses.
        [, , $pet] = tdFamily();
        expect(app(TrainingService::class)->dailyBudgetSeconds($pet, '2026-10-08'))->toBe(420);
    });
});

// ──────────────────────────────────────────────────────────────
//  1b. Confirmed effects (David 2026-10-07: potty 0.75, place 0.5)
// ──────────────────────────────────────────────────────────────

describe('confirmed effects (potty 0.75, place 0.5)', function () {
    it('seeds them verified with David\'s 2026-10-07 decision and keeps the source ids', function () {
        $data = json_decode((string) file_get_contents(base_path('../docs/research/dog-data/data.json')), true, flags: JSON_THROW_ON_ERROR);

        foreach (['mutt', 'border-collie'] as $breed) {
            $potty = tdParam($breed, 'all', 0, 'potty_training_accident_reduction');
            $place = tdParam($breed, 'all', 0, 'place_training_chewing_reduction');
            expect($potty->value)->toEqual(0.75)->and($place->value)->toEqual(0.5)
                ->and($potty->source_id)->toBe('S47,S31')->and($place->source_id)->toBe('S33');
            foreach ([$potty, $place] as $row) {
                expect($row->verified)->toBeTrue()
                    ->and($row->notes)->toStartWith('Decision: potrdil David 2026-10-07.')
                    ->and($row->notes)->toContain("the size of the effect is PetPrep's")
                    ->and($row->notes)->not->toContain('waiting for David')
                    ->and($row->confidence)->toBe('low');
            }
        }
        expect(BreedStageParamsSeeder::CONFIRMED_R03_EFFECTS)->toBe('potrdil David 2026-10-07');
        foreach (TD_EFFECT_KEYS as $entry) {
            expect($data['proposed_game_parameters'][$entry]['decision'])->toStartWith('potrdil David 2026-10-07');
        }
        // The only open proposal left is M5-R02's teething chewing chance.
        expect(BreedStageParam::whereIn('breed_slug', BreedConfig::query()->select('breed_slug')->where('species', 'dog'))->where('verified', false)
            // M5-R10-12 / M5-R10-23 / M5-R10-32 / M5-R10-57: the Bernese (76), Cane Corso (84), Miniature American Shepherd (108) Samoyed (90), Scottish Terrier (90, M5-R10-58) Bloodhound (61, M5-R10-62) and Akita (89, M5-R10-63) senior boundaries are separate open proposals — LifeStageDataTest / their breed tests.
            ->whereNotIn('breed_slug', ['bernese-mountain-dog', 'cane-corso', 'miniature-american-shepherd', 'chihuahua', 'samoyed', 'scottish-terrier', 'bloodhound', 'akita', 'saint-bernard', 'bull-terrier']) // dogs; cats: CatLifeStageDataTest
            // M5-R13: the five "more than once a week" brushing rows (2/week) are Claude's proposals — DogGroomingTest / LifeStageDataTest.
            ->where('key', '!=', 'grooming_sessions_per_week')
            ->pluck('key')->unique()->values()->all())->toBe(['chewing_chance_per_day']);
    });

    it('freezes exactly the seeder\'s 4 effect rows (tuple + columns) in the migration', function () {
        $migration = require database_path('migrations/'.TD_EFFECTS_MIGRATION);
        $fromSeeder = array_map(function (array $row): array {
            $columns = BreedStageParamsSeeder::columns($row);
            $columns['value'] = $row['value'];

            return [$row['breed_slug'], $row['stage'], $row['age_from_months'], $row['key'], $columns];
        }, tdEffectRows());

        expect($migration::TARGETS)->toHaveCount(4)
            ->and($migration::TARGETS)->toBe($fromSeeder)
            ->and($migration::ACTOR)->toBe('system: David decision 2026-10-07')
            ->and($migration->withinTransaction)->toBeFalse();
    });

    it('flips the PR #59 production rows to verified with one system audit row each, nothing else', function () {
        tdRevertEffectsToPr59();
        foreach (['mutt', 'border-collie'] as $slug) {
            app(LifeStageService::class)->paramsFor($slug);
        }
        $before = DB::table('breed_stage_params')->orderBy('id')->get()->keyBy('id');

        tdMigrateEffects();

        foreach (tdEffectRows() as $row) {
            $stored = tdParam($row['breed_slug'], 'all', 0, $row['key']);
            expect($stored->verified)->toBeTrue()
                ->and($stored->notes)->toBe(BreedStageParamsSeeder::columns($row)['notes'])
                ->and((float) $stored->value)->toBe((float) $row['value']);
        }
        expect(BreedStageParam::whereIn('breed_slug', BreedConfig::query()->select('breed_slug')->where('species', 'dog'))->where('verified', false)
            // M5-R10-12 / M5-R10-23 / M5-R10-32 / M5-R10-57: the Bernese (76), Cane Corso (84), Miniature American Shepherd (108) Samoyed (90), Scottish Terrier (90, M5-R10-58) Bloodhound (61, M5-R10-62) and Akita (89, M5-R10-63) senior boundaries are separate open proposals — LifeStageDataTest / their breed tests.
            ->whereNotIn('breed_slug', ['bernese-mountain-dog', 'cane-corso', 'miniature-american-shepherd', 'chihuahua', 'samoyed', 'scottish-terrier', 'bloodhound', 'akita', 'saint-bernard', 'bull-terrier']) // dogs; cats: CatLifeStageDataTest
            // M5-R13: the five "more than once a week" brushing rows (2/week) are Claude's proposals — DogGroomingTest / LifeStageDataTest.
            ->where('key', '!=', 'grooming_sessions_per_week')
            ->pluck('key')->unique()->values()->all())->toBe(['chewing_chance_per_day']);

        $changes = BreedStageParamChange::orderBy('id')->get();
        expect($changes)->toHaveCount(4)
            ->and($changes->pluck('action')->unique()->all())->toBe(['updated'])
            ->and($changes->pluck('user_id')->unique()->all())->toBe([null])
            ->and($changes->pluck('actor')->unique()->all())->toBe(['system: David decision 2026-10-07'])
            ->and($changes->pluck('key')->unique()->sort()->values()->all())->toBe(collect(TD_EFFECT_KEYS)->sort()->values()->all());
        $potty = $changes->firstWhere(fn ($c) => $c->breed_slug === 'mutt' && $c->key === 'potty_training_accident_reduction');
        expect($potty->old['verified'])->toBeFalse()
            ->and($potty->new['verified'])->toBeTrue()
            ->and($potty->new['notes'])->toStartWith('Decision: potrdil David 2026-10-07.')
            ->and($potty->new)->not->toHaveKey('value'); // the number itself did not change

        // The rules cache is cleared after the commit.
        foreach (['mutt', 'border-collie'] as $slug) {
            expect(Cache::has(LifeStageService::cacheKey($slug)))->toBeFalse();
        }

        // Rows outside the decision are untouched.
        $after = DB::table('breed_stage_params')->orderBy('id')->get()->keyBy('id');
        $changedIds = $changes->pluck('breed_stage_param_id')->all();
        foreach ($before as $id => $row) {
            if (! in_array($id, $changedIds, true)) {
                expect((array) $after[$id])->toBe((array) $row);
            }
        }
    });

    it('is idempotent and writes nothing on a fresh install', function () {
        tdMigrateEffects();
        expect(BreedStageParamChange::count())->toBe(0);

        tdRevertEffectsToPr59();
        tdMigrateEffects();
        $snapshot = DB::table('breed_stage_params')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $audits = BreedStageParamChange::count();
        expect($audits)->toBe(4);

        tdMigrateEffects();
        (new BreedStageParamsSeeder)->run();

        expect(DB::table('breed_stage_params')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())->toBe($snapshot)
            ->and(BreedStageParamChange::count())->toBe($audits);
    });

    it('never overwrites a value an admin changed, and skips a row changed outside Filament', function () {
        tdRevertEffectsToPr59();
        $admin = User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
        actingAs($admin);
        // Filament edit (audited): the mutt's potty effect becomes 0.6.
        tdParam('mutt', 'all', 0, 'potty_training_accident_reduction')->update(['value' => 0.6]);
        // Raw SQL (no audit): the Border Collie's place effect becomes 0.3.
        DB::table('breed_stage_params')->where(['breed_slug' => 'border-collie', 'key' => 'place_training_chewing_reduction'])->update(['value' => json_encode(0.3)]);
        app('auth')->forgetGuards();
        $adminAudits = BreedStageParamChange::count();

        Log::spy();
        tdMigrateEffects();

        $edited = tdParam('mutt', 'all', 0, 'potty_training_accident_reduction');
        $outside = tdParam('border-collie', 'all', 0, 'place_training_chewing_reduction');
        expect((float) $edited->value)->toBe(0.6)->and($edited->verified)->toBeFalse()
            ->and((float) $outside->value)->toBe(0.3)->and($outside->verified)->toBeFalse()
            ->and(tdParam('mutt', 'all', 0, 'place_training_chewing_reduction')->verified)->toBeTrue()
            ->and(tdParam('border-collie', 'all', 0, 'potty_training_accident_reduction')->verified)->toBeTrue()
            ->and(BreedStageParamChange::count())->toBe($adminAudits + 2)
            ->and(BreedStageParamChange::where('actor', 'system: David decision 2026-10-07')->where('breed_slug', 'mutt')->where('key', 'potty_training_accident_reduction')->exists())->toBeFalse();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'changed outside Filament')
            && $ctx['tuple'] === 'border-collie|all|0|place_training_chewing_reduction')->once();

        // The seeder never brings the proposal text back over the admin's value.
        (new BreedStageParamsSeeder)->run();
        expect((float) tdParam('mutt', 'all', 0, 'potty_training_accident_reduction')->value)->toBe(0.6);
    });
});

// ──────────────────────────────────────────────────────────────
//  2. Starting progress of a grown arrival
// ──────────────────────────────────────────────────────────────

describe('starting progress', function () {
    it('stores one verified row per stage and breed (puppy 0, young / adult / senior sit 50, come 30, place 0, potty 70)', function () {
        $data = json_decode((string) file_get_contents(base_path('../docs/research/dog-data/data.json')), true, flags: JSON_THROW_ON_ERROR);
        $entry = $data['proposed_game_parameters']['training_starting_progress'];
        $grown = ['sit' => 50, 'come' => 30, 'place' => 0, 'potty' => 70];

        foreach (['mutt', 'border-collie'] as $breed) {
            foreach (['puppy', 'young', 'adult', 'senior'] as $stage) {
                $row = tdParam($breed, $stage, 0, 'training_starting_progress');
                expect($row->value)->toEqual($stage === 'puppy' ? ['sit' => 0, 'come' => 0, 'place' => 0, 'potty' => 0] : $grown)
                    ->and($row->value)->toEqual($entry['value'][$stage])
                    ->and($row->verified)->toBeTrue()
                    ->and($row->confidence)->toBe('low')
                    ->and($row->source_id)->toBeNull()
                    ->and($row->data_ref)->toBe('proposed_game_parameters.training_starting_progress')
                    ->and($row->notes)->toStartWith('Decision: potrdil David 2026-10-06. No source; Claude proposal confirmed by David.');
            }
        }
        expect($entry['decision'])->toStartWith('potrdil David 2026-10-06')
            ->and(StageParamKey::TrainingStartingProgress->isBreedLevel())->toBeFalse()
            ->and(StageParamKey::TrainingStartingProgress->validate($grown))->toBeNull()
            ->and(StageParamKey::TrainingStartingProgress->validate(['sit' => 120]))->not->toBeNull()
            ->and(StageParamKey::TrainingStartingProgress->validate(['jump' => 10]))->not->toBeNull()
            ->and(StageParamKey::TrainingStartingProgress->validate([50, 30]))->not->toBeNull();
    });

    it('gives a dog arriving young, adult or senior (bought or adopted) its starting skills; a puppy starts at 0', function () {
        tdAt('2026-10-07 06:00:00');
        $grown = ['come' => 30.0, 'potty' => 70.0, 'sit' => 50.0];

        foreach ([['bought', 'puppy', []], ['adopted', 'puppy', []], ['bought', 'young', $grown], ['adopted', 'young', $grown],
            ['bought', 'adult', $grown], ['adopted', 'adult', $grown], ['bought', 'senior', $grown], ['adopted', 'senior', $grown]] as [$origin, $stage, $expected]) {
            [, $pet] = tdCreateViaPin($origin, $stage);
            expect($pet->training_enabled)->toBeTrue()
                ->and($pet->life_stage?->value)->toBe($stage)
                ->and(tdSkills($pet))->toBe($expected, "{$origin} {$stage}");
        }

        // Not a session: nothing practised, no session rows.
        expect(PetTrainingSkill::where('sessions_completed', '>', 0)->exists())->toBeFalse()
            ->and(PetTrainingSkill::whereNotNull('last_practised_at')->exists())->toBeFalse()
            ->and(PetTrainingSession::count())->toBe(0);
    });

    it('shows the starting skills in the child state (before and after the birth) and the parent summary', function () {
        tdAt('2026-10-07 06:00:00');
        [$child, $pet] = tdCreateViaPin('adopted', 'adult');
        $commands = [
            ['command' => 'sit', 'progress' => 50, 'learned' => false, 'last_practised_at' => null],
            ['command' => 'come', 'progress' => 30, 'learned' => false, 'last_practised_at' => null],
            ['command' => 'place', 'progress' => 0, 'learned' => false, 'last_practised_at' => null],
            ['command' => 'potty', 'progress' => 70, 'learned' => false, 'last_practised_at' => null],
        ];

        tdState($child)->assertJsonPath('training.enabled', true)->assertJsonPath('training.commands', $commands);

        postJson('/api/child/contract', ['signature_format' => 'svg_path', 'signature' => 'M10 10 L20 20'])->assertSuccessful();
        tdState($child)->assertJsonPath('training.commands', $commands)->assertJsonPath('training.today_done', false);
        expect(PetUpdated::payloadFor($pet->fresh(), 'signed_contract')['training']['commands'])->toBe($commands);
    });

    it('leaves pets without training and legacy pets alone, and never backfills existing pets', function () {
        tdAt('2026-10-07 06:00:00');
        [, $noTraining] = tdCreateViaPin('adopted', 'adult', training: false);
        [, $owner, $existing] = tdFamily(arrival: 36); // created before M5-R03b (factory)
        (new BreedStageParamsSeeder)->run();
        tdMigrate();

        expect($noTraining->training_enabled)->toBeFalse()
            ->and(tdSkills($noTraining))->toBe([])
            ->and(tdSkills($existing))->toBe([])
            // Legacy profile: nothing even when called directly.
            ->and(app(TrainingService::class)->applyStartingProgress(Pet::factory()->mutt()->create(['user_id' => User::factory()->child()->create(['parent_id' => $owner->parent_id])->id, 'training_enabled' => true]), now()))->toBe([]);
    });

    it('reads the values from breed_stage_params (Border Collie too; admin edits apply)', function () {
        tdAt('2026-10-07 06:00:00');
        $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
        $child = User::factory()->child()->create(['parent_id' => $parent->id]);
        $collie = Pet::factory()->borderCollie()->unborn()->create(['user_id' => $child->id, 'arrival_age_months' => 118, 'training_enabled' => true]);
        expect(app(TrainingService::class)->applyStartingProgress($collie, now()))->toBe(['sit' => 50.0, 'come' => 30.0, 'potty' => 70.0]);

        DB::table('breed_stage_params')->where(['breed_slug' => 'mutt', 'stage' => 'young', 'key' => 'training_starting_progress'])
            ->update(['value' => json_encode(['sit' => 10, 'place' => 5])]);
        LifeStageService::forgetBreed('mutt');
        app()->forgetInstance(LifeStageService::class);
        app()->forgetInstance(TrainingService::class);

        [, $pet] = tdCreateViaPin('bought', 'young');
        expect(tdSkills($pet))->toBe(['place' => 5.0, 'sit' => 10.0]);
    });

    it('decays like any other progress (−2 per missed day, not on the birth day)', function () {
        tdAt('2026-10-07 06:00:00');
        [$child, $pet] = tdCreateViaPin('bought', 'adult');
        app('auth')->forgetGuards();
        actingAsRole($child);
        postJson('/api/child/contract', ['signature_format' => 'svg_path', 'signature' => 'M10 10 L20 20'])->assertSuccessful();
        disableHygieneEvents($pet->fresh());

        // Birth day 2026-10-07 is never decayed; 2026-10-08 is missed.
        tdAt('2026-10-09 06:00:00');
        $fresh = $pet->fresh();
        expect(app(TrainingService::class)->applyDecay($fresh, now()))->toBe(1)
            ->and(tdSkills($pet))->toBe(['come' => 28.0, 'potty' => 68.0, 'sit' => 48.0]);
    });
});

// ──────────────────────────────────────────────────────────────
//  3. Fair share of the daily budget
// ──────────────────────────────────────────────────────────────

describe('fair share of the daily budget', function () {
    it('gives one child the whole 420 s (a start reserves the longest session, each counts its real length)', function () {
        [, $child, $pet] = tdFamily();
        tdState($child)
            ->assertJsonPath('training.children_sharing', 1)
            ->assertJsonPath('training.my_share_seconds', 420)
            ->assertJsonPath('training.my_seconds_left', 420)
            ->assertJsonPath('training.session_seconds', 58);

        $used = tdTrainUntilFull($child, $pet, '2026-10-08 08:00:00', 420);
        tdAt('2026-10-08 09:00:00');
        tdStart($child)->assertStatus(422)->assertJsonPath('reason', 'training_daily_budget_used')
            ->assertJsonPath('state.training.my_seconds_left', 420 - $used);
        expect(420 - $used)->toBeLessThan(58);
    });

    it('splits 420 s between 2 children: 210 s each, then training_child_share_used; the sibling keeps theirs', function () {
        [$parent, $child, $pet] = tdFamily();
        withoutQuietHours($pet); // M5-T02: about the clock / budget, not sleep (no school while the pet sleeps)
        $sibling = tdSibling($parent, $pet);

        tdState($child)
            ->assertJsonPath('training.children_sharing', 2)
            ->assertJsonPath('training.my_share_seconds', 210)
            ->assertJsonPath('training.my_seconds_left', 210)
            ->assertJsonPath('training.daily_budget_seconds', 420)
            ->assertJsonPath('training.can_start', true);

        $mine = tdTrainUntilFull($child, $pet, '2026-10-08 08:00:00', 210, $child);

        tdAt('2026-10-08 09:00:00');
        tdStart($child)->assertStatus(422)
            ->assertJsonPath('reason', 'training_child_share_used')
            ->assertJsonPath('next_allowed_at', '2026-10-09T00:00:00+02:00')
            ->assertJsonPath('state.training.my_share_seconds', 210)
            ->assertJsonPath('state.training.my_seconds_left', 210 - $mine)
            ->assertJsonPath('state.training.daily_budget_left_seconds', 420 - $mine)
            ->assertJsonPath('state.training.can_start', false);

        // The sibling still has their 210 s.
        tdState($sibling)->assertJsonPath('training.my_seconds_left', 210)->assertJsonPath('training.can_start', true);
        $theirs = tdTrainUntilFull($sibling, $pet, '2026-10-08 10:00:00', 210, $sibling);
        tdAt('2026-10-08 11:00:00');
        $reason = tdStart($sibling)->assertStatus(422)->json('reason');
        // Pet-wide first when the dog's whole budget cannot fit another session, else the own share.
        $dog = app(TrainingService::class)->usedSecondsOn($pet->fresh(), '2026-10-08');
        expect($reason)->toBe($dog + 58 > 420 ? 'training_daily_budget_used' : 'training_child_share_used')
            ->and($theirs + 58)->toBeGreaterThan(210);

        // A new family-local day gives everyone their share again.
        tdAt('2026-10-08 22:00:00');
        $s = tdStart($child)->assertOk()->json('session');
        tdState($child)->assertJsonPath('training.my_seconds_left', 210 - tdSeconds($s));
    });

    it('splits 420 s between 3 children (140 s each) and 4 children (105 s each)', function () {
        [$parent, $child, $pet] = tdFamily();
        tdSibling($parent, $pet);
        tdSibling($parent, $pet);

        tdState($child)->assertJsonPath('training.children_sharing', 3)->assertJsonPath('training.my_share_seconds', 140);
        $mine = tdTrainUntilFull($child, $pet, '2026-10-08 08:00:00', 140, $child);
        tdAt('2026-10-08 08:40:00');
        tdStart($child)->assertStatus(422)->assertJsonPath('reason', 'training_child_share_used');

        // A fourth child signs: 105 s each — the first child may already be over their new share.
        $fourth = tdSibling($parent, $pet, '2026-10-08 08:45:00');
        tdState($fourth)->assertJsonPath('training.children_sharing', 4)
            ->assertJsonPath('training.my_share_seconds', 105)
            ->assertJsonPath('training.my_seconds_left', 105);
        // 1–2 sessions (33–58 s each) fit 105 s.
        $theirs = tdTrainUntilFull($fourth, $pet, '2026-10-08 09:00:00', 105, $fourth);
        expect($theirs)->toBeGreaterThan(0)->toBeLessThanOrEqual(105);
        tdAt('2026-10-08 10:00:00');
        tdStart($fourth)->assertStatus(422)->assertJsonPath('reason', 'training_child_share_used')
            ->assertJsonPath('state.training.my_seconds_left', 105 - $theirs);
        tdState($child)->assertJsonPath('training.my_seconds_left', max(0, 105 - $mine));
    });

    it('does not count a child who has not signed their contract (they cannot train yet)', function () {
        [$parent, $child, $pet] = tdFamily();
        $unsigned = tdSibling($parent, $pet, null);

        tdState($child)->assertJsonPath('training.children_sharing', 1)->assertJsonPath('training.my_share_seconds', 420);
        tdState($unsigned)->assertJsonPath('training.my_share_seconds', 0)
            ->assertJsonPath('training.my_seconds_left', 0)
            ->assertJsonPath('training.can_start', false);
        expect(app(TrainingService::class)->trainerIds($pet))->toBe([$child->id]);
    });

    it('refunds an interrupted session to the child\'s share', function () {
        [$parent, $child, $pet] = tdFamily();
        tdSibling($parent, $pet);
        tdSession($child, '2026-10-08 08:00:00');
        $first = app(TrainingService::class)->usedSecondsOn($pet->fresh(), '2026-10-08', $child);

        tdAt('2026-10-08 08:04:00');
        $res = tdStart($child)->assertOk();
        $s = $res->json('session');
        // The running session counts too (seconds are summed in ms, then rounded up).
        $res->assertJsonPath('state.training.my_seconds_left', max(0, 210 - app(TrainingService::class)->usedSecondsOn($pet->fresh(), '2026-10-08', $child)));
        expect(tdSeconds($s))->toBeGreaterThan(0);
        tdAt('2026-10-08 08:04:20');
        Pet::find($pet->id)->update(['is_hard_stopped' => true]);
        tdAt('2026-10-08 08:05:00');
        Pet::find($pet->id)->update(['is_hard_stopped' => false]);

        tdFinish($child, $s['id'])->assertStatus(422)
            ->assertJsonPath('reason', 'training_session_interrupted')
            ->assertJsonPath('state.training.my_seconds_left', 210 - $first)
            ->assertJsonPath('state.training.can_start', true);
        tdStart($child)->assertOk();
    });

    it('lets siblings train one after the other (only one running session per dog)', function () {
        [$parent, $child, $pet] = tdFamily();
        $sibling = tdSibling($parent, $pet);
        tdAt('2026-10-08 08:00:00');
        $s = tdStart($child)->assertOk()->json('session');
        tdStart($sibling)->assertStatus(422)->assertJsonPath('reason', 'training_session_active');

        Carbon::setTestNow(Carbon::parse($s['ends_at'])->utc());
        tdFinish($child, $s['id'])->assertOk();
        $theirs = tdStart($sibling)->assertOk()->json('session');
        tdState($sibling)->assertJsonPath('training.my_seconds_left', 210 - tdSeconds($theirs));
    });
});

// ──────────────────────────────────────────────────────────────
//  QA follow-ups (m2, m3, n1)
// ──────────────────────────────────────────────────────────────

describe('QA follow-ups', function () {
    it('clears the rules cache of every breed after seeding (m2)', function () {
        foreach (['mutt', 'border-collie'] as $slug) {
            app(LifeStageService::class)->paramsFor($slug);
            expect(Cache::has(LifeStageService::cacheKey($slug)))->toBeTrue();
        }
        // A row deleted without Filament (no audit) is re-inserted by the seeder …
        DB::table('breed_stage_params')->where(['breed_slug' => 'mutt', 'stage' => 'adult', 'key' => 'training_starting_progress'])->delete();

        (new BreedStageParamsSeeder)->run();

        // … and the next reader sees it (no stale cache).
        foreach (['mutt', 'border-collie'] as $slug) {
            expect(Cache::has(LifeStageService::cacheKey($slug)))->toBeFalse();
        }
        app()->forgetInstance(LifeStageService::class);
        expect(collect(app(LifeStageService::class)->paramsFor('mutt'))
            ->contains(fn (array $r) => $r['stage'] === 'adult' && $r['key'] === 'training_starting_progress'))->toBeTrue();
    });

    it('logs a warning when a grown arrival has no starting-progress row, not for a puppy (m2)', function () {
        DB::table('breed_stage_params')->where('key', 'training_starting_progress')->delete();
        LifeStageService::forgetBreed('mutt');
        app()->forgetInstance(LifeStageService::class);
        app()->forgetInstance(TrainingService::class);
        tdAt('2026-10-07 06:00:00');

        Log::spy();
        [, $adult] = tdCreateViaPin('adopted', 'adult');
        [, $puppy] = tdCreateViaPin('bought', 'puppy');

        expect(tdSkills($adult))->toBe([])->and(tdSkills($puppy))->toBe([]);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'no training_starting_progress')
            && $ctx['pet_id'] === $adult->id && $ctx['life_stage'] === 'adult')->once();
        Log::shouldNotHaveReceived('warning', [Mockery::on(fn ($m) => is_string($m) && str_contains($m, 'no training_starting_progress')), Mockery::on(fn ($c) => is_array($c) && $c['pet_id'] === $puppy->id)]);
    });

    it('gives each of 7 children at least one session; the dog\'s 420 s still cap the total (m3)', function () {
        [$parent, $child, $pet] = tdFamily();
        $siblings = [];
        for ($i = 0; $i < 6; $i++) {
            $siblings[] = tdSibling($parent, $pet);
        }
        $all = [$child, ...$siblings];

        tdState($child)->assertJsonPath('training.children_sharing', 7)
            ->assertJsonPath('training.my_share_seconds', 60) // max(420 / 7 = 60, one session = 58)
            ->assertJsonPath('training.my_seconds_left', 60)
            ->assertJsonPath('training.can_start', true);

        // The children train once each, in turn, while the dog's budget fits a session.
        $service = app(TrainingService::class);
        $trained = 0;
        foreach ($all as $i => $c) {
            if ($service->usedSecondsOn($pet->fresh(), '2026-10-08') + 58 > 420) {
                break;
            }
            tdSession($c, sprintf('2026-10-08 08:%02d:00', $i * 2));
            $trained++;
        }
        $used = $service->usedSecondsOn($pet->fresh(), '2026-10-08');
        tdAt('2026-10-08 09:00:00');
        // A child who already trained: refused (own share or, first, the dog's budget).
        expect(tdStart($child)->assertStatus(422)->json('reason'))->toBeIn(['training_daily_budget_used', 'training_child_share_used']);
        if ($trained < 7) {
            // A child who comes too late today: the dog's budget is used.
            tdStart($all[$trained])->assertStatus(422)->assertJsonPath('reason', 'training_daily_budget_used')
                ->assertJsonPath('state.training.my_share_seconds', 60)
                ->assertJsonPath('state.training.my_seconds_left', 420 - $used)
                ->assertJsonPath('state.training.can_start', false);
        }
        expect($trained)->toBeGreaterThanOrEqual(5);
    });

    it('caps each of 7 children at one session (share 60 s), even with dog budget left (m3)', function () {
        [$parent, $child, $pet] = tdFamily();
        for ($i = 0; $i < 6; $i++) {
            tdSibling($parent, $pet);
        }
        tdSession($child, '2026-10-08 08:00:00');
        $mine = app(TrainingService::class)->usedSecondsOn($pet->fresh(), '2026-10-08', $child);
        tdAt('2026-10-08 08:02:00');
        tdStart($child)->assertStatus(422)->assertJsonPath('reason', 'training_child_share_used')
            ->assertJsonPath('state.training.my_seconds_left', 60 - $mine)
            ->assertJsonPath('state.training.daily_budget_left_seconds', 420 - $mine);
    });

    it('uses one trainer rule for the server and the payload: a child who cannot train is refused (n1)', function () {
        [$parent, $child, $pet] = tdFamily();
        $unsigned = tdSibling($parent, $pet, null);
        $stranger = User::factory()->child()->create(['parent_id' => $parent->id]);
        tdAt('2026-10-08 08:00:00');
        $service = app(TrainingService::class);

        // The service itself refuses anyone outside trainerIds() (the HTTP lock answers first for the unsigned child).
        foreach ([$unsigned, $stranger] as $c) {
            $result = DB::transaction(fn () => $service->start(Pet::whereKey($pet->id)->lockForUpdate()->first(), $c, TrainingCommand::Sit, now()));
            expect($result['refusal']?->value)->toBe('training_not_available')
                ->and($result['session'])->toBeNull();
        }
        tdStart($unsigned)->assertStatus(423)->assertJsonPath('reason', 'contract_required')
            ->assertJsonPath('state.training.my_share_seconds', 0)
            ->assertJsonPath('state.training.can_start', false);

        // Once the child signs, both agree: counted, a share, allowed.
        PetContract::create(['pet_id' => $pet->id, 'user_id' => $unsigned->id, 'signature_format' => 'svg_path', 'signature' => 'M1 1 L2 2', 'signed_at' => now()]);
        tdState($unsigned)->assertJsonPath('training.children_sharing', 2)
            ->assertJsonPath('training.my_share_seconds', 210)
            ->assertJsonPath('training.can_start', true);
        tdStart($unsigned)->assertOk();
    });
});
