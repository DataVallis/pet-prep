<?php

use App\Enums\PushType;
use App\Events\PetUpdated;
use App\Models\DevicePushToken;
use App\Models\Family;
use App\Models\Pet;
use App\Models\PushNotification;
use App\Models\QuietHours;
use App\Models\User;
use App\Services\AccountExportService;
use App\Services\CareScheduleService;
use App\Services\ChildPinLoginService;
use App\Services\ChildProfileService;
use App\Services\FamilyService;
use App\Services\NotificationService;
use App\Services\PetDecayService;
use App\Services\RoutineLedgerService;
use App\Services\SleepPayload;
use App\Support\ClockText;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

/*
|--------------------------------------------------------------------------
| Quiet hours per pet + 12 / 24 h clock (David 2026-11-16)
|--------------------------------------------------------------------------
|
| »Mi imamo 3 pse in mačko. Hčerka mačko, sin psa. Jaz psa in žena psa. Zdaj
| pa ko nastavim school hours mi avtomatsko za vse živali velja. To ni ok.«
| Every pet follows the family's quiet hours; a parent can give one pet its
| own schedule; a family change never moves it; null = follow again.
| November 2026: Europe/Ljubljana = UTC+1. Instants are UTC.
*/

/**
 * A Ljubljana family (bedtime 21:00–07:00, no school window) with two
 * children, each caring for a pet of the same breed.
 *
 * @return array{parent: User, kid1: User, kid2: User, own: Pet, other: Pet, family: Family}
 */
function pqhFamily(): array
{
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $kid1 = User::factory()->child()->create(['parent_id' => $parent->id]);
    $kid2 = User::factory()->child()->create(['parent_id' => $parent->id]);
    setQuietHours(['parent_id' => $parent->id, 'bedtime_start' => '21:00', 'bedtime_end' => '07:00', 'is_active' => true]);

    $attributes = [
        'born_at' => Carbon::parse('2026-11-01 10:00', 'UTC'),
        'hunger_level' => 100,
        'thirst_level' => 100,
        'last_decay_at' => now(),
    ];
    $own = disableHygieneEvents(Pet::factory()->create(['user_id' => $kid1->id] + $attributes));
    $other = disableHygieneEvents(Pet::factory()->create(['user_id' => $kid2->id] + $attributes));

    return [
        'parent' => $parent,
        'kid1' => $kid1,
        'kid2' => $kid2,
        'own' => $own->fresh(),
        'other' => $other->fresh(),
        'family' => app(FamilyService::class)->familyOf($parent),
    ];
}

function pqhAt(string $utc): void
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
}

/** School 08:00–13:00 (Ljubljana) + bedtime 20:00–06:30 for one pet. */
const PQH_SCHOOL = ['school_start' => '08:00', 'school_end' => '13:00', 'bedtime_start' => '20:00', 'bedtime_end' => '06:30', 'is_active' => true];

/**
 * @param  array<string, mixed>|null  $quietHours
 */
function pqhPut(User $parent, Pet $pet, ?array $quietHours): TestResponse
{
    actingAs($parent, 'sanctum');

    return putJson("/api/parent/pets/{$pet->id}/quiet-hours", ['quiet_hours' => $quietHours]);
}

beforeEach(function () {
    seedBreedConfigs();
    pqhAt('2026-11-16 08:00'); // Monday 09:00 Ljubljana
});

afterEach(function () {
    Carbon::setTestNow();
});

// ── Storage, endpoint, payloads ──────────────────────────────────────────────

it('gives ONE pet its own quiet hours; the family and the other pet stay', function () {
    $f = pqhFamily();
    Event::fake([PetUpdated::class]);

    pqhPut($f['parent'], $f['own'], PQH_SCHOOL)->assertOk()
        ->assertJsonPath('pet_id', $f['own']->id)
        ->assertJsonPath('quiet_hours_follow_family', false)
        ->assertJsonPath('quiet_hours.school_start', '08:00')
        ->assertJsonPath('quiet_hours.bedtime_end', '06:30')
        ->assertJsonPath('quiet_hours.follows_family', false);

    // 09:00 Ljubljana: the own pet is in school quiet, the other is not.
    expect(Pet::find($f['own']->id)->quietHours()->isQuietNow())->toBeTrue()
        ->and(Pet::find($f['own']->id)->quietHours()->isPetOwn())->toBeTrue()
        ->and(Pet::find($f['other']->id)->quietHours()->isQuietNow())->toBeFalse()
        ->and(QuietHours::where('family_id', $f['family']->id)->count())->toBe(1)
        ->and(QuietHours::where('family_id', $f['family']->id)->first()->school_start)->toBeNull();

    Event::assertDispatchedTimes(PetUpdated::class, 1);
    Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->petId === $f['own']->id && $e->eventType === 'pet_quiet_hours_changed');

    // Same value again: nothing to do, no broadcast.
    pqhPut($f['parent'], $f['own'], PQH_SCHOOL)->assertOk();
    Event::assertDispatchedTimes(PetUpdated::class, 1);
});

