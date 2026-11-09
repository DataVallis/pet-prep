<?php

use App\Events\PetUpdated;
use App\Models\BreedConfig;
use App\Models\DevicePushToken;
use App\Models\Family;
use App\Models\Pet;
use App\Models\PetDailyWalk;
use App\Models\PushNotification;
use App\Models\User;
use App\Services\CareScheduleService;
use App\Services\CareScoreService;
use App\Services\EscalationService;
use App\Services\FamilyInviteService;
use App\Services\FamilyService;
use App\Services\PetActivityService;
use App\Services\PetDecayService;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\putJson;

/*
|--------------------------------------------------------------------------
| Time zone per pet (David 2026-11-09)
|--------------------------------------------------------------------------
|
| David is in Lisbon with his dog, his wife and kids are in Ljubljana with
| theirs: one family zone can't serve both. A pet's effective zone =
| pets.timezone ?? families.timezone. November 2026: Europe/Ljubljana =
| UTC+1, Europe/Lisbon = UTC+0, America/New_York = UTC−5. Instants are UTC.
*/

/**
 * Parent + two children, each caring for their own pet (Ljubljana family,
 * bedtime 21:00–07:00).
 *
 * @return array{parent: User, kid1: User, kid2: User, lj: Pet, li: Pet, family: Family}
 */
function ptzFamily(?string $secondZone = 'Europe/Lisbon', int $steps = 0): array
{
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $kid1 = User::factory()->child()->create(['parent_id' => $parent->id]);
    $kid2 = User::factory()->child()->create(['parent_id' => $parent->id]);
    setQuietHours(['parent_id' => $parent->id, 'bedtime_start' => '21:00', 'bedtime_end' => '07:00', 'is_active' => true]);

    $attributes = [
        'born_at' => Carbon::parse('2026-11-01 10:00', 'UTC'),
        'hunger_level' => 100,
        'thirst_level' => 100,
        'energy_level' => $steps > 0 ? 100 : 0,
        'daily_step_count' => $steps,
        'last_step_reset_at' => Carbon::parse('2026-11-07 23:00', 'UTC'), // Ljubljana midnight of 2026-11-08
        'last_decay_at' => now(),
    ];
    $lj = disableHygieneEvents(Pet::factory()->create(['user_id' => $kid1->id] + $attributes));
    $li = disableHygieneEvents(Pet::factory()->create(['user_id' => $kid2->id] + $attributes));
    if ($secondZone !== null) {
        $li->forceFill(['timezone' => $secondZone])->save();
    }

    return [
        'parent' => $parent,
        'kid1' => $kid1,
        'kid2' => $kid2,
        'lj' => $lj->fresh(),
        'li' => $li->fresh(),
        'family' => app(FamilyService::class)->familyOf($parent),
    ];
}

function ptzAt(string $utc): void
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
}

function ptzTick(Pet $pet, string $utc): Pet
{
    ptzAt($utc);
    Pet::whereKey($pet->id)->update(['hunger_level' => 100, 'thirst_level' => 100]);
    app(PetDecayService::class)->processPetDecay(Pet::findOrFail($pet->id));
    app(EscalationService::class)->processPetEscalation(Pet::findOrFail($pet->id));

    return Pet::findOrFail($pet->id);
}

function ptzChange(User $parent, Pet $pet, ?string $timezone, string $utc): TestResponse
{
    ptzAt($utc);
    actingAs($parent, 'sanctum');

    return putJson("/api/parent/pets/{$pet->id}/timezone", ['timezone' => $timezone]);
}

/**
 * @return array<string, int>
 */
function ptzWalks(Pet $pet): array
{
    return PetDailyWalk::where('pet_id', $pet->id)->orderBy('local_date')->get()
        ->mapWithKeys(fn (PetDailyWalk $w) => [substr((string) $w->getRawOriginal('local_date'), 0, 10) => (int) $w->steps])
        ->all();
}

beforeEach(function () {
    seedBreedConfigs();
    ptzAt('2026-11-08 08:00');
});

