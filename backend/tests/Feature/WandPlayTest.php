<?php

use App\Enums\BreedType;
use App\Enums\CareSessionStatus;
use App\Enums\PushType;
use App\Enums\RoutineStatus;
use App\Enums\RoutineType;
use App\Events\PetUpdated;
use App\Models\ActivityLog;
use App\Models\BreedStageParam;
use App\Models\Pet;
use App\Models\PetCareSession;
use App\Models\PetContract;
use App\Models\PetDailyWalk;
use App\Models\PushNotification;
use App\Models\User;
use App\Services\CareScoreService;
use App\Services\FamilyService;
use App\Services\NotificationService;
use App\Services\PlayService;
use App\Services\Push\ExpoPushClient;
use App\Services\RoutineLedgerService;
use App\Services\WandPlayService;
use Database\Seeders\BreedStageParamsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| M5-R06-04 — cat rules part 1: wand play instead of steps
|--------------------------------------------------------------------------
| CAT_SPEC Q1 / Q2 / §5.2 / §5.5, PRODUCT_SPEC §13, M5-R06_PLAN T5 / T6.
| David 2026-10-08 ~20:40: 2 h between successful sessions; ~60 s game ending
| with the catch, counted only when the child really took part (server
| check); an unfinished session does not count, no penalty, restart at once.
| Every wall-clock rule runs in the family timezone (Europe/Ljubljana),
| incl. the DST change on 25 Oct 2026 (03:00 CEST → 02:00 CET).
*/

afterEach(function () {
    Carbon::setTestNow();
});

function wpAt(string $local): void
{
    Carbon::setTestNow(Carbon::parse($local, 'Europe/Ljubljana')->utc());
}

/**
 * A family with a cat born at $bornLocal. Arrival 12 months = young cat
 * (goal 2); 2 / 3 months = kitten (goal 3). Quiet hours: bedtime 21–07.
 *
 * @return array{0: User, 1: User, 2: Pet}
 */
function wpFamily(string $bornLocal = '2026-10-20 08:00', BreedType $breed = BreedType::DomesticCat, int $arrival = 12, array $attributes = []): array
{
    seedLifeStageData();
    wpAt($bornLocal);
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => 'Mia']);
    setQuietHours(['parent_id' => $parent->id, 'bedtime_start' => '21:00', 'bedtime_end' => '07:00']);
    $cat = disableHygieneEvents(Pet::factory()->create(array_merge([
        'breed_type' => $breed->value,
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrival,
        'origin' => 'adopted',
    ], $attributes)));

    return [$parent, $child, $cat->fresh()];
}

function wpSibling(User $parent, Pet $pet, string $name = 'Tim'): User
{
    $child = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => $name]);
    app(FamilyService::class)->addCaretaker($pet, $child, true);
    PetContract::create(['pet_id' => $pet->id, 'user_id' => $child->id, 'signature_format' => 'svg_path', 'signature' => 'M1 1 L2 2', 'signed_at' => now()]);

    return $child;
}

/**
 * A child who really played: ~22 "away" strokes over the whole minute with
 * human (irregular) gaps, plus a reaction right after every pounce cue.
 *
 * @param  list<int>  $pounces
 */
function wpGoodMoves(array $pounces = [], int $durationMs = 60000): array
{
    $moves = [];
    for ($i = 0; ($t = 500 + 2700 * $i + ($i * 389) % 500) < $durationMs; $i++) {
        $moves[] = ['t' => $t, 'away' => true];
    }
    foreach ($pounces as $p) {
        $moves[] = ['t' => min($durationMs, $p + 400), 'away' => true];
    }

    return $moves;
}

function wpPost(User $child, string $uri, array $body = [])
{
    test()->actingAs($child, 'sanctum');
    $response = test()->postJson($uri, $body);
    app('auth')->forgetGuards();

    return $response;
}

function wpStart(User $child)
{
    return wpPost($child, '/api/child/pet/wand/start');
}

function wpFinish(User $child, string $sessionId, ?array $moves = null)
{
    $pounces = PetCareSession::where('public_id', $sessionId)->first()?->schedule['pounces_ms'] ?? [];

    return wpPost($child, '/api/child/pet/wand/finish', ['session_id' => $sessionId, 'moves' => $moves ?? wpGoodMoves($pounces)]);
}

/** Start now, finish 60 s later with good moves; returns the finish response. */
function wpPlay(User $child, ?array $moves = null)
{
    $id = wpStart($child)->assertOk()->json('session.id');
    Carbon::setTestNow(now()->addSeconds(60));

    return wpFinish($child, $id, $moves);
}

function wpTick(): void
{
    Artisan::call('pets:process-decay');
}

describe('steps are dog-only (CAT_SPEC Q1, §5.3)', function () {
    it('refuses a step sync for a cat with 422 steps_not_applicable and changes nothing', function () {
        [, $child, $cat] = wpFamily();
        wpAt('2026-10-21 10:00');

        wpPost($child, '/api/child/pet/steps', ['steps_today' => 3000, 'source' => 'healthkit', 'recorded_at' => now()->toIso8601String()])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'steps_not_applicable')
            ->assertJsonPath('state.pet.species', 'cat');

        expect($cat->fresh()->daily_step_count)->toBe(0)
            ->and(DB::table('pet_daily_steps')->where('pet_id', $cat->id)->count())->toBe(0);
    });

    it('closes a cat day without a walk row or walk illness, even when the cat never played', function () {
        [, , $cat] = wpFamily('2026-10-20 08:00');

        // Two whole days without any play; the tick crosses both midnights.
        foreach (['2026-10-21 00:05', '2026-10-21 12:00', '2026-10-22 00:05', '2026-10-22 07:30', '2026-10-22 12:00'] as $t) {
            wpAt($t);
            wpTick();
        }

        $cat->refresh();
        expect(PetDailyWalk::where('pet_id', $cat->id)->count())->toBe(0)
            ->and($cat->walk_illness_due_at)->toBeNull()
            ->and($cat->illness_until)->toBeNull()
            ->and($cat->isIll())->toBeFalse()
            ->and($cat->displayMetric('energy_level'))->toBe(0);
    });
});

