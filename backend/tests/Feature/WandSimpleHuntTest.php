<?php

use App\Enums\BreedType;
use App\Models\ActivityLog;
use App\Models\Pet;
use App\Models\PetCareSession;
use App\Models\QuietHours;
use App\Models\User;
use App\Services\WandPlayService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/*
|--------------------------------------------------------------------------
| Cat wand — the simple hunt (rules 3, GAMES_SPEC §5.2–§5.4, §7.2)
|--------------------------------------------------------------------------
| David 2026-11-08 after testing on his iPhone: »nihče ne zna igrati … naredi
| bolj preprosto igro« (the rule designed by Claude). The child drags the
| feather; the cat crouches with a 👀 cue for 2 s; letting go (finger lifted /
| »Pusti!«) during the cue = the cat catches the feather (★). Too early or
| never = nothing (no penalty; the next cue comes). 3 catches = done, counts.
| Without `rules` v1 stays byte for byte. Goal, 2 h gap, meter, routine and
| the scratched sofa are unchanged.
*/

afterEach(function () {
    Carbon::setTestNow();
});

function wsAt(string $local): void
{
    Carbon::setTestNow(Carbon::parse($local, 'Europe/Ljubljana')->utc());
}

/** @return array{0: User, 1: User, 2: Pet} */
function wsFamily(): array
{
    seedLifeStageData();
    wsAt('2026-10-20 08:00');
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => 'Mia']);
    setQuietHours(['parent_id' => $parent->id, 'bedtime_start' => '21:00', 'bedtime_end' => '07:00']);
    $cat = disableHygieneEvents(Pet::factory()->create([
        'breed_type' => BreedType::DomesticCat->value,
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => 12,
        'origin' => 'adopted',
    ]));
    wsAt('2026-10-21 09:00');
    Artisan::call('pets:process-decay');

    return [$parent, $child, $cat->fresh()];
}

function wsPost(User $child, string $uri, array $body = [])
{
    test()->actingAs($child, 'sanctum');
    $response = test()->postJson($uri, $body);
    app('auth')->forgetGuards();

    return $response;
}

/** A fixed simple hunt (first cue 4500, window 2000, +6000 after a catch, +2500 after a miss, 6 cues, 3 catches). */
function wsSchedule(): array
{
    return [
        'rules_version' => 3, 'duration_ms' => 50500, 'stalk_ms' => 3000, 'first_cue_ms' => 4500,
        'cue_window_ms' => 2000, 'after_catch_ms' => 6000, 'after_miss_ms' => 2500,
        'max_cues' => 6, 'catches_needed' => 3, 'catch_clip_ms' => 3500,
    ];
}

