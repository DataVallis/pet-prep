<?php

use App\Enums\BreedType;
use App\Models\Pet;
use App\Models\PetCareSession;
use App\Models\QuietHours;
use App\Models\User;
use App\Services\AccountExportService;
use App\Services\CareScheduleService;
use App\Services\FamilyService;
use App\Services\PetDecayService;
use App\Services\Push\PushTiming;
use App\Services\RoutineLedgerService;
use App\Services\SleepPayload;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\putJson;

/*
|--------------------------------------------------------------------------
| School days in quiet hours (David 2026-11-22)
|--------------------------------------------------------------------------
|
| Bug: on Sunday the daughter could not play with the cat during the school
| window — the school window applied every day. Decision »Starš izbere dneve,
| privzeto pon–pet«: `school_days` = ISO weekdays 1..7 (default [1,2,3,4,5],
| [] = no school window); bedtime stays every day. The weekday is the
| PET-LOCAL date (Pet::effectiveTimezone()).
|
| The family lives in Ljubljana; the pet lives in Pacific/Auckland (NZDT,
| UTC+13 in November) — Monday 10:00 in Auckland is still Sunday 22:00 in
| Ljubljana, so a rule that read the family's weekday would fail these tests.
| November 2026: Sat 21, Sun 22, Mon 23, Wed 25.
*/

const SD_ZONE = 'Pacific/Auckland';

/** School 08:00–13:00, bedtime 21:00–07:00 (no school_days sent = an old app). */
const SD_FAMILY = ['school_start' => '08:00', 'school_end' => '13:00', 'bedtime_start' => '21:00', 'bedtime_end' => '07:00', 'is_active' => true];

/** Set the clock to a pet-local (Auckland) wall-clock time. */
function sdAt(string $local, string $zone = SD_ZONE): void
{
    Carbon::setTestNow(Carbon::parse($local, $zone)->utc());
}

/**
 * A Ljubljana family with two children; each cares for a profiled pet living
 * in Auckland (own zone).
 *
 * @return array{parent: User, kid1: User, kid2: User, pet: Pet, other: Pet}
 */
function sdFamily(array $petAttributes = []): array
{
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $kid1 = User::factory()->child()->create(['parent_id' => $parent->id]);
    $kid2 = User::factory()->child()->create(['parent_id' => $parent->id]);

    $attributes = $petAttributes + [
        'born_at' => Carbon::parse('2026-11-10 10:00', 'UTC'),
        'timezone' => SD_ZONE,
        'hunger_level' => 100,
        'thirst_level' => 100,
        'last_decay_at' => now(),
    ];
    $pet = disableHygieneEvents(Pet::factory()->create(['user_id' => $kid1->id] + $attributes));
    $other = disableHygieneEvents(Pet::factory()->create(['user_id' => $kid2->id] + $attributes));

    return ['parent' => $parent, 'kid1' => $kid1, 'kid2' => $kid2, 'pet' => $pet->fresh(), 'other' => $other->fresh()];
}

/**
 * @param  array<string, mixed>  $body
 */
function sdPutFamily(User $parent, array $body): TestResponse
{
    actingAs($parent, 'sanctum');

    return putJson('/api/parent/quiet-hours', $body);
}

/**
 * @param  array<string, mixed>|null  $quietHours
 */
function sdPutPet(User $parent, Pet $pet, ?array $quietHours): TestResponse
{
    actingAs($parent, 'sanctum');

    return putJson("/api/parent/pets/{$pet->id}/quiet-hours", ['quiet_hours' => $quietHours]);
}

function sdQuiet(Pet $pet, string $local): bool
{
    return Pet::findOrFail($pet->id)->quietHours()->isQuietNow(Carbon::parse($local, $pet->effectiveTimezone()));
}

beforeEach(function () {
    seedBreedConfigs();
    sdAt('2026-11-23 09:00'); // Monday in Auckland
});

afterEach(function () {
    Carbon::setTestNow();
});

