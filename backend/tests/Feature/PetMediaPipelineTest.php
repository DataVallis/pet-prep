<?php

use App\Enums\PetStateEnum;
use App\Events\PetUpdated;
use App\Filament\Resources\PetResource\Pages\EditPet;
use App\Filament\Resources\PetResource\RelationManagers\MediaRelationManager;
use App\Http\Controllers\PetMediaController;
use App\Jobs\GeneratePetReferenceImage;
use App\Jobs\StorePetMedia;
use App\Jobs\SubmitPetStateVideo;
use App\Models\AiSpendLedger;
use App\Models\Pet;
use App\Models\PetMedia;
use App\Models\User;
use App\Services\FalWebhookVerifier;
use App\Services\FamilyService;
use App\Services\Media\FalGateway;
use App\Services\Media\MediaDownloader;
use App\Services\Media\MediaDownloadException;
use App\Services\Media\MediaEntitlementService;
use App\Services\Media\PetMediaService;
use App\Services\PairingService;
use Carbon\CarbonInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/*
|--------------------------------------------------------------------------
| M4 part B — state videos at birth (M4-03), our own media storage + signed
| URLs (M4-05), entitlement, retries, backfill, Filament media panel.
|--------------------------------------------------------------------------
*/

const PM_JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";
const PM_MP4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom";

function pmJpeg(): string
{
    return PM_JPEG.str_repeat("\x00", 256);
}

function pmMp4(): string
{
    return PM_MP4.str_repeat("\x00", 512);
}

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
        'media.budget.daily_usd' => 5.0,
        'media.budget.monthly_usd' => 50.0,
        'media.budget.timezone' => 'UTC',
        'media.storage.url_ttl_minutes' => 60,
    ]);
    seedBreedConfigs();
});

/**
 * A born pet of a child in a parent's family. M3-11 P6: the full media tier
 * needs a purchased challenge — the mutt here is a free-plan pet (basic
 * set), every other breed a purchased challenge (full set).
 */
function pmFamilyPet(string $breed = 'mutt', array $dna = []): array
{
    $parent = createParentUser();
    $child = createChildUser(['parent_id' => $parent->id]);
    $factory = Pet::factory()->withPetDna($dna);
    $factory = $breed === 'mutt' ? $factory->freePlan() : $factory->purchased();
    $pet = $factory->create(['user_id' => $child->id, 'breed_type' => $breed, 'media_status' => 'pending']);

    return [$parent, $child, $pet];
}

/** A stored media slot (file on the fake disk). */
function pmStored(Pet $pet, string $kind = 'image', ?string $state = null, int $generation = 1): PetMedia
{
    $ext = $kind === 'image' ? 'jpg' : 'mp4';
    $path = sprintf('%d/%s-g%d.%s', $pet->id, $kind === 'image' ? 'reference' : $state, $generation, $ext);
    Storage::disk('pet_media')->put($path, $kind === 'image' ? pmJpeg() : pmMp4());

    return PetMedia::create([
        'pet_id' => $pet->id,
        'kind' => $kind,
        'state' => $state,
        'status' => 'ready',
        'generation' => $generation,
        'source_generation' => $kind === 'video' ? 1 : null,
        'storage_path' => $path,
        'mime' => $kind === 'image' ? 'image/jpeg' : 'video/mp4',
        'bytes' => strlen($kind === 'image' ? pmJpeg() : pmMp4()),
        'profile' => $kind === 'image' ? 'nano_banana_pro' : 'kling_v3_pro',
    ]);
}

/** Path + query of one of our absolute signed URLs. */
function pmPath(string $url): string
{
    $parts = parse_url($url);

    return $parts['path'].'?'.$parts['query'];
}

/* ─────────────────────────── End-to-end pipeline ─────────────────────────── */

