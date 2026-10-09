<?php

use App\Enums\BreedType;
use App\Enums\PetStateEnum;
use App\Enums\Species;
use App\Events\PetUpdated;
use App\Jobs\GeneratePetReferenceImage;
use App\Jobs\StorePetMedia;
use App\Models\Pet;
use App\Models\PetMedia;
use App\Models\User;
use App\Services\FalAiService;
use App\Services\FalWebhookVerifier;
use App\Services\Media\PetMediaService;
use App\Services\PairingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\call;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| FalAiService & Webhook Feature Tests
|--------------------------------------------------------------------------
|
| Tests covering the Pet DNA generation, fal.ai video state generation,
| and the asynchronous webhook handling flow.
|
*/

describe('FalAiService (disabled in test environment)', function () {
    it('generates Pet DNA with seed, prompt anchor, and visual traits even when fal.ai is disabled', function () {
        $service = app(FalAiService::class);

        // In test environment, FAL_AI_API_KEY is empty so fal.ai is disabled,
        // but generateInitialPetDna still produces seed + prompt_anchor + visual_traits
        $dna = $service->generateInitialPetDna(BreedType::Mutt);

        expect($dna['seed'])->toBeInt();
        expect($dna['prompt_anchor'])->toBeString();
        expect($dna['prompt_anchor'])->toContain('mutt');
        expect($dna['visual_traits'])->toBeArray();
        expect($dna['visual_traits'])->toHaveKey('color_scheme');
        expect($dna['visual_traits'])->toHaveKey('eye_color');
        expect($dna['visual_traits'])->toHaveKey('fur_texture');
        expect($dna['visual_traits'])->toHaveKey('markings');

        // reference_image_url is null when fal.ai is disabled
        expect($dna['reference_image_url'])->toBeNull();
    });

    it('generates breed-specific prompt anchors', function () {
        $service = app(FalAiService::class);

        $muttDna = $service->generateInitialPetDna(BreedType::Mutt);
        $collieDna = $service->generateInitialPetDna(BreedType::BorderCollie);

        expect($muttDna['prompt_anchor'])->toContain('mutt');
        expect($muttDna['prompt_anchor'])->toContain('golden brown');

        expect($collieDna['prompt_anchor'])->toContain('Border Collie');
        expect($collieDna['prompt_anchor'])->toContain('black and white');
    });

    it('fails a video slot as disabled when fal.ai is not configured', function () {
        Http::fake();
        $pet = Pet::factory()->withPetDna()->create(['user_id' => User::factory()->child()->create()->id]);
        PetMedia::create(['pet_id' => $pet->id, 'kind' => 'image', 'status' => 'ready', 'storage_path' => "{$pet->id}/reference-g1.jpg", 'mime' => 'image/jpeg']);
        $slot = PetMedia::create(['pet_id' => $pet->id, 'kind' => 'video', 'state' => 'idle', 'status' => 'pending']);

        expect(app(PetMediaService::class)->submitVideo($slot->id))->toBeTrue();

        expect($slot->fresh())->status->toBe('failed')->error_reason->toBe('disabled');
        Http::assertNothingSent();
    });

    it('keeps a video slot pending while the reference image is not stored', function () {
        config(['services.fal_ai.key' => 'test-key']);
        Http::fake();
        $pet = Pet::factory()->withPetDna()->create(['user_id' => User::factory()->child()->create()->id]);
        $slot = PetMedia::create(['pet_id' => $pet->id, 'kind' => 'video', 'state' => 'idle', 'status' => 'pending']);

        expect(app(PetMediaService::class)->submitVideo($slot->id))->toBeTrue();

        expect($slot->fresh())->status->toBe('pending')->request_id->toBeNull();
        Http::assertNothingSent();
    });
});

