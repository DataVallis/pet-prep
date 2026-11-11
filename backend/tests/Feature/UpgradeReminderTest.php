<?php

use App\Enums\ActivityType;
use App\Enums\FamilyRole;
use App\Enums\PetPlan;
use App\Enums\PushType;
use App\Enums\Species;
use App\Jobs\SendPushNotification;
use App\Models\ActivityLog;
use App\Models\DevicePushToken;
use App\Models\Pet;
use App\Models\PushNotification;
use App\Models\User;
use App\Services\FamilyService;
use App\Services\NotificationService;
use App\Services\Push\ExpoPushClient;
use App\Services\Push\PushCopy;
use App\Services\UpgradeReminderService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

use function Pest\Laravel\getJson;

/*
|--------------------------------------------------------------------------
| M6-U02 — the day-7 parent reminder of a free pet (FREE_PET_UPGRADE_SPEC U5 /
| D1 / §5.7, David 2026-11-10)
|--------------------------------------------------------------------------
| 7 days after the contract, when ONE child acted on at least 5 of those 7
| pet-local days: one push to the parents (held over quiet hours; title = the
| pet's name, body without names or prices) + the dashboard card. Once per pet;
| never for a locked pet, after game over or after the purchase.
|
| The pet below signs its contract Mon 2026-11-02 18:00 Ljubljana (17:00 UTC):
| its 7 days are 11-02 … 11-08; the reminder is due Mon 11-09 18:00 local.
*/

const UR_BORN = '2026-11-02 17:00:00';
const UR_DUE = '2026-11-09 17:00:00';

beforeEach(function () {
    Http::preventStrayRequests();
    seedLifeStageData();
    config(['push.enabled' => true]);
    Queue::fake([SendPushNotification::class]);
});

afterEach(fn () => Carbon::setTestNow());

function urAt(string $utc): void
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
}

/**
 * Parent + child + a born, profiled free mutt (contract UR_BORN), no quiet hours unless asked.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: User, 1: User, 2: Pet}
 */
function urFamily(array $attributes = [], string $breed = 'mutt', bool $quiet = false): array
{
    urAt(UR_BORN);
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => 'Maja']);
    if ($quiet) {
        setQuietHours(['parent_id' => $parent->id, 'bedtime_start' => '17:30', 'bedtime_end' => '07:00', 'is_active' => true]);
    } else {
        withoutQuietHours($parent);
    }
    $pet = Pet::factory()->freePlan()->create(array_merge([
        'user_id' => $child->id,
        'breed_type' => $breed,
        'born_at' => Carbon::parse(UR_BORN, 'UTC'),
        'arrival_age_months' => 2,
        'name' => 'Pika',
    ], $attributes));

    return [$parent, $child, disableHygieneEvents($pet)->fresh()];
}

/** One care action of $child at each UTC instant. */
function urActs(Pet $pet, User $child, string ...$utc): void
{
    foreach ($utc as $at) {
        ActivityLog::withoutEvents(fn () => (new ActivityLog)->forceFill([
            'pet_id' => $pet->id,
            'actor_user_id' => $child->id,
            'activity_type' => ActivityType::FedPet,
            'value' => null,
            'created_at' => Carbon::parse($at, 'UTC'),
        ])->save());
    }
}

/** Actions on the first $n of the 7 local days (11-02 … 11-08, at 19:00 local). */
function urActiveDays(Pet $pet, User $child, int $n): void
{
    $times = [];
    for ($d = 0; $d < $n; $d++) {
        $times[] = Carbon::parse('2026-11-02 18:00:00', 'UTC')->addDays($d)->format('Y-m-d H:i:s');
    }
    urActs($pet, $child, ...$times);
}

function urTick(?string $utc = null): array
{
    if ($utc !== null) {
        urAt($utc);
    }

    return app(UpgradeReminderService::class)->processDue();
}

function urPushes(Pet $pet): Collection
{
    return PushNotification::where('pet_id', $pet->id)->where('type', PushType::UpgradeReminder->value)->get();
}

function urCard(User $parent, Pet $pet): ?array
{
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return collect(getJson('/api/parent/dashboard')->assertOk()->json('family.pets'))->firstWhere('id', $pet->id)['upgrade_reminder'];
}

