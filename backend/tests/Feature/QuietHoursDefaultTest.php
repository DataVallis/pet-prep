<?php

use App\Enums\PushType;
use App\Models\DevicePushToken;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\Pet;
use App\Models\PushNotification;
use App\Models\QuietHours;
use App\Models\User;
use App\Services\FamilyInviteService;
use App\Services\NotificationService;
use App\Services\Push\PushTiming;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

use function Pest\Laravel\artisan;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| fix/quiet-hours-default (2026-10-08)
|--------------------------------------------------------------------------
| Production bug: a family whose parent app SHOWED quiet hours 21:00–07:00
| (form defaults) had no quiet_hours row on the server → a walk reminder at
| 00:00 local and a hygiene illness push around 03:30 local. Every family now
| has quiet hours (QuietHours::DEFAULTS), and the walk reminder never goes
| out right after midnight, even with quiet hours switched off.
| Family timezone Europe/Ljubljana; October 2026 = CEST (UTC+2).
*/

const QD_PUSH_SEND = 'https://exp.host/--/api/v2/push/send';

function qdAt(string $utc): void
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
}

/**
 * Parent + child + a born legacy mutt, push enabled and a device for the
 * child. $quietRow: 'default' (row created with the family), 'none' (the row
 * removed — production state before the fix) or attributes for the row.
 *
 * @return array{0: User, 1: User, 2: Pet}
 */
function qdFamily(string|array $quietRow = 'default', array $pet = []): array
{
    config(['push.enabled' => true]);
    seedBreedConfigs();

    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    if ($quietRow === 'none') {
        QuietHours::where('family_id', $parent->family->id)->delete();
    } elseif (is_array($quietRow)) {
        setQuietHours(array_merge(['parent_id' => $parent->id], $quietRow));
    }

    $created = disableHygieneEvents(Pet::factory()->mutt()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now()->copy()->subDays(5),
        'last_decay_at' => now(),
        'hunger_level' => 100,
        'thirst_level' => 100,
        'hygiene_level' => 100,
        'energy_level' => 100,
        'escalation_level' => 0,
    ], $pet)));

    DevicePushToken::create([
        'user_id' => $child->id,
        'expo_push_token' => 'ExponentPushToken['.Str::random(22).']',
        'platform' => 'ios',
        'app_version' => '3.0.0',
        'locale' => 'en',
        'last_seen_at' => now(),
    ]);

    return [$parent, $child, $created];
}

/** Fake Expo; every message sent is collected. */
function qdExpo(): ArrayObject
{
    $sent = new ArrayObject;
    Http::preventStrayRequests();
    Http::fake([
        QD_PUSH_SEND => function (Request $request) use ($sent) {
            $tickets = [];
            foreach ($request->data() as $message) {
                $sent->append($message);
                $tickets[] = ['status' => 'ok', 'id' => 'ticket-'.Str::random(8)];
            }

            return Http::response(['data' => $tickets]);
        },
        'https://exp.host/--/api/v2/push/getReceipts' => Http::response(['data' => []]),
    ]);

    return $sent;
}

/** One scheduler minute: the game loop and the held-push dispatcher. */
function qdTick(string $utc): void
{
    qdAt($utc);
    artisan('pets:process-decay')->assertSuccessful();
    artisan('push:dispatch-scheduled')->assertSuccessful();
}

/** @return list<string> */
function qdSentTypes(ArrayObject $sent): array
{
    return collect($sent->getArrayCopy())->pluck('data.type')->all();
}

afterEach(function () {
    Carbon::setTestNow();
});

