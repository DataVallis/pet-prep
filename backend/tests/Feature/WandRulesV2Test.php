<?php

use App\Enums\BreedType;
use App\Models\ActivityLog;
use App\Models\Pet;
use App\Models\PetCareSession;
use App\Models\User;
use App\Services\WandPlayService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/*
|--------------------------------------------------------------------------
| Games phase 2 — wand rules v2 (GAMES_SPEC §5.2–§5.4, §7.2, David 2026-10-30)
|--------------------------------------------------------------------------
| P1: one ~25 s hunt ending with the catch. P2: scored in stars — 3 dodges +
| the catch, counted at ≥ 2. A dodge counts only when the feather was still
| during the crouch cue, the first pull-away comes 150–1500 ms after the
| pounce, ends inside the zone and is a clean stroke. Spam earns nothing.
| Versioned: only a start with `rules: 2`; without it v1 is byte for byte.
| Goal, 2 h gap, meter, routine and the scratched sofa are unchanged.
*/

afterEach(function () {
    Carbon::setTestNow();
});

function wv2At(string $local): void
{
    Carbon::setTestNow(Carbon::parse($local, 'Europe/Ljubljana')->utc());
}

/** @return array{0: User, 1: User, 2: Pet} */
function wv2Family(): array
{
    seedLifeStageData();
    wv2At('2026-10-20 08:00');
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
    wv2At('2026-10-21 09:00');
    Artisan::call('pets:process-decay');

    return [$parent, $child, $cat->fresh()];
}

function wv2Post(User $child, string $uri, array $body = [])
{
    test()->actingAs($child, 'sanctum');
    $response = test()->postJson($uri, $body);
    app('auth')->forgetGuards();

    return $response;
}

/** A fixed v2 schedule (pounces 7000 / 13000 / 19000, catch 22500, end 26000) for the pure tests. */
function wv2Schedule(): array
{
    return [
        'rules_version' => 2, 'duration_ms' => 26000, 'stalk_ms' => 3000,
        'pounces_ms' => [7000, 13000, 19000], 'crouch_cues_ms' => [6100, 12100, 18100],
        'catch_at_ms' => 22500, 'catch_window_ms' => 1500, 'calm_window_ms' => 800, 'calm_max_moves' => 1,
        'dodge_window_ms' => [150, 1500], 'min_move_interval_ms' => 300, 'min_stars' => 2, 'max_stars' => 4,
        'max_speed_pt_s' => 4000, 'min_dodge_pt' => 40,
    ];
}

function wv2Move(int $t, bool $away = true, bool $inZone = true, bool $speedOk = true): array
{
    return ['t' => $t, 'away' => $away, 'in_zone' => $inZone, 'speed_ok' => $speedOk];
}

/**
 * A fair, human-like child: wiggles the feather now and then (irregular, inside the zone),
 * keeps it still from the 👀 cue until the pounce, pulls it away 300–700 ms after each
 * pounce, and lets the cat catch it (no pull-away after the catch cue).
 *
 * @param  array<string, mixed>  $s  a v2 schedule
 */
function wv2FairMoves(array $s): array
{
    $moves = [];
    $jitter = [0, 410, 170, 620, 90, 350];
    $t = 900;
    $k = 0;
    $quiet = array_map(fn (int $p): array => [$p - 1100, $p + 1600], $s['pounces_ms']);
    while ($t < $s['catch_at_ms'] - 400) {
        $calm = false;
        foreach ($quiet as [$a, $b]) {
            $calm = $calm || ($t >= $a && $t < $b);
        }
        if (! $calm) {
            // Slow wiggles: mostly away, sometimes back toward the cat.
            $moves[] = wv2Move($t, $k % 3 !== 2);
        }
        $t += 1300 + $jitter[$k++ % count($jitter)];
    }
    foreach ($s['pounces_ms'] as $i => $p) {
        $moves[] = wv2Move($p + [420, 610, 330][$i % 3]);
    }

    return $moves;
}

/** Continuous rapid away strokes for the whole session (human-ish irregular spam, 300–420 ms). */
function wv2SpamMoves(int $durationMs): array
{
    $moves = [];
    $gaps = [310, 380, 330, 420, 300, 360, 345];
    for ($t = 200, $k = 0; $t <= $durationMs; $t += $gaps[$k++ % count($gaps)]) {
        $moves[] = wv2Move($t);
    }

    return $moves;
}

