<?php

use App\Enums\ChallengePaidSource;
use App\Enums\PetStateEnum;
use App\Enums\PushType;
use App\Enums\Species;
use App\Events\PetUpdated;
use App\Filament\Resources\PetResource\RelationManagers\MediaRelationManager;
use App\Jobs\SendPushNotification;
use App\Jobs\StorePetMedia;
use App\Jobs\SubmitPetStateVideo;
use App\Models\DevicePushToken;
use App\Models\Pet;
use App\Models\PetMedia;
use App\Models\PushNotification;
use App\Models\User;
use App\Services\ChallengeService;
use App\Services\Media\PetMediaService;
use App\Services\Media\PetPreparationService;
use App\Services\Push\PushCopy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| M5-T06 — pet preparation (APP_REFINEMENT_SPEC §13, David 2026-10-20)
|--------------------------------------------------------------------------
| The user never learns that media are generated: the child state, the
| dashboard and PetUpdated carry a neutral `preparation` summary; videos are
| queued in "first minutes" order (idle, sleeping, hungry, then playing, then
| the rest) without changing which states a pet gets; when everything is
| stored the pet is "ready" ONCE — one PetUpdated `pet_ready`, one push to the
| caretaker children (title = name, body without it, payload {type, pet_id}) —
| and only when the preparation was shown (media missing at birth).
*/

beforeEach(function () {
    Http::preventStrayRequests();
    Storage::fake('pet_media');
    config([
        'services.fal_ai.key' => 'test-key',
        'app.url' => 'https://api.petprep.si',
        'media.state_video_profile' => 'kling_v3_pro',
        'push.enabled' => true,
    ]);
    seedBreedConfigs();
});

/**
 * An unborn pet of a child (contract not signed yet). Mutt = free plan (basic set:
 * idle + sleeping); any other breed = a purchased challenge (full set).
 *
 * @return array{0: User, 1: User, 2: Pet}
 */
function ppFamily(string $breed = 'mutt', array $attributes = []): array
{
    $parent = createParentUser();
    withoutQuietHours($parent);
    $child = createChildUser(['parent_id' => $parent->id]);
    $factory = Pet::factory()->unborn();
    $factory = $breed === 'mutt' ? $factory->freePlan() : $factory->purchased();
    $pet = $factory->create(array_merge(['user_id' => $child->id, 'breed_type' => $breed, 'media_status' => 'pending', 'name' => 'Luna'], $attributes));

    return [$parent, $child, $pet];
}

/** A stored slot of the pet (file on the fake disk). */
function ppStored(Pet $pet, ?string $state = null): PetMedia
{
    $kind = $state === null ? 'image' : 'video';
    $path = sprintf('%d/%s-g1.%s', $pet->id, $state ?? 'reference', $kind === 'image' ? 'jpg' : 'mp4');
    Storage::disk('pet_media')->put($path, 'x');

    $slot = PetMedia::updateOrCreate(
        ['pet_id' => $pet->id, 'kind' => $kind, 'state' => $state],
        ['status' => 'ready', 'generation' => 1, 'source_generation' => $kind === 'video' ? 1 : null, 'storage_path' => $path,
            'mime' => $kind === 'image' ? 'image/jpeg' : 'video/mp4', 'bytes' => 1, 'completed_at' => now()],
    );
    if ($kind === 'image') {
        $pet->updateQuietly(['media_status' => 'ready']);
    }

    return $slot;
}

/** A video slot fal is working on (webhook received, download pending). */
function ppRunning(Pet $pet, string $state): PetMedia
{
    return PetMedia::updateOrCreate(
        ['pet_id' => $pet->id, 'kind' => 'video', 'state' => $state],
        ['status' => 'running', 'generation' => 1, 'source_generation' => 1, 'request_id' => "req-{$pet->id}-{$state}", 'source_url' => "https://v3.fal.media/files/{$pet->id}/{$state}.mp4"],
    );
}

function ppSign(User $child): void
{
    app('auth')->forgetGuards();
    test()->actingAs($child, 'sanctum')
        ->postJson('/api/child/contract', ['signature_format' => 'svg_path', 'signature' => 'M10 10 L20 20'])
        ->assertCreated();
}

function ppDevice(User $user, string $locale = 'sl'): DevicePushToken
{
    return DevicePushToken::create([
        'user_id' => $user->id,
        'expo_push_token' => 'ExponentPushToken['.Str::random(22).']',
        'platform' => 'ios',
        'app_version' => '1.0.0',
        'locale' => $locale,
        'last_seen_at' => now(),
    ]);
}