describe('PetStateEnum', function () {
    it('has all required pet states', function () {
        // Six classic states + the M5-R02 behaviour videos (accident, chewing) + the cat's scratching (M5-R06-07).
        expect(PetStateEnum::cases())->toHaveCount(9);
        expect(PetStateEnum::Scratching->value)->toBe('scratching');
        expect(PetStateEnum::Accident->value)->toBe('accident');
        expect(PetStateEnum::Chewing->value)->toBe('chewing');
        expect(PetStateEnum::Idle->value)->toBe('idle');
        expect(PetStateEnum::Sleeping->value)->toBe('sleeping');
        expect(PetStateEnum::LowEnergy->value)->toBe('low_energy');
        expect(PetStateEnum::Hungry->value)->toBe('hungry');
        expect(PetStateEnum::Sick->value)->toBe('sick');
        expect(PetStateEnum::Playing->value)->toBe('playing');
    });

    it('provides prompt modifiers for each state', function () {
        foreach (PetStateEnum::cases() as $state) {
            // M5-R06-07: per species — a dog has no scratching, a cat no accident / chewing.
            foreach (Species::cases() as $species) {
                if ($state->appliesTo($species)) {
                    expect($state->promptModifier($species))->toBeString()->not->toBeEmpty();
                }
            }
        }
    });
});

// Webhook signing helpers (falTestKeyPair, fakeFalJwks, sendFalWebhook) live in tests/Pest.php.

/**
 * A state video slot submitted to fal and waiting for its webhook (M4-03).
 */
function pendingVideoSlot(array $petAttributes = [], string $requestId = 'req-video-1', string $state = 'idle'): PetMedia
{
    $user = User::factory()->child()->create();
    $pet = Pet::factory()->withPetDna()->create(array_merge(['user_id' => $user->id], $petAttributes));

    return PetMedia::create([
        'pet_id' => $pet->id,
        'kind' => PetMedia::KIND_VIDEO,
        'state' => $state,
        'status' => PetMedia::STATUS_RUNNING,
        'request_id' => $requestId,
        'profile' => 'kling_v3_pro',
    ]);
}

beforeEach(function () {
    Cache::forget(FalWebhookVerifier::CACHE_KEY);
    // Downloads (StorePetMedia) are covered in PetMediaPipelineTest.
    Queue::fake([StorePetMedia::class]);
});

