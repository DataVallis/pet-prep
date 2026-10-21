<?php

use App\Enums\BreedType;
use App\Enums\RoutineType;
use App\Models\ActivityLog;
use App\Models\Pet;
use App\Models\User;
use App\Services\CatChoreService;
use App\Services\RoutineLedgerService;
use App\Support\ClockText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| M5-T02b — "spečo žival ne motimo" (David 2026-10-21)
|--------------------------------------------------------------------------
| While the pet sleeps (PetSleepService — the family's quiet hours, night
| and the optional school window, read on the family wall clock) brushing
| (dog + cat) and cuddling are refused like play and school: grooming →
| 422 grooming_quiet_hours, play / cuddle → 422 play_pet_sleeping, both with
| next_allowed_at = the wake time; the state's can_* flags are false. A
| grooming slot the quiet hours leave no day for is never expected (never
| missed / matted only because the pet slept). Clock texts read "7:00".
| Europe/Ljubljana: UTC+2 until 25 Oct 2026 03:00, then UTC+1.
*/

afterEach(function () {
    Carbon::setTestNow();
});

function sgAt(string $local, string $tz = 'Europe/Ljubljana'): void
{
    Carbon::setTestNow(Carbon::parse($local, $tz)->utc());
}

/**
 * A family with a profiled, paid pet of $breed (dogs brush with grooming_enabled).
 *
 * @param  array<string, mixed>  $quiet
 * @return array{0: User, 1: User, 2: Pet}
 */
function sgFamily(BreedType $breed = BreedType::PembrokeWelshCorgi, string $tz = 'Europe/Ljubljana', array $quiet = ['bedtime_start' => '21:00', 'bedtime_end' => '07:00'], string $bornLocal = '2026-10-20 08:00'): array
{
    seedLifeStageData();
    sgAt($bornLocal, $tz);
    $parent = User::factory()->parent()->create(['timezone' => $tz]);
    $child = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => 'Mia']);
    setQuietHours(array_merge(['parent_id' => $parent->id], $quiet));
    $pet = disableHygieneEvents(Pet::factory()->create([
        'breed_type' => $breed->value,
        'user_id' => $child->id,
        'born_at' => now(),
        'last_decay_at' => now(),
        'last_step_reset_at' => now(),
        'arrival_age_months' => 12,
        'origin' => 'adopted',
        'grooming_enabled' => true,
        'plan' => 'challenge',
        'challenge_paid_at' => now(),
        'challenge_paid_source' => 'purchase',
    ]));
    Pet::whereKey($pet->id)->update(['play_scheduled_through' => '2999-12-31']);

    return [$parent, $child, $pet->fresh()];
}

function sgPost(User $child, string $uri, array $body = [])
{
    test()->actingAs($child, 'sanctum');
    $response = test()->postJson($uri, $body);
    app('auth')->forgetGuards();

    return $response;
}

function sgState(User $child): array
{
    test()->actingAs($child, 'sanctum');
    $json = test()->getJson('/api/child/pet')->assertOk()->json();
    app('auth')->forgetGuards();

    return $json;
}

function sgTick(string $local): void
{
    sgAt($local);
    DB::table('pets')->update(['hunger_level' => 100, 'thirst_level' => 100, 'hunger_zero_since' => null, 'thirst_zero_since' => null, 'walk_illness_due_at' => null]);
    Artisan::call('pets:process-decay');
}

