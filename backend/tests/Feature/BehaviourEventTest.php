<?php

use App\Enums\ActivityType;
use App\Enums\HygieneEventKind;
use App\Enums\HygieneEventStatus;
use App\Enums\PetStateEnum;
use App\Enums\RoutineStatus;
use App\Enums\RoutineType;
use App\Enums\StageParamKey;
use App\Events\PetUpdated;
use App\Models\ActivityLog;
use App\Models\BreedStageParam;
use App\Models\Pet;
use App\Models\PetDailyRoutine;
use App\Models\PetHygieneEvent;
use App\Models\PetMedia;
use App\Models\User;
use App\Services\BehaviourEventService;
use App\Services\BehaviourPayload;
use App\Services\CareScoreService;
use App\Services\ChildProfileService;
use App\Services\FamilyService;
use App\Services\LifeStageService;
use App\Services\Media\MediaEntitlementService;
use App\Services\Media\PetAppearancePrompt;
use App\Services\Media\PetMediaService;
use App\Services\PetDecayService;
use App\Services\Results\Routine;
use App\Services\RoutineLedgerService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| M5-R02 — behaviour events (David 2026-10-06): puppy accidents + "Pelji
| ven", chewing (teething / missed walk) + "Pospravi in daj igračo",
| scoring as clean routines, payload, premium behaviour videos; per-pet
| gate on the app's generate-pin `features` (PR #42 B1); no make-up after
| a scheduler outage (PR #42 M1).
|--------------------------------------------------------------------------
| Family in Europe/Ljubljana: UTC+2 until 2026-10-25 03:00 local, then
| UTC+1. Quiet hours (when on): school 08–13, bedtime 22–06 local
| = 06–11 and 20–04 UTC in October before the 25th.
*/

beforeEach(function () {
    seedLifeStageData();
});

function beAt(string $utc): void
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
}

/**
 * A born dog with a profile of a child (poops off; behaviour events ON
 * unless the attributes say otherwise).
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: User, 1: User, 2: Pet}
 */
function beFamily(string $bornUtc, int $arrivalMonths = 2, bool $quiet = true, array $attributes = []): array
{
    beAt($bornUtc);
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => 'Maja']);
    if ($quiet) {
        setQuietHours([
            'parent_id' => $parent->id,
            'school_start' => '08:00', 'school_end' => '13:00',
            'bedtime_start' => '22:00', 'bedtime_end' => '06:00',
            'is_active' => true,
        ]);
    } else {
        withoutQuietHours($parent); // none = switched off (default night 21–07 since 2026-10-08)
    }
    $pet = Pet::factory()->mutt()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => Carbon::parse($bornUtc, 'UTC'),
        'last_decay_at' => Carbon::parse($bornUtc, 'UTC'),
        'last_step_reset_at' => Carbon::parse($bornUtc, 'UTC'),
        'arrival_age_months' => $arrivalMonths,
        'behaviour_events_enabled' => true,
    ], $attributes));
    // Random poops off: these tests are about behaviour events only.
    Pet::whereKey($pet->id)->update(['hygiene_scheduled_through' => '2999-12-31']);

    return [$parent, $child, $pet->fresh()];
}

/** One tick at $utc (the previous tick must be ≤ 5 min earlier, else it is an outage). */
function beTick(Pet $pet, string $utc): Pet
{
    beAt($utc);
    app(PetDecayService::class)->processPetDecay($pet);

    return $pet->fresh();
}

/** Ticks every $step minutes in ($fromUtc, $toUtc] — a running scheduler. */
function beRun(Pet $pet, string $fromUtc, string $toUtc, int $step = 5): Pet
{
    $end = Carbon::parse($toUtc, 'UTC');
    for ($t = Carbon::parse($fromUtc, 'UTC')->addMinutes($step); $t->lessThan($end); $t->addMinutes($step)) {
        $pet = beTick($pet, $t->toDateTimeString());
    }

    return beTick($pet, $end->toDateTimeString());
}

/** @return Collection<int, PetHygieneEvent> */
function beEvents(Pet $pet, ?HygieneEventKind $kind = null)
{
    return PetHygieneEvent::where('pet_id', $pet->id)
        ->when($kind !== null, fn ($q) => $q->where('kind', $kind->value))
        ->orderBy('scheduled_at')->get();
}

/** @return list<string> */
function beTimes(Pet $pet, HygieneEventKind $kind): array
{
    return beEvents($pet, $kind)->map(fn ($e) => $e->scheduled_at->toDateTimeString())->all();
}

/** An open (applied, unresolved) mess planted at a fixed time. */
function bePlant(Pet $pet, HygieneEventKind $kind, string $atUtc): PetHygieneEvent
{
    $at = Carbon::parse($atUtc, 'UTC');

    return PetHygieneEvent::create([
        'pet_id' => $pet->id, 'kind' => $kind->value, 'local_date' => $pet->localDate($at),
        'scheduled_at' => $at, 'status' => HygieneEventStatus::Applied, 'resolved_at' => $at,
    ]);
}

/** @return list<Routine> */
function beCleanRoutines(Pet $pet, string $date): array
{
    $all = app(RoutineLedgerService::class)->routinesFor(collect([$pet->fresh()]), $date, $date)[$pet->id];

    return array_values(array_filter($all, fn (Routine $r) => $r->type === RoutineType::Clean));
}

function beChance(float $chance): void
{
    BreedStageParam::where('key', StageParamKey::ChewingChancePerDay->value)->update(['value' => json_encode($chance)]);
    foreach (['mutt', 'border-collie'] as $slug) {
        LifeStageService::forgetBreed($slug);
    }
}

/** Parent → generate-pin (with $body) → child pin-login: the pet an app build creates. */
function beCreateViaPin(array $body, ?array $childFeatures = ['behaviour_events']): Pet
{
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = app(ChildProfileService::class)->createChild($parent, 'Maja', null);
    actingAsRole($parent);
    $pin = postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body))->assertOk();
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);
    $login = postJson('/api/child/pin-login', array_merge(
        ['pin' => $pin->json('pin'), 'device_name' => 'Tablet'],
        $childFeatures === null ? [] : ['features' => $childFeatures],   // null = old child app (no field)
    ))->assertSuccessful();

    return Pet::findOrFail($login->json('pet.id'));
}

// ──────────────────────────────────────────────────────────────
//  Per-pet gate: the app build's `features` (PR #42 B1)
// ──────────────────────────────────────────────────────────────

