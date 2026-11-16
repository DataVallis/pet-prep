<?php

use App\Enums\ActivityType;
use App\Enums\PushType;
use App\Models\ActivityLog;
use App\Models\Pet;
use App\Models\PushNotification;
use App\Models\User;
use App\Services\CareScheduleService;
use App\Services\EscalationService;
use App\Services\PetDecayService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Hunger floor until the next meal (David 2026-11-15, PRODUCT_SPEC §5)
|--------------------------------------------------------------------------
| David on a device, Sunday 2026-11-15 15:32 (Ljubljana, CET = UTC+1): a
| French Bulldog puppy (6 months, meals 06–10 ✓ and 17–21) showed 0 % hunger
| although the morning meal was given and the next window opens at 17:00 —
| −12 %/h empties the bowl in 8 h 20 min, the windows start 11 h apart, and
| on a day without school quiet hours nothing slows the decay. The child
| could not feed and the parent got the »Nujno« alarm for a perfectly
| cared-for pet. »Izberem št. 1 in mejo 25 %«: while the meal obligation is
| met, hunger stops at 25 % until the next meal window opens.
*/

beforeEach(function () {
    seedLifeStageData();
    config(['push.enabled' => true]);
    Queue::fake();
});

afterEach(function () {
    Carbon::setTestNow();
});

function hfAt(string $utc): void
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
}

/**
 * A born pet of a child in a Ljubljana family, acting as that child. Quiet
 * hours: none (switched off) unless $quiet is given.
 *
 * @param  array<string, mixed>  $attributes
 * @param  array<string, mixed>|null  $quiet
 * @return array{0: Pet, 1: User, 2: User}
 */
function hfPet(string $bornUtc, array $attributes = [], ?array $quiet = null): array
{
    hfAt($bornUtc);
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    if ($quiet !== null) {
        setQuietHours(array_merge(['parent_id' => $parent->id], $quiet));
    } else {
        withoutQuietHours($parent);
    }
    $pet = disableHygieneEvents(Pet::factory()->create(array_merge([
        'user_id' => $child->id,
        'breed_type' => 'french_bulldog',
        'arrival_age_months' => 6,
        'born_at' => Carbon::parse($bornUtc, 'UTC'),
        'last_decay_at' => now(),
    ], $attributes)));
    actingAsRole($child);

    return [$pet->fresh(), $child, $parent];
}

/** Set values exactly at the current test time (no decay owed). */
function hfSet(Pet $pet, array $attributes): Pet
{
    Pet::whereKey($pet->id)->update(array_merge(['last_decay_at' => now()], $attributes));

    return $pet->refresh();
}

/** A fed row at an instant (child meal by default). */
function hfFed(Pet $pet, string $utc, ActivityType $type = ActivityType::FedPet): void
{
    $row = ActivityLog::create(['pet_id' => $pet->id, 'actor_user_id' => $type === ActivityType::FedPet ? $pet->user_id : null, 'activity_type' => $type->value, 'value' => 50]);
    ActivityLog::whereKey($row->id)->toBase()->update(['created_at' => Carbon::parse($utc, 'UTC')]);
}

/**
 * Run the game loop (decay tick + escalation) every $step minutes up to $untilUtc.
 * Returns the lowest stored and displayed hunger seen and the minutes at which
 * the displayed value first reached 0.
 *
 * @return array{min: float, minShown: int, zeroAt: string|null}
 */
function hfRun(Pet $pet, string $untilUtc, int $step = 1): array
{
    $until = Carbon::parse($untilUtc, 'UTC');
    $decay = app(PetDecayService::class);
    $escalation = app(EscalationService::class);
    $min = 100.0;
    $minShown = 100;
    $zeroAt = null;

    while (now()->lessThan($until)) {
        $next = now()->copy()->addMinutes($step);
        Carbon::setTestNow($next->greaterThan($until) ? $until : $next);
        $decay->processPetDecay($pet);
        $escalation->processPetEscalation($pet->fresh());
        $pet->refresh();
        $min = min($min, (float) $pet->hunger_level);
        $minShown = min($minShown, $pet->displayMetric('hunger_level'));
        if ($zeroAt === null && $pet->displayMetric('hunger_level') <= 0) {
            $zeroAt = now()->format('Y-m-d H:i');
        }
    }

    return ['min' => $min, 'minShown' => $minShown, 'zeroAt' => $zeroAt];
}

