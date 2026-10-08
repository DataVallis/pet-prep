<?php

use App\Enums\ActivityType;
use App\Enums\BreedType;
use App\Enums\CareSessionKind;
use App\Enums\CareSessionStatus;
use App\Enums\HygieneEventKind;
use App\Enums\HygieneEventStatus;
use App\Enums\PushType;
use App\Enums\RoutineType;
use App\Models\ActivityLog;
use App\Models\Pet;
use App\Models\PetCareSession;
use App\Models\PetContract;
use App\Models\PetHygieneEvent;
use App\Models\PushNotification;
use App\Models\User;
use App\Services\CareScoreService;
use App\Services\CatChoreService;
use App\Services\FamilyService;
use App\Services\LitterService;
use App\Services\NotificationService;
use App\Services\Push\ExpoPushClient;
use App\Services\Push\PushCopy;
use App\Services\RoutineLedgerService;
use App\Services\ScratchingService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| M5-R06-05 — cat rules part 2: litter, scratching, grooming
|--------------------------------------------------------------------------
| CAT_SPEC Q2 / Q3 / Q8 / Q10, §4, §6, §7; M5-R06_PLAN T6 / T7. David
| 2026-10-08 ~22:20: scratching resolve 2 h like dog chewing (then the
| ladder); matted coat = ≥ 2 of a program week's 3 groomings missed, counted
| at the weekly birthday; matted has no penalty (visible, the next grooming
| is ~2× and resolves it, only the missed routines count). Overdue weekly
| change → 2 h scoop deadline until changed.
| Every wall-clock rule in Europe/Ljubljana, incl. the DST change on
| 25 Oct 2026 (03:00 CEST → 02:00 CET). Quiet hours: bedtime 21–07.
*/

afterEach(function () {
    Carbon::setTestNow();
});

function ccAt(string $local): void
{
    Carbon::setTestNow(Carbon::parse($local, 'Europe/Ljubljana')->utc());
}

/**
 * A family with a cat born at $bornLocal (12 months = young cat; 2 / 3 =
 * kitten). Random litter uses and scratching are switched off — tests plant
 * what they need (ccUse) or switch them back on.
 *
 * @return array{0: User, 1: User, 2: Pet}
 */
function ccFamily(string $bornLocal = '2026-10-20 08:00', BreedType $breed = BreedType::DomesticCat, int $arrival = 12, array $attributes = []): array
{
    seedLifeStageData();
    ccAt($bornLocal);
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => 'Mia']);
    setQuietHours(['parent_id' => $parent->id, 'bedtime_start' => '21:00', 'bedtime_end' => '07:00']);
    if ($breed === BreedType::MaineCoon) {
        $attributes += ['plan' => 'challenge', 'challenge_paid_at' => now(), 'challenge_paid_source' => 'purchase'];
    }
    $cat = disableHygieneEvents(Pet::factory()->create(array_merge([
        'breed_type' => $breed->value,
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrival,
        'origin' => 'adopted',
    ], $attributes)));

    return [$parent, $child, $cat->fresh()];
}

function ccSibling(User $parent, Pet $pet, string $name = 'Tim'): User
{
    $child = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => $name]);
    app(FamilyService::class)->addCaretaker($pet, $child, true);
    PetContract::create(['pet_id' => $pet->id, 'user_id' => $child->id, 'signature_format' => 'svg_path', 'signature' => 'M1 1 L2 2', 'signed_at' => now()]);

    return $child;
}

/** Plant a pending litter use at a family-local time. */
function ccUse(Pet $pet, string $local): PetHygieneEvent
{
    $at = Carbon::parse($local, 'Europe/Ljubljana')->utc();

    return PetHygieneEvent::create([
        'pet_id' => $pet->id,
        'kind' => HygieneEventKind::LitterUse,
        'local_date' => $pet->localDate($at),
        'scheduled_at' => $at,
        'status' => HygieneEventStatus::Pending,
    ]);
}

/** One tick at $local; food and water topped up first (these tests are not about meals). */
function ccTick(?string $local = null): void
{
    if ($local !== null) {
        ccAt($local);
    }
    DB::table('pets')->update(['hunger_level' => 100, 'thirst_level' => 100, 'hunger_zero_since' => null, 'thirst_zero_since' => null]);
    Artisan::call('pets:process-decay');
}

/** A tick a minute before and a minute after $local (no scheduler "outage" in between). */
function ccTickAround(string $local): void
{
    $t = Carbon::parse($local, 'Europe/Ljubljana');
    ccTick($t->copy()->subMinute()->format('Y-m-d H:i'));
    ccTick($t->copy()->addMinute()->format('Y-m-d H:i'));
}

/** Hourly ticks from $fromLocal to $toLocal (inclusive). */
function ccTickHourly(string $fromLocal, string $toLocal): void
{
    $end = Carbon::parse($toLocal, 'Europe/Ljubljana');
    for ($t = Carbon::parse($fromLocal, 'Europe/Ljubljana'); $t->lessThanOrEqualTo($end); $t->addHour()) {
        ccTick($t->format('Y-m-d H:i'));
    }
}

function ccPost(User $child, string $uri, array $body = [])
{
    test()->actingAs($child, 'sanctum');
    $response = test()->postJson($uri, $body);
    app('auth')->forgetGuards();

    return $response;
}

function ccState(User $child): array
{
    test()->actingAs($child, 'sanctum');
    $json = test()->getJson('/api/child/pet')->assertOk()->json();
    app('auth')->forgetGuards();

    return $json;
}

/** Strokes of a child who really rubbed: every ~1.3 s with human gaps over the whole session. */
function ccStrokes(int $durationMs = 30000, int $count = 18): array
{
    $strokes = [];
    for ($i = 0; $i < $count; $i++) {
        $strokes[] = ['t' => min($durationMs, 300 + intdiv($i * ($durationMs - 600), max(1, $count - 1)) + ($i * 137) % 250)];
    }

    return $strokes;
}

/** Start + finish a chore ($kind: litter-change | grooming) with good strokes; returns the finish response. */
function ccChore(User $child, string $kind, ?array $strokes = null)
{
    $session = ccPost($child, "/api/child/pet/{$kind}/start")->assertOk()->json('session');
    Carbon::setTestNow(now()->addMilliseconds($session['duration_ms']));

    return ccPost($child, "/api/child/pet/{$kind}/finish", ['session_id' => $session['id'], 'strokes' => $strokes ?? ccStrokes($session['duration_ms'], $session['min_strokes'] + 6)]);
}

