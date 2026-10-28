<?php

use App\Enums\BreedType;
use App\Enums\ChallengePaidSource;
use App\Enums\LifeStage;
use App\Enums\PetOrigin;
use App\Enums\PetPlan;
use App\Events\PetUpdated;
use App\Jobs\GeneratePetKeyframe;
use App\Models\AiSpendLedger;
use App\Models\Pet;
use App\Models\PetMedia;
use App\Services\FalWebhookVerifier;
use App\Services\FamilyService;
use App\Services\Media\FalGateway;
use App\Services\Media\PetMediaService;
use App\Services\PairingService;
use App\Services\Results\PetProfileChoice;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Fix 2026-10-28 (David) — key / pose images through the fal QUEUE
|--------------------------------------------------------------------------
|
| Nano Banana Pro Edit often took > 60 s on the sync endpoint: the 60 s HTTP
| timeout fired, the cost was kept, the image thrown away — three paid tries
| per slot. Key images and pose images are now submitted to fal's queue with
| the signed webhook (like videos); media:sweep polls a lost webhook and times
| it out. A slot with a request id is never submitted twice.
*/

const QK_JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";

const QK_MP4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom";

const QK_EDIT = 'https://queue.fal.run/fal-ai/nano-banana-pro/edit';

beforeEach(function () {
    Http::preventStrayRequests();
    Storage::fake('pet_media');
    Cache::forget(FalWebhookVerifier::CACHE_KEY);
    Cache::forget(FalGateway::BALANCE_FLAG_KEY);
    config([
        'services.fal_ai.key' => 'test-key',
        'app.url' => 'https://api.petprep.si',
        'media.reference_image_profile' => 'nano_banana_pro',
        'media.state_video_profile' => 'kling_v3_pro',
        'media.budget.daily_usd' => 100.0,
        'media.budget.monthly_usd' => 1000.0,
        'media.budget.timezone' => 'UTC',
    ]);
    useStateMedia();
    usePhaseClips(false);
    useUniquePetMedia(true);
    seedBreedConfigs();
    seedStageParams();
});

/**
 * fal fakes. `$edit` answers the key image queue submits (default: a request id
 * with queue status / result URLs).
 */
function qkFakeFal(?Closure $edit = null, array $extra = []): void
{
    fakeFalJwks();
    $n = 0;
    Http::fake($extra + [
        'queue.fal.run/fal-ai/nano-banana-pro/edit*' => $edit ?? function () use (&$n) {
            $n++;

            return Http::response([
                'request_id' => "qk-edit-{$n}",
                'status_url' => "https://queue.fal.run/fal-ai/nano-banana-pro/requests/qk-edit-{$n}/status",
                'response_url' => "https://queue.fal.run/fal-ai/nano-banana-pro/requests/qk-edit-{$n}",
            ]);
        },
        'fal.run/fal-ai/nano-banana-pro' => fn () => Http::response(['images' => [['url' => 'https://v3.fal.media/files/qk/ref.jpg']]]),
        'queue.fal.run/fal-ai/kling-video/*' => function () use (&$n) {
            $n++;

            return Http::response(['request_id' => "qk-video-{$n}"]);
        },
        'v3.fal.media/files/qk/*.jpg' => fn () => Http::response(QK_JPEG.str_repeat("\x00", 256), 200, ['Content-Type' => 'image/jpeg']),
        'v3.fal.media/files/qk/*.mp4' => fn () => Http::response(QK_MP4.str_repeat("\x00", 512), 200, ['Content-Type' => 'video/mp4']),
    ]);
}

/** A born, paid labrador with its own media (state media version 3) — its key images are submitted at birth. */
function qkBornPet(): Pet
{
    $parent = createParentUser();
    $family = app(FamilyService::class)->ensureFamilyFor($parent);
    $child = createChildUser(['parent_id' => $parent->id]);
    $type = BreedType::from('labrador_retriever');
    $profile = new PetProfileChoice($type, PetOrigin::Bought, LifeStage::Young, [], $type->species(), 'black');

    $pet = DB::transaction(fn () => app(PairingService::class)->attachChildToPet($family, $child, null, $profile, PetPlan::Challenge)['pet'])->fresh();
    $pet->forceFill(['plan' => PetPlan::Challenge, 'challenge_paid_at' => now(), 'challenge_paid_source' => ChallengePaidSource::Purchase])->saveQuietly();
    $pet = $pet->fresh();
    $pet->giveBirth(now());
    $pet->save();
    app(PetMediaService::class)->queueStateVideos($pet->fresh());

    return $pet->fresh();
}

