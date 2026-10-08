<?php

use App\Enums\ActivityType;
use App\Models\ActivityLog;
use App\Models\Pet;
use App\Models\QuietHours;
use App\Models\User;
use App\Services\PetDecayService;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\putJson;

/*
|--------------------------------------------------------------------------
| Family timezone (M1-03)
|--------------------------------------------------------------------------
|
| Wall-clock rules (quiet hours, local midnight, dashboard days) follow the
| parent's IANA timezone; storage stays UTC (APP_TIMEZONE=UTC). All test
| instants below are written in UTC.
|
| Europe/Ljubljana: CEST (UTC+2) until 2026-10-25 03:00 → 02:00 CET (UTC+1);
| CET until 2027-03-28 02:00 → 03:00 CEST.
*/

const TZ_LJUBLJANA = 'Europe/Ljubljana';

/**
 * @return array{0: User, 1: User, 2: Pet}
 */
function tzFamily(array $quietHours = [], string $timezone = TZ_LJUBLJANA, array $pet = []): array
{
    $parent = User::factory()->parent()->create(['timezone' => $timezone]);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);

    if ($quietHours !== []) {
        setQuietHours(array_merge(['parent_id' => $parent->id, 'is_active' => true], $quietHours));
    } else {
        withoutQuietHours($parent); // none = switched off (default night 21–07 since 2026-10-08)
    }

    $pet = Pet::factory()->mutt()->create(array_merge(['user_id' => $child->id], $pet));

    return [$parent, $child, $pet];
}

function tzTick(Pet $pet, string $utc): Pet
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
    app(PetDecayService::class)->processPetDecay($fresh = Pet::findOrFail($pet->id));

    return $fresh->refresh();
}

/**
 * Decay a mutt from $fromUtc to $toUtc in one catch-up tick and return the
 * pet. Hunger: 8 %/h normal, 0.8 %/h quiet (hygiene has no gradual decay since M1-05).
 */
function tzCatchUp(array $quietHours, string $fromUtc, string $toUtc): Pet
{
    Carbon::setTestNow(Carbon::parse($fromUtc, 'UTC'));
    [, , $pet] = tzFamily($quietHours);

    return tzTick($pet, $toUtc);
}

const TZ_BEDTIME = ['bedtime_start' => '22:00', 'bedtime_end' => '06:00'];

beforeEach(function () {
    seedBreedConfigs();
});

describe('Family timezone resolution', function () {
    it('defaults new users to Europe/Ljubljana', function () {
        $user = User::factory()->parent()->create();

        expect($user->fresh()->timezone)->toBe(TZ_LJUBLJANA);
    });

    it('uses the parent timezone for a child and its pet', function () {
        [$parent, $child, $pet] = tzFamily(timezone: 'America/New_York');
        $child->update(['timezone' => 'Asia/Tokyo']);

        expect($parent->familyTimezone())->toBe('America/New_York');
        expect($child->fresh()->familyTimezone())->toBe('America/New_York');
        expect($pet->fresh()->familyTimezone())->toBe('America/New_York');
        // Every family has quiet hours (default 21:00–07:00, 2026-10-08),
        // read in the family timezone.
        expect($pet->fresh()->quietHours()->bedtime_start)->toStartWith(QuietHours::DEFAULTS['bedtime_start'])
            ->and($pet->fresh()->quietHours()->timezone())->toBe('America/New_York');
    });
});