/** @return list<array{0: string, 1: string, 2: int, 3: string, 4: int|null}> [date, type, slot, status, actor] */
function ccRoutines(Pet $cat, string $from, string $to, array $types): array
{
    $routines = app(RoutineLedgerService::class)->routinesFor(collect([$cat->fresh()]), $from, $to)[$cat->id] ?? [];

    return array_values(array_map(
        fn ($r) => [$r->localDate, $r->type->value, $r->slot, $r->status->value, $r->actorUserId],
        array_filter($routines, fn ($r) => in_array($r->type, $types, true)),
    ));
}

describe('litter uses (CAT_SPEC Q3): scheduled like poops, not a mess', function () {
    it('schedules 2 uses a day for a grown cat and 3 for a kitten, all outside quiet hours; a dog gets none', function () {
        foreach ([[12, 2], [2, 3]] as [$arrival, $expected]) {
            [, , $cat] = ccFamily('2026-10-20 08:00', BreedType::DomesticCat, $arrival);
            Pet::whereKey($cat->id)->update(['hygiene_scheduled_through' => null]);
            ccTick('2026-10-21 00:05');

            $uses = PetHygieneEvent::where('pet_id', $cat->id)->where('local_date', '2026-10-21')->orderBy('scheduled_at')->get();
            expect($uses)->toHaveCount($expected)
                ->and($uses->every(fn ($e) => $e->kind === HygieneEventKind::LitterUse))->toBeTrue()
                ->and(PetHygieneEvent::where('pet_id', $cat->id)->where('kind', 'poop')->count())->toBe(0);
            foreach ($uses as $use) {
                $hour = (int) $use->scheduled_at->copy()->setTimezone('Europe/Ljubljana')->format('G');
                expect($hour)->toBeGreaterThanOrEqual(7)->toBeLessThan(21);
            }
        }

        // A profiled dog: poops only, no litter.
        [, , $cat] = ccFamily();
        $dog = Pet::factory()->create(['breed_type' => 'mutt', 'user_id' => User::factory()->child()->create()->id, 'born_at' => now(), 'arrival_age_months' => 2]);
        ccTick('2026-10-21 00:05');
        expect(PetHygieneEvent::where('pet_id', $dog->id)->where('kind', 'litter_use')->count())->toBe(0)
            ->and(PetHygieneEvent::where('pet_id', $dog->id)->where('kind', 'poop')->count())->toBeGreaterThan(0);
    });

    it('applies a use without touching hygiene and fixes its 4 h scoop deadline', function () {
        [, $child, $cat] = ccFamily();
        $use = ccUse($cat, '2026-10-21 10:00');
        ccTickAround('2026-10-21 10:00');

        $use->refresh();
        expect($use->status)->toBe(HygieneEventStatus::Applied)
            ->and($use->due_at->toIso8601String())->toBe('2026-10-21T12:00:00+00:00') // 14:00 CEST
            ->and($cat->fresh()->displayMetric('hygiene_level'))->toBe(100);

        $state = ccState($child);
        expect($state['litter']['open_uses'])->toHaveCount(1)
            ->and($state['litter']['open_uses'][0]['due_at'])->toBe('2026-10-21T14:00:00+02:00')
            ->and($state['litter']['open_uses'][0]['expired'])->toBeFalse()
            ->and($state['litter']['next_due_at'])->toBe('2026-10-21T14:00:00+02:00')
            ->and($state['litter']['scoop_deadline_hours'])->toEqual(4)
            ->and($state['litter']['uses_per_day'])->toBe(2)
            ->and($state['litter']['can_scoop'])->toBeTrue()
            ->and($state['behaviour']['active_events'])->toBe([])
            ->and($state['grooming'])->toBeNull() // domestic cat: no grooming routine
            ->and($state['scratching']['active'])->toBeNull();
    });
});

