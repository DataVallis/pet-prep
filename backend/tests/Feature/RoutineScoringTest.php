<?php

use App\Enums\HygieneEventStatus;
use App\Enums\RoutineStatus;
use App\Enums\RoutineType;
use App\Models\Pet;
use App\Models\PetContract;
use App\Models\PetDailyRoutine;
use App\Models\PetDailyStep;
use App\Models\PetDailyWalk;
use App\Models\PetHygieneEvent;
use App\Models\PetStatusPeriod;
use App\Models\QuietHours;
use App\Models\User;
use App\Services\CareScoreService;
use App\Services\FamilyService;
use App\Services\Results\Routine;
use App\Services\RoutineLedgerService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\getJson;

/*
|--------------------------------------------------------------------------
| Routines, Care Score, traffic light (M2-06) + dashboard / report (M2-05)
|--------------------------------------------------------------------------
| Family in Europe/Ljubljana with quiet hours: bedtime 22:00–06:00 and
| school 08:00–13:00 → 11 non-quiet hours a day (06–08, 13–22).
| October 2026 before the 25th: local = UTC+2. Mutt: feed windows 06–10 and
| 17–21, water 3×/day, 4,000 steps.
*/

function rsAt(string $utc): void
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
}

/**
 * @return array{0: User, 1: User, 2: Pet}
 */
function rsFamily(string $bornUtc = '2026-10-11 22:30:00', bool $quiet = true): array
{
    seedBreedConfigs();
    rsAt($bornUtc);

    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => 'Maja']);
    if ($quiet) {
        QuietHours::create([
            'parent_id' => $parent->id,
            'school_start' => '08:00', 'school_end' => '13:00',
            'bedtime_start' => '22:00', 'bedtime_end' => '06:00',
            'is_active' => true,
        ]);
    }
    $pet = disableHygieneEvents(Pet::factory()->mutt()->create(['user_id' => $child->id]));

    return [$parent, $child, $pet];
}

/** A second child who joins $pet and signs their contract at $signedUtc. */
function rsSibling(User $parent, Pet $pet, string $signedUtc, string $name = 'Luka'): User
{
    $child = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => $name]);
    app(FamilyService::class)->addCaretaker($pet, $child, true);
    PetContract::create([
        'pet_id' => $pet->id, 'user_id' => $child->id,
        'signature_format' => 'svg_path', 'signature' => 'M1 1 L2 2',
        'signed_at' => Carbon::parse($signedUtc, 'UTC'),
    ]);

    return $child;
}

function rsAct(Pet $pet, string $type, string $utc, ?User $actor, ?int $value = null): void
{
    DB::table('activities_log')->insert([
        'pet_id' => $pet->id,
        'actor_user_id' => $actor?->id,
        'activity_type' => $type,
        'value' => $value,
        'created_at' => $utc,
    ]);
}

function rsMess(Pet $pet, string $atUtc, ?string $cleanedUtc = null, ?User $cleaner = null): void
{
    PetHygieneEvent::create([
        'pet_id' => $pet->id,
        'local_date' => $pet->localDate(Carbon::parse($atUtc, 'UTC')),
        'scheduled_at' => Carbon::parse($atUtc, 'UTC'),
        'status' => HygieneEventStatus::Applied,
        'resolved_at' => Carbon::parse($atUtc, 'UTC'),
        'cleaned_at' => $cleanedUtc !== null ? Carbon::parse($cleanedUtc, 'UTC') : null,
    ]);
    if ($cleanedUtc !== null) {
        rsAct($pet, 'cleaned_poop', $cleanedUtc, $cleaner);
    }
}

function rsWalk(Pet $pet, string $date, int $steps, int $goal = 4000): void
{
    PetDailyWalk::create([
        'pet_id' => $pet->id, 'local_date' => $date, 'steps' => $steps,
        'goal' => $goal, 'achieved' => $steps >= $goal, 'birth_day' => false,
    ]);
}

function rsSteps(Pet $pet, User $child, string $date, int $steps): void
{
    PetDailyStep::create(['pet_id' => $pet->id, 'user_id' => $child->id, 'local_date' => $date, 'steps' => $steps]);
}

function rsPeriod(Pet $pet, string $kind, string $startUtc, ?string $endUtc): void
{
    PetStatusPeriod::create([
        'pet_id' => $pet->id, 'kind' => $kind,
        'started_at' => Carbon::parse($startUtc, 'UTC'),
        'ended_at' => $endUtc !== null ? Carbon::parse($endUtc, 'UTC') : null,
    ]);
}

/**
 * @return list<Routine>
 */
function rsDay(Pet $pet, string $date, ?RoutineType $type = null): array
{
    $routines = app(RoutineLedgerService::class)->routinesFor(collect([$pet->fresh()]), $date, $date)[$pet->id];

    return array_values(array_filter($routines, fn (Routine $r) => $type === null || $r->type === $type));
}

/** A full, perfect day of care for a mutt (2 feeds, 3 water) on local date $date (UTC+2). */
function rsPerfectDay(Pet $pet, string $date, User $child): void
{
    $d = Carbon::parse($date, 'UTC');
    rsAct($pet, 'fed_pet', $d->copy()->setTime(4, 30)->toDateTimeString(), $child);                      // 06:30 local
    rsAct($pet, 'fed_pet', $d->copy()->setTime(15, 30)->toDateTimeString(), $child);                     // 17:30 local
    rsAct($pet, 'watered_pet', $d->copy()->setTime(4, 15)->toDateTimeString(), $child);                  // 06:15
    rsAct($pet, 'watered_pet', $d->copy()->setTime(11, 30)->toDateTimeString(), $child);                 // 13:30
    rsAct($pet, 'watered_pet', $d->copy()->setTime(17, 0)->toDateTimeString(), $child);                  // 19:00
}

function rsBoard(Pet $pet, ?string $tz = 'Europe/Ljubljana'): array
{
    return app(CareScoreService::class)->board(Pet::whereKey($pet->id)->get(), $tz);
}