describe('D1: 5 of the 7 days', function () {
    it('sends once 7 days after the contract when the child acted on 5 of the 7 days', function () {
        [$parent, $child, $pet] = urFamily();
        urActiveDays($pet, $child, 5);

        // Not before the 7 days are over.
        expect(urTick('2026-11-09 16:59:00')['sent'])->toBe(0)
            ->and(urPushes($pet))->toHaveCount(0);

        expect(urTick(UR_DUE)['sent'])->toBe(1);
        $push = urPushes($pet)->sole();
        expect($push->status)->toBe(PushNotification::STATUS_QUEUED)
            ->and(collect($push->recipients)->pluck('user_id')->all())->toBe([$parent->id])
            ->and(collect($push->recipients)->pluck('audience')->unique()->all())->toBe([PushNotification::AUDIENCE_PARENT]);

        $pet = $pet->fresh();
        expect($pet->upgrade_reminder_sent_at?->toDateTimeString())->toBe(UR_DUE)
            ->and($pet->upgrade_reminder_child_id)->toBe($child->id);

        expect(urCard($parent, $pet))->toBe(['sent_at' => '2026-11-09T18:00:00+01:00', 'child_id' => $child->id]);
    });

    it('does not send with only 4 of the 7 days — and never later', function () {
        [$parent, $child, $pet] = urFamily();
        urActiveDays($pet, $child, 4);

        expect(urTick(UR_DUE)['sent'])->toBe(0);
        // A 5th day after the 7 days does not count; the pet is decided.
        urActs($pet, $child, '2026-11-09 18:00:00');
        expect(urTick('2026-11-09 19:00:00')['pets'])->toBe(0)
            ->and(urPushes($pet))->toHaveCount(0)
            ->and(urCard($parent, $pet))->toBeNull();
    });

    it('counts pet-local dates, not actions (many actions on one day = one day)', function () {
        [, $child, $pet] = urFamily();
        urActs($pet, $child, '2026-11-02 18:00:00', '2026-11-02 19:00:00', '2026-11-02 20:00:00', '2026-11-03 08:00:00', '2026-11-04 08:00:00', '2026-11-05 08:00:00');

        expect(app(UpgradeReminderService::class)->activeDaysByChild($pet))->toBe([$child->id => 4]);
    });

    it('counts on the pet clock: 23:30 UTC is already the next Ljubljana day', function () {
        [, $child, $pet] = urFamily();
        // 11-03 23:30 UTC = 11-04 00:30 local; 11-08 23:30 UTC = 11-09 00:30 local (the 8th date — outside).
        urActs($pet, $child, '2026-11-02 18:00:00', '2026-11-03 23:30:00', '2026-11-08 23:30:00');

        expect(app(UpgradeReminderService::class)->activeDaysByChild($pet))->toBe([$child->id => 2]);
    });

    it('is per child — two siblings with 3 days each do not add up', function () {
        [$parent, $child, $pet] = urFamily();
        $sibling = User::factory()->child()->create(['parent_id' => $parent->id]);
        urActs($pet, $child, '2026-11-02 18:00:00', '2026-11-03 08:00:00', '2026-11-04 08:00:00');
        urActs($pet, $sibling, '2026-11-05 08:00:00', '2026-11-06 08:00:00', '2026-11-07 08:00:00');

        expect(urTick(UR_DUE)['sent'])->toBe(0);
    });

    it('does not count the contract, parent rows or system rows', function () {
        [$parent, $child, $pet] = urFamily();
        urActiveDays($pet, $child, 4);
        foreach (['2026-11-07 08:00:00', '2026-11-08 08:00:00'] as $at) {
            DB::table('activities_log')->insert(['pet_id' => $pet->id, 'actor_user_id' => $child->id, 'activity_type' => ActivityType::SignedContract->value, 'created_at' => $at]);
            DB::table('activities_log')->insert(['pet_id' => $pet->id, 'actor_user_id' => $parent->id, 'activity_type' => ActivityType::ParentLoggedWalk->value, 'created_at' => $at]);
            DB::table('activities_log')->insert(['pet_id' => $pet->id, 'actor_user_id' => null, 'activity_type' => ActivityType::IgnoredWarning->value, 'created_at' => $at]);
        }

        expect(urTick(UR_DUE)['sent'])->toBe(0);
    });
});