// ── Default Monday–Friday ────────────────────────────────────────────────────

it('by default the school window is Mon–Fri on the PET\'s date; bedtime stays every day', function () {
    $f = sdFamily();
    sdPutFamily($f['parent'], SD_FAMILY)->assertOk()->assertJsonPath('quiet_hours.school_days', [1, 2, 3, 4, 5]);

    expect(sdQuiet($f['pet'], '2026-11-21 10:00'))->toBeFalse()   // Saturday school time
        ->and(sdQuiet($f['pet'], '2026-11-22 10:00'))->toBeFalse() // Sunday school time
        ->and(sdQuiet($f['pet'], '2026-11-23 10:00'))->toBeTrue()  // Monday (still Sunday in Ljubljana)
        ->and(sdQuiet($f['pet'], '2026-11-23 13:00'))->toBeFalse() // end of school
        ->and(sdQuiet($f['pet'], '2026-11-21 22:00'))->toBeTrue()  // Saturday night
        ->and(sdQuiet($f['pet'], '2026-11-22 06:30'))->toBeTrue(); // Sunday morning, still bedtime

    // The child state's sleep info follows: Sunday 10:00 awake, Monday 10:00 asleep until 13:00.
    sdAt('2026-11-22 10:00');
    expect(SleepPayload::for(Pet::find($f['pet']->id))->asleep)->toBeFalse();
    sdAt('2026-11-23 10:00');
    expect(SleepPayload::for(Pet::find($f['pet']->id))->asleep)->toBeTrue()
        ->and(SleepPayload::for(Pet::find($f['pet']->id))->until)->toBe('2026-11-23T13:00:00+13:00');
});

it('GET, dashboard, child state and export carry school_days; an unsaved family shows the default', function () {
    $f = sdFamily();

    actingAs($f['parent'], 'sanctum');
    getJson('/api/parent/quiet-hours')->assertOk()
        ->assertJsonPath('quiet_hours.saved', true)
        ->assertJsonPath('quiet_hours.school_days', [1, 2, 3, 4, 5]);

    sdPutFamily($f['parent'], SD_FAMILY + ['school_days' => [6, 7]])->assertOk();
    $dashboard = getJson('/api/parent/dashboard')->assertOk();
    $pets = collect($dashboard->json('family.pets'))->keyBy('id');
    expect($dashboard->json('quiet_hours.school_days'))->toBe([6, 7])
        ->and($pets[$f['pet']->id]['quiet_hours']['school_days'])->toBe([6, 7])
        ->and($pets[$f['pet']->id]['quiet_hours']['follows_family'])->toBeTrue();

    actingAs($f['kid1'], 'sanctum');
    getJson('/api/child/pet')->assertOk()->assertJsonPath('quiet_hours.school_days', [6, 7]);

    $export = app(AccountExportService::class)->exportFor(User::find($f['parent']->id));
    expect($export['quiet_hours']['school_days'] ?? null)->toBe([6, 7]);

    // A family without a row reads QuietHours::DEFAULTS (no school window, Mon–Fri days).
    $lonely = User::factory()->parent()->create();
    $family = app(FamilyService::class)->familyOf($lonely);
    QuietHours::where('family_id', $family->id)->delete();
    actingAs($lonely, 'sanctum');
    getJson('/api/parent/quiet-hours')->assertOk()
        ->assertJsonPath('quiet_hours.saved', false)
        ->assertJsonPath('quiet_hours.school_days', [1, 2, 3, 4, 5]);
});

// ── Custom days, [] and old apps ─────────────────────────────────────────────

