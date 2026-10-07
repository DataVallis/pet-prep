<?php

use App\Enums\ActivityType;
use App\Enums\HygieneEventKind;
use App\Enums\HygieneEventStatus;
use App\Enums\PushType;
use App\Enums\RoutineType;
use App\Jobs\SendPushNotification;
use App\Models\ActivityLog;
use App\Models\DevicePushToken;
use App\Models\Pet;
use App\Models\PetHygieneEvent;
use App\Models\PushNotification;
use App\Models\QuietHours;
use App\Models\User;
use App\Services\CareScheduleService;
use App\Services\EscalationService;
use App\Services\FamilyService;
use App\Services\NotificationService;
use App\Services\Push\ExpoPushClient;
use App\Services\Results\Routine;
use App\Services\RoutineLedgerService;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| M3-12 — emergency meal + pushes never ask for a refused action
|--------------------------------------------------------------------------
|
| David on a device, 2026-10-07 12:11 (Ljubljana, CEST = UTC+2): a legacy
| mutt at 0 % hunger, the feed button said "ob 17:00" (06–10 window missed),
| and the push said "feed it within 30 minutes or it gets sick". Agreed the
| same day: (1) at displayed hunger ≤ 20 % the child may feed outside a meal
| window — it never makes the missed window "on time" and does not use up
| the next window; (2) pushes never ask for an action the app refuses.
*/

const EF_DAVID_NOW = '2026-10-07 10:11:00'; // Wednesday 12:11 in Ljubljana

beforeEach(function () {
    seedBreedConfigs();
});

afterEach(function () {
    Carbon::setTestNow();
});

function efAt(string $utc): void
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
}

/**
 * A child with a legacy mutt (windows 06–10 / 17–21, no quiet hours) born
 * the day before, acting as that child.
 *
 * @param  array<string, mixed>  $pet
 * @return array{0: User, 1: Pet, 2: User}
 */
function efChild(string $nowUtc = EF_DAVID_NOW, array $pet = []): array
{
    efAt($nowUtc);

    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    $created = disableHygieneEvents(Pet::factory()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => Carbon::parse('2026-10-06 08:00:00', 'UTC'),
        'last_decay_at' => now(),
    ], $pet)));

    actingAsRole($child);

    return [$child, $created, $parent];
}

/** Set a metric exactly (no decay owed: last_decay_at = now). */
function efSet(Pet $pet, array $attributes): Pet
{
    Pet::whereKey($pet->id)->update(array_merge(['last_decay_at' => now()], $attributes));

    return $pet->refresh();
}

function efMess(Pet $pet, HygieneEventKind $kind): void
{
    PetHygieneEvent::create([
        'pet_id' => $pet->id,
        'local_date' => $pet->localDate(now()),
        'scheduled_at' => now()->subMinutes(10),
        'status' => HygieneEventStatus::Applied,
        'resolved_at' => now()->subMinutes(10),
        'kind' => $kind,
    ]);
}

function efFeedRows(Pet $pet)
{
    return ActivityLog::where('pet_id', $pet->id)->where('activity_type', ActivityType::FedPet->value)->orderBy('id')->get();
}

function efWatered(Pet $pet, User $child, Carbon $at): void
{
    $row = ActivityLog::create(['pet_id' => $pet->id, 'actor_user_id' => $child->id, 'activity_type' => ActivityType::WateredPet->value, 'value' => 50]);
    ActivityLog::whereKey($row->id)->toBase()->update(['created_at' => $at]);
}

/** @return list<Routine> */
function efFeedRoutines(Pet $pet, string $date): array
{
    $routines = app(RoutineLedgerService::class)->routinesFor(collect([$pet->fresh()]), $date, $date)[$pet->id];

    return array_values(array_filter($routines, fn ($r) => $r->type === RoutineType::Feed));
}

/* ───────────────────────────── Feeding rule ───────────────────────────── */