describe('client capability gate', function () {
    it('enables behaviour events only for a pet created with features: ["behaviour_events"]', function () {
        beAt('2026-10-12 04:00:00');
        $new = beCreateViaPin(['origin' => 'bought', 'age_stage' => 'puppy', 'features' => ['behaviour_events']]);
        $old = beCreateViaPin(['origin' => 'bought', 'age_stage' => 'puppy']);
        $legacy = beCreateViaPin(['features' => ['behaviour_events']]);   // no profile → legacy, features ignored

        expect($new->behaviour_events_enabled)->toBeTrue()
            ->and($old->behaviour_events_enabled)->toBeFalse()
            ->and($legacy->behaviour_events_enabled)->toBeFalse()
            ->and($legacy->isLegacyProfile())->toBeTrue();
    });

    it('needs BOTH devices: new parent + old child app (or the reverse) → off', function () {
        beAt('2026-10-12 04:00:00');
        $profile = ['origin' => 'bought', 'age_stage' => 'puppy'];

        $both = beCreateViaPin([...$profile, 'features' => ['behaviour_events']], ['behaviour_events']);
        $oldChild = beCreateViaPin([...$profile, 'features' => ['behaviour_events']], null);
        $childWithoutIt = beCreateViaPin([...$profile, 'features' => ['behaviour_events']], []);
        $oldParent = beCreateViaPin($profile, ['behaviour_events']);

        expect($both->behaviour_events_enabled)->toBeTrue()
            ->and($oldChild->behaviour_events_enabled)->toBeFalse()
            ->and($childWithoutIt->behaviour_events_enabled)->toBeFalse()
            ->and($oldParent->behaviour_events_enabled)->toBeFalse();
    });

    it('never changes the setting on re-login, whatever the child device declares', function () {
        beAt('2026-10-12 04:00:00');
        $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
        $child = app(ChildProfileService::class)->createChild($parent, 'Maja', null);
        actingAsRole($parent);
        $pin = postJson('/api/parent/generate-pin', ['child_id' => $child->id, 'origin' => 'bought', 'age_stage' => 'puppy'])->json('pin');
        app('auth')->forgetGuards();
        test()->withHeaders(['Authorization' => '']);
        $petId = postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => ['behaviour_events']])->json('pet.id');

        actingAsRole($parent);
        $relogin = postJson('/api/parent/generate-pin', ['child_id' => $child->id, 'features' => ['behaviour_events']])->json('pin');
        app('auth')->forgetGuards();
        test()->withHeaders(['Authorization' => '']);
        postJson('/api/child/pin-login', ['pin' => $relogin, 'device_name' => 'Phone', 'features' => ['behaviour_events']])
            ->assertSuccessful()->assertJsonPath('mode', 'relogin');

        expect(Pet::find($petId)->behaviour_events_enabled)->toBeFalse();
    });

    it('stores features on the PIN, ignores unknown values (forward compatible) and still validates the shape', function () {
        beAt('2026-10-12 04:00:00');
        $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
        $child = app(ChildProfileService::class)->createChild($parent, 'Maja', null);
        actingAsRole($parent);

        postJson('/api/parent/generate-pin', ['child_id' => $child->id, 'origin' => 'bought', 'age_stage' => 'puppy', 'features' => ['behaviour_events']])
            ->assertOk()->assertJsonPath('pet_profile.features', ['behaviour_events']);
        postJson('/api/parent/generate-pin', ['child_id' => $child->id, 'origin' => 'bought', 'age_stage' => 'puppy', 'features' => ['teleport', 'behaviour_events', 'behaviour_events']])
            ->assertOk()->assertJsonPath('pet_profile.features', ['behaviour_events']);
        $pin = postJson('/api/parent/generate-pin', ['child_id' => $child->id, 'origin' => 'bought', 'age_stage' => 'puppy', 'features' => ['teleport']])
            ->assertOk()->assertJsonPath('pet_profile.features', [])->json('pin');
        postJson('/api/parent/generate-pin', ['child_id' => $child->id, 'origin' => 'bought', 'age_stage' => 'puppy', 'features' => 'behaviour_events'])
            ->assertUnprocessable()->assertJsonValidationErrors('features');
        postJson('/api/parent/generate-pin', ['child_id' => $child->id, 'origin' => 'bought', 'age_stage' => 'puppy', 'features' => [123]])
            ->assertUnprocessable()->assertJsonValidationErrors('features.0');
        postJson('/api/parent/generate-pin', ['child_id' => $child->id, 'origin' => 'bought', 'age_stage' => 'puppy', 'features' => array_fill(0, 11, 'x')])
            ->assertUnprocessable()->assertJsonValidationErrors('features');

        // pin-login: same shape rules, unknown values ignored.
        app('auth')->forgetGuards();
        test()->withHeaders(['Authorization' => '']);
        postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => 'behaviour_events'])
            ->assertUnprocessable()->assertJsonValidationErrors('features');
        postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => ['teleport', 'behaviour_events']])
            ->assertSuccessful();
    });

    it('never gives an old-client pet (no features) accidents, chewing, a clock, take-out or the new videos', function () {
        beChance(1.0);
        [, $child, $pet] = beFamily('2026-10-12 02:00:00', 3, quiet: false, attributes: ['behaviour_events_enabled' => false, 'breed_type' => 'border_collie', 'challenge_paid_source' => 'purchase', 'life_stage' => 'puppy']);

        $pet = beRun($pet, '2026-10-12 02:00:00', '2026-10-13 02:00:00');
        expect(beEvents($pet))->toHaveCount(0)
            ->and($pet->displayMetric('hygiene_level'))->toBe(100)
            ->and(array_map(fn (PetStateEnum $s) => $s->value, app(MediaEntitlementService::class)->videoStatesFor($pet)))
            ->toBe(['idle', 'sleeping', 'low_energy', 'hungry', 'sick', 'playing']);

        actingAsRole($child);
        postJson('/api/child/pet/take-out')->assertStatus(422)
            ->assertJsonPath('reason', 'take_out_not_needed')
            ->assertJsonPath('state.behaviour.take_out', null)
            ->assertJsonPath('state.pet.profile.behaviour_enabled', false);
    });

    it('keeps the setting when a second child joins the pet', function () {
        [$parent, , $pet] = beFamily('2026-10-12 04:00:00', 2);
        $sibling = User::factory()->child()->create(['parent_id' => $parent->id]);
        app(FamilyService::class)->addCaretaker($pet, $sibling, true);

        expect($pet->fresh()->behaviour_events_enabled)->toBeTrue();
    });
});

// ──────────────────────────────────────────────────────────────
//  Puppy accidents + "Pelji ven"
// ──────────────────────────────────────────────────────────────