describe('wand session (server-driven, CAT_SPEC §5.2)', function () {
    it('starts a ~60 s session with the server schedule and counts a played game: meter, activity, routine', function () {
        [, $child, $cat] = wpFamily();
        wpAt('2026-10-21 09:00');
        wpTick(); // midnight passed: meter 0

        $start = wpStart($child)->assertOk()->assertJsonPath('status', 'accepted');
        $session = $start->json('session');
        expect($session['duration_ms'])->toBe(60000)
            ->and($session['catch_at_ms'])->toBe(60000)
            ->and($session['ends_at'])->toBe('2026-10-21T09:01:00+02:00')
            ->and($session['expires_at'])->toBe('2026-10-21T09:02:00+02:00')
            ->and(count($session['pounces_ms']))->toBeGreaterThanOrEqual(1)
            ->and($session['min_away_moves'])->toBe(8)
            ->and($start->json('state.wand.session.id'))->toBe($session['id'])
            ->and($start->json('state.wand.can_start'))->toBeTrue() // own game running: a new start restarts it
            ->and($start->json('state.wand.session_running'))->toBeTrue();

        Carbon::setTestNow(now()->addSeconds(60));
        $finish = wpFinish($child, $session['id'])->assertOk()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('result.success', true)
            ->assertJsonPath('result.reason', null)
            ->assertJsonPath('result.segments_hit', 4);
        expect($finish->json('result.away_moves'))->toBeGreaterThanOrEqual(8)
            ->and($finish->json('result.pounces_hit'))->toBe(count($session['pounces_ms']))
            ->and($session['pounce_window_ms'])->toBe(2000);

        // Goal 2 (young cat): 1 / 2 → 50 %.
        expect($finish->json('state.pet.energy_level'))->toBe(50)
            ->and($finish->json('state.wand.goal'))->toBe(2)
            ->and($finish->json('state.wand.sessions_today'))->toBe(1)
            ->and($finish->json('state.wand.my_sessions_today'))->toBe(1)
            ->and($finish->json('state.wand.next_allowed_at'))->toBe('2026-10-21T11:01:00+02:00')
            ->and($finish->json('state.wand.blocked_reason'))->toBe('wand_too_soon');
        $row = ActivityLog::where('pet_id', $cat->id)->where('activity_type', 'played_wand')->sole();
        expect($row->value)->toBe(1)->and($row->actor_user_id)->toBe($child->id);

        $routine = collect(app(RoutineLedgerService::class)->routinesFor(collect([$cat->fresh()]), '2026-10-21', '2026-10-21')[$cat->id])
            ->firstWhere('type', RoutineType::Play);
        expect($routine->status)->toBe(RoutineStatus::Pending)->and($routine->steps)->toBe(1)->and($routine->goal)->toBe(2);

        // A repeat of the finish → unchanged with the same verdict, nothing more counted.
        wpFinish($child, $session['id'])->assertOk()->assertJsonPath('status', 'unchanged')->assertJsonPath('result.success', true);
        expect(ActivityLog::where('pet_id', $cat->id)->where('activity_type', 'played_wand')->count())->toBe(1);
    });

    it('measures the 2 h gap from the last SUCCESSFUL session (David 2026-10-08); the goal reached → meter 100, routine done', function () {
        [, $child, $cat] = wpFamily();
        wpAt('2026-10-21 09:00');
        wpPlay($child)->assertJsonPath('status', 'accepted'); // finished 09:01

        wpAt('2026-10-21 11:00');
        wpStart($child)->assertStatus(422)
            ->assertJsonPath('reason', 'wand_too_soon')
            ->assertJsonPath('next_allowed_at', '2026-10-21T11:01:00+02:00');

        // A failed game in between does not move the gap.
        wpAt('2026-10-21 11:01');
        $fail = wpPlay($child, [['t' => 1000, 'away' => true]])->assertOk()->assertJsonPath('status', 'rejected');
        expect($fail->json('result.reason'))->toBe('too_few_moves')
            ->and($fail->json('state.pet.energy_level'))->toBe(50);
        // … and the child may start again at once (no penalty).
        $second = wpPlay($child)->assertOk()->assertJsonPath('status', 'accepted');

        expect($second->json('state.pet.energy_level'))->toBe(100)
            ->and($second->json('state.wand.sessions_today'))->toBe(2)
            ->and(PetCareSession::where('pet_id', $cat->id)->orderBy('id')->pluck('status')->map->value->all())
            ->toBe(['completed', 'failed', 'completed']);

        wpAt('2026-10-21 18:00');
        $routine = collect(app(RoutineLedgerService::class)->routinesFor(collect([$cat->fresh()]), '2026-10-21', '2026-10-21')[$cat->id])
            ->firstWhere('type', RoutineType::Play);
        expect($routine->status)->toBe(RoutineStatus::Done)->and($routine->steps)->toBe(2)->and($routine->actorUserId)->toBe($child->id);
    });

    it('lets the same child restart at once (the unfinished game is aborted) and blocks a sibling while a game runs', function () {
        [$parent, $child, $cat] = wpFamily();
        $sibling = wpSibling($parent, $cat);
        wpAt('2026-10-21 09:00');

        $first = wpStart($child)->assertOk()->json('session.id');
        wpAt('2026-10-21 09:00:20');
        wpStart($sibling)->assertStatus(422)
            ->assertJsonPath('reason', 'wand_session_active')
            ->assertJsonPath('next_allowed_at', '2026-10-21T09:02:00+02:00');

        $second = wpStart($child)->assertOk()->json('session.id');
        expect($second)->not->toBe($first)
            ->and(PetCareSession::where('public_id', $first)->sole()->status)->toBe(CareSessionStatus::Aborted);

        // The aborted game cannot be finished any more; it never counts.
        wpAt('2026-10-21 09:01:30');
        wpFinish($child, $first)->assertStatus(422)->assertJsonPath('reason', 'wand_session_expired');
        wpFinish($child, $second)->assertOk()->assertJsonPath('status', 'accepted');
        // A sibling cannot finish someone else's game.
        wpFinish($sibling, $second)->assertStatus(422)->assertJsonPath('reason', 'wand_session_invalid');
    });

    it('refuses a finish before the game could have run, after the TTL, and with impossible moves', function () {
        [, $child, $cat] = wpFamily();
        wpAt('2026-10-21 09:00');
        $id = wpStart($child)->json('session.id');

        wpAt('2026-10-21 09:00:54'); // < 60 s − 5 s tolerance
        wpFinish($child, $id)->assertStatus(422)->assertJsonPath('reason', 'wand_session_not_over');

        wpAt('2026-10-21 09:00:56'); // inside the tolerance
        wpFinish($child, $id, [['t' => 60001, 'away' => true]])->assertStatus(422)->assertJsonPath('reason', 'wand_invalid_moves');

        wpAt('2026-10-21 09:02:00'); // TTL = ends + 60 s
        wpFinish($child, $id)->assertStatus(422)->assertJsonPath('reason', 'wand_session_expired');
        expect(PetCareSession::where('public_id', $id)->sole()->status)->toBe(CareSessionStatus::Expired)
            ->and($cat->fresh()->displayMetric('energy_level'))->toBe(0); // first full day: nothing counted

        // Validation (FormRequest).
        wpPost($child, '/api/child/pet/wand/finish', ['session_id' => 'nope', 'moves' => []])->assertStatus(422)->assertJsonValidationErrors('session_id');
        wpPost($child, '/api/child/pet/wand/finish', ['session_id' => $id, 'moves' => [['t' => -1, 'away' => true]]])->assertStatus(422)->assertJsonValidationErrors('moves.0.t');
    });

    it('does not count a game during which a lock began (hard stop) — interrupted', function () {
        [, $child, $cat] = wpFamily();
        wpAt('2026-10-21 09:00');
        $id = wpStart($child)->json('session.id');

        wpAt('2026-10-21 09:00:30');
        $cat->fresh()->update(['is_hard_stopped' => true]);
        wpAt('2026-10-21 09:00:45');
        $cat->fresh()->update(['is_hard_stopped' => false]);

        wpAt('2026-10-21 09:01:00');
        wpFinish($child, $id)->assertStatus(422)->assertJsonPath('reason', 'wand_session_interrupted');
        expect(ActivityLog::where('pet_id', $cat->id)->where('activity_type', 'played_wand')->count())->toBe(0);

        // While locked: 423.
        $cat->fresh()->update(['is_hard_stopped' => true]);
        wpStart($child)->assertStatus(423)->assertJsonPath('reason', 'hard_stopped');
    });

    it('refuses a start whose game would run past the family-local midnight (wand_day_ending)', function () {
        [, $child, $cat] = wpFamily();
        withoutQuietHours($cat); // else the night quiet hours refuse first
        wpAt('2026-10-21 23:58:30');
        wpStart($child)->assertStatus(422)
            ->assertJsonPath('reason', 'wand_day_ending')
            ->assertJsonPath('next_allowed_at', '2026-10-22T00:00:00+02:00');

        wpAt('2026-10-21 23:57:00');
        wpStart($child)->assertOk();
    });

    it('is only for cats: a dog gets 422 wand_not_available and wand = null in its state', function () {
        seedLifeStageData();
        wpAt('2026-10-21 09:00');
        $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
        $child = User::factory()->child()->create(['parent_id' => $parent->id]);
        Pet::factory()->mutt()->create(['user_id' => $child->id, 'arrival_age_months' => 2]);

        $res = wpStart($child)->assertStatus(422)->assertJsonPath('reason', 'wand_not_available');
        expect($res->json('state.wand'))->toBeNull()
            ->and($res->json('state.pet.species'))->toBe('dog');
        expect(PetCareSession::count())->toBe(0);
    });

    it('uses the kitten goal of 3 (CAT_SPEC Q1): one game → 33 %', function () {
        [, $child, $cat] = wpFamily('2026-10-20 08:00', BreedType::MaineCoon, 3);
        wpAt('2026-10-21 09:00');
        wpTick();

        $res = wpPlay($child)->assertJsonPath('status', 'accepted');
        expect($res->json('state.wand.goal'))->toBe(3)
            ->and($res->json('state.pet.energy_level'))->toBe(33)
            ->and($cat->fresh()->life_stage?->value)->toBe('puppy');
    });

    it('refuses the game in quiet hours — the cat sleeps (David 2026-10-08): now, or before the game would end', function () {
        [, $child, $cat] = wpFamily(); // bedtime 21:00–07:00
        wpAt('2026-10-21 22:00');
        $res = wpStart($child)->assertStatus(422)
            ->assertJsonPath('reason', 'wand_quiet_hours')
            ->assertJsonPath('next_allowed_at', '2026-10-22T07:00:00+02:00');
        expect($res->json('state.wand.blocked_reason'))->toBe('wand_quiet_hours')
            ->and($res->json('state.wand.can_start'))->toBeFalse();

        // 20:59: the game + its TTL would run into the night → refused like wand_day_ending.
        wpAt('2026-10-21 20:59:00');
        wpStart($child)->assertStatus(422)->assertJsonPath('reason', 'wand_quiet_hours')
            ->assertJsonPath('next_allowed_at', '2026-10-22T07:00:00+02:00');
        // 20:57:59 still fits (60 s game + 60 s TTL end before 21:00).
        wpAt('2026-10-21 20:57:59');
        wpStart($child)->assertOk();

        // School quiet hours too: next = end of school.
        setQuietHours(['family_id' => $cat->family_id, 'school_start' => '08:00', 'school_end' => '13:00', 'bedtime_start' => '21:00', 'bedtime_end' => '07:00']);
        wpAt('2026-10-22 10:00');
        wpStart($child)->assertStatus(422)->assertJsonPath('next_allowed_at', '2026-10-22T13:00:00+02:00');
        expect(PetCareSession::where('pet_id', $cat->id)->where('local_date', '2026-10-22')->count())->toBe(0);
    });

    it('is not gated by the cats flag once a cat exists', function () {
        [, $child] = wpFamily();
        config(['petprep.cats_enabled' => false]);
        wpAt('2026-10-21 10:00');

        wpPlay($child)->assertOk()->assertJsonPath('status', 'accepted');
    });

    it('fits a kitten\'s 3 games (2 h gap) into a school + night day; an impossible day is excused, not missed (derived rule)', function () {
        $day = fn (string $d) => [Carbon::parse($d, 'Europe/Ljubljana')->startOfDay()->utc(), Carbon::parse($d, 'Europe/Ljubljana')->addDay()->startOfDay()->utc()];

        // Realistic: night 21–07, school 08–13 (or 08–15): 07:00, 13:00 / 15:00, 15:01 / 17:01 → ≥ 3.
        [, , $cat] = wpFamily('2026-10-20 08:00', BreedType::MaineCoon, 3);
        setQuietHours(['family_id' => $cat->family_id, 'school_start' => '08:00', 'school_end' => '13:00', 'bedtime_start' => '21:00', 'bedtime_end' => '07:00']);
        [$a, $b] = $day('2026-10-21');
        expect(WandPlayService::feasibleSessions($cat->fresh()->quietHours(), $a, $b, 120))->toBeGreaterThanOrEqual(3);
        setQuietHours(['family_id' => $cat->family_id, 'school_start' => '08:00', 'school_end' => '15:00', 'bedtime_start' => '21:00', 'bedtime_end' => '07:00']);
        expect(WandPlayService::feasibleSessions($cat->fresh()->quietHours(), $a, $b, 120))->toBeGreaterThanOrEqual(3);
        // DST day (25 h) with the same quiet hours.
        [$a, $b] = $day('2026-10-25');
        expect(WandPlayService::feasibleSessions($cat->fresh()->quietHours(), $a, $b, 120))->toBeGreaterThanOrEqual(3);

        // Impossible: night 20–07 + school 08–19 → only 07:00 and 19:00 fit (2 < 3).
        setQuietHours(['family_id' => $cat->family_id, 'school_start' => '08:00', 'school_end' => '19:00', 'bedtime_start' => '20:00', 'bedtime_end' => '07:00']);
        [$a, $b] = $day('2026-10-21');
        expect(WandPlayService::feasibleSessions($cat->fresh()->quietHours(), $a, $b, 120))->toBe(2);

        // The kitten never plays on 21 Oct: the play routine is not expected, nothing is recorded.
        wpAt('2026-10-22 00:05');
        wpTick();
        $types = collect(app(RoutineLedgerService::class)->routinesFor(collect([$cat->fresh()]), '2026-10-21', '2026-10-21')[$cat->id])->pluck('type');
        expect($types->contains(RoutineType::Play))->toBeFalse()
            ->and($cat->fresh()->play_missed_on)->toBeNull();
    });
});

