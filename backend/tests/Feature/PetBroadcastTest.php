<?php

use App\Events\PetUpdated;
use App\Models\Pet;
use App\Models\User;
use App\Services\EscalationService;
use App\Services\PetDecayService;
use Illuminate\Broadcasting\Broadcasters\NullBroadcaster;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| M1-08 private pet channel + M1-09 queued broadcasts
|--------------------------------------------------------------------------
*/

/**
 * Parent → child → mutt pet, metrics full, no hygiene events.
 *
 * @return array{0: User, 1: User, 2: Pet}
 */
function broadcastFamily(array $petAttributes = []): array
{
    seedBreedConfigs();

    $parent = User::factory()->parent()->create(['timezone' => 'UTC']);
    withoutQuietHours($parent); // runs on the wall clock: no default night quiet hours
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    $pet = Pet::factory()->mutt()->create(array_merge([
        'user_id' => $child->id,
        'hunger_level' => 100,
        'thirst_level' => 100,
        'energy_level' => 100,
        'hygiene_level' => 100,
        'escalation_level' => 0,
    ], $petAttributes));

    return [$parent, $child, disableHygieneEvents($pet)];
}

/**
 * Put the decay clock $minutes in the past (the next tick catches up).
 */
function bcRewind(Pet $pet, int $minutes): Pet
{
    Pet::whereKey($pet->id)->update(['last_decay_at' => now()->subMinutes($minutes)]);

    return $pet->refresh();
}

/**
 * Use a real Pusher-protocol broadcaster (Reverb) so /api/broadcasting/auth
 * evaluates the channel callbacks (the `log` driver used by phpunit.xml
 * authorizes nothing). No network: auth only signs locally.
 */
function useReverbBroadcasterForAuth(): void
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
        'broadcasting.connections.reverb.options.host' => 'localhost',
    ]);
    app(BroadcastManager::class)->forgetDrivers();
    require base_path('routes/channels.php');
}

function authChannel(string $channel): TestResponse
{
    // Form-encoded like pusher-js' authorizer, without Accept: application/json.
    return post('/api/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => $channel,
    ]);
}

describe('channel authorization (POST /api/broadcasting/auth)', function () {
    beforeEach(fn () => useReverbBroadcasterForAuth());

    it('allows the child who owns the pet', function () {
        [, $child, $pet] = broadcastFamily();
        actingAs($child, 'sanctum');

        authChannel("private-pet.{$pet->id}")
            ->assertOk()
            ->assertJsonStructure(['auth']);
    });

    it('accepts a JSON body (as the mobile api client sends it)', function () {
        [, $child, $pet] = broadcastFamily();
        actingAs($child, 'sanctum');

        postJson('/api/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-pet.{$pet->id}",
        ])->assertOk()->assertJsonStructure(['auth']);
    });

    it("allows the owning child's parent", function () {
        [$parent, , $pet] = broadcastFamily();
        actingAs($parent, 'sanctum');

        authChannel("private-pet.{$pet->id}")
            ->assertOk()
            ->assertJsonStructure(['auth']);
    });

    it('denies another child', function () {
        [, , $pet] = broadcastFamily();
        actingAs(User::factory()->child()->create(), 'sanctum');

        authChannel("private-pet.{$pet->id}")->assertForbidden();
    });

    it('denies another parent', function () {
        [, , $pet] = broadcastFamily();
        actingAs(User::factory()->parent()->create(), 'sanctum');

        authChannel("private-pet.{$pet->id}")->assertForbidden();
    });

    it('denies a parent whose own child has a different pet', function () {
        [, , $pet] = broadcastFamily();
        [$otherParent] = broadcastFamily();
        actingAs($otherParent, 'sanctum');

        authChannel("private-pet.{$pet->id}")->assertForbidden();
    });

    it('denies a guest with a JSON 401 (no redirect)', function () {
        [, , $pet] = broadcastFamily();

        authChannel("private-pet.{$pet->id}")
            ->assertUnauthorized()
            ->assertJson(['message' => 'Unauthenticated.']);
    });

    it('denies a pet that does not exist and a malformed id', function () {
        [, $child] = broadcastFamily();
        actingAs($child, 'sanctum');

        authChannel('private-pet.999999')->assertForbidden();
        authChannel('private-pet.abc')->assertForbidden();
    });

    it('no longer knows the old pet.updated.{id} channel', function () {
        [, $child, $pet] = broadcastFamily();
        actingAs($child, 'sanctum');

        authChannel("private-pet.updated.{$pet->id}")->assertForbidden();
    });

    it('serves the auth endpoint only under /api (no session route)', function () {
        [, $child, $pet] = broadcastFamily();
        actingAs($child, 'sanctum');

        postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-pet.{$pet->id}",
        ])->assertNotFound();
    });
});