it('a custom day set works, [] means no school window, bedtime applies every day; an old app keeps the stored days', function () {
    $f = sdFamily();

    sdPutFamily($f['parent'], SD_FAMILY + ['school_days' => [7, 6, 6]])->assertUnprocessable(); // duplicate
    sdPutFamily($f['parent'], SD_FAMILY + ['school_days' => [7, 3]])->assertOk()
        ->assertJsonPath('quiet_hours.school_days', [3, 7]); // stored sorted

    expect(sdQuiet($f['pet'], '2026-11-25 10:00'))->toBeTrue()  // Wednesday
        ->and(sdQuiet($f['pet'], '2026-11-22 10:00'))->toBeTrue()  // Sunday
        ->and(sdQuiet($f['pet'], '2026-11-23 10:00'))->toBeFalse() // Monday
        ->and(sdQuiet($f['pet'], '2026-11-23 23:00'))->toBeTrue(); // Monday night

    // An old app saves without the field: the stored days stay.
    sdPutFamily($f['parent'], ['bedtime_start' => '22:00', 'bedtime_end' => '06:00', 'is_active' => true])->assertOk()
        ->assertJsonPath('quiet_hours.school_days', [3, 7])
        ->assertJsonPath('quiet_hours.school_start', '08:00');
    expect(DB::table('quiet_hours')->where('family_id', $f['pet']->family_id)->value('school_days'))->toBe('[3, 7]');

    // [] = no school window on any day; bedtime still applies.
    sdPutFamily($f['parent'], SD_FAMILY + ['school_days' => []])->assertOk()->assertJsonPath('quiet_hours.school_days', []);
    foreach (['2026-11-21', '2026-11-22', '2026-11-23', '2026-11-25'] as $date) {
        expect(sdQuiet($f['pet'], "{$date} 10:00"))->toBeFalse()
            ->and(sdQuiet($f['pet'], "{$date} 22:00"))->toBeTrue();
    }
    expect(Pet::find($f['pet']->id)->quietHours()->nextBoundaryAfter(Carbon::parse('2026-11-23 07:00', SD_ZONE))?->toIso8601String())
        ->toBe(Carbon::parse('2026-11-23 21:00', SD_ZONE)->toIso8601String()); // school times are no boundary
});

it('rejects invalid school_days with 422 school_days_invalid and saves nothing (family and pet)', function (mixed $days) {
    $f = sdFamily();
    sdPutFamily($f['parent'], SD_FAMILY + ['school_days' => [1, 2]])->assertOk();

    sdPutFamily($f['parent'], ['bedtime_start' => '20:00'] + SD_FAMILY + ['school_days' => $days])
        ->assertStatus(422)->assertJsonPath('reason', 'school_days_invalid');
    sdPutPet($f['parent'], $f['pet'], SD_FAMILY + ['school_days' => $days])
        ->assertStatus(422)->assertJsonPath('reason', 'school_days_invalid');

    $row = QuietHours::where('family_id', $f['pet']->family_id)->first();
    expect($row->schoolDays())->toBe([1, 2])
        ->and(substr((string) $row->bedtime_start, 0, 5))->toBe('21:00')
        ->and(Pet::find($f['pet']->id)->own_quiet_hours)->toBeNull();
})->with([
    'zero' => [[0, 1]],
    'eight' => [[8]],
    'duplicate' => [[1, 1]],
    'string day' => [['1']],
    'name' => [['mon']],
    'null' => [null],
    'not a list' => [['a' => 1]],
    'scalar' => [5],
    'eight entries' => [[1, 2, 3, 4, 5, 6, 7, 7]],
]);

// ── Consumers ────────────────────────────────────────────────────────────────