function qkKey(Pet $pet, string $state = 'sleeping'): PetMedia
{
    return PetMedia::where('pet_id', $pet->id)->where('kind', 'keyframe')->where('state', $state)->firstOrFail();
}

function qkEditCalls(): int
{
    return Http::recorded(fn (Request $r) => str_starts_with($r->url(), QK_EDIT))->count();
}

function qkWebhook(PetMedia $slot, string $file = 'key'): void
{
    sendFalWebhook([
        'request_id' => $slot->request_id,
        'status' => 'OK',
        'payload' => ['images' => [['url' => "https://v3.fal.media/files/qk/{$file}-{$slot->id}.jpg"]], 'description' => ''],
    ])->assertOk();
}

describe('submit', function () {
    it('a key image is SUBMITTED to the fal queue with the signed webhook — never the sync endpoint', function () {
        qkFakeFal();
        $pet = qkBornPet();
        $key = qkKey($pet);

        $submit = Http::recorded(fn (Request $r) => str_starts_with($r->url(), QK_EDIT))->first()[0];
        expect($submit->url())->toContain('fal_webhook='.urlencode('https://api.petprep.si/api/webhooks/fal-ai'))
            ->and($submit['image_urls'] ?? $submit['image_url'] ?? null)->not->toBeNull()
            ->and(Http::recorded(fn (Request $r) => str_starts_with($r->url(), 'https://fal.run/fal-ai/nano-banana-pro/edit'))->count())->toBe(0);

        expect($key->status)->toBe('running')
            ->and($key->request_id)->toStartWith('qk-edit-')
            ->and($key->status_url)->toContain($key->request_id)
            ->and($key->response_url)->toContain($key->request_id)
            ->and($key->source_url)->toBeNull()
            ->and($key->profile)->not->toBeNull();

        // Exactly one committed ledger entry per submission, carrying the request id.
        $ledger = AiSpendLedger::where('pet_media_id', $key->id)->get();
        expect($ledger)->toHaveCount(1)
            ->and($ledger[0]->status)->toBe(AiSpendLedger::STATUS_COMMITTED)
            ->and($ledger[0]->purpose)->toBe('reference_image')
            ->and($ledger[0]->request_id)->toBe($key->request_id)
            ->and((float) $key->fresh()->cost_usd)->toBe(0.15);
    });

    it('fal never got the request (connect error) → ledger void, slot pending, the job retries', function () {
        qkFakeFal(fn () => throw new ConnectionException('cURL error 7: Failed to connect to queue.fal.run'));
        Queue::fake([GeneratePetKeyframe::class]);
        $key = qkKey(qkBornPet());

        expect(app(PetMediaService::class)->generateKeyframe($key->id))->toBeFalse() // → job retry (backoff)
            ->and($key->fresh()->status)->toBe('pending')
            ->and($key->fresh()->request_id)->toBeNull()
            ->and(AiSpendLedger::where('pet_media_id', $key->id)->counted()->count())->toBe(0);
    });

    it('sent but unanswered (timeout after sending) → cost kept, slot failed, the job does not retry', function () {
        qkFakeFal(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received'));
        Queue::fake([GeneratePetKeyframe::class]);
        $key = qkKey(qkBornPet());

        expect(app(PetMediaService::class)->generateKeyframe($key->id))->toBeTrue() // no job retry → no second paid try
            ->and($key->fresh()->status)->toBe('failed')
            ->and($key->fresh()->error_reason)->toBe('http_error')
            ->and(qkEditCalls())->toBe(1)
            ->and(AiSpendLedger::where('pet_media_id', $key->id)->where('status', AiSpendLedger::STATUS_COMMITTED)->count())->toBe(1);
    });

    it('the submit HTTP timeout stays below the job timeout (a slow fal answer never kills the worker mid-submit)', function () {
        expect(App\Services\FalAiService::KEYFRAME_SUBMIT_TIMEOUT_SECONDS)->toBeLessThan((new GeneratePetKeyframe(1))->timeout);
    });
});

describe('webhook', function () {
    it('stores the key image, broadcasts keyframe_ready once and submits the state loop', function () {
        qkFakeFal();
        $pet = qkBornPet();
        $key = qkKey($pet);
        Event::fake([PetUpdated::class]);

        $loopsBefore = Http::recorded(fn (Request $r) => str_contains($r->url(), 'kling-video'))->count();
        qkWebhook($key);

        $key->refresh();
        expect($key->status)->toBe('ready')
            ->and($key->storage_path)->toBe("{$pet->id}/key-sleeping-g1.jpg")
            ->and($key->source_url)->toBe("https://v3.fal.media/files/qk/key-{$key->id}.jpg");
        Storage::disk('pet_media')->assertExists($key->storage_path);
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->petId === $pet->id && $e->eventType === 'keyframe_ready');
        expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'kling-video'))->count())->toBe($loopsBefore + 1);

        // A repeat delivery changes nothing.
        sendFalWebhook(['request_id' => $key->request_id, 'status' => 'OK', 'payload' => ['images' => [['url' => 'https://v3.fal.media/files/qk/other.jpg']]]])
            ->assertOk()->assertJson(['message' => 'Already processed.']);
    });

    it('a fal error or an untrusted image URL fails the slot', function () {
        qkFakeFal();
        $pet = qkBornPet();
        $sleeping = qkKey($pet);
        $hungry = qkKey($pet, 'hungry');

        sendFalWebhook(['request_id' => $sleeping->request_id, 'status' => 'ERROR', 'error' => 'boom'])->assertOk();
        sendFalWebhook(['request_id' => $hungry->request_id, 'status' => 'OK', 'payload' => ['images' => [['url' => 'https://evil.example/x.jpg']]]])->assertOk();

        expect($sleeping->fresh()->status)->toBe('failed')
            ->and($sleeping->fresh()->error_reason)->toBe('generation_failed')
            ->and($hungry->fresh()->status)->toBe('failed')
            ->and($hungry->fresh()->error_reason)->toBe('invalid_response');
    });
});

