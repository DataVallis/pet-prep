<?php

use App\Enums\PushType;
use App\Events\PetUpdated;
use App\Jobs\SendPushNotification;
use App\Models\DevicePushToken;
use App\Models\Pet;
use App\Models\PetDailyStep;
use App\Models\PushNotification;
use App\Models\User;
use App\Services\AccountExportService;
use App\Services\FamilyService;
use App\Services\NotificationService;
use App\Services\Push\ExpoPushClient;
use App\Services\Push\PushCopy;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| M5-T12 — adapted mode: wheelchair pushes instead of steps
|--------------------------------------------------------------------------
|
| APP_REFINEMENT_SPEC §6 (David 2026-10-20). A parent switches »Prilagojen
| način« on a child profile; the child's phone then reads wheelchair pushes and
| sends them as `steps_today` with `unit: pushes` (1 push = 1 step). Goals,
| energy, anti-cheat, idempotency, routines and the Care Score are the same
| numbers; only the wording changes (child app, walk reminder, parent report).
| Only the boolean is stored — never a reason.
*/

beforeEach(function () {
    seedBreedConfigs();
});

afterEach(function () {
    Carbon::setTestNow();
});

/** @return array{0: User, 1: User, 2: Pet} */
function amFamily(array $pet = []): array
{
    Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00', 'UTC')); // 12:00 Ljubljana
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    withoutQuietHours($parent);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    $p = disableHygieneEvents(Pet::factory()->create(array_merge([
        'user_id' => $child->id,
        'breed_type' => 'mutt',
        'energy_level' => 0,
        'daily_step_count' => 0,
        'last_step_reset_at' => now()->copy()->subDay(),
    ], $pet)));

    return [$parent, $child, $p];
}

function amSync(User $child, int $steps, ?string $unit, string $at = '2026-10-14T12:00:00+02:00')
{
    test()->actingAs($child, 'sanctum');
    $body = ['steps_today' => $steps, 'source' => 'healthkit', 'recorded_at' => $at];
    if ($unit !== null) {
        $body['unit'] = $unit;
    }

    return test()->postJson('/api/child/pet/steps', $body);
}

describe('PATCH /api/parent/children/{child}/settings', function () {
    it('lets a parent of the family switch adapted mode on and off, stores only the boolean and broadcasts once per change', function () {
        [$parent, $child, $pet] = amFamily();
        Event::fake([PetUpdated::class]);
        test()->actingAs($parent, 'sanctum');

        test()->patchJson("/api/parent/children/{$child->id}/settings", ['adapted_mode' => true])
            ->assertOk()
            ->assertExactJson(['child_id' => $child->id, 'adapted_mode' => true]);
        expect($child->refresh()->adapted_mode)->toBeTrue();
        Event::assertDispatchedTimes(PetUpdated::class, 1);
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->petId === $pet->id && $e->eventType === 'adapted_mode_changed');

        // The same value again: nothing changes, no broadcast.
        test()->patchJson("/api/parent/children/{$child->id}/settings", ['adapted_mode' => true])->assertOk();
        Event::assertDispatchedTimes(PetUpdated::class, 1);

        test()->patchJson("/api/parent/children/{$child->id}/settings", ['adapted_mode' => false])
            ->assertOk()->assertJsonPath('adapted_mode', false);
        expect($child->refresh()->adapted_mode)->toBeFalse();
        Event::assertDispatchedTimes(PetUpdated::class, 2);
    });

    it('never stores a reason: unknown fields are ignored and the users table has no such column', function () {
        [$parent, $child] = amFamily();
        test()->actingAs($parent, 'sanctum');

        test()->patchJson("/api/parent/children/{$child->id}/settings", [
            'adapted_mode' => true,
            'reason' => 'wheelchair user',
            'condition' => 'x',
        ])->assertOk()->assertExactJson(['child_id' => $child->id, 'adapted_mode' => true]);

        $columns = Schema::getColumnListing('users');
        expect($columns)->toContain('adapted_mode')
            ->not->toContain('adapted_reason')
            ->not->toContain('reason')
            ->not->toContain('condition');
        expect(json_encode($child->refresh()->getAttributes()))->not->toContain('wheelchair user');
    });

    it('validates the body (boolean required)', function () {
        [$parent, $child] = amFamily();
        test()->actingAs($parent, 'sanctum');

        test()->patchJson("/api/parent/children/{$child->id}/settings", [])->assertStatus(422)->assertJsonValidationErrors('adapted_mode');
        test()->patchJson("/api/parent/children/{$child->id}/settings", ['adapted_mode' => 'maybe'])->assertStatus(422);
        expect($child->refresh()->adapted_mode)->toBeFalse();
    });

    it('is parent-only and family-scoped: a child → 403, another family → 404, a parent id → 404', function () {
        [$parent, $child] = amFamily();
        $other = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
        $otherChild = User::factory()->child()->create(['parent_id' => $other->id]);

        test()->actingAs($child, 'sanctum');
        test()->patchJson("/api/parent/children/{$child->id}/settings", ['adapted_mode' => true])->assertStatus(403);

        app('auth')->forgetGuards();
        test()->actingAs($parent, 'sanctum');
        test()->patchJson("/api/parent/children/{$otherChild->id}/settings", ['adapted_mode' => true])
            ->assertStatus(404)->assertJsonPath('reason', 'child_not_found');
        test()->patchJson("/api/parent/children/{$parent->id}/settings", ['adapted_mode' => true])->assertStatus(404);
        test()->patchJson('/api/parent/children/abc/settings', ['adapted_mode' => true])->assertStatus(404);

        expect($otherChild->refresh()->adapted_mode)->toBeFalse();
    });

    it('works for a child without a pet (no broadcast)', function () {
        $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
        $child = User::factory()->child()->create(['parent_id' => $parent->id]);
        Event::fake([PetUpdated::class]);
        test()->actingAs($parent, 'sanctum');

        test()->patchJson("/api/parent/children/{$child->id}/settings", ['adapted_mode' => true])->assertOk();
        expect($child->refresh()->adapted_mode)->toBeTrue();
        Event::assertNotDispatched(PetUpdated::class);
    });
});