describe('participation check (pure scoring)', function () {
    it('needs enough away moves, spread over the minute, mostly away from the cat', function () {
        $score = fn (array $moves, array $pounces = []) => WandPlayService::score($moves, 60000, $pounces);

        expect($score(wpGoodMoves()))->success->toBeTrue()->reason->toBeNull();
        expect($score([]))->success->toBeFalse()->reason->toBe('too_few_moves');

        // 10 away moves, all in the first 15 s → not spread.
        $burst = array_map(fn ($i) => ['t' => 1000 + $i * 1100 + $i * 37, 'away' => true], range(0, 9));
        expect($score($burst))->reason->toBe('not_spread')->segments_hit->toBe(1);

        // Waving in front of the face (toward) more than away → wrong technique (C11).
        $teasing = wpGoodMoves();
        foreach (wpGoodMoves() as $m) {
            $teasing[] = ['t' => $m['t'] + 900, 'away' => false];
            $teasing[] = ['t' => $m['t'] + 1700, 'away' => false];
        }
        expect($score($teasing))->reason->toBe('wrong_technique');

        // Moves closer than 300 ms count once.
        $spamOneSecond = array_map(fn ($i) => ['t' => 30000 + $i * 10, 'away' => true], range(0, 99));
        expect($score($spamOneSecond))->away_moves->toBe(4)->reason->toBe('too_few_moves');
    });

    it('requires a reaction after every pounce cue and rejects machine-regular moves (QA)', function () {
        $score = fn (array $moves, array $pounces = []) => WandPlayService::score($moves, 60000, $pounces);
        $pounces = [12000, 31000, 47500];

        // A human game that reacts to every pounce → counts.
        expect($score(wpGoodMoves($pounces), $pounces))->success->toBeTrue()->pounces_hit->toBe(3)->pounces->toBe(3);

        // Ignoring the cat: no away move within 2 s after the 31 s pounce.
        $ignoring = array_values(array_filter(wpGoodMoves($pounces), fn ($m) => $m['t'] < 31000 || $m['t'] > 33000));
        expect($score($ignoring, $pounces))->reason->toBe('missed_pounces')->pounces_hit->toBe(2);

        // A script: 8 evenly spaced away moves (one every 7.5 s) → too uniform, even without pounces.
        $script = array_map(fn ($i) => ['t' => 1000 + $i * 7500, 'away' => true], range(0, 7));
        expect($score($script))->reason->toBe('too_uniform');
        // A 100 ms stream counts every 300 ms → also machine-regular.
        $stream = array_map(fn ($i) => ['t' => $i * 100, 'away' => true], range(0, 599));
        expect($score($stream))->reason->toBe('too_uniform');
    });

    it('refuses a scripted finish over HTTP (evenly spaced moves after 55 s) — nothing counts', function () {
        [, $child, $cat] = wpFamily();
        wpAt('2026-10-21 09:00');
        $id = wpStart($child)->json('session.id');
        wpAt('2026-10-21 09:00:56');
        $script = array_map(fn ($i) => ['t' => 1000 + $i * 7500, 'away' => true], range(0, 7));

        wpFinish($child, $id, $script)->assertOk()->assertJsonPath('status', 'rejected')
            ->assertJsonPath('result.success', false);
        expect(ActivityLog::where('pet_id', $cat->id)->where('activity_type', 'played_wand')->count())->toBe(0);
    });
});