describe('never paid twice', function () {
    it('a duplicate job or a sweep never submits a slot that already has a request id', function () {
        qkFakeFal();
        $pet = qkBornPet();
        $key = qkKey($pet);
        $calls = qkEditCalls();
        $media = app(PetMediaService::class);

        expect($media->generateKeyframe($key->id))->toBeTrue();
        (new GeneratePetKeyframe($key->id))->handle($media);
        app(PetMediaService::class)->queueStateVideos($pet->fresh());
        expect(qkEditCalls())->toBe($calls);

        // Long after the stale-claim window: the sweep polls it (pending at fal) — no reclaim, no submit.
        Http::fake(['queue.fal.run/fal-ai/nano-banana-pro/requests/*/status' => Http::response(['status' => 'IN_PROGRESS'])]);
        $this->travel(30)->minutes();
        $media->sweepStale();
        $media->generateKeyframe($key->id);

        expect(qkEditCalls())->toBe(0) // Http::fake above restarted the recording
            ->and($key->fresh()->status)->toBe('running')
            ->and($key->fresh()->request_id)->toBe($key->request_id)
            ->and(AiSpendLedger::where('pet_media_id', $key->id)->count())->toBe(1);
    });

    it('a dead worker\'s accepted submit is adopted on reclaim (no second paid submit)', function () {
        qkFakeFal();
        $pet = qkBornPet();
        $key = qkKey($pet);
        $requestId = $key->request_id;
        $calls = qkEditCalls();

        // The worker died after fal accepted (ledger committed) but before the slot got the request id.
        PetMedia::whereKey($key->id)->update(['request_id' => null, 'status_url' => null, 'response_url' => null, 'updated_at' => now()]);
        $this->travel(11)->minutes();

        expect(app(PetMediaService::class)->sweepStale()['reclaimed'])->toBeGreaterThanOrEqual(1)
            ->and(qkEditCalls())->toBe($calls)
            ->and($key->fresh()->status)->toBe('running')
            ->and($key->fresh()->request_id)->toBe($requestId)
            ->and(AiSpendLedger::where('pet_media_id', $key->id)->count())->toBe(1);

        qkWebhook($key->fresh());
        expect($key->fresh()->status)->toBe('ready');
    });
});