describe('Two zones in one family at the same instant', function () {
    it('reads meal windows and quiet hours on each pet\'s own clock', function () {
        ['lj' => $lj, 'li' => $li] = ptzFamily();
        ptzAt('2026-11-09 20:30'); // 21:30 Ljubljana, 20:30 Lisbon

        expect($lj->effectiveTimezone())->toBe('Europe/Ljubljana')
            ->and($li->effectiveTimezone())->toBe('Europe/Lisbon')
            ->and($lj->quietHours()->isQuietNow(now()))->toBeTrue()
            ->and($li->quietHours()->isQuietNow(now()))->toBeFalse()
            ->and($lj->localDate(Carbon::parse('2026-11-09 23:30', 'UTC')))->toBe('2026-11-10')
            ->and($li->localDate(Carbon::parse('2026-11-09 23:30', 'UTC')))->toBe('2026-11-09');

        // Same configured windows, one hour apart in UTC: e.g. 07:00 local.
        $schedule = app(CareScheduleService::class);
        $config = BreedConfig::forBreed($lj->breed_type);
        $ljWindows = $schedule->feedWindowsStartingOn($lj, $config, '2026-11-09');
        $liWindows = $schedule->feedWindowsStartingOn($li, $config, '2026-11-09');
        expect($ljWindows)->not->toBeEmpty()->and(count($liWindows))->toBe(count($ljWindows));
        foreach ($ljWindows as $i => [$start]) {
            expect($start->format('H:i'))->toBe($liWindows[$i][0]->format('H:i'))
                ->and($liWindows[$i][0]->getTimestamp() - $start->getTimestamp())->toBe(3600);
        }
    });

    it('closes each pet\'s day at its own midnight', function () {
        ['lj' => $lj, 'li' => $li] = ptzFamily(steps: 3000);
        // Both stamped at the Ljubljana midnight; give the Lisbon pet its own day start.
        Pet::whereKey($li->id)->update(['last_step_reset_at' => Carbon::parse('2026-11-08 00:00', 'UTC')]);

        expect(ptzTick($lj, '2026-11-08 23:05')->daily_step_count)->toBe(0)
            ->and(ptzTick($li, '2026-11-08 23:05')->daily_step_count)->toBe(3000)
            ->and(ptzTick($li, '2026-11-09 00:05')->daily_step_count)->toBe(0)
            ->and(ptzWalks($lj))->toBe(['2026-11-08' => 3000])
            ->and(ptzWalks($li))->toBe(['2026-11-08' => 3000]);
    });

    it('carries each pet\'s zone in the child state and the parent dashboard', function () {
        ['parent' => $parent, 'kid2' => $kid2, 'lj' => $lj, 'li' => $li] = ptzFamily();

        actingAs($kid2, 'sanctum');
        getJson('/api/child/pet')->assertOk()->assertJsonPath('timezone', 'Europe/Lisbon');

        actingAs($parent, 'sanctum');
        $pets = collect(getJson('/api/parent/dashboard')->assertOk()->json('family.pets'))->keyBy('id');
        expect($pets[$lj->id]['timezone'])->toBe('Europe/Ljubljana')
            ->and($pets[$lj->id]['timezone_follows_family'])->toBeTrue()
            ->and($pets[$li->id]['timezone'])->toBe('Europe/Lisbon')
            ->and($pets[$li->id]['timezone_follows_family'])->toBeFalse();
    });
});