/** Today 13 Oct at 22:00 local: both feeds missed + an uncleaned 07:30 mess = 3 missed. */
function rsThreeMissedToday(): array
{
    [$parent, $child, $pet] = rsFamily();
    rsMess($pet, '2026-10-13 05:30:00');
    rsAt('2026-10-13 20:00:00');

    return [$parent, $child, $pet->fresh()];
}

afterEach(fn () => Carbon::setTestNow());

describe('routine ledger — feeding', function () {
    it('one routine per window: done with the actor inside the window, missed after the window ends', function () {
        [, $child, $pet] = rsFamily();
        rsAct($pet, 'fed_pet', '2026-10-13 05:15:00', $child); // 07:15 local
        rsAct($pet, 'fed_pet', '2026-10-13 19:30:00', $child); // 21:30 local — after the evening window

        rsAt('2026-10-14 08:00:00');
        $feeds = rsDay($pet, '2026-10-13', RoutineType::Feed);

        expect($feeds)->toHaveCount(2)
            ->and($feeds[0]->status)->toBe(RoutineStatus::Done)
            ->and($feeds[0]->actorUserId)->toBe($child->id)
            ->and($feeds[0]->opensAt->toDateTimeString())->toBe('2026-10-13 04:00:00')
            ->and($feeds[0]->dueAt->toDateTimeString())->toBe('2026-10-13 08:00:00')
            ->and($feeds[1]->status)->toBe(RoutineStatus::Missed)
            ->and($feeds[1]->opensAt->toDateTimeString())->toBe('2026-10-13 15:00:00');
    });

    it('is pending while the window is open', function () {
        [, , $pet] = rsFamily();
        rsAt('2026-10-13 16:00:00'); // 18:00 local, evening window open

        $feeds = rsDay($pet, '2026-10-13', RoutineType::Feed);
        expect($feeds[0]->status)->toBe(RoutineStatus::Missed)
            ->and($feeds[1]->status)->toBe(RoutineStatus::Pending);
    });

    it('does not expect a window that lies entirely in quiet hours', function () {
        [$parent, , $pet] = rsFamily();
        QuietHours::where('family_id', $pet->family_id)->update(['school_start' => '06:00', 'school_end' => '10:00']);

        rsAt('2026-10-14 08:00:00');
        $feeds = rsDay($pet, '2026-10-13', RoutineType::Feed);

        expect($feeds)->toHaveCount(1)
            ->and($feeds[0]->opensAt->toDateTimeString())->toBe('2026-10-13 15:00:00');
    });

    it('still expects a window that is only partly quiet (06–08 is free before school)', function () {
        [, , $pet] = rsFamily();
        rsAt('2026-10-14 08:00:00');

        expect(rsDay($pet, '2026-10-13', RoutineType::Feed))->toHaveCount(2);
    });
});

describe('routine ledger — water', function () {
    it('expects water_times_per_day refills; each one missing at day end is missed', function () {
        [, $child, $pet] = rsFamily();
        rsAct($pet, 'watered_pet', '2026-10-13 04:30:00', $child);
        rsAct($pet, 'watered_pet', '2026-10-13 12:00:00', $child);

        rsAt('2026-10-13 21:00:00'); // 23:00 local — the day is not over
        $water = rsDay($pet, '2026-10-13', RoutineType::Water);
        expect(array_map(fn ($r) => $r->status, $water))->toBe([RoutineStatus::Done, RoutineStatus::Done, RoutineStatus::Pending]);

        rsAt('2026-10-13 22:00:00'); // local midnight
        $water = rsDay($pet, '2026-10-13', RoutineType::Water);
        expect(array_map(fn ($r) => $r->status, $water))->toBe([RoutineStatus::Done, RoutineStatus::Done, RoutineStatus::Missed])
            ->and($water[2]->dueAt->toDateTimeString())->toBe('2026-10-13 22:00:00');
    });
});

describe('routine ledger — cleaning', function () {
    it('allows 2 hours counted outside quiet hours (07:30 mess → deadline 14:30 because of school)', function () {
        [, $child, $pet] = rsFamily();
        rsMess($pet, '2026-10-13 05:30:00', '2026-10-13 12:00:00', $child); // 07:30 → cleaned 14:00 local
        rsMess($pet, '2026-10-14 05:30:00', '2026-10-14 13:00:00', $child); // 07:30 → cleaned 15:00 local

        rsAt('2026-10-15 08:00:00');
        $day13 = rsDay($pet, '2026-10-13', RoutineType::Clean);
        $day14 = rsDay($pet, '2026-10-14', RoutineType::Clean);

        expect($day13)->toHaveCount(1)
            ->and($day13[0]->dueAt->toDateTimeString())->toBe('2026-10-13 12:30:00')
            ->and($day13[0]->status)->toBe(RoutineStatus::Done)
            ->and($day13[0]->actorUserId)->toBe($child->id)
            ->and($day14[0]->status)->toBe(RoutineStatus::Missed);
    });

    it('without quiet hours the deadline is 2 real hours', function () {
        [, $child, $pet] = rsFamily(quiet: false);
        rsMess($pet, '2026-10-13 05:30:00', '2026-10-13 12:00:00', $child);

        rsAt('2026-10-15 08:00:00');
        $clean = rsDay($pet, '2026-10-13', RoutineType::Clean);

        expect($clean[0]->dueAt->toDateTimeString())->toBe('2026-10-13 07:30:00')
            ->and($clean[0]->status)->toBe(RoutineStatus::Missed);
    });

    it('carries a late-evening mess over the night (21:30 → 07:30 next morning)', function () {
        [, , $pet] = rsFamily();
        rsMess($pet, '2026-10-13 19:30:00');

        rsAt('2026-10-14 05:00:00'); // 07:00 local: still pending
        expect(rsDay($pet, '2026-10-13', RoutineType::Clean)[0]->status)->toBe(RoutineStatus::Pending)
            ->and(rsDay($pet, '2026-10-13', RoutineType::Clean)[0]->dueAt->toDateTimeString())->toBe('2026-10-14 05:30:00');
    });
});