describe('midnight, missed play and DST (family timezone)', function () {
    it('resets the meter at the family-local midnight and records a missed play day for M5-R06-05 (no illness)', function () {
        [, $child, $cat] = wpFamily('2026-10-20 08:00');

        // 21 Oct: only one game (goal 2) → missed.
        wpAt('2026-10-21 09:00');
        wpPlay($child)->assertJsonPath('status', 'accepted');
        expect($cat->fresh()->displayMetric('energy_level'))->toBe(50);

        wpAt('2026-10-22 00:05');
        wpTick();
        $cat->refresh();
        expect($cat->displayMetric('energy_level'))->toBe(0)
            ->and(substr((string) $cat->getRawOriginal('play_missed_on'), 0, 10))->toBe('2026-10-21')
            ->and($cat->isIll())->toBeFalse()
            ->and($cat->walk_illness_due_at)->toBeNull();

        test()->actingAs($child, 'sanctum');
        expect(test()->getJson('/api/child/pet')->json('wand.missed_yesterday'))->toBeTrue();
        app('auth')->forgetGuards();

        // 22 Oct: the goal is reached → nothing recorded for that day.
        wpAt('2026-10-22 09:00');
        wpPlay($child);
        wpAt('2026-10-22 11:30');
        wpPlay($child)->assertJsonPath('state.pet.energy_level', 100);
        wpAt('2026-10-23 00:05');
        wpTick();
        expect(substr((string) $cat->fresh()->getRawOriginal('play_missed_on'), 0, 10))->toBe('2026-10-21');

        $routines = collect(app(RoutineLedgerService::class)->routinesFor(collect([$cat->fresh()]), '2026-10-20', '2026-10-22')[$cat->id])
            ->where('type', RoutineType::Play)->values();
        // Not expected on the birth day (20 Oct); 21 missed, 22 done.
        expect($routines->map(fn ($r) => [$r->localDate, $r->status->value])->all())
            ->toBe([['2026-10-21', 'missed'], ['2026-10-22', 'done']]);
    });

    it('counts across the DST change of 25 Oct: the gap is 2 real hours, the day closes at local midnight (CET)', function () {
        [, $child, $cat] = wpFamily('2026-10-23 08:00');
        withoutQuietHours($cat); // the clock change lies in the night quiet hours (no play then)

        // 01:29 CEST on 25 Oct, finished 01:30 CEST (= 23:30 UTC on 24 Oct).
        wpAt('2026-10-25 01:29');
        $res = wpPlay($child)->assertJsonPath('status', 'accepted');
        // 2 h later in real time = 02:30 CET (the clock went back at 03:00).
        expect($res->json('state.wand.next_allowed_at'))->toBe('2026-10-25T02:30:00+01:00');

        Carbon::setTestNow(Carbon::parse('2026-10-25 01:29:00', 'UTC')); // 02:29 CET: 1 min short
        wpStart($child)->assertStatus(422)->assertJsonPath('reason', 'wand_too_soon');
        Carbon::setTestNow(Carbon::parse('2026-10-25 01:30:00', 'UTC')); // 02:30 CET
        wpPlay($child)->assertJsonPath('status', 'accepted')->assertJsonPath('state.wand.sessions_today', 2);

        // The 25-hour day ends at 00:00 CET = 23:00 UTC.
        Carbon::setTestNow(Carbon::parse('2026-10-25 22:59:00', 'UTC'));
        wpTick();
        expect($cat->fresh()->displayMetric('energy_level'))->toBe(100);
        Carbon::setTestNow(Carbon::parse('2026-10-25 23:01:00', 'UTC'));
        wpTick();
        expect($cat->fresh()->displayMetric('energy_level'))->toBe(0)
            ->and($cat->fresh()->play_missed_on)->toBeNull();

        $routine = collect(app(RoutineLedgerService::class)->routinesFor(collect([$cat->fresh()]), '2026-10-25', '2026-10-25')[$cat->id])
            ->firstWhere('type', RoutineType::Play);
        expect($routine->status)->toBe(RoutineStatus::Done)
            ->and($routine->dueAt->toIso8601String())->toBe('2026-10-25T23:00:00+00:00');
    });
});