describe('Changing one pet\'s zone during the day', function () {
    it('moves west (Ljubljana → Lisbon) keeping today\'s steps until the Lisbon midnight', function () {
        Event::fake([PetUpdated::class]);
        ['parent' => $parent, 'lj' => $pet, 'li' => $other] = ptzFamily(secondZone: null);
        ptzAt('2026-11-08 09:00');
        app(PetActivityService::class)->recordSteps($pet->fresh(), 3000, now());

        ptzChange($parent, $pet, 'Europe/Lisbon', '2026-11-08 19:30')->assertOk()
            ->assertJsonPath('timezone', 'Europe/Lisbon')
            ->assertJsonPath('timezone_follows_family', false);

        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->petId === $pet->id && $e->eventType === 'pet_timezone_changed');
        Event::assertNotDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->petId === $other->id);
        expect($other->fresh()->effectiveTimezone())->toBe('Europe/Ljubljana');

        expect(ptzTick($pet, '2026-11-08 19:35')->daily_step_count)->toBe(3000)
            ->and(ptzTick($pet, '2026-11-08 23:05')->daily_step_count)->toBe(3000);
        $closed = ptzTick($pet, '2026-11-09 00:01');
        expect($closed->daily_step_count)->toBe(0)
            ->and($closed->walk_illness_due_at)->toBeNull()
            ->and(ptzWalks($pet))->toBe(['2026-11-08' => 3000]);
    });

    it('moves west without steps and never schedules a walk illness at the change', function () {
        ['parent' => $parent, 'lj' => $pet] = ptzFamily(secondZone: null);

        ptzChange($parent, $pet, 'America/New_York', '2026-11-08 14:00')->assertOk();
        $after = ptzTick($pet, '2026-11-08 14:05');

        expect($after->walk_illness_due_at)->toBeNull()
            ->and($after->illness_until)->toBeNull()
            ->and(ptzWalks($pet))->toBe([]);
    });

    it('moves east (Lisbon → Ljubljana) across the new midnight: a shorter day, closed once', function () {
        ['parent' => $parent, 'li' => $pet] = ptzFamily(steps: 3000);
        Pet::whereKey($pet->id)->update(['last_step_reset_at' => Carbon::parse('2026-11-08 00:00', 'UTC')]);

        // 23:30 Lisbon (2026-11-08) = 00:30 Ljubljana (2026-11-09).
        ptzChange($parent, $pet->fresh(), null, '2026-11-08 23:30')->assertOk()
            ->assertJsonPath('timezone', 'Europe/Ljubljana')
            ->assertJsonPath('timezone_follows_family', true);

        $after = ptzTick($pet, '2026-11-08 23:35');
        expect($after->daily_step_count)->toBe(0)
            ->and($after->walk_illness_due_at)->toBeNull()
            ->and(ptzWalks($pet))->toBe(['2026-11-08' => 3000]);

        // The Ljubljana midnight of 2026-11-10 (23:00 UTC) is the next one.
        expect(ptzTick($pet, '2026-11-09 22:55')->last_step_reset_at->utc()->format('Y-m-d'))->toBe('2026-11-08')
            ->and(ptzWalks($pet))->toBe(['2026-11-08' => 3000]);
    });
});

describe('Family zone and reset', function () {
    it('changes only the default: pets with their own zone keep it and are not stamped', function () {
        ['parent' => $parent, 'lj' => $follower, 'li' => $own, 'family' => $family] = ptzFamily(steps: 3000);
        $ownReset = $own->last_step_reset_at->toIso8601String();

        ptzAt('2026-11-08 14:00');
        actingAs($parent, 'sanctum');
        putJson('/api/parent/settings', ['timezone' => 'America/New_York'])->assertOk();

        expect($family->fresh()->timezone)->toBe('America/New_York')
            ->and($follower->fresh()->effectiveTimezone())->toBe('America/New_York')
            ->and($own->fresh()->effectiveTimezone())->toBe('Europe/Lisbon')
            ->and($own->fresh()->timezone)->toBe('Europe/Lisbon')
            ->and($own->fresh()->last_step_reset_at->toIso8601String())->toBe($ownReset)
            ->and($own->fresh()->timezone_changed_at)->toBeNull()
            ->and($follower->fresh()->timezone_changed_at)->toBeNull();
    });

    it('resets a pet to the family zone (column back to null)', function () {
        ['parent' => $parent, 'li' => $pet] = ptzFamily();

        ptzChange($parent, $pet, null, '2026-11-08 12:00')->assertOk()
            ->assertJsonPath('timezone', 'Europe/Ljubljana')
            ->assertJsonPath('timezone_follows_family', true);

        expect($pet->fresh()->timezone)->toBeNull()
            ->and($pet->fresh()->hasOwnTimezone())->toBeFalse();
    });

    it('runs David\'s sequence: family back to Ljubljana, then his dog to Lisbon right away', function () {
        ['parent' => $parent, 'lj' => $wife, 'li' => $david, 'family' => $family] = ptzFamily(secondZone: null);
        actingAs($parent, 'sanctum');
        ptzAt('2026-11-08 19:30');
        putJson('/api/parent/settings', ['timezone' => 'Europe/Lisbon'])->assertOk();

        ptzAt('2026-11-09 19:31');
        putJson('/api/parent/settings', ['timezone' => 'Europe/Ljubljana'])->assertOk();
        ptzChange($parent, $david, 'Europe/Lisbon', '2026-11-09 19:32')->assertOk();

        expect($family->fresh()->timezone)->toBe('Europe/Ljubljana')
            ->and($wife->fresh()->effectiveTimezone())->toBe('Europe/Ljubljana')
            ->and($david->fresh()->effectiveTimezone())->toBe('Europe/Lisbon');
    });
});