describe('brushing while the pet sleeps (dog + cat)', function () {
    it('refuses a dog\'s brushing at night: 422 grooming_quiet_hours, wake time, can_start false', function () {
        [, $child] = sgFamily();
        sgAt('2026-10-21 22:00');

        expect(sgState($child)['grooming'])->toMatchArray([
            'can_start' => false, 'blocked_reason' => 'grooming_quiet_hours', 'next_allowed_at' => '2026-10-22T07:00:00+02:00',
        ])->and(sgState($child)['sleep'])->toBe(['asleep' => true, 'until' => '2026-10-22T07:00:00+02:00']);
        sgPost($child, '/api/child/pet/grooming/start')->assertStatus(422)
            ->assertJsonPath('reason', 'grooming_quiet_hours')
            ->assertJsonPath('next_allowed_at', '2026-10-22T07:00:00+02:00');

        sgAt('2026-10-22 07:00');
        sgPost($child, '/api/child/pet/grooming/start')->assertOk()->assertJsonPath('session.kind', 'grooming');
    });

    it('a brushed cat sleeps the same way (Maine Coon)', function () {
        [, $child] = sgFamily(BreedType::MaineCoon);
        sgAt('2026-10-21 06:59');
        sgPost($child, '/api/child/pet/grooming/start')->assertStatus(422)
            ->assertJsonPath('reason', 'grooming_quiet_hours')
            ->assertJsonPath('next_allowed_at', '2026-10-21T07:00:00+02:00');
        expect(sgState($child)['grooming']['can_start'])->toBeFalse();
    });

    it('sleep is the reason before "done today" — after midnight too (family-local day)', function () {
        [, $child, $dog] = sgFamily();
        sgAt('2026-10-21 18:00');
        $session = sgPost($child, '/api/child/pet/grooming/start')->assertOk()->json('session');
        Carbon::setTestNow(now()->addMilliseconds($session['duration_ms']));
        $strokes = [];
        for ($i = 0; $i < $session['min_strokes'] + 6; $i++) {
            $strokes[] = ['t' => min($session['duration_ms'], 300 + intdiv($i * ($session['duration_ms'] - 600), $session['min_strokes'] + 5) + ($i * 137) % 250)];
        }
        sgPost($child, '/api/child/pet/grooming/finish', ['session_id' => $session['id'], 'strokes' => $strokes])
            ->assertOk()->assertJsonPath('status', 'accepted');
        expect(ActivityLog::where('pet_id', $dog->id)->where('activity_type', 'groomed_pet')->count())->toBe(1);

        sgAt('2026-10-21 23:30'); // already brushed today AND asleep → the sleep explains it
        expect(sgState($child)['grooming'])->toMatchArray(['blocked_reason' => 'grooming_quiet_hours', 'next_allowed_at' => '2026-10-22T07:00:00+02:00']);

        sgAt('2026-10-22 00:30'); // a new family-local day, still asleep
        sgPost($child, '/api/child/pet/grooming/start')->assertStatus(422)
            ->assertJsonPath('reason', 'grooming_quiet_hours')
            ->assertJsonPath('next_allowed_at', '2026-10-22T07:00:00+02:00');
    });

    it('reads the family time zone (New York family) and the DST night', function () {
        [, $child] = sgFamily(BreedType::PembrokeWelshCorgi, 'America/New_York', bornLocal: '2026-10-20 08:00');
        sgAt('2026-10-21 22:00', 'America/New_York'); // 02:00 UTC — Ljubljana would be awake
        sgPost($child, '/api/child/pet/grooming/start')->assertStatus(422)
            ->assertJsonPath('reason', 'grooming_quiet_hours')
            ->assertJsonPath('next_allowed_at', '2026-10-22T07:00:00-04:00');

        [, $sl] = sgFamily(BreedType::PembrokeWelshCorgi, bornLocal: '2026-10-22 08:00');
        sgAt('2026-10-25 02:30'); // the night the clocks go back: wakes at 07:00 CET
        sgPost($sl, '/api/child/pet/grooming/start')->assertStatus(422)
            ->assertJsonPath('next_allowed_at', '2026-10-25T07:00:00+01:00');
    });

    it('the school window counts as sleep too (David 2026-10-21 keeps the rule): wakes at its end', function () {
        [, $child] = sgFamily(quiet: ['school_start' => '08:00', 'school_end' => '14:00', 'bedtime_start' => '21:00', 'bedtime_end' => '07:00']);
        sgAt('2026-10-21 10:00');
        sgPost($child, '/api/child/pet/grooming/start')->assertStatus(422)
            ->assertJsonPath('reason', 'grooming_quiet_hours')->assertJsonPath('next_allowed_at', '2026-10-21T14:00:00+02:00');
        sgPost($child, '/api/child/pet/play', ['kind' => 'cuddle'])->assertStatus(422)
            ->assertJsonPath('reason', 'play_pet_sleeping')->assertJsonPath('next_allowed_at', '2026-10-21T14:00:00+02:00');
    });
});

describe('cuddling while the pet sleeps', function () {
    it('refuses a dog\'s cuddle and ball game: 422 play_pet_sleeping, wake time, can_play false; allowed once awake', function () {
        [, $child] = sgFamily(BreedType::BorderCollie);
        sgAt('2026-10-21 21:00');
        expect(sgState($child)['play']['can_play'])->toBeFalse();
        foreach (['cuddle', 'play'] as $kind) {
            sgPost($child, '/api/child/pet/play', ['kind' => $kind])->assertStatus(422)
                ->assertJsonPath('reason', 'play_pet_sleeping')
                ->assertJsonPath('next_allowed_at', '2026-10-22T07:00:00+02:00')
                ->assertJsonPath('state.play.can_play', false);
        }
        expect(ActivityLog::whereIn('activity_type', ['played_with_pet', 'cuddled_pet'])->count())->toBe(0);

        sgAt('2026-10-22 07:00');
        sgPost($child, '/api/child/pet/play', ['kind' => 'cuddle'])->assertOk()->assertJsonPath('play.kind', 'cuddle');
    });

    it('refuses a cat\'s cuddle after midnight; the sleep wins over an open mess', function () {
        [, $child, $cat] = sgFamily(BreedType::MaineCoon);
        sgAt('2026-10-22 00:10');
        Pet::whereKey($cat->id)->update(['hygiene_level' => 0]);
        sgPost($child, '/api/child/pet/play', ['kind' => 'cuddle'])->assertStatus(422)
            ->assertJsonPath('reason', 'play_pet_sleeping')
            ->assertJsonPath('next_allowed_at', '2026-10-22T07:00:00+02:00');

        sgAt('2026-10-22 08:00'); // awake: the mess is the reason now
        sgPost($child, '/api/child/pet/play', ['kind' => 'cuddle'])->assertStatus(422)
            ->assertJsonPath('reason', 'play_not_available')->assertJsonPath('next_allowed_at', null);
    });
});