describe('routine ledger — walk', function () {
    it('done when the goal was reached, missed at day end otherwise; live for today', function () {
        [, $child, $pet] = rsFamily();
        rsWalk($pet, '2026-10-13', 4200);
        rsWalk($pet, '2026-10-14', 1000);
        rsAct($pet, 'walked_pet', '2026-10-13 16:00:00', $child, 4200);

        rsAt('2026-10-15 10:00:00');
        Pet::whereKey($pet->id)->update(['daily_step_count' => 4100, 'last_step_reset_at' => '2026-10-14 22:00:00']);

        $w13 = rsDay($pet, '2026-10-13', RoutineType::Walk)[0];
        expect($w13->status)->toBe(RoutineStatus::Done)
            ->and($w13->actorUserId)->toBe($child->id)
            ->and($w13->steps)->toBe(4200)
            ->and(rsDay($pet, '2026-10-14', RoutineType::Walk)[0]->status)->toBe(RoutineStatus::Missed)
            ->and(rsDay($pet, '2026-10-15', RoutineType::Walk)[0]->status)->toBe(RoutineStatus::Done);
    });

    it('is not expected on the birth day nor for a past day without step data', function () {
        [, , $pet] = rsFamily();
        rsAt('2026-10-15 10:00:00');

        expect(rsDay($pet, '2026-10-12', RoutineType::Walk))->toBe([])
            ->and(rsDay($pet, '2026-10-13', RoutineType::Walk))->toBe([]);
    });
});

describe('routine ledger — birth day, hard stop, vet, game over', function () {
    it('birth day: only routines after the birth (born 18:00 local → 1 water, no feed, no walk)', function () {
        [, , $pet] = rsFamily('2026-10-12 16:00:00');
        rsAt('2026-10-13 08:00:00');

        $day = rsDay($pet, '2026-10-12');
        expect(array_map(fn ($r) => $r->type, $day))->toBe([RoutineType::Water]);
    });

    it('an unborn pet has no routines', function () {
        seedBreedConfigs();
        rsAt('2026-10-13 08:00:00');
        $parent = User::factory()->parent()->create();
        $child = User::factory()->child()->create(['parent_id' => $parent->id]);
        $pet = Pet::factory()->unborn()->create(['user_id' => $child->id]);

        rsAt('2026-10-15 08:00:00');
        expect(app(RoutineLedgerService::class)->routinesFor(collect([$pet]), '2026-10-13', '2026-10-15'))->toBe([]);
    });

    it('a hard stop excuses overlapping routines that were not done and pro-rates water', function () {
        [, , $pet] = rsFamily();
        rsWalk($pet, '2026-10-13', 500);
        rsPeriod($pet, 'hard_stop', '2026-10-13 14:30:00', '2026-10-13 17:30:00'); // 16:30–19:30 local

        rsAt('2026-10-14 08:00:00');
        $day = rsDay($pet, '2026-10-13');

        // morning feed still expected (and missed); evening feed excused; the
        // walk stays expected (3 of 11 non-quiet hours blocked < 50 %)
        expect(array_map(fn ($r) => $r->type->value, $day))->toBe(['walk', 'water', 'water', 'feed'])
            ->and($day[0]->status)->toBe(RoutineStatus::Missed)
            ->and($day[3]->opensAt->toDateTimeString())->toBe('2026-10-13 04:00:00');
    });

    it('a routine done during a hard stop still counts as done', function () {
        [, $child, $pet] = rsFamily();
        rsPeriod($pet, 'hard_stop', '2026-10-13 14:30:00', '2026-10-13 17:30:00');
        rsAct($pet, 'fed_pet', '2026-10-13 15:10:00', $child);

        rsAt('2026-10-14 08:00:00');
        $feeds = rsDay($pet, '2026-10-13', RoutineType::Feed);
        expect($feeds)->toHaveCount(2)->and($feeds[1]->status)->toBe(RoutineStatus::Done);
    });

    it('records hard stops and illnesses from the pet model and excuses the vet time', function () {
        [, , $pet] = rsFamily();

        rsAt('2026-10-13 10:00:00'); // 12:00 local: falls ill for 12 h
        $pet->fresh()->update(['illness_until' => Carbon::parse('2026-10-13 22:00:00', 'UTC'), 'pet_state' => 'sick']);
        rsAt('2026-10-14 02:00:00');
        $pet->fresh()->update(['is_hard_stopped' => true]);
        rsAt('2026-10-14 03:00:00');
        $pet->fresh()->update(['is_hard_stopped' => false]);

        $periods = PetStatusPeriod::where('pet_id', $pet->id)->orderBy('started_at')->get();
        expect($periods)->toHaveCount(2)
            ->and($periods[0]->kind->value)->toBe('illness')
            ->and($periods[0]->started_at->toDateTimeString())->toBe('2026-10-13 10:00:00')
            ->and($periods[0]->ended_at->toDateTimeString())->toBe('2026-10-13 22:00:00')
            ->and($periods[1]->kind->value)->toBe('hard_stop')
            ->and($periods[1]->ended_at->toDateTimeString())->toBe('2026-10-14 03:00:00');

        rsAt('2026-10-14 08:00:00');
        $day = rsDay($pet, '2026-10-13');
        // morning feed (missed) + 1 water (06–08 = 2 of 11 non-quiet hours → 0.55 → 1); evening feed excused
        expect(array_map(fn ($r) => $r->type->value, $day))->toBe(['water', 'feed']);
    });

    it('expects nothing after game over', function () {
        [, , $pet] = rsFamily();
        rsAt('2026-10-13 10:00:00');
        $pet->fresh()->update(['is_game_over' => true, 'is_active' => false]);

        rsAt('2026-10-15 08:00:00');
        expect(rsDay($pet, '2026-10-14'))->toBe([])
            ->and(rsDay($pet, '2026-10-13', RoutineType::Feed))->toHaveCount(1); // morning window only
    });
});