describe('pipeline at birth', function () {
    it('goes pairing → reference image → stored → contract (birth) → entitled videos → webhook → stored → signed URLs in the state', function () {
        fakeFalJwks();
        Http::fake([
            'fal.run/fal-ai/nano-banana-pro' => Http::response(['images' => [['url' => 'https://v3.fal.media/files/dog/ref.jpg']]]),
            'v3.fal.media/files/dog/ref.jpg' => Http::response(pmJpeg(), 200, ['Content-Type' => 'image/jpeg']),
            'queue.fal.run/fal-ai/kling-video/v3/pro/image-to-video*' => Http::sequence()
                ->push(['request_id' => 'req-idle'])
                ->push(['request_id' => 'req-sleeping']),
            'v3.fal.media/files/dog/idle.mp4' => Http::response(pmMp4(), 200, ['Content-Type' => 'video/mp4']),
            'v3.fal.media/files/dog/sleeping.mp4' => Http::response(pmMp4(), 200, ['Content-Type' => 'video/mp4']),
        ]);
        Event::fake([PetUpdated::class]);

        $parent = createParentUser();
        withoutQuietHours($parent); // wall clock: at night the pet would show `sleeping`
        $child = createChildUser();
        $pin = app(PairingService::class)->generatePin($parent)['pin'];
        $this->actingAs($child)->postJson('/api/child/pair', ['pin' => $pin])->assertCreated();

        $pet = $child->activePet();
        $image = PetMedia::where('pet_id', $pet->id)->images()->sole();

        // Reference image: Nano Banana Pro with the DNA v2 prompt, stored on our disk.
        Http::assertSent(fn (Request $r) => $r->url() === 'https://fal.run/fal-ai/nano-banana-pro'
            && $r['prompt'] === $pet->pet_dna['prompt'] && $r['aspect_ratio'] === '9:16' && $r['resolution'] === '1K' && ! isset($r['negative_prompt']));
        expect($image)->status->toBe('ready')->profile->toBe('nano_banana_pro')->mime->toBe('image/jpeg')->cost_usd->toEqualWithDelta(0.15, 1e-9)
            ->and(Storage::disk('pet_media')->exists($image->storage_path))->toBeTrue()
            ->and($pet->fresh()->media_status)->toBe('ready');

        // Unborn (contract not signed yet): no videos, no Kling call.
        expect(PetMedia::where('pet_id', $pet->id)->videos()->count())->toBe(0);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'queue.fal.run'));

        // First contract = birth → the free mutt's basic set: idle + sleeping.
        // M3-11: the deprecated /child/pair path creates a challenge pet (full
        // set); this test is about the pipeline, so the pet is made free here.
        Pet::whereKey($pet->id)->update(['plan' => 'free', 'challenge_paid_at' => null, 'challenge_paid_source' => null]);
        app('auth')->forgetGuards();
        $this->actingAs($child)->postJson('/api/child/contract', ['signature_format' => 'svg_path', 'signature' => 'M10 10 L20 20'])->assertCreated();

        $videos = PetMedia::where('pet_id', $pet->id)->videos()->orderBy('id')->get();
        expect($videos->pluck('state')->all())->toBe(['idle', 'sleeping'])
            ->and($videos->pluck('status')->unique()->all())->toBe(['running'])
            ->and($videos->pluck('request_id')->all())->toBe(['req-idle', 'req-sleeping']);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://queue.fal.run/fal-ai/kling-video/v3/pro/image-to-video?fal_webhook=')
            && str_starts_with($r['start_image_url'], "https://api.petprep.si/api/media/{$image->id}?")
            && str_contains($r['prompt'], 'Static locked-off camera') && str_contains($r['negative_prompt'], 'camera movement')
            && $r['generate_audio'] === false && $r['duration'] === '5');

        foreach (['idle', 'sleeping'] as $state) {
            sendFalWebhook([
                'request_id' => "req-{$state}",
                'status' => 'OK',
                'payload' => ['video' => ['url' => "https://v3.fal.media/files/dog/{$state}.mp4"]],
            ])->assertOk();
        }

        expect(PetMedia::where('pet_id', $pet->id)->videos()->pluck('status')->unique()->all())->toBe(['ready']);
        expect((float) AiSpendLedger::where('pet_id', $pet->id)->counted()->sum('cost_usd'))->toEqualWithDelta(0.15 + 2 * 0.56, 1e-9);

        // One broadcast per stored file (image + 2 videos) + the birth.
        Event::assertDispatchedTimes(PetUpdated::class, 4);
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->eventType === 'video_ready' && $e->payload['media']['status'] === 'ready');

        // The child's state carries only our signed URLs.
        app('auth')->forgetGuards();
        $state = $this->actingAs($child)->getJson('/api/child/pet')->assertOk()->json('pet');

        expect($state['media']['status'])->toBe('ready')
            ->and($state['media']['states'])->toBe(['idle', 'sleeping'])
            ->and(array_keys($state['media']['videos']))->toBe(['idle', 'sleeping'])
            ->and($state['media']['current_video_url'])->toBe($state['media']['videos']['idle'])
            ->and($state['current_video_url'])->toBe($state['media']['current_video_url'])
            ->and($state['reference_image_url'])->toBe($state['media']['reference_image_url'])
            ->and($state['media']['reference_image_url'])->toStartWith("https://api.petprep.si/api/media/{$image->id}?")
            ->and(json_encode($state))->not->toContain('fal.media');

        app('auth')->forgetGuards();
        $this->get(pmPath($state['media']['videos']['sleeping']))->assertOk()->assertHeader('Content-Type', 'video/mp4');
    });

    it('queues no videos for an unborn pet — not from the stored image, the backfill or the panel', function () {
        Queue::fake([SubmitPetStateVideo::class]);
        [, , $pet] = pmFamilyPet();
        $pet->update(['born_at' => null]);
        pmStored($pet);

        expect(app(PetMediaService::class)->queueStateVideos($pet->fresh()))->toBe(0)
            ->and(app(PetMediaService::class)->planMissing($pet->fresh()))->toMatchArray(['image' => 'ok', 'videos' => [], 'cost_usd' => 0.0])
            ->and(app(PetMediaService::class)->generateMissing($pet->fresh()))->toBe(['image' => false, 'videos' => 0]);
        Queue::assertNothingPushed();
        expect(PetMedia::where('pet_id', $pet->id)->videos()->count())->toBe(0);
    });

    it('queues the videos when the first contract births the pet, and not again for a second caretaker', function () {
        Queue::fake([SubmitPetStateVideo::class]);
        [$parent, $first, $pet] = pmFamilyPet();
        $pet->update(['born_at' => null, 'last_decay_at' => null]);
        pmStored($pet);
        $second = createChildUser(['parent_id' => $parent->id]);
        app(FamilyService::class)->addCaretaker($pet, $second, requiresContract: true);
        $svg = ['signature_format' => 'svg_path', 'signature' => 'M10 10 L20 20'];

        $this->actingAs($first, 'sanctum')->postJson('/api/child/contract', $svg)->assertCreated();

        expect($pet->fresh()->born_at)->not->toBeNull();
        Queue::assertPushed(SubmitPetStateVideo::class, 2);
        expect(PetMedia::where('pet_id', $pet->id)->videos()->orderBy('id')->pluck('state')->all())->toBe(['idle', 'sleeping']);

        app('auth')->forgetGuards();
        $this->actingAs($second, 'sanctum')->postJson('/api/child/contract', $svg)->assertCreated();

        Queue::assertPushed(SubmitPetStateVideo::class, 2); // no duplicate jobs
        expect(PetMedia::where('pet_id', $pet->id)->videos()->count())->toBe(2);
    });

    it('queues the videos once the image is stored when the contract was signed first', function () {
        Queue::fake([SubmitPetStateVideo::class]);
        Http::fake(['v3.fal.media/*' => Http::response(pmJpeg(), 200, ['Content-Type' => 'image/jpeg'])]);
        [, , $pet] = pmFamilyPet(); // born
        $image = PetMedia::create(['pet_id' => $pet->id, 'kind' => 'image', 'status' => 'running', 'source_url' => 'https://v3.fal.media/files/late.jpg']);

        app(PetMediaService::class)->storeResult($image->id);

        Queue::assertPushed(SubmitPetStateVideo::class, 2);
    });

    it('gives a paid breed (premium_unlock) all six state videos', function () {
        Queue::fake([SubmitPetStateVideo::class]);
        [, , $pet] = pmFamilyPet('border_collie');
        pmStored($pet);

        expect(app(MediaEntitlementService::class)->tierFor($pet))->toBe('full')
            ->and(app(PetMediaService::class)->queueStateVideos($pet))->toBe(6);

        expect(PetMedia::where('pet_id', $pet->id)->videos()->orderBy('id')->pluck('state')->all())
            ->toBe(['idle', 'sleeping', 'low_energy', 'hungry', 'sick', 'playing']);
        Queue::assertPushed(SubmitPetStateVideo::class, 6);
    });

    it('defines the entitlement sets in config with idle always first', function () {
        config(['media.video_states.basic' => ['sleeping', 'nonsense']]);

        expect(MediaEntitlementService::statesOfTier('basic'))->toBe([PetStateEnum::Idle, PetStateEnum::Sleeping])
            // Six classic states + the M5-R02 behaviour videos + the cat's scratching (M5-R06-07),
            // filtered per pet (and species) by MediaEntitlementService.
            ->and(MediaEntitlementService::statesOfTier('full'))->toHaveCount(9);
    });

    it('builds per-state video prompts from the DNA: same dog, subtle motion, static camera, no people or text', function () {
        Http::fake(['queue.fal.run/*' => Http::response(['request_id' => 'req-x'])]);
        [, , $pet] = pmFamilyPet('mutt', ['version' => 2, 'breed' => 'mutt', 'traits' => ['size' => 'small', 'coat_color' => 'cream', 'coat_length' => 'short'], 'prompt' => 'p']);
        pmStored($pet);
        $slot = PetMedia::create(['pet_id' => $pet->id, 'kind' => 'video', 'state' => 'hungry', 'status' => 'pending']);

        app(PetMediaService::class)->submitVideo($slot->id);

        Http::assertSent(function (Request $r) {
            $prompt = $r['prompt'];

            return str_contains($prompt, 'The same small')
                && str_contains($prompt, 'short coat in cream')
                && str_contains($prompt, 'empty dog food bowl')
                && str_contains($prompt, 'Static locked-off camera')
                && str_contains($prompt, 'No people')
                && str_contains($prompt, 'no text');
        });
    });
});

/* ─────────────────────────── Idempotency ─────────────────────────── */