describe('bladder clock', function () {
    it('holds 1 hour per month of age: 2 months → 2 h, 3 months → 3 h, from the day after the weekly birthday', function () {
        [, , $two] = beFamily('2026-10-12 04:00:00', 2);
        [, , $three] = beFamily('2026-10-12 04:00:00', 3);
        $behaviour = app(BehaviourEventService::class);

        expect($behaviour->holdHoursOn($two, '2026-10-12'))->toBe(2)
            ->and($behaviour->holdHoursOn($three, '2026-10-12'))->toBe(3)
            // One real week = one month: 3 months on the day AFTER the birthday (rules at local midnight).
            ->and($behaviour->holdHoursOn($two, '2026-10-19'))->toBe(2)
            ->and($behaviour->holdHoursOn($two, '2026-10-20'))->toBe(3);
    });

    it('applies only to puppies with behaviour events (legacy, young, adult → no clock)', function () {
        [, , $legacy] = beFamily('2026-10-12 04:00:00', 2, attributes: ['arrival_age_months' => null]);
        [, , $young] = beFamily('2026-10-12 04:00:00', 9);
        [, , $adult] = beFamily('2026-10-12 04:00:00', 36);
        $behaviour = app(BehaviourEventService::class);

        foreach ([$legacy, $young, $adult] as $pet) {
            expect($behaviour->holdHoursOn($pet, '2026-10-12'))->toBeNull()
                ->and($behaviour->pottyClock($pet, $pet->quietHours()))->toBeNull();
        }
    });

    it('counts only time outside quiet hours: a 06:30 take-out with school 08–13 is due at 13:30', function () {
        [, $child, $pet] = beFamily('2026-10-12 04:00:00', 2);  // born 06:00 local
        $pet = beRun($pet, '2026-10-12 04:00:00', '2026-10-12 04:30:00');

        actingAsRole($child);
        postJson('/api/child/pet/take-out')->assertOk()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('state.behaviour.take_out.hold_hours', 2)
            ->assertJsonPath('state.behaviour.take_out.clock_started_at', '2026-10-12T06:30:00+02:00')
            // 1.5 h before school + 0.5 h after → 13:30 local.
            ->assertJsonPath('state.behaviour.take_out.next_due_at', '2026-10-12T13:30:00+02:00')
            ->assertJsonPath('state.behaviour.take_out.last_taken_out_at', '2026-10-12T06:30:00+02:00')
            ->assertJsonPath('state.behaviour.can_take_out', true)
            ->assertJsonPath('state.pet.profile.behaviour_enabled', true);

        // Nothing during school, even though 2 real hours passed long ago.
        $pet = beRun($pet, '2026-10-12 04:30:00', '2026-10-12 11:29:00');
        expect(beEvents($pet, HygieneEventKind::Accident))->toHaveCount(0)
            ->and($pet->displayMetric('hygiene_level'))->toBe(100);

        $pet = beTick($pet, '2026-10-12 11:31:00');
        $accident = beEvents($pet, HygieneEventKind::Accident)->sole();
        expect($accident->scheduled_at->toDateTimeString())->toBe('2026-10-12 11:30:00')
            ->and($accident->status)->toBe(HygieneEventStatus::Applied)
            ->and($pet->displayMetric('hygiene_level'))->toBe(0)
            ->and($pet->hygiene_zero_since->toDateTimeString())->toBe('2026-10-12 11:30:00')
            // A dirty dog stays `sick` (old app builds know six states).
            ->and($pet->pet_state)->toBe(PetStateEnum::Sick);
        // Parent timeline: one system row at the accident time, no actor.
        $row = ActivityLog::where('pet_id', $pet->id)->where('activity_type', ActivityType::PetAccident->value)->sole();
        expect($row->actor_user_id)->toBeNull()
            ->and($row->created_at->toDateTimeString())->toBe('2026-10-12 11:30:00');
    });

    it('moves a due instant that lands on a quiet start to the end of the quiet stretch, and never creates accidents in quiet hours', function () {
        [, , $pet] = beFamily('2026-10-12 04:00:00', 2);  // clock 06:00 local, 2 h → 08:00 = school start

        expect(app(BehaviourEventService::class)->pottyClock($pet, $pet->quietHours())['due_at']->toDateTimeString())
            ->toBe('2026-10-12 11:00:00');                   // 13:00 local

        // A whole day and night: accidents every 2 h of non-quiet time from 13:00
        // (13, 15, 17, 19, 21 local), none in the night.
        $pet = beRun($pet, '2026-10-12 04:00:00', '2026-10-13 03:50:00');

        expect(beTimes($pet, HygieneEventKind::Accident))->toBe([
            '2026-10-12 11:00:00', '2026-10-12 13:00:00', '2026-10-12 15:00:00',
            '2026-10-12 17:00:00', '2026-10-12 19:00:00',
        ]);
        foreach (beEvents($pet, HygieneEventKind::Accident) as $e) {
            expect($pet->quietHours()->isQuietNow($e->scheduled_at))->toBeFalse();
        }
    });

    it('restarts the clock on take-out (any due accident first), and a repeat within a minute is unchanged', function () {
        [, $child, $pet] = beFamily('2026-10-12 04:00:00', 2, quiet: false);  // no quiet hours
        actingAsRole($child);

        $pet = beRun($pet, '2026-10-12 04:00:00', '2026-10-12 05:00:00');
        postJson('/api/child/pet/take-out')->assertOk()->assertJsonPath('status', 'accepted');
        beAt('2026-10-12 05:00:30');
        postJson('/api/child/pet/take-out')->assertOk()->assertJsonPath('status', 'unchanged');
        expect(ActivityLog::where('pet_id', $pet->id)->where('activity_type', 'took_out_pet')->count())->toBe(1);

        // Without the take-out the accident would have been at 06:00 UTC.
        $pet = beRun($pet, '2026-10-12 05:00:30', '2026-10-12 06:30:00');
        expect(beEvents($pet, HygieneEventKind::Accident))->toHaveCount(0);

        // Take-out at 07:01, just after the due 07:00 (last tick 06:58): the accident happened first.
        $pet = beRun($pet, '2026-10-12 06:30:00', '2026-10-12 06:58:00', 4);
        beAt('2026-10-12 07:01:00');
        postJson('/api/child/pet/take-out')->assertOk()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('state.pet.hygiene_level', 0)
            ->assertJsonPath('state.behaviour.active_events.0.kind', 'accident')
            ->assertJsonPath('state.behaviour.scene', 'accident')
            ->assertJsonPath('state.behaviour.take_out.next_due_at', '2026-10-12T11:01:00+02:00');
        expect(beTimes($pet, HygieneEventKind::Accident))->toBe(['2026-10-12 07:00:00']);
    });

    it('restarts the clock at the family-local midnight (the night is the parents\')', function () {
        [, , $pet] = beFamily('2026-10-12 17:00:00', 2, quiet: false);  // born 19:00 local

        // Local: 19:00 + 2 h = 21:00 → accident; 21:00 + 2 h = 23:00 → accident; then
        // 23:00 + 2 h would be 01:00, but the clock restarts at 00:00 → 02:00 (00:00 UTC).
        $pet = beRun($pet, '2026-10-12 17:00:00', '2026-10-13 00:05:00');
        expect(beTimes($pet, HygieneEventKind::Accident))
            ->toBe(['2026-10-12 19:00:00', '2026-10-12 21:00:00', '2026-10-13 00:00:00']);
    });

    it('is DST safe: on 2026-10-25 (25-hour day) the clock follows the wall clock and real hours', function () {
        [, , $pet] = beFamily('2026-10-24 18:00:00', 2);  // 20:00 CEST, quiet 22–06 / 08–13

        // Night of the change: bedtime until 06:00 CET = 05:00 UTC; 2 h → 08:00 CET = school
        // start → 13:00 CET = 12:00 UTC (in summer the same wall time was 11:00 UTC).
        beAt('2026-10-25 05:00:00');
        expect(app(BehaviourEventService::class)->pottyClock($pet->fresh(), $pet->quietHours())['due_at']->toDateTimeString())
            ->toBe('2026-10-25 12:00:00');

        // Without quiet hours: born 23:00 CEST; 23:00 + 2 h runs over midnight, so the
        // clock restarts at 00:00 CEST (22:00 UTC on the 24th) → 00:00 UTC (02:00 CEST);
        // the next is 2 REAL hours later: 02:00 UTC = 03:00 CET (the clock went back at 03:00 CEST).
        [, , $plain] = beFamily('2026-10-24 21:00:00', 2, quiet: false);
        $plain = beRun($plain, '2026-10-24 21:00:00', '2026-10-25 02:05:00');
        expect(beTimes($plain, HygieneEventKind::Accident))->toBe(['2026-10-25 00:00:00', '2026-10-25 02:00:00'])
            ->and(beEvents($plain, HygieneEventKind::Accident)->map(fn ($e) => $e->local_date->toDateString())->unique()->all())
            ->toBe(['2026-10-25']);
    });

    it('makes up nothing after a scheduler outage: no accident for the gap, the clock restarts at the tick', function () {
        [, , $pet] = beFamily('2026-10-12 04:00:00', 2, quiet: false);   // due 06:00 UTC

        $pet = beRun($pet, '2026-10-12 04:00:00', '2026-10-12 05:00:00');
        // The scheduler is down 05:00 → 09:00 (two due instants pass).
        $pet = beTick($pet, '2026-10-12 09:00:00');

        expect(beEvents($pet, HygieneEventKind::Accident))->toHaveCount(0)
            ->and($pet->potty_clock_started_at->toDateTimeString())->toBe('2026-10-12 09:00:00')
            ->and($pet->displayMetric('hygiene_level'))->toBe(100);
        $pet = beRun($pet, '2026-10-12 09:00:00', '2026-10-12 11:05:00');
        expect(beTimes($pet, HygieneEventKind::Accident))->toBe(['2026-10-12 11:00:00']);
    });
});