function hfPushes(Pet $pet, PushType $type, ?string $metric = 'hunger'): int
{
    return PushNotification::where('pet_id', $pet->id)->where('type', $type->value)
        ->when($metric !== null, fn ($q) => $q->where('metric', $metric))->count();
}

function hfWindows(Pet $pet, string $date): array
{
    $pet = $pet->fresh();

    return array_map(
        fn (array $w): string => $w[0]->format('H:i').'-'.$w[1]->format('H:i'),
        app(CareScheduleService::class)->feedWindowsStartingOn($pet, $pet->breedConfig(), $date),
    );
}

describe('David\'s case — French Bulldog puppy, 6 months, a day without school', function () {
    it('stays at 25 % from the fed morning meal until 17:00, without a zero clock or a parent alarm; then decays', function () {
        // Born on Saturday; Sunday 2026-11-15 07:00 Ljubljana = 06:00 UTC.
        [$pet, , $parent] = hfPet('2026-11-14 12:00:00');
        expect(hfWindows($pet, '2026-11-15'))->toBe(['06:00-10:00', '17:00-21:00'])
            ->and((float) $pet->breedConfig()->hunger_decay_rate)->toBe(12.0)
            ->and($pet->isFrozen())->toBeFalse();

        hfAt('2026-11-15 06:00:00');
        hfSet($pet, ['hunger_level' => 60, 'thirst_level' => 100]);
        $this->postJson('/api/child/pet/feed')->assertOk()->assertJsonPath('feed_mode', 'window');

        // Minute by minute to 16:59 Ljubljana. Water is topped up so only hunger speaks.
        $low = ['min' => 100.0, 'minShown' => 100, 'zeroAt' => null];
        foreach (['2026-11-15 10:00:00', '2026-11-15 13:00:00', '2026-11-15 15:59:00'] as $until) {
            $part = hfRun($pet, $until);
            $low = ['min' => min($low['min'], $part['min']), 'minShown' => min($low['minShown'], $part['minShown']), 'zeroAt' => $low['zeroAt'] ?? $part['zeroAt']];
            Pet::whereKey($pet->id)->update(['thirst_level' => 100]);
        }
        $pet->refresh();

        // 100 − 12 %/h reaches 25 % after 6 h 15 min (13:15); from then on it stands still.
        expect($low['min'])->toBe(25.0)
            ->and($low['minShown'])->toBe(25)
            ->and($low['zeroAt'])->toBeNull()
            ->and($pet->hunger_level)->toBe(25.0) // the precise value, not a rounded one
            ->and($pet->hunger_zero_since)->toBeNull()
            ->and($pet->escalation_level)->toBe(1) // one phase-1 reminder at 30 %, nothing above it
            ->and(EscalationService::parentAlarmMetric($pet))->toBeNull()
            ->and(hfPushes($pet, PushType::SoftWarning))->toBe(1)
            ->and(hfPushes($pet, PushType::CriticalAlert))->toBe(0)
            ->and(hfPushes($pet, PushType::ParentAlarm, null))->toBe(0);

        // The app still refuses the meal and names the next one — nothing asks for a refused action.
        $this->getJson('/api/child/pet')->assertOk()
            ->assertJsonPath('pet.hunger_level', 25)
            ->assertJsonPath('feeding.can_feed', false);
        $this->postJson('/api/child/pet/feed')->assertStatus(422);

        // 17:00: the window opens, the floor is gone. Not fed → 25 % is empty after 2 h 5 min,
        // the parent alarm follows one hour later — the existing consequences.
        Pet::whereKey($pet->id)->update(['thirst_level' => 100]);
        $after = hfRun($pet, '2026-11-15 16:30:00');
        expect($pet->refresh()->hunger_level)->toEqualWithDelta(19.0, 0.001);
        $this->getJson('/api/child/pet')->assertJsonPath('feeding.can_feed', true);

        Pet::whereKey($pet->id)->update(['thirst_level' => 100]);
        $after = hfRun($pet, '2026-11-15 19:10:00');
        Pet::whereKey($pet->id)->update(['thirst_level' => 100]);
        expect($after['zeroAt'])->toBe('2026-11-15 18:03') // shows 0 % below 0.5
            ->and($pet->refresh()->hunger_zero_since)->not->toBeNull()
            ->and(hfPushes($pet, PushType::ParentAlarm, null))->toBe(1)
            ->and(EscalationService::parentAlarmMetric($pet))->toBe('hunger');
    });

    it('feeding in the evening window brings the floor back for the night', function () {
        // Born on Sunday before the morning window (birth-day grace: no walk illness on Monday).
        [$pet] = hfPet('2026-11-15 03:00:00');
        hfAt('2026-11-15 16:30:00'); // 17:30 Ljubljana, window open
        hfSet($pet, ['hunger_level' => 19, 'thirst_level' => 100]);
        $this->postJson('/api/child/pet/feed')->assertOk();

        hfSet($pet, ['hunger_level' => 27]);
        $low = hfRun($pet, '2026-11-16 04:59:00', 7); // to 05:59 on Monday
        expect($low['min'])->toBe(25.0)->and($pet->refresh()->hunger_zero_since)->toBeNull();

        hfRun($pet, '2026-11-16 05:30:00'); // 06:30 — the morning window is open
        expect($pet->refresh()->isFrozen())->toBeFalse();
        expect($pet->refresh()->hunger_level)->toEqualWithDelta(19.0, 0.001);
    });
});