describe('idempotency', function () {
    it('creates each slot once and submits each video once', function () {
        Http::fake(['queue.fal.run/*' => Http::response(['request_id' => 'req-once'])]);
        Queue::fake([SubmitPetStateVideo::class]);
        [, , $pet] = pmFamilyPet();
        pmStored($pet);
        $service = app(PetMediaService::class);

        expect($service->queueStateVideos($pet))->toBe(2);
        expect($service->queueStateVideos($pet))->toBe(2); // still never submitted → re-dispatch is harmless
        expect(PetMedia::where('pet_id', $pet->id)->videos()->count())->toBe(2);

        $idle = PetMedia::where('pet_id', $pet->id)->where('state', 'idle')->sole();
        $service->submitVideo($idle->id);
        $service->submitVideo($idle->id); // claimed already → no second fal call

        Http::assertSentCount(1);
        expect($service->queueStateVideos($pet))->toBe(1); // only sleeping is still unsubmitted
        expect(AiSpendLedger::count())->toBe(1);
    });

    it('stores a webhook result once even when fal delivers twice', function () {
        fakeFalJwks();
        Http::fake(['v3.fal.media/*' => Http::response(pmMp4(), 200, ['Content-Type' => 'video/mp4'])]);
        [, , $pet] = pmFamilyPet();
        $slot = PetMedia::create(['pet_id' => $pet->id, 'kind' => 'video', 'state' => 'idle', 'status' => 'running', 'request_id' => 'req-dup']);
        $body = ['request_id' => 'req-dup', 'status' => 'OK', 'payload' => ['video' => ['url' => 'https://v3.fal.media/files/a.mp4']]];

        sendFalWebhook($body)->assertOk();
        sendFalWebhook($body)->assertOk()->assertJson(['message' => 'Already processed.']);

        expect($slot->fresh())->status->toBe('ready')->storage_path->toBe("{$pet->id}/idle-g1.mp4");
        Http::assertSent(fn (Request $r) => $r->url() === 'https://v3.fal.media/files/a.mp4');
        expect(collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'v3.fal.media'))->count())->toBe(1);
    });

    it('acknowledges a late webhook of a regenerated slot as superseded, but not one that beat our own submit', function () {
        fakeFalJwks();
        [, , $pet] = pmFamilyPet();
        $slot = PetMedia::create(['pet_id' => $pet->id, 'kind' => 'video', 'state' => 'idle', 'status' => 'running', 'request_id' => 'req-new']);
        AiSpendLedger::create(['purpose' => 'state_video', 'profile' => 'kling_v3_pro', 'endpoint' => 'x/y', 'unit' => 'second', 'units' => 5, 'cost_usd' => 0.56, 'status' => 'committed', 'request_id' => 'req-old', 'pet_id' => $pet->id, 'pet_media_id' => $slot->id]);

        sendFalWebhook(['request_id' => 'req-old', 'status' => 'OK', 'payload' => ['video' => ['url' => 'https://v3.fal.media/files/old.mp4']]])
            ->assertOk()->assertJson(['message' => 'Superseded.']);

        // fal answered before submitVideo stored the request id: 404 → fal delivers again.
        $slot->update(['request_id' => null]);
        AiSpendLedger::create(['purpose' => 'state_video', 'profile' => 'kling_v3_pro', 'endpoint' => 'x/y', 'unit' => 'second', 'units' => 5, 'cost_usd' => 0.56, 'status' => 'committed', 'request_id' => 'req-early', 'pet_id' => $pet->id, 'pet_media_id' => $slot->id]);
        sendFalWebhook(['request_id' => 'req-early', 'status' => 'OK', 'payload' => ['video' => ['url' => 'https://v3.fal.media/files/e.mp4']]])
            ->assertNotFound();
    });
});

/* ─────────────────────────── Budget + retry ─────────────────────────── */

describe('budget', function () {
    it('fails a video blocked by the daily cap without calling fal, and media:retry re-queues it the next day', function () {
        Http::fake(['queue.fal.run/*' => Http::response(['request_id' => 'req-later'])]);
        [, , $pet] = pmFamilyPet();
        pmStored($pet);
        $slot = PetMedia::create(['pet_id' => $pet->id, 'kind' => 'video', 'state' => 'idle', 'status' => 'pending']);
        AiSpendLedger::create(['purpose' => 'reference_image', 'profile' => 'nano_banana_pro', 'endpoint' => 'x/y', 'unit' => 'image', 'units' => 1, 'cost_usd' => 4.9, 'status' => 'committed']);

        app(PetMediaService::class)->submitVideo($slot->id);

        Http::assertNothingSent();
        expect($slot->fresh())->status->toBe('failed')->error_reason->toBe('budget_daily')
            ->and($pet->fresh()->media_status)->toBe('pending'); // the game is untouched

        // Same day: still no room.
        Queue::fake([SubmitPetStateVideo::class]);
        $this->artisan('media:retry')->assertSuccessful()->expectsOutputToContain('0 state video(s)');
        Queue::assertNothingPushed();

        $this->travel(1)->days();
        $this->artisan('media:retry')->assertSuccessful()->expectsOutputToContain('1 state video(s)');
        Queue::assertPushed(SubmitPetStateVideo::class, fn ($job) => $job->petMediaId === $slot->id);
        expect($slot->fresh())->status->toBe('pending')->error_reason->toBeNull();

        app(PetMediaService::class)->submitVideo($slot->id);
        expect($slot->fresh())->status->toBe('running')->request_id->toBe('req-later');
    });

    it('does not auto-retry a video that fal reported as failed', function () {
        Queue::fake([SubmitPetStateVideo::class]);
        [, , $pet] = pmFamilyPet();
        pmStored($pet);
        PetMedia::create(['pet_id' => $pet->id, 'kind' => 'video', 'state' => 'idle', 'status' => 'failed', 'error_reason' => 'generation_failed']);

        $this->artisan('media:retry')->assertSuccessful();

        Queue::assertNothingPushed();
    });

    it('keeps the cost when the submit timed out after sending, without retrying blindly', function () {
        Http::fake(['queue.fal.run/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds')]);
        [, , $pet] = pmFamilyPet();
        pmStored($pet);
        $slot = PetMedia::create(['pet_id' => $pet->id, 'kind' => 'video', 'state' => 'idle', 'status' => 'pending']);

        expect(app(PetMediaService::class)->submitVideo($slot->id))->toBeTrue(); // no queue retry

        expect($slot->fresh())->status->toBe('failed')->error_reason->toBe('http_error')->cost_usd->toEqualWithDelta(0.56, 1e-9);
    });

    it('lets the queue retry a video when fal answered 5xx (cost voided)', function () {
        Http::fake(['queue.fal.run/*' => Http::response('down', 503)]);
        [, , $pet] = pmFamilyPet();
        pmStored($pet);
        $slot = PetMedia::create(['pet_id' => $pet->id, 'kind' => 'video', 'state' => 'idle', 'status' => 'pending']);
        $job = new SubmitPetStateVideo($slot->id);

        expect(fn () => $job->handle(app(PetMediaService::class)))->toThrow(RuntimeException::class);
        expect($slot->fresh())->status->toBe('pending')->cost_usd->toEqualWithDelta(0.0, 1e-9);

        $job->failed(new RuntimeException('gave up'));
        expect($slot->fresh())->status->toBe('failed')->error_reason->toBe('http_error');
    });
});

/* ─────────────────────────── Download (M4-05) ─────────────────────────── */

