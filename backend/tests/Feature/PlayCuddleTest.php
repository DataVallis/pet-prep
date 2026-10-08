<?php

use App\Enums\ActivityType;
use App\Enums\PlayKind;
use App\Enums\PlaySource;
use App\Enums\PlayStatus;
use App\Events\PetUpdated;
use App\Models\ActivityLog;
use App\Models\Pet;
use App\Models\PetContract;
use App\Models\PetPlayEvent;
use App\Models\User;
use App\Services\AccountExportService;
use App\Services\CareScoreService;
use App\Services\FamilyService;
use App\Services\LifeStageService;
use App\Services\PetActivityService;
use App\Services\PetDecayService;
use App\Services\PlayService;
use App\Services\Results\Routine;
use App\Services\RoutineLedgerService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| M5-R05 — play & cuddle (David 2026-10-07 / 2026-10-08, PLAY_CUDDLE_SPEC)
|--------------------------------------------------------------------------
| Mood and video only: a finished ball game / cuddle makes the dog happy for
| 30 minutes and shows on the parent timeline + daily count. Challenge in
| trial or paid only; free play any time the pet is not locked, not in quiet
| hours (D) and has no mess; the dog's invitations (2 / day, 07–20, ≥ 3 h
| apart, open 2 h) show only after today's walk goal with hunger / thirst
| > 30 %. Never a score, routine or metric.
|--------------------------------------------------------------------------
| Family in Europe/Ljubljana (UTC+2 in early October 2026), default quiet
| hours 21:00–07:00 local (19:00–05:00 UTC).
*/

beforeEach(function () {
    seedLifeStageData();
});

function plAt(string $utc): void
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
}

/**
 * A born Border Collie challenge with a profile (trial unless $plan says
 * otherwise), random messes off, invitations off (tests about scheduling
 * clear `play_scheduled_through` themselves).
 *
 * @param  'trial'|'paid'  $plan
 * @param  array<string, mixed>  $attributes
 * @return array{0: User, 1: User, 2: Pet}
 */
function plFamily(string $plan = 'trial', array $attributes = [], string $bornUtc = '2026-10-06 18:00:00'): array
{
    plAt($bornUtc);
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => 'Maja']);
    $factory = Pet::factory()->borderCollie();
    $factory = $plan === 'paid' ? $factory->purchased() : $factory->trial();
    $pet = $factory->create(array_merge([
        'user_id' => $child->id,
        'born_at' => Carbon::parse($bornUtc, 'UTC'),
        'last_decay_at' => Carbon::parse($bornUtc, 'UTC'),
        'last_step_reset_at' => Carbon::parse($bornUtc, 'UTC'),
        'arrival_age_months' => 4,
    ], $attributes));
    disableHygieneEvents($pet);
    Pet::whereKey($pet->id)->update(['play_scheduled_through' => '2999-12-31']);

    return [$parent, $child, $pet->fresh()];
}

/**
 * Move the clock and give the dog fresh metrics: no decay owed, the local
 * day already started (no midnight close pending).
 */
function plNow(Pet $pet, string $utc, array $metrics = []): Pet
{
    plAt($utc);
    Pet::whereKey($pet->id)->update(array_merge([
        'last_decay_at' => now(),
        'last_step_reset_at' => now(),
        'hunger_level' => 100,
        'thirst_level' => 100,
        'hygiene_level' => 100,
        // What the tick derives for these metrics (energy 100 from birth).
        'pet_state' => 'playing',
    ], $metrics));

    return $pet->refresh();
}

/** Today's walk goal reached (steps of the family-local day ≥ the stage goal). */
function plWalk(Pet $pet): Pet
{
    $goal = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()))->stepGoal;
    Pet::whereKey($pet->id)->update([
        'daily_step_count' => $goal,
        'energy_level' => 100,
        'last_step_reset_at' => now(),
        // What the tick derives for a fed, walked dog.
        'pet_state' => 'playing',
    ]);

    return $pet->refresh();
}

/** A pending invitation planted at $atUtc, open $minutes. */
function plInvite(Pet $pet, string $kind, string $atUtc, int $minutes = 120): PetPlayEvent
{
    $at = Carbon::parse($atUtc, 'UTC');

    return PetPlayEvent::create([
        'pet_id' => $pet->id,
        'kind' => $kind,
        'source' => PlaySource::Invitation,
        'local_date' => $pet->localDate($at),
        'scheduled_at' => $at,
        'expires_at' => $at->copy()->addMinutes($minutes),
        'status' => PlayStatus::Pending,
    ]);
}

function plPlay(User $child, string $kind = 'play'): TestResponse
{
    app('auth')->forgetGuards();
    actingAsRole($child);

    return postJson('/api/child/pet/play', ['kind' => $kind]);
}

function plState(User $child): TestResponse
{
    app('auth')->forgetGuards();
    actingAsRole($child);

    return getJson('/api/child/pet')->assertOk();
}

/** A second child caring for the pet, with (or without) a signed contract. */
function plSibling(User $parent, Pet $pet, bool $signed = true): User
{
    $child = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => 'Luka']);
    app(FamilyService::class)->addCaretaker($pet, $child, true);
    if ($signed) {
        PetContract::create([
            'pet_id' => $pet->id, 'user_id' => $child->id,
            'signature_format' => 'svg_path', 'signature' => 'M1 1 L2 2',
            'signed_at' => now(),
        ]);
    }

    return $child;
}