describe('a missed meal has no floor', function () {
    it('goes to 0 % as before, allows the emergency meal and alarms the parent', function () {
        [$pet] = hfPet('2026-11-14 12:00:00');
        hfAt('2026-11-15 09:00:00'); // 10:00 Ljubljana: the morning window just ended unfed
        hfSet($pet, ['hunger_level' => 100, 'thirst_level' => 100]);

        $run = hfRun($pet, '2026-11-15 12:20:00'); // 200 min → 60 %
        expect($pet->refresh()->hunger_level)->toEqualWithDelta(60.0, 0.001);
        Pet::whereKey($pet->id)->update(['thirst_level' => 100]);

        $run = hfRun($pet, '2026-11-15 15:40:00'); // 400 min → 20 %
        expect($pet->refresh()->displayMetric('hunger_level'))->toBe(20);
        $this->getJson('/api/child/pet')->assertJsonPath('feeding.can_feed', true)->assertJsonPath('feeding.feed_mode', 'emergency');
        Pet::whereKey($pet->id)->update(['thirst_level' => 100]);

        // 16:00 UTC = 17:00 Ljubljana: the evening window opens; still unfed → 0 % at 500 min.
        $run = hfRun($pet, '2026-11-15 18:30:00');
        Pet::whereKey($pet->id)->update(['thirst_level' => 100]);
        expect($run['zeroAt'])->toBe('2026-11-15 17:18') // 500 min = 17:20; shows 0 % below 0.5
            ->and($pet->refresh()->hunger_zero_since)->not->toBeNull()
            ->and(hfPushes($pet, PushType::ParentAlarm, null))->toBe(1);
    });

    it('holds again after the emergency meal (the child can do nothing more until the next window)', function () {
        [$pet] = hfPet('2026-11-14 12:00:00');
        hfAt('2026-11-15 10:00:00'); // 11:00 Ljubljana, morning window missed
        hfSet($pet, ['hunger_level' => 15, 'thirst_level' => 100]);
        $this->postJson('/api/child/pet/feed')->assertOk()->assertJsonPath('feed_mode', 'emergency');

        hfSet($pet, ['hunger_level' => 30]);
        $low = hfRun($pet, '2026-11-15 15:59:00', 3);
        expect($low['min'])->toBe(25.0);
        $this->getJson('/api/child/pet')->assertJsonPath('feeding.can_feed', false);
    });

    it('an emergency meal that covers the next window keeps the floor through that window', function () {
        // Born on Sunday before the (missed) morning window; birth-day grace: no walk illness on Monday.
        [$pet] = hfPet('2026-11-15 03:00:00');
        hfAt('2026-11-15 14:30:00'); // 15:30 Ljubljana — within 2 h of the 17:00 window
        hfSet($pet, ['hunger_level' => 10, 'thirst_level' => 100]);
        $this->postJson('/api/child/pet/feed')->assertOk()->assertJsonPath('feed_mode', 'emergency');

        // The 17–21 window counts as fed: the floor holds through it and the night …
        hfSet($pet, ['hunger_level' => 28]);
        $low = hfRun($pet, '2026-11-16 04:59:00', 7);
        expect($low['min'])->toBe(25.0);

        // … and ends when Monday's morning window opens.
        hfRun($pet, '2026-11-16 06:00:00');
        expect($pet->refresh()->isFrozen())->toBeFalse();
        expect($pet->refresh()->hunger_level)->toEqualWithDelta(13.0, 0.001);
    });
});

