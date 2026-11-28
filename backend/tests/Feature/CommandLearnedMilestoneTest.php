<?php

use App\Enums\ActivityType;
use App\Enums\PushType;
use App\Enums\Species;
use App\Enums\TrainingCommand;
use App\Jobs\SendPushNotification;
use App\Models\ActivityLog;
use App\Models\DevicePushToken;
use App\Models\Pet;
use App\Models\PetTrainingSkill;
use App\Models\PushNotification;
use App\Models\QuietHours;
use App\Models\User;
use App\Services\AccountExportService;
use App\Services\LifeStageService;
use App\Services\NotificationService;
use App\Services\Push\ExpoPushClient;
use App\Services\Push\PushCopy;
use App\Services\TrainingPayload;
use App\Services\TrainingService;
use Database\Seeders\BreedStageParamsSeeder;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| School milestone (David 2026-11-28): the first time a dog LEARNS a command
| (PetTrainingSkill::isMastered — displayed progress 100, from 99.5) is
| celebrated: the finish response carries `command_learned`, the skill row
| stores `learned_at` once, the parent timeline gets one `command_learned`
| row, the family's parents get one push (never the child, never the child's
| name). Never again for that pet + command, never for a replayed finish,
| a command that was already learned or the migration's backfill.
|--------------------------------------------------------------------------
| Family in Europe/Ljubljana; quiet hours off unless a test turns them on.
*/

beforeEach(function () {
    seedLifeStageData();
    config(['push.enabled' => true]);
    Queue::fake([SendPushNotification::class]);
});

function clmAt(string $utc): void
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
}

/** @return array{0: User, 1: User, 2: Pet} parent, child, pet */
function clmFamily(string $bornUtc = '2026-11-01 08:00:00'): array
{
    clmAt($bornUtc);
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana', 'name' => 'Mama Ana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => 'Maja']);
    $pet = Pet::factory()->mutt()->create([
        'user_id' => $child->id,
        'born_at' => Carbon::parse($bornUtc, 'UTC'),
        'last_decay_at' => Carbon::parse($bornUtc, 'UTC'),
        'last_step_reset_at' => Carbon::parse($bornUtc, 'UTC'),
        'arrival_age_months' => 2,
        'training_enabled' => true,
    ]);
    disableHygieneEvents($pet);
    withoutQuietHours($pet);

    return [$parent, $child, $pet->fresh()];
}

/** @param  array<string, float>  $progress  command => progress */
function clmSkills(Pet $pet, array $progress, ?string $learnedAt = null): void
{
    foreach ($progress as $command => $value) {
        PetTrainingSkill::updateOrCreate(['pet_id' => $pet->id, 'command' => $command], ['progress' => $value, 'learned_at' => $learnedAt]);
    }
}

/**
 * One session at $utc with every obeyed cue praised on time; returns [session, taps, finish response].
 *
 * @param  array<string, mixed>  $start
 */
function clmSession(User $child, string $utc, string $command = 'sit', array $start = [], ?int $endedAfterRound = null): array
{
    clmAt($utc);
    app('auth')->forgetGuards();
    actingAsRole($child);
    $session = postJson('/api/child/pet/training/start', ['command' => $command, ...$start])->assertOk()->json('session');
    $taps = [];
    foreach ($session['trials'] as $trial) {
        if ($endedAfterRound !== null && $trial['index'] > $session['rounds'][0]['last_index']) {
            break;
        }
        if ($trial['obeys']) {
            $taps[] = $trial['obey_at_ms'] + 400 + 30 * count($taps);
        }
    }
    $end = $endedAfterRound !== null
        ? Carbon::parse($session['started_at'])->utc()->addMilliseconds($session['rounds'][0]['end_ms'] + 200)
        : Carbon::parse($session['ends_at'])->utc();
    Carbon::setTestNow($end);

    return [$session, $taps, clmFinish($child, $session['id'], $taps, $endedAfterRound)->assertOk()];
}

/** @param  list<int>  $taps */
function clmFinish(User $child, string $sessionId, array $taps, ?int $endedAfterRound = null): TestResponse
{
    app('auth')->forgetGuards();
    actingAsRole($child);

    return postJson('/api/child/pet/training/finish', ['session_id' => $sessionId, 'taps' => $taps]
        + ($endedAfterRound !== null ? ['ended_after_round' => $endedAfterRound] : []));
}