describe('scoop deadline → litter accident on the dog ladder (CAT_SPEC Q3)', function () {
    it('counts the 4 h only outside quiet hours and writes the mess next to the tray at the deadline, then the illness after 6 h', function () {
        [, $child, $cat] = ccFamily();
        $use = ccUse($cat, '2026-10-21 18:00');
        ccTickAround('2026-10-21 18:00');
        // 3 h until 21:00, the night is quiet, 1 h after 07:00 → 08:00 next morning.
        expect($use->fresh()->due_at->copy()->setTimezone('Europe/Ljubljana')->format('Y-m-d H:i'))->toBe('2026-10-22 08:00');

        ccTick('2026-10-21 21:30');
        ccTick('2026-10-22 07:30');
        ccTick('2026-10-22 07:59');
        expect(PetHygieneEvent::where('pet_id', $cat->id)->where('kind', 'litter_accident')->count())->toBe(0)
            ->and($cat->fresh()->displayMetric('hygiene_level'))->toBe(100);

        ccTick('2026-10-22 08:01');
        $cat->refresh();
        $accident = PetHygieneEvent::where('pet_id', $cat->id)->where('kind', 'litter_accident')->sole();
        expect($accident->status)->toBe(HygieneEventStatus::Applied)
            ->and($accident->scheduled_at->equalTo($use->fresh()->due_at))->toBeTrue()
            ->and($cat->displayMetric('hygiene_level'))->toBe(0)
            ->and($cat->hygiene_zero_since->equalTo($accident->scheduled_at))->toBeTrue()
            ->and(ActivityLog::where('pet_id', $cat->id)->where('activity_type', ActivityType::PetLitterAccident->value)->whereNull('actor_user_id')->count())->toBe(1);

        $state = ccState($child);
        expect(array_column($state['behaviour']['active_events'], 'kind'))->toBe(['litter_accident'])
            ->and($state['behaviour']['scene'])->toBeNull() // no video for it: the app shows an icon
            ->and($state['litter']['open_uses'][0]['expired'])->toBeTrue()
            ->and($state['wand']['blocked_reason'])->toBe('needs_cleaning');

        expect(ccRoutines($cat, '2026-10-21', '2026-10-22', [RoutineType::LitterScoop]))->toBe([['2026-10-21', 'litter_scoop', 0, 'missed', null]]);

        // The existing ladder: illness after 6 h of hygiene 0 outside quiet hours.
        ccTickHourly('2026-10-22 09:00', '2026-10-22 13:00');
        expect($cat->fresh()->isIll())->toBeFalse();
        ccTick('2026-10-22 14:01');
        expect($cat->fresh()->isIll())->toBeTrue()
            ->and(PetHygieneEvent::where('pet_id', $cat->id)->where('kind', 'litter_accident')->count())->toBe(1);
    });

    it('scoops in time: the routine is done by the child, nothing happens at the deadline; a repeat is unchanged', function () {
        [$parent, $child, $cat] = ccFamily();
        $sibling = ccSibling($parent, $cat);
        ccUse($cat, '2026-10-21 09:00');
        ccUse($cat, '2026-10-21 10:00');
        ccTickAround('2026-10-21 10:00');

        ccAt('2026-10-21 11:00');
        ccPost($child, '/api/child/pet/litter/scoop')->assertOk()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('scooped', 2)
            ->assertJsonPath('state.litter.open_uses', [])
            ->assertJsonPath('state.litter.can_scoop', false);
        ccPost($sibling, '/api/child/pet/litter/scoop')->assertOk()->assertJsonPath('status', 'unchanged');

        ccTickAround('2026-10-21 14:00');
        $scoop = ActivityLog::where('pet_id', $cat->id)->where('activity_type', 'scooped_litter')->sole();
        expect(PetHygieneEvent::where('pet_id', $cat->id)->where('kind', 'litter_accident')->count())->toBe(0)
            ->and($scoop->actor_user_id)->toBe($child->id)
            ->and((int) $scoop->value)->toBe(2);

        expect(ccRoutines($cat, '2026-10-21', '2026-10-21', [RoutineType::LitterScoop]))->toBe([
            ['2026-10-21', 'litter_scoop', 0, 'done', $child->id],
            ['2026-10-21', 'litter_scoop', 1, 'done', $child->id],
        ]);

        // Fair share: the scoops count for the child who scooped, not the sibling.
        $scores = app(CareScoreService::class);
        $board = $scores->board(collect([$cat->fresh()]), 'Europe/Ljubljana');
        foreach ($board['routines'][$cat->id] as $r) {
            if ($r->type === RoutineType::LitterScoop) {
                expect($scores->credited($board, $cat, $r, $child->id, 2))->toBeTrue()
                    ->and($scores->credited($board, $cat, $r, $sibling->id, 2))->toBeFalse();
            }
        }
    });

    it('cleaning the litter accident also scoops the tray (CAT_SPEC §4)', function () {
        [, $child, $cat] = ccFamily();
        $use = ccUse($cat, '2026-10-21 10:00');
        ccTickAround('2026-10-21 10:00');
        ccTickAround('2026-10-21 14:00');
        expect($cat->fresh()->displayMetric('hygiene_level'))->toBe(0);

        ccAt('2026-10-21 14:30');
        ccPost($child, '/api/child/pet/clean')->assertOk()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('state.pet.hygiene_level', 100)
            ->assertJsonPath('state.litter.open_uses', []);
        expect($use->fresh()->cleaned_at)->not->toBeNull();

        ccTick('2026-10-21 15:00');
        expect(ccRoutines($cat, '2026-10-21', '2026-10-21', [RoutineType::LitterScoop, RoutineType::Clean]))->toBe([
            ['2026-10-21', 'litter_scoop', 0, 'missed', null],
            ['2026-10-21', 'clean', 0, 'done', $child->id],
        ]);
    });

    it('writes no accident for a deadline that passed while the cat was hard-stopped', function () {
        [$parent, , $cat] = ccFamily();
        ccUse($cat, '2026-10-21 10:00');
        ccTickAround('2026-10-21 10:00');

        ccAt('2026-10-21 12:00');
        $cat->refresh()->update(['is_hard_stopped' => true]);
        ccTick('2026-10-21 14:30');
        ccAt('2026-10-21 15:00');
        $cat->refresh()->update(['is_hard_stopped' => false]);
        ccTick('2026-10-21 15:01');

        expect(PetHygieneEvent::where('pet_id', $cat->id)->where('kind', 'litter_accident')->count())->toBe(0)
            ->and(PetHygieneEvent::where('pet_id', $cat->id)->where('kind', 'litter_use')->sole()->escalated_at)->not->toBeNull()
            ->and($cat->fresh()->displayMetric('hygiene_level'))->toBe(100);
    });

    it('refuses the scoop for a dog with 422 litter_not_available and keeps dog payloads free of cat blocks', function () {
        seedLifeStageData();
        $child = User::factory()->child()->create();
        Pet::factory()->create(['breed_type' => 'mutt', 'user_id' => $child->id, 'arrival_age_months' => 12]);

        ccPost($child, '/api/child/pet/litter/scoop')->assertStatus(422)->assertJsonPath('reason', 'litter_not_available')
            ->assertJsonPath('state.litter', null)
            ->assertJsonPath('state.grooming', null)
            ->assertJsonPath('state.scratching', null);
        ccPost($child, '/api/child/pet/litter-change/start')->assertStatus(422)->assertJsonPath('reason', 'litter_not_available');
        ccPost($child, '/api/child/pet/grooming/start')->assertStatus(422)->assertJsonPath('reason', 'grooming_not_available');
        ccPost($child, '/api/child/pet/scratching/start')->assertStatus(422)->assertJsonPath('reason', 'scratching_not_needed');
    });
});