describe('who fed and which clock', function () {
    it('a parent meal in a quiet-hours window counts', function () {
        // Mutt puppy, 2 months: 07–09, 11–13, 15–17, 19–21; school 08–13 → 11–13 is the parent's.
        $quiet = ['school_start' => '08:00', 'school_end' => '13:00', 'bedtime_start' => '22:00', 'bedtime_end' => '06:00'];
        $make = fn () => hfPet('2026-11-14 12:00:00', ['breed_type' => 'mutt', 'arrival_age_months' => 2], $quiet)[0];

        // Monday 2026-11-16 (a school day). The child missed 07–09.
        $fedByParent = $make();
        hfAt('2026-11-16 09:59:00'); // 10:59 Ljubljana
        hfSet($fedByParent, ['hunger_level' => 50, 'thirst_level' => 100]);
        hfRun($fedByParent, '2026-11-16 10:01:00'); // the tick feeds at 11:00
        expect(hfWindows($fedByParent, '2026-11-16'))->toBe(['07:00-09:00', '11:00-13:00', '15:00-17:00', '19:00-21:00']);
        expect(ActivityLog::where('pet_id', $fedByParent->id)->where('activity_type', ActivityType::ParentFedPet->value)->count())->toBe(1);

        hfAt('2026-11-16 12:00:00'); // 13:00, school over
        hfSet($fedByParent, ['hunger_level' => 27, 'thirst_level' => 100]);
        $low = hfRun($fedByParent, '2026-11-16 13:59:00');
        expect($low['min'])->toBe(25.0);
        hfRun($fedByParent, '2026-11-16 14:30:00'); // 15:30 — the child's window is open
        expect($fedByParent->refresh()->hunger_level)->toEqualWithDelta(21.0, 0.001); // mutt −8 %/h

        // Without the parent meal (a hard stop over 11:00 skipped it) the missed 07–09 has no floor.
        $unfed = $make();
        hfAt('2026-11-16 12:00:00');
        hfSet($unfed, ['hunger_level' => 27, 'thirst_level' => 100]);
        hfRun($unfed, '2026-11-16 13:59:00');
        expect($unfed->refresh()->hunger_level)->toBeLessThan(12.0);
    });

    it('a tick gap over the window opening floors up to the opening and decays after it', function () {
        [$late] = hfPet('2026-11-14 12:00:00');
        [$live] = hfPet('2026-11-14 12:00:00');
        foreach ([$late, $live] as $pet) {
            hfFed($pet, '2026-11-15 06:00:00');
            hfAt('2026-11-15 13:00:00'); // 14:00 Ljubljana
            hfSet($pet, ['hunger_level' => 40, 'thirst_level' => 100]);
        }

        hfRun($live, '2026-11-15 17:00:00');
        hfAt('2026-11-15 17:00:00'); // one tick four hours later (scheduler outage), 18:00 Ljubljana
        app(PetDecayService::class)->processPetDecay($late);

        // 40 → 25 at 15:15, held to 17:00, then one hour at −12 %.
        expect($late->refresh()->hunger_level)->toEqualWithDelta(13.0, 0.001)
            ->and($live->refresh()->hunger_level)->toEqualWithDelta(13.0, 0.001);
    });

    it('uses the pet\'s own time zone, not the family\'s', function () {
        [$pet] = hfPet('2026-11-14 12:00:00', ['timezone' => 'America/New_York']);
        expect($pet->effectiveTimezone())->toBe('America/New_York');

        // 07:00 in New York (EST = UTC−5) = 12:00 UTC: fed in the pet's morning window.
        hfFed($pet, '2026-11-15 12:00:00');
        hfAt('2026-11-15 15:00:00'); // 16:00 Ljubljana, 10:00 New York
        hfSet($pet, ['hunger_level' => 27, 'thirst_level' => 100]);

        // 17:00 Ljubljana passes without effect — the pet's window opens at 17:00 New York = 22:00 UTC.
        $low = hfRun($pet, '2026-11-15 21:59:00', 3);
        expect($low['min'])->toBe(25.0)->and($pet->refresh()->hunger_level)->toBe(25.0);

        hfRun($pet, '2026-11-15 22:30:00');
        expect($pet->refresh()->hunger_level)->toEqualWithDelta(19.0, 0.001);
    });

    it('holds for a legacy-profile pet on its breed windows', function () {
        [$pet] = hfPet('2026-11-14 12:00:00', ['breed_type' => 'border_collie', 'arrival_age_months' => null]);
        expect($pet->isLegacyProfile())->toBeTrue()
            ->and(hfWindows($pet, '2026-11-15'))->toBe(['06:00-10:00', '17:00-21:00']);

        hfAt('2026-11-15 06:00:00');
        hfSet($pet, ['hunger_level' => 50, 'thirst_level' => 100]);
        $this->postJson('/api/child/pet/feed')->assertOk();

        $low = hfRun($pet, '2026-11-15 15:59:00', 3);
        expect($low['min'])->toBe(25.0)->and($pet->refresh()->hunger_zero_since)->toBeNull();
        hfRun($pet, '2026-11-15 17:00:00');
        expect($pet->refresh()->hunger_level)->toEqualWithDelta(13.0, 0.05);
    });

    it('holds for a cat between its meals', function () {
        [$pet] = hfPet('2026-11-14 12:00:00', ['breed_type' => 'domestic_cat', 'arrival_age_months' => 12]);
        expect($pet->isCat())->toBeTrue();
        $schedule = app(CareScheduleService::class);
        $config = $pet->breedConfig();
        $rate = (float) $config->hunger_decay_rate;

        // Feed in the first window of Sunday, then follow the cat to the next window's start.
        [$first, $second] = $schedule->feedWindowsStartingOn($pet, $config, '2026-11-15');
        hfFed($pet, $first[0]->copy()->utc()->addMinutes(5)->toDateTimeString());
        Carbon::setTestNow($second[0]->copy()->utc()->subMinutes(90));
        hfSet($pet, ['hunger_level' => 27, 'thirst_level' => 100]);

        $low = hfRun($pet, $second[0]->copy()->utc()->subMinute()->toDateTimeString());
        expect($low['min'])->toBe(25.0);

        hfRun($pet, $second[0]->copy()->utc()->addMinutes(30)->toDateTimeString());
        expect($pet->refresh()->hunger_level)->toEqualWithDelta(25.0 - $rate / 2, 0.05);
    });
});