it('decay ×0.1 applies in a weekday school window and not on Saturday', function () {
    config(['feeding.hunger_floor_percent' => 0]);
    $f = sdFamily();
    sdPutFamily($f['parent'], SD_FAMILY)->assertOk();

    $loss = function (string $fromLocal, string $toLocal) use ($f): float {
        Pet::whereKey($f['pet']->id)->update(['last_decay_at' => Carbon::parse($fromLocal, SD_ZONE)->utc(), 'hunger_level' => 100]);
        sdAt($toLocal);
        app(PetDecayService::class)->processPetDecay(Pet::findOrFail($f['pet']->id));

        return 100 - Pet::find($f['pet']->id)->hunger_level;
    };

    $saturday = $loss('2026-11-21 09:00', '2026-11-21 11:00');
    $monday = $loss('2026-11-23 09:00', '2026-11-23 11:00');

    expect($saturday)->toBeGreaterThan(0.0)
        ->and($monday)->toEqualWithDelta($saturday * PetDecayService::QUIET_HOURS_DECAY_MULTIPLIER, 0.0001);

    // The split itself: Friday 12:00 → Saturday 12:00 = 1 h school + 10 h night quiet.
    $split = QuietHours::splitSecondsBetween(Pet::find($f['pet']->id)->quietHours(), Carbon::parse('2026-11-20 12:00', SD_ZONE), Carbon::parse('2026-11-21 12:00', SD_ZONE));
    expect($split['quiet'])->toBe(11.0 * 3600)->and($split['normal'])->toBe(13.0 * 3600);
});

it('wand play: Sunday 10:00 is not refused, Monday 10:00 is refused with wand_quiet_hours', function () {
    seedLifeStageData();
    $f = sdFamily([
        'breed_type' => BreedType::DomesticCat->value,
        'arrival_age_months' => 12,
        'origin' => 'adopted',
        'born_at' => Carbon::parse('2026-11-01 10:00', 'UTC'),
    ]);
    sdPutFamily($f['parent'], SD_FAMILY)->assertOk();

    sdAt('2026-11-22 10:00'); // Sunday — the bug: this was refused
    actingAs($f['kid1'], 'sanctum');
    $sunday = test()->postJson('/api/child/pet/wand/start');
    $sunday->assertOk();
    app('auth')->forgetGuards();

    sdAt('2026-11-23 10:00'); // Monday school
    actingAs($f['kid1'], 'sanctum');
    test()->postJson('/api/child/pet/wand/start')->assertStatus(422)
        ->assertJsonPath('reason', 'wand_quiet_hours')
        ->assertJsonPath('next_allowed_at', '2026-11-23T13:00:00+13:00');

    expect(PetCareSession::where('pet_id', $f['pet']->id)->where('local_date', '2026-11-23')->count())->toBe(0);
});

it('a feed window fully inside school is the parent\'s only on school days', function () {
    $f = sdFamily(['arrival_age_months' => 3]);
    sdPutFamily($f['parent'], SD_FAMILY)->assertOk();
    $pet = Pet::find($f['pet']->id);
    $schedule = app(CareScheduleService::class);
    $window = fn (string $date) => [Carbon::parse("{$date} 10:00", SD_ZONE), Carbon::parse("{$date} 12:00", SD_ZONE)];

    expect($schedule->isParentCoveredFor($pet, $pet->quietHours(), ...$window('2026-11-23')))->toBeTrue()  // Monday
        ->and($schedule->isParentCoveredFor($pet, $pet->quietHours(), ...$window('2026-11-21')))->toBeFalse() // Saturday
        ->and($schedule->isParentCoveredFor($pet, $pet->quietHours(), ...$window('2026-11-22')))->toBeFalse(); // Sunday
});

it('walk reminder: 2 h after the night on Saturday, 2 h after school on Monday', function () {
    $f = sdFamily();
    sdPutFamily($f['parent'], SD_FAMILY)->assertOk();
    $quiet = Pet::find($f['pet']->id)->quietHours();
    $earliest = fn (string $local) => PushTiming::walkReminderEarliest($quiet, Carbon::parse($local, SD_ZONE), SD_ZONE)->setTimezone(SD_ZONE)->format('Y-m-d H:i');

    expect($earliest('2026-11-21 08:30'))->toBe('2026-11-21 09:00')  // Saturday: night ended 07:00
        ->and($earliest('2026-11-21 13:30'))->toBe('2026-11-21 13:30') // no school stretch on Saturday
        ->and($earliest('2026-11-23 10:00'))->toBe('2026-11-23 15:00') // Monday in school: end 13:00 + 2 h
        ->and($earliest('2026-11-23 13:30'))->toBe('2026-11-23 15:00');
});