describe('schedule', function () {
    it('draws the first cue in 4–5 s after the 3 s stalk and sends every number the app plays the timeline with', function () {
        foreach (range(1, 30) as $seed) {
            $s = WandPlayService::scheduleSimple(new Randomizer(new Xoshiro256StarStar(hash('sha256', "ws-{$seed}", true))));
            expect($s['rules_version'])->toBe(3)
                ->and($s['first_cue_ms'])->toBeGreaterThanOrEqual(4000)->toBeLessThanOrEqual(5000)
                ->and($s['first_cue_ms'])->toBeGreaterThanOrEqual($s['stalk_ms'])
                ->and([$s['stalk_ms'], $s['cue_window_ms'], $s['after_catch_ms'], $s['after_miss_ms'], $s['max_cues'], $s['catches_needed'], $s['catch_clip_ms']])
                ->toBe([3000, 2000, 6000, 2500, 6, 3, 3500])
                // The latest possible end: 6 cues, each followed by the longer of a late catch / a miss.
                ->and($s['duration_ms'])->toBe($s['first_cue_ms'] + 5 * 8000 + 2000 + 3500)
                // Inside the v1 minute the start refusals reserve.
                ->and($s['duration_ms'])->toBeLessThan(60000);
        }
    });

    it('starts a simple hunt with rules: 3 (and rules: 2 from an older build); without rules the v1 payload keys are unchanged', function () {
        [, $child] = wsFamily();

        $v1 = wsPost($child, '/api/child/pet/wand/start')->assertOk()->json('session');
        expect(array_keys($v1))->toBe(['id', 'started_at', 'ends_at', 'expires_at', 'duration_ms', 'catch_at_ms', 'pounces_ms',
            'min_away_moves', 'segments', 'min_move_interval_ms', 'pounce_window_ms'])
            ->and($v1['duration_ms'])->toBe(60000)
            ->and(PetCareSession::where('public_id', $v1['id'])->sole()->schedule)->not->toHaveKey('rules_version');

        $start = wsPost($child, '/api/child/pet/wand/start', ['rules' => 3])->assertOk();
        $s = $start->json('session');
        $stored = PetCareSession::where('public_id', $s['id'])->sole();
        expect($s['rules_version'])->toBe(3)
            ->and($s)->toHaveKeys(['stalk_ms', 'first_cue_ms', 'cue_window_ms', 'after_catch_ms', 'after_miss_ms', 'max_cues', 'catches_needed', 'catch_clip_ms'])
            ->and($s['cue_window_ms'])->toBe(2000)
            ->and($s['catches_needed'])->toBe(3)
            ->and($s['duration_ms'])->toBe($stored->duration_ms)
            ->and($stored->schedule['rules_version'])->toBe(3)
            ->and($start->json('state.wand.session.rules_version'))->toBe(3)
            ->and($start->json('state.wand.session.first_cue_ms'))->toBe($s['first_cue_ms']);
        expect(PetCareSession::where('public_id', $v1['id'])->sole()->status->value)->toBe('aborted');

        // An older TestFlight build (the strict hunt is gone) gets the simple hunt — never a crash.
        expect(wsPost($child, '/api/child/pet/wand/start', ['rules' => 2])->assertOk()->json('session.rules_version'))->toBe(3);
        wsPost($child, '/api/child/pet/wand/start', ['rules' => 4])->assertUnprocessable()->assertJsonValidationErrors('rules');
    });
});

describe('the rule: let go while the cat crouches', function () {
    it('a let-go any time during the 2 s cue is a catch; the next cue follows 6 s after it', function () {
        $s = wsSchedule();
        $t = WandPlayService::simpleTimeline([4500], $s);
        expect($t['cues'][0])->toBe(['cue_ms' => 4500, 'caught' => true, 'release_ms' => 4500])
            ->and($t['cues'][1]['cue_ms'])->toBe(10500);
        $late = WandPlayService::simpleTimeline([6500], $s);
        expect($late['cues'][0]['caught'])->toBeTrue()->and($late['cues'][1]['cue_ms'])->toBe(12500);
    });

    it('letting go too early or never is no fault: no catch, the cat crouches again 2.5 s after the window', function () {
        $s = wsSchedule();
        $early = WandPlayService::scoreSimple([1000, 4499], $s);
        expect($early['cues'][0])->toBe(['cue_ms' => 4500, 'caught' => false, 'release_ms' => null])
            ->and($early['cues'][1]['cue_ms'])->toBe(9000)
            ->and($early['stars'])->toBe(0)
            ->and($early['success'])->toBeFalse()
            ->and($early['reason'])->toBe('too_few_catches')
            ->and($early['hint'])->toBe('let_go');

        $never = WandPlayService::scoreSimple([], $s);
        expect($never['cues'])->toHaveCount(6)
            ->and(collect($never['cues'])->pluck('cue_ms')->all())->toBe([4500, 9000, 13500, 18000, 22500, 27000])
            ->and($never['stars'])->toBe(0)
            ->and(WandPlayService::simpleTimeline([], $s)['done_ms'])->toBe(29000);
    });

    it('three catches end the hunt and count; releases during a catch change nothing', function () {
        $s = wsSchedule();
        // Catch at 5200 → next cue 11200; a nervous tap at 8000 (during the catch) is ignored.
        $r = WandPlayService::scoreSimple([5200, 8000, 12000, 18500], $s);
        expect($r['success'])->toBeTrue()
            ->and($r['reason'])->toBeNull()
            ->and($r['hint'])->toBeNull()
            ->and([$r['stars'], $r['min_stars'], $r['max_stars']])->toBe([3, 3, 3])
            ->and(collect($r['cues'])->pluck('cue_ms')->all())->toBe([4500, 11200, 18000])
            ->and(WandPlayService::simpleTimeline([5200, 8000, 12000, 18500], $s)['done_ms'])->toBe(18500 + 3500);

        // A miss in between: still done at the third catch (~20–30 s for a child).
        $mixed = WandPlayService::scoreSimple([5000, 16000, 23000], $s);
        expect(collect($mixed['cues'])->pluck('caught')->all())->toBe([true, false, true, true])
            ->and($mixed['success'])->toBeTrue();
    });

    it('scores a session stored with the old strict v2 schedule without crashing (no releases → not counted)', function () {
        $legacy = ['rules_version' => 2, 'duration_ms' => 26000, 'pounces_ms' => [7000, 13000, 19000], 'crouch_cues_ms' => [5500, 11500, 17500]];
        $r = WandPlayService::scoreSimple([], $legacy);
        expect($r['success'])->toBeFalse()->and($r['cues'][0]['cue_ms'])->toBe(5500)
            ->and(WandPlayService::scoreSimple([5600, 11700, 17700], $legacy)['success'])->toBeTrue();
    });
});