it('dashboard and child state show the effective schedule and whether it is the family\'s', function () {
    $f = pqhFamily();
    pqhPut($f['parent'], $f['own'], PQH_SCHOOL)->assertOk();

    actingAs($f['parent'], 'sanctum');
    $pets = collect(getJson('/api/parent/dashboard')->assertOk()->json('family.pets'))->keyBy('id');
    expect($pets[$f['own']->id]['quiet_hours_follow_family'])->toBeFalse()
        ->and($pets[$f['own']->id]['quiet_hours']['school_start'])->toBe('08:00')
        ->and($pets[$f['other']->id]['quiet_hours_follow_family'])->toBeTrue()
        ->and($pets[$f['other']->id]['quiet_hours'])->toBe([
            'school_start' => null, 'school_end' => null, 'bedtime_start' => '21:00', 'bedtime_end' => '07:00',
            'is_active' => true, 'school_days' => [1, 2, 3, 4, 5], 'follows_family' => true,
        ]);

    actingAs($f['kid1'], 'sanctum');
    getJson('/api/child/pet')->assertOk()
        ->assertJsonPath('quiet_hours.school_start', '08:00')
        ->assertJsonPath('quiet_hours_follow_family', false);
    actingAs($f['kid2'], 'sanctum');
    getJson('/api/child/pet')->assertOk()
        ->assertJsonPath('quiet_hours.bedtime_start', '21:00')
        ->assertJsonPath('quiet_hours_follow_family', true);
});

it('a family change never moves a pet with its own hours; null follows the family again', function () {
    $f = pqhFamily();
    pqhPut($f['parent'], $f['own'], PQH_SCHOOL)->assertOk();

    // Family gets a school window 08:00–12:00 through the OLD endpoint.
    actingAs($f['parent'], 'sanctum');
    putJson('/api/parent/quiet-hours', ['school_start' => '08:00', 'school_end' => '12:00', 'bedtime_start' => '22:00', 'bedtime_end' => '06:00', 'is_active' => true])->assertOk();

    pqhAt('2026-11-16 11:30'); // 12:30 Ljubljana: own school (–13:00) yes, family school (–12:00) no
    expect(Pet::find($f['own']->id)->quietHours()->isQuietNow())->toBeTrue()
        ->and(Pet::find($f['other']->id)->quietHours()->isQuietNow())->toBeFalse()
        ->and(Pet::find($f['own']->id)->quietHours()->bedtime_start)->toBe('20:00');

    pqhPut($f['parent'], $f['own'], null)->assertOk()
        ->assertJsonPath('quiet_hours_follow_family', true)
        ->assertJsonPath('quiet_hours.school_end', '12:00')
        ->assertJsonPath('quiet_hours.follows_family', true);

    expect(Pet::find($f['own']->id)->own_quiet_hours)->toBeNull()
        ->and(Pet::find($f['own']->id)->quietHours()->isQuietNow())->toBeFalse()
        ->and(Pet::find($f['own']->id)->quietHours()->isPetOwn())->toBeFalse();
});

it('the old family endpoint keeps its shape (old apps) — only `school_days` added (David 2026-11-22)', function () {
    $f = pqhFamily();
    pqhPut($f['parent'], $f['own'], PQH_SCHOOL)->assertOk();

    actingAs($f['parent'], 'sanctum');
    $get = getJson('/api/parent/quiet-hours')->assertOk()->json();
    expect(array_keys($get))->toBe(['quiet_hours', 'timezone'])
        ->and(array_keys($get['quiet_hours']))->toBe(['id', 'school_start', 'school_end', 'bedtime_start', 'bedtime_end', 'is_active', 'school_days', 'saved'])
        ->and($get['quiet_hours']['bedtime_start'])->toBe('21:00');

    $put = putJson('/api/parent/quiet-hours', ['bedtime_start' => '22:00', 'bedtime_end' => '06:00', 'is_active' => true])->assertOk()->json();
    expect(array_keys($put))->toBe(['message', 'quiet_hours', 'timezone'])
        ->and($put['quiet_hours']['bedtime_start'])->toBe('22:00');
});