describe('The 24 h limit per pet', function () {
    it('allows one move per pet per 24 h; another pet and a free pin are not limited', function () {
        ['parent' => $parent, 'lj' => $pet, 'li' => $other] = ptzFamily();

        ptzChange($parent, $pet, 'Europe/Lisbon', '2026-11-08 12:00')->assertOk();
        ptzChange($parent, $pet, 'America/New_York', '2026-11-09 11:59')
            ->assertStatus(422)
            ->assertJsonPath('reason', 'timezone_change_too_soon')
            ->assertJsonPath('next_allowed_at', '2026-11-09T12:00:00+00:00');
        expect($pet->fresh()->timezone)->toBe('Europe/Lisbon');

        // Resetting moves the clock too: limited as well.
        ptzChange($parent, $pet, null, '2026-11-09 11:59')->assertStatus(422);

        // The other pet has its own limit.
        ptzChange($parent, $other, 'America/New_York', '2026-11-09 11:59')->assertOk();

        // The same zone again changes nothing.
        ptzChange($parent, $pet, 'Europe/Lisbon', '2026-11-09 11:59')->assertOk();
        expect($pet->fresh()->timezone_changed_at->utc()->format('Y-m-d H:i'))->toBe('2026-11-08 12:00');

        ptzChange($parent, $pet, null, '2026-11-09 12:00')->assertOk();
        expect($pet->fresh()->effectiveTimezone())->toBe('Europe/Ljubljana');
    });

    it('lets a pet pin the zone it already follows without counting it', function () {
        ['parent' => $parent, 'lj' => $pet] = ptzFamily();

        ptzChange($parent, $pet, 'Europe/Ljubljana', '2026-11-08 12:00')->assertOk()
            ->assertJsonPath('timezone_follows_family', false);
        expect($pet->fresh()->timezone_changed_at)->toBeNull();

        ptzChange($parent, $pet, 'Europe/Lisbon', '2026-11-08 12:01')->assertOk();
    });
});

describe('Authorization and input', function () {
    it('answers 403 for another family\'s pet and for a child, 404 for an unknown pet', function () {
        ['parent' => $parent, 'kid1' => $kid, 'lj' => $pet] = ptzFamily();
        $stranger = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);

        ptzChange($stranger, $pet, 'Europe/Lisbon', '2026-11-08 12:00')->assertForbidden();
        ptzChange($kid, $pet, 'Europe/Lisbon', '2026-11-08 12:00')->assertForbidden();
        actingAs($parent, 'sanctum');
        putJson('/api/parent/pets/999999/timezone', ['timezone' => 'Europe/Lisbon'])->assertNotFound();

        expect($pet->fresh()->timezone)->toBeNull();
    });

    it('rejects an unknown zone and accepts device aliases', function () {
        ['parent' => $parent, 'lj' => $pet] = ptzFamily();

        ptzChange($parent, $pet, 'Mars/Olympus', '2026-11-08 12:00')->assertStatus(422)->assertJsonValidationErrors('timezone');
        actingAs($parent, 'sanctum');
        putJson("/api/parent/pets/{$pet->id}/timezone", [])->assertStatus(422)->assertJsonValidationErrors('timezone');

        ptzChange($parent, $pet, 'Etc/UTC', '2026-11-08 12:00')->assertOk()->assertJsonPath('timezone', 'UTC');
    });
});