describe('no behaviour events', function () {
    it('never makes accidents or chewing for legacy, adult or unborn pets', function () {
        beChance(1.0);
        [, , $legacy] = beFamily('2026-10-12 04:00:00', 4, quiet: false, attributes: ['arrival_age_months' => null]);
        [, , $adult] = beFamily('2026-10-12 04:00:00', 36, quiet: false);
        [, , $unborn] = beFamily('2026-10-12 04:00:00', 4, quiet: false, attributes: ['born_at' => null]);

        for ($t = Carbon::parse('2026-10-12 04:05:00', 'UTC'); $t->lessThanOrEqualTo(Carbon::parse('2026-10-13 04:00:00', 'UTC')); $t->addMinutes(5)) {
            beAt($t->toDateTimeString());
            app(PetDecayService::class)->processAllActivePets();
        }

        foreach ([$legacy, $adult, $unborn] as $pet) {
            expect(beEvents($pet))->toHaveCount(0)
                ->and($pet->fresh()->displayMetric('hygiene_level'))->toBe(100);
        }
    });

    it('refuses take-out exactly when the payload has no clock (422 ⇔ take_out null), and 423 while locked or unborn', function () {
        $cases = [
            'young' => [9, [], 422],
            'adult' => [36, [], 422],
            'old client' => [2, ['behaviour_events_enabled' => false], 422],
            'puppy' => [2, [], 200],
        ];
        foreach ($cases as $label => [$age, $attrs, $status]) {
            [, $kid] = beFamily('2026-10-12 04:00:00', $age, attributes: $attrs);
            app('auth')->forgetGuards();
            actingAsRole($kid);
            $res = postJson('/api/child/pet/take-out')->assertStatus($status);
            expect($res->json('state.behaviour.take_out') === null)->toBe($status === 422, $label);
            if ($status === 422) {
                expect($res->json('reason'))->toBe('take_out_not_needed')
                    ->and($res->json('state.behaviour.can_take_out'))->toBeFalse();
            }
        }

        [, $unbornChild] = beFamily('2026-10-12 04:00:00', 2, attributes: ['born_at' => null]);
        app('auth')->forgetGuards();
        actingAsRole($unbornChild);
        postJson('/api/child/pet/take-out')->assertStatus(423)->assertJsonPath('reason', 'contract_required');
        postJson('/api/child/pet/resolve-chewing')->assertStatus(423)->assertJsonPath('reason', 'contract_required');

        [, $child, $pet] = beFamily('2026-10-12 04:00:00', 2);
        $pet->update(['is_hard_stopped' => true]);
        app('auth')->forgetGuards();
        actingAsRole($child);
        postJson('/api/child/pet/take-out')->assertStatus(423)->assertJsonPath('reason', 'hard_stopped');
        expect(ActivityLog::where('pet_id', $pet->id)->where('activity_type', 'took_out_pet')->exists())->toBeFalse();
    });

    it('makes no accident during a hard stop; the clock resumes from the end of the freeze', function () {
        [, , $pet] = beFamily('2026-10-12 11:00:00', 2, quiet: false);  // due 13:00 UTC

        $pet = beRun($pet, '2026-10-12 11:00:00', '2026-10-12 12:30:00');
        Pet::find($pet->id)->update(['is_hard_stopped' => true]);
        $pet = beRun($pet, '2026-10-12 12:30:00', '2026-10-12 14:00:00');
        Pet::find($pet->id)->update(['is_hard_stopped' => false]);
        $pet = beRun($pet, '2026-10-12 14:00:00', '2026-10-12 15:55:00');

        expect(beEvents($pet, HygieneEventKind::Accident))->toHaveCount(0)
            ->and($pet->displayMetric('hygiene_level'))->toBe(100);
        // Clock held at the last frozen tick (14:00) → due 16:00.
        $pet = beRun($pet, '2026-10-12 15:55:00', '2026-10-12 16:05:00');
        expect(beTimes($pet, HygieneEventKind::Accident))->toBe(['2026-10-12 16:00:00']);
    });

    it('makes no accident during an illness; after the vet the clock starts at the recovery', function () {
        [, , $pet] = beFamily('2026-10-12 04:00:00', 2, quiet: false);  // due 06:00 UTC

        $pet = beRun($pet, '2026-10-12 04:00:00', '2026-10-12 05:00:00');
        Pet::find($pet->id)->update(['illness_until' => Carbon::parse('2026-10-12 08:00:00', 'UTC')]);
        $pet = beRun($pet, '2026-10-12 05:00:00', '2026-10-12 09:55:00');
        expect(beEvents($pet, HygieneEventKind::Accident))->toHaveCount(0);

        // Recovery at 08:00 (fresh start), the frozen ticks held the clock until then → due 10:00.
        $pet = beRun($pet, '2026-10-12 09:55:00', '2026-10-12 10:05:00');
        expect(beTimes($pet, HygieneEventKind::Accident))->toBe(['2026-10-12 10:00:00']);
    });

    it('makes no accident after game over; a re-activated pet starts a new clock', function () {
        [, , $pet] = beFamily('2026-10-12 04:00:00', 2, quiet: false);

        $pet = beRun($pet, '2026-10-12 04:00:00', '2026-10-12 05:00:00');
        Pet::find($pet->id)->update(['is_game_over' => true, 'is_active' => false]);
        for ($t = Carbon::parse('2026-10-12 05:10:00', 'UTC'); $t->lessThanOrEqualTo(Carbon::parse('2026-10-12 11:00:00', 'UTC')); $t->addMinutes(10)) {
            beAt($t->toDateTimeString());
            app(PetDecayService::class)->processAllActivePets();   // game-over pets are not ticked
        }
        expect(beEvents($pet, HygieneEventKind::Accident))->toHaveCount(0);

        beAt('2026-10-12 11:00:00');
        Pet::find($pet->id)->update(['is_game_over' => false, 'is_active' => true]);   // decay clock restarts now
        $pet = beRun($pet, '2026-10-12 11:00:00', '2026-10-12 13:05:00');
        expect(beTimes($pet, HygieneEventKind::Accident))->toBe(['2026-10-12 13:00:00']);
    });
});