describe('review fixes (PR #19)', function () {
    it('a game-over pet with a pending hygiene event still closes its days and goes dormant', function () {
        [, , $pet] = rsFamily();
        rsAt('2026-10-13 08:00:00'); // 10:00 local
        $pet->fresh()->update(['is_game_over' => true, 'is_active' => false]);
        PetHygieneEvent::create([
            'pet_id' => $pet->id, 'local_date' => '2026-10-13',
            'scheduled_at' => Carbon::parse('2026-10-13 13:00:00', 'UTC'), // 15:00 local, never ticked
            'status' => HygieneEventStatus::Pending,
        ]);

        rsAt('2026-10-15 08:00:00');
        app(RoutineLedgerService::class)->closeDueDays();
        $fresh = $pet->fresh();

        expect($fresh->routines_closed_through)->toBe('2026-10-14')
            ->and($fresh->getRawOriginal('routines_next_close_at'))->toBeNull()
            ->and(rsDay($pet, '2026-10-13', RoutineType::Clean))->toBe([]);

        rsAt('2026-10-16 08:00:00');
        expect(app(RoutineLedgerService::class)->closeDueDays())->toBe(['pets' => 0, 'days' => 0]);
    });

    it('ignores illnesses before the first ledger day and before the child started', function () {
        [$parent, $maja, $pet] = rsFamily('2026-09-20 08:00:00');
        rsPeriod($pet, 'illness', '2026-09-25 10:00:00', '2026-09-25 22:00:00');
        rsPeriod($pet, 'illness', '2026-09-30 10:00:00', '2026-09-30 22:00:00');
        rsPeriod($pet, 'illness', '2026-10-13 00:30:00', '2026-10-13 00:30:01'); // 02:30 local, quiet
        $luka = rsSibling($parent, $pet, '2026-10-13 12:00:00');
        rsPerfectDay($pet, '2026-10-13', $maja);

        rsAt('2026-10-13 22:30:00');
        $board = rsBoard($pet);
        $scores = app(CareScoreService::class);
        $pet = $pet->fresh();

        expect($scores->petSummary($board, $pet)['care_score']['illnesses'])->toBe(1)
            ->and($scores->childScore($board, $maja->id, $pet)['illnesses'])->toBe(1)
            ->and($scores->childScore($board, $luka->id, $pet)['illnesses'])->toBe(0);
    });

    it('an over-achiever is capped at 100 before the −10 per illness', function () {
        [$parent, $maja, $pet] = rsFamily();
        rsSibling($parent, $pet, '2026-10-11 22:30:00');
        rsPerfectDay($pet, '2026-10-12', $maja);
        rsPerfectDay($pet, '2026-10-13', $maja);
        rsPeriod($pet, 'illness', '2026-10-13 20:30:00', '2026-10-14 08:30:00');

        rsAt('2026-10-13 22:30:00');
        // done 10 of a fair share of 5 → 200 % → 100 → −10
        expect(app(CareScoreService::class)->childScore(rsBoard($pet), $maja->id, $pet->fresh()))
            ->toMatchArray(['done' => 10, 'expected' => 5.0, 'illnesses' => 1, 'score' => 90]);
    });

    it('walk: a 1-minute hard stop does not excuse it', function () {
        [, , $pet] = rsFamily();
        rsWalk($pet, '2026-10-13', 500);
        rsPeriod($pet, 'hard_stop', '2026-10-13 12:00:00', '2026-10-13 12:01:00');

        rsAt('2026-10-14 08:00:00');
        expect(rsDay($pet, '2026-10-13', RoutineType::Walk)[0]->status)->toBe(RoutineStatus::Missed);
    });

    it('walk: an overnight hard stop until 09:00 does not excuse either day', function () {
        [, , $pet] = rsFamily();
        rsWalk($pet, '2026-10-13', 500);
        rsWalk($pet, '2026-10-14', 500);
        rsPeriod($pet, 'hard_stop', '2026-10-13 20:00:00', '2026-10-14 07:00:00'); // 22:00 → 09:00 local

        rsAt('2026-10-15 08:00:00');
        expect(rsDay($pet, '2026-10-13', RoutineType::Walk)[0]->status)->toBe(RoutineStatus::Missed)
            ->and(rsDay($pet, '2026-10-14', RoutineType::Walk)[0]->status)->toBe(RoutineStatus::Missed);
    });

    it('walk: 12 h at the vet covering most of the non-quiet day excuses it', function () {
        [, , $pet] = rsFamily();
        rsWalk($pet, '2026-10-13', 500);
        rsPeriod($pet, 'illness', '2026-10-13 06:00:00', '2026-10-13 18:00:00'); // 08–20 local: 7 of 11 h

        rsAt('2026-10-14 08:00:00');
        expect(rsDay($pet, '2026-10-13', RoutineType::Walk))->toBe([]);
    });

    it('yellow counts a mess missed this morning even though it happened yesterday evening', function () {
        [, $child, $pet] = rsFamily();
        rsMess($pet, '2026-10-13 19:50:00'); // 21:50 local → due 07:50 next morning
        rsAt('2026-10-14 19:30:00'); // 21:30 local: both feeds of today missed too
        $board = rsBoard($pet);
        $scores = app(CareScoreService::class);
        $pet = $pet->fresh();

        expect(rsDay($pet, '2026-10-13', RoutineType::Clean)[0]->dueAt->toDateTimeString())->toBe('2026-10-14 05:50:00')
            ->and($scores->petLight($board, $pet))->toBe(['color' => 'yellow', 'reasons' => ['missed_routines']])
            ->and($scores->childLight($board, $child->id, $pet)['color'])->toBe('yellow')
            ->and($scores->dayBlock($board, $pet, $child->id, '2026-10-14')['missed_count'])->toBe(3);
    });

    it('closes at most N pets per tick; the rest come next tick', function () {
        [, , $a] = rsFamily();
        [, , $b] = rsFamily();
        rsAt('2026-10-14 08:00:00');
        $ledger = app(RoutineLedgerService::class);

        expect($ledger->closeDueDays(limit: 1)['pets'])->toBe(1)
            ->and($ledger->closeDueDays(limit: 1)['pets'])->toBe(1)
            ->and($ledger->closeDueDays(limit: 1)['pets'])->toBe(0)
            ->and($a->fresh()->routines_closed_through)->toBe('2026-10-13')
            ->and($b->fresh()->routines_closed_through)->toBe('2026-10-13');
    });

    it('backs off 30 minutes after a failure instead of retrying every minute', function () {
        [, , $pet] = rsFamily();
        DB::table('families')->where('id', $pet->family_id)->update(['timezone' => 'Not/AZone']);
        rsAt('2026-10-14 08:00:00');

        app(RoutineLedgerService::class)->closeDueDays();

        expect(Carbon::parse($pet->fresh()->getRawOriginal('routines_next_close_at'))->toDateTimeString())->toBe('2026-10-14 08:30:00')
            ->and(app(RoutineLedgerService::class)->closeDueDays()['pets'])->toBe(0);
    });
});