describe('Quiet hours in the family timezone', function () {
    it('is quiet at 21:30 UTC in summer (23:30 local) and not at 19:30 UTC (21:30 local)', function () {
        [, , $pet] = tzFamily(TZ_BEDTIME);
        $quiet = $pet->quietHours();

        expect($quiet->isQuietNow(Carbon::parse('2026-07-15 21:30', 'UTC')))->toBeTrue();
        expect($quiet->isQuietNow(Carbon::parse('2026-07-15 19:30', 'UTC')))->toBeFalse();
        // 06:00 local = 04:00 UTC in summer (end is exclusive).
        expect($quiet->isQuietNow(Carbon::parse('2026-07-16 03:59', 'UTC')))->toBeTrue();
        expect($quiet->isQuietNow(Carbon::parse('2026-07-16 04:00', 'UTC')))->toBeFalse();
    });

    it('shifts with winter time (UTC+1)', function () {
        [, , $pet] = tzFamily(TZ_BEDTIME);
        $quiet = $pet->quietHours();

        expect($quiet->isQuietNow(Carbon::parse('2026-12-15 20:59', 'UTC')))->toBeFalse();
        expect($quiet->isQuietNow(Carbon::parse('2026-12-15 21:00', 'UTC')))->toBeTrue();
        expect($quiet->isQuietNow(Carbon::parse('2026-12-16 04:59', 'UTC')))->toBeTrue();
        expect($quiet->isQuietNow(Carbon::parse('2026-12-16 05:00', 'UTC')))->toBeFalse();
    });

    it('finds the next boundary in local time and returns it in the caller timezone', function () {
        [, , $pet] = tzFamily(TZ_BEDTIME);
        $next = $pet->quietHours()->nextBoundaryAfter(Carbon::parse('2026-07-15 12:00', 'UTC'));

        expect($next->getTimezone()->getName())->toBe('UTC');
        expect($next->format('Y-m-d H:i'))->toBe('2026-07-15 20:00');
    });

    it('lists the fall-back transition and the next boundary on 2026-10-25', function () {
        [, , $pet] = tzFamily(TZ_BEDTIME);
        $quiet = $pet->quietHours();

        $b1 = $quiet->nextBoundaryAfter(Carbon::parse('2026-10-24 19:00', 'UTC'));
        $b2 = $quiet->nextBoundaryAfter($b1);
        $b3 = $quiet->nextBoundaryAfter($b2);

        expect($b1->format('Y-m-d H:i'))->toBe('2026-10-24 20:00'); // 22:00 CEST
        expect($b2->format('Y-m-d H:i'))->toBe('2026-10-25 01:00'); // 03:00 CEST → 02:00 CET
        expect($b3->format('Y-m-d H:i'))->toBe('2026-10-25 05:00'); // 06:00 CET
    });

    it('starts a window that begins inside the spring-forward gap at the jump', function () {
        // 02:30 does not exist on 2027-03-28: the clock goes 01:59 CET → 03:00 CEST (01:00 UTC).
        [, , $pet] = tzFamily(['school_start' => '02:30', 'school_end' => '04:00']);
        $quiet = $pet->quietHours();

        expect($quiet->isQuietNow(Carbon::parse('2027-03-28 00:59', 'UTC')))->toBeFalse();
        expect($quiet->isQuietNow(Carbon::parse('2027-03-28 01:00', 'UTC')))->toBeTrue();
        expect($quiet->nextBoundaryAfter(Carbon::parse('2027-03-28 00:30', 'UTC'))->format('Y-m-d H:i'))
            ->toBe('2027-03-28 01:00');
        // 04:00 CEST = 02:00 UTC.
        expect($quiet->nextBoundaryAfter(Carbon::parse('2027-03-28 01:00', 'UTC'))->format('Y-m-d H:i'))
            ->toBe('2027-03-28 02:00');
    });
});

describe('Decay catch-up across DST changes', function () {
    it('counts 9 quiet hours for 22:00–06:00 on the night clocks go back (2026-10-25)', function () {
        // 18:00 UTC (20:00 CEST) → 08:00 UTC (09:00 CET): quiet 20:00–05:00 UTC = 9 h, normal 5 h.
        $pet = tzCatchUp(TZ_BEDTIME, '2026-10-24 18:00', '2026-10-25 08:00');

        expect($pet->hunger_level)->toEqualWithDelta(100 - 8 * (5 + 9 * 0.1), 1e-6);  // 52.8
        expect(QuietHours::splitSecondsBetween($pet->quietHours(), Carbon::parse('2026-10-24 18:00', 'UTC'), now()))
            ->toBe(['normal' => 5 * 3600.0, 'quiet' => 9 * 3600.0]);
    });

    it('counts 7 quiet hours for 22:00–06:00 on the night clocks go forward (2027-03-28)', function () {
        // 18:00 UTC (19:00 CET) → 08:00 UTC (10:00 CEST): quiet 21:00–04:00 UTC = 7 h, normal 7 h.
        $pet = tzCatchUp(TZ_BEDTIME, '2027-03-27 18:00', '2027-03-28 08:00');

        expect($pet->hunger_level)->toEqualWithDelta(100 - 8 * (7 + 7 * 0.1), 1e-6);  // 38.4
        expect(QuietHours::splitSecondsBetween($pet->quietHours(), Carbon::parse('2027-03-27 18:00', 'UTC'), now()))
            ->toBe(['normal' => 7 * 3600.0, 'quiet' => 7 * 3600.0]);
    });

    it('handles a window inside the repeated hour (02:30–05:00 on 2026-10-25)', function () {
        // 02:30 CEST (00:30 UTC) quiet → 03:00 CEST becomes 02:00 CET (01:00 UTC) not quiet
        // → 02:30 CET (01:30 UTC) quiet → 05:00 CET (04:00 UTC). Quiet 0.5 + 2.5 = 3 h of 8 h.
        $window = ['school_start' => '02:30', 'school_end' => '05:00'];
        $pet = tzCatchUp($window, '2026-10-24 22:00', '2026-10-25 06:00');

        expect(QuietHours::splitSecondsBetween($pet->quietHours(), Carbon::parse('2026-10-24 22:00', 'UTC'), now()))
            ->toBe(['normal' => 5 * 3600.0, 'quiet' => 3 * 3600.0]);
        expect($pet->hunger_level)->toEqualWithDelta(100 - 8 * (5 + 3 * 0.1), 1e-6);
    });

    it('matches minute-by-minute ticks across the fall-back night', function () {
        Carbon::setTestNow(Carbon::parse('2026-10-24 18:00', 'UTC'));
        [, , $perMinute] = tzFamily(TZ_BEDTIME);
        $start = now()->copy();
        for ($minute = 1; $minute <= 14 * 60; $minute++) {
            tzTick($perMinute, $start->copy()->addMinutes($minute)->toDateTimeString());
        }

        $single = tzCatchUp(TZ_BEDTIME, '2026-10-24 18:00', '2026-10-25 08:00');

        // Hygiene events are random per pet (HygieneEventTest covers catch-up).
        foreach (['hunger_level', 'thirst_level', 'energy_level'] as $metric) {
            expect($perMinute->fresh()->{$metric})->toEqualWithDelta($single->{$metric}, 1e-6);
        }
    });
});