// ──────────────────────────────────────────────────────────────
//  Chewing
// ──────────────────────────────────────────────────────────────

describe('chewing', function () {
    it('draws the teething chance from a seeded RNG: deterministic per salt, about the configured rate', function () {
        [, , $pet] = beFamily('2026-10-12 04:00:00', 3);
        $a = useBehaviourSalt('salt-a');
        $hits = 0;
        $first = [];
        for ($d = 0; $d < 600; $d++) {
            $date = Carbon::parse('2027-01-01')->addDays($d)->toDateString();
            $roll = $a->randomizerFor($pet, $date)->nextFloat();
            $hits += $roll < 0.5 ? 1 : 0;
            if ($d < 30) {
                $first[] = $roll;
            }
        }
        expect($hits)->toBeGreaterThan(240)->toBeLessThan(360);

        $again = array_map(fn (int $d) => useBehaviourSalt('salt-a')->randomizerFor($pet, Carbon::parse('2027-01-01')->addDays($d)->toDateString())->nextFloat(), range(0, 29));
        $other = array_map(fn (int $d) => useBehaviourSalt('salt-b')->randomizerFor($pet, Carbon::parse('2027-01-01')->addDays($d)->toDateString())->nextFloat(), range(0, 29));
        expect($again)->toBe($first)->and($other)->not->toBe($first);
    });

    it('stores the teething chance as an unverified proposal (0.5) and gates on teething months 3–6', function () {
        $row = BreedStageParam::where('breed_slug', 'mutt')->where('key', StageParamKey::ChewingChancePerDay->value)->sole();
        expect($row->value)->toBe(0.5)->and($row->verified)->toBeFalse();

        [, , $two] = beFamily('2026-10-12 04:00:00', 2);
        [, , $three] = beFamily('2026-10-12 04:00:00', 3);
        [, , $five] = beFamily('2026-10-12 04:00:00', 5);
        $behaviour = app(BehaviourEventService::class);
        expect($behaviour->isTeethingOn($two, '2026-10-12'))->toBeFalse()
            ->and($behaviour->isTeethingOn($three, '2026-10-12'))->toBeTrue()
            ->and($behaviour->isTeethingOn($five, '2026-10-12'))->toBeTrue()
            ->and($behaviour->isTeethingOn($five, '2026-10-20'))->toBeFalse()   // 6 months from the day after the birthday
            ->and($behaviour->teethingChanceOn($three, '2026-10-12'))->toBe(0.5);
    });

    it('chews at most once per teething day at a random minute outside quiet hours (chance 1), never when not teething', function () {
        beChance(1.0);
        [, , $teething] = beFamily('2026-10-12 02:00:00', 3);
        [, , $baby] = beFamily('2026-10-12 02:00:00', 2);

        // Hourly ticks (each one an "outage" for the bladder clock, which is parked anyway);
        // chewing is still decided once per day and applied at its time.
        for ($t = Carbon::parse('2026-10-12 02:30:00', 'UTC'); $t->lessThanOrEqualTo(Carbon::parse('2026-10-14 21:30:00', 'UTC')); $t->addHour()) {
            beAt($t->toDateTimeString());
            foreach ([$teething, $baby] as $pet) {
                app(PetDecayService::class)->processPetDecay($pet);
                // Keep the bladder clock out of the way.
                Pet::whereKey($pet->id)->update(['potty_clock_started_at' => '2999-12-31 00:00:00']);
            }
        }

        $chewing = beEvents($teething, HygieneEventKind::Chewing);
        expect($chewing->map(fn ($e) => $e->local_date->toDateString())->all())->toBe(['2026-10-12', '2026-10-13', '2026-10-14']);
        foreach ($chewing as $e) {
            expect($teething->quietHours()->isQuietNow($e->scheduled_at))->toBeFalse();
        }
        // The 2-month puppy is not teething: nothing on its birth day or the next day.
        // (On the 14th it chews because nobody walked it on the 13th — the walk rule.)
        expect(beEvents($baby, HygieneEventKind::Chewing)->map(fn ($e) => $e->local_date->toDateString())->all())->toBe(['2026-10-14'])
            ->and(app(BehaviourEventService::class)->chewingReasonOn($baby->fresh(), '2026-10-14', now()))->toBe('walk_missed')
            ->and(ActivityLog::where('pet_id', $teething->id)->where('activity_type', 'pet_chewed')->count())
            ->toBe($chewing->where('status', HygieneEventStatus::Applied)->count());
    });

    it('never back-schedules days after an outage, and does not make up today\'s past event', function () {
        beChance(1.0);
        [, , $pet] = beFamily('2026-10-12 02:00:00', 3, quiet: false);
        Pet::whereKey($pet->id)->update(['behaviour_scheduled_through' => '2026-10-12', 'potty_clock_started_at' => '2999-12-31 00:00:00']);

        useBehaviourSalt('outage-salt');
        Log::spy();
        Cache::forget('behaviour-events:outage-warning');

        // Down from the 12th until 23:59:30 local on the 15th (no quiet hours): every
        // whole minute of the day — so today's chewing time — has already passed.
        $pet = beTick($pet->fresh(), '2026-10-15 21:59:30');

        $event = beEvents($pet, HygieneEventKind::Chewing)->sole();
        expect($event->local_date->toDateString())->toBe('2026-10-15')
            ->and($pet->behaviour_scheduled_through)->toBe('2026-10-15')
            ->and($event->status)->toBe(HygieneEventStatus::Skipped)
            ->and($pet->displayMetric('hygiene_level'))->toBe(100);
        // One rate-limited warning for the whole outage.
        Log::shouldHaveReceived('warning')->with('BehaviourEventService: scheduler gap, behaviour events not made up', Mockery::any())->once();
    });

    it('skips an already-pending chewing event after an outage instead of applying it with a passed deadline', function () {
        [, , $pet] = beFamily('2026-10-12 02:00:00', 36, quiet: false);
        $event = PetHygieneEvent::create([
            'pet_id' => $pet->id, 'kind' => 'chewing', 'local_date' => '2026-10-12',
            'scheduled_at' => Carbon::parse('2026-10-12 08:00:00', 'UTC'), 'status' => HygieneEventStatus::Pending,
        ]);
        Pet::whereKey($pet->id)->update(['behaviour_scheduled_through' => '2026-10-12', 'last_decay_at' => '2026-10-12 07:00:00']);

        // Down 07:00 → 11:00: the event (08:00) and its 2-hour deadline passed unseen.
        $pet = beTick($pet->fresh(), '2026-10-12 11:00:00');

        expect($event->fresh()->status)->toBe(HygieneEventStatus::Skipped)
            ->and($pet->displayMetric('hygiene_level'))->toBe(100)
            ->and(ActivityLog::where('pet_id', $pet->id)->where('activity_type', 'pet_chewed')->exists())->toBeFalse()
            ->and(collect(beCleanRoutines($pet, '2026-10-12')))->toHaveCount(0);

        // Without an outage the same pending event happens normally.
        [, , $other] = beFamily('2026-10-12 02:00:00', 36, quiet: false);
        $ok = PetHygieneEvent::create([
            'pet_id' => $other->id, 'kind' => 'chewing', 'local_date' => '2026-10-12',
            'scheduled_at' => Carbon::parse('2026-10-12 08:00:00', 'UTC'), 'status' => HygieneEventStatus::Pending,
        ]);
        Pet::whereKey($other->id)->update(['behaviour_scheduled_through' => '2026-10-12', 'last_decay_at' => '2026-10-12 07:58:00']);
        $other = beTick($other->fresh(), '2026-10-12 08:01:00');
        expect($ok->fresh()->status)->toBe(HygieneEventStatus::Applied)
            ->and($other->displayMetric('hygiene_level'))->toBe(0);
    });

    it('chews once the day after a missed walk goal (any dog with behaviour events), not after a reached goal', function () {
        beChance(0.0);
        [, , $lazy] = beFamily('2026-10-10 10:00:00', 36);   // adult mutt, goal 6,000
        [, , $walked] = beFamily('2026-10-10 10:00:00', 36);

        // 12 Oct: 1,000 steps vs 6,000 steps.
        beAt('2026-10-12 21:58:00');
        foreach ([[$lazy, 1000], [$walked, 6000]] as [$pet, $steps]) {
            Pet::whereKey($pet->id)->update([
                'daily_step_count' => $steps, 'energy_level' => min(100, $steps / 60),
                'last_step_reset_at' => Carbon::parse('2026-10-11 22:00:00', 'UTC'),
                'last_decay_at' => Carbon::parse('2026-10-12 21:58:00', 'UTC'),
                'behaviour_scheduled_through' => '2026-10-12',
            ]);
        }

        // Local midnight 13 Oct: the day closes, the chewing of the 13th is decided.
        beAt('2026-10-12 22:01:00');
        foreach ([$lazy, $walked] as $pet) {
            app(PetDecayService::class)->processPetDecay($pet);
        }

        $event = beEvents($lazy, HygieneEventKind::Chewing)->sole();
        expect($event->local_date->toDateString())->toBe('2026-10-13')
            ->and($event->status)->toBe(HygieneEventStatus::Pending)
            ->and($lazy->quietHours()->isQuietNow($event->scheduled_at))->toBeFalse()
            ->and(app(BehaviourEventService::class)->chewingReasonOn($lazy->fresh(), '2026-10-13', now()))->toBe('walk_missed')
            ->and(beEvents($walked, HygieneEventKind::Chewing))->toHaveCount(0);

        // It happens at its time: hygiene 0, timeline row.
        $lazy = beRun($lazy, '2026-10-12 22:01:00', $event->scheduled_at->copy()->addMinute()->toDateTimeString());
        expect($lazy->displayMetric('hygiene_level'))->toBe(0)
            ->and($event->fresh()->status)->toBe(HygieneEventStatus::Applied)
            ->and(ActivityLog::where('pet_id', $lazy->id)->where('activity_type', 'pet_chewed')->count())->toBe(1);
    });

    it('does not chew for a legacy dog after a missed walk', function () {
        [, , $legacy] = beFamily('2026-10-10 10:00:00', 36, attributes: ['arrival_age_months' => null]);
        beAt('2026-10-12 21:58:00');
        Pet::whereKey($legacy->id)->update([
            'daily_step_count' => 100, 'last_step_reset_at' => Carbon::parse('2026-10-11 22:00:00', 'UTC'),
            'last_decay_at' => Carbon::parse('2026-10-12 21:58:00', 'UTC'),
        ]);
        beTick($legacy, '2026-10-12 22:01:00');

        expect(beEvents($legacy, HygieneEventKind::Chewing))->toHaveCount(0);
    });
});