/**
 * Scoring isolation (§12.6, QA PR #82 M1): two identical dogs over three
 * family-local days; one child plays every 40 minutes from 07:00 to 20:40
 * local (21 plays a day, alternating kinds), the other dog only gets a tick
 * at the same instants. Then both tick once more and the ledger closes the
 * days. `$mutate` makes every play also write a `fed_pet` row — the proof
 * that the comparison would catch play leaking into scoring.
 *
 * @return array<string, mixed>
 */
function plIsolationRun(bool $mutate): array
{
    [, $playChild, $playPet] = plFamily();
    [, $idleChild, $idlePet] = plFamily();
    $activities = app(PetActivityService::class);
    $decay = app(PetDecayService::class);
    $plays = 0;

    foreach (['2026-10-07', '2026-10-08', '2026-10-09'] as $day) {
        $at = Carbon::parse("{$day} 07:00:00", 'Europe/Ljubljana')->utc();
        for ($i = 0; $i < 21; $i++, $at->addMinutes(40)) {
            plAt($at->toDateTimeString());
            $decay->processPetDecay($idlePet);
            $result = $activities->play($playPet, $playChild, $i % 2 === 0 ? PlayKind::Play : PlayKind::Cuddle);
            expect($result->status)->toBe('accepted');
            $plays++;
            if ($mutate) {
                ActivityLog::withoutEvents(fn () => ActivityLog::create([
                    'pet_id' => $playPet->id, 'actor_user_id' => $playChild->id, 'activity_type' => 'fed_pet', 'value' => 50,
                ]));
            }
        }
    }

    plAt('2026-10-10 10:00:00');
    $decay->processPetDecay($playPet);
    $decay->processPetDecay($idlePet);
    app(RoutineLedgerService::class)->closeDueDays(now());

    $ledger = app(RoutineLedgerService::class);
    $scores = app(CareScoreService::class);
    $snapshot = function (Pet $pet, User $child) use ($ledger, $scores): array {
        $pet->refresh();
        $board = $scores->board(collect([$pet]), 'Europe/Ljubljana');
        $s = $scores->childScore($board, $child->id, $pet);

        return [
            // Everything in activities_log except the two play timeline types
            // (system rows like parent_fed_pet happen to both dogs alike).
            'otherActivities' => DB::table('activities_log')->where('pet_id', $pet->id)
                ->whereNotIn('activity_type', ['played_with_pet', 'cuddled_pet'])
                ->orderBy('created_at')->orderBy('id')
                ->get(['activity_type', 'value', 'created_at', 'actor_user_id'])
                ->map(fn ($r) => [$r->activity_type, $r->value, $r->created_at, $r->actor_user_id !== null])->all(),
            'routineRows' => DB::table('pet_daily_routines')->where('pet_id', $pet->id)
                ->orderBy('local_date')->orderBy('routine_type')->orderBy('slot')
                ->get(['local_date', 'routine_type', 'slot', 'status', 'opens_at', 'due_at', 'done_at', 'steps', 'goal', 'event_kind', 'actor_user_id'])
                ->map(fn ($r) => array_merge((array) $r, ['actor_user_id' => $r->actor_user_id !== null]))->all(),
            'periods' => DB::table('pet_status_periods')->where('pet_id', $pet->id)->orderBy('started_at')
                ->get(['kind', 'started_at', 'ended_at'])->map(fn ($r) => (array) $r)->all(),
            'ledger' => array_map(
                fn (Routine $r) => [$r->localDate, $r->type->value, $r->slot, $r->status->value],
                $ledger->routinesFor(collect([$pet]), '2026-10-06', '2026-10-10', now())[$pet->id] ?? [],
            ),
            'score' => [$s['score'], $s['done'], $s['expected'], $scores->childLight($board, $child->id, $pet)],
            'metrics' => [$pet->displayMetrics(), $pet->pet_state?->value, (int) $pet->escalation_level, $pet->illness_until?->toIso8601String()],
        ];
    };

    return [
        'plays' => $plays,
        // Activity types the play dog has that the idle dog does not.
        'playTypes' => array_values(array_diff(
            DB::table('activities_log')->where('pet_id', $playPet->id)->distinct()->pluck('activity_type')->all(),
            DB::table('activities_log')->where('pet_id', $idlePet->id)->distinct()->pluck('activity_type')->all(),
        )),
        'play' => $snapshot($playPet, $playChild),
        'idle' => $snapshot($idlePet, $idleChild),
    ];
}