describe('download to our storage', function () {
    function pmRunning(Pet $pet, string $url, string $kind = 'video'): PetMedia
    {
        return PetMedia::create([
            'pet_id' => $pet->id,
            'kind' => $kind,
            'state' => $kind === 'video' ? 'idle' : null,
            'status' => 'running',
            'request_id' => 'req-'.uniqid(),
            'source_url' => $url,
        ]);
    }

    it('refuses a source outside the fal media allowlist without any request', function () {
        Http::fake();
        [, , $pet] = pmFamilyPet();
        $slot = pmRunning($pet, 'https://evil.example.com/dog.mp4');

        expect(app(PetMediaService::class)->storeResult($slot->id))->toBeTrue();

        Http::assertNothingSent();
        expect($slot->fresh())->status->toBe('failed')->error_reason->toBe('invalid_response')->storage_path->toBeNull();
        expect(Storage::disk('pet_media')->allFiles())->toBe([]);
    });

    it('refuses files over the size limit', function () {
        config(['media.storage.max_video_bytes' => 100]);
        Http::fake(['v3.fal.media/*' => Http::response(pmMp4(), 200, ['Content-Type' => 'video/mp4'])]);
        [, , $pet] = pmFamilyPet();
        $slot = pmRunning($pet, 'https://v3.fal.media/files/big.mp4');

        app(PetMediaService::class)->storeResult($slot->id);

        expect($slot->fresh())->status->toBe('failed')->error->toContain('too large');
        expect(Storage::disk('pet_media')->allFiles())->toBe([]);
    });

    it('refuses content that is not the expected media type', function (string $body, string $declared, string $error) {
        Http::fake(['v3.fal.media/*' => Http::response($body, 200, ['Content-Type' => $declared])]);
        [, , $pet] = pmFamilyPet();
        $slot = pmRunning($pet, 'https://v3.fal.media/files/x.mp4');

        app(PetMediaService::class)->storeResult($slot->id);

        expect($slot->fresh())->status->toBe('failed')->error->toContain($error);
        expect(Storage::disk('pet_media')->allFiles())->toBe([]);
    })->with([
        'html page' => ['<html><body>not a video</body></html>', 'text/html', 'Unexpected content type text/html'],
        'image instead of video' => [PM_JPEG.str_repeat("\x00", 64), 'image/jpeg', 'Unexpected content type image/jpeg'],
        'declared type contradicts bytes' => [PM_MP4.str_repeat("\x00", 64), 'image/png', 'does not match'],
        'empty' => ['', 'video/mp4', 'empty'],
    ]);

    it('accepts application/octet-stream when the bytes are an mp4', function () {
        Http::fake(['v3.fal.media/*' => Http::response(pmMp4(), 200, ['Content-Type' => 'application/octet-stream'])]);
        [, , $pet] = pmFamilyPet();
        $slot = pmRunning($pet, 'https://v3.fal.media/files/ok.mp4');

        app(PetMediaService::class)->storeResult($slot->id);

        expect($slot->fresh())->status->toBe('ready')->mime->toBe('video/mp4')->bytes->toBe(strlen(pmMp4()));
    });

    it('fails permanently on 404 (and forgets a dead reference URL), retries on 5xx', function () {
        [, , $pet] = pmFamilyPet('mutt', ['reference_image_url' => 'https://v3.fal.media/files/gone.jpg']);
        Http::fake([
            'v3.fal.media/files/gone.jpg' => Http::response('nope', 404),
            'v3.fal.media/files/flaky.mp4' => Http::response('busy', 502),
        ]);
        $image = pmRunning($pet, 'https://v3.fal.media/files/gone.jpg', 'image');

        expect(app(PetMediaService::class)->storeResult($image->id))->toBeTrue();
        expect($image->fresh()->status)->toBe('failed')
            ->and($pet->fresh())->media_status->toBe('failed')->media_error->toBe('invalid_response')
            ->and($pet->fresh()->pet_dna['reference_image_url'])->toBeNull();

        $video = pmRunning($pet, 'https://v3.fal.media/files/flaky.mp4');
        expect(fn () => (new StorePetMedia($video->id))->handle(app(PetMediaService::class)))->toThrow(RuntimeException::class);
        expect($video->fresh()->status)->toBe('running'); // queue retries
    });

    it('downloads a pre-M4-05 pet\'s existing fal reference image instead of paying for a new one', function () {
        Queue::fake([SubmitPetStateVideo::class]);
        Http::fake(['v3.fal.media/files/old-ref.jpg' => Http::response(pmJpeg(), 200, ['Content-Type' => 'image/jpeg'])]);
        [, , $pet] = pmFamilyPet('mutt', ['reference_image_url' => 'https://v3.fal.media/files/old-ref.jpg']);
        $pet->update(['media_status' => 'ready']);

        (new GeneratePetReferenceImage($pet->id))->handle(app(PetMediaService::class));

        expect(PetMedia::where('pet_id', $pet->id)->images()->sole())->status->toBe('ready')->profile->toBe('legacy')->cost_usd->toEqualWithDelta(0, 1e-9)
            ->and(AiSpendLedger::count())->toBe(0);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'fal.run'));
        Queue::assertPushed(SubmitPetStateVideo::class, 2);
    });

    it('keeps the old video playable while a regeneration runs and deletes it once the new one is stored', function () {
        fakeFalJwks();
        Http::fake([
            'queue.fal.run/*' => Http::response(['request_id' => 'req-regen']),
            'v3.fal.media/files/new.mp4' => Http::response(pmMp4().'new', 200, ['Content-Type' => 'video/mp4']),
        ]);
        [, , $pet] = pmFamilyPet();
        pmStored($pet);
        $old = pmStored($pet, 'video', 'idle');
        $oldPath = $old->storage_path;

        expect(app(PetMediaService::class)->regenerate($old))->toBeTrue(); // sync queue → submitted
        expect($old->fresh())->status->toBe('running')->generation->toBe(2)->storage_path->toBe($oldPath)
            ->and((array) app(PetMediaService::class)->payloadFor($pet->fresh())['videos'])->toHaveKey('idle');

        sendFalWebhook(['request_id' => 'req-regen', 'status' => 'OK', 'payload' => ['video' => ['url' => 'https://v3.fal.media/files/new.mp4']]])->assertOk();

        expect($old->fresh())->status->toBe('ready')->storage_path->toBe("{$pet->id}/idle-g2.mp4");
        expect(Storage::disk('pet_media')->exists($oldPath))->toBeFalse()
            ->and(Storage::disk('pet_media')->exists("{$pet->id}/idle-g2.mp4"))->toBeTrue();
    });
});

/* ─────────────────────────── Signed route (M4-05) ─────────────────────────── */