// ──────────────────────────────────────────────────────────────
//  Resolving + scoring
// ──────────────────────────────────────────────────────────────

describe('resolve flow and routines', function () {
    it('tidies up chewing with resolve-chewing: hygiene back, clean routine of kind chewing done by the child within 2 h', function () {
        [, $child, $pet] = beFamily('2026-10-12 04:00:00', 36);
        bePlant($pet, HygieneEventKind::Chewing, '2026-10-12 12:00:00');   // 14:00 local
        Pet::whereKey($pet->id)->update(['hygiene_level' => 0, 'hygiene_zero_since' => '2026-10-12 12:00:00', 'last_decay_at' => '2026-10-12 12:00:00']);
        actingAsRole($child);

        beAt('2026-10-12 12:30:00');
        getJson('/api/child/pet')->assertOk()
            ->assertJsonPath('behaviour.scene', 'chewing')
            ->assertJsonPath('behaviour.active_events.0.kind', 'chewing')
            ->assertJsonPath('behaviour.active_events.0.started_at', '2026-10-12T14:00:00+02:00')
            ->assertJsonPath('behaviour.active_events.0.due_at', '2026-10-12T16:00:00+02:00')
            ->assertJsonPath('behaviour.can_resolve_chewing', true)
            ->assertJsonPath('feeding.can_feed', false);

        postJson('/api/child/pet/feed')->assertStatus(422)->assertJsonPath('reason', 'needs_cleaning');

        beAt('2026-10-12 13:00:00');
        postJson('/api/child/pet/resolve-chewing')->assertOk()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('state.pet.hygiene_level', 100)
            ->assertJsonPath('state.behaviour.active_events', [])
            ->assertJsonPath('state.behaviour.scene', null);
        postJson('/api/child/pet/resolve-chewing')->assertOk()->assertJsonPath('status', 'unchanged');

        $routine = collect(beCleanRoutines($pet, '2026-10-12'))->sole();
        expect($routine->eventKind)->toBe(HygieneEventKind::Chewing)
            ->and($routine->status)->toBe(RoutineStatus::Done)
            ->and($routine->actorUserId)->toBe($child->id)
            ->and(ActivityLog::where('pet_id', $pet->id)->where('activity_type', 'resolved_chewing')->sole()->actor_user_id)->toBe($child->id);
    });

    it('answers unchanged to a clean while only a chewing event is open (no cleaned_poop row, hygiene stays 0)', function () {
        [, $child, $pet] = beFamily('2026-10-12 04:00:00', 36);
        $event = bePlant($pet, HygieneEventKind::Chewing, '2026-10-12 12:00:00');
        Pet::whereKey($pet->id)->update(['hygiene_level' => 0, 'hygiene_zero_since' => '2026-10-12 12:00:00', 'last_decay_at' => '2026-10-12 12:00:00']);
        actingAsRole($child);

        beAt('2026-10-12 12:03:00');
        postJson('/api/child/pet/clean')->assertOk()
            ->assertJsonPath('status', 'unchanged')
            ->assertJsonPath('state.pet.hygiene_level', 0)
            ->assertJsonPath('state.behaviour.active_events.0.kind', 'chewing');
        expect($event->fresh()->cleaned_at)->toBeNull()
            ->and(ActivityLog::where('pet_id', $pet->id)->where('activity_type', 'cleaned_poop')->exists())->toBeFalse();
    });

    it('counts an accident like a poop: cleaned in 2 h outside quiet hours = done; too late = missed; closed with its kind', function () {
        [, $child, $pet] = beFamily('2026-10-12 04:00:00', 2);
        $pet = beRun($pet, '2026-10-12 04:00:00', '2026-10-12 11:01:00');   // accident at 11:00 UTC (13:00 local)
        expect(beEvents($pet, HygieneEventKind::Accident))->toHaveCount(1);

        // 2 h outside quiet hours → due 13:00 UTC (15:00 local).
        $routine = collect(beCleanRoutines($pet, '2026-10-12'))->sole();
        expect($routine->dueAt->toDateTimeString())->toBe('2026-10-12 13:00:00')
            ->and($routine->status)->toBe(RoutineStatus::Pending)
            ->and($routine->eventKind)->toBe(HygieneEventKind::Accident);

        $pet = beRun($pet, '2026-10-12 11:01:00', '2026-10-12 12:38:00');
        actingAsRole($child);
        beAt('2026-10-12 12:40:00');
        postJson('/api/child/pet/clean')->assertOk()->assertJsonPath('status', 'accepted')
            ->assertJsonPath('state.pet.hygiene_level', 100);
        $done = collect(beCleanRoutines($pet, '2026-10-12'))->first(fn (Routine $r) => $r->eventKind === HygieneEventKind::Accident);
        expect($done->status)->toBe(RoutineStatus::Done)->and($done->actorUserId)->toBe($child->id);

        // Next accident 2 h after the first (13:00 UTC); nobody cleans → missed after 15:00 UTC.
        $pet = beRun($pet, '2026-10-12 12:40:00', '2026-10-12 13:05:00');
        beAt('2026-10-12 15:30:00');
        expect(collect(beCleanRoutines($pet, '2026-10-12'))->pluck('status')->all())->toContain(RoutineStatus::Missed);

        // Closed days keep the kind.
        beAt('2026-10-14 03:00:00');
        app(RoutineLedgerService::class)->closePet($pet->id);
        expect(PetDailyRoutine::where('pet_id', $pet->id)->where('routine_type', 'clean')->pluck('event_kind')->map->value->unique()->all())
            ->toBe(['accident']);
        // Missed items carry the kind for the parent.
        $board = app(CareScoreService::class)->board(Pet::whereKey($pet->id)->get(), 'Europe/Ljubljana');
        $missed = collect($board['routines'][$pet->id])->first(fn (Routine $r) => $r->isMissed() && $r->type === RoutineType::Clean);
        expect(app(CareScoreService::class)->missedItem($board, $missed)['kind'])->toBe('accident');
    });

    it('keeps hygiene at 0 while another kind is still open (accident cleaned, chewing open)', function () {
        [, $child, $pet] = beFamily('2026-10-12 04:00:00', 3);
        bePlant($pet, HygieneEventKind::Accident, '2026-10-12 12:00:00');
        bePlant($pet, HygieneEventKind::Chewing, '2026-10-12 12:10:00');
        Pet::whereKey($pet->id)->update(['hygiene_level' => 0, 'hygiene_zero_since' => '2026-10-12 12:00:00', 'last_decay_at' => '2026-10-12 12:18:00', 'potty_clock_started_at' => '2026-10-12 12:00:00']);
        actingAsRole($child);

        beAt('2026-10-12 12:20:00');
        postJson('/api/child/pet/clean')->assertOk()->assertJsonPath('status', 'accepted')
            ->assertJsonPath('state.pet.hygiene_level', 0)
            ->assertJsonPath('state.behaviour.active_events.0.kind', 'chewing');
        postJson('/api/child/pet/resolve-chewing')->assertOk()->assertJsonPath('state.pet.hygiene_level', 100);
        expect(Pet::find($pet->id)->hygiene_zero_since)->toBeNull();
    });

    it('lets the vet close open messes at the end of an illness (no stale active events on a clean dog)', function () {
        [, , $pet] = beFamily('2026-10-12 04:00:00', 36);
        $event = bePlant($pet, HygieneEventKind::Chewing, '2026-10-12 05:00:00');
        Pet::whereKey($pet->id)->update(['hygiene_level' => 0, 'illness_until' => '2026-10-12 20:00:00', 'last_decay_at' => '2026-10-12 06:00:00']);

        $pet = beTick($pet, '2026-10-12 20:05:00');
        expect($pet->displayMetric('hygiene_level'))->toBe(100)
            ->and($event->fresh()->cleaned_at->toDateTimeString())->toBe('2026-10-12 20:00:00')
            ->and(BehaviourPayload::for($pet)->activeEvents)->toBe([]);
    });
});