describe('Care Score fair share (goal / n, rounded up)', function () {
    it('credits a child the play routine only with ⌈goal / n⌉ own successful sessions', function () {
        [$parent, $child, $cat] = wpFamily('2026-10-20 08:00', BreedType::MaineCoon, 3); // kitten: goal 3
        $sibling = wpSibling($parent, $cat);

        // 21 Oct: Mia 2 games, Tim 1 → goal 3 reached; ⌈3 / 2⌉ = 2 → only Mia is credited.
        foreach ([['09:00', $child], ['11:05', $sibling], ['13:10', $child]] as [$time, $who]) {
            wpAt("2026-10-21 {$time}");
            wpPlay($who)->assertJsonPath('status', 'accepted');
        }

        wpAt('2026-10-22 10:00');
        $scores = app(CareScoreService::class);
        $board = $scores->board(Pet::whereKey($cat->id)->get(), 'Europe/Ljubljana');
        $play = collect($scores->routinesOn($board, $cat, '2026-10-21'))->firstWhere('type', RoutineType::Play);

        expect($play->isDone())->toBeTrue()
            ->and($scores->sharers($board, $cat, $play, $child->id))->toBe(2)
            ->and($scores->credited($board, $cat, $play, $child->id, 2))->toBeTrue()
            ->and($scores->credited($board, $cat, $play, $sibling->id, 2))->toBeFalse()
            ->and($scores->dayBlock($board, $cat, $child->id, '2026-10-21')['done_by_child'])->toBe(1)
            ->and($scores->dayBlock($board, $cat, $sibling->id, '2026-10-21')['done_by_child'])->toBe(0);
    });
});