describe('emergency meal (feed outside a window at ≤ 20 %)', function () {
    it('allows feeding outside a window at 20 % and reports it as an emergency meal', function () {
        [, $pet] = efChild(pet: ['hunger_level' => 20]);

        $this->getJson('/api/child/pet')->assertOk()
            ->assertJsonPath('feeding.current_window', null)
            ->assertJsonPath('feeding.can_feed', true)
            ->assertJsonPath('feeding.feed_mode', 'emergency')
            ->assertJsonPath('feeding.emergency_threshold', CareScheduleService::EMERGENCY_FEED_THRESHOLD)
            // The next regular meal is still the evening window.
            ->assertJsonPath('feeding.next_feed_window.start', '2026-10-07T17:00:00+02:00');

        $this->postJson('/api/child/pet/feed')->assertOk()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('feed_mode', 'emergency')
            ->assertJsonPath('state.pet.hunger_level', 100)
            ->assertJsonPath('state.feeding.can_feed', false)
            ->assertJsonPath('state.feeding.feed_mode', null)
            ->assertJsonPath('state.feeding.next_feed_window.start', '2026-10-07T17:00:00+02:00');

        $rows = efFeedRows($pet);
        expect($rows)->toHaveCount(1)->and($rows[0]->value)->toBe(20);
        $this->getJson('/api/child/pet')->assertJsonPath('feeding.emergency_threshold', null);
        expect(Pet::findOrFail($pet->id)->hunger_zero_since)->toBeNull();
    });

    it('refuses outside a window at 21 % with the next window', function () {
        [, $pet] = efChild(pet: ['hunger_level' => 21]);

        $this->getJson('/api/child/pet')
            ->assertJsonPath('feeding.can_feed', false)
            ->assertJsonPath('feeding.feed_mode', null);

        $this->postJson('/api/child/pet/feed')->assertStatus(422)
            ->assertJsonPath('reason', 'outside_feed_window')
            ->assertJsonPath('next_allowed_at', '2026-10-07T17:00:00+02:00');

        expect(efFeedRows($pet))->toHaveCount(0);
    });

    it('follows the displayed value: 20.4 shows 20 (allowed), 20.5 shows 21 (refused)', function () {
        [, $pet] = efChild(pet: ['hunger_level' => 20.4]);
        $this->getJson('/api/child/pet')->assertJsonPath('pet.hunger_level', 20)->assertJsonPath('feeding.can_feed', true);
        $this->postJson('/api/child/pet/feed')->assertOk()->assertJsonPath('feed_mode', 'emergency');

        efSet($pet, ['hunger_level' => 20.5]);
        $this->getJson('/api/child/pet')->assertJsonPath('pet.hunger_level', 21)->assertJsonPath('feeding.can_feed', false);
        $this->postJson('/api/child/pet/feed')->assertStatus(422)->assertJsonPath('reason', 'outside_feed_window');
    });

    it('applies decay owed since the last tick before deciding (21.3 % an hour ago → emergency now)', function () {
        // Legacy mutt −8 %/h: 21.3 one hour ago is 13.3 now.
        [, $pet] = efChild(pet: ['hunger_level' => 21.3, 'last_decay_at' => Carbon::parse(EF_DAVID_NOW, 'UTC')->subHour()]);

        $this->postJson('/api/child/pet/feed')->assertOk()->assertJsonPath('feed_mode', 'emergency');
        expect(efFeedRows($pet)[0]->value)->toBe(13);
    });

    it('prefers the window inside an unused window (scored on time), even when hunger is low', function () {
        [, $pet] = efChild('2026-10-07 04:30:00', ['hunger_level' => 5]); // 06:30 local

        $this->getJson('/api/child/pet')->assertJsonPath('feeding.feed_mode', 'window');
        $this->postJson('/api/child/pet/feed')->assertOk()->assertJsonPath('feed_mode', 'window');
    });

    it('refuses a second meal in a window already used, even at low hunger (rule A: nothing was missed)', function () {
        [, $pet] = efChild('2026-10-07 04:30:00'); // 06:30, morning window
        $this->postJson('/api/child/pet/feed')->assertOk()->assertJsonPath('feed_mode', 'window');

        efAt('2026-10-07 07:30:00'); // 09:30, same window, starving dog (admin edit)
        efSet($pet, ['hunger_level' => 15]);
        $this->getJson('/api/child/pet')->assertJsonPath('feeding.can_feed', false)->assertJsonPath('feeding.emergency_threshold', null);
        $this->postJson('/api/child/pet/feed')->assertStatus(422)->assertJsonPath('reason', 'already_fed_this_window');
    });

    it('rule A: a child who fed on time gets no emergency meal at 16:00 with 20 %', function () {
        [, $pet] = efChild('2026-10-07 05:00:00'); // 07:00 local
        $this->postJson('/api/child/pet/feed')->assertOk()->assertJsonPath('feed_mode', 'window');

        efAt('2026-10-07 14:00:00'); // 16:00, last ended window (06–10) was fed
        efSet($pet, ['hunger_level' => 20]);
        $this->getJson('/api/child/pet')
            ->assertJsonPath('feeding.can_feed', false)
            ->assertJsonPath('feeding.feed_mode', null)
            ->assertJsonPath('feeding.emergency_threshold', null);
        $this->postJson('/api/child/pet/feed')->assertStatus(422)
            ->assertJsonPath('reason', 'outside_feed_window')
            ->assertJsonPath('next_allowed_at', '2026-10-07T17:00:00+02:00');
    });

    it('rule A: after an emergency meal no second one until the next window ends unfed', function () {
        [, $pet] = efChild(pet: ['hunger_level' => 5]); // 12:11, 06–10 missed
        $this->getJson('/api/child/pet')->assertJsonPath('feeding.emergency_threshold', 20);
        $this->postJson('/api/child/pet/feed')->assertOk()->assertJsonPath('feed_mode', 'emergency');

        // A minute later (double tap) and at 16:30 with low hunger: refused.
        efAt('2026-10-07 10:12:00');
        efSet($pet, ['hunger_level' => 15]);
        $this->postJson('/api/child/pet/feed')->assertStatus(422)->assertJsonPath('reason', 'outside_feed_window');
        efAt('2026-10-07 14:30:00');
        efSet($pet, ['hunger_level' => 15]);
        $this->getJson('/api/child/pet')->assertJsonPath('feeding.emergency_threshold', null);
        $this->postJson('/api/child/pet/feed')->assertStatus(422);

        // 21:30: the evening window ended unfed → a new emergency meal is possible.
        efAt('2026-10-07 19:30:00');
        efSet($pet, ['hunger_level' => 15]);
        $this->getJson('/api/child/pet')->assertJsonPath('feeding.emergency_threshold', 20)->assertJsonPath('feeding.feed_mode', 'emergency');
        $this->postJson('/api/child/pet/feed')->assertOk()->assertJsonPath('feed_mode', 'emergency');
    });

    it('rule A: no ended window since the birth → not missed (newborn)', function () {
        // Born 12:00 local, the 06–10 window ended before the birth.
        [, $pet] = efChild(pet: ['born_at' => Carbon::parse('2026-10-07 10:00:00', 'UTC'), 'hunger_level' => 15]);
        efAt('2026-10-07 13:00:00'); // 15:00
        efSet($pet, ['hunger_level' => 15]);
        $this->postJson('/api/child/pet/feed')->assertStatus(422)->assertJsonPath('reason', 'outside_feed_window');
    });

    it('rule A: a parent-covered quiet-hour window counts as fed; the HUD next meal skips it', function () {
        seedStageParams();
        // 2-month puppy: 07–09, 11–13, 15–17, 19–21; school 10–13 covers 11–13.
        [, $pet, $parent] = efChild('2026-10-07 11:30:00', ['arrival_age_months' => 2]); // 13:30
        QuietHours::create([
            'parent_id' => $parent->id,
            'school_start' => '10:00', 'school_end' => '13:00',
            'bedtime_start' => '22:00', 'bedtime_end' => '06:00',
            'is_active' => true,
        ]);
        efSet($pet, ['hunger_level' => 15]);
        $this->getJson('/api/child/pet')
            ->assertJsonPath('feeding.windows.1.start', '11:00')
            ->assertJsonPath('feeding.can_feed', false)
            ->assertJsonPath('feeding.emergency_threshold', null);

        // 09:30: 07–09 missed but hunger 28 → refused; next CHILD meal is 15:00, not 11:00.
        efAt('2026-10-07 07:30:00');
        efSet($pet, ['hunger_level' => 28]);
        $this->postJson('/api/child/pet/feed')->assertStatus(422)
            ->assertJsonPath('next_allowed_at', '2026-10-07T15:00:00+02:00')
            ->assertJsonPath('state.feeding.next_feed_window.start', '2026-10-07T15:00:00+02:00');
    });

    it('is refused (423) and not offered under payment_required, illness, unborn and a missing own contract', function (array $attributes, string $reason) {
        [$child, $pet, $parent] = efChild(pet: ['hunger_level' => 5]);
        if ($reason === 'contract_required' && ($attributes['sibling'] ?? false)) {
            $sibling = User::factory()->child()->create(['parent_id' => $parent->id]);
            app(FamilyService::class)->addCaretaker($pet, $sibling, requiresContract: true);
            actingAsRole($sibling);
        } else {
            efSet($pet, $attributes);
        }

        $this->getJson('/api/child/pet')->assertJsonPath('feeding.can_feed', false)->assertJsonPath('feeding.feed_mode', null);
        $this->postJson('/api/child/pet/feed')->assertStatus(423)->assertJsonPath('reason', $reason);
        expect(efFeedRows($pet))->toHaveCount(0);
    })->with([
        'payment_required' => [['payment_locked_at' => '2026-10-07 09:00:00'], 'payment_required'],
        'ill' => [['illness_until' => '2026-10-07 20:00:00', 'frozen_at' => '2026-10-07 08:00:00'], 'ill'],
        'unborn' => [['born_at' => null], 'contract_required'],
        'sibling without contract' => [['sibling' => true], 'contract_required'],
    ]);

    it('works for a profiled pet (stage windows) too', function () {
        seedStageParams();
        // Adult mutt with a profile: 2 meals 06–10 / 17–21 by stage rules.
        [, $pet] = efChild(pet: ['arrival_age_months' => 36, 'hunger_level' => 18]);
        expect($pet->isLegacyProfile())->toBeFalse();

        $this->postJson('/api/child/pet/feed')->assertOk()->assertJsonPath('feed_mode', 'emergency');
    });

    it('keeps the hygiene guard: at 0 % hygiene even a starving dog must be cleaned first', function () {
        [, $pet] = efChild(pet: ['hunger_level' => 0, 'hygiene_level' => 0]);

        $this->getJson('/api/child/pet')->assertJsonPath('feeding.can_feed', false)->assertJsonPath('feeding.feed_mode', null);
        $this->postJson('/api/child/pet/feed')->assertStatus(422)->assertJsonPath('reason', 'needs_cleaning');

        expect(efFeedRows($pet))->toHaveCount(0);
    });

    it('keeps the locks: a hard-stopped dog cannot get an emergency meal', function () {
        [, $pet] = efChild(pet: ['hunger_level' => 0, 'is_hard_stopped' => true]);

        $this->getJson('/api/child/pet')->assertJsonPath('feeding.can_feed', false);
        $this->postJson('/api/child/pet/feed')->assertStatus(423)->assertJsonPath('reason', 'hard_stopped');
    });

    it('allows it during quiet hours like every other feed (child actions are not blocked by quiet hours)', function () {
        [, $pet, $parent] = efChild();
        QuietHours::create([
            'parent_id' => $parent->id,
            'school_start' => '10:00', 'school_end' => '15:00',
            'bedtime_start' => '22:00', 'bedtime_end' => '06:00',
            'is_active' => true,
        ]);

        efAt('2026-10-07 10:00:00'); // 12:00, school (quiet), no window
        efSet($pet, ['hunger_level' => 12]);
        $this->postJson('/api/child/pet/feed')->assertOk()->assertJsonPath('feed_mode', 'emergency');
    });
});