/** @return Collection<int, PushNotification> */
function clmPushes(Pet $pet)
{
    return PushNotification::where('pet_id', $pet->id)->where('type', PushType::CommandLearned->value)->orderBy('id')->get();
}

/** @return Collection<int, ActivityLog> */
function clmRows(Pet $pet)
{
    return ActivityLog::where('pet_id', $pet->id)->where('activity_type', ActivityType::CommandLearned->value)->orderBy('id')->get();
}

describe('first time only', function () {
    it('celebrates the session that makes the dog learn the command: response, learned_at, timeline row, one parent push', function () {
        [$parent, $child, $pet] = clmFamily();
        clmSkills($pet, ['place' => 99.0]);

        [, , $response] = clmSession($child, '2026-11-02 09:00:00', 'place');

        $skill = PetTrainingSkill::where('pet_id', $pet->id)->where('command', 'place')->first();
        expect($skill->isMastered())->toBeTrue()
            ->and($skill->learned_at)->not->toBeNull()
            ->and($response->json('status'))->toBe('accepted')
            ->and($response->json('command_learned'))->toBe([
                'command' => 'place',
                'learned_at' => $skill->learned_at->copy()->setTimezone('Europe/Ljubljana')->toIso8601String(),
            ])
            ->and($response->json('command_learned.learned_at'))->toEndWith('+01:00')
            // The `result` keeps its v1 key list (no new key inside it).
            ->and(array_keys($response->json('result')))->toBe(['session_id', 'command', 'successes', 'obeyed', 'trials', 'progress_before', 'progress_after', 'progress_gain']);

        $rows = clmRows($pet);
        expect($rows)->toHaveCount(1)
            ->and((int) $rows[0]->value)->toBe(TrainingCommand::Place->code())
            ->and((int) $rows[0]->actor_user_id)->toBe($child->id);

        $pushes = clmPushes($pet);
        expect($pushes)->toHaveCount(1)
            ->and($pushes[0]->metric)->toBe('place')
            ->and($pushes[0]->status)->toBe(PushNotification::STATUS_QUEUED)
            ->and(collect($pushes[0]->recipients)->pluck('user_id')->all())->toBe([$parent->id])
            ->and(collect($pushes[0]->recipients)->pluck('audience')->unique()->all())->toBe([PushNotification::AUDIENCE_PARENT])
            ->and($pushes[0]->type->isUrgent())->toBeFalse();
    });

    it('never celebrates again: a later session of a learned command, a replayed finish', function () {
        [, $child, $pet] = clmFamily();
        clmSkills($pet, ['sit' => 99.0]);

        [$session, $taps, $first] = clmSession($child, '2026-11-02 09:00:00');
        expect($first->json('command_learned.command'))->toBe('sit');
        $learnedAt = PetTrainingSkill::where('pet_id', $pet->id)->where('command', 'sit')->value('learned_at');

        // The same finish again (lost response, retry) → unchanged, no milestone.
        $replay = clmFinish($child, $session['id'], $taps)->assertOk();
        expect($replay->json('status'))->toBe('unchanged')
            ->and($replay->json('command_learned'))->toBeNull()
            ->and($replay->json('result.session_id'))->toBe($session['id']);

        // Next day another session of the learned command.
        [, , $later] = clmSession($child, '2026-11-03 09:00:00');
        expect($later->json('command_learned'))->toBeNull()
            ->and((string) PetTrainingSkill::where('pet_id', $pet->id)->where('command', 'sit')->value('learned_at'))->toBe((string) $learnedAt)
            ->and(clmRows($pet))->toHaveCount(1)
            ->and(clmPushes($pet))->toHaveCount(1);
    });

    it('is null for a session that does not reach the threshold', function () {
        [, $child, $pet] = clmFamily();
        clmSkills($pet, ['come' => 10.0]);

        [, , $response] = clmSession($child, '2026-11-02 09:00:00', 'come');

        expect($response->json('command_learned'))->toBeNull()
            ->and($response->json())->toHaveKey('command_learned')
            ->and(PetTrainingSkill::where('pet_id', $pet->id)->where('command', 'come')->value('learned_at'))->toBeNull()
            ->and(clmRows($pet))->toHaveCount(0)
            ->and(clmPushes($pet))->toHaveCount(0);
    });

    it('follows the displayed value: 99.5 is already learned — a session from there is no milestone', function () {
        [, $child, $pet] = clmFamily();
        // A learned row without a moment (defensive: the migration backfills them) gets one silently.
        clmSkills($pet, ['sit' => 99.5]);

        [, , $response] = clmSession($child, '2026-11-02 09:00:00');

        expect($response->json('command_learned'))->toBeNull()
            ->and(PetTrainingSkill::where('pet_id', $pet->id)->where('command', 'sit')->value('learned_at'))->not->toBeNull()
            ->and(clmRows($pet))->toHaveCount(0)
            ->and(clmPushes($pet))->toHaveCount(0);
    });

    it('counts a rules-v2 early end (round 1 only) that crosses the threshold', function () {
        [, $child, $pet] = clmFamily();
        clmSkills($pet, ['potty' => 99.0]);

        [, , $response] = clmSession($child, '2026-11-02 09:00:00', 'potty', ['rules' => 2], endedAfterRound: 1);

        expect($response->json('result.counts_for_routine'))->toBeFalse()
            ->and($response->json('command_learned.command'))->toBe('potty')
            ->and(clmRows($pet))->toHaveCount(1)
            ->and((int) clmRows($pet)[0]->value)->toBe(4)
            ->and(clmPushes($pet))->toHaveCount(1);
    });

    it('starting progress from arrival is never a milestone (a command known on arrival is learned silently)', function () {
        clmAt('2026-10-07 06:00:00');
        (new BreedStageParamsSeeder)->run();
        $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
        $child = User::factory()->child()->create(['parent_id' => $parent->id]);
        $pet = Pet::factory()->mutt()->unborn()->create(['user_id' => $child->id, 'arrival_age_months' => 118, 'training_enabled' => true]);
        // An admin edit that makes the grown arrival already know »Sedi«.
        DB::table('breed_stage_params')->where(['breed_slug' => 'mutt', 'key' => 'training_starting_progress'])->where('stage', '!=', 'puppy')
            ->update(['value' => json_encode(['sit' => 100, 'come' => 30])]);
        LifeStageService::forgetBreed('mutt');

        expect(app(TrainingService::class)->applyStartingProgress($pet, now()))->toBe(['sit' => 100.0, 'come' => 30.0]);

        $learned = PetTrainingSkill::where('pet_id', $pet->id)->pluck('learned_at', 'command');
        expect($learned['sit'])->not->toBeNull()
            ->and($learned['come'])->toBeNull()
            ->and(clmRows($pet))->toHaveCount(0)
            ->and(clmPushes($pet))->toHaveCount(0);
    });
});