describe('weekly full litter change (CAT_SPEC Q3, program week)', function () {
    it('is one routine per program week: done by the server-scored session, a second one is refused until next week', function () {
        [, $child, $cat] = ccFamily('2026-10-20 08:00');

        ccAt('2026-10-21 10:00');
        $state = ccState($child);
        expect($state['litter']['change'])->toMatchArray([
            'week_started_at' => '2026-10-20T08:00:00+02:00',
            // DST: the weekly birthday keeps the wall-clock hour (CET from 25 Oct).
            'due_at' => '2026-10-27T08:00:00+01:00',
            'done' => false, 'overdue' => false, 'blocked_reason' => null, 'can_start' => true,
        ]);

        // Not enough strokes → rejected, nothing counts, try again at once.
        $first = ccPost($child, '/api/child/pet/litter-change/start')->assertOk()->json('session');
        expect($first)->toMatchArray(['kind' => 'litter_change', 'duration_ms' => 30000, 'min_strokes' => 10, 'segments' => 3, 'matted' => false]);
        Carbon::setTestNow(now()->addSeconds(30));
        ccPost($child, '/api/child/pet/litter-change/finish', ['session_id' => $first['id'], 'strokes' => [['t' => 100], ['t' => 900]]])
            ->assertOk()->assertJsonPath('status', 'rejected')->assertJsonPath('result.reason', 'too_few_strokes');

        ccUse($cat, '2026-10-21 10:30');
        ccTickAround('2026-10-21 10:30');
        ccAt('2026-10-21 11:00');
        ccChore($child, 'litter-change')->assertOk()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('result.success', true)
            ->assertJsonPath('state.litter.change.done', true)
            ->assertJsonPath('state.litter.change.blocked_reason', 'litter_change_done')
            // "Dump everything": the open use is scooped too.
            ->assertJsonPath('state.litter.open_uses', []);
        expect(ActivityLog::where('pet_id', $cat->id)->where('activity_type', 'changed_litter')->sole()->actor_user_id)->toBe($child->id);

        ccAt('2026-10-23 10:00');
        ccPost($child, '/api/child/pet/litter-change/start')->assertStatus(422)
            ->assertJsonPath('reason', 'litter_change_done')
            ->assertJsonPath('next_allowed_at', '2026-10-27T08:00:00+01:00');

        // The week's routine sits on the day the week ends.
        ccTickHourly('2026-10-26 22:00', '2026-10-27 09:00');
        expect(ccRoutines($cat, '2026-10-20', '2026-10-27', [RoutineType::LitterChange]))->toBe([['2026-10-27', 'litter_change', 0, 'done', $child->id]]);
    });

    it('a missed week makes the next scoop deadlines 2 h until the litter is changed (David 2026-10-08)', function () {
        [, $child, $cat] = ccFamily('2026-10-20 08:00');
        ccTickHourly('2026-10-26 22:00', '2026-10-27 09:00');
        expect(ccRoutines($cat, '2026-10-27', '2026-10-27', [RoutineType::LitterChange]))->toBe([['2026-10-27', 'litter_change', 0, 'missed', null]]);

        $use = ccUse($cat, '2026-10-27 10:00');
        ccTickAround('2026-10-27 10:00');
        expect($use->fresh()->due_at->copy()->setTimezone('Europe/Ljubljana')->format('H:i'))->toBe('12:00');
        $state = ccState($child);
        expect($state['litter']['change']['overdue'])->toBeTrue()
            ->and($state['litter']['scoop_deadline_hours'])->toEqual(2);

        ccAt('2026-10-27 11:00');
        ccPost($child, '/api/child/pet/litter/scoop')->assertOk()->assertJsonPath('status', 'accepted');
        ccChore($child, 'litter-change')->assertOk()->assertJsonPath('status', 'accepted')
            ->assertJsonPath('state.litter.change.overdue', false)
            ->assertJsonPath('state.litter.scoop_deadline_hours', 4);

        $next = ccUse($cat, '2026-10-27 13:00');
        ccTickAround('2026-10-27 13:00');
        expect($next->fresh()->due_at->copy()->setTimezone('Europe/Ljubljana')->format('H:i'))->toBe('17:00');
    });

    it('is refused while a mess is open and lets only one child change at a time', function () {
        [$parent, $child, $cat] = ccFamily();
        $sibling = ccSibling($parent, $cat);
        ccAt('2026-10-21 10:00');
        ccPost($child, '/api/child/pet/litter-change/start')->assertOk();
        ccPost($sibling, '/api/child/pet/litter-change/start')->assertStatus(422)->assertJsonPath('reason', 'litter_change_session_active');

        Pet::whereKey($cat->id)->update(['hygiene_level' => 0]);
        ccPost($child, '/api/child/pet/litter-change/start')->assertStatus(422)->assertJsonPath('reason', 'needs_cleaning');
    });
});