/* ─────────────────────────────── Scoring ─────────────────────────────── */

describe('emergency meal and the routine ledger', function () {
    it('David\'s day: the missed morning window stays missed, the evening window is still feedable and counts', function () {
        // 12:11, nobody fed in 06–10, hunger 0 % for a while.
        [$child, $pet] = efChild(pet: ['hunger_level' => 0, 'hunger_zero_since' => Carbon::parse(EF_DAVID_NOW, 'UTC')->subMinutes(40)]);

        $this->getJson('/api/child/pet')->assertJsonPath('feeding.can_feed', true)->assertJsonPath('feeding.feed_mode', 'emergency');
        $this->postJson('/api/child/pet/feed')->assertOk()->assertJsonPath('feed_mode', 'emergency');

        [$morning, $evening] = efFeedRoutines($pet, '2026-10-07');
        expect($morning->isMissed())->toBeTrue()
            ->and($morning->doneAt)->toBeNull()
            ->and($evening->isPending())->toBeTrue();

        // 17:30 — the evening window is not used up by the emergency meal.
        efAt('2026-10-07 15:30:00');
        $this->getJson('/api/child/pet')
            ->assertJsonPath('feeding.can_feed', true)
            ->assertJsonPath('feeding.feed_mode', 'window')
            ->assertJsonPath('feeding.fed_in_current_window', false);
        $this->postJson('/api/child/pet/feed')->assertOk()->assertJsonPath('feed_mode', 'window');

        efAt('2026-10-08 06:00:00'); // next day — 07-10 closed
        [$morning, $evening] = efFeedRoutines($pet, '2026-10-07');
        expect($morning->isMissed())->toBeTrue()
            ->and($evening->isDone())->toBeTrue()
            ->and($evening->actorUserId)->toBe($child->id)
            ->and($evening->doneAt?->toIso8601String())->toBe('2026-10-07T15:30:00+00:00');
        expect(efFeedRows($pet))->toHaveCount(2);
    });

    it('an emergency meal right before a window does not make that window done', function () {
        [, $pet] = efChild('2026-10-07 14:50:00', ['hunger_level' => 10]); // 16:50
        $this->postJson('/api/child/pet/feed')->assertOk()->assertJsonPath('feed_mode', 'emergency');

        efAt('2026-10-07 19:05:00'); // 21:05, evening window over without a meal in it
        [, $evening] = efFeedRoutines($pet, '2026-10-07');
        expect($evening->isMissed())->toBeTrue();
    });

    it('a stored closed day keeps the window missed', function () {
        [, $pet] = efChild(pet: ['hunger_level' => 5]);
        $this->postJson('/api/child/pet/feed')->assertOk();

        efAt('2026-10-08 06:00:00');
        app(RoutineLedgerService::class)->closePet($pet->id);
        $stored = DB::table('pet_daily_routines')->where('pet_id', $pet->id)->where('local_date', '2026-10-07')
            ->where('routine_type', 'feed')->orderBy('slot')->pluck('status')->all();
        expect($stored)->toBe(['missed', 'missed']);
    });
});