describe('eligibility (§2, §3.1)', function () {
    it('lets a child play in the trial and on a paid challenge', function (string $plan) {
        [, $child, $pet] = plFamily($plan);
        plNow($pet, '2026-10-07 08:00:00'); // 10:00 local

        plPlay($child)->assertOk()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('play.kind', 'play')
            ->assertJsonPath('play.source', 'free')
            ->assertJsonPath('state.play.can_play', true);
    })->with(['trial', 'paid']);

    it('has no play for a free mutt, a mutt shown as free or a legacy pet (422, play null)', function (string $case) {
        plAt('2026-10-06 18:00:00');
        $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
        $child = User::factory()->child()->create(['parent_id' => $parent->id]);
        $pet = match ($case) {
            'free_mutt' => Pet::factory()->freePlan()->create(['user_id' => $child->id, 'arrival_age_months' => 4]),
            // Grandfathered mutt challenge: the parent sees "Free" (M5-F02).
            'grandfathered_mutt' => Pet::factory()->mutt()->create(['user_id' => $child->id, 'arrival_age_months' => 4]),
            // (D) Q2: legacy pets get no play.
            'legacy' => Pet::factory()->borderCollie()->trial()->create(['user_id' => $child->id, 'arrival_age_months' => null]),
        };
        disableHygieneEvents($pet);
        plNow($pet, '2026-10-07 08:00:00');

        plPlay($child)->assertStatus(422)->assertJsonPath('reason', 'play_not_available')
            ->assertJsonPath('state.play', null);
        expect(PetPlayEvent::count())->toBe(0)
            ->and(ActivityLog::whereIn('activity_type', ['played_with_pet', 'cuddled_pet'])->count())->toBe(0);
    })->with(['free_mutt', 'grandfathered_mutt', 'legacy']);

    it('is locked while the challenge waits for payment (423, play null)', function () {
        [, $child, $pet] = plFamily('trial', [
            'trial_ends_at' => Carbon::parse('2026-10-06 19:00:00', 'UTC'),
            'payment_locked_at' => Carbon::parse('2026-10-06 19:00:00', 'UTC'),
        ]);
        plNow($pet, '2026-10-07 08:00:00');

        plPlay($child)->assertStatus(423)->assertJsonPath('reason', 'payment_required')
            ->assertJsonPath('state.play', null);
    });

    it('is locked before birth, in a hard stop and at the vet; can_play false', function (string $case, string $reason) {
        [, $child, $pet] = plFamily();
        plNow($pet, '2026-10-07 08:00:00');
        match ($case) {
            'unborn' => Pet::whereKey($pet->id)->update(['born_at' => null, 'last_decay_at' => null]),
            'hard_stop' => Pet::whereKey($pet->id)->update(['is_hard_stopped' => true]),
            'ill' => Pet::whereKey($pet->id)->update(['illness_until' => now()->addHours(5)]),
        };

        plPlay($child)->assertStatus(423)->assertJsonPath('reason', $reason);
        $play = plState($child)->json('play');
        expect($play === null || $play['can_play'] === false)->toBeTrue();
        expect(PetPlayEvent::count())->toBe(0);
    })->with([
        ['unborn', 'contract_required'],
        ['hard_stop', 'hard_stopped'],
        ['ill', 'ill'],
    ]);

    it('refuses play in quiet hours with next_allowed_at = their end (D)', function () {
        [, $child, $pet] = plFamily();
        plNow($pet, '2026-10-07 20:00:00'); // 22:00 local, bedtime 21–07

        plPlay($child)->assertStatus(422)
            ->assertJsonPath('reason', 'play_not_available')
            ->assertJsonPath('next_allowed_at', '2026-10-08T07:00:00+02:00')
            ->assertJsonPath('state.play.can_play', false);
    });

    it('refuses play while a mess waits to be cleaned', function () {
        [, $child, $pet] = plFamily();
        plNow($pet, '2026-10-07 08:00:00', ['hygiene_level' => 0]);

        plPlay($child, 'cuddle')->assertStatus(422)->assertJsonPath('reason', 'play_not_available')
            ->assertJsonPath('next_allowed_at', null);
    });

    it('allows free play while hungry, thirsty and before the walk (no conditions)', function () {
        [, $child, $pet] = plFamily();
        plNow($pet, '2026-10-07 08:00:00', ['hunger_level' => 10, 'thirst_level' => 10]);

        plPlay($child)->assertOk()->assertJsonPath('status', 'accepted');
    });

    it('a sibling without a signed contract is locked, a signed sibling plays', function () {
        [$parent, , $pet] = plFamily();
        plNow($pet, '2026-10-07 08:00:00');
        $unsigned = plSibling($parent, $pet, signed: false);

        plPlay($unsigned)->assertStatus(423)->assertJsonPath('reason', 'contract_required');
        expect(plState($unsigned)->json('play.can_play'))->toBeFalse();
    });

    it('validates kind', function () {
        [, $child, $pet] = plFamily();
        plNow($pet, '2026-10-07 08:00:00');

        plPlay($child, 'fetch')->assertStatus(422)->assertJsonValidationErrors('kind');
    });

    it('is child only', function () {
        [$parent, , $pet] = plFamily();
        plNow($pet, '2026-10-07 08:00:00');
        actingAsRole($parent);

        postJson('/api/child/pet/play', ['kind' => 'play'])->assertForbidden();
    });
});

describe('mood (§5, David Q5)', function () {
    it('makes the dog happy for 30 minutes; a new play extends, never adds up', function () {
        [, $child, $pet] = plFamily();
        plNow($pet, '2026-10-07 08:00:00');

        plPlay($child)->assertOk()
            ->assertJsonPath('state.play.mood.happy_until', '2026-10-07T10:30:00+02:00')
            ->assertJsonPath('state.play.mood.scene', 'playing')
            // pet_state and behaviour scene unchanged (old builds unaffected).
            ->assertJsonPath('state.behaviour.scene', null);

        plAt('2026-10-07 08:20:00');
        plPlay($child, 'cuddle')->assertOk()->assertJsonPath('state.play.mood.happy_until', '2026-10-07T10:50:00+02:00');

        plAt('2026-10-07 08:51:00');
        plState($child)->assertJsonPath('play.mood.happy_until', null)->assertJsonPath('play.mood.scene', null);
    });

    it('never hides a need: a hungry dog shows no happy scene', function () {
        [, $child, $pet] = plFamily();
        plNow($pet, '2026-10-07 08:00:00', ['hunger_level' => 20, 'pet_state' => 'hungry']);

        $state = plPlay($child)->assertOk()->json('state');
        expect($state['pet']['pet_state'])->toBe('hungry')
            ->and($state['play']['mood']['happy_until'])->toBe('2026-10-07T10:30:00+02:00')
            ->and($state['play']['mood']['scene'])->toBeNull();
    });
});