describe('PetUpdated event shape', function () {
    it('broadcasts on the private pet channel only', function () {
        [, , $pet] = broadcastFamily();

        $channels = PetUpdated::fromPet($pet, 'metric_changed')->broadcastOn();

        expect($channels)->toHaveCount(1);
        expect($channels[0])->toBeInstanceOf(PrivateChannel::class);
        expect($channels[0]->name)->toBe("private-pet.{$pet->id}");
    });

    it('carries pet state only — no user id, name or email', function () {
        [, $child, $pet] = broadcastFamily(['hunger_level' => 33.6]);

        $payload = PetUpdated::fromPet($pet, 'fed_pet')->broadcastWith();

        expect(array_keys($payload))->toEqualCanonicalizing([
            'pet_id', 'breed_type', 'hunger_level', 'thirst_level', 'energy_level', 'hygiene_level',
            'is_active', 'pet_state', 'escalation_level', 'is_ill', 'illness_until', 'is_game_over',
            'is_hard_stopped', 'virtual_age_months', 'born_at', 'awaiting_contract', 'current_video_url', 'media_status',
            'reference_image_url', 'media', 'event_type', 'updated_at', 'emitted_at',
            // M3-11 plan + payment status
            'plan',
            // M5-R06-01 species (additive)
            'species',
            // M5-R01 profile brief
            'age_months', 'origin', 'life_stage',
            // M5-R02 behaviour events
            'behaviour',
            // M5-R03 training summary
            'training',
            // M5-R05 play & cuddle (null without play)
            'play',
            // M5-R06-04 cat wand play (additive; null for a dog)
            'wand',
        ]);
        expect(array_keys($payload['behaviour']))->toBe(['take_out', 'active_events', 'scene'])
            ->and(array_keys($payload['training']))->toBe(['enabled', 'commands', 'today_done', 'session_active']);
        expect(array_keys($payload['media']))->toBe(['status', 'reference_image_url', 'videos', 'current_video_url', 'states', 'expires_at']);
        expect($payload['hunger_level'])->toBe(34);
        expect($payload['event_type'])->toBe('fed_pet');

        $encoded = json_encode($payload);
        expect($encoded)->not->toContain($child->email);
        expect($encoded)->not->toContain($child->name);
    });

    it('is queued (ShouldBroadcast, not ShouldBroadcastNow) on the broadcasts queue', function () {
        [, , $pet] = broadcastFamily();
        $event = PetUpdated::fromPet($pet);

        expect($event)->toBeInstanceOf(ShouldBroadcast::class);
        expect($event)->not->toBeInstanceOf(ShouldBroadcastNow::class);
        expect($event->broadcastQueue)->toBe('broadcasts');
    });

    it('pushes a BroadcastEvent job onto the broadcasts queue after a tick change', function () {
        Queue::fake();
        [, , $pet] = broadcastFamily(['hunger_level' => 80]);
        bcRewind($pet, 60);

        app(PetDecayService::class)->processPetDecay($pet);

        Queue::assertPushedOn('broadcasts', BroadcastEvent::class, function (BroadcastEvent $job) use ($pet) {
            return $job->event instanceof PetUpdated
                && $job->event->petId === $pet->id
                && $job->event->eventType === 'metric_changed';
        });
        Queue::assertPushed(BroadcastEvent::class, 1);
    });

    it('snapshots the committed state (the job needs no model)', function () {
        Queue::fake();
        [, , $pet] = broadcastFamily(['hunger_level' => 80]);
        bcRewind($pet, 60);

        app(PetDecayService::class)->processPetDecay($pet);
        $pet->delete();

        Queue::assertPushed(BroadcastEvent::class, function (BroadcastEvent $job) {
            $restored = unserialize(serialize($job));

            return $restored->event->payload['hunger_level'] === 72;
        });
    });
});