describe('scratching the day after a missed play (CAT_SPEC Q2 / Q10, David 2026-10-08 ~22:20)', function () {
    /** Born 20 Oct, no play on 21 Oct → one scratching on 22 Oct, moved to 10:00 and applied. */
    function ccScratchedCat(): array
    {
        [$parent, $child, $cat] = ccFamily('2026-10-20 08:00');
        Pet::whereKey($cat->id)->update(['behaviour_scheduled_through' => null]);
        ccTick('2026-10-21 00:05');
        ccTick('2026-10-22 00:05');

        $event = PetHygieneEvent::where('pet_id', $cat->id)->where('kind', 'scratching')->sole();
        $hour = (int) $event->scheduled_at->copy()->setTimezone('Europe/Ljubljana')->format('G');
        expect($event->local_date->toDateString())->toBe('2026-10-22')
            ->and($hour)->toBeGreaterThanOrEqual(7)->toBeLessThan(21)
            ->and($event->status)->toBe(HygieneEventStatus::Pending);
        $event->forceFill(['scheduled_at' => Carbon::parse('2026-10-22 10:00', 'Europe/Ljubljana')->utc()])->save();
        ccTickAround('2026-10-22 10:00');

        return [$parent, $child, $cat->fresh(), $event->fresh()];
    }

    it('scratches once, outside quiet hours, the day after a missed play: hygiene 0, timeline row, scene', function () {
        [, $child, $cat, $event] = ccScratchedCat();

        expect($event->status)->toBe(HygieneEventStatus::Applied)
            ->and($cat->displayMetric('hygiene_level'))->toBe(0)
            ->and(ActivityLog::where('pet_id', $cat->id)->where('activity_type', 'pet_scratched')->whereNull('actor_user_id')->count())->toBe(1);
        $state = ccState($child);
        expect($state['behaviour']['scene'])->toBe('scratching')
            ->and(array_column($state['behaviour']['active_events'], 'kind'))->toBe(['scratching'])
            ->and($state['scratching']['active']['due_at'])->toBe('2026-10-22T12:00:00+02:00')
            ->and($state['scratching']['can_start'])->toBeTrue();

        // Max 1 per day: the next day's tick does not add a second one for the same day.
        ccTick('2026-10-22 18:00');
        expect(PetHygieneEvent::where('pet_id', $cat->id)->where('kind', 'scratching')->count())->toBe(1);
    });

    it('does not scratch after a day whose play routine was done', function () {
        [, $child, $cat] = ccFamily('2026-10-20 08:00');
        Pet::whereKey($cat->id)->update(['behaviour_scheduled_through' => null]);
        ccTick('2026-10-21 00:05');
        foreach (['2026-10-21 09:00', '2026-10-21 12:00'] as $t) {
            ActivityLog::withoutEvents(fn () => (new ActivityLog)->forceFill([
                'pet_id' => $cat->id, 'actor_user_id' => $child->id, 'activity_type' => ActivityType::PlayedWand, 'value' => 1,
                'created_at' => Carbon::parse($t, 'Europe/Ljubljana')->utc(),
            ])->save());
        }
        ccTick('2026-10-22 00:05');

        expect($cat->fresh()->play_missed_on)->toBeNull()
            ->and(PetHygieneEvent::where('pet_id', $cat->id)->where('kind', 'scratching')->count())->toBe(0);
    });

    it('resolves with "carry to the scratcher + praise within 3 s", scored on the server; never a punishment', function () {
        [$parent, $child, $cat] = ccScratchedCat();
        $sibling = ccSibling($parent, $cat);

        ccAt('2026-10-22 10:30');
        $start = ccPost($child, '/api/child/pet/scratching/start')->assertOk()->assertJsonPath('status', 'accepted');
        $session = $start->json('session');
        expect($session['land_at_ms'])->toBeGreaterThanOrEqual(800)->toBeLessThanOrEqual(2000)
            ->and($session['praise_window_ms'])->toBe(3000)
            ->and($session['min_reaction_ms'])->toBe(150)
            ->and($start->json('state.scratching.session.id'))->toBe($session['id']);
        ccPost($sibling, '/api/child/pet/scratching/start')->assertStatus(422)->assertJsonPath('reason', 'scratching_session_active');

        // Before the cat has landed: nothing to praise yet.
        ccPost($child, '/api/child/pet/scratching/finish', ['session_id' => $session['id'], 'praise_ms' => 100])
            ->assertStatus(422)->assertJsonPath('reason', 'care_session_not_over');

        // Too late (4 s after the landing) → rejected, still scratched, try again.
        Carbon::setTestNow(now()->addMilliseconds($session['land_at_ms'] + 4500));
        ccPost($child, '/api/child/pet/scratching/finish', ['session_id' => $session['id'], 'praise_ms' => $session['land_at_ms'] + 4000])
            ->assertOk()->assertJsonPath('status', 'rejected')->assertJsonPath('result.reason', 'too_late')
            ->assertJsonPath('state.pet.hygiene_level', 0);

        // Too early (a reflex tap at the landing) → rejected.
        $again = ccPost($child, '/api/child/pet/scratching/start')->assertOk()->json('session');
        Carbon::setTestNow(now()->addMilliseconds($again['land_at_ms'] + 500));
        ccPost($child, '/api/child/pet/scratching/finish', ['session_id' => $again['id'], 'praise_ms' => $again['land_at_ms'] + 50])
            ->assertOk()->assertJsonPath('result.reason', 'too_early');

        // In time (1.2 s after the landing) → resolved.
        $good = ccPost($child, '/api/child/pet/scratching/start')->assertOk()->json('session');
        Carbon::setTestNow(now()->addMilliseconds($good['land_at_ms'] + 1500));
        ccPost($child, '/api/child/pet/scratching/finish', ['session_id' => $good['id'], 'praise_ms' => $good['land_at_ms'] + 1200])
            ->assertOk()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('result.success', true)
            ->assertJsonPath('result.delay_ms', 1200)
            ->assertJsonPath('state.pet.hygiene_level', 100)
            ->assertJsonPath('state.scratching.active', null)
            ->assertJsonPath('state.behaviour.scene', null);
        // A repeat returns the stored verdict.
        ccPost($child, '/api/child/pet/scratching/finish', ['session_id' => $good['id'], 'praise_ms' => $good['land_at_ms'] + 1200])
            ->assertOk()->assertJsonPath('status', 'unchanged')->assertJsonPath('result.success', true);

        expect(PetCareSession::where('pet_id', $cat->id)->where('kind', 'scratching')->pluck('status')->map->value->sort()->values()->all())
            ->toBe(['completed', 'failed', 'failed']);
        ccTick('2026-10-22 13:00');
        expect(ccRoutines($cat, '2026-10-22', '2026-10-22', [RoutineType::Clean]))->toBe([['2026-10-22', 'clean', 0, 'done', $child->id]]);
    });

    it('unresolved after 2 h outside quiet hours: the clean routine is missed and the ladder runs (like chewing)', function () {
        [, , $cat] = ccScratchedCat();
        ccTickHourly('2026-10-22 11:00', '2026-10-22 16:00');

        expect(ccRoutines($cat, '2026-10-22', '2026-10-22', [RoutineType::Clean]))->toBe([['2026-10-22', 'clean', 0, 'missed', null]])
            ->and($cat->fresh()->isIll())->toBeTrue(); // 6 h of hygiene 0 outside quiet hours (10:00 → 16:00)
    });

    it('a hygiene push for an open scratching points to the scratcher, never to cleaning (M3-12)', function () {
        [, , $cat] = ccScratchedCat();
        $variant = (new ReflectionMethod(NotificationService::class, 'hygieneVariant'))->invoke(app(NotificationService::class), $cat);
        expect($variant)->toBe(PushCopy::VARIANT_SCRATCHER)
            ->and(PushCopy::body(PushType::SoftWarning, 'hygiene', 'child', 'sl', $variant))->toContain('praskalnik')
            ->and(PushCopy::body(PushType::CriticalAlert, 'hygiene', 'child', 'en', $variant))->toContain('scratching post');

        // Plus a mess next to the tray → clean and scratcher.
        PetHygieneEvent::create(['pet_id' => $cat->id, 'kind' => HygieneEventKind::LitterAccident, 'local_date' => '2026-10-22',
            'scheduled_at' => now(), 'status' => HygieneEventStatus::Applied]);
        expect((new ReflectionMethod(NotificationService::class, 'hygieneVariant'))->invoke(app(NotificationService::class), $cat))
            ->toBe(PushCopy::VARIANT_CLEAN_AND_SCRATCHER);
    });
});

