<?php

use App\Enums\ActivityType;
use App\Models\ActivityLog;
use App\Models\BreedConfig;
use App\Models\Pet;
use App\Models\User;
use App\Services\PetDecayService;
use App\Services\PetProfilePayload;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| M5-R04 — profile.today.feed_windows[].fed: a meal was given inside that
| window today (child fed_pet or parent-covered parent_fed_pet).
|--------------------------------------------------------------------------
| Family in Europe/Ljubljana (UTC+2 in early October). 2-month mutt puppy:
| windows 07–09, 11–13, 15–17, 19–21 local; school 08–13 → 11–13 is the
| parent's meal.
*/

beforeEach(function () {
    seedLifeStageData();
    $this->withoutMiddleware([ThrottleRequests::class]);
});

/**
 * @return array{0: User, 1: User, 2: Pet}
 */
function fwFamily(array $attributes = []): array
{
    Carbon::setTestNow(Carbon::parse('2026-10-05 04:30:00', 'UTC'));
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => 'Maja']);
    setQuietHours([
        'parent_id' => $parent->id,
        'school_start' => '08:00', 'school_end' => '13:00',
        'bedtime_start' => '22:00', 'bedtime_end' => '06:00',
        'is_active' => true,
    ]);
    $pet = disableHygieneEvents(Pet::factory()->mutt()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => Carbon::parse('2026-10-05 04:30:00', 'UTC'),
        'arrival_age_months' => 2,
    ], $attributes)));

    return [$parent, $child, $pet->fresh()];
}

function fwLog(Pet $pet, ActivityType $type, string $utc, ?User $actor = null): void
{
    (new ActivityLog)->forceFill([
        'pet_id' => $pet->id, 'actor_user_id' => $actor?->id, 'activity_type' => $type,
        'value' => 50, 'created_at' => Carbon::parse($utc, 'UTC'),
    ])->save();
}

/** @return list<array{string, bool, bool}> start, parent_covered, fed */
function fwWindows(array $profile): array
{
    return array_map(fn (array $w) => [$w['start'], $w['parent_covered'], $w['fed']], $profile['today']['feed_windows']);
}