describe('GET /api/media/{media}', function () {
    it('serves the file for a valid signature — without a token (players), to a caretaker and to a family parent', function () {
        [$parent, $child, $pet] = pmFamilyPet();
        $video = pmStored($pet, 'video', 'idle');
        $url = pmPath(app(PetMediaService::class)->signedUrl($video));

        $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'video/mp4')->assertHeader('Accept-Ranges', 'bytes')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeaderMissing('X-Accel-Redirect'); // default serve_via = php
        expect($response->headers->get('Cache-Control'))->toContain('private')->not->toContain('public');

        $this->actingAs($child, 'sanctum')->get($url)->assertOk();
        app('auth')->forgetGuards();
        $this->actingAs($parent, 'sanctum')->get($url)->assertOk();
    });

    it('answers range requests (iOS AVPlayer)', function () {
        [, , $pet] = pmFamilyPet();
        $video = pmStored($pet, 'video', 'idle');

        $response = $this->get(pmPath(app(PetMediaService::class)->signedUrl($video)), ['Range' => 'bytes=0-7']);

        $response->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-7/'.strlen(pmMp4()));
    });

    it('refuses a signed-in user of another family, an expired, tampered or unsigned URL', function () {
        [, , $pet] = pmFamilyPet();
        $video = pmStored($pet, 'video', 'idle');
        $url = pmPath(app(PetMediaService::class)->signedUrl($video));
        [$otherParent, $otherChild] = pmFamilyPet();

        $this->actingAs($otherChild, 'sanctum')->get($url)->assertForbidden();
        app('auth')->forgetGuards();
        $this->actingAs($otherParent, 'sanctum')->get($url)->assertForbidden();
        app('auth')->forgetGuards();

        $this->get(str_replace('signature=', 'signature=0', $url))->assertForbidden();
        $this->get(preg_replace('/media\/\d+/', 'media/'.pmStored($otherChild->activePet())->id, $url))->assertForbidden(); // id swapped
        $this->get("/api/media/{$video->id}")->assertForbidden();

        $this->travel(91)->minutes();
        $this->get($url)->assertForbidden();
    });

    it('returns 404 while the slot has no stored file', function () {
        [, , $pet] = pmFamilyPet();
        $slot = PetMedia::create(['pet_id' => $pet->id, 'kind' => 'video', 'state' => 'idle', 'status' => 'running']);

        $this->get(pmPath(app(PetMediaService::class)->signedUrl($slot)))->assertNotFound();
    });

    describe('serve_via = caddy (M4-05b, production)', function () {
        beforeEach(fn () => config(['media.storage.serve_via' => 'caddy']));

        it('checks the signature, then hands the file to Caddy: X-Accel-Redirect + empty body + media headers', function () {
            [$parent, , $pet] = pmFamilyPet();
            $video = pmStored($pet, 'video', 'idle');
            $url = pmPath(app(PetMediaService::class)->signedUrl($video));

            $response = $this->get($url, ['Range' => 'bytes=0-7'])->assertOk()
                ->assertHeader('X-Accel-Redirect', "/{$pet->id}/idle-g1.mp4")
                ->assertHeader('Content-Type', 'video/mp4')
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Content-Disposition', 'inline');

            expect($response->getContent())->toBe('')
                ->and($response->headers->get('Cache-Control'))->toContain('private')->not->toContain('public');

            $this->actingAs($parent, 'sanctum')->get($url)->assertOk()->assertHeader('X-Accel-Redirect', "/{$pet->id}/idle-g1.mp4");
        });

        it('still refuses a bad signature, an unsigned URL and another family — without the internal header', function () {
            [, , $pet] = pmFamilyPet();
            $video = pmStored($pet, 'video', 'idle');
            $url = pmPath(app(PetMediaService::class)->signedUrl($video));
            [$otherParent, $otherChild] = pmFamilyPet();

            $this->get(str_replace('signature=', 'signature=0', $url))->assertForbidden()->assertHeaderMissing('X-Accel-Redirect');
            $this->get("/api/media/{$video->id}")->assertForbidden()->assertHeaderMissing('X-Accel-Redirect');
            $this->actingAs($otherChild, 'sanctum')->get($url)->assertForbidden()->assertHeaderMissing('X-Accel-Redirect');
            app('auth')->forgetGuards();
            $this->actingAs($otherParent, 'sanctum')->get($url)->assertForbidden()->assertHeaderMissing('X-Accel-Redirect');
        });

        it('returns 404 without the header while the slot has no stored file', function () {
            [, , $pet] = pmFamilyPet();
            $slot = PetMedia::create(['pet_id' => $pet->id, 'kind' => 'video', 'state' => 'idle', 'status' => 'running']);

            $this->get(pmPath(app(PetMediaService::class)->signedUrl($slot)))->assertNotFound()->assertHeaderMissing('X-Accel-Redirect');
        });

        it('never puts an unexpected path into the header — streams via PHP instead', function () {
            [, , $pet] = pmFamilyPet();
            $video = pmStored($pet, 'video', 'idle');
            Storage::disk('pet_media')->put("{$pet->id}/odd name.mp4", pmMp4());
            $video->update(['storage_path' => "{$pet->id}/odd name.mp4"]);

            $response = $this->get(pmPath(app(PetMediaService::class)->signedUrl($video->fresh())))->assertOk()
                ->assertHeaderMissing('X-Accel-Redirect');
            expect($response->baseResponse)->toBeInstanceOf(BinaryFileResponse::class);
        });

        it('only applies to a local disk', function () {
            [, , $pet] = pmFamilyPet();
            $video = pmStored($pet, 'video', 'idle');
            config(['filesystems.disks.pet_media.driver' => 's3']); // the fake disk instance is already resolved

            $this->get(pmPath(app(PetMediaService::class)->signedUrl($video)))->assertOk()->assertHeaderMissing('X-Accel-Redirect');
        });

        it('only applies to the disk root Caddy mounts (storage/app/pet-media)', function () {
            [, , $pet] = pmFamilyPet();
            $video = pmStored($pet, 'video', 'idle');
            config(['filesystems.disks.pet_media.root' => storage_path('app/elsewhere')]);

            $this->get(pmPath(app(PetMediaService::class)->signedUrl($video)))->assertOk()->assertHeaderMissing('X-Accel-Redirect');
        });

        it('accepts every path the pipeline writes', function (string $path) {
            expect(preg_match(PetMediaController::SAFE_RELATIVE_PATH, $path))->toBe(1);
        })->with(['12/reference-g1.jpg', '12/idle-g2.mp4', '7/low_energy-g10.webp', '3/playing-g1.png']);

        it('rejects anything else', function (string $path) {
            expect(preg_match(PetMediaController::SAFE_RELATIVE_PATH, $path))->toBe(0);
        })->with(['../.env', '12/../../.env', '/etc/passwd', '12/a/b.mp4', "12/x.mp4\n", '12/x y.mp4', '12/.hidden', 'abc/x.mp4', '12/x.mp4?y=1']);
    });

    it('keeps the same URL for half the TTL and a validity between 60 and 90 minutes', function () {
        $this->travelTo(now()->setTime(10, 5));
        [, , $pet] = pmFamilyPet();
        $video = pmStored($pet, 'video', 'idle');
        $service = app(PetMediaService::class);

        $first = $service->signedUrl($video);
        $this->travel(20)->minutes();
        expect($service->signedUrl($video))->toBe($first);

        parse_str(parse_url($first, PHP_URL_QUERY), $query);
        $validFor = (int) $query['expires'] - now()->subMinutes(20)->getTimestamp();
        expect($validFor)->toBeGreaterThanOrEqual(3600)->toBeLessThanOrEqual(5400);
    });
});

/* ─────────────────────────── Payloads ─────────────────────────── */