describe('daily play reminder (one a day, M3-12)', function () {
    beforeEach(function () {
        config(['push.enabled' => true]);
        Queue::fake();
    });

    it('sends the cat a play_reminder (never a walk_reminder) once a day, not before the floor', function () {
        [, , $cat] = wpFamily('2026-10-20 08:00');

        foreach (['2026-10-21 00:05', '2026-10-21 07:10', '2026-10-21 10:00', '2026-10-21 15:00'] as $t) {
            wpAt($t);
            wpTick();
        }

        // (Hunger / thirst pushes of the unfed cat are the normal ladder — not this test.)
        $pushes = PushNotification::where('pet_id', $cat->id)
            ->whereIn('type', [PushType::PlayReminder->value, PushType::WalkReminder->value])->get();
        expect($pushes->pluck('type')->map->value->all())->toBe([PushType::PlayReminder->value])
            ->and($pushes->first()->metric)->toBe('play')
            // Night quiet ends 07:00 → floor 09:00 local.
            ->and($pushes->first()->send_after?->toIso8601String())->toBe('2026-10-21T07:00:00+00:00');
    });

    it('suppresses a queued play reminder once the meter is above 30 % (play_done)', function () {
        [, $child, $cat] = wpFamily('2026-10-20 08:00');
        wpAt('2026-10-21 09:30');
        wpTick();
        $push = PushNotification::where('pet_id', $cat->id)->where('type', PushType::PlayReminder->value)->sole();

        wpPlay($child)->assertJsonPath('status', 'accepted');
        $push->forceFill(['status' => PushNotification::STATUS_QUEUED, 'send_after' => null])->save();
        app(NotificationService::class)->deliver($push->id, app(ExpoPushClient::class));

        expect($push->fresh())->status->toBe(PushNotification::STATUS_SUPPRESSED)->suppressed_reason->toBe('play_done');
    });

    it('suppresses a queued play reminder whose game is refused without an end today (not_actionable, M3-12)', function () {
        [, , $cat] = wpFamily('2026-10-20 08:00');
        wpAt('2026-10-21 09:30');
        wpTick();
        $push = PushNotification::where('pet_id', $cat->id)->where('type', PushType::PlayReminder->value)->sole();

        // An open mess (hygiene 0 %): the game waits for the clean-up — no time to hold the push for.
        Pet::whereKey($cat->id)->update(['hygiene_level' => 0]);
        $push->forceFill(['status' => PushNotification::STATUS_QUEUED, 'send_after' => null])->save();
        app(NotificationService::class)->deliver($push->id, app(ExpoPushClient::class));

        expect($push->fresh())->status->toBe(PushNotification::STATUS_SUPPRESSED)->suppressed_reason->toBe('not_actionable');
    });
});

describe('play reminder is not lost for the day (QA)', function () {
    beforeEach(function () {
        config(['push.enabled' => true]);
        Queue::fake();
    });

    it('holds a reminder while a sibling plays and sends it once the game may start', function () {
        [$parent, , $cat] = wpFamily('2026-10-20 08:00');
        wpAt('2026-10-21 09:30');
        wpTick();
        $push = PushNotification::where('pet_id', $cat->id)->where('type', PushType::PlayReminder->value)->sole();

        $sibling = wpSibling($parent, $cat);
        wpStart($sibling)->assertOk(); // TTL 09:32
        $push->forceFill(['status' => PushNotification::STATUS_QUEUED, 'send_after' => null])->save();
        app(NotificationService::class)->deliver($push->id, app(ExpoPushClient::class));

        expect($push->fresh())->status->toBe(PushNotification::STATUS_SCHEDULED)
            ->and($push->fresh()->send_after->toIso8601String())->toBe('2026-10-21T07:32:00+00:00')
            ->and($push->fresh()->suppressed_reason)->toBeNull();
    });

    it('decides again after a not_actionable drop, and never while the game cannot start', function () {
        [$parent, , $cat] = wpFamily('2026-10-20 08:00');
        $sibling = wpSibling($parent, $cat);

        // A game runs at the first chance → no decision at all (nothing lost).
        wpAt('2026-10-21 09:30');
        wpStart($sibling)->assertOk();
        wpTick();
        expect(PushNotification::where('pet_id', $cat->id)->where('type', PushType::PlayReminder->value)->count())->toBe(0);

        // An older row dropped as not_actionable does not count as "decided today".
        PushNotification::create([
            'idempotency_key' => (string) Str::uuid(), 'pet_id' => $cat->id, 'type' => PushType::PlayReminder,
            'metric' => 'play', 'recipients' => [], 'status' => PushNotification::STATUS_SUPPRESSED, 'suppressed_reason' => 'not_actionable',
        ]);
        wpAt('2026-10-21 09:40'); // the sibling's game expired
        wpTick();
        expect(PushNotification::where('pet_id', $cat->id)->where('type', PushType::PlayReminder->value)
            ->whereIn('status', [PushNotification::STATUS_QUEUED, PushNotification::STATUS_SCHEDULED])->count())->toBe(1);
        wpAt('2026-10-21 10:00');
        wpTick();
        expect(PushNotification::where('pet_id', $cat->id)->where('type', PushType::PlayReminder->value)->count())->toBe(2);
    });
});