describe('Pushes', function () {
    it('holds quiet hours on each pet\'s clock', function () {
        config(['push.enabled' => true]);
        ['parent' => $parent, 'lj' => $lj, 'li' => $li] = ptzFamily();
        DevicePushToken::create([
            'user_id' => $parent->id,
            'expo_push_token' => 'ExponentPushToken[ptz-parent]',
            'platform' => 'ios',
            'app_version' => '1.0.0',
            'locale' => 'sl',
            'last_seen_at' => now(),
        ]);
        Http::swap(new HttpFactory(app('events')));
        Http::preventStrayRequests();
        Http::fake(['https://exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket-1']]])]);

        // 21:30 Ljubljana (quiet), 20:30 Lisbon (not quiet): both bowls empty for 2 h.
        ptzAt('2026-11-09 20:30');
        foreach ([$lj, $li] as $pet) {
            Pet::whereKey($pet->id)->update(['hunger_level' => 0, 'hunger_zero_since' => now()->subHours(2), 'last_decay_at' => now()]);
            app(EscalationService::class)->processPetEscalation(Pet::findOrFail($pet->id));
        }

        $ljPush = PushNotification::where('pet_id', $lj->id)->first();
        $liPush = PushNotification::where('pet_id', $li->id)->first();
        expect($ljPush?->status)->toBe('suppressed')
            ->and($ljPush?->suppressed_reason)->toBe('quiet_hours')
            ->and($liPush)->not->toBeNull()
            ->and($liPush?->suppressed_reason)->toBeNull();
    });
});