it('authorizes: another family\'s parent and children 403, unknown pet 404, bad times 422', function () {
    $f = pqhFamily();
    $stranger = User::factory()->parent()->create();

    pqhPut($stranger, $f['own'], PQH_SCHOOL)->assertForbidden();
    pqhPut($f['kid1'], $f['own'], PQH_SCHOOL)->assertForbidden();
    pqhPut($f['parent'], $f['own'], ['school_start' => '8 am'] + PQH_SCHOOL)->assertUnprocessable()
        ->assertJsonValidationErrors('quiet_hours.school_start');
    actingAs($f['parent'], 'sanctum');
    putJson('/api/parent/pets/999999/quiet-hours', ['quiet_hours' => null])->assertNotFound();
    putJson("/api/parent/pets/{$f['own']->id}/quiet-hours", [])->assertUnprocessable();

    expect(Pet::find($f['own']->id)->own_quiet_hours)->toBeNull();
});

it('refuses an empty or partial schedule (422) — nothing is wiped (QA #1259 M5)', function () {
    $f = pqhFamily();
    pqhPut($f['parent'], $f['own'], PQH_SCHOOL)->assertOk();

    pqhPut($f['parent'], $f['own'], [])->assertUnprocessable()
        ->assertJsonValidationErrors(['quiet_hours.school_start', 'quiet_hours.school_end', 'quiet_hours.bedtime_start', 'quiet_hours.bedtime_end', 'quiet_hours.is_active']);
    pqhPut($f['parent'], $f['own'], ['school_start' => '09:00', 'school_end' => '12:00'])->assertUnprocessable()
        ->assertJsonValidationErrors(['quiet_hours.bedtime_start', 'quiet_hours.bedtime_end', 'quiet_hours.is_active'])
        ->assertJsonMissingValidationErrors(['quiet_hours.school_start']);
    pqhPut($f['parent'], $f['own'], ['is_active' => 'yes'] + PQH_SCHOOL)->assertUnprocessable()
        ->assertJsonValidationErrors('quiet_hours.is_active');

    expect(Pet::find($f['own']->id)->ownQuietHoursValues())->toBe(PQH_SCHOOL + ['school_days' => [1, 2, 3, 4, 5]]);

    // Every key present, windows null = a pet with no quiet windows (allowed, like the family editor).
    $none = ['school_start' => null, 'school_end' => null, 'bedtime_start' => null, 'bedtime_end' => null, 'is_active' => true];
    pqhPut($f['parent'], $f['own'], $none)->assertOk()->assertJsonPath('quiet_hours.bedtime_start', null);
});

it('every sign-in payload carries `time_format` like GET /api/user (QA #1259 m7)', function () {
    $parent = User::factory()->parent()->create(['email' => 'qh-tf@example.com', 'password' => 'secret-pass-1', 'time_format' => '12h']);

    postJson('/api/login', ['email' => 'qh-tf@example.com', 'password' => 'secret-pass-1', 'device_name' => 'test'])
        ->assertOk()->assertJsonPath('user.time_format', '12h');
    postJson('/api/register', [
        'name' => 'Nova', 'email' => 'qh-new@example.com', 'password' => 'Varno1Geslo', 'password_confirmation' => 'Varno1Geslo',
        'timezone' => 'Europe/Ljubljana', 'accept_terms' => true, 'device_name' => 'test',
    ])->assertCreated()->assertJsonPath('user.time_format', 'auto');

    // A child's PIN login (ChildAuthController::session) too — a new pet from a fresh PIN.
    $kid = app(ChildProfileService::class)->createChild($parent, 'Maja', null);
    $kid->forceFill(['time_format' => '24h'])->save();
    $pin = app(ChildPinLoginService::class)->generatePin($parent, $kid->id, null)['pin'];
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);
    postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'test'])
        ->assertOk()->assertJsonPath('user.time_format', '24h');
});

// ── Rules read the pet's schedule ───────────────────────────────────────────