describe('routine ledger — DST (Europe/Ljubljana, 25 Oct 2026 has 25 hours)', function () {
    it('follows the wall clock and closes the day at the real local midnight', function () {
        [, $child, $pet] = rsFamily('2026-10-20 08:00:00');
        rsAct($pet, 'fed_pet', '2026-10-25 05:30:00', $child); // 06:30 CET
        rsWalk($pet, '2026-10-25', 5000);

        rsAt('2026-10-26 08:00:00');
        $day = rsDay($pet, '2026-10-25');
        $feeds = array_values(array_filter($day, fn ($r) => $r->type === RoutineType::Feed));
        $water = array_values(array_filter($day, fn ($r) => $r->type === RoutineType::Water));
        $walk = array_values(array_filter($day, fn ($r) => $r->type === RoutineType::Walk));

        expect($feeds[0]->opensAt->toDateTimeString())->toBe('2026-10-25 05:00:00')
            ->and($feeds[0]->status)->toBe(RoutineStatus::Done)
            ->and($feeds[1]->opensAt->toDateTimeString())->toBe('2026-10-25 16:00:00')
            ->and($water)->toHaveCount(3)
            ->and($walk[0]->opensAt->toDateTimeString())->toBe('2026-10-24 22:00:00')
            ->and($walk[0]->dueAt->toDateTimeString())->toBe('2026-10-25 23:00:00');
    });

    it('lists 7 distinct family-local days across the change', function () {
        [$parent, , $pet] = rsFamily('2026-10-20 08:00:00');
        rsAt('2026-10-27 08:00:00');
        actingAsRole($parent);

        $dates = array_column(getJson('/api/parent/dashboard')->assertOk()->json('family.children.0.last_7_days'), 'date');
        expect($dates)->toBe(['2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24', '2026-10-25', '2026-10-26', '2026-10-27']);
    });
});

describe('Care Score', function () {
    it('= done / expected × 100 − 10 per illness', function () {
        [, $child, $pet] = rsFamily();          // born 12 Oct 00:30 local
        rsPerfectDay($pet, '2026-10-12', $child);
        rsPerfectDay($pet, '2026-10-13', $child);
        rsWalk($pet, '2026-10-13', 4500);
        // Missed: one feed and one water on the 13th (11 expected, 9 done).
        DB::table('activities_log')->where('pet_id', $pet->id)->where('created_at', '2026-10-13 15:30:00')->delete();
        DB::table('activities_log')->where('pet_id', $pet->id)->where('created_at', '2026-10-13 17:00:00')->delete();
        rsPeriod($pet, 'illness', '2026-10-13 20:30:00', '2026-10-14 08:30:00');

        rsAt('2026-10-13 22:30:00'); // 00:30 local on the 14th — today's routines are pending
        $board = rsBoard($pet);
        $scores = app(CareScoreService::class);
        $pet = $pet->fresh();

        $petScore = $scores->petSummary($board, $pet)['care_score'];
        $childScore = $scores->childScore($board, $child->id, $pet);

        expect($petScore)->toMatchArray(['done' => 9, 'expected' => 11, 'illnesses' => 1, 'score' => 72])
            ->and($childScore)->toMatchArray(['done' => 9, 'expected' => 11.0, 'illnesses' => 1, 'score' => 72]);

        rsPeriod($pet, 'illness', '2026-10-12 19:00:00', '2026-10-12 19:00:01');
        expect($scores->childScore(rsBoard($pet), $child->id, $pet)['score'])->toBe(62);
    });

    it('never goes below 0 and is null while nothing is expected yet', function () {
        [, $child, $pet] = rsFamily();
        rsPeriod($pet, 'illness', '2026-10-12 08:10:00', '2026-10-12 08:10:01');
        rsAt('2026-10-12 08:30:00'); // 10:30 local, birth day: morning feed missed, rest pending

        $board = rsBoard($pet);
        expect(app(CareScoreService::class)->childScore($board, $child->id, $pet->fresh())['score'])->toBe(0);

        [, $child2, $pet2] = rsFamily('2026-10-12 21:00:00'); // born 23:00 local
        expect(app(CareScoreService::class)->childScore(rsBoard($pet2), $child2->id, $pet2->fresh())['score'])->toBeNull();
    });

    it('shared pet, one child does everything: 100 vs 0 (fair share)', function () {
        [$parent, $maja, $pet] = rsFamily();
        $luka = rsSibling($parent, $pet, '2026-10-11 22:30:00');
        rsPerfectDay($pet, '2026-10-12', $maja);
        rsPerfectDay($pet, '2026-10-13', $maja);
        rsWalk($pet, '2026-10-13', 4000);
        rsSteps($pet, $maja, '2026-10-13', 4000);

        rsAt('2026-10-13 22:30:00');
        $board = rsBoard($pet);
        $scores = app(CareScoreService::class);
        $pet = $pet->fresh();

        expect($scores->childScore($board, $maja->id, $pet))->toMatchArray(['score' => 100, 'done' => 11, 'expected' => 5.5, 'routines' => 11])
            ->and($scores->childScore($board, $luka->id, $pet))->toMatchArray(['score' => 0, 'done' => 0, 'expected' => 5.5]);
    });

    it('shared pet: a walk counts for each child who walked at least goal / n', function () {
        [$parent, $maja, $pet] = rsFamily();
        $luka = rsSibling($parent, $pet, '2026-10-11 22:30:00');
        rsWalk($pet, '2026-10-13', 4000);
        rsSteps($pet, $maja, '2026-10-13', 2000);
        rsSteps($pet, $luka, '2026-10-13', 2000);
        rsWalk($pet, '2026-10-14', 4000);
        rsSteps($pet, $maja, '2026-10-14', 3500);
        rsSteps($pet, $luka, '2026-10-14', 500);

        rsAt('2026-10-15 06:00:00');
        $board = rsBoard($pet);
        $pet = $pet->fresh();
        $walks = array_values(array_filter($board['routines'][$pet->id], fn ($r) => $r->type === RoutineType::Walk && $r->isDone()));
        $scores = app(CareScoreService::class);

        expect($walks)->toHaveCount(2)
            ->and($scores->credited($board, $pet, $walks[0], $maja->id, 2))->toBeTrue()
            ->and($scores->credited($board, $pet, $walks[0], $luka->id, 2))->toBeTrue()
            ->and($scores->credited($board, $pet, $walks[1], $maja->id, 2))->toBeTrue()
            ->and($scores->credited($board, $pet, $walks[1], $luka->id, 2))->toBeFalse();
    });

    it('a child who joins later shares only the routines that open after their contract', function () {
        [$parent, $maja, $pet] = rsFamily();
        $luka = rsSibling($parent, $pet, '2026-10-13 12:00:00'); // 14:00 local
        rsPerfectDay($pet, '2026-10-12', $maja);
        rsPerfectDay($pet, '2026-10-13', $maja);
        rsWalk($pet, '2026-10-13', 4000);
        // Luka fed the evening (instead of Maja).
        DB::table('activities_log')->where('pet_id', $pet->id)->where('created_at', '2026-10-13 15:30:00')->update(['actor_user_id' => $luka->id]);

        rsAt('2026-10-13 22:30:00');
        $board = rsBoard($pet);
        $scores = app(CareScoreService::class);
        $pet = $pet->fresh();

        expect($scores->childScore($board, $luka->id, $pet))->toMatchArray(['done' => 1, 'expected' => 0.5, 'routines' => 1, 'score' => 100])
            // Maja: 10 alone + ½ of the evening feed; did 10 → 95
            ->and($scores->childScore($board, $maja->id, $pet))->toMatchArray(['done' => 10, 'expected' => 10.5, 'score' => 95]);
    });
});