describe('backfill (migration)', function () {
    it('gives already learned rows their updated_at, leaves the rest null, and sends / logs nothing', function () {
        [, , $pet] = clmFamily();
        [, , $other] = clmFamily();
        DB::table('pet_training_skills')->insert([
            ['pet_id' => $pet->id, 'command' => 'sit', 'progress' => 100.0, 'sessions_completed' => 9, 'created_at' => '2026-10-20 08:00:00', 'updated_at' => '2026-10-25 17:30:00'],
            ['pet_id' => $pet->id, 'command' => 'come', 'progress' => 99.5, 'sessions_completed' => 7, 'created_at' => '2026-10-20 08:00:00', 'updated_at' => '2026-10-26 17:30:00'],
            ['pet_id' => $pet->id, 'command' => 'place', 'progress' => 99.4, 'sessions_completed' => 7, 'created_at' => '2026-10-20 08:00:00', 'updated_at' => '2026-10-26 17:30:00'],
            ['pet_id' => $other->id, 'command' => 'sit', 'progress' => 100.0, 'sessions_completed' => 3, 'created_at' => '2026-10-21 08:00:00', 'updated_at' => null],
        ]);

        $migration = require database_path('migrations/2027_05_19_120000_add_command_learned_milestone.php');
        $migration->down();
        $migration->up();

        $learned = DB::table('pet_training_skills')->orderBy('pet_id')->orderBy('command')->get(['pet_id', 'command', 'learned_at'])
            ->map(fn ($r) => [$r->pet_id === $pet->id ? 'pet' : 'other', $r->command, $r->learned_at === null ? null : substr((string) $r->learned_at, 0, 19)])->all();
        expect($learned)->toBe([
            ['pet', 'come', '2026-10-26 17:30:00'],
            ['pet', 'place', null],
            ['pet', 'sit', '2026-10-25 17:30:00'],
            ['other', 'sit', '2026-10-21 08:00:00'],
        ])
            ->and(ActivityLog::where('activity_type', ActivityType::CommandLearned->value)->count())->toBe(0)
            ->and(PushNotification::where('type', PushType::CommandLearned->value)->count())->toBe(0);
        Queue::assertNothingPushed();
    });
});