describe('a game that ends without counting is broadcast (QA)', function () {
    it('broadcasts wand_finished on a rejected finish and wand_ended when the tick expires a game', function () {
        [$parent, $child, $cat] = wpFamily();
        $sibling = wpSibling($parent, $cat);
        wpAt('2026-10-21 09:00');
        $id = wpStart($child)->json('session.id');

        Event::fake([PetUpdated::class]);
        wpAt('2026-10-21 09:01');
        wpFinish($child, $id, [])->assertJsonPath('status', 'rejected');
        Event::assertDispatched(PetUpdated::class, fn ($e) => $e->eventType === 'wand_finished');
        expect(PetUpdated::payloadFor($cat->fresh())['wand']['session_running'])->toBeFalse();

        // A sibling's game nobody finishes: the tick after its TTL ends it once.
        wpAt('2026-10-21 09:02');
        wpStart($sibling)->assertOk();
        wpAt('2026-10-21 09:04:30');
        wpTick();
        Event::assertDispatched(PetUpdated::class, fn ($e) => $e->eventType === 'wand_ended');
        expect(PetCareSession::where('user_id', $sibling->id)->sole()->status)->toBe(CareSessionStatus::Expired);
        $count = fn () => collect(Event::dispatched(PetUpdated::class))->filter(fn ($e) => $e[0]->eventType === 'wand_ended')->count();
        $before = $count();
        wpAt('2026-10-21 09:05:30');
        wpTick();
        expect($count())->toBe($before);
    });
});

describe('dog-only features for a cat', function () {
    it('refuses the ball game for a cat (422 play_not_available); cuddles stay; cat invitations are cuddles only', function () {
        [, $child, $cat] = wpFamily('2026-10-20 08:00', BreedType::MaineCoon, 12, ['plan' => 'challenge', 'challenge_paid_at' => now(), 'challenge_paid_source' => 'purchase']);
        wpAt('2026-10-21 10:00');

        wpPost($child, '/api/child/pet/play', ['kind' => 'play'])->assertStatus(422)->assertJsonPath('reason', 'play_not_available');
        wpPost($child, '/api/child/pet/play', ['kind' => 'cuddle'])->assertOk()->assertJsonPath('play.kind', 'cuddle');

        $service = app(PlayService::class);
        foreach (['2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24', '2026-10-25'] as $date) {
            $plan = $service->planDay($cat->fresh(), $date, $cat->quietHours());
            expect(count($plan))->toBeLessThanOrEqual(1);
            foreach ($plan as [$kind]) {
                expect($kind->value)->toBe('cuddle');
            }
        }
    });

    it('shows the cuddle invitation only once the cat\'s play goal of the day is reached', function () {
        [, $child, $cat] = wpFamily('2026-10-20 08:00', BreedType::MaineCoon, 12, ['plan' => 'challenge', 'challenge_paid_at' => now(), 'challenge_paid_source' => 'purchase']);
        $service = app(PlayService::class);

        wpAt('2026-10-21 09:00');
        expect($service->walkGoalReached($cat->fresh(), now()))->toBeFalse();
        wpPlay($child);
        wpAt('2026-10-21 11:05');
        wpPlay($child);
        expect($service->walkGoalReached($cat->fresh(), now()))->toBeTrue();
    });

    it('refuses training for a cat by species at runtime, even with the stored flag set (training_not_available)', function () {
        [, $child, $cat] = wpFamily('2026-10-20 08:00', BreedType::DomesticCat, 12, ['training_enabled' => true]);
        expect($cat->fresh()->training_enabled)->toBeTrue()->and($cat->fresh()->trainingEnabled())->toBeFalse();

        wpAt('2026-10-21 10:00');
        wpPost($child, '/api/child/pet/training/start', ['command' => 'sit'])->assertStatus(422)->assertJsonPath('reason', 'training_not_available');

        wpAt('2026-10-22 10:00');
        $types = collect(app(RoutineLedgerService::class)->routinesFor(collect([$cat->fresh()]), '2026-10-21', '2026-10-21')[$cat->id])->pluck('type');
        expect($types->contains(RoutineType::Training))->toBeFalse()
            ->and($types->contains(RoutineType::Walk))->toBeFalse()
            ->and($types->contains(RoutineType::Play))->toBeTrue();
    });
});