it('a pet\'s own hours carry their own days; missing days keep the stored ones; the other pet follows the family', function () {
    $f = sdFamily();
    sdPutFamily($f['parent'], SD_FAMILY)->assertOk(); // family Mon–Fri

    // Switched to own hours by an OLD app (no school_days): Mon–Fri default.
    sdPutPet($f['parent'], $f['pet'], SD_FAMILY)->assertOk()->assertJsonPath('quiet_hours.school_days', [1, 2, 3, 4, 5]);

    sdPutPet($f['parent'], $f['pet'], SD_FAMILY + ['school_days' => [3]])->assertOk()
        ->assertJsonPath('quiet_hours.school_days', [3])
        ->assertJsonPath('quiet_hours.follows_family', false);

    expect(sdQuiet($f['pet'], '2026-11-25 10:00'))->toBeTrue()   // Wednesday
        ->and(sdQuiet($f['pet'], '2026-11-23 10:00'))->toBeFalse() // Monday
        ->and(sdQuiet($f['other'], '2026-11-23 10:00'))->toBeTrue()
        ->and(sdQuiet($f['other'], '2026-11-25 10:00'))->toBeTrue();

    // An old app edits the pet's times: the pet's own days stay.
    sdPutPet($f['parent'], $f['pet'], ['school_start' => '09:00'] + SD_FAMILY)->assertOk()
        ->assertJsonPath('quiet_hours.school_days', [3])
        ->assertJsonPath('quiet_hours.school_start', '09:00');

    // The ledger's batch read gets the same schedule.
    $inputs = (new ReflectionMethod(RoutineLedgerService::class, 'loadInputs'))
        ->invoke(app(RoutineLedgerService::class), collect([Pet::find($f['pet']->id), Pet::find($f['other']->id)]), '2026-11-23', '2026-11-23');
    expect($inputs[$f['pet']->id]['quiet']->schoolDays())->toBe([3])
        ->and($inputs[$f['pet']->id]['quiet']->isQuietNow(Carbon::parse('2026-11-23 10:00', SD_ZONE)))->toBeFalse()
        ->and($inputs[$f['other']->id]['quiet']->schoolDays())->toBe([1, 2, 3, 4, 5])
        ->and($inputs[$f['other']->id]['quiet']->isQuietNow(Carbon::parse('2026-11-23 10:00', SD_ZONE)))->toBeTrue();

    // A pet schedule stored before 2026-11-22 (no key) reads Mon–Fri.
    DB::table('pets')->where('id', $f['other']->id)->update(['own_quiet_hours' => json_encode(SD_FAMILY)]);
    expect(Pet::find($f['other']->id)->quietHours()->schoolDays())->toBe([1, 2, 3, 4, 5])
        ->and(sdQuiet($f['other'], '2026-11-22 10:00'))->toBeFalse();
});

it('existing closed days do not change when the days change', function () {
    $f = sdFamily(['arrival_age_months' => 3, 'born_at' => Carbon::parse('2026-11-15 10:00', 'UTC')]);
    sdPutFamily($f['parent'], SD_FAMILY)->assertOk();

    sdAt('2026-11-23 00:30'); // Monday: Saturday + Sunday are closed now
    app(RoutineLedgerService::class)->closeDueDays(now());
    $snapshot = fn () => DB::table('pet_daily_routines')->where('pet_id', $f['pet']->id)
        ->whereIn('local_date', ['2026-11-21', '2026-11-22'])->orderBy('id')
        ->get(['local_date', 'routine_type', 'slot', 'opens_at', 'due_at', 'status'])->map(fn ($r) => (array) $r)->all();
    $before = $snapshot();
    expect($before)->not->toBe([]);

    sdPutFamily($f['parent'], SD_FAMILY + ['school_days' => [1, 2, 3, 4, 5, 6, 7]])->assertOk();
    sdAt('2026-11-23 01:30');
    app(RoutineLedgerService::class)->closeDueDays(now());

    expect($snapshot())->toBe($before);
});