describe('Maine Coon grooming (CAT_SPEC Q8) and the matted coat (David 2026-10-08 ~22:20)', function () {
    it('is the Maine Coon\'s routine only: 3 per program week, one per day, not in quiet hours', function () {
        [, $domesticChild] = ccFamily();
        ccAt('2026-10-21 10:00');
        ccPost($domesticChild, '/api/child/pet/grooming/start')->assertStatus(422)->assertJsonPath('reason', 'grooming_not_available');

        [, $child, $cat] = ccFamily('2026-10-20 08:00', BreedType::MaineCoon);
        ccAt('2026-10-21 10:00');
        expect(ccState($child)['grooming'])->toMatchArray([
            'goal_per_week' => 3, 'done_this_week' => 0, 'week_started_at' => '2026-10-20T08:00:00+02:00',
            'week_ends_at' => '2026-10-27T08:00:00+01:00', 'matted' => false, 'session_seconds' => 30, 'can_start' => true,
        ]);
        ccChore($child, 'grooming')->assertOk()->assertJsonPath('status', 'accepted')
            ->assertJsonPath('state.grooming.done_this_week', 1)
            ->assertJsonPath('state.grooming.blocked_reason', 'grooming_done_today')
            // QA n1: the next possible start is after the night's quiet hours, not at midnight.
            ->assertJsonPath('state.grooming.next_allowed_at', '2026-10-22T07:00:00+02:00');
        $groomed = ActivityLog::where('pet_id', $cat->id)->where('activity_type', 'groomed_pet')->sole();
        expect((int) $groomed->value)->toBe(1)->and($groomed->actor_user_id)->toBe($child->id);

        ccAt('2026-10-21 15:00');
        ccPost($child, '/api/child/pet/grooming/start')->assertStatus(422)->assertJsonPath('reason', 'grooming_done_today');
        // The cat sleeps: no grooming in quiet hours, nor one that would run into them.
        ccAt('2026-10-22 20:59');
        ccPost($child, '/api/child/pet/grooming/start')->assertStatus(422)
            ->assertJsonPath('reason', 'grooming_quiet_hours')->assertJsonPath('next_allowed_at', '2026-10-23T07:00:00+02:00');
        ccAt('2026-10-22 10:00');
        ccChore($child, 'grooming')->assertOk()->assertJsonPath('status', 'accepted');
        ccAt('2026-10-23 10:00');
        ccChore($child, 'grooming')->assertOk()->assertJsonPath('state.grooming.done_this_week', 3);
        ccAt('2026-10-24 10:00');
        ccPost($child, '/api/child/pet/grooming/start')->assertStatus(422)
            ->assertJsonPath('reason', 'grooming_week_done')->assertJsonPath('next_allowed_at', '2026-10-27T08:00:00+01:00');

        // Too few strokes in the next week → rejected (no penalty).
        ccAt('2026-10-27 10:00');
        ccChore($child, 'grooming', [['t' => 500]])->assertOk()->assertJsonPath('status', 'rejected');
        ccTickHourly('2026-10-26 23:00', '2026-10-27 09:00');
        expect(ccRoutines($cat, '2026-10-27', '2026-10-27', [RoutineType::Grooming]))->toBe([
            ['2026-10-27', 'grooming', 0, 'done', $child->id],
            ['2026-10-27', 'grooming', 1, 'done', $child->id],
            ['2026-10-27', 'grooming', 2, 'done', $child->id],
        ])->and($cat->fresh()->coat_matted_at)->toBeNull();
    });

    it('mats the coat at the weekly birthday after ≥ 2 of 3 missed — no penalty; the next ~2× grooming resolves it', function () {
        [, $child, $cat] = ccFamily('2026-10-20 08:00', BreedType::MaineCoon);
        ccTick('2026-10-20 09:00');
        ccAt('2026-10-21 10:00');
        ccChore($child, 'grooming')->assertOk()->assertJsonPath('status', 'accepted');

        ccTickHourly('2026-10-26 22:00', '2026-10-27 07:00');
        expect($cat->fresh()->coat_matted_at)->toBeNull();
        ccTick('2026-10-27 08:01');
        $cat->refresh();
        expect($cat->coat_matted_at?->copy()->setTimezone('Europe/Ljubljana')->format('Y-m-d H:i'))->toBe('2026-10-27 08:00')
            ->and(ActivityLog::where('pet_id', $cat->id)->where('activity_type', 'pet_coat_matted')->whereNull('actor_user_id')->count())->toBe(1)
            // No illness, no metric / mood effect — only the missed routines count.
            ->and($cat->isIll())->toBeFalse()
            ->and($cat->displayMetric('hygiene_level'))->toBe(100)
            ->and((int) $cat->escalation_level)->toBe(0);
        expect(ccRoutines($cat, '2026-10-27', '2026-10-27', [RoutineType::Grooming]))->toBe([
            ['2026-10-27', 'grooming', 0, 'done', $child->id],
            ['2026-10-27', 'grooming', 1, 'missed', null],
            ['2026-10-27', 'grooming', 2, 'missed', null],
        ]);

        ccAt('2026-10-27 10:00');
        $state = ccState($child);
        expect($state['grooming'])->toMatchArray(['matted' => true, 'matted_since' => '2026-10-27T08:00:00+01:00', 'session_seconds' => 60]);

        $session = ccPost($child, '/api/child/pet/grooming/start')->assertOk()->json('session');
        expect($session)->toMatchArray(['matted' => true, 'duration_ms' => 60000, 'min_strokes' => 20]);
        Carbon::setTestNow(now()->addSeconds(60));
        ccPost($child, '/api/child/pet/grooming/finish', ['session_id' => $session['id'], 'strokes' => ccStrokes(60000, 26)])
            ->assertOk()->assertJsonPath('status', 'accepted')->assertJsonPath('result.matted', true)
            ->assertJsonPath('state.grooming.matted', false)
            ->assertJsonPath('state.grooming.session_seconds', 30);
        expect($cat->fresh()->coat_matted_at)->toBeNull();

        // Next week: 2 of 3 done (1 missed) → not matted again.
        ccAt('2026-10-28 10:00');
        ccChore($child, 'grooming')->assertOk();
        ccTickHourly('2026-11-02 22:00', '2026-11-03 09:00');
        expect($cat->fresh()->coat_matted_at)->toBeNull()
            ->and(ActivityLog::where('pet_id', $cat->id)->where('activity_type', 'pet_coat_matted')->count())->toBe(1);
    });
});

describe('push: the litter reminder (M3-12 — only what the app allows)', function () {
    beforeEach(function () {
        config(['push.enabled' => true]);
        Queue::fake();
    });

    it('reminds the children once an hour before the scoop deadline and drops it when the tray was scooped', function () {
        [, $child, $cat] = ccFamily();
        ccUse($cat, '2026-10-21 10:00');
        ccTickAround('2026-10-21 10:00');
        ccTick('2026-10-21 12:30');
        expect(PushNotification::where('pet_id', $cat->id)->where('type', 'litter_reminder')->count())->toBe(0);

        ccTick('2026-10-21 13:01');
        ccTick('2026-10-21 13:20');
        $push = PushNotification::where('pet_id', $cat->id)->where('type', PushType::LitterReminder->value)->sole();
        expect($push->metric)->toBe('litter:'.PetHygieneEvent::where('pet_id', $cat->id)->where('kind', 'litter_use')->sole()->id)
            ->and(collect($push->recipients)->pluck('user_id')->all())->toBe([$child->id])
            ->and(PushCopy::body(PushType::LitterReminder, 'litter', 'child', 'sl'))->toContain('pesek');

        ccAt('2026-10-21 13:30');
        ccPost($child, '/api/child/pet/litter/scoop')->assertOk();
        $push->forceFill(['status' => PushNotification::STATUS_QUEUED, 'send_after' => null])->save();
        app(NotificationService::class)->deliver($push->id, app(ExpoPushClient::class));
        expect($push->fresh())->status->toBe(PushNotification::STATUS_SUPPRESSED)->suppressed_reason->toBe('scooped');
    });
});

describe('family timezone and DST (25 Oct 2026)', function () {
    it('counts a scoop deadline over the DST night on the wall clock (18:00 → 08:00 CET)', function () {
        [, , $cat] = ccFamily('2026-10-20 08:00');
        $use = ccUse($cat, '2026-10-24 18:00');
        ccTickAround('2026-10-24 18:00');

        expect($use->fresh()->due_at->toIso8601String())->toBe('2026-10-25T07:00:00+00:00'); // 08:00 CET
        $period = app(LitterService::class)->changePeriodAt($cat->fresh(), Carbon::parse('2026-10-25 12:00', 'Europe/Ljubljana'));
        expect($period['index'])->toBe(0)
            ->and($period['end']->toIso8601String())->toBe('2026-10-27T07:00:00+00:00');
    });
});

