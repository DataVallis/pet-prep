<?php

use App\Enums\ActivityType;
use App\Enums\TrainingCommand;
use App\Models\ActivityLog;
use App\Models\Pet;
use App\Models\PetItem;
use App\Models\PetTrainingSession;
use App\Models\PetTrainingSkill;
use App\Models\User;
use App\Services\TrainingService;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| Games phase 3 — school rules v2 (GAMES_SPEC §4.1 / §4.2 / §7.2, David 2026-10-30)
|--------------------------------------------------------------------------
| Š1: 2 rounds × 3 cues with a 2.5 s break. Š2: no reversed release clip — R =
| MASK_MS 1200 (the cut hides under the feedback mask). Š3: the child may stop
| after round 1: progress counts, the »Šolanje« routine only a full session.
| Versioned: only a start with `rules: 2`; without it v1 is byte for byte.
| Praise window, obey chance, progress, budget, fair share: unchanged.
| Treat token (David 2026-10-30, V6): display only, from the »Želje« treats.
*/

beforeEach(function () {
    seedLifeStageData();
});

afterEach(function () {
    Carbon::setTestNow();
});

/** @return array{0: User, 1: User, 2: Pet} */
function tv2Family(): array
{
    Carbon::setTestNow(Carbon::parse('2026-10-07 05:00:00', 'UTC'));
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => 'Maja']);
    $pet = Pet::factory()->mutt()->create([
        'user_id' => $child->id,
        'born_at' => now(),
        'last_decay_at' => now(),
        'last_step_reset_at' => now(),
        'arrival_age_months' => 2,
        'training_enabled' => true,
    ]);
    disableHygieneEvents($pet);
    Carbon::setTestNow(Carbon::parse('2026-10-07 08:00:00', 'UTC'));

    return [$parent, $child, $pet->fresh()];
}

function tv2Post(User $child, string $uri, array $body): TestResponse
{
    app('auth')->forgetGuards();
    actingAsRole($child);

    return postJson($uri, $body);
}

/** Taps 400 ms + after every obey instant of the given trials (a good child). */
function tv2Taps(array $trials): array
{
    $taps = [];
    foreach ($trials as $trial) {
        if ($trial['obeys']) {
            $taps[] = $trial['obey_at_ms'] + 400 + 30 * count($taps);
        }
    }

    return $taps;
}

function tv2Rng(string $seed): Randomizer
{
    return new Randomizer(new Xoshiro256StarStar(hash('sha256', $seed, true)));
}

describe('schedule (Š1, Š2)', function () {
    it('draws 2 rounds × 3 cues with a 2.5 s break after cue 3 and R = the 1.2 s mask; the same draw as v1', function () {
        $service = app(TrainingService::class);
        foreach (range(1, 25) as $n) {
            $v1 = $service->schedule(40.0, tv2Rng("tv2-{$n}"), 5000, 2400);
            $v2 = $service->schedule(40.0, tv2Rng("tv2-{$n}"), 5000, 2400, TrainingService::RULES_V2);
            $t = $v2['trials'];

            expect($v2['rules_version'])->toBe(2)
                ->and($v2['mask_ms'])->toBe(1200)
                ->and($v2['round_break_ms'])->toBe(2500)
                ->and($t)->toHaveCount(6)
                // Same obeys / think times as v1 (only R and the break differ).
                ->and(array_column($t, 'obeys'))->toBe(array_column($v1['trials'], 'obeys'))
                ->and(array_map(fn ($a) => $a['perform_at_ms'] === null ? null : $a['perform_at_ms'] - $a['cue_at_ms'], $t))
                ->toBe(array_map(fn ($a) => $a['perform_at_ms'] === null ? null : $a['perform_at_ms'] - $a['cue_at_ms'], $v1['trials']))
                ->and($t[0]['cue_at_ms'])->toBe(2000);

            $slotEnd = fn (array $trial): int => $trial['obeys'] ? $trial['release_at_ms'] + 1200 + 700 : $trial['cue_at_ms'] + 4500;
            foreach ([1, 2, 4, 5] as $i) {
                expect($t[$i]['cue_at_ms'])->toBe($slotEnd($t[$i - 1]));
            }
            $round1End = $slotEnd($t[2]);
            expect($t[3]['cue_at_ms'])->toBe($round1End + 2500)
                ->and($v2['round_breaks_ms'])->toBe([$round1End])
                ->and($v2['rounds'])->toBe([
                    ['round' => 1, 'first_index' => 0, 'last_index' => 2, 'start_ms' => 0, 'end_ms' => $round1End],
                    ['round' => 2, 'first_index' => 3, 'last_index' => 5, 'start_ms' => $round1End + 2500, 'end_ms' => $slotEnd($t[5])],
                ])
                ->and($v2['duration_ms'])->toBe($slotEnd($t[5]))
                ->and($v2['duration_ms'])->toBeLessThanOrEqual(TrainingService::maxSessionMs(5000, 2400, TrainingService::RULES_V2));
            foreach ($t as $trial) {
                if ($trial['obeys']) {
                    expect($trial['release_at_ms'])->toBe($trial['window_end_ms'])
                        ->and($trial['window_end_ms'] - $trial['obey_at_ms'])->toBe(1500);
                }
            }
            // v1 keeps its shape (no v2 keys).
            expect(array_keys($v1))->toBe(['praise_window_ms', 'min_reaction_ms', 'duration_ms', 'trials']);
        }
    });

    it('computes the v2 maximum (mask + one break) and reserves per version; the state the larger of both', function () {
        // 2000 + 6 × (1500 + 5000 + 1500 + 1200 + 700) + 2500 = 63 900 (v1 with R 2.5 s: 69 200).
        expect(TrainingService::maxSessionMs(5000, 2500, TrainingService::RULES_V2))->toBe(63900)
            ->and(TrainingService::maxSessionMs(5000, 2500))->toBe(69200)
            // A short stored release clip: v2 (mask 1.2 s + break) can be the longer one.
            ->and(TrainingService::maxSessionMs(3000, 500, TrainingService::RULES_V2))->toBe(51900)
            ->and(TrainingService::maxSessionMs(3000, 500))->toBe(45200);

        [, $child, $pet] = tv2Family();
        $service = app(TrainingService::class);
        expect($service->maxSessionMsFor($pet))->toBe(max($service->maxSessionMsFor($pet, 1), $service->maxSessionMsFor($pet, 2)))
            ->and(tv2Post($child, '/api/child/pet/training/start', ['command' => 'sit', 'rules' => 2])->assertOk()->json('state.training.session_seconds'))
            ->toBe($service->sessionSecondsFor($pet));
    });
});