describe('the child state and the family dashboard carry the switch', function () {
    it('steps.adapted_mode is the requesting child\'s switch', function () {
        [$parent, $child, $pet] = amFamily();
        test()->actingAs($child, 'sanctum');
        test()->getJson('/api/child/pet')->assertOk()->assertJsonPath('steps.adapted_mode', false);

        $child->forceFill(['adapted_mode' => true])->save();
        app('auth')->forgetGuards();
        test()->actingAs($child->refresh(), 'sanctum');
        test()->getJson('/api/child/pet')->assertOk()->assertJsonPath('steps.adapted_mode', true);
    });

    it('a shared pet: each caretaker sees their own switch', function () {
        [$parent, $child, $pet] = amFamily();
        $sibling = User::factory()->child()->create(['parent_id' => $parent->id]);
        app(FamilyService::class)->addCaretaker($pet, $sibling, false);
        $sibling->forceFill(['adapted_mode' => true])->save();

        test()->actingAs($sibling->refresh(), 'sanctum');
        test()->getJson('/api/child/pet')->assertOk()->assertJsonPath('steps.adapted_mode', true);
        app('auth')->forgetGuards();
        test()->actingAs($child, 'sanctum');
        test()->getJson('/api/child/pet')->assertOk()->assertJsonPath('steps.adapted_mode', false);
    });

    it('the parent dashboard lists adapted_mode per child', function () {
        [$parent, $child] = amFamily();
        $child->forceFill(['adapted_mode' => true])->save();
        test()->actingAs($parent, 'sanctum');

        $children = test()->getJson('/api/parent/dashboard')->assertOk()->json('family.children');
        expect(collect($children)->firstWhere('id', $child->id)['adapted_mode'])->toBeTrue();
    });
});

describe('POST /api/child/pet/steps with unit: pushes', function () {
    it('counts pushes exactly like steps (1 : 1): energy, goal, walked_pet row', function () {
        [, $child, $pet] = amFamily();
        $child->forceFill(['adapted_mode' => true])->save();

        // 2,000 pushes at 12:00 (12 h since midnight → anti-cheat allows them).
        $response = amSync($child, 2000, 'pushes')->assertOk();
        expect($response->json('status'))->toBe('accepted')
            ->and($response->json('accepted_steps'))->toBe(2000)
            ->and($response->json('steps_today'))->toBe(2000);

        $fresh = $pet->refresh();
        expect($fresh->daily_step_count)->toBe(2000)
            ->and(PetDailyStep::sole()->unit->value)->toBe('pushes')
            ->and(PetDailyStep::sole()->steps)->toBe(2000);

        // Same energy as the same number of steps on an identical pet.
        [, $walker, $twin] = amFamily();
        amSync($walker, 2000, null)->assertOk();
        expect($twin->refresh()->energy_level)->toEqual($fresh->energy_level)
            ->and(PetDailyStep::where('user_id', $walker->id)->sole()->unit->value)->toBe('steps');
    });

    it('keeps idempotency and the 200 / min anti-cheat for pushes', function () {
        [, $child, $pet] = amFamily();
        amSync($child, 1000, 'pushes')->assertOk();
        // The same total again → unchanged.
        expect(amSync($child, 1000, 'pushes')->json('status'))->toBe('unchanged');

        // +5,000 one minute later → capped at 200.
        Carbon::setTestNow(now()->addMinute());
        $r = amSync($child, 6000, 'pushes', '2026-10-14T12:01:00+02:00');
        expect($r->json('status'))->toBe('capped')
            ->and($r->json('accepted_steps'))->toBe(200)
            ->and($pet->refresh()->daily_step_count)->toBe(1200);
    });

    it('a day with pushes stays `pushes` even if a later sync of that day says steps', function () {
        [, $child] = amFamily();
        amSync($child, 500, 'pushes')->assertOk();
        Carbon::setTestNow(now()->addMinutes(5));
        amSync($child, 700, 'steps', '2026-10-14T12:05:00+02:00')->assertOk();
        expect(PetDailyStep::sole()->unit->value)->toBe('pushes')
            ->and(PetDailyStep::sole()->steps)->toBe(700);
    });

    it('rejects an unknown unit and keeps old apps (no unit) on steps', function () {
        [, $child] = amFamily();
        amSync($child, 100, 'rolls')->assertStatus(422)->assertJsonValidationErrors('unit');
        amSync($child, 100, null)->assertOk();
        expect(PetDailyStep::sole()->unit->value)->toBe('steps');
    });

    it('the parent report names a pushes day (walk_unit) without changing any number', function () {
        [$parent, $child] = amFamily(['arrival_age_months' => 36]);
        $child->forceFill(['adapted_mode' => true])->save();
        amSync($child, 3000, 'pushes')->assertOk();

        test()->actingAs($parent, 'sanctum');
        $report = test()->getJson("/api/parent/children/{$child->id}/report?days=7")->assertOk();
        expect($report->json('child.adapted_mode'))->toBeTrue();
        $today = collect($report->json('daily'))->firstWhere('date', '2026-10-14');
        expect($today['walk_unit'])->toBe('pushes')
            ->and($today['walk_steps'])->toBe(3000);
        $yesterday = collect($report->json('daily'))->firstWhere('date', '2026-10-13');
        expect($yesterday['walk_unit'])->toBe('steps');
    });

    it('the account export carries the switch and the unit (no reason)', function () {
        [$parent, $child] = amFamily();
        $child->forceFill(['adapted_mode' => true])->save();
        amSync($child, 300, 'pushes')->assertOk();

        $export = app(AccountExportService::class)->exportFor($parent->refresh());
        $json = json_encode($export);
        expect($json)->toContain('"adapted_mode":true')
            ->and($json)->toContain('"unit":"pushes"');
    });
});