/** Store a running video slot through the real pipeline (download faked). */
function ppStore(PetMedia $slot): void
{
    Http::fake(['v3.fal.media/*' => Http::response("\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 512), 200, ['Content-Type' => 'video/mp4'])]);
    app(PetMediaService::class)->storeResult($slot->id);
}

describe('generation order', function () {
    it('queues the first-minutes clips first (idle, sleeping, hungry), then playing, then the rest — same set, same count', function () {
        Queue::fake([SubmitPetStateVideo::class]);
        [, $child, $pet] = ppFamily('border_collie');
        ppStored($pet);
        ppSign($child);

        $queued = [];
        Queue::assertPushed(SubmitPetStateVideo::class, function (SubmitPetStateVideo $job) use (&$queued): bool {
            $queued[] = PetMedia::find($job->petMediaId)->state;

            return true;
        });

        // The full set of a purchased dog (adult, no behaviour events): exactly the six classic states.
        expect($queued)->toBe(['idle', 'sleeping', 'hungry', 'playing', 'low_energy', 'sick']);
    });

    it('orders without adding or dropping a state', function () {
        $all = PetStateEnum::cases();
        $ordered = PetPreparationService::order($all);

        expect($ordered)->toHaveCount(count($all))
            ->and(array_map(fn ($s) => $s->value, array_slice($ordered, 0, 4)))->toBe(['idle', 'sleeping', 'hungry', 'playing'])
            ->and(collect($ordered)->map->value->sort()->values()->all())->toBe(collect($all)->map->value->sort()->values()->all());
    });

    it('keeps the basic set to idle + sleeping', function () {
        Queue::fake([SubmitPetStateVideo::class]);
        [, $child, $pet] = ppFamily();
        ppStored($pet);
        ppSign($child);

        Queue::assertPushed(SubmitPetStateVideo::class, 2);
        expect(PetMedia::where('pet_id', $pet->id)->videos()->orderBy('id')->pluck('state')->all())->toBe(['idle', 'sleeping']);
    });

    it('keeps the claims: a slot already submitted to fal is not queued again', function () {
        Queue::fake([SubmitPetStateVideo::class]);
        [, $child, $pet] = ppFamily();
        ppStored($pet);
        ppSign($child);
        PetMedia::where('pet_id', $pet->id)->videos()->get()->each(fn (PetMedia $m) => $m->update(['status' => 'running', 'request_id' => "req-{$m->id}", 'source_generation' => 1]));

        expect(app(PetMediaService::class)->queueStateVideos($pet->fresh()))->toBe(0);
        Queue::assertPushed(SubmitPetStateVideo::class, 2);
    });
});

describe('readiness summary', function () {
    it('starts the preparation at birth when media are missing and reports it in the child state', function () {
        Queue::fake([SubmitPetStateVideo::class]);
        [, $child, $pet] = ppFamily();
        ppStored($pet);
        ppSign($child);

        expect($pet->fresh()->preparation_started_at)->not->toBeNull();

        app('auth')->forgetGuards();
        $prep = $this->actingAs($child, 'sanctum')->getJson('/api/child/pet')->assertOk()->json('pet.preparation');

        expect($prep)->toBe(['ready' => false, 'pending' => ['idle', 'sleeping'], 'preparing' => true, 'running' => true, 'ready_at' => null]);
    });

    it('stops showing the set-up screen once the first-minutes clips are stored, and flips ready with the last item', function () {
        Queue::fake([SubmitPetStateVideo::class]);
        [, $child, $pet] = ppFamily('border_collie');
        ppStored($pet);
        ppSign($child);
        $service = app(PetPreparationService::class);

        foreach (['idle', 'sleeping', 'hungry'] as $state) {
            ppStored($pet, $state);
        }
        $mid = $service->summary($pet->fresh());
        expect($mid->ready)->toBeFalse()
            ->and($mid->preparing)->toBeFalse()
            ->and($mid->running)->toBeTrue()
            ->and($mid->pending)->toBe(['playing', 'low_energy', 'sick']);

        foreach (['playing', 'low_energy', 'sick'] as $state) {
            ppStored($pet, $state);
        }
        expect($service->summary($pet->fresh())->ready)->toBeTrue();
    });

    it('closes the set-up screen after 15 minutes even if a first clip still hangs (the preparation keeps running)', function () {
        Queue::fake([SubmitPetStateVideo::class]);
        [, $child, $pet] = ppFamily();
        ppStored($pet);
        ppSign($child);
        expect(app(PetPreparationService::class)->summary($pet->fresh())->preparing)->toBeTrue();

        $this->travel(PetPreparationService::SCREEN_MAX_MINUTES + 1)->minutes();
        $late = app(PetPreparationService::class)->summary($pet->fresh());

        expect($late->preparing)->toBeFalse()
            ->and($late->running)->toBeTrue()
            ->and($late->ready)->toBeFalse();
    });

    it('treats a failed item as not on its way (no endless set-up screen), but not as ready', function () {
        Queue::fake([SubmitPetStateVideo::class]);
        [, $child, $pet] = ppFamily();
        ppStored($pet);
        ppSign($child);
        PetMedia::where('pet_id', $pet->id)->where('state', 'idle')->update(['status' => 'failed', 'error_reason' => 'budget_daily']);
        PetMedia::where('pet_id', $pet->id)->where('state', 'sleeping')->update(['status' => 'failed', 'error_reason' => 'budget_daily']);

        $summary = app(PetPreparationService::class)->summary($pet->fresh());

        expect($summary->ready)->toBeFalse()
            ->and($summary->preparing)->toBeFalse()
            ->and($summary->pending)->toBe(['idle', 'sleeping']);
    });

    it('is ready with nothing pending for a pet that gets no AI media, and never starts a preparation', function () {
        [, $child, $pet] = ppFamily('mutt', ['media_status' => 'disabled']);
        ppSign($child);

        $fresh = $pet->fresh();
        expect($fresh->media_status)->toBe('disabled')
            ->and($fresh->preparation_started_at)->toBeNull()
            ->and(app(PetPreparationService::class)->summary($fresh)->toArray())
            ->toBe(['ready' => true, 'pending' => [], 'preparing' => false, 'running' => false, 'ready_at' => null]);
    });

    it('does not start a preparation when everything is already stored at birth (shared look ready)', function () {
        Event::fake([PetUpdated::class]);
        [, $child, $pet] = ppFamily();
        ppStored($pet);
        ppStored($pet, 'idle');
        ppStored($pet, 'sleeping');
        ppSign($child);

        expect($pet->fresh()->preparation_started_at)->toBeNull();
        Event::assertNotDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->eventType === 'pet_ready');
        expect(PushNotification::where('pet_id', $pet->id)->where('type', PushType::PetReady->value)->exists())->toBeFalse();
    });

    it('carries the summary in PetUpdated and the parent dashboard', function () {
        Queue::fake([SubmitPetStateVideo::class]);
        [$parent, $child, $pet] = ppFamily();
        ppStored($pet);
        ppSign($child);

        expect(PetUpdated::payloadFor($pet->fresh())['preparation']['pending'])->toBe(['idle', 'sleeping']);

        app('auth')->forgetGuards();
        $pets = $this->actingAs($parent, 'sanctum')->getJson('/api/parent/dashboard')->assertOk()->json('family.pets');
        expect(collect($pets)->firstWhere('id', $pet->id)['preparation']['running'] ?? null)->toBeTrue();
    });
});

describe('the single "ready" news', function () {
    it('announces once: one PetUpdated pet_ready and one push to the caretaker child (title = name, body without it, payload {type, pet_id})', function () {
        Queue::fake([SubmitPetStateVideo::class]);
        [$parent, $child, $pet] = ppFamily();
        ppStored($pet);
        ppSign($child);
        ppDevice($child);
        ppDevice($parent);
        ppStored($pet, 'idle');
        $last = ppRunning($pet, 'sleeping');

        Event::fake([PetUpdated::class]);
        Queue::fake([SendPushNotification::class]);
        ppStore($last);

        $fresh = $pet->fresh();
        expect($fresh->preparation_ready_at)->not->toBeNull();
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->eventType === 'pet_ready' && $e->petId === $pet->id);
        Event::assertDispatchedTimes(PetUpdated::class, 2); // video_ready + pet_ready

        $push = PushNotification::where('pet_id', $pet->id)->where('type', PushType::PetReady->value)->sole();
        expect(collect($push->recipients)->pluck('user_id')->all())->toBe([$child->id])
            ->and($push->status)->toBe(PushNotification::STATUS_QUEUED);
        Queue::assertPushed(SendPushNotification::class, 1);

        // A later store (regenerate, a new stage) never announces again.
        Event::fake([PetUpdated::class]);
        $again = ppRunning($pet, 'idle');
        ppStore($again);
        Event::assertNotDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->eventType === 'pet_ready');
        expect(PushNotification::where('pet_id', $pet->id)->where('type', PushType::PetReady->value)->count())->toBe(1);
    });

    it('sends the name as the title and a body without it; data has only {type, pet_id}', function () {
        Queue::fake([SubmitPetStateVideo::class]);
        [, $child, $pet] = ppFamily();
        ppStored($pet);
        ppSign($child);
        ppDevice($child);
        ppStored($pet, 'idle');
        $last = ppRunning($pet, 'sleeping');

        $sent = [];
        Http::fake([
            'v3.fal.media/*' => Http::response("\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 512), 200, ['Content-Type' => 'video/mp4']),
            'https://exp.host/--/api/v2/push/send' => function ($request) use (&$sent) {
                foreach ($request->data() as $message) {
                    $sent[] = $message;
                }

                return Http::response(['data' => array_map(fn () => ['status' => 'ok', 'id' => 'ticket-'.Str::random(8)], $request->data())]);
            },
        ]);
        app(PetMediaService::class)->storeResult($last->id);

        expect($sent)->toHaveCount(1)
            ->and($sent[0]['title'])->toBe('Luna')
            ->and($sent[0]['body'])->toBe(PushCopy::body(PushType::PetReady, null, 'child', 'sl'))
            ->and($sent[0]['body'])->not->toContain('Luna')
            ->and(array_keys($sent[0]['data']))->toEqualCanonicalizing(['type', 'pet_id'])
            ->and($sent[0]['data']['type'])->toBe('pet_ready');
    });

    it('sends no push for a preparation that ended long after it started (a failed clip retried the next day)', function () {
        Queue::fake([SubmitPetStateVideo::class, SendPushNotification::class]);
        [, $child, $pet] = ppFamily();
        ppStored($pet);
        ppSign($child);
        ppStored($pet, 'idle');
        ppStored($pet, 'sleeping');
        $this->travel(PetPreparationService::PUSH_MAX_HOURS + 1)->hours();

        Event::fake([PetUpdated::class]);
        expect(app(PetPreparationService::class)->settle($pet->id))->toBeTrue();

        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->eventType === 'pet_ready');
        expect(PushNotification::where('pet_id', $pet->id)->where('type', PushType::PetReady->value)->exists())->toBeFalse();
    });

    it('has neutral texts in every language (no AI, no generation) and a cat text for a cat', function () {
        foreach (['en', 'sl'] as $locale) {
            foreach ([Species::Dog, Species::Cat] as $species) {
                $body = PushCopy::body(PushType::PetReady, null, 'child', $locale, null, [], $species);
                expect($body)->not->toBe('')
                    ->and(mb_strtolower($body))->not->toMatch('/\b(ai|generat|ustvarj|video|slik)/u');
            }
        }
        expect(PushCopy::body(PushType::PetReady, null, 'child', 'sl', null, [], Species::Cat))->toContain('muca');
    });

    it('settles a shared look link like a stored file (the look pipeline also announces once)', function () {
        Queue::fake([SubmitPetStateVideo::class, StorePetMedia::class]);
        [, $child, $pet] = ppFamily();
        ppStored($pet);
        ppSign($child);
        ppStored($pet, 'idle');
        ppStored($pet, 'sleeping'); // e.g. linked from the look (same servable slot)

        Event::fake([PetUpdated::class]);
        expect(app(PetPreparationService::class)->settle($pet->id))->toBeTrue()
            ->and(app(PetPreparationService::class)->settle($pet->id))->toBeFalse();
        Event::assertDispatchedTimes(PetUpdated::class, 1);
    });

    it('starts the preparation at the purchase for a pet that waited behind the payment lock', function () {
        Queue::fake([SubmitPetStateVideo::class]);
        [, , $pet] = ppFamily('mutt');
        ppStored($pet);
        $pet->forceFill(['born_at' => now(), 'last_decay_at' => now(), 'payment_locked_at' => now(), 'plan' => 'challenge', 'challenge_paid_at' => null, 'challenge_paid_source' => null])->saveQuietly();

        // Behind the lock: no preparation yet.
        expect(app(PetPreparationService::class)->begin($pet->fresh()))->toBeFalse();

        DB::transaction(fn () => app(ChallengeService::class)
            ->markPaid(Pet::whereKey($pet->id)->lockForUpdate()->first(), ChallengePaidSource::Purchase, now()));

        expect($pet->fresh()->preparation_started_at)->not->toBeNull();
    });
});

describe('admin measurement', function () {
    it('measures preparation = ready_at − started_at and the pipeline span', function () {
        Carbon::setTestNow(Carbon::parse('2026-10-21 10:00:00', 'UTC'));
        [, , $pet] = ppFamily();
        $pet->forceFill(['preparation_started_at' => now()->subMinutes(7)->subSeconds(5), 'preparation_ready_at' => now()])->saveQuietly();
        $image = ppStored($pet);
        $image->forceFill(['created_at' => now()->subMinutes(9)])->saveQuietly();

        $timing = app(PetPreparationService::class)->timing($pet->fresh());

        expect($timing['preparation_seconds'])->toBe(425)
            ->and($timing['pipeline_seconds'])->toBe(540)
            ->and(MediaRelationManager::timingLine($pet->fresh()))
            ->toContain('7 min 05 s');
    });
});