describe('POST /api/webhooks/fal-ai (signed)', function () {
    it('accepts a correctly signed OK webhook and sets the pet video', function () {
        fakeFalJwks();
        $job = pendingVideoSlot();

        sendFalWebhook([
            'request_id' => 'req-video-1',
            'gateway_request_id' => 'req-video-1',
            'status' => 'OK',
            'payload' => ['video' => ['url' => 'https://v3.fal.media/files/dog/idle.mp4']],
        ])->assertOk()->assertJson(['pet_id' => $job->pet_id]);

        // Only recorded; StorePetMedia downloads it — the apps never get the fal URL.
        expect($job->fresh())->status->toBe(PetMedia::STATUS_RUNNING)->source_url->toBe('https://v3.fal.media/files/dog/idle.mp4');
        Queue::assertPushed(StorePetMedia::class, fn ($q) => $q->petMediaId === $job->id);
    });

    it('rejects unsigned webhooks (fail closed)', function () {
        fakeFalJwks();
        $job = pendingVideoSlot();

        postJson('/api/webhooks/fal-ai', [
            'request_id' => 'req-video-1',
            'status' => 'OK',
            'payload' => ['video' => ['url' => 'https://v3.fal.media/files/x.mp4']],
        ])->assertStatus(401);

        expect($job->fresh()->source_url)->toBeNull();
        Queue::assertNothingPushed();
    });

    it('rejects webhooks signed with a different key', function () {
        fakeFalJwks();
        $job = pendingVideoSlot();

        sendFalWebhook([
            'request_id' => 'req-video-1',
            'status' => 'OK',
            'payload' => ['video' => ['url' => 'https://v3.fal.media/files/x.mp4']],
        ], keyPair: sodium_crypto_sign_keypair())->assertStatus(401);

        expect($job->fresh())->status->toBe(PetMedia::STATUS_RUNNING)->source_url->toBeNull();
    });

    it('rejects a tampered body', function () {
        fakeFalJwks();
        pendingVideoSlot();

        $body = ['request_id' => 'req-video-1', 'status' => 'OK', 'payload' => ['video' => ['url' => 'https://v3.fal.media/files/a.mp4']]];
        $raw = json_encode($body);
        $timestamp = (string) now()->timestamp;
        $message = implode("\n", ['req-video-1', 'user-123', $timestamp, hash('sha256', $raw)]);
        $signature = bin2hex(sodium_crypto_sign_detached($message, sodium_crypto_sign_secretkey(falTestKeyPair())));

        $tampered = json_encode(array_replace_recursive($body, ['payload' => ['video' => ['url' => 'https://v3.fal.media/files/evil.mp4']]]));

        call('POST', '/api/webhooks/fal-ai', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_FAL_WEBHOOK_REQUEST_ID' => 'req-video-1',
            'HTTP_X_FAL_WEBHOOK_USER_ID' => 'user-123',
            'HTTP_X_FAL_WEBHOOK_TIMESTAMP' => $timestamp,
            'HTTP_X_FAL_WEBHOOK_SIGNATURE' => $signature,
        ], $tampered)->assertStatus(401);
    });

    it('rejects stale timestamps (replay protection)', function () {
        fakeFalJwks();
        pendingVideoSlot();

        sendFalWebhook([
            'request_id' => 'req-video-1',
            'status' => 'OK',
            'payload' => ['video' => ['url' => 'https://v3.fal.media/files/x.mp4']],
        ], timestamp: now()->subMinutes(10)->timestamp)->assertStatus(401);
    });

    it('rejects when the signed request id differs from the body', function () {
        fakeFalJwks();
        pendingVideoSlot();
        pendingVideoSlot(requestId: 'req-video-2');

        sendFalWebhook([
            'request_id' => 'req-video-2',
            'status' => 'OK',
            'payload' => ['video' => ['url' => 'https://v3.fal.media/files/x.mp4']],
        ], ['X-Fal-Webhook-Request-Id' => 'req-video-1'])->assertStatus(401);
    });

    it('fails closed when the JWKS cannot be fetched', function () {
        Http::fake(['rest.fal.ai/*' => Http::response('down', 503)]);
        pendingVideoSlot();

        sendFalWebhook([
            'request_id' => 'req-video-1',
            'status' => 'OK',
            'payload' => ['video' => ['url' => 'https://v3.fal.media/files/x.mp4']],
        ])->assertStatus(401);
    });

    it('returns 404 for a request id we never created', function () {
        fakeFalJwks();

        sendFalWebhook([
            'request_id' => 'req-unknown',
            'status' => 'OK',
            'payload' => ['video' => ['url' => 'https://v3.fal.media/files/x.mp4']],
        ])->assertStatus(404);
    });

    it('records ERROR results without touching the pet', function () {
        fakeFalJwks();
        $job = pendingVideoSlot();
        $job->update(['storage_path' => "{$job->pet_id}/idle-g1.mp4", 'mime' => 'video/mp4']); // older generation stays servable

        sendFalWebhook([
            'request_id' => 'req-video-1',
            'status' => 'ERROR',
            'error' => 'Invalid status code: 422',
            'payload' => ['detail' => 'bad input'],
        ])->assertOk()->assertJson(['message' => 'Failure recorded.']);

        expect($job->fresh())->status->toBe(PetMedia::STATUS_FAILED)
            ->error_reason->toBe('generation_failed')
            ->error->toBe('Invalid status code: 422')
            ->storage_path->toBe("{$job->pet_id}/idle-g1.mp4");
        Queue::assertNothingPushed();
    });

    it('refuses video URLs outside fal.ai media hosts', function () {
        fakeFalJwks();
        $job = pendingVideoSlot();

        sendFalWebhook([
            'request_id' => 'req-video-1',
            'status' => 'OK',
            'payload' => ['video' => ['url' => 'https://evil.example.com/v.mp4']],
        ])->assertOk();

        expect($job->fresh())->status->toBe(PetMedia::STATUS_FAILED)->error_reason->toBe('invalid_response')->source_url->toBeNull();
        Queue::assertNothingPushed();
    });

    it('is idempotent for repeated deliveries', function () {
        fakeFalJwks();
        $job = pendingVideoSlot();
        $body = ['request_id' => 'req-video-1', 'status' => 'OK', 'payload' => ['video' => ['url' => 'https://v3.fal.media/files/first.mp4']]];

        sendFalWebhook($body)->assertOk();
        $body['payload']['video']['url'] = 'https://v3.fal.media/files/second.mp4';
        sendFalWebhook($body)->assertOk()->assertJson(['message' => 'Already processed.']);

        expect($job->fresh()->source_url)->toBe('https://v3.fal.media/files/first.mp4');
        Queue::assertPushed(StorePetMedia::class, 1);
    });

    it('answers unsigned malformed requests with a bare 401 (no schema leak)', function () {
        fakeFalJwks();

        postJson('/api/webhooks/fal-ai', ['status' => 'COMPLETED'])
            ->assertStatus(401)
            ->assertJsonMissingPath('errors');
    });

    it('validates the payload shape of signed requests', function () {
        fakeFalJwks();

        sendFalWebhook(['request_id' => 'req-x', 'status' => 'COMPLETED'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    });

    it('rejects timestamps too far in the future', function () {
        fakeFalJwks();
        pendingVideoSlot();

        sendFalWebhook([
            'request_id' => 'req-video-1',
            'status' => 'OK',
            'payload' => ['video' => ['url' => 'https://v3.fal.media/files/x.mp4']],
        ], timestamp: now()->addMinutes(10)->timestamp)->assertStatus(401);
    });

    it('caches the JWKS across webhooks', function () {
        fakeFalJwks();
        pendingVideoSlot();
        pendingVideoSlot(requestId: 'req-video-2');

        foreach (['req-video-1', 'req-video-2'] as $id) {
            sendFalWebhook(['request_id' => $id, 'status' => 'OK', 'payload' => ['video' => ['url' => "https://v3.fal.media/files/{$id}.mp4"]]])->assertOk();
        }

        Http::assertSentCount(1);
    });

    it('picks up rotated keys with a single refresh', function () {
        $oldKeys = sodium_crypto_sign_keypair();
        $newKeys = sodium_crypto_sign_keypair();
        Cache::put(FalWebhookVerifier::CACHE_KEY, [base64_encode(sodium_crypto_sign_publickey($oldKeys))], 3600);
        fakeFalJwks($newKeys);
        $job = pendingVideoSlot();

        sendFalWebhook([
            'request_id' => 'req-video-1',
            'status' => 'OK',
            'payload' => ['video' => ['url' => 'https://v3.fal.media/files/rotated.mp4']],
        ], keyPair: $newKeys)->assertOk();

        expect($job->fresh()->source_url)->toBe('https://v3.fal.media/files/rotated.mp4');
        Http::assertSentCount(1);
    });

    it('does not let bad signatures force repeated JWKS fetches', function () {
        fakeFalJwks();
        pendingVideoSlot();
        $body = ['request_id' => 'req-video-1', 'status' => 'OK', 'payload' => ['video' => ['url' => 'https://v3.fal.media/files/x.mp4']]];

        foreach (range(1, 5) as $i) {
            sendFalWebhook($body, keyPair: sodium_crypto_sign_keypair())->assertStatus(401);
        }

        Http::assertSentCount(1);
    });

    it('does not broadcast on the webhook — the app hears about the video once it is stored', function () {
        fakeFalJwks();
        Event::fake([PetUpdated::class]);
        pendingVideoSlot();

        sendFalWebhook([
            'request_id' => 'req-video-1',
            'status' => 'OK',
            'payload' => ['video' => ['url' => 'https://v3.fal.media/files/x.mp4']],
        ])->assertOk();

        Event::assertNotDispatched(PetUpdated::class);
    });
});

describe('Media URL allowlist', function () {
    it('accepts https URLs on fal.media and its subdomains', function (string $url) {
        expect(app(FalAiService::class)->isAllowedMediaUrl($url))->toBeTrue();
    })->with([
        'https://fal.media/files/a.mp4',
        'https://v3.fal.media/files/a.mp4',
        'HTTPS://V3.FAL.MEDIA/files/a.mp4',
    ]);

    it('rejects URLs that could resolve elsewhere', function (string $url) {
        expect(app(FalAiService::class)->isAllowedMediaUrl($url))->toBeFalse();
    })->with([
        'http://v3.fal.media/files/a.mp4',
        'https://evil.com\@v3.fal.media/x.mp4',
        'https://evil.com@v3.fal.media/x.mp4',
        'https://user:pass@v3.fal.media/x.mp4',
        'https://v3.fal.media:8443/x.mp4',
        'https://fal.media.evil.com/x.mp4',
        'https://evilfal.media/x.mp4',
        'javascript:alert(1)',
        'https://v3.fal.media/a b.mp4',
        '',
    ]);
});

describe('Asynchronous media generation', function () {
    it('does not call fal.ai during pairing and queues the reference image job', function () {
        config(['services.fal_ai.key' => 'test-key']);
        Queue::fake();
        Http::fake();
        seedBreedConfigs();

        $parent = createParentUser();
        $child = createChildUser();
        $pin = app(PairingService::class)->generatePin($parent)['pin'];

        $this->actingAs($child)->postJson('/api/child/pair', ['pin' => $pin])->assertCreated();

        $pet = $child->activePet();
        expect($pet->media_status)->toBe('pending');
        Http::assertNothingSent();
        Queue::assertPushed(GeneratePetReferenceImage::class, fn ($job) => $job->petId === $pet->id);
    });

    it('marks media as disabled and queues nothing when fal.ai is not configured', function () {
        config(['services.fal_ai.key' => null]);
        Queue::fake();
        seedBreedConfigs();

        $parent = createParentUser();
        $child = createChildUser();
        $pin = app(PairingService::class)->generatePin($parent)['pin'];

        $this->actingAs($child)->postJson('/api/child/pair', ['pin' => $pin])->assertCreated();

        expect($child->activePet()->media_status)->toBe('disabled');
        Queue::assertNothingPushed();
    });

    it('generates the reference image with the configured profile and queues the download', function () {
        config(['services.fal_ai.key' => 'test-key', 'media.reference_image_profile' => 'flux_schnell']);
        Http::fake([
            'fal.run/fal-ai/flux/schnell' => Http::response(['images' => [['url' => 'https://v3.fal.media/files/ref.jpg']]]),
        ]);
        $user = User::factory()->child()->create();
        $pet = Pet::factory()->withPetDna()->create(['user_id' => $user->id, 'media_status' => 'pending']);

        (new GeneratePetReferenceImage($pet->id))->handle(app(PetMediaService::class));

        $pet->refresh();
        expect($pet->media_status)->toBe('pending'); // ready once stored (M4-05)
        expect($pet->pet_dna['reference_image_url'])->toBe('https://v3.fal.media/files/ref.jpg');
        expect(PetMedia::sole())->kind->toBe('image')->status->toBe('running')->source_url->toBe('https://v3.fal.media/files/ref.jpg');
        Queue::assertPushed(StorePetMedia::class, 1);
        Http::assertSent(fn ($request) => $request->url() === 'https://fal.run/fal-ai/flux/schnell'
            && $request->header('Authorization')[0] === 'Key test-key');
    });

    it('throws so the queue retries when image generation fails, and marks failed at the end', function () {
        config(['services.fal_ai.key' => 'test-key']);
        Http::fake(['fal.run/*' => Http::response(['detail' => 'error'], 500)]);
        $user = User::factory()->child()->create();
        $pet = Pet::factory()->withPetDna()->create(['user_id' => $user->id, 'media_status' => 'pending']);
        $job = new GeneratePetReferenceImage($pet->id);

        expect(fn () => $job->handle(app(PetMediaService::class)))->toThrow(RuntimeException::class);
        expect(PetMedia::sole()->status)->toBe('pending'); // given back for the next attempt

        $job->failed(new RuntimeException('gave up'));
        expect($pet->fresh()->media_status)->toBe('failed')
            ->and(PetMedia::sole())->status->toBe('failed')->error_reason->toBe('http_error');
    });

    it('records the request id on the video slot and points fal at our webhook', function () {
        config(['services.fal_ai.key' => 'test-key', 'app.url' => 'https://api.petprep.si']);
        Http::fake([
            'queue.fal.run/*' => Http::response(['request_id' => 'req-abc', 'status' => 'IN_QUEUE']),
        ]);
        $user = User::factory()->child()->create();
        $pet = Pet::factory()->withPetDna()->create(['user_id' => $user->id]);
        PetMedia::create(['pet_id' => $pet->id, 'kind' => 'image', 'status' => 'ready', 'storage_path' => "{$pet->id}/reference-g1.jpg", 'mime' => 'image/jpeg']);
        $slot = PetMedia::create(['pet_id' => $pet->id, 'kind' => 'video', 'state' => 'hungry', 'status' => 'pending']);

        app(PetMediaService::class)->submitVideo($slot->id);

        expect($slot->fresh())->request_id->toBe('req-abc')->state->toBe('hungry')->status->toBe(PetMedia::STATUS_RUNNING);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'fal_webhook='.urlencode('https://api.petprep.si/api/webhooks/fal-ai')));
    });
});