describe('export, data provenance and payload contract', function () {
    it('exports the litter / scratching events with kind and deadline, the matted coat and the new care sessions', function () {
        [$parent, $child, $cat] = ccFamily('2026-10-20 08:00', BreedType::MaineCoon);
        ccUse($cat, '2026-10-21 10:00');
        ccTickAround('2026-10-21 10:00');
        ccAt('2026-10-21 11:00');
        ccChore($child, 'grooming')->assertOk();

        test()->actingAs($parent, 'sanctum');
        $pet = collect(test()->getJson('/api/parent/account/export')->assertOk()->json('pets'))->firstWhere('id', $cat->id);
        expect($pet['coat_matted_at'])->toBeNull()
            ->and($pet['hygiene_events'][0])->toMatchArray(['kind' => 'litter_use'])
            ->and($pet['hygiene_events'][0]['due_at'])->not->toBeNull()
            ->and(collect($pet['care_sessions'])->pluck('kind')->all())->toBe(['grooming']);
    });

    it('takes the key-less game rules from data.json and records David\'s decisions there', function () {
        $data = json_decode((string) file_get_contents(base_path('../docs/research/cat-data/data.json')), true);

        $overdue = $data['general']['litter']['game_overdue_change_deadline'];
        $resolve = $data['general']['scratching']['game_resolve_deadline'];
        $matted = $data['maine_coon']['grooming']['game_matted_after_missed'];
        expect($overdue['value'])->toBe(LitterService::OVERDUE_SCOOP_DEADLINE_HOURS)
            ->and($resolve['value'] * 3600)->toBe(RoutineLedgerService::CLEAN_WITHIN_SECONDS)
            ->and($matted['value'])->toBe(CatChoreService::MATTED_AFTER_MISSED)
            ->and($resolve['decision'] ?? '')->toContain('potrdil David 2026-10-08')
            ->and($matted['decision'] ?? '')->toContain('22:20')
            ->and(ScratchingService::praiseWindowMs())->toBe(3000);
    });

    it('settles a grooming that ran past its TTL as expired (tick) — no consequence', function () {
        [, $child, $cat] = ccFamily('2026-10-20 08:00', BreedType::MaineCoon);
        ccAt('2026-10-21 10:00');
        $id = ccPost($child, '/api/child/pet/grooming/start')->assertOk()->json('session.id');
        ccTick('2026-10-21 10:05');

        expect(PetCareSession::where('public_id', $id)->sole()->status)->toBe(CareSessionStatus::Expired);
        ccPost($child, '/api/child/pet/grooming/finish', ['session_id' => $id, 'strokes' => ccStrokes()])
            ->assertStatus(422)->assertJsonPath('reason', 'care_session_expired');
        ccPost($child, '/api/child/pet/grooming/start')->assertOk(); // start again at once
        expect(app(CatChoreService::class)->liveSession($cat, CareSessionKind::Grooming, now()))->not->toBeNull();
    });
});

describe('migration 2026_10_27_120000_add_cat_litter_scratching_grooming', function () {
    it('down() removes only the new rows / columns and restores the checks; up() adds them again', function () {
        $migration = require database_path('migrations/2026_10_27_120000_add_cat_litter_scratching_grooming.php');
        [, , $cat] = ccFamily();
        ccUse($cat, '2026-10-21 10:00');
        PetHygieneEvent::create(['pet_id' => $cat->id, 'kind' => HygieneEventKind::Poop, 'local_date' => '2026-10-21',
            'scheduled_at' => Carbon::parse('2026-10-21 12:00', 'UTC'), 'status' => HygieneEventStatus::Pending]);

        $migration->down();
        expect(DB::table('pet_hygiene_events')->where('pet_id', $cat->id)->pluck('kind')->all())->toBe(['poop'])
            ->and(fn () => DB::transaction(fn () => DB::table('pet_hygiene_events')->insert(['pet_id' => $cat->id, 'kind' => 'scratching',
                'local_date' => '2026-10-21', 'scheduled_at' => '2026-10-21 13:00:00', 'status' => 'pending'])))->toThrow(QueryException::class);

        $migration->up();
        ccUse($cat, '2026-10-21 14:00');
        expect(DB::table('pet_hygiene_events')->where('pet_id', $cat->id)->count())->toBe(2);
    });
});