describe('Daily step reset at local midnight', function () {
    it('resets at 22:00 UTC in summer (00:00 Europe/Ljubljana), not before, and not again at UTC midnight', function () {
        Carbon::setTestNow(Carbon::parse('2026-07-15 08:00', 'UTC'));
        [, , $pet] = tzFamily(pet: ['daily_step_count' => 5000, 'last_step_reset_at' => now()]);

        expect(tzTick($pet, '2026-07-15 21:59')->daily_step_count)->toBe(5000);

        $reset = tzTick($pet, '2026-07-15 22:00');
        expect($reset->daily_step_count)->toBe(0);
        expect($reset->last_step_reset_at->format('Y-m-d H:i'))->toBe('2026-07-15 22:00');

        Pet::whereKey($pet->id)->update(['daily_step_count' => 3000]);
        expect(tzTick($pet, '2026-07-16 00:01')->daily_step_count)->toBe(3000);
    });

    it('resets at 23:00 UTC in winter (00:00 CET)', function () {
        Carbon::setTestNow(Carbon::parse('2026-12-15 08:00', 'UTC'));
        [, , $pet] = tzFamily(pet: ['daily_step_count' => 5000, 'last_step_reset_at' => now()]);

        expect(tzTick($pet, '2026-12-15 22:59')->daily_step_count)->toBe(5000);
        expect(tzTick($pet, '2026-12-15 23:00')->daily_step_count)->toBe(0);
    });
});

describe('Parent dashboard in local days', function () {
    it('buckets weekly performance by the family local day and returns the timezone', function () {
        Carbon::setTestNow(Carbon::parse('2026-07-14 21:30', 'UTC')); // 14 Jul 23:30 local
        [$parent, , $pet] = tzFamily();
        ActivityLog::create(['pet_id' => $pet->id, 'activity_type' => ActivityType::FedPet->value, 'value' => 100]);

        Carbon::setTestNow(Carbon::parse('2026-07-14 22:30', 'UTC')); // 15 Jul 00:30 local
        ActivityLog::create(['pet_id' => $pet->id, 'activity_type' => ActivityType::WateredPet->value, 'value' => 100]);
        ActivityLog::create(['pet_id' => $pet->id, 'activity_type' => ActivityType::IgnoredWarning->value, 'value' => 30]);

        // 15 Jul 23:30 local, still "today" locally although UTC is 21:30.
        Carbon::setTestNow(Carbon::parse('2026-07-15 21:30', 'UTC'));
        actingAs($parent, 'sanctum');

        $response = getJson('/api/parent/dashboard')->assertOk();

        $response->assertJsonPath('timezone', TZ_LJUBLJANA);
        $days = collect($response->json('weekly_performance'))->keyBy('date');
        expect($days->keys()->first())->toBe('2026-07-09');
        expect($days->keys()->last())->toBe('2026-07-15');
        expect($days['2026-07-14'])->toMatchArray(['completed' => 1, 'missed' => 0]);
        expect($days['2026-07-15'])->toMatchArray(['completed' => 1, 'missed' => 1]);
    });

    it('rolls over to the next local day at 22:00 UTC in summer', function () {
        Carbon::setTestNow(Carbon::parse('2026-07-15 22:00', 'UTC')); // 16 Jul 00:00 local
        [$parent] = tzFamily();
        actingAs($parent, 'sanctum');

        $response = getJson('/api/parent/dashboard')->assertOk();

        expect(collect($response->json('weekly_performance'))->last()['date'])->toBe('2026-07-16');
    });

    it('returns the timezone even before a child is paired', function () {
        $parent = User::factory()->parent()->create(['timezone' => 'Europe/Vienna']);
        actingAs($parent, 'sanctum');

        getJson('/api/parent/dashboard')->assertOk()->assertJsonPath('timezone', 'Europe/Vienna');
    });
});