it('DST week: Mon–Fri school + nightly bedtime over the week the clocks go back (pet in Lisbon)', function () {
    $f = sdFamily(['timezone' => 'Europe/Lisbon']);
    sdPutFamily($f['parent'], SD_FAMILY)->assertOk();
    $quiet = Pet::find($f['pet']->id)->quietHours();
    $from = Carbon::parse('2026-10-19 00:00', 'Europe/Lisbon'); // Monday
    $to = Carbon::parse('2026-10-26 00:00', 'Europe/Lisbon');   // next Monday (Sun 25 Oct has 25 h)

    $split = QuietHours::splitSecondsBetween($quiet, $from, $to);
    // School 5 × 5 h = 25 h; nights: Mon 0–7 (7 h) + 5 × 10 h + Sat→Sun 11 h (fall back) + Sun 21–24 (3 h) = 71 h.
    expect($split['quiet'] + $split['normal'])->toBe(169.0 * 3600)
        ->and($split['quiet'])->toBe(96.0 * 3600);

    expect($quiet->isQuietNow(Carbon::parse('2026-10-23 10:00', 'Europe/Lisbon')))->toBeTrue()   // Friday
        ->and($quiet->isQuietNow(Carbon::parse('2026-10-24 10:00', 'Europe/Lisbon')))->toBeFalse() // Saturday
        ->and($quiet->isQuietNow(Carbon::parse('2026-10-25 10:00', 'Europe/Lisbon')))->toBeFalse() // Sunday (DST day)
        ->and($quiet->isQuietNow(Carbon::parse('2026-10-26 10:00', 'Europe/Lisbon')))->toBeTrue(); // Monday
});

it('a school window across midnight counts on the date of the instant; midnight is a boundary', function () {
    $f = sdFamily();
    // Night-shift style school 22:00–02:00 on Fridays only, no bedtime.
    sdPutFamily($f['parent'], ['school_start' => '22:00', 'school_end' => '02:00', 'bedtime_start' => null, 'bedtime_end' => null, 'is_active' => true, 'school_days' => [5]])->assertOk();
    $quiet = Pet::find($f['pet']->id)->quietHours();

    expect($quiet->isQuietNow(Carbon::parse('2026-11-27 23:00', SD_ZONE)))->toBeTrue()   // Friday 23:00
        ->and($quiet->isQuietNow(Carbon::parse('2026-11-28 01:00', SD_ZONE)))->toBeFalse() // Saturday 01:00
        ->and($quiet->nextBoundaryAfter(Carbon::parse('2026-11-27 23:00', SD_ZONE))?->toIso8601String())
        ->toBe(Carbon::parse('2026-11-28 00:00', SD_ZONE)->toIso8601String());
});

it('migration: existing family rows are backfilled to Mon–Fri; down() drops the column', function () {
    $f = sdFamily();
    sdPutFamily($f['parent'], SD_FAMILY + ['school_days' => [6]])->assertOk();
    $migration = require database_path('migrations/2027_05_01_120000_add_school_days_to_quiet_hours.php');

    $migration->down();
    expect(DB::getSchemaBuilder()->hasColumn('quiet_hours', 'school_days'))->toBeFalse();

    $migration->up();
    expect(DB::table('quiet_hours')->where('family_id', $f['pet']->family_id)->value('school_days'))->toBe('[1, 2, 3, 4, 5]')
        ->and(QuietHours::where('family_id', $f['pet']->family_id)->first()->schoolDays())->toBe([1, 2, 3, 4, 5]);
    expect(fn () => DB::table('quiet_hours')->where('family_id', $f['pet']->family_id)->update(['school_days' => '{"a":1}']))
        ->toThrow(QueryException::class);
});