// ──────────────────────────────────────────────────────────────
//  API contract, authz, broadcasts, parent dashboard
// ──────────────────────────────────────────────────────────────

describe('API', function () {
    it('answers 403 to a parent token and never touches another family\'s pet', function () {
        [$parent, , $pet] = beFamily('2026-10-12 04:00:00', 2);
        [, $otherChild, $otherPet] = beFamily('2026-10-12 04:00:00', 2);
        $before = $pet->fresh()->potty_clock_started_at;

        actingAsRole($parent);
        postJson('/api/child/pet/take-out')->assertStatus(403);
        postJson('/api/child/pet/resolve-chewing')->assertStatus(403);

        app('auth')->forgetGuards();
        actingAsRole($otherChild);
        beAt('2026-10-12 04:02:00');
        postJson('/api/child/pet/take-out')->assertOk()->assertJsonPath('state.pet.id', $otherPet->id);
        expect($pet->fresh()->potty_clock_started_at?->toDateTimeString())->toBe($before?->toDateTimeString())
            ->and(ActivityLog::where('pet_id', $pet->id)->where('activity_type', 'took_out_pet')->exists())->toBeFalse();
    });

    it('broadcasts once per take-out / resolve and carries the behaviour summary', function () {
        Event::fake([PetUpdated::class]);
        [, $child, $pet] = beFamily('2026-10-12 04:00:00', 3);
        // Teething chewing off (a draw landing in 04:00–04:02 would add an open mess).
        Pet::whereKey($pet->id)->update(['behaviour_scheduled_through' => '2999-12-31']);
        actingAsRole($child);

        beAt('2026-10-12 04:02:00');
        postJson('/api/child/pet/take-out')->assertOk();
        Event::assertDispatchedTimes(PetUpdated::class, 1);
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->petId === $pet->id && $e->eventType === 'took_out_pet');

        $payload = PetUpdated::payloadFor($pet->fresh(), 'took_out_pet');
        expect($payload['behaviour']['take_out']['hold_hours'])->toBe(3)
            ->and($payload['behaviour']['active_events'])->toBe([])
            ->and(json_encode($payload))->not->toContain('Maja');
    });

    it('shows behaviour, take-outs and behaviour events to the parent', function () {
        [$parent, $child, $pet] = beFamily('2026-10-12 04:00:00', 2, quiet: false);
        $pet = beRun($pet, '2026-10-12 04:00:00', '2026-10-12 04:30:00');
        actingAsRole($child);
        postJson('/api/child/pet/take-out')->assertOk();
        beRun($pet, '2026-10-12 04:30:00', '2026-10-12 06:31:00');            // accident at 06:30

        app('auth')->forgetGuards();
        actingAsRole($parent);
        $res = getJson('/api/parent/dashboard')->assertOk();
        $petJson = collect($res->json('family.pets'))->firstWhere('id', $pet->id);
        expect($petJson['behaviour']['scene'])->toBe('accident')
            ->and($petJson['behaviour']['take_out']['hold_hours'])->toBe(2)
            ->and($petJson['profile']['behaviour_enabled'])->toBeTrue()
            ->and(collect($petJson['timeline'])->pluck('activity_type')->all())->toContain('took_out_pet', 'pet_accident')
            ->and(collect($petJson['timeline'])->firstWhere('activity_type', 'pet_accident')['is_positive'])->toBeFalse()
            ->and(collect($res->json('family.children'))->firstWhere('id', $child->id)['stats']['taken_out'])->toBe(1);
    });

    it('keeps the free mutt payload unchanged: media states idle + sleeping, pet_state one of the six', function () {
        // M3-11: the basic media set follows the free plan, not the breed.
        [, $child, $pet] = beFamily('2026-10-12 04:00:00', 2, attributes: ['plan' => 'free', 'challenge_paid_at' => null, 'challenge_paid_source' => null]);
        actingAsRole($child);

        getJson('/api/child/pet')->assertOk()
            ->assertJsonPath('pet.media.states', ['idle', 'sleeping'])
            ->assertJsonPath('pet.pet_state', 'idle')
            ->assertJsonPath('behaviour.take_out.hold_hours', 2)
            ->assertJsonPath('behaviour.active_events', [])
            ->assertJsonPath('behaviour.scene', null);
    });
});