describe('traffic light', function () {
    it('green with at most 2 missed routines today', function () {
        [, $child, $pet] = rsFamily();
        rsAt('2026-10-13 20:00:00'); // feeds missed (2), water / walk still pending
        $board = rsBoard($pet);
        $scores = app(CareScoreService::class);

        expect($scores->petLight($board, $pet->fresh()))->toBe(['color' => 'green', 'reasons' => []])
            ->and($scores->childLight($board, $child->id, $pet->fresh()))->toBe(['color' => 'green', 'reasons' => []]);
    });

    it('yellow with more than 2 missed routines today — for every caretaker of a shared pet', function () {
        [$parent, $maja, $pet] = rsThreeMissedToday();
        $luka = rsSibling($parent, $pet, '2026-10-11 22:30:00');
        $board = rsBoard($pet);
        $scores = app(CareScoreService::class);

        expect($scores->petLight($board, $pet)['color'])->toBe('yellow')
            ->and($scores->childLight($board, $maja->id, $pet))->toBe(['color' => 'yellow', 'reasons' => ['missed_routines']])
            ->and($scores->childLight($board, $luka->id, $pet))->toBe(['color' => 'yellow', 'reasons' => ['missed_routines']]);
    });

    it('red when the phase-3 alarm is active', function () {
        [, $child, $pet] = rsFamily();
        rsAt('2026-10-13 06:00:00');
        Pet::whereKey($pet->id)->update(['escalation_level' => 3]);

        expect(app(CareScoreService::class)->childLight(rsBoard($pet), $child->id, $pet->fresh()))
            ->toBe(['color' => 'red', 'reasons' => ['phase3_alarm']]);
    });

    it('red when the pet fell ill today, not for yesterday\'s illness', function () {
        [, $child, $pet] = rsFamily();
        rsPeriod($pet, 'illness', '2026-10-13 04:00:00', '2026-10-13 16:00:00');
        rsAt('2026-10-13 18:00:00');
        expect(app(CareScoreService::class)->petLight(rsBoard($pet), $pet->fresh()))->toBe(['color' => 'red', 'reasons' => ['fell_ill_today']]);

        rsAt('2026-10-14 06:00:00'); // next local day
        expect(app(CareScoreService::class)->petLight(rsBoard($pet), $pet->fresh())['color'])->toBe('green');
    });

    it('red after game over', function () {
        [, $child, $pet] = rsFamily();
        rsAt('2026-10-13 10:00:00');
        $pet->fresh()->update(['is_game_over' => true, 'is_active' => false, 'escalation_level' => 3]);

        expect(app(CareScoreService::class)->childLight(rsBoard($pet), $child->id, $pet->fresh()))
            ->toBe(['color' => 'red', 'reasons' => ['game_over']]);
    });
});