describe('once, and only for an upgradeable free pet', function () {
    it('sends once — later ticks and a second remind() change nothing', function () {
        [, $child, $pet] = urFamily();
        urActiveDays($pet, $child, 7);

        urTick(UR_DUE);
        urTick('2026-11-09 17:05:00');
        urTick('2026-11-10 17:00:00');
        expect(app(UpgradeReminderService::class)->remind($pet->id, $child->id))->toBeFalse()
            ->and(urPushes($pet))->toHaveCount(1);
    });

    it('never for a challenge pet (paid breed, or a mutt bought at the start)', function () {
        [, $child, $pet] = urFamily(['plan' => PetPlan::Challenge, 'challenge_paid_at' => Carbon::parse(UR_BORN, 'UTC'), 'challenge_paid_source' => 'purchase'], 'border_collie');
        urActiveDays($pet, $child, 7);

        expect(urTick(UR_DUE)['pets'])->toBe(0)
            ->and(urPushes($pet))->toHaveCount(0);
    });

    it('not after the purchase (upgraded meanwhile)', function () {
        [, $child, $pet] = urFamily();
        urActiveDays($pet, $child, 7);
        DB::table('pets')->where('id', $pet->id)->update(['plan' => PetPlan::Challenge->value, 'challenge_paid_at' => '2026-11-08 10:00:00', 'challenge_paid_source' => 'purchase', 'challenge_started_at' => '2026-11-08 10:00:00']);

        expect(urTick(UR_DUE)['sent'])->toBe(0)
            ->and(urPushes($pet))->toHaveCount(0);
    });

    it('not after game over, not for an inactive pet, not for a pre-M5 legacy mutt', function () {
        foreach ([['is_game_over' => true], ['is_active' => false], ['arrival_age_months' => null]] as $attributes) {
            [, $child, $pet] = urFamily($attributes);
            urActiveDays($pet, $child, 7);

            expect(urTick(UR_DUE)['sent'])->toBe(0)
                ->and(urPushes($pet))->toHaveCount(0);
        }
    });

    it('not for a pet whose window ended before this feature (existing free pets)', function () {
        [, $child, $pet] = urFamily();
        urActiveDays($pet, $child, 7);

        expect(urTick('2026-11-10 17:00:00')['sent'])->toBe(0)
            ->and(urPushes($pet))->toHaveCount(0)
            ->and($pet->fresh()->upgrade_reminder_next_check_at->year)->toBe(2999);
    });

    it('not while the pet is locked; sent when the lock ends inside the window', function () {
        [, $child, $pet] = urFamily();
        urActiveDays($pet, $child, 7);
        DB::table('pets')->where('id', $pet->id)->update(['is_hard_stopped' => true]);

        expect(urTick(UR_DUE)['sent'])->toBe(0)
            ->and($pet->fresh()->upgrade_reminder_next_check_at->toDateTimeString())->toBe('2026-11-09 17:05:00');

        DB::table('pets')->where('id', $pet->id)->update(['is_hard_stopped' => false]);
        expect(urTick('2026-11-09 20:00:00')['sent'])->toBe(1);
    });

    it('no reminder when the whole window passes locked (ill)', function () {
        [, $child, $pet] = urFamily();
        urActiveDays($pet, $child, 7);
        DB::table('pets')->where('id', $pet->id)->update(['illness_until' => '2026-11-11 00:00:00']);

        urTick(UR_DUE);
        urTick('2026-11-10 12:00:00');
        DB::table('pets')->where('id', $pet->id)->update(['illness_until' => null]);
        urTick('2026-11-10 17:00:00');

        expect(urPushes($pet))->toHaveCount(0);
    });
});