describe('start / finish over HTTP', function () {
    it('starts v2 only with rules: 2 (session carries the v2 keys); without it the v1 payload keys are unchanged', function () {
        [, $child] = tv2Family();

        $v1 = tv2Post($child, '/api/child/pet/training/start', ['command' => 'sit'])->assertOk()->json('session');
        expect(array_keys($v1))->toBe(['id', 'command', 'started_at', 'ends_at', 'expires_at', 'duration_ms', 'praise_window_ms', 'min_reaction_ms', 'trials'])
            ->and(PetTrainingSession::where('public_id', $v1['id'])->sole()->schedule)->not->toHaveKey('rules_version');
        PetTrainingSession::query()->update(['status' => PetTrainingSession::STATUS_EXPIRED]);

        $start = tv2Post($child, '/api/child/pet/training/start', ['command' => 'come', 'rules' => 2])->assertOk();
        $v2 = $start->json('session');
        expect($v2['rules_version'])->toBe(2)
            ->and($v2['mask_ms'])->toBe(1200)
            ->and($v2['round_break_ms'])->toBe(2500)
            ->and($v2['round_breaks_ms'])->toBe([$v2['rounds'][0]['end_ms']])
            ->and($v2['rounds'])->toHaveCount(2)
            ->and($v2['duration_ms'])->toBe($v2['rounds'][1]['end_ms'])
            // Resume after an app restart: the child's own running session carries the same keys.
            ->and($start->json('state.training.session.rules_version'))->toBe(2)
            ->and($start->json('state.training.session.rounds'))->toBe($v2['rounds']);

        tv2Post($child, '/api/child/pet/training/start', ['command' => 'sit', 'rules' => 5])
            ->assertUnprocessable()->assertJsonValidationErrors('rules');
    });

    it('scores a full v2 session as before: progress, one trained_pet row, the routine', function () {
        [, $child, $pet] = tv2Family();
        $session = tv2Post($child, '/api/child/pet/training/start', ['command' => 'sit', 'rules' => 2])->assertOk()->json('session');
        Carbon::setTestNow(Carbon::parse($session['ends_at'])->utc());

        $finish = tv2Post($child, '/api/child/pet/training/finish', ['session_id' => $session['id'], 'taps' => tv2Taps($session['trials'])])
            ->assertOk()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('result.rules_version', 2)
            ->assertJsonPath('result.ended_after_round', null)
            ->assertJsonPath('result.counts_for_routine', true)
            ->assertJsonPath('state.training.today_done', true);
        $obeyed = count(array_filter($session['trials'], fn ($t) => $t['obeys']));
        expect($finish->json('result.successes'))->toBe($obeyed)
            ->and($finish->json('result.trials'))->toHaveCount(6)
            ->and(ActivityLog::where('pet_id', $pet->id)->where('activity_type', ActivityType::TrainedPet->value)->count())->toBe(1);
    });

    it('ends after round 1 (Š3): round 1 scored, progress × 8 / 6, no routine, the budget counts round 1 only', function () {
        [, $child, $pet] = tv2Family();
        $session = tv2Post($child, '/api/child/pet/training/start', ['command' => 'sit', 'rules' => 2])->assertOk()->json('session');
        $round1 = $session['rounds'][0];
        $start = Carbon::parse($session['started_at'])->utc();
        $taps = tv2Taps($session['trials']); // also taps in round 2 — never scored after an early end

        // Not before round 1 is over (− 2 s tolerance).
        Carbon::setTestNow($start->copy()->addMilliseconds($round1['end_ms'] - 2500));
        tv2Post($child, '/api/child/pet/training/finish', ['session_id' => $session['id'], 'taps' => [], 'ended_after_round' => 1])
            ->assertUnprocessable()->assertJsonPath('reason', 'training_session_not_over');

        Carbon::setTestNow($start->copy()->addMilliseconds($round1['end_ms']));
        $finish = tv2Post($child, '/api/child/pet/training/finish', ['session_id' => $session['id'], 'taps' => $taps, 'ended_after_round' => 1])
            ->assertOk()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('result.ended_after_round', 1)
            ->assertJsonPath('result.counts_for_routine', false)
            ->assertJsonPath('state.training.today_done', false);

        $obeyedRound1 = count(array_filter(array_slice($session['trials'], 0, 3), fn ($t) => $t['obeys']));
        $service = app(TrainingService::class);
        $expected = $obeyedRound1 * $service->gainPerSuccess($pet->fresh(), '2026-10-07') * 8 / 6;
        expect($finish->json('result.trials'))->toHaveCount(3)
            ->and($finish->json('result.successes'))->toBe($obeyedRound1)
            ->and((float) PetTrainingSkill::where('pet_id', $pet->id)->where('command', TrainingCommand::Sit->value)->value('progress'))
            ->toEqualWithDelta($expected, 0.0001)
            ->and(ActivityLog::where('pet_id', $pet->id)->where('activity_type', ActivityType::TrainedPet->value)->count())->toBe(0)
            ->and(PetTrainingSession::where('public_id', $session['id'])->sole()->duration_ms)->toBe($round1['end_ms'])
            ->and($service->usedSecondsOn($pet->fresh(), '2026-10-07'))->toBe(intdiv($round1['end_ms'] + 999, 1000))
            ->and($service->doneOn($pet->fresh(), now()))->toBeFalse();

        // A repeat → unchanged, still no routine row.
        tv2Post($child, '/api/child/pet/training/finish', ['session_id' => $session['id'], 'taps' => $taps, 'ended_after_round' => 1])
            ->assertOk()->assertJsonPath('status', 'unchanged')->assertJsonPath('result.ended_after_round', 1);
        expect(ActivityLog::where('pet_id', $pet->id)->where('activity_type', ActivityType::TrainedPet->value)->count())->toBe(0);
    });

    it('refuses an early end for a v1 session (training_session_not_over) and validates the field', function () {
        [, $child] = tv2Family();
        $session = tv2Post($child, '/api/child/pet/training/start', ['command' => 'sit'])->assertOk()->json('session');
        Carbon::setTestNow(Carbon::parse($session['ends_at'])->utc());

        tv2Post($child, '/api/child/pet/training/finish', ['session_id' => $session['id'], 'taps' => [], 'ended_after_round' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('ended_after_round');
        tv2Post($child, '/api/child/pet/training/finish', ['session_id' => $session['id'], 'taps' => [], 'ended_after_round' => 1])
            ->assertUnprocessable()->assertJsonPath('reason', 'training_session_not_over');

        // The v1 result keeps its shape.
        $result = tv2Post($child, '/api/child/pet/training/finish', ['session_id' => $session['id'], 'taps' => tv2Taps($session['trials'])])
            ->assertOk()->json('result');
        expect(array_keys($result))->toBe(['session_id', 'command', 'successes', 'obeyed', 'trials', 'progress_before', 'progress_after', 'progress_gain']);
    });
});

describe('treat token (David 2026-10-30, V6)', function () {
    it('is true only while the pet has a bought, unrevoked treats pack — display only, never consumed', function () {
        [, $child, $pet] = tv2Family();
        $state = fn () => tv2Post($child, '/api/child/pet/training/start', ['command' => 'sit', 'rules' => 2]);

        expect($state()->assertOk()->json('state.training.treat_token'))->toBeFalse();
        PetTrainingSession::query()->update(['status' => PetTrainingSession::STATUS_EXPIRED]);

        $pack = PetItem::create(['pet_id' => $pet->id, 'item_key' => 'treats', 'kind' => 'consumable', 'variant' => 'dog_treats',
            'quantity_total' => 10, 'quantity_left' => 0, 'acquired_at' => now()]);
        $start = $state()->assertOk();
        expect($start->json('state.training.treat_token'))->toBeTrue();

        // A full school session never touches the treats.
        $session = $start->json('session');
        Carbon::setTestNow(Carbon::parse($session['ends_at'])->utc());
        tv2Post($child, '/api/child/pet/training/finish', ['session_id' => $session['id'], 'taps' => tv2Taps($session['trials'])])->assertOk();
        expect($pack->fresh()->quantity_left)->toBe(0);

        // A refunded pack is revoked → no token.
        $pack->update(['revoked_at' => now()]);
        Carbon::setTestNow(now()->addMinutes(5));
        expect($state()->assertOk()->json('state.training.treat_token'))->toBeFalse();
    });
});