describe('repeat and timeline (§7, §12.5 — D)', function () {
    it('treats the same child and kind within 10 s as one play', function () {
        [, $child, $pet] = plFamily();
        plNow($pet, '2026-10-07 08:00:00');

        plPlay($child)->assertOk()->assertJsonPath('status', 'accepted');
        plAt('2026-10-07 08:00:05');
        Event::fake([PetUpdated::class]);
        plPlay($child)->assertOk()->assertJsonPath('status', 'unchanged');
        Event::assertNotDispatched(PetUpdated::class);
        // Another kind is another play.
        plPlay($child, 'cuddle')->assertOk()->assertJsonPath('status', 'accepted');
        expect(PetPlayEvent::count())->toBe(2);

        plAt('2026-10-07 08:00:11');
        plPlay($child)->assertOk()->assertJsonPath('status', 'accepted');
        expect(PetPlayEvent::where('kind', 'play')->count())->toBe(2);
    });

    it('writes one timeline row per child and kind per hour; repeats count in its value', function () {
        [$parent, $child, $pet] = plFamily();
        plNow($pet, '2026-10-07 08:00:00');
        $luka = plSibling($parent, $pet);

        foreach (['08:00:00', '08:00:20', '08:00:40'] as $t) {
            plAt("2026-10-07 {$t}");
            plPlay($child)->assertOk();
        }
        plPlay($child, 'cuddle')->assertOk();
        plPlay($luka)->assertOk();

        $rows = fn (string $type, User $actor) => ActivityLog::where('activity_type', $type)->where('actor_user_id', $actor->id)->orderBy('id')->pluck('value')->all();
        expect($rows('played_with_pet', $child))->toBe([3])
            ->and($rows('cuddled_pet', $child))->toBe([1])
            ->and($rows('played_with_pet', $luka))->toBe([1]);

        plAt('2026-10-07 09:01:00');
        plPlay($child)->assertOk();
        expect($rows('played_with_pet', $child))->toBe([3, 1]);

        // Parent timeline: a positive row with the child's nickname.
        actingAsRole($parent);
        $timeline = getJson('/api/parent/dashboard')->assertOk()->json('family.pets.0.timeline');
        $item = collect($timeline)->firstWhere('activity_type', 'played_with_pet');
        expect($item['actor_nickname'])->toBe('Maja')->and($item['is_positive'])->toBeTrue();
    });
});