describe('schedule (GAMES_SPEC §5.2)', function () {
    it('draws one ~23–29 s hunt: stalk 3 s, three pounces with a crouch cue 900 ms before, the catch 3.5 s after the last', function () {
        foreach (range(1, 40) as $seed) {
            $s = WandPlayService::scheduleV2(new Randomizer(new Xoshiro256StarStar(hash('sha256', "wv2-{$seed}", true))));
            [$p1, $p2, $p3] = $s['pounces_ms'];
            expect($s['rules_version'])->toBe(2)
                ->and($p1)->toBeGreaterThanOrEqual(6000)->toBeLessThanOrEqual(7500)
                ->and($p2 - $p1)->toBeGreaterThanOrEqual(5000)->toBeLessThanOrEqual(6500)
                ->and($p3 - $p2)->toBeGreaterThanOrEqual(5000)->toBeLessThanOrEqual(6500)
                ->and($s['crouch_cues_ms'])->toBe([$p1 - 900, $p2 - 900, $p3 - 900])
                ->and($s['catch_at_ms'])->toBe($p3 + 3500)
                ->and($s['duration_ms'])->toBe($p3 + 7000)
                ->and($s['duration_ms'])->toBeGreaterThanOrEqual(23000)->toBeLessThanOrEqual(27500)
                ->and($s['min_stars'])->toBe(2)->and($s['max_stars'])->toBe(4)
                ->and($s['dodge_window_ms'])->toBe([150, 1500])
                ->and([$s['calm_window_ms'], $s['calm_max_moves'], $s['catch_window_ms']])->toBe([800, 1, 1500])
                ->and([$s['max_speed_pt_s'], $s['min_dodge_pt']])->toBe([4000, 40]);
        }
    });

    it('starts a v2 session only with rules: 2 and stores its version; without it the v1 payload keys are unchanged', function () {
        [, $child] = wv2Family();

        $v1 = wv2Post($child, '/api/child/pet/wand/start')->assertOk()->json('session');
        expect(array_keys($v1))->toBe(['id', 'started_at', 'ends_at', 'expires_at', 'duration_ms', 'catch_at_ms', 'pounces_ms',
            'min_away_moves', 'segments', 'min_move_interval_ms', 'pounce_window_ms'])
            ->and($v1['duration_ms'])->toBe(60000)
            ->and(PetCareSession::where('public_id', $v1['id'])->sole()->schedule)->not->toHaveKey('rules_version');

        $start = wv2Post($child, '/api/child/pet/wand/start', ['rules' => 2])->assertOk();
        $v2 = $start->json('session');
        $stored = PetCareSession::where('public_id', $v2['id'])->sole();
        expect($v2['rules_version'])->toBe(2)
            ->and($v2['duration_ms'])->toBe($stored->schedule['duration_ms'])
            ->and($v2['duration_ms'])->toBeLessThanOrEqual(27500)
            ->and($v2['catch_at_ms'])->toBe($v2['pounces_ms'][2] + 3500)
            ->and($v2['crouch_cues_ms'])->toHaveCount(3)
            ->and($v2)->toHaveKeys(['stalk_ms', 'catch_window_ms', 'calm_window_ms', 'calm_max_moves', 'dodge_window_ms', 'min_stars', 'max_stars', 'max_speed_pt_s', 'min_dodge_pt'])
            ->and($stored->schedule['rules_version'])->toBe(2)
            ->and($stored->duration_ms)->toBe($v2['duration_ms'])
            // ends_at is stored to the second (like the school's).
            ->and($stored->ends_at->diffInMilliseconds($stored->started_at, true))->toEqualWithDelta($v2['duration_ms'], 1000)
            // The resumable session in the child state carries the same v2 keys.
            ->and($start->json('state.wand.session.rules_version'))->toBe(2)
            ->and($start->json('state.wand.session.crouch_cues_ms'))->toBe($v2['crouch_cues_ms']);
        // The same child's v1 game was replaced (no penalty).
        expect(PetCareSession::where('public_id', $v1['id'])->sole()->status->value)->toBe('aborted');

        wv2Post($child, '/api/child/pet/wand/start', ['rules' => 3])->assertUnprocessable()->assertJsonValidationErrors('rules');
    });
});