// ──────────────────────────────────────────────────────────────
//  Premium behaviour videos
// ──────────────────────────────────────────────────────────────

describe('behaviour videos', function () {
    it('gives the premium set accident + chewing where the event can happen; free, legacy and old-client sets unchanged', function () {
        $entitlements = app(MediaEntitlementService::class);
        $states = fn (Pet $p) => array_map(fn (PetStateEnum $s) => $s->value, $entitlements->videoStatesFor($p));

        [, , $bcPuppy] = beFamily('2026-10-12 04:00:00', 2, attributes: ['breed_type' => 'border_collie', 'challenge_paid_source' => 'purchase', 'life_stage' => 'puppy']);
        [, , $bcYoung] = beFamily('2026-10-12 04:00:00', 9, attributes: ['breed_type' => 'border_collie', 'challenge_paid_source' => 'purchase', 'life_stage' => 'young']);
        [, , $bcLegacy] = beFamily('2026-10-12 04:00:00', 2, attributes: ['breed_type' => 'border_collie', 'challenge_paid_source' => 'purchase', 'arrival_age_months' => null]);
        [, , $bcOldClient] = beFamily('2026-10-12 04:00:00', 2, attributes: ['breed_type' => 'border_collie', 'challenge_paid_source' => 'purchase', 'life_stage' => 'puppy', 'behaviour_events_enabled' => false]);
        [, , $mutt] = beFamily('2026-10-12 04:00:00', 2, attributes: ['life_stage' => 'puppy', 'plan' => 'free', 'challenge_paid_at' => null, 'challenge_paid_source' => null]);
        // M3-11 P6: the full set needs a purchase — a purchased mutt gets it; a
        // trial or grandfathered Border Collie gets the basic set.
        [, , $paidMutt] = beFamily('2026-10-12 04:00:00', 2, attributes: ['life_stage' => 'puppy', 'challenge_paid_source' => 'purchase']);
        [, , $trialBc] = beFamily('2026-10-12 04:00:00', 2, attributes: ['breed_type' => 'border_collie', 'life_stage' => 'puppy', 'challenge_paid_at' => null, 'challenge_paid_source' => null]);
        [, , $oldBc] = beFamily('2026-10-12 04:00:00', 2, attributes: ['breed_type' => 'border_collie', 'life_stage' => 'puppy']);

        $six = ['idle', 'sleeping', 'low_energy', 'hungry', 'sick', 'playing'];
        expect($states($bcPuppy))->toBe([...$six, 'accident', 'chewing'])
            ->and($states($bcYoung))->toBe([...$six, 'chewing'])
            ->and($states($bcLegacy))->toBe($six)
            ->and($states($bcOldClient))->toBe($six)
            ->and($states($mutt))->toBe(['idle', 'sleeping'])
            ->and($states($paidMutt))->toBe([...$six, 'accident', 'chewing'])
            ->and($states($trialBc))->toBe(['idle', 'sleeping'])
            ->and($states($oldBc))->toBe(['idle', 'sleeping']);
    });

    it('plans the two new videos from the current stage image and stops serving the puppy accident video after puppy → young', function () {
        [, , $bc] = beFamily('2026-10-12 04:00:00', 2, attributes: ['breed_type' => 'border_collie', 'challenge_paid_source' => 'purchase', 'life_stage' => 'puppy']);
        PetMedia::create(['pet_id' => $bc->id, 'kind' => 'image', 'status' => 'ready', 'generation' => 1, 'life_stage' => 'puppy', 'storage_path' => $bc->id.'/reference-g1.jpg']);
        foreach (['idle', 'sleeping', 'low_energy', 'hungry', 'sick', 'playing'] as $state) {
            PetMedia::create(['pet_id' => $bc->id, 'kind' => 'video', 'state' => $state, 'status' => 'ready', 'generation' => 1, 'source_generation' => 1, 'storage_path' => "{$bc->id}/{$state}-g1.mp4"]);
        }

        expect(app(PetMediaService::class)->planMissing($bc->fresh())['videos'])->toBe(['accident', 'chewing']);

        foreach (['accident', 'chewing'] as $state) {
            PetMedia::create(['pet_id' => $bc->id, 'kind' => 'video', 'state' => $state, 'status' => 'ready', 'generation' => 1, 'source_generation' => 1, 'storage_path' => "{$bc->id}/{$state}-g1.mp4"]);
        }
        expect(array_keys(app(PetMediaService::class)->mediaFor($bc->fresh())->videos))->toContain('accident', 'chewing');

        Pet::whereKey($bc->id)->update(['life_stage' => 'young']);
        $videos = array_keys(app(PetMediaService::class)->mediaFor($bc->fresh())->videos);
        expect($videos)->toContain('chewing')->not->toContain('accident');

        // The two behaviour prompts describe the dog only — no people, no names.
        foreach ([PetStateEnum::Accident, PetStateEnum::Chewing] as $state) {
            $prompt = app(PetAppearancePrompt::class)->videoPrompt('border_collie', $state);
            expect($prompt)->toContain($state->promptModifier())->not->toContain('Maja');
        }
    });

    it('accepts the new states only for video slots: pets.pet_state keeps the six classic states', function () {
        [, , $pet] = beFamily('2026-10-12 04:00:00', 2);
        PetMedia::create(['pet_id' => $pet->id, 'kind' => 'video', 'state' => 'chewing', 'status' => 'pending', 'generation' => 1]);
        expect(PetMedia::where('pet_id', $pet->id)->where('state', 'chewing')->exists())->toBeTrue();

        expect(fn () => DB::table('pets')->where('id', $pet->id)->update(['pet_state' => 'accident']))->toThrow(QueryException::class);
        expect(fn () => DB::table('pet_hygiene_events')->insert([
            'pet_id' => $pet->id, 'kind' => 'barking', 'local_date' => '2026-10-12',
            'scheduled_at' => now(), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });
});