describe('media payload', function () {
    it('reports status and the video for the current pet state, falling back to idle', function () {
        [$parent, $child, $pet] = pmFamilyPet('border_collie');
        $service = app(PetMediaService::class);

        expect($service->payloadFor($pet))->toMatchArray(['status' => 'pending', 'reference_image_url' => null, 'current_video_url' => null, 'expires_at' => null])
            ->and(json_encode($service->payloadFor($pet)['videos']))->toBe('{}');

        pmStored($pet);
        pmStored($pet, 'video', 'idle');
        $hungry = pmStored($pet, 'video', 'hungry');
        $pet->update(['pet_state' => 'hungry']);
        $media = $service->payloadFor($pet->fresh());

        expect($media['status'])->toBe('partial')
            ->and($media['states'])->toBe(['idle', 'sleeping', 'low_energy', 'hungry', 'sick', 'playing'])
            ->and($media['current_video_url'])->toBe($service->signedUrl($hungry))
            ->and($media['expires_at'])->not->toBeNull();

        $pet->update(['pet_state' => 'sick']);
        expect($service->payloadFor($pet->fresh())['current_video_url'])->toBe($media['videos']->idle);

        // Parent dashboard (legacy pet + family pets) and the broadcast carry the same shape.
        $dashboard = $this->actingAs($parent)->getJson('/api/parent/dashboard')->assertOk();
        expect($dashboard->json('pet.media.status'))->toBe('partial')
            ->and($dashboard->json('pet.current_video_url'))->toBe($media['videos']->idle)
            ->and($dashboard->json('pet.reference_image_url'))->toBe($media['reference_image_url'])
            ->and($dashboard->json('family.pets.0.media.videos'))->toHaveKeys(['idle', 'hungry']);

        expect((array) PetUpdated::payloadFor($pet->fresh())['media']['videos'])->toHaveKeys(['idle', 'hungry'])
            ->and(PetUpdated::payloadFor($pet->fresh())['current_video_url'])->toBe($media['videos']->idle);
    });

    it('reports ready / failed / disabled', function () {
        [, , $pet] = pmFamilyPet();
        $service = app(PetMediaService::class);

        pmStored($pet);
        pmStored($pet, 'video', 'idle');
        pmStored($pet, 'video', 'sleeping');
        expect($service->payloadFor($pet)['status'])->toBe('ready');

        [, , $failed] = pmFamilyPet();
        $failed->update(['media_status' => 'failed', 'media_error' => 'budget_daily']);
        expect($service->payloadFor($failed)['status'])->toBe('failed');

        [, , $off] = pmFamilyPet();
        $off->update(['media_status' => 'disabled']);
        expect($service->payloadFor($off)['status'])->toBe('disabled');
    });

    it('returns media in the pairing response and never the fal URL', function () {
        Queue::fake();
        $parent = createParentUser();
        $child = createChildUser();
        $pin = app(PairingService::class)->generatePin($parent)['pin'];

        $pet = $this->actingAs($child)->postJson('/api/child/pair', ['pin' => $pin])->assertCreated()->json('pet');

        // M3-11 P6: /child/pair creates an unpaid challenge pet (trial) → the basic set.
        expect($pet['media'])->toMatchArray(['status' => 'pending', 'states' => ['idle', 'sleeping']])
            ->and($pet['pet_dna']['reference_image_url'])->toBeNull();
    });
});

/* ─────────────────────────── Backfill + Filament ─────────────────────────── */

describe('media:backfill', function () {
    it('only prints the plan and the estimate on --dry-run', function () {
        Queue::fake();
        [, , $fresh] = pmFamilyPet('border_collie');
        [, , $legacy] = pmFamilyPet('mutt', ['reference_image_url' => 'https://v3.fal.media/files/legacy.jpg']);
        [, , $done] = pmFamilyPet();
        pmStored($done);
        pmStored($done, 'video', 'idle');
        pmStored($done, 'video', 'sleeping');

        expect(Artisan::call('media:backfill', ['--dry-run' => true]))->toBe(0);
        $output = Artisan::output();

        expect($output)
            ->toMatch('/\|\s*'.$fresh->id.'\s*\|\s*border_collie\s*\|\s*full\s*\|\s*generate\s*\|\s*idle, sleeping, low_energy, hungry, sick, playing\s*\|\s*\$3\.51\s*\|\s*planned/') // 0.15 + 6 × 0.56
            ->toMatch('/\|\s*'.$legacy->id.'\s*\|\s*mutt\s*\|\s*basic\s*\|\s*download\s*\|\s*idle, sleeping\s*\|\s*\$1\.12/')                                       // free download + 2 × 0.56
            ->not->toMatch('/\|\s*'.$done->id.'\s*\|/')
            ->toContain('Dry run: 2 pet(s), estimated $4.63');

        Queue::assertNothingPushed();
        expect(PetMedia::where('pet_id', $fresh->id)->count())->toBe(0)
            ->and(AiSpendLedger::count())->toBe(0);
    });

    it('queues missing media within the budget and stops at the cap', function () {
        Queue::fake();
        [, , $a] = pmFamilyPet();
        pmStored($a);
        [, , $b] = pmFamilyPet('border_collie');
        config(['media.budget.daily_usd' => 2.0]);

        $this->artisan('media:backfill')->expectsOutputToContain('Stopped at the AI budget')->assertSuccessful();

        Queue::assertPushed(SubmitPetStateVideo::class, 2);           // pet a: idle + sleeping ($1.12)
        Queue::assertNotPushed(GeneratePetReferenceImage::class);     // pet b ($3.51) would exceed $2
    });

    it('refuses to run (not dry) when fal is disabled', function () {
        config(['services.fal_ai.key' => null]);

        $this->artisan('media:backfill')->assertFailed();
    });

    it('limits to one pet with --pet', function () {
        Queue::fake();
        [, , $a] = pmFamilyPet();
        [, , $b] = pmFamilyPet();

        $this->artisan('media:backfill', ['--pet' => $b->id])->assertSuccessful();

        Queue::assertPushed(GeneratePetReferenceImage::class, fn ($job) => $job->petId === $b->id);
        Queue::assertPushed(GeneratePetReferenceImage::class, 1);
    });
});

describe('Filament media panel', function () {
    it('shows the media of a pet with Regenerate for superadmins', function () {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
        $this->actingAs($admin);
        [, , $pet] = pmFamilyPet();
        $image = pmStored($pet);
        $video = pmStored($pet, 'video', 'idle');

        Livewire::test(MediaRelationManager::class, ['ownerRecord' => $pet, 'pageClass' => EditPet::class])
            ->assertOk()
            ->assertCanSeeTableRecords([$image, $video])
            ->assertTableActionVisible('regenerate', $video)
            ->callTableAction('regenerate', $video);

        expect($video->fresh())->status->toBe('pending')->generation->toBe(2);
        Queue::assertPushed(SubmitPetStateVideo::class, fn ($job) => $job->petMediaId === $video->id);

        Livewire::test(MediaRelationManager::class, ['ownerRecord' => $pet, 'pageClass' => EditPet::class])
            ->callTableAction('regenerate', $image);
        expect($image->fresh())->status->toBe('pending')->generation->toBe(2)
            ->and($pet->fresh()->media_status)->toBe('pending');
        Queue::assertPushed(GeneratePetReferenceImage::class, 1);
    });

    it('generates missing media from the panel', function () {
        Queue::fake();
        $this->actingAs(User::factory()->create(['role' => 'parent', 'is_superadmin' => true]));
        [, , $pet] = pmFamilyPet();
        pmStored($pet);

        Livewire::test(MediaRelationManager::class, ['ownerRecord' => $pet, 'pageClass' => EditPet::class])
            ->callTableAction('generateMissing');

        Queue::assertPushed(SubmitPetStateVideo::class, 2);
    });

    it('regenerates the videos from a new reference image once it is stored', function () {
        Queue::fake([SubmitPetStateVideo::class]);
        Http::fake(['v3.fal.media/*' => Http::response(pmJpeg(), 200, ['Content-Type' => 'image/jpeg'])]);
        [, , $pet] = pmFamilyPet();
        $image = pmStored($pet);
        $video = pmStored($pet, 'video', 'idle');
        $image->update(['generation' => 2, 'status' => 'running', 'source_url' => 'https://v3.fal.media/files/ref2.jpg']);

        app(PetMediaService::class)->storeResult($image->id);

        expect($video->fresh())->status->toBe('pending')->generation->toBe(2)->storage_path->not->toBeNull();
        Queue::assertPushed(SubmitPetStateVideo::class, 2); // idle (stale) + sleeping (new)
    });
});