describe('materialised ledger (tick)', function () {
    it('closes finished days once, idempotently, and keeps them when quiet hours change later', function () {
        [, $child, $pet] = rsFamily();
        rsPerfectDay($pet, '2026-10-13', $child);
        rsAt('2026-10-14 08:00:00');
        $live = rsDay($pet, '2026-10-13');

        $ledger = app(RoutineLedgerService::class);
        expect($ledger->closeDueDays())->toBe(['pets' => 1, 'days' => 2]);
        $stored = PetDailyRoutine::where('pet_id', $pet->id)->count();
        expect($stored)->toBe(10) // 12th: 2 feeds + 3 water; 13th: 2 feeds + 3 water
            ->and($pet->fresh()->routines_closed_through)->toBe('2026-10-13')
            ->and($ledger->closeDueDays())->toBe(['pets' => 0, 'days' => 0])
            ->and(PetDailyRoutine::where('pet_id', $pet->id)->count())->toBe($stored);

        // Stored rows read back exactly like the live computation.
        $fromTable = rsDay($pet, '2026-10-13');
        expect(array_map(fn ($r) => [$r->type, $r->slot, $r->status, $r->opensAt->toDateTimeString(), $r->actorUserId], $fromTable))
            ->toBe(array_map(fn ($r) => [$r->type, $r->slot, $r->status, $r->opensAt->toDateTimeString(), $r->actorUserId], $live));

        // History is frozen: a later quiet-hours change does not rewrite the 13th.
        QuietHours::where('family_id', $pet->family_id)->update(['school_start' => '06:00', 'school_end' => '10:00']);
        expect(rsDay($pet, '2026-10-13', RoutineType::Feed))->toHaveCount(2)
            ->and(rsDay($pet, '2026-10-14', RoutineType::Feed))->toHaveCount(1);
    });

    it('waits for a cleaning deadline that runs past midnight', function () {
        [, , $pet] = rsFamily();
        rsMess($pet, '2026-10-13 19:30:00'); // 21:30 local → due 07:30 next morning

        rsAt('2026-10-13 23:00:00');
        app(RoutineLedgerService::class)->closeDueDays();
        $fresh = $pet->fresh();
        expect($fresh->routines_closed_through)->toBe('2026-10-12')
            ->and(Carbon::parse($fresh->routines_next_close_at)->toDateTimeString())->toBe('2026-10-14 05:30:00');

        rsAt('2026-10-14 05:31:00');
        app(RoutineLedgerService::class)->closeDueDays();
        expect($pet->fresh()->routines_closed_through)->toBe('2026-10-13')
            ->and(PetDailyRoutine::where('pet_id', $pet->id)->where('routine_type', 'clean')->value('status'))->toBe(RoutineStatus::Missed);
    });

    it('runs inside pets:process-decay', function () {
        [, , $pet] = rsFamily();
        rsAt('2026-10-14 08:00:00');
        Artisan::call('pets:process-decay');

        expect($pet->fresh()->routines_closed_through)->toBe('2026-10-13');
    });
});

describe('migration backfill', function () {
    it('rebuilds illness, hard-stop and inactive periods from existing data and can be re-applied', function () {
        [, , $sick] = rsFamily();
        [, , $paused] = rsFamily();
        [, , $dead] = rsFamily();
        $migration = require database_path('migrations/2026_10_05_120000_create_routine_ledger.php');
        $migration->down();

        // Hygiene illness (row time = start) and walk illness (start = planned 06:00).
        DB::table('activities_log')->insert(['pet_id' => $sick->id, 'activity_type' => 'ignored_warning', 'value' => -1, 'created_at' => '2026-10-13 13:00:00']);
        DB::table('activities_log')->insert(['pet_id' => $sick->id, 'activity_type' => 'ignored_warning', 'value' => -1, 'created_at' => '2026-10-15 04:01:00']);
        rsWalk($sick, '2026-10-14', 0);
        PetDailyWalk::where('pet_id', $sick->id)->update(['illness_due_at' => '2026-10-15 04:00:00', 'illness_started_at' => '2026-10-15 04:00:00']);
        DB::table('pets')->where('id', $paused->id)->update(['is_hard_stopped' => true, 'frozen_at' => '2026-10-14 10:00:00']);
        DB::table('pets')->where('id', $dead->id)->update(['is_active' => false, 'is_game_over' => true]);
        DB::table('activities_log')->insert(['pet_id' => $dead->id, 'activity_type' => 'ignored_warning', 'value' => -2, 'created_at' => '2026-10-14 20:00:00']);

        $migration->up();

        $periods = fn (Pet $p) => DB::table('pet_status_periods')->where('pet_id', $p->id)->orderBy('started_at')
            ->get(['kind', 'started_at', 'ended_at'])->map(fn ($r) => [$r->kind, $r->started_at, $r->ended_at])->all();

        expect($periods($sick))->toBe([
            ['illness', '2026-10-13 13:00:00', '2026-10-14 01:00:00'],
            ['illness', '2026-10-15 04:00:00', '2026-10-15 16:00:00'],
        ])
            ->and($periods($paused))->toBe([['hard_stop', '2026-10-14 10:00:00', null]])
            ->and($periods($dead))->toBe([['inactive', '2026-10-14 20:00:00', null]])
            ->and(Schema::hasColumn('pets', 'routines_closed_through'))->toBeTrue();
    });
});