describe('stars (GAMES_SPEC §5.3)', function () {
    it('passes a fair, human-like player with all four stars', function () {
        $s = wv2Schedule();
        $r = WandPlayService::scoreV2(wv2FairMoves($s), $s);

        expect($r['success'])->toBeTrue()->and($r['reason'])->toBeNull()
            ->and($r['stars'])->toBe(4)->and($r['hint'])->toBeNull()
            ->and(array_column($r['dodges'], 'star'))->toBe([true, true, true])
            ->and($r['catch'])->toBe(['star' => true, 'reason' => null]);
    });

    it('gives continuous rapid away strokes for the whole session 0 dodge stars and no catch star (the spam exploit)', function () {
        $s = wv2Schedule();
        $r = WandPlayService::scoreV2(wv2SpamMoves($s['duration_ms']), $s);

        expect($r['success'])->toBeFalse()->and($r['reason'])->toBe('too_few_stars')
            ->and($r['stars'])->toBe(0)
            ->and(array_column($r['dodges'], 'star'))->toBe([false, false, false])
            ->and(array_unique(array_column($r['dodges'], 'reason')))->toBe(['moved_during_crouch'])
            ->and($r['catch'])->toBe(['star' => false, 'reason' => 'pulled_away'])
            ->and($r['hint'])->toBe('calm');

        // Faster bursts (every 120 ms, collapsed by the 300 ms rule) do no better.
        $fast = [];
        for ($t = 0; $t <= $s['duration_ms']; $t += 120) {
            $fast[] = wv2Move($t);
        }
        expect(WandPlayService::scoreV2($fast, $s)['stars'])->toBe(0);
    });

    it('rewards only the first pull-away 150–1500 ms after the pounce, in the zone, as a clean stroke', function () {
        $s = wv2Schedule();
        $dodge = fn (array $move): array => WandPlayService::scoreV2([$move], ['pounces_ms' => [7000]] + $s)['dodges'][0];

        expect($dodge(wv2Move(7400)))->toBe(['pounce_ms' => 7000, 'star' => true, 'reason' => null, 'move_ms' => 7400])
            ->and($dodge(wv2Move(7149))['reason'])->toBe('too_early')
            ->and($dodge(wv2Move(7150))['star'])->toBeTrue()
            ->and($dodge(wv2Move(8500))['star'])->toBeTrue()
            ->and($dodge(wv2Move(8501))['reason'])->toBe('no_dodge')
            ->and($dodge(wv2Move(7400, away: false))['reason'])->toBe('no_dodge')
            // A swipe off the screen / out of the zone never counts.
            ->and($dodge(wv2Move(7400, inZone: false))['reason'])->toBe('out_of_zone')
            ->and($dodge(wv2Move(7400, speedOk: false))['reason'])->toBe('bad_stroke')
            // A move without the v2 fields fails closed.
            ->and($dodge(['t' => 7400, 'away' => true])['reason'])->toBe('out_of_zone');

        // Only the first pull-away counts: an out-of-zone one is not saved by a later good one.
        $r = WandPlayService::scoreV2([wv2Move(7300, inZone: false), wv2Move(7900)], ['pounces_ms' => [7000]] + $s);
        expect($r['dodges'][0]['reason'])->toBe('out_of_zone');
    });

    it('allows one move in the calm window, not two (any direction)', function () {
        $s = ['pounces_ms' => [7000]] + wv2Schedule();

        expect(WandPlayService::scoreV2([wv2Move(6500, away: false), wv2Move(7400)], $s)['dodges'][0]['star'])->toBeTrue()
            ->and(WandPlayService::scoreV2([wv2Move(6300, away: false), wv2Move(6700, away: false), wv2Move(7400)], $s)['dodges'][0]['reason'])->toBe('moved_during_crouch')
            // Moves before the window (p − 800) are fine.
            ->and(WandPlayService::scoreV2([wv2Move(5800), wv2Move(6150), wv2Move(7400)], $s)['dodges'][0]['star'])->toBeTrue();
    });

    it('counts the hunt at two stars and names the first missing rule as the one hint', function () {
        $s = wv2Schedule();
        // Dodge 1 good, dodges 2 / 3 missed (no reaction), the catch left alone → 2 stars.
        $two = WandPlayService::scoreV2([wv2Move(7400)], $s);
        expect($two['stars'])->toBe(2)->and($two['success'])->toBeTrue()->and($two['hint'])->toBe('dodge');

        // Only the catch → 1 star, not counted; the hint is the first failed event (pounce 1: no reaction).
        $one = WandPlayService::scoreV2([], $s);
        expect($one['stars'])->toBe(1)->and($one['success'])->toBeFalse()->and($one['hint'])->toBe('dodge');

        // Pulled away at the catch: no catch star; feather left outside the zone: no catch star.
        expect(WandPlayService::scoreV2([wv2Move(23000)], $s)['catch'])->toBe(['star' => false, 'reason' => 'pulled_away'])
            ->and(WandPlayService::scoreV2([wv2Move(21000, away: false, inZone: false)], $s)['catch'])->toBe(['star' => false, 'reason' => 'out_of_zone'])
            // Drawing it toward the cat at the catch is fine.
            ->and(WandPlayService::scoreV2([wv2Move(22800, away: false)], $s)['catch']['star'])->toBeTrue();
    });
});