/* ─────────────────────────── Sweep + cleanup ─────────────────────────── */

describe('media:sweep', function () {
    it('times out videos without a webhook, re-queues lost downloads and dead claims', function () {
        Queue::fake();
        [, , $pet] = pmFamilyPet();
        $waiting = PetMedia::create(['pet_id' => $pet->id, 'kind' => 'video', 'state' => 'idle', 'status' => 'running', 'request_id' => 'req-lost']);
        $fresh = PetMedia::create(['pet_id' => $pet->id, 'kind' => 'video', 'state' => 'sleeping', 'status' => 'running', 'request_id' => 'req-fresh']);
        $download = PetMedia::create(['pet_id' => $pet->id, 'kind' => 'video', 'state' => 'hungry', 'status' => 'running', 'request_id' => 'req-dl', 'source_url' => 'https://v3.fal.media/x.mp4']);
        $dead = PetMedia::create(['pet_id' => $pet->id, 'kind' => 'image', 'status' => 'running']);
        PetMedia::whereKey([$waiting->id, $download->id, $dead->id])->update(['updated_at' => now()->subHours(3)]);

        $this->artisan('media:sweep')->assertSuccessful()->expectsOutputToContain('Timed out 1, re-queued 1 download(s) and 1 stale claim(s), deleted 0 stale temp file(s).');

        expect($waiting->fresh())->status->toBe('failed')->error_reason->toBe('timed_out')
            ->and($fresh->fresh()->status)->toBe('running');
        Queue::assertPushed(StorePetMedia::class, fn ($job) => $job->petMediaId === $download->id);
        Queue::assertPushed(GeneratePetReferenceImage::class, fn ($job) => $job->petId === $pet->id);
    });

    it('lets a new job claim a slot a dead worker left running', function () {
        Http::fake(['fal.run/*' => Http::response(['images' => [['url' => 'https://v3.fal.media/files/again.jpg']]])]);
        Queue::fake([StorePetMedia::class]);
        [, , $pet] = pmFamilyPet();
        $slot = PetMedia::create(['pet_id' => $pet->id, 'kind' => 'image', 'status' => 'running', 'attempts' => 1]);

        (new GeneratePetReferenceImage($pet->id))->handle(app(PetMediaService::class));
        Http::assertNothingSent(); // fresh claim: somebody may still be working on it

        PetMedia::whereKey($slot->id)->update(['updated_at' => now()->subMinutes(11)]);
        (new GeneratePetReferenceImage($pet->id))->handle(app(PetMediaService::class));

        expect($slot->fresh())->attempts->toBe(2)->source_url->toBe('https://v3.fal.media/files/again.jpg');
    });

    it('is scheduled hourly', function () {
        $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command.' '.$e->expression);

        expect($events->first(fn ($e) => str_contains($e, 'media:sweep') && ! str_contains($e, 'sweep-lab')))->toContain('47 * * * *');
    });

    it('deletes the stored files with the pet', function () {
        [, , $pet] = pmFamilyPet();
        $image = pmStored($pet);

        $pet->delete();

        expect(Storage::disk('pet_media')->exists($image->storage_path))->toBeFalse()
            ->and(PetMedia::count())->toBe(0);
    });
});

/* ─────────────────────────── Migration ─────────────────────────── */