describe('invitations: scheduling (§3.2, §12.2)', function () {
    it('decides two invitations per day inside 07–20 local, outside quiet hours, ≥ 3 h apart, open ≤ 2 h', function () {
        [$parent, , $pet] = plFamily();
        setQuietHours(['parent_id' => $parent->id, 'school_start' => '08:00', 'school_end' => '13:00', 'bedtime_start' => '21:00', 'bedtime_end' => '07:00']);
        Pet::whereKey($pet->id)->update(['play_scheduled_through' => '2026-10-07']);
        plNow($pet, '2026-10-07 22:00:00');
        plAt('2026-10-07 22:01:00'); // 00:01 local on 10-08

        app(PetDecayService::class)->processPetDecay($pet);

        $rows = PetPlayEvent::where('pet_id', $pet->id)->orderBy('scheduled_at')->get();
        expect($rows)->toHaveCount(2)
            ->and($rows->pluck('kind')->map->value->sort()->values()->all())->toBe(['cuddle', 'play'])
            ->and($rows->every(fn ($r) => $r->status === PlayStatus::Pending && $r->local_date === '2026-10-08'))->toBeTrue()
            ->and($pet->fresh()->play_scheduled_through)->toBe('2026-10-08');

        $quiet = $pet->fresh()->quietHours();
        foreach ($rows as $row) {
            $local = $row->scheduled_at->copy()->setTimezone('Europe/Ljubljana');
            expect($local->format('H:i') >= '07:00' && $local->format('H:i') < '20:00')->toBeTrue()
                ->and($quiet->isQuietNow($row->scheduled_at))->toBeFalse()
                ->and($row->scheduled_at->second)->toBe(0)
                ->and($row->expires_at->lessThanOrEqualTo($row->scheduled_at->copy()->addMinutes(120)))->toBeTrue();
        }
        expect((int) $rows[0]->scheduled_at->diffInMinutes($rows[1]->scheduled_at, true))->toBeGreaterThanOrEqual(180);

        // A second tick the same day decides nothing new.
        plAt('2026-10-07 22:02:00');
        app(PetDecayService::class)->processPetDecay($pet);
        expect(PetPlayEvent::where('pet_id', $pet->id)->count())->toBe(2);
    });

    it('is deterministic per seed and keeps the rules over many days', function () {
        [$parent, , $pet] = plFamily();
        setQuietHours(['parent_id' => $parent->id, 'school_start' => '08:00', 'school_end' => '13:00', 'bedtime_start' => '21:00', 'bedtime_end' => '07:00']);
        $quiet = $pet->fresh()->quietHours();
        $service = app(PlayService::class);

        for ($d = 0; $d < 40; $d++) {
            $date = Carbon::parse('2026-10-08')->addDays($d)->toDateString(); // crosses the DST change (10-25)
            $plan = $service->planDay($pet, $date, $quiet);
            $again = $service->planDay($pet, $date, $quiet);

            expect(array_map(fn ($p) => [$p[0]->value, $p[1]->toIso8601String()], $plan))
                ->toBe(array_map(fn ($p) => [$p[0]->value, $p[1]->toIso8601String()], $again))
                ->and($plan)->toHaveCount(2)
                ->and($plan[0][0])->not->toBe($plan[1][0]);
            foreach ($plan as [, $at]) {
                $local = $at->setTimezone('Europe/Ljubljana');
                expect($local->toDateString())->toBe($date)
                    ->and($local->format('H:i') >= '07:00' && $local->format('H:i') < '20:00')->toBeTrue()
                    ->and($quiet->isQuietNow($at))->toBeFalse();
            }
            expect(abs($plan[1][1]->getTimestamp() - $plan[0][1]->getTimestamp()))->toBeGreaterThanOrEqual(180 * 60);
        }
    });

    it('gives one invitation on a short day and none when the whole band is quiet', function () {
        [$parent, , $pet] = plFamily();
        $service = app(PlayService::class);

        // Only 18:30–20:00 is free: no pair 3 h apart fits.
        setQuietHours(['parent_id' => $parent->id, 'school_start' => '07:00', 'school_end' => '18:30', 'bedtime_start' => '21:00', 'bedtime_end' => '07:00']);
        $plan = $service->planDay($pet, '2026-10-08', $pet->fresh()->quietHours());
        expect($plan)->toHaveCount(1);
        expect($plan[0][1]->setTimezone('Europe/Ljubljana')->format('H:i') >= '18:30')->toBeTrue();

        setQuietHours(['parent_id' => $parent->id, 'school_start' => '07:00', 'school_end' => '20:00', 'bedtime_start' => '21:00', 'bedtime_end' => '07:00']);
        expect($service->planDay($pet, '2026-10-08', $pet->fresh()->quietHours()))->toBe([]);
    });

    it('ends an invitation at the next quiet hours', function () {
        [, , $pet] = plFamily();
        $quiet = $pet->quietHours(); // bedtime 21:00–07:00

        $end = app(PlayService::class)->expiresAt(Carbon::parse('2026-10-08 17:30:00', 'UTC'), $quiet); // 19:30 local
        expect($end->setTimezone('Europe/Ljubljana')->format('H:i'))->toBe('21:00');

        $end = app(PlayService::class)->expiresAt(Carbon::parse('2026-10-08 08:00:00', 'UTC'), $quiet); // 10:00 local
        expect($end->setTimezone('Europe/Ljubljana')->format('H:i'))->toBe('12:00');
    });

    it('skips invitations before the birth', function () {
        // Plan the dog's day first (seed per pet + date), then let it be born
        // one minute after the first invitation's time.
        [, , $pet] = plFamily();
        $plan = app(PlayService::class)->planDay($pet, '2026-10-08', $pet->quietHours());
        expect($plan)->toHaveCount(2);
        $born = $plan[0][1]->addMinutes(1);
        Pet::whereKey($pet->id)->update(['born_at' => $born, 'play_scheduled_through' => null]);
        plNow($pet, $born->toDateTimeString());
        $pet->refresh();

        app(PlayService::class)->ensureInvitationsScheduled($pet, now()->subMinute(), now(), $pet->quietHours());

        $rows = PetPlayEvent::where('pet_id', $pet->id)->orderBy('scheduled_at')->get();
        expect($rows)->toHaveCount(2)
            ->and($rows[0]->status)->toBe(PlayStatus::Skipped)   // before the birth
            ->and($rows[1]->status)->toBe(PlayStatus::Pending);
    });

    it('does not make up invitations after a hard stop across midnight (QA M2)', function () {
        [, , $pet] = plFamily();
        $plan = app(PlayService::class)->planDay($pet, '2026-10-08', $pet->quietHours());
        expect($plan)->toHaveCount(2);

        // Hard stop from the evening before; the 10-08 day is not decided while frozen.
        plNow($pet, '2026-10-07 18:00:00');
        Pet::whereKey($pet->id)->update(['play_scheduled_through' => '2026-10-07']);
        $pet->refresh()->update(['is_hard_stopped' => true]);
        plAt('2026-10-07 22:01:00');
        app(PetDecayService::class)->processPetDecay($pet);
        expect(PetPlayEvent::where('pet_id', $pet->id)->count())->toBe(0);

        // Thaw one minute after the first invitation's time, then a tick.
        $thaw = $plan[0][1]->addMinute();
        plAt($thaw->toDateTimeString());
        app(PetDecayService::class)->processPetDecay($pet);
        $pet->refresh()->update(['is_hard_stopped' => false]);
        plAt($thaw->addMinute()->toDateTimeString());
        app(PetDecayService::class)->processPetDecay($pet);

        $rows = PetPlayEvent::where('pet_id', $pet->id)->orderBy('scheduled_at')->get();
        expect($rows)->toHaveCount(2)
            ->and($rows[0]->scheduled_at->equalTo($plan[0][1]))->toBeTrue()
            ->and($rows[0]->status)->toBe(PlayStatus::Skipped)
            ->and($rows[1]->status)->toBe(PlayStatus::Pending);
    });

    it('does not make up invitations after a payment lock across midnight, once paid (QA M2)', function () {
        [, , $pet] = plFamily('trial', [
            'trial_ends_at' => Carbon::parse('2026-10-06 19:00:00', 'UTC'),
            'payment_locked_at' => Carbon::parse('2026-10-06 19:00:00', 'UTC'),
        ]);
        $plan = app(PlayService::class)->planDay($pet, '2026-10-08', $pet->quietHours());
        Pet::whereKey($pet->id)->update(['play_scheduled_through' => '2026-10-07']);
        plNow($pet, '2026-10-07 22:00:00');
        plAt('2026-10-07 22:01:00');
        app(PetDecayService::class)->processPetDecay($pet);
        expect(PetPlayEvent::where('pet_id', $pet->id)->count())->toBe(0);

        // The parent pays after the first invitation's time.
        $paid = $plan[0][1]->addMinute();
        plAt($paid->toDateTimeString());
        $pet->refresh()->forceFill(['challenge_paid_at' => now(), 'challenge_paid_source' => 'admin', 'payment_locked_at' => null])->save();
        plAt($paid->addMinute()->toDateTimeString());
        app(PetDecayService::class)->processPetDecay($pet);

        $rows = PetPlayEvent::where('pet_id', $pet->id)->orderBy('scheduled_at')->get();
        expect($rows)->toHaveCount(2)
            ->and($rows[0]->status)->toBe(PlayStatus::Skipped)
            ->and($rows[1]->status)->toBe(PlayStatus::Pending);
    });

    it('drops pending invitations at game over and deactivation (QA m3)', function (string $change) {
        [, , $pet] = plFamily();
        $invite = plInvite($pet, 'play', '2026-10-07 14:00:00');
        plNow($pet, '2026-10-07 08:00:00');

        $pet->refresh()->update($change === 'game_over' ? ['is_game_over' => true] : ['is_active' => false]);

        expect($invite->fresh()->status)->toBe(PlayStatus::Skipped);
    })->with(['game_over', 'inactive']);

    it('keeps the band and the quiet-hours cut on the DST day (25 h, 2026-10-25)', function () {
        [, , $pet] = plFamily('paid');
        Pet::whereKey($pet->id)->update(['play_scheduled_through' => '2026-10-24']);
        plNow($pet, '2026-10-24 22:00:00'); // 00:00 local, CEST
        plAt('2026-10-24 22:01:00');
        $pet->refresh();

        app(PlayService::class)->ensureInvitationsScheduled($pet, Carbon::parse('2026-10-24 22:00:00', 'UTC'), now(), $pet->quietHours());

        $rows = PetPlayEvent::where('pet_id', $pet->id)->orderBy('scheduled_at')->get();
        expect($rows)->toHaveCount(2);
        foreach ($rows as $row) {
            $local = $row->scheduled_at->copy()->setTimezone('Europe/Ljubljana');
            expect($row->local_date)->toBe('2026-10-25')
                ->and($local->format('H:i') >= '07:00' && $local->format('H:i') < '20:00')->toBeTrue()
                ->and($local->offsetHours)->toBe(1) // CET after the change
                ->and($row->status)->toBe(PlayStatus::Pending);
        }

        // 19:30 CET + 2 h is cut at bedtime 21:00 CET (20:00 UTC).
        $end = app(PlayService::class)->expiresAt(Carbon::parse('2026-10-25 18:30:00', 'UTC'), $pet->quietHours());
        expect($end->toIso8601String())->toBe('2026-10-25T20:00:00+00:00');
    });

    it('after a scheduler outage decides only today and skips the invitations already past', function () {
        [, , $pet] = plFamily();
        Pet::whereKey($pet->id)->update(['play_scheduled_through' => '2026-10-06']);
        plNow($pet, '2026-10-08 14:00:00'); // 16:00 local
        $pet->refresh();

        app(PlayService::class)->ensureInvitationsScheduled($pet, Carbon::parse('2026-10-07 10:00:00', 'UTC'), now(), $pet->quietHours());

        $rows = PetPlayEvent::where('pet_id', $pet->id)->get();
        expect($rows->pluck('local_date')->unique()->values()->all())->toBe(['2026-10-08']);
        foreach ($rows as $row) {
            expect($row->status)->toBe($row->scheduled_at->lessThanOrEqualTo(now()) ? PlayStatus::Skipped : PlayStatus::Pending);
        }
    });

    it('does not schedule for a pet without play', function () {
        plAt('2026-10-07 22:01:00');
        $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
        $child = User::factory()->child()->create(['parent_id' => $parent->id]);
        $pet = disableHygieneEvents(Pet::factory()->freePlan()->create(['user_id' => $child->id, 'arrival_age_months' => 4]));

        app(PetDecayService::class)->processPetDecay($pet);
        expect(PetPlayEvent::count())->toBe(0);
    });
});