describe('QA #891 follow-ups', function () {
    it('lets a co-parent of the family change the zone', function () {
        ['parent' => $parent, 'lj' => $pet] = ptzFamily();
        $second = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
        app(FamilyInviteService::class)->joinFamily($second, app(FamilyInviteService::class)->createInvite($parent)['code']);

        ptzChange($second, $pet, 'Europe/Lisbon', '2026-11-08 12:00')->assertOk()->assertJsonPath('timezone', 'Europe/Lisbon');
    });

    it('repeats a date on a move west through the pet endpoint without a second walk illness', function () {
        // Day 2026-11-07 (Ljubljana) walked in full and closed at 23:00 UTC.
        ptzAt('2026-11-07 22:00');
        ['parent' => $parent, 'lj' => $pet] = ptzFamily(secondZone: null, steps: 6000);
        Pet::whereKey($pet->id)->update(['last_step_reset_at' => Carbon::parse('2026-11-06 23:00', 'UTC')]);
        expect(ptzTick($pet, '2026-11-07 23:01')->daily_step_count)->toBe(0)
            ->and(ptzWalks($pet))->toBe(['2026-11-07' => 6000]);

        // 03:30 Ljubljana 2026-11-08 = 21:30 New York 2026-11-07: the date repeats, energy 0.
        ptzChange($parent, $pet, 'America/New_York', '2026-11-08 02:30')->assertOk();
        expect(ptzTick($pet, '2026-11-08 02:35')->walk_illness_due_at)->toBeNull();

        $again = ptzTick($pet, '2026-11-08 05:01'); // New York midnight
        expect(ptzWalks($pet))->toBe(['2026-11-07' => 6000])
            ->and($again->walk_illness_due_at)->toBeNull()
            ->and($again->illness_until)->toBeNull();
    });

    it('closes the old day like the tick: frozen without a walk illness, game over / inactive not at all', function () {
        ['parent' => $parent, 'lj' => $normal, 'li' => $stopped] = ptzFamily(secondZone: null);
        $kid3 = User::factory()->child()->create(['parent_id' => $parent->id]);
        $over = disableHygieneEvents(Pet::factory()->create([
            'user_id' => $kid3->id, 'born_at' => Carbon::parse('2026-11-01 10:00', 'UTC'), 'energy_level' => 0,
            'daily_step_count' => 0, 'last_step_reset_at' => Carbon::parse('2026-11-06 23:00', 'UTC'), 'last_decay_at' => now(),
        ]));
        $over->forceFill(['is_game_over' => true, 'is_active' => false])->save();
        // Day 2026-11-07 (Ljubljana) not walked and not closed yet.
        Pet::whereIn('id', [$normal->id, $stopped->id])->update(['last_step_reset_at' => Carbon::parse('2026-11-06 23:00', 'UTC')]);
        $stopped->fresh()->forceFill(['is_hard_stopped' => true])->save();

        foreach ([$normal, $stopped, $over] as $pet) {
            ptzChange($parent, $pet->fresh(), 'Europe/Lisbon', '2026-11-08 12:00')->assertOk();
        }

        expect(ptzWalks($normal))->toHaveKey('2026-11-07')
            ->and($normal->fresh()->walk_illness_due_at)->not->toBeNull()
            ->and(ptzWalks($stopped))->toHaveKey('2026-11-07')
            ->and($stopped->fresh()->walk_illness_due_at)->toBeNull()
            ->and(ptzWalks($over))->toBe([])
            ->and($over->fresh()->timezone)->toBe('Europe/Lisbon');
    });

    it('changes the zone of an ill pet without a walk illness', function () {
        ['parent' => $parent, 'lj' => $pet] = ptzFamily(secondZone: null);
        Pet::whereKey($pet->id)->update(['last_step_reset_at' => Carbon::parse('2026-11-06 23:00', 'UTC')]);
        $pet->fresh()->forceFill(['illness_until' => Carbon::parse('2026-11-09 12:00', 'UTC')])->save();

        ptzChange($parent, $pet->fresh(), 'Europe/Lisbon', '2026-11-08 12:00')->assertOk();
        expect($pet->fresh()->walk_illness_due_at)->toBeNull()
            ->and($pet->fresh()->effectiveTimezone())->toBe('Europe/Lisbon');
    });

    it('builds the Care Score board with each pet\'s own »today«', function () {
        ['lj' => $lj, 'li' => $li] = ptzFamily();
        $now = Carbon::parse('2026-11-09 23:30', 'UTC'); // 00:30 Ljubljana 11-10, 23:30 Lisbon 11-09
        $scores = app(CareScoreService::class);
        $board = $scores->board(collect([$lj->fresh(), $li->fresh()]), 'Europe/Ljubljana', now: $now);

        expect(CareScoreService::todayOf($board, $board['pets'][$lj->id]))->toBe('2026-11-10')
            ->and(CareScoreService::todayOf($board, $board['pets'][$li->id]))->toBe('2026-11-09')
            ->and(CareScoreService::zoneOf($board, $board['pets'][$li->id]))->toBe('Europe/Lisbon')
            ->and($scores->petSummary($board, $board['pets'][$lj->id])['today']['date'])->toBe('2026-11-10')
            ->and($scores->petSummary($board, $board['pets'][$li->id])['today']['date'])->toBe('2026-11-09');
    });

    it('writes one audit line per saved change, also for a pin that does not move the clock', function () {
        ['parent' => $parent, 'lj' => $pet] = ptzFamily();
        Log::spy();

        ptzChange($parent, $pet, 'Europe/Ljubljana', '2026-11-08 12:00')->assertOk();
        ptzChange($parent, $pet, 'Europe/Lisbon', '2026-11-08 12:01')->assertOk();

        $lines = [];
        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context = []) use (&$lines): bool {
            if ($message === 'PetTimezoneService: pet timezone changed') {
                $lines[] = $context;
            }

            return true;
        });
        expect($lines)->toHaveCount(2)
            ->and($lines[0])->toMatchArray(['pet_id' => $pet->id, 'by_user_id' => $parent->id, 'from' => 'Europe/Ljubljana', 'to' => 'Europe/Ljubljana', 'follows_family' => false, 'clock_moved' => false])
            ->and($lines[1])->toMatchArray(['from' => 'Europe/Ljubljana', 'to' => 'Europe/Lisbon', 'clock_moved' => true]);
    });

    it('exports each pet\'s effective zone (its local_date values are in it)', function () {
        ['parent' => $parent, 'lj' => $lj, 'li' => $li] = ptzFamily();
        actingAs($parent, 'sanctum');
        $pets = collect(getJson('/api/parent/account/export')->assertOk()->json('pets'))->keyBy('id');

        expect($pets[$li->id])->toMatchArray(['timezone' => 'Europe/Lisbon', 'timezone_follows_family' => false])
            ->and($pets[$lj->id])->toMatchArray(['timezone' => 'Europe/Ljubljana', 'timezone_follows_family' => true]);
    });
});