describe('exactly one PetUpdated per state change', function () {
    it('tick: one when a displayed value changed, none otherwise', function () {
        [, , $pet] = broadcastFamily(['hunger_level' => 80]);
        Event::fake([PetUpdated::class]);

        bcRewind($pet, 60);
        app(PetDecayService::class)->processPetDecay($pet);
        Event::assertDispatchedTimes(PetUpdated::class, 1);

        // One second later nothing visible changes → no second event.
        $this->travel(1)->seconds();
        app(PetDecayService::class)->processPetDecay($pet);
        Event::assertDispatchedTimes(PetUpdated::class, 1);
    });

    it('escalation steps: one event each, with its own event type', function (array $attributes, string $eventType) {
        [, , $pet] = broadcastFamily($attributes);
        Event::fake([PetUpdated::class]);

        expect(app(EscalationService::class)->processPetEscalation($pet))->toBeTrue();

        Event::assertDispatchedTimes(PetUpdated::class, 1);
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->eventType === $eventType && $e->petId === $pet->id);
    })->with([
        'phase 1' => [['hunger_level' => 30], 'soft_warning'],
        'phase 2' => [['hunger_level' => 10], 'critical_alert'],
        'phase 3' => [['hunger_level' => 0, 'hunger_zero_since' => now()->subHours(2)], 'parent_intervention_alarm'],
        'illness' => [['hygiene_level' => 0, 'hygiene_zero_since' => now()->subHours(7)], 'illness_triggered'],
        'game over' => [['hunger_level' => 0, 'hunger_zero_since' => now()->subHours(25), 'escalation_level' => 3], 'game_over_virtual_shelter'],
        'reset' => [['escalation_level' => 2], 'escalation_reset'],
    ]);

    it('escalation: no event when nothing changes', function () {
        [, , $pet] = broadcastFamily(['hunger_level' => 30, 'escalation_level' => 1]);
        Event::fake([PetUpdated::class]);

        app(EscalationService::class)->processPetEscalation($pet);

        Event::assertNotDispatched(PetUpdated::class);
    });

    it('game over carries the final state (inactive, game over)', function () {
        [, , $pet] = broadcastFamily(['hunger_level' => 0, 'hunger_zero_since' => now()->subHours(25)]);
        Event::fake([PetUpdated::class]);

        app(EscalationService::class)->processPetEscalation($pet);

        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->payload['is_game_over'] === true
            && $e->payload['is_active'] === false);
    });

    it('parent hard stop: one event', function () {
        [$parent, , $pet] = broadcastFamily();
        Event::fake([PetUpdated::class]);
        actingAs($parent, 'sanctum');

        postJson('/api/parent/hard-stop')->assertOk();

        Event::assertDispatchedTimes(PetUpdated::class, 1);
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->eventType === 'hard_stop_activated'
            && $e->payload['is_hard_stopped'] === true);
    });

    // Child actions (feed / water / clean / steps / contract): one event each,
    // asserted in ChildPetApiTest, EnergyStepsTest and HygieneEventTest.

    it('a plain model save broadcasts nothing (no observers)', function () {
        [, , $pet] = broadcastFamily();
        Event::fake([PetUpdated::class]);

        $pet->update(['hunger_level' => 55]);
        $pet->activities()->create(['activity_type' => 'fed_pet', 'value' => 55]);

        Event::assertNotDispatched(PetUpdated::class);
    });
});

describe('broadcast after commit only', function () {
    it('escalation: dispatched only when the surrounding transaction commits', function () {
        [, , $pet] = broadcastFamily(['hunger_level' => 10]);
        Event::fake([PetUpdated::class]);

        DB::transaction(function () use ($pet) {
            app(EscalationService::class)->processPetEscalation($pet);
            Event::assertNotDispatched(PetUpdated::class);
        });

        Event::assertDispatchedTimes(PetUpdated::class, 1);
    });

    it('tick: nothing is dispatched when the transaction rolls back', function () {
        [, , $pet] = broadcastFamily(['hunger_level' => 80]);
        bcRewind($pet, 60);
        Event::fake([PetUpdated::class]);

        try {
            DB::transaction(function () use ($pet) {
                app(PetDecayService::class)->processPetDecay($pet);
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
            // expected
        }

        Event::assertNotDispatched(PetUpdated::class);
    });
});

describe('the tick survives broadcasting failures (M1-09)', function () {
    it('keeps processing every pet when the queue push throws', function () {
        [, , $first] = broadcastFamily(['hunger_level' => 80]);
        [, , $second] = broadcastFamily(['hunger_level' => 80]);
        bcRewind($first, 60);
        bcRewind($second, 60);

        $this->mock(BroadcastFactory::class, function ($mock) {
            $mock->shouldReceive('event')->andThrow(new RuntimeException('queue down'));
            $mock->shouldReceive('queue')->andThrow(new RuntimeException('queue down'));
        });

        Log::spy();

        artisan('pets:process-decay')->assertSuccessful();

        Log::shouldHaveReceived('error')->with('PetUpdated: broadcast could not be queued', Mockery::any())->atLeast()->twice();

        expect($first->refresh()->displayMetric('hunger_level'))->toBe(72);
        expect($second->refresh()->displayMetric('hunger_level'))->toBe(72);
    });

    it('keeps processing when Reverb itself throws (sync queue delivers inline)', function () {
        config(['queue.default' => 'sync']);
        Broadcast::extend('exploding', fn () => new class extends NullBroadcaster
        {
            public function broadcast(array $channels, $event, array $payload = [])
            {
                throw new RuntimeException('Reverb unreachable');
            }
        });
        config(['broadcasting.default' => 'exploding']);
        app(BroadcastManager::class)->forgetDrivers();

        [, , $first] = broadcastFamily(['hunger_level' => 80]);
        [, , $second] = broadcastFamily(['hunger_level' => 10]);
        bcRewind($first, 60);
        bcRewind($second, 60);

        Log::spy();

        artisan('pets:process-decay')->assertSuccessful();

        Log::shouldHaveReceived('error')->with('PetUpdated: broadcast could not be queued', Mockery::any())->atLeast()->twice();

        expect($first->refresh()->displayMetric('hunger_level'))->toBe(72);
        expect($second->refresh()->escalation_level)->toBe(2);
    });
});