describe('pet_media migration', function () {
    it('moves pet_media_jobs rows into pet_media and back (rollback)', function () {
        [, , $pet] = pmFamilyPet();
        $migration = 'database/migrations/2026_10_07_120000_create_pet_media_table.php';

        $this->artisan('migrate:rollback', ['--path' => $migration])->assertSuccessful();
        expect(Schema::hasTable('pet_media'))->toBeFalse();

        DB::table('pet_media_jobs')->insert([
            ['pet_id' => $pet->id, 'request_id' => 'old-pending', 'kind' => 'video', 'pet_state' => 'idle', 'status' => 'pending', 'result_url' => null, 'created_at' => now(), 'updated_at' => now()],
            ['pet_id' => $pet->id, 'request_id' => 'old-done', 'kind' => 'video', 'pet_state' => 'hungry', 'status' => 'completed', 'result_url' => 'https://v3.fal.media/x.mp4', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->artisan('migrate', ['--path' => $migration])->assertSuccessful();

        expect(Schema::hasTable('pet_media_jobs'))->toBeFalse();
        // Still waiting → running with the same request id (a late webhook matches);
        // completed but never downloaded → failed (media:backfill regenerates).
        expect(PetMedia::where('state', 'idle')->sole())->status->toBe('running')->request_id->toBe('old-pending')->source_generation->toBe(1)
            ->and(PetMedia::where('state', 'hungry')->sole())->status->toBe('failed')->request_id->toBeNull()->source_generation->toBe(1);
    });

    it('allows one slot per pet, kind and state (the image state is NULL)', function () {
        [, , $pet] = pmFamilyPet();
        PetMedia::create(['pet_id' => $pet->id, 'kind' => 'image']);

        expect(fn () => PetMedia::create(['pet_id' => $pet->id, 'kind' => 'image']))->toThrow(UniqueConstraintViolationException::class);
    });
});

/* ─────────────────────────── PR #24 review fixes ─────────────────────────── */

describe('PR #24 review', function () {
    function prLedger(PetMedia $slot, string $requestId, CarbonInterface $at): AiSpendLedger
    {
        $row = AiSpendLedger::create(['purpose' => 'state_video', 'profile' => 'kling_v3_pro', 'endpoint' => 'fal-ai/kling-video/v3/pro/image-to-video', 'unit' => 'second', 'units' => 5, 'cost_usd' => 0.56, 'status' => 'committed', 'request_id' => $requestId, 'pet_id' => $slot->pet_id, 'pet_media_id' => $slot->id]);
        $row->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();

        return $row;
    }

    it('m1: a reclaimed slot adopts the request id fal already accepted from the dead worker — no second paid submit', function () {
        Http::fake(['queue.fal.run/*' => Http::response(['request_id' => 'req-second'])]);
        [, , $pet] = pmFamilyPet();
        pmStored($pet);
        $slot = PetMedia::create(['pet_id' => $pet->id, 'kind' => 'video', 'state' => 'idle', 'status' => 'running', 'attempts' => 1]);
        PetMedia::whereKey($slot->id)->update(['updated_at' => now()->subMinutes(15)]);
        prLedger($slot, 'req-dead', now()->subMinutes(14)); // accepted after the dead worker's claim

        expect(app(PetMediaService::class)->submitVideo($slot->id))->toBeTrue();

        Http::assertNothingSent();
        expect($slot->fresh())->status->toBe('running')->request_id->toBe('req-dead')->source_generation->toBe(1)->cost_usd->toEqualWithDelta(0.56, 1e-9)
            ->and(AiSpendLedger::count())->toBe(1);
    });

    it('m1: a ledger row from before the dead claim (older generation) is not adopted', function () {
        Http::fake(['queue.fal.run/*' => Http::response(['request_id' => 'req-new'])]);
        [, , $pet] = pmFamilyPet();
        pmStored($pet);
        $slot = PetMedia::create(['pet_id' => $pet->id, 'kind' => 'video', 'state' => 'idle', 'status' => 'running', 'attempts' => 1]);
        PetMedia::whereKey($slot->id)->update(['updated_at' => now()->subMinutes(15)]);
        prLedger($slot, 'req-old-generation', now()->subHours(3));

        app(PetMediaService::class)->submitVideo($slot->id);

        Http::assertSentCount(1);
        expect($slot->fresh()->request_id)->toBe('req-new');
    });

    it('m2: a late successful webhook still stores the video of a slot the sweep failed', function () {
        fakeFalJwks();
        Queue::fake([StorePetMedia::class]);
        [, , $pet] = pmFamilyPet();
        $slot = PetMedia::create(['pet_id' => $pet->id, 'kind' => 'video', 'state' => 'idle', 'status' => 'failed', 'error_reason' => 'timed_out', 'request_id' => 'req-late']);

        sendFalWebhook(['request_id' => 'req-late', 'status' => 'ERROR', 'error' => 'late error'])->assertOk()->assertJson(['message' => 'Already processed.']);
        expect($slot->fresh())->status->toBe('failed')->error_reason->toBe('timed_out');

        sendFalWebhook(['request_id' => 'req-late', 'status' => 'OK', 'payload' => ['video' => ['url' => 'https://v3.fal.media/files/late.mp4']]])
            ->assertOk()->assertJson(['pet_id' => $pet->id]);

        expect($slot->fresh())->status->toBe('running')->error_reason->toBeNull()->source_url->toBe('https://v3.fal.media/files/late.mp4');
        Queue::assertPushed(StorePetMedia::class, fn ($job) => $job->petMediaId === $slot->id);
    });

    it('m3: refuses a redirect to a host outside the allowlist — nothing stored', function () {
        $mock = new MockHandler([
            new Response(302, ['Location' => 'https://evil.example.com/dog.mp4']),
            new Response(200, ['Content-Type' => 'video/mp4'], pmMp4()),
        ]);
        app()->instance(MediaDownloader::class, app(MediaDownloader::class)->withHandler($mock));
        [, , $pet] = pmFamilyPet();
        $slot = PetMedia::create(['pet_id' => $pet->id, 'kind' => 'video', 'state' => 'idle', 'status' => 'running', 'request_id' => 'r1', 'source_url' => 'https://v3.fal.media/files/redirect.mp4']);

        expect(app(PetMediaService::class)->storeResult($slot->id))->toBeTrue();

        expect($slot->fresh())->status->toBe('failed')->error->toContain('Redirect to a host outside')->storage_path->toBeNull()
            ->and(Storage::disk('pet_media')->allFiles())->toBe([])
            ->and($mock->count())->toBe(1); // the evil host was never requested
    });

    it('m3: follows a redirect inside the allowlist', function () {
        $mock = new MockHandler([
            new Response(302, ['Location' => 'https://v2.fal.media/files/moved.mp4']),
            new Response(200, ['Content-Type' => 'video/mp4'], pmMp4()),
        ]);
        $file = app(MediaDownloader::class)->withHandler($mock)->download('https://v3.fal.media/files/a.mp4', 1024 * 1024, ['video/mp4']);

        expect($file['mime'])->toBe('video/mp4')->and(file_get_contents($file['path']))->toBe(pmMp4());
        @unlink($file['path']);
    });

    it('m3: aborts on a too large Content-Length and while streaming (progress)', function () {
        $downloader = app(MediaDownloader::class);
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'video/mp4', 'Content-Length' => '5000000'], pmMp4()),
        ]);
        $tempBefore = count(glob(sys_get_temp_dir().'/petmedia-*') ?: []);

        expect(fn () => $downloader->withHandler($mock)->download('https://v3.fal.media/files/big.mp4', 1000, ['video/mp4']))
            ->toThrow(MediaDownloadException::class, 'File too large (5000000 bytes');
        expect(count(glob(sys_get_temp_dir().'/petmedia-*') ?: []))->toBe($tempBefore); // temp file removed

        // MockHandler does not stream; the progress callback cURL calls is checked directly.
        $progress = $downloader->transferOptions('/dev/null', 1000)['progress'];
        $progress(0, 1000); // at the limit: fine
        expect(fn () => $progress(0, 1001))->toThrow(MediaDownloadException::class, 'over 1000 bytes');
    });

    it('m4: accepts a QuickTime container and stores it as video/mp4', function () {
        $qt = "\x00\x00\x00\x14ftypqt  \x00\x00\x02\x00qt  ".str_repeat("\x00", 512);
        Http::fake(['v3.fal.media/*' => Http::response($qt, 200, ['Content-Type' => 'video/quicktime'])]);
        [, , $pet] = pmFamilyPet();
        $slot = PetMedia::create(['pet_id' => $pet->id, 'kind' => 'video', 'state' => 'idle', 'status' => 'running', 'request_id' => 'rq', 'source_url' => 'https://v3.fal.media/files/clip.mov']);

        app(PetMediaService::class)->storeResult($slot->id);

        expect($slot->fresh())->status->toBe('ready')->mime->toBe('video/mp4')->storage_path->toBe("{$pet->id}/idle-g1.mp4");
        $this->get(pmPath(app(PetMediaService::class)->signedUrl($slot->fresh())))->assertOk()->assertHeader('Content-Type', 'video/mp4');
    });

    it('m7: never resets a slot another worker has pending or running', function () {
        Queue::fake([SubmitPetStateVideo::class]);
        [, , $pet] = pmFamilyPet();
        pmStored($pet);
        $slot = pmStored($pet, 'video', 'idle');
        $service = app(PetMediaService::class);

        // Loaded as ready, but a worker claimed it meanwhile.
        PetMedia::whereKey($slot->id)->update(['status' => 'running', 'request_id' => 'req-busy']);
        expect($service->resetForNewGeneration($slot, bump: true))->toBeFalse()
            ->and($slot->fresh())->status->toBe('running')->request_id->toBe('req-busy')->generation->toBe(1)
            ->and($service->regenerate($slot))->toBeFalse();

        PetMedia::whereKey($slot->id)->update(['status' => 'ready', 'request_id' => null]);
        expect($service->resetForNewGeneration($slot->fresh(), bump: true))->toBeTrue()
            ->and($slot->fresh())->status->toBe('pending')->generation->toBe(2);
        Queue::assertNothingPushed();
    });

    it('n3: the sweep deletes download temp files older than 2 h only', function () {
        $old = tempnam(sys_get_temp_dir(), 'petmedia-');
        $fresh = tempnam(sys_get_temp_dir(), 'petmedia-');
        touch($old, now()->subHours(3)->getTimestamp());

        expect(app(PetMediaService::class)->cleanupTempFiles())->toBeGreaterThanOrEqual(1);

        expect(file_exists($old))->toBeFalse()->and(file_exists($fresh))->toBeTrue();
        @unlink($fresh);
    });
});