describe('state, export, data', function () {
    it('shows the day\'s wand play in the parent report rows of a cat; a dog\'s rows keep their shape (M5-R06-08b)', function () {
        [$parent, $child] = wpFamily();
        wpAt('2026-10-21 09:00');
        wpPlay($child)->assertJsonPath('status', 'accepted');

        test()->actingAs($parent, 'sanctum');
        $daily = collect(test()->getJson("/api/parent/children/{$child->id}/report?days=7")->assertOk()->json('daily'))->keyBy('date');
        app('auth')->forgetGuards();
        expect($daily['2026-10-21'])->play_sessions->toBe(1)->play_goal->toBe(2)->play_done->toBeFalse()
            ->walk_goal->toBeNull()->walk_steps->toBe(0)
            // The birth day has no play routine (not expected) → nulls, still a cat row.
            ->and($daily['2026-10-20'])->toHaveKey('play_sessions')
            ->and($daily['2026-10-20']['play_goal'])->toBeNull();

        // A dog's report rows carry no play keys (byte-identical to before).
        $dogParent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
        $dogChild = User::factory()->child()->create(['parent_id' => $dogParent->id]);
        Pet::factory()->mutt()->create(['user_id' => $dogChild->id, 'arrival_age_months' => 2]);
        test()->actingAs($dogParent, 'sanctum');
        $dogRow = test()->getJson("/api/parent/children/{$dogChild->id}/report?days=7")->assertOk()->json('daily.0');
        expect($dogRow)->not->toHaveKey('play_sessions')->toHaveKey('walk_steps');
    });

    it('names the end of every timed blocked_reason in wand.next_allowed_at (QA M5-R06-08a m5)', function () {
        [$parent, $child, $cat] = wpFamily(); // bedtime 21:00–07:00
        $tim = wpSibling($parent, $cat);
        $wand = function (User $viewer): array {
            test()->actingAs($viewer, 'sanctum');
            $state = test()->getJson('/api/child/pet')->assertOk()->json('wand');
            app('auth')->forgetGuards();

            return $state;
        };

        // Quiet hours: the end of the night (was null before — only the 2 h gap had a time).
        wpAt('2026-10-21 22:00');
        expect($wand($child))->blocked_reason->toBe('wand_quiet_hours')
            ->next_allowed_at->toBe('2026-10-22T07:00:00+02:00');

        // Another child's game: its TTL end (the 422 says the same).
        wpAt('2026-10-22 09:00');
        $expires = wpStart($tim)->assertOk()->json('session.expires_at');
        $blocked = $wand($child);
        expect($blocked['blocked_reason'])->toBe('wand_session_active')
            ->and(Carbon::parse($blocked['next_allowed_at'])->equalTo(Carbon::parse($expires)))->toBeTrue();
        expect(wpStart($child)->assertStatus(422)->json('next_allowed_at'))->toBe($blocked['next_allowed_at']);
        // Tim's own view: his running game is no refusal.
        expect($wand($tim))->blocked_reason->toBeNull()->next_allowed_at->toBeNull();

        // The day ending: the family-local midnight.
        withoutQuietHours($cat);
        wpAt('2026-10-22 23:58:30');
        expect($wand($child))->blocked_reason->toBe('wand_day_ending')
            ->next_allowed_at->toBe('2026-10-23T00:00:00+02:00');

        // No refusal and no gap → null (unchanged).
        wpAt('2026-10-23 10:00');
        expect($wand($child))->blocked_reason->toBeNull()->next_allowed_at->toBeNull();

        // The broadcast (no viewer) carries the pet-level time too.
        wpAt('2026-10-23 22:30');
        setQuietHours(['family_id' => $cat->family_id, 'bedtime_start' => '21:00', 'bedtime_end' => '07:00']);
        $payload = PetUpdated::payloadFor($cat->fresh());
        expect($payload['wand']['blocked_reason'])->toBe('wand_quiet_hours')
            ->and($payload['wand']['next_allowed_at'])->toBe('2026-10-24T07:00:00+02:00');
    });

    it('gives the child state a wand object for a cat (and the meter in energy_level)', function () {
        [, $child] = wpFamily();
        wpAt('2026-10-21 09:00');
        wpTick();

        test()->actingAs($child, 'sanctum');
        $state = test()->getJson('/api/child/pet')->assertOk()->json();
        expect($state['pet']['species'])->toBe('cat')
            ->and($state['wand'])->toBe([
                'goal' => 2, 'sessions_today' => 0, 'my_sessions_today' => 0, 'min_gap_minutes' => 120,
                'next_allowed_at' => null, 'blocked_reason' => null, 'can_start' => true, 'session' => null,
                'session_running' => false, 'missed_yesterday' => false,
            ])
            ->and($state['pet']['energy_level'])->toBe(0)
            ->and($state['training']['can_start'])->toBeFalse();
    });

    it('exports the cat\'s care sessions with the server verdict', function () {
        [$parent, $child, $cat] = wpFamily();
        wpAt('2026-10-21 09:00');
        wpPlay($child);

        test()->actingAs($parent, 'sanctum');
        $export = test()->getJson('/api/parent/account/export')->assertOk()->json();
        $pet = collect($export['pets'] ?? data_get($export, 'family.pets', []))->firstWhere('id', $cat->id);
        expect($pet['care_sessions'])->toHaveCount(1)
            ->and($pet['care_sessions'][0])->kind->toBe('wand_play')->status->toBe('completed')->child_id->toBe($child->id)
            ->and($pet['care_sessions'][0]['result']['success'])->toBeTrue();
    });

    it('records David\'s play gap decision: seeder row verified; the data migration flips an R06-03 row once (audit row, idempotent)', function () {
        $migration = require database_path('migrations/2026_10_26_130000_apply_david_cat_play_gap_decision.php');

        // TARGETS equal the seeder today.
        foreach ($migration::TARGETS as [$breed, $stage, $from, $key, $target]) {
            $row = collect(BreedStageParamsSeeder::catRows())->first(fn ($r) => $r['breed_slug'] === $breed && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key);
            expect($row)->not->toBeNull();
            $columns = BreedStageParamsSeeder::columns($row);
            expect($target['verified'])->toBe($columns['verified'])
                ->and($target['notes'])->toBe($columns['notes'])
                ->and($target['data_ref'])->toBe($columns['data_ref'])
                ->and(json_encode($target['value']))->toBe($columns['value']);
        }

        // An R06-03 production row (proposal, verified = false) → flipped once.
        seedLifeStageData();
        BreedStageParam::where('key', 'play_min_gap_minutes')->toBase()->update([
            'verified' => false, 'notes' => 'UNSOURCED — proposal (D), CAT_SPEC §5.2, waiting for David: spread the sessions over the day.',
        ]);
        DB::table('breed_stage_param_changes')->delete();

        $migration->up();
        $migration->up(); // idempotent
        expect(BreedStageParam::where('key', 'play_min_gap_minutes')->pluck('verified')->unique()->values()->all())->toBe([true])
            ->and(DB::table('breed_stage_param_changes')->where('key', 'play_min_gap_minutes')->count())->toBe(2)
            ->and(DB::table('breed_stage_param_changes')->where('key', 'play_min_gap_minutes')->value('actor'))->toBe('system: David decision 2026-10-08');

        // An admin-touched tuple is never overwritten.
        BreedStageParam::where('key', 'play_min_gap_minutes')->toBase()->update(['verified' => false]);
        $migration->up();
        expect(BreedStageParam::where('key', 'play_min_gap_minutes')->pluck('verified')->unique()->values()->all())->toBe([false]);
    });
});