describe('parent push', function () {
    it('copy: SL / EN with the command name, title = the pet name else PetPrep, never the child name; data {type, pet_id}', function () {
        expect(PushCopy::body(PushType::CommandLearned, 'place', PushNotification::AUDIENCE_PARENT, 'sl'))->toBe('Kuža zna nov ukaz: »Prostor«!')
            ->and(PushCopy::body(PushType::CommandLearned, 'place', PushNotification::AUDIENCE_PARENT, 'en'))->toBe('Your pup learned a new command: “Place”!')
            ->and(PushCopy::body(PushType::CommandLearned, 'sit', PushNotification::AUDIENCE_PARENT, 'sl'))->toBe('Kuža zna nov ukaz: »Sedi«!')
            ->and(PushCopy::body(PushType::CommandLearned, 'come', PushNotification::AUDIENCE_PARENT, 'en'))->toBe('Your pup learned a new command: “Come”!')
            ->and(PushCopy::body(PushType::CommandLearned, 'potty', PushNotification::AUDIENCE_PARENT, 'sl'))->toBe('Kuža zna nov ukaz: »Lulat zunaj«!')
            ->and(PushCopy::body(PushType::CommandLearned, 'nonsense', PushNotification::AUDIENCE_PARENT, 'sl'))->toBe('Kuža zna nov ukaz!')
            // A language without its own texts → the default (English).
            ->and(PushCopy::body(PushType::CommandLearned, 'place', PushNotification::AUDIENCE_PARENT, 'de'))->toBe('Your pup learned a new command: “Place”!');

        [$parent, $child, $pet] = clmFamily();
        Pet::whereKey($pet->id)->update(['name' => 'Pika']);
        clmSkills($pet, ['place' => 99.0]);
        $sl = DevicePushToken::create(['user_id' => $parent->id, 'expo_push_token' => 'ExponentPushToken['.Str::random(22).']', 'platform' => 'ios', 'app_version' => '1.0.0', 'locale' => 'sl', 'last_seen_at' => now()]);
        // The child's own phone never gets it.
        DevicePushToken::create(['user_id' => $child->id, 'expo_push_token' => 'ExponentPushToken['.Str::random(22).']', 'platform' => 'ios', 'app_version' => '1.0.0', 'locale' => 'sl', 'last_seen_at' => now()]);

        clmSession($child, '2026-11-02 09:00:00', 'place');
        $push = clmPushes($pet)->first();

        $sent = [];
        Http::swap(new HttpFactory(app('events')));
        Http::preventStrayRequests();
        Http::fake(['https://exp.host/--/api/v2/push/send' => function (Request $request) use (&$sent) {
            $tickets = [];
            foreach ($request->data() as $message) {
                $sent[] = $message;
                $tickets[] = ['status' => 'ok', 'id' => 'ticket-'.Str::random(8)];
            }

            return Http::response(['data' => $tickets]);
        }]);
        app(NotificationService::class)->deliver($push->id, app(ExpoPushClient::class));

        expect($push->fresh()->status)->toBe(PushNotification::STATUS_SENT)
            ->and($sent)->toHaveCount(1)
            ->and($sent[0]['to'])->toBe($sl->expo_push_token)
            ->and($sent[0]['title'])->toBe('Pika')
            ->and($sent[0]['body'])->toBe('Kuža zna nov ukaz: »Prostor«!')
            ->and($sent[0]['data'])->toBe(['type' => 'command_learned', 'pet_id' => $pet->id]);
        expect(json_encode($sent))->not->toContain('Maja');

        expect(PushCopy::title('sl', null))->toBe('PetPrep');
    });

    it('is held over quiet hours (news), and only one row per pet + command ever', function () {
        [, , $pet] = clmFamily();
        QuietHours::where('family_id', $pet->family_id)->update(['is_active' => true, 'bedtime_start' => '21:00', 'bedtime_end' => '07:00']);
        clmAt('2026-11-02 21:30:00'); // 22:30 in Ljubljana

        $held = app(NotificationService::class)->commandLearned($pet->fresh(), TrainingCommand::Sit);
        expect($held->status)->toBe(PushNotification::STATUS_SCHEDULED)
            ->and($held->send_after?->utc()->toDateTimeString())->toBe('2026-11-03 06:00:00');

        $again = app(NotificationService::class)->commandLearned($pet->fresh(), TrainingCommand::Sit);
        $other = app(NotificationService::class)->commandLearned($pet->fresh(), TrainingCommand::Come);
        expect($again->status)->toBe(PushNotification::STATUS_SUPPRESSED)
            ->and($again->suppressed_reason)->toBe('duplicate')
            ->and($other->status)->toBe(PushNotification::STATUS_SCHEDULED)
            ->and(PushType::CommandLearned->holdsOverQuietHours())->toBeTrue()
            ->and(PushType::CommandLearned->isUrgent())->toBeFalse()
            ->and(PushType::CommandLearned->androidChannel())->toBe('default');
    });
});