describe('finish over HTTP', function () {
    it('counts three catches (meter, played_wand row, the 2 h gap) and accepts the finish as soon as the third catch played', function () {
        [, $child, $cat] = wsFamily();
        $s = wsPost($child, '/api/child/pet/wand/start', ['rules' => 3])->assertOk()->json('session');
        $start = Carbon::parse($s['started_at'])->utc();
        $c1 = $s['first_cue_ms'] + 600;
        $c2 = $c1 + 6000 + 900;
        $c3 = $c2 + 6000 + 300;
        $releases = [$c1, $c2, $c3];

        Carbon::setTestNow($start->copy()->addMilliseconds($c3 + 3500 - 1));
        wsPost($child, '/api/child/pet/wand/finish', ['session_id' => $s['id'], 'moves' => [], 'releases' => $releases])
            ->assertUnprocessable()->assertJsonPath('reason', 'wand_session_not_over');

        Carbon::setTestNow($start->copy()->addMilliseconds($c3 + 3500));
        $finish = wsPost($child, '/api/child/pet/wand/finish', ['session_id' => $s['id'], 'moves' => [], 'releases' => $releases])
            ->assertOk()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('result.success', true)
            ->assertJsonPath('result.rules_version', 3)
            ->assertJsonPath('result.stars', 3)
            ->assertJsonPath('result.max_stars', 3)
            ->assertJsonPath('result.hint', null);
        expect($finish->json('result.cues'))->toHaveCount(3)
            ->and($finish->json('result.cues.0'))->toBe(['cue_ms' => $s['first_cue_ms'], 'caught' => true, 'release_ms' => $c1])
            ->and($finish->json('state.pet.energy_level'))->toBe(50)
            ->and($finish->json('state.wand.blocked_reason'))->toBe('wand_too_soon')
            ->and(ActivityLog::where('pet_id', $cat->id)->where('activity_type', 'played_wand')->count())->toBe(1);

        wsPost($child, '/api/child/pet/wand/start', ['rules' => 3])->assertUnprocessable()->assertJsonPath('reason', 'wand_too_soon');
    });

    it('a hunt without three catches does not count (no penalty, start again at once); finish after the last window', function () {
        [, $child, $cat] = wsFamily();
        $s = wsPost($child, '/api/child/pet/wand/start', ['rules' => 3])->assertOk()->json('session');
        $start = Carbon::parse($s['started_at'])->utc();
        $lastWindowEnd = $s['first_cue_ms'] + 5 * (2000 + 2500) + 2000;

        Carbon::setTestNow($start->copy()->addMilliseconds($lastWindowEnd));
        wsPost($child, '/api/child/pet/wand/finish', ['session_id' => $s['id'], 'moves' => [], 'releases' => [100]])
            ->assertOk()
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('result.reason', 'too_few_catches')
            ->assertJsonPath('result.stars', 0)
            ->assertJsonPath('result.hint', 'let_go');
        expect(ActivityLog::where('pet_id', $cat->id)->where('activity_type', 'played_wand')->count())->toBe(0);
        wsPost($child, '/api/child/pet/wand/start', ['rules' => 3])->assertOk();
    });

    it('refuses releases outside the session and non-integer releases', function () {
        [, $child] = wsFamily();
        $s = wsPost($child, '/api/child/pet/wand/start', ['rules' => 3])->assertOk()->json('session');
        Carbon::setTestNow(Carbon::parse($s['started_at'])->utc()->addMilliseconds($s['duration_ms']));

        wsPost($child, '/api/child/pet/wand/finish', ['session_id' => $s['id'], 'moves' => [], 'releases' => ['soon']])
            ->assertUnprocessable()->assertJsonValidationErrors('releases.0');
        wsPost($child, '/api/child/pet/wand/finish', ['session_id' => $s['id'], 'moves' => [], 'releases' => [$s['duration_ms'] + 1]])
            ->assertUnprocessable()->assertJsonPath('reason', 'wand_invalid_moves');
    });

    it('an older build finishing a simple hunt with strict-v2 moves is not counted — never a crash', function () {
        [, $child] = wsFamily();
        $s = wsPost($child, '/api/child/pet/wand/start', ['rules' => 2])->assertOk()->json('session');
        Carbon::setTestNow(Carbon::parse($s['started_at'])->utc()->addMilliseconds($s['duration_ms']));
        wsPost($child, '/api/child/pet/wand/finish', ['session_id' => $s['id'], 'moves' => [['t' => 7400, 'away' => true, 'in_zone' => true, 'speed_ok' => true]]])
            ->assertOk()->assertJsonPath('status', 'rejected')->assertJsonPath('result.stars', 0);
    });

    it('refuses a start in / right before quiet hours and before midnight (the v1 60 s + TTL is reserved)', function () {
        [, $child, $cat] = wsFamily();
        wsAt('2026-10-21 20:58:30');
        wsPost($child, '/api/child/pet/wand/start', ['rules' => 3])
            ->assertUnprocessable()->assertJsonPath('reason', 'wand_quiet_hours');
        QuietHours::where('family_id', $cat->family_id)->update(['bedtime_start' => '01:00', 'bedtime_end' => '07:00']);
        wsAt('2026-10-21 23:58:30');
        wsPost($child, '/api/child/pet/wand/start', ['rules' => 3])
            ->assertUnprocessable()->assertJsonPath('reason', 'wand_day_ending');
    });

    it('keeps a v1 session on the v1 rules (releases are ignored) with the v1 result keys', function () {
        [, $child] = wsFamily();
        $s = wsPost($child, '/api/child/pet/wand/start')->assertOk()->json('session');
        Carbon::setTestNow(now()->addSeconds(60));
        $result = wsPost($child, '/api/child/pet/wand/finish', ['session_id' => $s['id'], 'moves' => [['t' => 1000, 'away' => true]], 'releases' => [5000]])
            ->assertOk()->assertJsonPath('status', 'rejected')->json('result');
        expect(array_keys($result))->toBe(['session_id', 'success', 'reason', 'away_moves', 'toward_moves', 'segments_hit', 'segments', 'pounces_hit', 'pounces'])
            ->and($result['reason'])->toBe('too_few_moves');
    });
});