describe('regression: the family without a quiet_hours row (production, 2026-10-08)', function () {
    it('sends no walk reminder at 00:00 local — the default night holds it until 09:00', function () {
        qdAt('2026-10-07 21:50:00'); // 23:50 local
        [, , $pet] = qdFamily('none', [
            'energy_level' => 60, 'daily_step_count' => 6000, 'last_step_reset_at' => Carbon::parse('2026-10-07 06:00:00', 'UTC'),
        ]);
        expect(QuietHours::where('family_id', $pet->family_id)->exists())->toBeFalse();
        $sent = qdExpo();

        qdTick('2026-10-07 21:55:00');
        qdTick('2026-10-07 22:00:00'); // 00:00 local: the day closes, energy → 0
        qdTick('2026-10-07 22:01:00');

        expect($pet->refresh()->displayMetric('energy_level'))->toBe(0)
            ->and(PushNotification::count())->toBe(0)          // before the fix: walk_reminder sent at 00:00
            ->and($sent)->toHaveCount(0);

        // 07:00 local: the night ends; the reminder is decided now, held until 09:00.
        qdTick('2026-10-08 05:00:00');
        $row = PushNotification::where('type', PushType::WalkReminder->value)->sole();
        expect($row->status)->toBe(PushNotification::STATUS_SCHEDULED)
            ->and($row->send_after->toIso8601String())->toBe('2026-10-08T07:00:00+00:00')
            ->and($sent)->toHaveCount(0);

        qdTick('2026-10-08 07:00:00'); // 09:00 local
        expect($row->refresh()->status)->toBe(PushNotification::STATUS_SENT)
            ->and(qdSentTypes($sent))->toBe(['walk_reminder']);
    });

    it('does not make the dog ill at 03:30 local: the hygiene clock pauses over the default night', function () {
        qdAt('2026-10-07 17:00:00'); // 19:00 local, the mess appears
        [, , $pet] = qdFamily('none', ['hygiene_level' => 0, 'hygiene_zero_since' => now()]);
        $sent = qdExpo();

        // Before the fix: 6 h of neglect → illness + push at 01:00 local.
        foreach (['2026-10-07 23:00:00', '2026-10-08 01:30:00'] as $utc) { // 01:00 and 03:30 local
            qdTick($utc);
        }
        expect($pet->refresh()->isIll())->toBeFalse()
            ->and($sent)->toHaveCount(0);

        // 19–21 = 2 h, then 07:00 + 4 h → ill at 11:00 local, push right away (not quiet).
        qdTick('2026-10-08 08:55:00');
        expect($pet->refresh()->isIll())->toBeFalse();
        qdTick('2026-10-08 09:00:00');
        expect($pet->refresh()->isIll())->toBeTrue()
            ->and(qdSentTypes($sent))->toContain('illness_triggered');
    });

    it('holds an illness push decided at 03:30 local until 07:00 local', function () {
        qdAt('2026-10-08 01:30:00'); // 03:30 local
        [, , $pet] = qdFamily('none');
        qdExpo();

        $row = app(NotificationService::class)->escalation($pet, PushType::Illness, 'hygiene');

        expect($row->status)->toBe(PushNotification::STATUS_SCHEDULED)
            ->and($row->send_after->toIso8601String())->toBe('2026-10-08T05:00:00+00:00');
    });

    it('reads the defaults for a family that cannot own a row (no parent)', function () {
        $child = User::factory()->child()->create();
        $pet = Pet::factory()->create(['user_id' => $child->id]);

        $quiet = $pet->quietHours();
        expect($quiet->exists)->toBeFalse()
            ->and($quiet->bedtime_start)->toBe('21:00')
            ->and($quiet->bedtime_end)->toBe('07:00')
            ->and($quiet->school_start)->toBeNull()
            ->and($quiet->is_active)->toBeTrue()
            ->and($quiet->isQuietNow(Carbon::parse('2026-10-08 01:30:00', 'UTC')))->toBeTrue()
            ->and($quiet->isQuietNow(Carbon::parse('2026-10-08 10:00:00', 'UTC')))->toBeFalse();
    });
});