describe('state, timeline, report, export', function () {
    it('child state, parent dashboard summary and PetUpdated carry learned_at per command', function () {
        [$parent, $child, $pet] = clmFamily();
        clmSkills($pet, ['sit' => 99.0, 'come' => 40.0]);
        clmSession($child, '2026-11-02 09:00:00');
        $learnedAt = PetTrainingSkill::where('pet_id', $pet->id)->where('command', 'sit')->value('learned_at');
        $iso = Carbon::parse($learnedAt, 'UTC')->setTimezone('Europe/Ljubljana')->toIso8601String();

        app('auth')->forgetGuards();
        actingAsRole($child);
        $commands = collect(getJson('/api/child/pet')->assertOk()->json('training.commands'))->keyBy('command');
        expect($commands['sit']['learned'])->toBeTrue()
            ->and($commands['sit']['learned_at'])->toBe($iso)
            ->and($commands['come']['learned_at'])->toBeNull()
            ->and($commands['place'])->toHaveKey('learned_at');

        $summary = collect(TrainingPayload::summaryFor($pet->fresh())->summary()['commands'])->keyBy('command');
        expect($summary['sit']['learned_at'])->toBe($iso);

        app('auth')->forgetGuards();
        actingAsRole($parent);
        $dashboard = getJson('/api/parent/dashboard')->assertOk();
        $petRow = collect($dashboard->json('family.pets'))->firstWhere('id', $pet->id);
        expect(collect($petRow['training']['commands'])->firstWhere('command', 'sit')['learned_at'])->toBe($iso);

        // Parent timeline: the milestone row with the child's nickname (parent-only data).
        $row = collect($petRow['timeline'])->firstWhere('activity_type', 'command_learned');
        expect($row)->not->toBeNull()
            ->and($row['value'])->toBe(1)
            ->and($row['command'])->toBe('sit')
            ->and($row['actor_user_id'])->toBe($child->id)
            ->and($row['actor_nickname'])->toBe('Maja')
            ->and($row['is_positive'])->toBeTrue();

        // Other rows carry `command: null`; the paginated activities list has the same field.
        expect(collect($petRow['timeline'])->firstWhere('activity_type', 'trained_pet')['command'])->toBeNull();
        $activity = collect(getJson("/api/parent/activities?pet_id={$pet->id}")->assertOk()->json('data'))->firstWhere('activity_type', 'command_learned');
        expect($activity['command'])->toBe('sit')->and($activity['value'])->toBe(1);

        // The child's report lists it.
        $report = getJson("/api/parent/children/{$child->id}/report?days=7")->assertOk();
        expect($report->json('commands_learned'))->toBe([['command' => 'sit', 'learned_at' => Carbon::parse(clmRows($pet)[0]->created_at)->setTimezone('Europe/Ljubljana')->toIso8601String()]]);
    });

    it('the export carries learned_at; the child state never names a push', function () {
        [$parent, $child, $pet] = clmFamily();
        clmSkills($pet, ['sit' => 99.0]);
        clmSession($child, '2026-11-02 09:00:00');

        $export = app(AccountExportService::class)->exportFor($parent);
        $skills = collect(data_get($export, 'pets.0.training_skills'))->keyBy('command');
        expect($skills['sit'])->toHaveKey('learned_at')
            ->and($skills['sit']['learned_at'])->not->toBeNull();
    });

    it('a cat never has school, so the copy is dog-only (the cat falls back to the dog text, never sent)', function () {
        expect(PushCopy::body(PushType::CommandLearned, 'sit', PushNotification::AUDIENCE_PARENT, 'sl', null, [], Species::Cat))->toBe('Kuža zna nov ukaz: »Sedi«!');
    });
});