describe('edges', function () {
    it('a pet that was just born is held until its first window opens', function () {
        // Born at 11:00 Ljubljana (after the morning window): nothing was missed.
        [$pet] = hfPet('2026-11-15 10:00:00');
        hfSet($pet, ['hunger_level' => 100, 'thirst_level' => 100]);
        $low = hfRun($pet, '2026-11-15 15:59:00', 3);
        expect($low['min'])->toBeGreaterThanOrEqual(25.0);

        hfSet($pet, ['hunger_level' => 25.1]);
        hfRun($pet, '2026-11-15 16:30:00');
        expect($pet->refresh()->hunger_level)->toEqualWithDelta(19.0, 0.001);
    });

    it('never raises a value that is already below the floor — it only stops falling', function () {
        [$pet] = hfPet('2026-11-14 12:00:00');
        hfFed($pet, '2026-11-15 06:00:00');
        hfAt('2026-11-15 12:00:00');
        hfSet($pet, ['hunger_level' => 9.4, 'thirst_level' => 100]); // e.g. a pet that sank before the deploy

        hfRun($pet, '2026-11-15 15:59:00', 3);
        expect($pet->refresh()->hunger_level)->toBe(9.4);
    });

    it('a frozen pet neither decays nor loses the floor afterwards', function () {
        [$pet] = hfPet('2026-11-14 12:00:00');
        hfFed($pet, '2026-11-15 06:00:00');
        hfAt('2026-11-15 12:00:00'); // 13:00 Ljubljana
        hfSet($pet, ['hunger_level' => 26, 'thirst_level' => 100]);

        $pet->update(['is_hard_stopped' => true]);
        hfRun($pet, '2026-11-15 15:00:00', 5);
        expect($pet->refresh()->hunger_level)->toBe(26.0);

        $pet->update(['is_hard_stopped' => false]);
        hfRun($pet, '2026-11-15 15:59:00');
        expect($pet->refresh()->hunger_level)->toBe(25.0);

        // A window that opens during a freeze ends the floor all the same once the pet thaws.
        $pet->update(['is_hard_stopped' => true]);
        hfRun($pet, '2026-11-15 16:30:00', 5);
        $pet->update(['is_hard_stopped' => false]);
        hfRun($pet, '2026-11-15 17:00:00');
        expect($pet->refresh()->hunger_level)->toEqualWithDelta(19.0, 0.001);
    });

    it('is a tunable number: another floor, and 0 switches the rule off', function () {
        [$pet] = hfPet('2026-11-14 12:00:00');
        hfFed($pet, '2026-11-15 06:00:00');
        hfAt('2026-11-15 12:00:00');
        hfSet($pet, ['hunger_level' => 45, 'thirst_level' => 100]);

        config(['feeding.hunger_floor_percent' => 40]);
        hfRun($pet, '2026-11-15 13:00:00');
        expect($pet->refresh()->hunger_level)->toBe(40.0);

        config(['feeding.hunger_floor_percent' => 0]);
        hfRun($pet, '2026-11-15 14:00:00');
        expect($pet->refresh()->hunger_level)->toEqualWithDelta(28.0, 0.001);
    });

    it('holds exactly while the child cannot feed (same facts as the feed check)', function () {
        // Two days, sampled every 20 minutes, with a fed morning, a missed evening and an emergency meal.
        [$pet] = hfPet('2026-11-14 12:00:00');
        $schedule = app(CareScheduleService::class);
        $meals = [
            '2026-11-15 06:10:00', // Sunday morning window
            '2026-11-16 00:30:00', // emergency meal in the night after the missed evening
            '2026-11-16 15:10:00', // emergency meal that covers Monday's 17:00 window
        ];
        $seen = ['on' => 0, 'off' => 0];

        for ($at = Carbon::parse('2026-11-15 04:00:00', 'UTC'); $at->lessThan(Carbon::parse('2026-11-17 04:00:00', 'UTC')); $at = $at->copy()->addMinutes(20)) {
            Carbon::setTestNow($at);
            while ($meals !== [] && Carbon::parse($meals[0], 'UTC')->lessThanOrEqualTo($at)) {
                hfFed($pet, array_shift($meals));
            }
            $fresh = $pet->fresh();
            $config = $fresh->breedConfig();
            $feeding = $schedule->feeding($fresh, $config, $at);
            $facts = $schedule->hungerFloorFacts($fresh, $config, $at, $at, null);

            expect($schedule->hungerFloorActive($fresh, $facts['governing'], $facts['lastFedAt']))
                ->toBe(! $feeding->windowOpen() && ! $feeding->missedMeal, 'at '.$at->toDateTimeString());
            $seen[! $feeding->windowOpen() && ! $feeding->missedMeal ? 'on' : 'off']++;
        }
        expect($seen['on'])->toBeGreaterThan(20)->and($seen['off'])->toBeGreaterThan(20);
    });
});