describe('every family has quiet hours from its creation', function () {
    it('creates the default row with the family (registration)', function () {
        postJson('/api/register', [
            'name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'Varno1Geslo',
            'password_confirmation' => 'Varno1Geslo', 'timezone' => 'Europe/Ljubljana',
            'accept_terms' => true, 'device_name' => 'iPhone',
        ])->assertCreated();

        $parent = User::where('email', 'ana@example.com')->sole();
        $row = QuietHours::where('family_id', $parent->family->id)->sole();
        expect($row->parent_id)->toBe($parent->id)
            ->and($row->bedtime_start)->toStartWith('21:00')
            ->and($row->bedtime_end)->toStartWith('07:00')
            ->and($row->school_start)->toBeNull()
            ->and($row->school_end)->toBeNull()
            ->and($row->is_active)->toBeTrue();

        // The parent app shows server truth: the saved defaults, not null.
        actingAsRole($parent);
        getJson('/api/parent/quiet-hours')->assertOk()
            ->assertJsonPath('quiet_hours.bedtime_start', '21:00')
            ->assertJsonPath('quiet_hours.bedtime_end', '07:00')
            ->assertJsonPath('quiet_hours.school_start', null)
            ->assertJsonPath('quiet_hours.is_active', true);
    });

    it('keeps one row per family when a second parent joins, and never overwrites a saved choice', function () {
        $first = User::factory()->parent()->create();
        setQuietHours(['parent_id' => $first->id, 'bedtime_start' => '22:30', 'bedtime_end' => '06:30', 'is_active' => false]);
        $second = User::factory()->parent()->create();
        $code = app(FamilyInviteService::class)->createInvite($first)['code'];

        actingAsRole($second);
        postJson('/api/parent/join-family', ['code' => $code])->assertOk();

        expect(QuietHours::count())->toBe(1)
            ->and(QuietHours::sole())
            ->family_id->toBe($first->family->id)
            ->bedtime_start->toStartWith('22:30')
            ->is_active->toBeFalse();
    });
});

describe('data migration 2026_10_22_120000_backfill_default_quiet_hours', function () {
    function qdRunMigration(): void
    {
        (require database_path('migrations/2026_10_22_120000_backfill_default_quiet_hours.php'))->up();
    }

    it('inserts the default only where a family has none, never touches existing rows, and is idempotent', function () {
        // A: a parent family without a row (production before the fix).
        $a = User::factory()->parent()->create();
        $aFamily = $a->family->id;
        QuietHours::where('family_id', $aFamily)->delete();

        // B: the parent's explicit choice — switched off, own times.
        $b = User::factory()->parent()->create();
        $bRow = setQuietHours(['parent_id' => $b->id, 'school_start' => '08:00', 'school_end' => '13:00', 'is_active' => false]);
        $bBefore = DB::table('quiet_hours')->where('id', $bRow->id)->first();

        // C: no parent at all (legacy child-only family) → skipped, code fallback.
        $c = User::factory()->child()->create();
        $cFamily = Pet::factory()->create(['user_id' => $c->id])->family_id;

        // D: first parent still owns a legacy row without a family (parent_id is unique) →
        // the next parent of the family gets the row.
        $d1 = User::factory()->parent()->create();
        $dFamily = $d1->family->id;
        QuietHours::where('family_id', $dFamily)->update(['family_id' => null]);
        $d2 = User::factory()->parent()->create();
        $d2Own = $d2->family->id;
        FamilyMember::where('user_id', $d2->id)->update(['family_id' => $dFamily]);
        QuietHours::where('family_id', $d2Own)->delete();
        Family::whereKey($d2Own)->delete();

        qdRunMigration();
        qdRunMigration();

        $aRow = QuietHours::where('family_id', $aFamily)->sole();
        expect($aRow->parent_id)->toBe($a->id)
            ->and($aRow->bedtime_start)->toStartWith('21:00')
            ->and($aRow->bedtime_end)->toStartWith('07:00')
            ->and($aRow->school_start)->toBeNull()
            ->and($aRow->is_active)->toBeTrue();
        expect((array) DB::table('quiet_hours')->where('id', $bRow->id)->first())->toBe((array) $bBefore);
        expect(QuietHours::where('family_id', $cFamily)->exists())->toBeFalse();
        expect(QuietHours::where('family_id', $dFamily)->sole()->parent_id)->toBe($d2->id)
            ->and(QuietHours::where('parent_id', $d1->id)->sole()->family_id)->toBeNull();
        expect(QuietHours::count())->toBe(4); // A (new), B, D legacy, D new
    });
});