describe('profile.today.feed_windows[].fed', function () {
    it('ticks windows fed today by the child and by the parent — not yesterday\'s meal, not windows still ahead', function () {
        [$parent, $child, $pet] = fwFamily();
        fwLog($pet, ActivityType::FedPet, '2026-10-05 17:30:00', $child);   // yesterday 19:30 local
        fwLog($pet, ActivityType::FedPet, '2026-10-06 05:30:00', $child);   // 07:30 local
        fwLog($pet, ActivityType::ParentFedPet, '2026-10-06 09:00:00');     // 11:00 local (tick)
        fwLog($pet, ActivityType::WateredPet, '2026-10-06 13:10:00', $child); // 15:10 — water is no meal
        Carbon::setTestNow(Carbon::parse('2026-10-06 14:00:00', 'UTC'));    // 16:00 local

        actingAsRole($child);
        $profile = getJson('/api/child/pet')->assertOk()->json('pet.profile');
        expect(fwWindows($profile))->toBe([
            ['07:00', false, true],
            ['11:00', true, true],
            ['15:00', false, false],
            ['19:00', false, false],
        ]);

        // The parent sees the same ticks (single-pet field and the family list).
        app('auth')->forgetGuards();
        actingAsRole($parent);
        $dashboard = getJson('/api/parent/dashboard')->assertOk();
        expect(fwWindows($dashboard->json('pet.profile')))->toBe(fwWindows($profile))
            ->and(fwWindows(collect($dashboard->json('family.pets'))->firstWhere('id', $pet->id)['profile']))->toBe(fwWindows($profile));
    });

    it('ticks the window right after a real child feed and after the tick fed the parent meal', function () {
        [, $child, $pet] = fwFamily();
        Carbon::setTestNow(Carbon::parse('2026-10-06 05:15:00', 'UTC')); // 07:15 local
        app(PetDecayService::class)->processPetDecay($pet->fresh());

        actingAsRole($child);
        $state = postJson('/api/child/pet/feed')->assertOk()->json('state.pet.profile');
        expect(fwWindows($state)[0])->toBe(['07:00', false, true])
            ->and(fwWindows($state)[1])->toBe(['11:00', true, false]);

        Carbon::setTestNow(Carbon::parse('2026-10-06 09:01:00', 'UTC')); // 11:01 local
        app(PetDecayService::class)->processPetDecay($pet->fresh());
        $profile = getJson('/api/child/pet')->assertOk()->json('pet.profile');
        expect(fwWindows($profile)[1])->toBe(['11:00', true, true]);
    });

    it('is false for every window of an unborn pet and of a day without meals', function () {
        [, , $unborn] = fwFamily(['born_at' => null]);
        [, , $hungry] = fwFamily();
        Carbon::setTestNow(Carbon::parse('2026-10-06 19:30:00', 'UTC'));

        foreach ([$unborn, $hungry] as $pet) {
            $windows = PetProfilePayload::for($pet->fresh())->toArray()['today']['feed_windows'];
            expect($windows)->not->toBeEmpty()
                ->and(array_unique(array_column($windows, 'fed')))->toBe([false]);
        }
    });

    it('keeps the window instants right across the DST fall-back (2026-10-25)', function () {
        // Legacy pet → breed windows. 01:00 CEST (23:00 UTC) – 04:00 CET (03:00 UTC): 4 h on the
        // clock, 4 h real; 07:00–09:00 CET = 06:00–08:00 UTC (it was 05:00–07:00 UTC the day before).
        BreedConfig::where('breed_slug', 'mutt')->update(['feed_windows' => json_encode([['01:00', '04:00'], ['07:00', '09:00']])]);
        [, $child, $pet] = fwFamily(['arrival_age_months' => null]);
        fwLog($pet, ActivityType::FedPet, '2026-10-25 02:30:00', $child); // 03:30 CET (after the jump back)
        fwLog($pet, ActivityType::FedPet, '2026-10-25 05:30:00', $child); // 06:30 CET — before the 07:00 window
        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:00', 'UTC'));

        expect(fwWindows(PetProfilePayload::for($pet->fresh())->toArray()))->toBe([
            ['01:00', false, true],
            ['07:00', false, false],
        ]);

        fwLog($pet, ActivityType::FedPet, '2026-10-25 06:30:00', $child); // 07:30 CET
        expect(fwWindows(PetProfilePayload::for($pet->fresh())->toArray())[1])->toBe(['07:00', false, true]);
    });

    it('counts a meal after midnight for the window that started yesterday, not for today\'s list', function () {
        BreedConfig::where('breed_slug', 'mutt')->update(['feed_windows' => json_encode([['07:00', '09:00'], ['22:00', '02:00']])]);
        [, $child, $pet] = fwFamily(['arrival_age_months' => null]);
        // 2026-10-07 00:30 local (UTC+2) = 2026-10-06 22:30 UTC, inside the window 10-06 22:00 – 10-07 02:00.
        fwLog($pet, ActivityType::FedPet, '2026-10-06 22:30:00', $child);

        // Yesterday's list (the window that started 10-06 22:00) is ticked …
        $yesterday = PetProfilePayload::for($pet->fresh(), Carbon::parse('2026-10-06 21:45:00', 'UTC'))->toArray();
        expect($yesterday['today']['date'])->toBe('2026-10-06')
            ->and(fwWindows($yesterday))->toBe([['07:00', false, false], ['22:00', false, true]]);

        // … today's list (10-07, after midnight) holds only windows starting today: both open.
        $today = PetProfilePayload::for($pet->fresh(), Carbon::parse('2026-10-06 23:00:00', 'UTC'))->toArray();
        expect($today['today']['date'])->toBe('2026-10-07')
            ->and(fwWindows($today))->toBe([['07:00', false, false], ['22:00', false, false]]);
    });

    it('uses one activity-log query for all windows', function () {
        [, $child, $pet] = fwFamily();
        fwLog($pet, ActivityType::FedPet, '2026-10-06 05:30:00', $child);
        Carbon::setTestNow(Carbon::parse('2026-10-06 18:00:00', 'UTC'));
        $pet = $pet->fresh();

        $queries = 0;
        DB::listen(function ($q) use (&$queries) {
            if (str_contains($q->sql, 'activities_log')) {
                $queries++;
            }
        });
        PetProfilePayload::for($pet);

        expect($queries)->toBe(1);
    });
});