describe('QA round 1 (M5-R06-05)', function () {
    function ccLock(Pet $pet, string $kind, string $fromLocal, string $toLocal): void
    {
        DB::table('pet_status_periods')->insert([
            'pet_id' => $pet->id, 'kind' => $kind,
            'started_at' => Carbon::parse($fromLocal, 'Europe/Ljubljana')->utc(),
            'ended_at' => Carbon::parse($toLocal, 'Europe/Ljubljana')->utc(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    it('M1: a payment lock inside a program week lengthens it — no second change, no wrong missed / overdue / matted', function () {
        [, $child, $cat] = ccFamily('2026-10-20 08:00', BreedType::MaineCoon);
        ccAt('2026-10-20 18:00');
        ccChore($child, 'litter-change')->assertOk()->assertJsonPath('status', 'accepted');
        ccAt('2026-10-21 10:00');
        ccChore($child, 'grooming')->assertOk()->assertJsonPath('status', 'accepted');
        ccLock($cat, 'payment_lock', '2026-10-22 08:00', '2026-10-23 08:00');

        ccAt('2026-10-23 12:00');
        $period = app(LitterService::class)->changePeriodAt($cat->fresh(), now());
        expect($period['index'])->toBe(0)
            ->and($period['start']->toIso8601String())->toBe('2026-10-20T06:00:00+00:00')
            // 7 program days + the 24 h lock; DST keeps 08:00 local.
            ->and($period['end']->toIso8601String())->toBe('2026-10-28T07:00:00+00:00');
        ccPost($child, '/api/child/pet/litter-change/start')->assertStatus(422)
            ->assertJsonPath('reason', 'litter_change_done')
            ->assertJsonPath('next_allowed_at', '2026-10-28T08:00:00+01:00');
        ccChore($child, 'grooming')->assertOk()->assertJsonPath('state.grooming.done_this_week', 2);
        ccAt('2026-10-24 10:00');
        ccChore($child, 'grooming')->assertOk()->assertJsonPath('state.grooming.done_this_week', 3);

        ccTickHourly('2026-10-26 22:00', '2026-10-28 09:00');
        expect(ccRoutines($cat, '2026-10-20', '2026-10-28', [RoutineType::LitterChange, RoutineType::Grooming]))->toBe([
            ['2026-10-28', 'grooming', 0, 'done', $child->id],
            ['2026-10-28', 'grooming', 1, 'done', $child->id],
            ['2026-10-28', 'grooming', 2, 'done', $child->id],
            ['2026-10-28', 'litter_change', 0, 'done', $child->id],
        ])->and($cat->fresh()->coat_matted_at)->toBeNull()
            ->and(app(LitterService::class)->changeOverdueAt($cat->fresh(), Carbon::parse('2026-10-28 10:00', 'Europe/Ljubljana')))->toBeFalse();
    });

    it('M1: a missed week with a lock inside ends at the lengthened end — 4 h before it, 2 h and matted after it', function () {
        [, $child, $cat] = ccFamily('2026-10-20 08:00', BreedType::MaineCoon);
        ccTick('2026-10-20 09:00');
        ccAt('2026-10-21 10:00');
        ccChore($child, 'grooming')->assertOk();
        ccLock($cat, 'payment_lock', '2026-10-22 08:00', '2026-10-23 08:00');

        ccTickHourly('2026-10-26 22:00', '2026-10-27 09:00');
        expect($cat->fresh()->coat_matted_at)->toBeNull();
        $early = ccUse($cat, '2026-10-27 10:00');
        ccTickAround('2026-10-27 10:00');
        expect($early->fresh()->due_at->copy()->setTimezone('Europe/Ljubljana')->format('H:i'))->toBe('14:00');
        ccAt('2026-10-27 11:00');
        ccPost($child, '/api/child/pet/litter/scoop')->assertOk();

        ccTickHourly('2026-10-27 12:00', '2026-10-28 07:00');
        ccTick('2026-10-28 08:01');
        expect($cat->fresh()->coat_matted_at?->copy()->setTimezone('Europe/Ljubljana')->format('Y-m-d H:i'))->toBe('2026-10-28 08:00')
            ->and(ccRoutines($cat, '2026-10-28', '2026-10-28', [RoutineType::LitterChange]))->toBe([['2026-10-28', 'litter_change', 0, 'missed', null]]);
        $late = ccUse($cat, '2026-10-28 10:00');
        ccTickAround('2026-10-28 10:00');
        expect($late->fresh()->due_at->copy()->setTimezone('Europe/Ljubljana')->format('H:i'))->toBe('12:00');
    });

    it('M1: a week mostly hard-stopped (≥ 50 %) is excused — no weekly routines, no overdue, no matted coat', function () {
        [, , $cat] = ccFamily('2026-10-20 08:00', BreedType::MaineCoon);
        ccTick('2026-10-20 09:00');
        ccLock($cat, 'hard_stop', '2026-10-21 08:00', '2026-10-25 08:00');

        ccTickHourly('2026-10-26 22:00', '2026-10-27 09:00');
        expect(ccRoutines($cat, '2026-10-27', '2026-10-27', [RoutineType::LitterChange, RoutineType::Grooming]))->toBe([])
            ->and($cat->fresh()->coat_matted_at)->toBeNull();
        $use = ccUse($cat, '2026-10-27 10:00');
        ccTickAround('2026-10-27 10:00');
        expect($use->fresh()->due_at->copy()->setTimezone('Europe/Ljubljana')->format('H:i'))->toBe('14:00');
    });

    it('m1: one game with the cat at a time — another child gets care_session_active; the own other game ends', function () {
        [$parent, $child, $cat] = ccFamily('2026-10-20 08:00', BreedType::MaineCoon);
        $sibling = ccSibling($parent, $cat);
        ccAt('2026-10-21 10:00');
        $grooming = ccPost($child, '/api/child/pet/grooming/start')->assertOk()->json('session.id');

        foreach (['wand/start', 'litter-change/start'] as $uri) {
            ccPost($sibling, '/api/child/pet/'.$uri)->assertStatus(422)->assertJsonPath('reason', 'care_session_active');
        }
        expect(ccState($sibling)['wand']['blocked_reason'])->toBe('care_session_active');

        // The child who grooms switches to the litter change: the grooming ends (no penalty).
        ccPost($child, '/api/child/pet/litter-change/start')->assertOk();
        expect(PetCareSession::where('public_id', $grooming)->sole()->status)->toBe(CareSessionStatus::Aborted);
        ccPost($sibling, '/api/child/pet/grooming/start')->assertStatus(422)->assertJsonPath('reason', 'care_session_active');
    });

    it('m2: a deadline moved past the night is reminded in the last hour before quiet hours; one reminder per use', function () {
        config(['push.enabled' => true]);
        Queue::fake();
        [, , $cat] = ccFamily();
        $evening = ccUse($cat, '2026-10-21 17:00');
        ccTickAround('2026-10-21 17:00');
        expect($evening->fresh()->due_at->copy()->setTimezone('Europe/Ljubljana')->format('Y-m-d H:i'))->toBe('2026-10-22 07:00');
        ccTick('2026-10-21 19:30');
        expect(PushNotification::where('pet_id', $cat->id)->where('type', 'litter_reminder')->count())->toBe(0);
        ccTick('2026-10-21 20:01');
        expect(PushNotification::where('pet_id', $cat->id)->where('type', 'litter_reminder')->pluck('metric')->all())->toBe(['litter:'.$evening->id]);

        // Two uses 20 min apart → two reminders (not folded by the 30-min type dedupe).
        [, , $other] = ccFamily();
        $a = ccUse($other, '2026-10-21 10:00');
        $b = ccUse($other, '2026-10-21 10:20');
        ccTickAround('2026-10-21 10:00');
        ccTickAround('2026-10-21 10:20');
        ccTick('2026-10-21 13:01');
        ccTick('2026-10-21 13:21');
        ccTick('2026-10-21 13:30');
        expect(PushNotification::where('pet_id', $other->id)->where('type', 'litter_reminder')->orderBy('id')->pluck('metric')->all())
            ->toBe(['litter:'.$a->id, 'litter:'.$b->id]);
    });

    it('m4: a praise for a scratching already resolved (vet, sibling) does not count as completed', function () {
        [, $child, $cat] = ccScratchedCat();
        ccAt('2026-10-22 10:30');
        $session = ccPost($child, '/api/child/pet/scratching/start')->assertOk()->json('session');
        PetHygieneEvent::where('pet_id', $cat->id)->where('kind', 'scratching')->update(['cleaned_at' => now()]);
        Carbon::setTestNow(now()->addMilliseconds($session['land_at_ms'] + 1500));

        ccPost($child, '/api/child/pet/scratching/finish', ['session_id' => $session['id'], 'praise_ms' => $session['land_at_ms'] + 1000])
            ->assertStatus(422)->assertJsonPath('reason', 'scratching_not_needed');
        expect(PetCareSession::where('public_id', $session['id'])->sole()->status)->toBe(CareSessionStatus::Aborted)
            ->and(ActivityLog::where('pet_id', $cat->id)->where('activity_type', 'resolved_scratching')->count())->toBe(0);
    });
});