/* ──────────────────────────────── Pushes ──────────────────────────────── */

function efToken(): string
{
    return 'ExponentPushToken['.Str::random(22).']';
}

function efDevice(User $user, string $locale = 'sl'): DevicePushToken
{
    return DevicePushToken::create([
        'user_id' => $user->id,
        'expo_push_token' => efToken(),
        'platform' => 'ios',
        'app_version' => '1.0.0',
        'locale' => $locale,
        'last_seen_at' => now(),
    ]);
}

function efFakeExpo(): ArrayObject
{
    $sent = new ArrayObject;
    Http::swap(new HttpFactory(app('events')));
    Http::preventStrayRequests();
    Http::fake([
        'https://exp.host/--/api/v2/push/send' => function (Request $request) use ($sent) {
            $tickets = [];
            foreach ($request->data() as $message) {
                $sent->append($message);
                $tickets[] = ['status' => 'ok', 'id' => 'ticket-'.Str::random(10)];
            }

            return Http::response(['data' => $tickets]);
        },
    ]);

    return $sent;
}

/** Decide + deliver one escalation push now; returns the stored row (fresh). */
function efPush(Pet $pet, PushType $type, string $metric): PushNotification
{
    config(['push.enabled' => true]);
    Queue::fake([SendPushNotification::class]);
    $row = app(NotificationService::class)->escalation($pet->fresh(), $type, $metric);
    expect($row)->not->toBeNull();
    (new SendPushNotification($row->id))->handle(app(NotificationService::class), app(ExpoPushClient::class));

    return $row->fresh();
}