describe('invitations: tick (§12.2)', function () {
    it('expires an invitation nobody took — nothing else happens', function () {
        [, $child, $pet] = plFamily();
        $invite = plInvite($pet, 'play', '2026-10-07 08:00:00');
        plNow($pet, '2026-10-07 10:00:00');
        $before = $pet->fresh()->displayMetrics();
        plAt('2026-10-07 10:01:00');

        app(PetDecayService::class)->processPetDecay($pet);

        expect($invite->fresh()->status)->toBe(PlayStatus::Expired)
            ->and(ActivityLog::where('pet_id', $pet->id)->count())->toBe(0)
            ->and($pet->fresh()->displayMetrics())->toBe($before);
    });

    it('skips an invitation whose time comes during a hard stop', function () {
        [, , $pet] = plFamily();
        $invite = plInvite($pet, 'cuddle', '2026-10-07 08:00:00');
        plNow($pet, '2026-10-07 07:00:00');
        $pet->update(['is_hard_stopped' => true]);

        plAt('2026-10-07 08:01:00');
        app(PetDecayService::class)->processPetDecay($pet);

        expect($invite->fresh()->status)->toBe(PlayStatus::Skipped);
    });

    it('broadcasts once when an invitation appears on a tick', function () {
        [, , $pet] = plFamily();
        $invite = plInvite($pet, 'play', '2026-10-07 08:00:00');
        plNow($pet, '2026-10-07 07:59:00');
        plWalk($pet);
        plAt('2026-10-07 08:00:00');
        Event::fake([PetUpdated::class]);

        app(PetDecayService::class)->processPetDecay($pet);

        Event::assertDispatchedTimes(PetUpdated::class, 1);
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->eventType === 'play'
            && $e->payload['play']['invitation']['id'] === $invite->id);
    });
});