describe('finish over HTTP', function () {
    it('counts a fair v2 hunt (meter, played_wand row, the 2 h gap) and returns the stars', function () {
        [, $child, $cat] = wv2Family();
        $session = wv2Post($child, '/api/child/pet/wand/start', ['rules' => 2])->assertOk()->json('session');
        $schedule = PetCareSession::where('public_id', $session['id'])->sole()->schedule;

        // Not before the end (− 5 s tolerance).
        Carbon::setTestNow(now()->addMilliseconds($session['duration_ms'] - 6000));
        wv2Post($child, '/api/child/pet/wand/finish', ['session_id' => $session['id'], 'moves' => []])
            ->assertUnprocessable()->assertJsonPath('reason', 'wand_session_not_over');

        Carbon::setTestNow(now()->addMilliseconds(6000));
        $finish = wv2Post($child, '/api/child/pet/wand/finish', ['session_id' => $session['id'], 'moves' => wv2FairMoves($schedule)])
            ->assertOk()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('result.success', true)
            ->assertJsonPath('result.rules_version', 2)
            ->assertJsonPath('result.stars', 4)
            ->assertJsonPath('result.min_stars', 2)
            ->assertJsonPath('result.max_stars', 4)
            ->assertJsonPath('result.catch.star', true)
            ->assertJsonPath('result.hint', null);
        expect($finish->json('result.dodges'))->toHaveCount(3)
            ->and($finish->json('state.pet.energy_level'))->toBe(50)
            ->and($finish->json('state.wand.blocked_reason'))->toBe('wand_too_soon')
            ->and(ActivityLog::where('pet_id', $cat->id)->where('activity_type', 'played_wand')->count())->toBe(1);

        // The 2 h gap is unchanged.
        wv2Post($child, '/api/child/pet/wand/start', ['rules' => 2])->assertUnprocessable()->assertJsonPath('reason', 'wand_too_soon');
    });

    it('rejects a spammed v2 hunt over HTTP — nothing counts, start again at once', function () {
        [, $child, $cat] = wv2Family();
        $session = wv2Post($child, '/api/child/pet/wand/start', ['rules' => 2])->assertOk()->json('session');
        Carbon::setTestNow(now()->addMilliseconds($session['duration_ms']));

        wv2Post($child, '/api/child/pet/wand/finish', ['session_id' => $session['id'], 'moves' => wv2SpamMoves($session['duration_ms'])])
            ->assertOk()
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('result.reason', 'too_few_stars')
            ->assertJsonPath('result.stars', 0)
            ->assertJsonPath('result.hint', 'calm');
        expect(ActivityLog::where('pet_id', $cat->id)->where('activity_type', 'played_wand')->count())->toBe(0);
        wv2Post($child, '/api/child/pet/wand/start', ['rules' => 2])->assertOk();
    });

    it('keeps a v1 session on the v1 rules even when moves carry the v2 fields; refuses non-boolean fields', function () {
        [, $child] = wv2Family();
        $session = wv2Post($child, '/api/child/pet/wand/start')->assertOk()->json('session');
        Carbon::setTestNow(now()->addSeconds(60));

        wv2Post($child, '/api/child/pet/wand/finish', ['session_id' => $session['id'], 'moves' => [['t' => 100, 'away' => true, 'in_zone' => 'maybe']]])
            ->assertUnprocessable()->assertJsonValidationErrors('moves.0.in_zone');
        wv2Post($child, '/api/child/pet/wand/finish', ['session_id' => $session['id'], 'moves' => [['t' => 100, 'away' => true, 'x' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('moves.0');

        $result = wv2Post($child, '/api/child/pet/wand/finish', ['session_id' => $session['id'], 'moves' => [wv2Move(1000)]])
            ->assertOk()->assertJsonPath('status', 'rejected')->json('result');
        expect(array_keys($result))->toBe(['session_id', 'success', 'reason', 'away_moves', 'toward_moves', 'segments_hit', 'segments', 'pounces_hit', 'pounces'])
            ->and($result['reason'])->toBe('too_few_moves');
    });
});