const EF_FEED_NOW_TEXTS = [
    'Tvoj kuža te milo gleda in kaže na posodo s hrano.',
    'Če ga ne nahraniš v 30 minutah, bo zbolel.',
];

describe('pushes never ask for a refused action', function () {
    it('David\'s exact scenario: the critical hunger push at 12:11 asks for something the app now allows', function () {
        [$child, $pet] = efChild(pet: ['hunger_level' => 8]);
        efDevice($child);
        $sent = efFakeExpo();

        $row = efPush($pet, PushType::CriticalAlert, 'hunger');

        expect($row->status)->toBe(PushNotification::STATUS_SENT)
            ->and($sent[0]['body'])->toBe('Če ga ne nahraniš v 30 minutah, bo zbolel.');
        // …and feeding is really possible right now.
        $this->postJson('/api/child/pet/feed')->assertOk()->assertJsonPath('feed_mode', 'emergency');
    });

    it('a 30 % hunger reminder at noon (window closed, feeding refused) names the next meal instead of "feed now"', function () {
        [$child, $pet] = efChild(pet: ['hunger_level' => 28]);
        $sl = efDevice($child, 'sl');
        $en = efDevice($child, 'en');
        $sent = efFakeExpo();

        $row = efPush($pet, PushType::SoftWarning, 'hunger');

        $byToken = collect($sent->getArrayCopy())->keyBy('to');
        expect($row->status)->toBe(PushNotification::STATUS_SENT)
            ->and($byToken[$sl->expo_push_token]['body'])->toBe('Tvoj kuža postaja lačen. Naslednji obrok je ob 17:00 — ne pozabi nanj.')
            ->and($byToken[$en->expo_push_token]['body'])->toBe('Your dog is getting hungry. The next meal is at 17:00 — don’t forget it.');
        $this->postJson('/api/child/pet/feed')->assertStatus(422);
    });

    it('keeps the plain hunger text inside an open window', function () {
        [$child, $pet] = efChild('2026-10-07 15:30:00', ['hunger_level' => 28]); // 17:30
        efDevice($child);
        $sent = efFakeExpo();

        efPush($pet, PushType::SoftWarning, 'hunger');

        expect($sent[0]['body'])->toBe('Tvoj kuža te milo gleda in kaže na posodo s hrano.');
    });

    it('drops a 30 % hunger reminder when no meal is possible any more today', function () {
        [$child, $pet] = efChild('2026-10-07 19:30:00', ['hunger_level' => 28]); // 21:30, evening window over
        efDevice($child);
        $sent = efFakeExpo();

        $row = efPush($pet, PushType::SoftWarning, 'hunger');

        expect($row->status)->toBe(PushNotification::STATUS_SUPPRESSED)
            ->and($row->suppressed_reason)->toBe('not_actionable')
            ->and($sent)->toHaveCount(0);
    });

    it('asks to clean first when hygiene 0 % blocks the food', function () {
        [$child, $pet] = efChild(pet: ['hunger_level' => 0, 'hygiene_level' => 0]);
        $sl = efDevice($child, 'sl');
        $en = efDevice($child, 'en');
        $sent = efFakeExpo();

        efPush($pet, PushType::CriticalAlert, 'hunger');

        $byToken = collect($sent->getArrayCopy())->keyBy('to');
        expect($byToken[$sl->expo_push_token]['body'])->toBe('Tvoj kuža je lačen, a najprej je treba počistiti nered. Potem ga lahko nahraniš.')
            ->and($byToken[$en->expo_push_token]['body'])->toBe('Your dog is hungry, but the mess has to be cleaned up first. Then you can feed it.');
        $this->postJson('/api/child/pet/feed')->assertStatus(422)->assertJsonPath('reason', 'needs_cleaning');
    });

    it('a thirst reminder during the water gap says when water is possible again', function () {
        [$child, $pet] = efChild(pet: ['thirst_level' => 8]);
        efWatered($pet, $child, now()->subHour());
        efDevice($child);
        $sent = efFakeExpo();

        efPush($pet, PushType::CriticalAlert, 'thirst');

        // Last water 11:11 + 180 min = 14:11.
        expect($sent[0]['body'])->toBe('Tvoj kuža je žejen. Vodo mu lahko spet daš ob 14:11 — ne pozabi nanj.');
        $this->postJson('/api/child/pet/water')->assertStatus(422)->assertJsonPath('reason', 'water_too_soon');
    });

    it('drops a thirst reminder once the daily water limit is reached (nothing possible until tomorrow)', function () {
        [$child, $pet] = efChild('2026-10-07 18:00:00', ['thirst_level' => 25]); // 20:00
        foreach (['2026-10-07 05:00:00', '2026-10-07 09:00:00', '2026-10-07 13:00:00'] as $at) {
            efWatered($pet, $child, Carbon::parse($at, 'UTC'));
        }
        efDevice($child);
        $sent = efFakeExpo();

        $row = efPush($pet, PushType::SoftWarning, 'thirst');

        expect($row->suppressed_reason)->toBe('not_actionable')->and($sent)->toHaveCount(0);
    });

    it('does not send care reminders to a caretaker who still has to sign the contract', function () {
        [$child, $pet, $parent] = efChild(pet: ['hunger_level' => 8]);
        $sibling = User::factory()->child()->create(['parent_id' => $parent->id]);
        app(FamilyService::class)->addCaretaker($pet, $sibling, requiresContract: true);
        $signed = efDevice($child);
        efDevice($sibling);
        $sent = efFakeExpo();

        efPush($pet, PushType::CriticalAlert, 'hunger');

        expect(collect($sent->getArrayCopy())->pluck('to')->all())->toBe([$signed->expo_push_token]);
    });

    it('a "wait" hunger push names the next CHILD window, skipping a parent-covered one', function () {
        seedStageParams();
        [$child, $pet, $parent] = efChild('2026-10-07 07:30:00', ['arrival_age_months' => 2, 'hunger_level' => 28]); // 09:30
        QuietHours::create([
            'parent_id' => $parent->id,
            'school_start' => '10:00', 'school_end' => '13:00',
            'bedtime_start' => '22:00', 'bedtime_end' => '06:00',
            'is_active' => true,
        ]);
        efDevice($child);
        $sent = efFakeExpo();

        efPush($pet, PushType::SoftWarning, 'hunger');

        expect($sent[0]['body'])->toBe('Tvoj kuža postaja lačen. Naslednji obrok je ob 15:00 — ne pozabi nanj.');
    });

    it('drops a "wait" push when no child meal is left today', function () {
        seedStageParams();
        // 20:30 → 21:15: 19–21 used; puppy windows done; bedtime 21:00–06:00 would cover nothing else today.
        [$child, $pet] = efChild('2026-10-07 18:30:00', ['arrival_age_months' => 2]);
        $this->postJson('/api/child/pet/feed')->assertOk()->assertJsonPath('feed_mode', 'window');
        efAt('2026-10-07 18:45:00');
        efSet($pet, ['hunger_level' => 28]);
        efDevice($child);
        $sent = efFakeExpo();

        $row = efPush($pet, PushType::SoftWarning, 'hunger');

        expect($row->suppressed_reason)->toBe('not_actionable')->and($sent)->toHaveCount(0);
    });

    it('hygiene reminders point to "tidy up and give a toy" while a chewed item is open', function () {
        [$child, $pet] = efChild(pet: ['hygiene_level' => 0]);
        $sl = efDevice($child, 'sl');
        $en = efDevice($child, 'en');
        efMess($pet, HygieneEventKind::Chewing);
        $sent = efFakeExpo();

        efPush($pet, PushType::SoftWarning, 'hygiene');
        $byToken = collect($sent->getArrayCopy())->keyBy('to');
        expect($byToken[$sl->expo_push_token]['body'])->toBe('Tvoj kuža je nekaj pregriznil. Pospravi in mu daj igračo.')
            ->and($byToken[$en->expo_push_token]['body'])->toBe('Your dog has chewed something. Tidy it up and give it a toy.');

        // Chewing + a poop: both actions; critical copy.
        efMess($pet, HygieneEventKind::Poop);
        $sent = efFakeExpo();
        efPush($pet, PushType::CriticalAlert, 'hygiene');
        $byToken = collect($sent->getArrayCopy())->keyBy('to');
        expect($byToken[$sl->expo_push_token]['body'])->toBe('Počisti nered, pospravi pregrizeno in kužku daj igračo čim prej, sicer bo zbolel.');
    });

    it('keeps the plain mess text when only a poop is open', function () {
        [$child, $pet] = efChild(pet: ['hygiene_level' => 0]);
        efDevice($child);
        efMess($pet, HygieneEventKind::Poop);
        $sent = efFakeExpo();

        efPush($pet, PushType::SoftWarning, 'hygiene');

        expect($sent[0]['body'])->toBe('Tvoj kuža te milo gleda in kaže na nered, ki ga je treba počistiti.');
    });

    it('a not_actionable drop keeps the escalation ladder: phase 2 and 3 still go out', function () {
        config(['push.enabled' => true]);
        Queue::fake([SendPushNotification::class]);
        [$child, $pet, $parent] = efChild('2026-10-07 19:30:00', ['hunger_level' => 28]); // 21:30, evening missed
        // The child fed the evening window late? No: nobody fed today — but at 28 % feeding is refused and no window is left today.
        efDevice($child);
        efDevice($parent);
        $sent = efFakeExpo();
        $deliverAll = function () use ($pet): void {
            PushNotification::where('pet_id', $pet->id)->where('status', PushNotification::STATUS_QUEUED)->pluck('id')
                ->each(fn ($id) => (new SendPushNotification($id))->handle(app(NotificationService::class), app(ExpoPushClient::class)));
        };

        app(EscalationService::class)->processPetEscalation($pet->fresh());
        $deliverAll();
        expect(Pet::findOrFail($pet->id)->escalation_level)->toBe(1)
            ->and(PushNotification::where('pet_id', $pet->id)->where('type', PushType::SoftWarning->value)->value('suppressed_reason'))->toBe('not_actionable')
            ->and($sent)->toHaveCount(0);

        // 21:40, hunger 8 % → phase 2; the 17–21 window was missed → emergency meal possible → plain text.
        efAt('2026-10-07 19:40:00');
        efSet($pet, ['hunger_level' => 8]);
        app(EscalationService::class)->processPetEscalation($pet->fresh());
        $deliverAll();
        expect(Pet::findOrFail($pet->id)->escalation_level)->toBe(2)
            ->and(collect($sent->getArrayCopy())->pluck('body')->all())->toBe(['Če ga ne nahraniš v 30 minutah, bo zbolel.']);

        // 0 % for more than an hour → phase 3 to the parent.
        efAt('2026-10-07 21:00:00');
        efSet($pet, ['hunger_level' => 0, 'hunger_zero_since' => Carbon::parse('2026-10-07 19:50:00', 'UTC')]);
        app(EscalationService::class)->processPetEscalation($pet->fresh());
        $deliverAll();
        expect(Pet::findOrFail($pet->id)->escalation_level)->toBe(3)
            ->and(PushNotification::where('pet_id', $pet->id)->where('type', PushType::ParentAlarm->value)->value('status'))->toBe(PushNotification::STATUS_SENT);
    });

    it('property: over a whole day, a hunger reminder says "feed" only when the feed action would be accepted', function () {
        [$child, $pet] = efChild('2026-10-06 22:00:00'); // 00:00 local
        efDevice($child);

        foreach ([28, 22, 21, 20, 15, 5] as $hunger) {
            for ($hour = 0; $hour < 24; $hour++) {
                efAt(Carbon::parse('2026-10-06 22:00:00', 'UTC')->addHours($hour)->addMinutes(15)->toDateTimeString());
                efSet($pet, ['hunger_level' => $hunger]);
                PushNotification::where('pet_id', $pet->id)->delete();
                $sent = efFakeExpo();

                efPush($pet, $hunger <= 10 ? PushType::CriticalAlert : PushType::SoftWarning, 'hunger');
                $body = $sent[0]['body'] ?? null;

                $allowed = app(CareScheduleService::class)
                    ->feedCheck($pet->fresh(), $pet->breedConfig(), now())->allowed;
                $saysFeed = in_array($body, EF_FEED_NOW_TEXTS, true);

                expect($saysFeed)->toBe($allowed, "hunger {$hunger} at local hour {$hour}: '{$body}'");
                // Nobody fed since the birth, so every ended window was missed:
                // at ≤ 20 % the emergency meal is always possible → plain text.
                if ($hunger <= CareScheduleService::EMERGENCY_FEED_THRESHOLD) {
                    expect($saysFeed)->toBeTrue();
                }
            }
        }
    });
});