describe('walk reminder wording', function () {
    it('renders the pushes variant in EN and SL, the plain text otherwise', function () {
        expect(PushCopy::body(PushType::WalkReminder, 'energy', 'child', 'sl', PushCopy::VARIANT_PUSHES))
            ->toBe('Tvoj kuža si želi na pravi sprehod – danes ima še premalo potiskov. Gremo na sprehod?')
            ->and(PushCopy::body(PushType::WalkReminder, 'energy', 'child', 'en', PushCopy::VARIANT_PUSHES))
            ->toBe('Your dog wants a proper walk – not enough pushes yet today. Time for a walk?')
            ->and(PushCopy::body(PushType::WalkReminder, 'energy', 'child', 'sl'))
            ->toBe('Tvoj kuža si želi na pravi sprehod – danes ima še premalo korakov. Gremo na sprehod?');
    });

    it('sends "pushes" only to the caretaker in adapted mode on a shared pet', function () {
        config(['push.enabled' => true]);
        [$parent, $child, $pet] = amFamily(['energy_level' => 0, 'hunger_level' => 100, 'thirst_level' => 100, 'hygiene_level' => 100]);
        $sibling = User::factory()->child()->create(['parent_id' => $parent->id]);
        app(FamilyService::class)->addCaretaker($pet, $sibling, false);
        $sibling->forceFill(['adapted_mode' => true])->save();

        $device = fn (User $u): DevicePushToken => DevicePushToken::create([
            'user_id' => $u->id, 'expo_push_token' => 'ExponentPushToken['.Str::random(22).']',
            'platform' => 'ios', 'app_version' => '1.0.0', 'locale' => 'sl', 'last_seen_at' => now(),
        ]);
        $walkerDevice = $device($child);
        $rollerDevice = $device($sibling);

        $sent = new ArrayObject;
        Http::swap(new HttpFactory(app('events')));
        Http::preventStrayRequests();
        Http::fake(['https://exp.host/--/api/v2/push/send' => function (Request $request) use ($sent) {
            foreach ($request->data() as $m) {
                $sent->append($m);
            }

            return Http::response(['data' => array_map(fn () => ['status' => 'ok', 'id' => 'tk-'.Str::random(6)], $request->data())]);
        }]);

        Queue::fake([SendPushNotification::class]);
        $row = app(NotificationService::class)->walkReminder($pet->refresh());
        expect($row)->not->toBeNull()->and($row->type)->toBe(PushType::WalkReminder);
        (new SendPushNotification($row->id))->handle(app(NotificationService::class), app(ExpoPushClient::class));

        $byToken = collect($sent->getArrayCopy())->keyBy('to');
        expect($byToken)->toHaveCount(2)
            ->and($byToken[$rollerDevice->expo_push_token]['body'])->toContain('potiskov')
            ->and($byToken[$walkerDevice->expo_push_token]['body'])->toContain('korakov')
            ->and($byToken[$rollerDevice->expo_push_token]['data'])->toBe(['type' => 'walk_reminder', 'pet_id' => $pet->id])
            ->and(PushNotification::sole()->status)->toBe('sent');
    });
});