describe('a grooming is never missed only because the pet slept', function () {
    it('counts the days whose awake time leaves room for one brushing', function () {
        [$parent] = sgFamily();
        $quiet = setQuietHours(['parent_id' => $parent->id, 'bedtime_start' => '21:00', 'bedtime_end' => '07:00']);
        $from = CarbonImmutable::parse('2026-10-20 08:00', 'Europe/Ljubljana');
        $to = $from->addDays(7);
        expect(CatChoreService::feasibleGroomingDays($quiet, $from, $to, 'Europe/Ljubljana', 90))->toBe(7)
            ->and(CatChoreService::feasibleGroomingDays($quiet, $from, $to, 'Europe/Ljubljana', 90, 3))->toBe(3);

        // Night 21–07 + school 07–21 = asleep all day: no day has room.
        $always = setQuietHours(['parent_id' => $parent->id, 'school_start' => '07:00', 'school_end' => '21:00', 'bedtime_start' => '21:00', 'bedtime_end' => '07:00']);
        expect(CatChoreService::feasibleGroomingDays($always, $from, $to, 'Europe/Ljubljana', 90))->toBe(0);

        // 07:00–07:01 awake (60 s < session + grace): no room either; quiet hours off → every day.
        $minute = setQuietHours(['parent_id' => $parent->id, 'bedtime_start' => '07:01', 'bedtime_end' => '07:00']);
        expect(CatChoreService::feasibleGroomingDays($minute, $from, $to, 'Europe/Ljubljana', 90))->toBe(0);
        $off = setQuietHours(['parent_id' => $parent->id, 'is_active' => false]);
        expect(CatChoreService::feasibleGroomingDays($off, $from, $to, 'Europe/Ljubljana', 90))->toBe(7);
    });

    it('a pet that sleeps all day gets no grooming slot: nothing missed, no matted coat', function () {
        [, $child, $dog] = sgFamily(quiet: ['school_start' => '07:00', 'school_end' => '21:00', 'bedtime_start' => '21:00', 'bedtime_end' => '07:00']);
        sgTick('2026-10-20 09:00');
        sgAt('2026-10-21 12:00');
        expect(sgState($child)['grooming'])->toMatchArray(['can_start' => false, 'blocked_reason' => 'grooming_quiet_hours']);

        for ($t = Carbon::parse('2026-10-26 22:00', 'Europe/Ljubljana'); $t->lessThanOrEqualTo(Carbon::parse('2026-10-27 09:00', 'Europe/Ljubljana')); $t->addHour()) {
            sgTick($t->format('Y-m-d H:i'));
        }
        $routines = app(RoutineLedgerService::class)->routinesFor(collect([$dog->fresh()]), '2026-10-20', '2026-10-27')[$dog->id] ?? [];
        expect(array_values(array_filter($routines, fn ($r) => $r->type === RoutineType::Grooming)))->toBe([])
            ->and($dog->fresh()->coat_matted_at)->toBeNull();
    });
});

describe('clock texts read "7:00" (David 2026-10-21)', function () {
    it('drops the leading zero of the hour on the family wall clock', function () {
        expect(ClockText::at(CarbonImmutable::parse('2026-10-22 05:00', 'UTC'), 'Europe/Ljubljana'))->toBe('7:00')
            ->and(ClockText::at(CarbonImmutable::parse('2026-10-22 07:30', 'UTC'), 'Europe/Ljubljana'))->toBe('9:30')
            ->and(ClockText::at(CarbonImmutable::parse('2026-10-22 15:00', 'UTC'), 'Europe/Ljubljana'))->toBe('17:00')
            ->and(ClockText::at(CarbonImmutable::parse('2026-10-21 22:05', 'UTC'), 'Europe/Ljubljana'))->toBe('0:05')
            ->and(ClockText::at(CarbonImmutable::parse('2026-10-22 11:00', 'UTC'), 'America/New_York'))->toBe('7:00');
    });
});