describe('invitations: offer and completion (§3.2, §12.3, Q4, Q8)', function () {
    it('shows an invitation only after the walk goal and with hunger and thirst above 30 %', function () {
        [, $child, $pet] = plFamily();
        $invite = plInvite($pet, 'play', '2026-10-07 08:00:00');
        plNow($pet, '2026-10-07 07:59:00');

        // Before its time.
        plWalk($pet);
        plState($child)->assertJsonPath('play.invitation', null);

        // Walk not done.
        plNow($pet, '2026-10-07 08:30:00', ['daily_step_count' => 0]);
        plState($child)->assertJsonPath('play.invitation', null)->assertJsonPath('play.can_play', true);

        plWalk($pet);
        plState($child)->assertJsonPath('play.invitation', [
            'id' => $invite->id,
            'kind' => 'play',
            'expires_at' => '2026-10-07T12:00:00+02:00',
        ]);

        plNow($pet, '2026-10-07 08:31:00', ['hunger_level' => 30]);
        plState($child)->assertJsonPath('play.invitation', null);
        plNow($pet, '2026-10-07 08:32:00', ['thirst_level' => 25]);
        plState($child)->assertJsonPath('play.invitation', null);
        plNow($pet, '2026-10-07 08:33:00', ['hygiene_level' => 0]);
        plState($child)->assertJsonPath('play.invitation', null)->assertJsonPath('play.can_play', false);
    });

    it('a play of the same kind completes the shown invitation; another kind is a free play', function () {
        [, $child, $pet] = plFamily();
        $invite = plInvite($pet, 'play', '2026-10-07 08:00:00');
        plNow($pet, '2026-10-07 08:10:00');
        plWalk($pet);

        plPlay($child, 'cuddle')->assertOk()->assertJsonPath('play.source', 'free')
            ->assertJsonPath('state.play.invitation.id', $invite->id);
        expect($invite->fresh()->status)->toBe(PlayStatus::Pending);

        plPlay($child, 'play')->assertOk()->assertJsonPath('play.source', 'invitation')
            ->assertJsonPath('state.play.invitation', null);
        $invite->refresh();
        expect($invite->status)->toBe(PlayStatus::Done)
            ->and($invite->completed_by)->toBe($child->id)
            ->and($invite->completed_at->toIso8601String())->toBe(now()->toIso8601String())
            // No extra free row for the invitation's play.
            ->and(PetPlayEvent::where('kind', 'play')->count())->toBe(1);
    });

    it('a hidden invitation (walk not done) is not completed by a play', function () {
        [, $child, $pet] = plFamily();
        $invite = plInvite($pet, 'play', '2026-10-07 08:00:00');
        plNow($pet, '2026-10-07 08:10:00');

        plPlay($child)->assertOk()->assertJsonPath('play.source', 'free');
        expect($invite->fresh()->status)->toBe(PlayStatus::Pending);
    });

    it('shared pet: the first child completes the invitation, the sibling sees it closed and still plays freely', function () {
        [$parent, $child, $pet] = plFamily();
        $invite = plInvite($pet, 'cuddle', '2026-10-07 08:00:00');
        plNow($pet, '2026-10-07 08:10:00');
        plWalk($pet);
        $luka = plSibling($parent, $pet);

        plState($luka)->assertJsonPath('play.invitation.id', $invite->id);
        plPlay($child, 'cuddle')->assertOk()->assertJsonPath('play.source', 'invitation');

        plState($luka)->assertJsonPath('play.invitation', null);
        plPlay($luka, 'cuddle')->assertOk()->assertJsonPath('play.source', 'free');
        expect($invite->fresh()->completed_by)->toBe($child->id);
    });

    it('broadcasts the play once after commit', function () {
        [, $child, $pet] = plFamily();
        plNow($pet, '2026-10-07 08:00:00');
        Event::fake([PetUpdated::class]);

        plPlay($child)->assertOk();

        Event::assertDispatchedTimes(PetUpdated::class, 1);
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->eventType === 'play'
            && $e->payload['play']['mood']['happy_until'] !== null
            && $e->payload['play']['mood']['scene'] === 'playing');
    });
});