describe('media:sweep — lost webhook', function () {
    it('polls fal (free) and stores a finished key image', function () {
        qkFakeFal();
        $pet = qkBornPet();
        $key = qkKey($pet);
        Http::fake([
            "queue.fal.run/fal-ai/nano-banana-pro/requests/{$key->request_id}/status" => Http::response(['status' => 'COMPLETED']),
            "queue.fal.run/fal-ai/nano-banana-pro/requests/{$key->request_id}" => Http::response(['images' => [['url' => 'https://v3.fal.media/files/qk/polled.jpg']], 'description' => '']),
        ]);

        // Too early: nothing polled.
        $this->travel(5)->minutes();
        expect(app(PetMediaService::class)->sweepStale()['polled'])->toBe(0);

        $this->travel(10)->minutes();
        $ledgerBefore = AiSpendLedger::count();
        expect(app(PetMediaService::class)->sweepStale()['polled'])->toBeGreaterThanOrEqual(1);

        $key->refresh();
        expect($key->status)->toBe('ready')
            ->and($key->source_url)->toBe('https://v3.fal.media/files/qk/polled.jpg')
            ->and(AiSpendLedger::count())->toBeGreaterThanOrEqual($ledgerBefore) // loops may follow; the image itself is not paid again
            ->and(AiSpendLedger::where('pet_media_id', $key->id)->count())->toBe(1);
    });

    it('a request still running at fal waits; after 2 h it times out (cost kept); a late webhook still stores it', function () {
        qkFakeFal();
        $pet = qkBornPet();
        $key = qkKey($pet);
        Http::fake(['queue.fal.run/fal-ai/nano-banana-pro/requests/*/status' => Http::response(['status' => 'IN_PROGRESS'])]);

        $this->travel(61)->minutes();
        app(PetMediaService::class)->sweepStale();
        expect($key->fresh()->status)->toBe('running');

        $this->travel(60)->minutes();
        expect(app(PetMediaService::class)->sweepStale()['timed_out'])->toBeGreaterThanOrEqual(1)
            ->and($key->fresh()->status)->toBe('failed')
            ->and($key->fresh()->error_reason)->toBe('timed_out')
            ->and(AiSpendLedger::where('pet_media_id', $key->id)->where('status', AiSpendLedger::STATUS_COMMITTED)->count())->toBe(1);

        qkWebhook($key->fresh(), 'late');
        expect($key->fresh()->status)->toBe('ready');
    });

    it('fal reports the polled request failed → the slot fails', function () {
        qkFakeFal();
        $pet = qkBornPet();
        $key = qkKey($pet);
        Http::fake([
            "queue.fal.run/fal-ai/nano-banana-pro/requests/{$key->request_id}/status" => Http::response(['status' => 'COMPLETED']),
            "queue.fal.run/fal-ai/nano-banana-pro/requests/{$key->request_id}" => Http::response(['detail' => 'content policy'], 422),
            'queue.fal.run/fal-ai/nano-banana-pro/requests/*/status' => Http::response(['status' => 'IN_QUEUE']),
        ]);

        $this->travel(11)->minutes();
        app(PetMediaService::class)->sweepStale();

        expect($key->fresh()->status)->toBe('failed')
            ->and($key->fresh()->error_reason)->toBe('generation_failed');
    });
});

describe('switches', function () {
    it('state media or »School & game clips« switched off → no key / pose image submit (slot left pending)', function () {
        qkFakeFal();
        Queue::fake([GeneratePetKeyframe::class]);
        $key = qkKey(qkBornPet());

        useStateMedia(false);
        expect(app(PetMediaService::class)->generateKeyframe($key->id))->toBeTrue()
            ->and($key->fresh()->status)->toBe('pending')
            ->and(qkEditCalls())->toBe(0);

        useStateMedia();
        expect(app(PetMediaService::class)->generateKeyframe($key->id))->toBeTrue()
            ->and(qkEditCalls())->toBe(1);
    });
});