it('decay: the own school hours slow only that pet (×0.1)', function () {
    config(['feeding.hunger_floor_percent' => 0]);
    $f = pqhFamily();
    pqhPut($f['parent'], $f['own'], PQH_SCHOOL)->assertOk();
    Pet::whereKey([$f['own']->id, $f['other']->id])->update(['last_decay_at' => Carbon::parse('2026-11-16 08:00', 'UTC'), 'hunger_level' => 100]);

    pqhAt('2026-11-16 10:00'); // 09:00–11:00 Ljubljana
    app(PetDecayService::class)->processPetDecay(Pet::findOrFail($f['own']->id));
    app(PetDecayService::class)->processPetDecay(Pet::findOrFail($f['other']->id));

    $ownLoss = 100 - Pet::find($f['own']->id)->hunger_level;
    $otherLoss = 100 - Pet::find($f['other']->id)->hunger_level;
    expect($otherLoss)->toBeGreaterThan(0.0)
        ->and($ownLoss)->toEqualWithDelta($otherLoss * PetDecayService::QUIET_HOURS_DECAY_MULTIPLIER, 0.0001);
});

it('sleep, pushes and parent-covered meals follow the pet\'s own hours', function () {
    config(['push.enabled' => true]);
    Queue::fake();
    $f = pqhFamily();
    pqhPut($f['parent'], $f['own'], PQH_SCHOOL)->assertOk();
    $own = Pet::find($f['own']->id);
    $other = Pet::find($f['other']->id);

    // Asleep in its own school hours (09:00 Ljubljana), until 13:00.
    expect(SleepPayload::for($own)->asleep)->toBeTrue()
        ->and(SleepPayload::for($own)->until)->toBe('2026-11-16T13:00:00+01:00')
        ->and(SleepPayload::for($other)->asleep)->toBeFalse();

    // A hunger warning is dropped for the sleeping pet, sent for the other.
    $n = app(NotificationService::class);
    expect($n->escalation($own, PushType::SoftWarning, 'hunger')->suppressed_reason)->toBe('quiet_hours')
        ->and($n->escalation($other, PushType::SoftWarning, 'hunger')->status)->toBe(PushNotification::STATUS_QUEUED);

    // A 10–12 meal is the parent's for the own pet only (profiled pets).
    Pet::whereKey([$own->id, $other->id])->update(['arrival_age_months' => 3]);
    $own = Pet::find($own->id);
    $other = Pet::find($other->id);
    $start = Carbon::parse('2026-11-16 10:00', 'Europe/Ljubljana');
    $end = Carbon::parse('2026-11-16 12:00', 'Europe/Ljubljana');
    $schedule = app(CareScheduleService::class);
    expect($schedule->isParentCoveredFor($own, $own->quietHours(), $start, $end))->toBeTrue()
        ->and($schedule->isParentCoveredFor($other, $other->quietHours(), $start, $end))->toBeFalse();
});

it('routines (ledger inputs) read the pet\'s own hours; a partial select never falls back to the family', function () {
    $f = pqhFamily();
    pqhPut($f['parent'], $f['own'], PQH_SCHOOL)->assertOk();

    $inputs = (new ReflectionMethod(RoutineLedgerService::class, 'loadInputs'))
        ->invoke(app(RoutineLedgerService::class), collect([Pet::find($f['own']->id), Pet::find($f['other']->id)]), '2026-11-16', '2026-11-16');
    expect($inputs[$f['own']->id]['quiet']->isPetOwn())->toBeTrue()
        ->and($inputs[$f['own']->id]['quiet']->school_start)->toBe('08:00')
        ->and($inputs[$f['own']->id]['quiet']->timezone())->toBe('Europe/Ljubljana')
        ->and($inputs[$f['other']->id]['quiet']->isPetOwn())->toBeFalse();

    $partial = Pet::query()->whereKey($f['own']->id)->first(['id', 'family_id']);
    expect($partial->quietHours()->school_start)->toBe('08:00');
});

it('a pet\'s own schedule can never be saved as a family row', function () {
    $f = pqhFamily();
    pqhPut($f['parent'], $f['own'], PQH_SCHOOL)->assertOk();

    expect(fn () => Pet::find($f['own']->id)->quietHours()->save())->toThrow(LogicException::class);
});

it('GDPR export lists the pet\'s own hours; deleting the pet takes them along', function () {
    $f = pqhFamily();
    pqhPut($f['parent'], $f['own'], PQH_SCHOOL)->assertOk();

    $export = app(AccountExportService::class)->exportFor($f['parent']);
    $pets = collect($export['pets'])->keyBy('id');
    expect($pets[$f['own']->id]['own_quiet_hours'])->toBe(PQH_SCHOOL + ['school_days' => [1, 2, 3, 4, 5]])
        ->and($pets[$f['own']->id]['quiet_hours_follow_family'])->toBeFalse()
        ->and($pets[$f['other']->id]['own_quiet_hours'])->toBeNull()
        ->and($pets[$f['other']->id]['quiet_hours_follow_family'])->toBeTrue();
});