describe('parent (§7, Q7)', function () {
    it('shows today\'s counts of all children; yesterday does not count; a free pet has null', function () {
        [$parent, $child, $pet] = plFamily();
        plNow($pet, '2026-10-07 08:00:00');
        $luka = plSibling($parent, $pet);
        PetPlayEvent::create([
            'pet_id' => $pet->id, 'kind' => PlayKind::Play, 'source' => PlaySource::Free, 'local_date' => '2026-10-06',
            'status' => PlayStatus::Done, 'completed_by' => $child->id, 'completed_at' => Carbon::parse('2026-10-06 18:30:00', 'UTC'),
        ]);

        plPlay($child)->assertOk();
        plPlay($luka)->assertOk();
        plPlay($child, 'cuddle')->assertOk();
        // An expired invitation does not count.
        PetPlayEvent::create([
            'pet_id' => $pet->id, 'kind' => PlayKind::Cuddle, 'source' => PlaySource::Invitation, 'local_date' => '2026-10-07',
            'scheduled_at' => Carbon::parse('2026-10-07 05:00:00', 'UTC'), 'expires_at' => Carbon::parse('2026-10-07 07:00:00', 'UTC'),
            'status' => PlayStatus::Expired,
        ]);

        app('auth')->forgetGuards();
        actingAsRole($parent);
        getJson('/api/parent/dashboard')->assertOk()
            ->assertJsonPath('family.pets.0.play_today', ['play' => 2, 'cuddle' => 1]);

        $mutt = Pet::factory()->freePlan()->make(['id' => 999999, 'arrival_age_months' => 4]);
        expect(app(PlayService::class)->todayCounts(collect([$mutt]))[$mutt->id])->toBeNull();
    });

    it('does not count plays as care actions in the child stats', function () {
        [$parent, $child, $pet] = plFamily();
        plNow($pet, '2026-10-07 08:00:00');
        plPlay($child)->assertOk();
        plPlay($child, 'cuddle')->assertOk();

        app('auth')->forgetGuards();
        actingAsRole($parent);
        getJson('/api/parent/dashboard')->assertOk()->assertJsonPath('family.children.0.stats.actions_total', 0);
    });
});

describe('isolation from scoring (§12.6)', function () {
    it('a dog that plays all day has the same routines, Care Score, periods and metrics as one that never plays', function () {
        $run = plIsolationRun(mutate: false);

        // Play added nothing but its two timeline types; every other row is identical.
        expect($run['playTypes'])->toEqualCanonicalizing(['played_with_pet', 'cuddled_pet'])
            ->and($run['plays'])->toBe(3 * 21)
            ->and($run['play']['otherActivities'])->toBe($run['idle']['otherActivities']);

        expect($run['play']['routineRows'])->toBe($run['idle']['routineRows'])->not->toBe([])
            ->and($run['play']['periods'])->toBe($run['idle']['periods'])
            ->and($run['play']['ledger'])->toBe($run['idle']['ledger'])->not->toBe([])
            ->and($run['play']['score'])->toBe($run['idle']['score'])
            ->and($run['play']['metrics'])->toBe($run['idle']['metrics']);
    });

    it('the isolation check is sensitive: if play also wrote fed_pet, the dogs would differ', function () {
        $run = plIsolationRun(mutate: true);

        expect($run['playTypes'])->toContain('fed_pet')
            ->and($run['play']['routineRows'])->not->toBe($run['idle']['routineRows'])
            ->and($run['play']['score'])->not->toBe($run['idle']['score']);
    });

    it('play activity types are not routine inputs', function () {
        $ledgerTypes = (new ReflectionClassConstant(RoutineLedgerService::class, 'ACTIVITY_TYPES'))->getValue();

        foreach (PlayService::activityTypes() as $type) {
            expect($ledgerTypes)->not->toContain($type)
                ->and($type->isNegativeEvent())->toBeFalse();
        }
    });
});

describe('data (§11, §12.4)', function () {
    it('exports play events and deletes them with the pet', function () {
        [$parent, $child, $pet] = plFamily();
        plNow($pet, '2026-10-07 08:00:00');
        plWalk($pet);
        $invite = plInvite($pet, 'cuddle', '2026-10-07 07:30:00');
        plPlay($child, 'cuddle')->assertOk()->assertJsonPath('play.source', 'invitation');
        // Many free plays are one count per day, child and kind (QA m2: no export blow-up).
        foreach (['08:00:00', '08:00:20', '08:00:40'] as $t) {
            plAt("2026-10-07 {$t}");
            plPlay($child)->assertOk();
        }

        $export = app(AccountExportService::class)->exportFor($parent);
        expect($export['pets'][0]['play_invitations'])->toHaveCount(1)
            ->and($export['pets'][0]['play_invitations'][0])->toMatchArray(['kind' => 'cuddle', 'status' => 'done', 'child_id' => $child->id])
            ->and($export['pets'][0]['free_plays_per_day'])->toBe([
                ['local_date' => '2026-10-07', 'kind' => 'play', 'child_id' => $child->id, 'count' => 3],
            ]);
        expect($invite->fresh()->status)->toBe(PlayStatus::Done);

        $pet->delete();
        expect(PetPlayEvent::count())->toBe(0);
    });

    it('enforces the row rules in the database', function () {
        [, $child, $pet] = plFamily();

        // A free play is always done and has no schedule.
        expect(fn () => DB::table('pet_play_events')->insert([
            'pet_id' => $pet->id, 'kind' => 'play', 'source' => 'free', 'local_date' => '2026-10-07',
            'scheduled_at' => now(), 'status' => 'done', 'completed_at' => now(),
        ]))->toThrow(QueryException::class);
    });

    it('allows one invitation per kind and day', function () {
        [, , $pet] = plFamily();
        plInvite($pet, 'play', '2026-10-07 08:00:00');

        expect(fn () => plInvite($pet, 'play', '2026-10-07 14:00:00'))->toThrow(QueryException::class);
    });

    it('accepts the new activity types in the CHECK constraint', function () {
        [, $child, $pet] = plFamily();
        foreach ([ActivityType::PlayedWithPet, ActivityType::CuddledPet] as $type) {
            ActivityLog::withoutEvents(fn () => ActivityLog::create(['pet_id' => $pet->id, 'actor_user_id' => $child->id, 'activity_type' => $type->value, 'value' => 1]));
        }
        expect(ActivityLog::whereIn('activity_type', ['played_with_pet', 'cuddled_pet'])->count())->toBe(2);
    });
});