describe('GET /api/parent/dashboard (M2-05)', function () {
    it('adds traffic light, Care Score, today, last 7 days and progress per child; light, metrics, timeline per pet', function () {
        [$parent, $child, $pet] = rsFamily();
        rsPerfectDay($pet, '2026-10-12', $child);
        rsAct($pet, 'fed_pet', '2026-10-13 05:00:00', $child);
        rsAt('2026-10-13 06:00:00'); // 08:00 local
        actingAsRole($parent);

        $res = getJson('/api/parent/dashboard')->assertOk();

        // Legacy fields untouched.
        expect($res->json('traffic_light'))->toBe('green')
            ->and($res->json('pet.id'))->toBe($pet->id);

        $c = $res->json('family.children.0');
        expect($c['traffic_light'])->toBe(['color' => 'green', 'reasons' => []])
            ->and($c['care_score'])->toMatchArray(['score' => 100, 'done' => 6, 'expected' => 6, 'illnesses' => 0])
            ->and($c['care_score']['since'])->toBe('2026-10-11T22:30:00+00:00')
            ->and($c['today'])->toMatchArray(['date' => '2026-10-13', 'done' => 1, 'done_by_child' => 1, 'missed_count' => 0])
            ->and($c['last_7_days'])->toHaveCount(7)
            ->and($c['last_7_days'][5])->toMatchArray(['date' => '2026-10-12', 'expected' => 5, 'done' => 5, 'missed' => 0])
            ->and($c['progress'])->toMatchArray(['week' => 1, 'weeks_total' => 12, 'days_elapsed' => 1, 'completed' => false]);

        $p = $res->json('family.pets.0');
        expect($p['traffic_light'])->toBe(['color' => 'green', 'reasons' => []])
            ->and($p['metrics'])->toHaveKeys(['hunger', 'thirst', 'energy', 'hygiene'])
            ->and($p['care_score']['score'])->toBe(100)
            ->and($p['timeline'][0])->toMatchArray(['activity_type' => 'fed_pet', 'actor_user_id' => $child->id, 'actor_nickname' => 'Maja', 'is_positive' => true])
            ->and($p['timeline'][0]['created_at'])->toBe('2026-10-13T07:00:00+02:00');
    });

    it('lists missed routines of today with type and time', function () {
        [$parent] = rsThreeMissedToday();
        actingAsRole($parent);

        $today = getJson('/api/parent/dashboard')->assertOk()->json('family.children.0.today');
        expect($today['missed_count'])->toBe(3)
            ->and(array_column($today['missed'], 'type'))->toBe(['feed', 'clean', 'feed'])
            ->and($today['missed'][1]['due_at'])->toBe('2026-10-13T14:30:00+02:00');
    });

    it('keeps the query count bounded: 2 pets × 84 days costs the same as 7 days', function () {
        $count = function (int $days): int {
            // Cold caches in both runs (life-stage params are cached per breed, M5-R01).
            Cache::flush();
            seedBreedConfigs();
            $now = Carbon::parse('2027-01-10 10:00:00', 'UTC');
            rsAt($now->copy()->subDays($days)->toDateTimeString());
            $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
            QuietHours::create(['parent_id' => $parent->id, 'school_start' => '08:00', 'school_end' => '13:00', 'bedtime_start' => '22:00', 'bedtime_end' => '06:00', 'is_active' => true]);
            foreach (['A', 'B'] as $name) {
                $child = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => $name]);
                $pet = disableHygieneEvents(Pet::factory()->mutt()->create(['user_id' => $child->id]));
                for ($d = $now->copy()->subDays($days); $d->lessThan($now); $d->addDay()) {
                    $date = $d->toDateString();
                    rsPerfectDay($pet, $date, $child);
                    rsMess($pet, $d->copy()->setTime(14, 0)->toDateTimeString(), $d->copy()->setTime(14, 30)->toDateTimeString(), $child);
                    rsWalk($pet, $date, 4100);
                    rsSteps($pet, $child, $date, 4100);
                }
            }
            rsAt($now->toDateTimeString());
            actingAsRole($parent);

            DB::flushQueryLog();
            DB::enableQueryLog();
            $res = getJson('/api/parent/dashboard')->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            expect($res->json('family.children.0.care_score.expected'))->toBeGreaterThan(5 * ($days - 1));

            return $queries;
        };

        $week = $count(7);
        $challenge = $count(84);

        expect($challenge)->toBe($week)->toBeLessThanOrEqual(60);
    });

    it('never shows another family', function () {
        [$parentA, $childA] = rsFamily();
        [$parentB, $childB] = rsFamily();
        rsAt('2026-10-13 06:00:00');
        actingAsRole($parentB);

        $ids = array_column(getJson('/api/parent/dashboard')->assertOk()->json('family.children'), 'id');
        expect($ids)->toBe([$childB->id]);
        getJson("/api/parent/children/{$childA->id}/report")->assertNotFound()->assertJsonPath('reason', 'child_not_found');
    });
});

describe('GET /api/parent/children/{child}/report', function () {
    it('returns score, light, progress, per-type totals, daily rows and missed routines', function () {
        [$parent, $child, $pet] = rsFamily();
        rsPerfectDay($pet, '2026-10-12', $child);
        rsMess($pet, '2026-10-13 05:30:00');
        rsWalk($pet, '2026-10-13', 4000);
        rsAt('2026-10-14 06:00:00');
        actingAsRole($parent);

        $res = getJson("/api/parent/children/{$child->id}/report?days=30")->assertOk();

        expect($res->json('days'))->toBe(30)
            ->and($res->json('daily'))->toHaveCount(30)
            ->and($res->json('from'))->toBe('2026-09-15')
            ->and($res->json('to'))->toBe('2026-10-14')
            ->and($res->json('child'))->toBe(['id' => $child->id, 'name' => 'Maja'])
            // today's (14th) routines are still pending
            ->and($res->json('by_type.feed'))->toMatchArray(['expected' => 6, 'done' => 2, 'missed' => 2, 'pending' => 2])
            ->and($res->json('by_type.clean'))->toMatchArray(['expected' => 1, 'missed' => 1])
            ->and($res->json('by_type.walk'))->toMatchArray(['expected' => 2, 'done' => 1, 'pending' => 1])
            ->and($res->json('missed.0.type'))->toBeIn(['water', 'feed', 'clean'])
            ->and($res->json('care_score.score'))->toBe(50) // 6 of 12 scored (14th still pending)
            ->and($res->json('progress.week'))->toBe(1)
            ->and($res->json('traffic_light.color'))->toBe('green');

        getJson("/api/parent/children/{$child->id}/report")->assertOk()->assertJsonPath('days', 7);
        getJson("/api/parent/children/{$child->id}/report?days=84")->assertOk()->assertJsonCount(84, 'daily');
    });

    it('validates days and hides unknown ids and other roles', function () {
        [$parent, $child] = rsFamily();
        rsAt('2026-10-13 06:00:00');
        actingAsRole($parent);

        getJson("/api/parent/children/{$child->id}/report?days=5")->assertStatus(422);
        getJson('/api/parent/children/999999/report')->assertNotFound();
        getJson('/api/parent/children/abc/report')->assertNotFound();
        getJson("/api/parent/children/{$parent->id}/report")->assertNotFound();

        actingAsRole($child);
        getJson("/api/parent/children/{$child->id}/report")->assertForbidden();
    });
});