// ── 12 / 24 h clock ──────────────────────────────────────────────────────────

it('ClockText: 24 h unchanged, 12 h with noon / midnight and per-language suffix', function () {
    expect(ClockText::of(7, 0))->toBe('7:00')
        ->and(ClockText::of(17, 5))->toBe('17:05')
        ->and(ClockText::of(0, 0, true, 'en'))->toBe('12:00 AM')
        ->and(ClockText::of(12, 0, true, 'en'))->toBe('12:00 PM')
        ->and(ClockText::of(17, 30, true, 'en'))->toBe('5:30 PM')
        ->and(ClockText::of(9, 15, true, 'sl'))->toBe('9:15 dop.')
        ->and(ClockText::of(23, 45, true, 'sl'))->toBe('11:45 pop.')
        ->and(ClockText::to12h('17:00', 'en'))->toBe('5:00 PM')
        ->and(ClockText::to12h('later', 'en'))->toBe('later');
});

it('time format: auto by default, PUT for parent and child, unknown value 422, in GET /api/user and the export', function () {
    $f = pqhFamily();

    actingAs($f['parent'], 'sanctum');
    getJson('/api/user')->assertOk()->assertJsonPath('time_format', 'auto');
    putJson('/api/user/time-format', ['time_format' => '12h'])->assertOk()->assertJsonPath('time_format', '12h');
    putJson('/api/user/time-format', ['time_format' => 'am/pm'])->assertUnprocessable();
    getJson('/api/user')->assertJsonPath('time_format', '12h');

    actingAs($f['kid1'], 'sanctum');
    putJson('/api/user/time-format', ['time_format' => '24h'])->assertOk();

    expect(User::find($f['parent']->id)->time_format)->toBe('12h')
        ->and(User::find($f['kid1']->id)->time_format)->toBe('24h');

    $export = app(AccountExportService::class)->exportFor(User::find($f['parent']->id));
    expect($export['parents'][0]['time_format'] ?? null)->toBe('12h');
});

it('devices store the phone\'s own 12 h setting and keep it when an app does not send it', function () {
    $f = pqhFamily();
    actingAs($f['parent'], 'sanctum');
    $token = 'ExponentPushToken[pqh-device-'.$f['parent']->id.']';

    postJson('/api/devices', ['expo_push_token' => $token, 'platform' => 'ios', 'hour12' => true])->assertOk()
        ->assertJsonPath('device.hour12', true);
    postJson('/api/devices', ['expo_push_token' => $token, 'platform' => 'ios'])->assertOk()
        ->assertJsonPath('device.hour12', true);
    postJson('/api/devices', ['expo_push_token' => $token, 'platform' => 'ios', 'hour12' => false])->assertOk()
        ->assertJsonPath('device.hour12', false);
});

it('push `:time` follows the person\'s choice, `auto` = the phone; unknown phone stays 24 h', function (string $format, ?bool $hour12, string $expected) {
    $f = pqhFamily();
    $kid = $f['kid1'];
    $kid->forceFill(['time_format' => $format])->save();
    $device = DevicePushToken::create([
        'user_id' => $kid->id, 'expo_push_token' => 'ExponentPushToken[pqh-message-1]', 'platform' => 'ios',
        'locale' => 'en', 'hour12' => $hour12, 'last_seen_at' => now(),
    ])->load('user:id,time_format');
    $row = new PushNotification(['type' => PushType::SoftWarning, 'metric' => 'hunger', 'pet_id' => $f['own']->id]);
    $copy = ['variant' => 'wait', 'replace' => ['time' => '17:00'], 'species' => $f['own']->speciesValue()];

    $message = (new ReflectionMethod(NotificationService::class, 'message'))
        ->invoke(app(NotificationService::class), $row, $device, PushNotification::AUDIENCE_CHILD, $copy);

    expect($message['body'])->toContain('at '.$expected);
})->with([
    'auto, phone unknown (old app)' => ['auto', null, '17:00'],
    'auto, 24 h phone' => ['auto', false, '17:00'],
    'auto, 12 h phone' => ['auto', true, '5:00 PM'],
    '12h chosen on a 24 h phone' => ['12h', false, '5:00 PM'],
    '24h chosen on a 12 h phone' => ['24h', true, '17:00'],
]);