describe('push and card', function () {
    it('holds the push over quiet hours (never sent at night)', function () {
        [, $child, $pet] = urFamily([], 'mutt', quiet: true); // 18:00 local is inside 17:30–07:00
        urActiveDays($pet, $child, 5);

        urTick(UR_DUE);
        $push = urPushes($pet)->sole();
        expect($push->status)->toBe(PushNotification::STATUS_SCHEDULED)
            ->and($push->send_after?->toDateTimeString())->toBe('2026-11-10 06:00:00'); // 07:00 local
    });

    it('is suppressed at delivery when the pet was upgraded meanwhile', function () {
        [$parent, $child, $pet] = urFamily();
        urActiveDays($pet, $child, 5);
        urTick(UR_DUE);
        $push = urPushes($pet)->sole();
        DevicePushToken::create(['user_id' => $parent->id, 'expo_push_token' => 'ExponentPushToken['.Str::random(22).']', 'platform' => 'ios', 'app_version' => '1.0.0', 'locale' => 'sl', 'last_seen_at' => now()]);

        DB::table('pets')->where('id', $pet->id)->update(['plan' => PetPlan::Challenge->value, 'challenge_paid_at' => now(), 'challenge_paid_source' => 'purchase', 'challenge_started_at' => now()]);
        app(NotificationService::class)->deliver($push->id, app(ExpoPushClient::class));

        expect($push->fresh()->status)->toBe(PushNotification::STATUS_SUPPRESSED)
            ->and($push->fresh()->suppressed_reason)->toBe('not_upgradeable');
    });

    it('card: gone after the purchase and after 7 days', function () {
        [$parent, $child, $pet] = urFamily();
        urActiveDays($pet, $child, 5);
        urTick(UR_DUE);

        urAt('2026-11-16 16:59:00');
        expect(urCard($parent, $pet))->not->toBeNull();
        urAt('2026-11-16 17:00:00');
        expect(urCard($parent, $pet))->toBeNull();

        urAt('2026-11-10 10:00:00');
        DB::table('pets')->where('id', $pet->id)->update(['plan' => PetPlan::Challenge->value, 'challenge_paid_at' => now(), 'challenge_paid_source' => 'purchase', 'challenge_started_at' => now()]);
        expect(urCard($parent, $pet->fresh()))->toBeNull();
    });

    it('card: child_id null once that child profile is deleted', function () {
        [$parent, $child, $pet] = urFamily();
        urActiveDays($pet, $child, 5);
        urTick(UR_DUE);
        $sibling = User::factory()->child()->create(['parent_id' => $parent->id]); // the pet keeps a caretaker
        DB::table('pets')->where('id', $pet->id)->update(['user_id' => $sibling->id]);
        DB::table('pet_caretakers')->where('user_id', $child->id)->delete();
        DB::table('activities_log')->where('actor_user_id', $child->id)->update(['actor_user_id' => null]);
        DB::table('family_user')->where('user_id', $child->id)->delete();
        DB::table('users')->where('id', $child->id)->delete();

        expect(urCard($parent, $pet->fresh())['child_id'])->toBeNull();
    });

    it('copy: dog / cat, SL / EN, title = the pet name, no child name, no price', function () {
        $sl = PushCopy::body(PushType::UpgradeReminder, null, PushNotification::AUDIENCE_PARENT, 'sl');
        $en = PushCopy::body(PushType::UpgradeReminder, null, PushNotification::AUDIENCE_PARENT, 'en');
        $slCat = PushCopy::body(PushType::UpgradeReminder, null, PushNotification::AUDIENCE_PARENT, 'sl', null, [], Species::Cat);
        $enCat = PushCopy::body(PushType::UpgradeReminder, null, PushNotification::AUDIENCE_PARENT, 'en', null, [], Species::Cat);

        expect($sl)->toBe('Vaš otrok že 7 dni skrbi za kužka. Je čas za pravi preizkus? V aplikaciji lahko z istim kužkom začnete 12-tedenski izziv.')
            ->and($slCat)->toBe('Vaš otrok že 7 dni skrbi za muco. Je čas za pravi preizkus? V aplikaciji lahko z isto muco začnete 12-tedenski izziv.')
            ->and($en)->toContain('the dog')->and($enCat)->toContain('the cat')
            ->and(PushCopy::title('sl', 'Pika'))->toBe('Pika');
        foreach ([$sl, $en, $slCat, $enCat] as $text) {
            expect($text)->not->toContain('€')->not->toContain('Maja')->not->toContain('49');
        }
    });

    it('is a normal-priority push held over quiet hours, parents only', function () {
        expect(PushType::UpgradeReminder->isUrgent())->toBeFalse()
            ->and(PushType::UpgradeReminder->holdsOverQuietHours())->toBeTrue()
            ->and(PushType::UpgradeReminder->value)->toBe(UpgradeReminderService::TYPE);
    });

    it('runs in the game-loop tick', function () {
        [, $child, $pet] = urFamily();
        urActiveDays($pet, $child, 5);
        urAt(UR_DUE);

        test()->artisan('pets:process-decay')->assertSuccessful();
        expect(urPushes($pet))->toHaveCount(1);
    });

    it('goes to the parents whose app shows the card (free_breed_challenge); every parent when none (QA #983)', function () {
        [$parent, $child, $pet] = urFamily();
        $other = User::factory()->parent()->create();
        DB::table('family_user')->where('user_id', $other->id)->delete();
        app(FamilyService::class)->addMember(app(FamilyService::class)->familyOf($parent), $other, FamilyRole::Parent);
        DB::table('users')->where('id', $other->id)->update(['client_features' => json_encode(['care_tasks', 'free_breed_challenge'])]);
        urActiveDays($pet, $child, 5);

        urTick(UR_DUE);
        expect(collect(urPushes($pet)->sole()->recipients)->pluck('user_id')->all())->toBe([$other->id]);
    });
});

describe('migration', function () {
    it('down() removes the columns, the index and the push type; up() restores them', function () {
        $migration = require database_path('migrations/2027_03_22_120000_add_upgrade_reminder.php');
        $index = fn () => DB::selectOne("SELECT 1 AS ok FROM pg_indexes WHERE indexname = 'pets_upgrade_reminder_due_idx'");
        expect($index())->not->toBeNull();

        $migration->down();
        expect(Schema::hasColumns('pets', ['upgrade_reminder_sent_at', 'free_breed_challenge']))->toBeFalse()
            ->and($index())->toBeNull();

        $migration->up();
        expect(Schema::hasColumns('pets', ['upgrade_reminder_sent_at', 'upgrade_reminder_child_id', 'upgrade_reminder_next_check_at', 'free_breed_challenge']))->toBeTrue()
            ->and($index())->not->toBeNull();
    });
});