describe('PUT /api/parent/settings', function () {
    it('lets a parent set the family timezone', function () {
        $parent = User::factory()->parent()->create();
        actingAs($parent, 'sanctum');

        putJson('/api/parent/settings', ['timezone' => 'America/New_York'])
            ->assertOk()
            ->assertJsonPath('settings.timezone', 'America/New_York');

        expect($parent->fresh()->timezone)->toBe('America/New_York');
    });

    it('rejects names that are not IANA timezones', function (mixed $timezone) {
        $parent = User::factory()->parent()->create();
        actingAs($parent, 'sanctum');

        putJson('/api/parent/settings', ['timezone' => $timezone])
            ->assertStatus(422)
            ->assertJsonValidationErrors('timezone');

        expect($parent->fresh()->timezone)->toBe(TZ_LJUBLJANA);
    })->with([
        'unknown' => 'Mars/Olympus',
        'offset' => '+02:00',
        'abbreviation' => 'CEST',
        'empty' => '',
        'not a string' => 42,
    ]);

    it('requires the timezone field', function () {
        actingAs(User::factory()->parent()->create(), 'sanctum');

        putJson('/api/parent/settings', [])->assertStatus(422)->assertJsonValidationErrors('timezone');
    });

    it('forbids a child from changing family settings', function () {
        $parent = User::factory()->parent()->create();
        $child = User::factory()->child()->create(['parent_id' => $parent->id]);
        actingAs($child, 'sanctum');

        putJson('/api/parent/settings', ['timezone' => 'America/New_York'])->assertForbidden();

        expect($parent->fresh()->timezone)->toBe(TZ_LJUBLJANA);
        expect($child->fresh()->timezone)->toBe(TZ_LJUBLJANA);
    });

    it('requires authentication', function () {
        putJson('/api/parent/settings', ['timezone' => 'America/New_York'])->assertUnauthorized();
    });
});

describe('PUT /api/parent/quiet-hours with timezone', function () {
    it('saves an optional timezone together with the windows', function () {
        $parent = User::factory()->parent()->create();
        actingAs($parent, 'sanctum');

        putJson('/api/parent/quiet-hours', [
            'bedtime_start' => '21:00',
            'bedtime_end' => '07:00',
            'timezone' => 'Europe/London',
        ])->assertOk()->assertJsonPath('timezone', 'Europe/London');

        expect($parent->fresh()->timezone)->toBe('Europe/London');
        expect($parent->quietHours()->first()->bedtime_start)->toStartWith('21:00');
    });

    it('keeps the timezone when it is omitted', function () {
        $parent = User::factory()->parent()->create(['timezone' => 'Europe/Vienna']);
        actingAs($parent, 'sanctum');

        putJson('/api/parent/quiet-hours', ['bedtime_start' => '21:00', 'bedtime_end' => '07:00'])
            ->assertOk()
            ->assertJsonPath('timezone', 'Europe/Vienna');
    });

    it('rejects an invalid timezone and saves nothing', function () {
        $parent = User::factory()->parent()->create();
        actingAs($parent, 'sanctum');

        putJson('/api/parent/quiet-hours', [
            'school_start' => '08:00',
            'school_end' => '13:00',
            'bedtime_start' => '22:30',
            'bedtime_end' => '06:30',
            'is_active' => false,
            'timezone' => 'Ljubljana',
        ])->assertStatus(422)->assertJsonValidationErrors('timezone');

        // Only the default row the family got at registration, still the defaults.
        $row = $parent->quietHours()->sole();
        expect($row->bedtime_start)->toStartWith('21:00')
            ->and($row->bedtime_end)->toStartWith('07:00')
            ->and($row->school_start)->toBeNull()
            ->and($row->school_end)->toBeNull()
            ->and($row->is_active)->toBeTrue();
        expect($parent->fresh()->timezone)->toBe(TZ_LJUBLJANA);
    });
});