describe('quiet hours switched off (is_active = false) — the parent\'s choice', function () {
    it('counts the night: hygiene illness and its push at 01:00 local', function () {
        qdAt('2026-10-07 17:00:00'); // 19:00 local
        [, , $pet] = qdFamily(['bedtime_start' => '21:00', 'bedtime_end' => '07:00', 'is_active' => false], [
            'hygiene_level' => 0, 'hygiene_zero_since' => now(),
        ]);
        $sent = qdExpo();

        qdTick('2026-10-07 23:00:00'); // 01:00 local, 6 h

        expect($pet->refresh()->isIll())->toBeTrue()
            ->and(qdSentTypes($sent))->toContain('illness_triggered');
    });

    it('still holds the walk reminder after midnight: 2 h after the stored night end, else 09:00', function (array $row, string $sendAfterUtc) {
        qdAt('2026-10-07 21:55:00'); // 23:55 local
        [, , $pet] = qdFamily(array_merge($row, ['is_active' => false]), [
            'energy_level' => 60, 'daily_step_count' => 6000, 'last_step_reset_at' => Carbon::parse('2026-10-07 06:00:00', 'UTC'),
        ]);
        $sent = qdExpo();

        qdTick('2026-10-07 22:00:00'); // 00:00 local: energy → 0, not quiet

        $reminder = PushNotification::where('type', PushType::WalkReminder->value)->sole();
        expect($reminder->status)->toBe(PushNotification::STATUS_SCHEDULED)
            ->and($reminder->send_after->toIso8601String())->toBe($sendAfterUtc)
            ->and($sent)->toHaveCount(0);

        // Nothing at 03:00 local either.
        qdTick('2026-10-08 01:00:00');
        expect($sent)->toHaveCount(0);

        qdAt($sendAfterUtc);
        artisan('push:dispatch-scheduled')->assertSuccessful();
        expect(qdSentTypes($sent))->toBe(['walk_reminder']);
    })->with([
        'default night 21–07 → 09:00' => [['bedtime_start' => '21:00', 'bedtime_end' => '07:00'], '2026-10-08T07:00:00+00:00'],
        'night 22–06 → 08:00' => [['bedtime_start' => '22:00', 'bedtime_end' => '06:00'], '2026-10-08T06:00:00+00:00'],
        'no night window → 09:00' => [['school_start' => '08:00', 'school_end' => '13:00'], '2026-10-08T07:00:00+00:00'],
    ]);
});

describe('PushTiming::walkReminderFloor', function () {
    it('derives the floor from a night window that wraps midnight, else 09:00', function (?string $start, ?string $end, string $floor) {
        $quiet = new QuietHours(['bedtime_start' => $start, 'bedtime_end' => $end, 'is_active' => false]);

        expect(PushTiming::walkReminderFloor($quiet, Carbon::parse('2026-10-08 10:00:00', 'UTC'), 'Europe/Ljubljana')->format('H:i'))
            ->toBe($floor);
    })->with([
        ['21:00', '07:00', '09:00'],
        ['22:00', '05:30', '07:30'],
        [null, null, '09:00'],
        ['13:00', '15:00', '09:00'],   // not a night window
        ['23:59', '23:00', '09:00'],   // end + 2 h falls on the next day
    ]);

    it('keeps the later of the floor and 2 h after the last quiet stretch (school until 13:00 → 15:00)', function () {
        $quiet = new QuietHours(['school_start' => '08:00', 'school_end' => '13:00', 'bedtime_start' => '22:00', 'bedtime_end' => '06:00', 'is_active' => true]);
        $quiet->setRelation('family', new Family(['timezone' => 'Europe/Ljubljana']));

        expect(PushTiming::walkReminderEarliest($quiet, Carbon::parse('2026-10-08 11:30:00', 'UTC'), 'Europe/Ljubljana')->utc()->toIso8601String())
            ->toBe('2026-10-08T13:00:00+00:00');
    });
});
