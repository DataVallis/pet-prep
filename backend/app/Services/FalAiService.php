<?php

namespace App\Services;

use App\Enums\BreedType;
use App\Enums\PetStateEnum;
use App\Models\Pet;
use App\Models\PetMediaJob;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * FalAiService — all interactions with the fal.ai REST API.
 *
 * - Reference image (Pet DNA anchor): synchronous call to https://fal.run/{model},
 *   only ever executed from a queued job (never inside an HTTP request / DB transaction).
 * - State videos (Kling image-to-video): submitted to the queue API
 *   https://queue.fal.run/{model}?fal_webhook=... ; the result arrives on our
 *   signed webhook and is matched via the pet_media_jobs table.
 *
 * Every pet stays visually consistent through its "Pet DNA": a fixed seed,
 * a prompt anchor, visual traits and the canonical reference image URL.
 */
class FalAiService
{
    private const SYNC_BASE_URL = 'https://fal.run';

    private const QUEUE_BASE_URL = 'https://queue.fal.run';

    private const MODEL_IMAGE = 'fal-ai/flux/schnell';

    private const MODEL_VIDEO = 'fal-ai/kling-v1.6/pro/image-to-video';

    /**
     * Check if fal.ai integration is enabled (API key configured).
     */
    public function isEnabled(): bool
    {
        return filled(config('services.fal_ai.key'));
    }

    // ──────────────────────────────────────────────────────────────
    //  Pet DNA
    // ──────────────────────────────────────────────────────────────

    /**
     * Build the Pet DNA payload for a new pet. Pure and offline: no network call.
     * The reference image is generated later by GeneratePetReferenceImageJob.
     *
     * @return array{seed: int, visual_traits: array<string, string>, prompt_anchor: string, reference_image_url: null}
     */
    public function generateInitialPetDna(BreedType $breed): array
    {
        $seed = random_int(1, 4294967295);

        return [
            'seed' => $seed,
            'visual_traits' => $this->buildVisualTraits($breed, $seed),
            'prompt_anchor' => $this->buildPromptAnchor($breed),
            'reference_image_url' => null,
        ];
    }

    private function buildPromptAnchor(BreedType $breed): string
    {
        return match ($breed) {
            BreedType::Mutt => 'A friendly medium-sized mutt dog with a mix of golden brown and white fur, '
                .'short smooth coat, amber eyes, floppy ears, white blaze on chest, '
                .'photorealistic, studio quality, natural lighting',

            BreedType::BorderCollie => 'A beautiful Border Collie dog with classic black and white markings, '
                .'medium-length double coat, bright intelligent brown eyes, erect expressive ears, '
                .'white blaze on face, photorealistic, studio quality, natural lighting',
        };
    }

    /**
     * @return array<string, string>
     */
    private function buildVisualTraits(BreedType $breed, int $seed): array
    {
        $variantIndex = $seed % 3;

        return match ($breed) {
            BreedType::Mutt => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'golden brown with white patches',
                    1 => 'black and tan with white chest',
                    2 => 'cream and brown mixed',
                },
                'eye_color' => 'amber',
                'fur_texture' => 'short and smooth',
                'markings' => match ($variantIndex) {
                    0 => 'white blaze on chest',
                    1 => 'white paws and tail tip',
                    2 => 'brown mask around eyes',
                },
            ],
            BreedType::BorderCollie => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'classic black and white',
                    1 => 'black white and tan tri-color',
                    2 => 'blue merle and white',
                },
                'eye_color' => 'brown',
                'fur_texture' => 'medium-length double coat',
                'markings' => match ($variantIndex) {
                    0 => 'white blaze on face and white collar',
                    1 => 'full white chest and white socks',
                    2 => 'merle patches on body',
                },
            ],
        };
    }

    // ──────────────────────────────────────────────────────────────
    //  Reference image (synchronous — call only from a queued job)
    // ──────────────────────────────────────────────────────────────

    /**
     * Generate the canonical reference image for a pet.
     *
     * @return string|null Public URL of the image, or null if disabled/failed.
     */
    public function generateReferenceImage(string $promptAnchor, int $seed): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        try {
            $response = $this->http()->timeout(60)->post($this->syncUrl(self::MODEL_IMAGE), [
                'prompt' => $promptAnchor,
                'seed' => $seed,
                'image_size' => ['width' => 1024, 'height' => 1024],
                'num_inference_steps' => 4,
                'num_images' => 1,
                'enable_safety_checker' => true,
            ]);
        } catch (\Throwable $e) {
            Log::error('FalAiService: reference image request failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::error('FalAiService: reference image generation failed', ['status' => $response->status()]);

            return null;
        }

        $url = $response->json('images.0.url');

        if (! is_string($url) || ! $this->isAllowedMediaUrl($url)) {
            Log::error('FalAiService: reference image response had no usable URL');

            return null;
        }

        return $url;
    }

    // ──────────────────────────────────────────────────────────────
    //  State videos (asynchronous via queue API + signed webhook)
    // ──────────────────────────────────────────────────────────────

    /**
     * Submit a Kling image-to-video job for the given pet state.
     * The request is recorded in pet_media_jobs; the result arrives on the webhook.
     *
     * @return string|null The fal.ai request ID, or null if disabled/failed.
     */
    public function generatePetVideoState(Pet $pet, PetStateEnum $state): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $referenceImageUrl = $pet->pet_dna['reference_image_url'] ?? null;

        if (! $referenceImageUrl) {
            Log::warning('FalAiService: cannot generate video without a reference image', ['pet_id' => $pet->id]);

            return null;
        }

        try {
            $response = $this->http()
                ->withQueryParameters(['fal_webhook' => $this->webhookUrl()])
                ->post($this->queueUrl(self::MODEL_VIDEO), [
                    'image_url' => $referenceImageUrl,
                    'prompt' => $this->buildVideoPrompt($pet, $state),
                    'duration' => '5',
                    'aspect_ratio' => '9:16',
                    'cfg_scale' => 0.7,
                ]);
        } catch (\Throwable $e) {
            Log::error('FalAiService: video request failed', ['pet_id' => $pet->id, 'error' => $e->getMessage()]);

            return null;
        }

        $requestId = $response->successful() ? $response->json('request_id') : null;

        if (! is_string($requestId) || $requestId === '') {
            Log::error('FalAiService: video generation was not accepted', [
                'pet_id' => $pet->id,
                'state' => $state->value,
                'status' => $response->status(),
            ]);

            return null;
        }

        PetMediaJob::create([
            'pet_id' => $pet->id,
            'request_id' => $requestId,
            'kind' => PetMediaJob::KIND_VIDEO,
            'pet_state' => $state->value,
            'status' => PetMediaJob::STATUS_PENDING,
        ]);

        return $requestId;
    }

    private function buildVideoPrompt(Pet $pet, PetStateEnum $state): string
    {
        $promptAnchor = $pet->pet_dna['prompt_anchor'] ?? '';

        return $promptAnchor.'. '.$state->promptModifier().'. '
            .'Maintain exact visual consistency with the reference image. '
            .'Cinematic quality, smooth motion, 5 second loop.';
    }

    // ──────────────────────────────────────────────────────────────
    //  Webhook payload
    // ──────────────────────────────────────────────────────────────

    /**
     * Interpret a (signature-verified) fal.ai webhook body.
     *
     * Format: {request_id, gateway_request_id, status: "OK"|"ERROR", payload, error?}
     *
     * @param  array<string, mixed>  $body
     * @return array{ok: bool, video_url: string|null, error: string|null}
     */
    public function parseWebhookResult(array $body): array
    {
        if (($body['status'] ?? null) !== 'OK') {
            $error = $body['error'] ?? 'fal.ai reported an error';

            return ['ok' => false, 'video_url' => null, 'error' => is_string($error) ? $error : 'fal.ai reported an error'];
        }

        $payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];
        $videoUrl = $payload['video']['url'] ?? null;

        if (! is_string($videoUrl) || ! $this->isAllowedMediaUrl($videoUrl)) {
            return ['ok' => false, 'video_url' => null, 'error' => 'Missing or untrusted video URL in payload'];
        }

        return ['ok' => true, 'video_url' => $videoUrl, 'error' => null];
    }

    /**
     * Only accept HTTPS media served from fal.ai-owned hosts. Defence in depth:
     * a URL shown full-screen to a child must never point somewhere arbitrary.
     */
    public function isAllowedMediaUrl(string $url): bool
    {
        // Characters that parse differently in PHP vs. WHATWG URL parsers (React Native,
        // browsers) — e.g. "https://evil.com\\@v3.fal.media" — are rejected outright.
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\\\\@\s]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);

        if ($parts === false
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }

        $host = strtolower($parts['host']);

        foreach ((array) config('services.fal_ai.media_hosts', ['fal.media']) as $allowed) {
            $allowed = strtolower((string) $allowed);
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    // ──────────────────────────────────────────────────────────────
    //  HTTP & config helpers
    // ──────────────────────────────────────────────────────────────

    private function http(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => 'Key '.config('services.fal_ai.key'),
        ])->acceptJson()->asJson()->timeout(120);
    }

    private function syncUrl(string $model): string
    {
        return self::SYNC_BASE_URL.'/'.$model;
    }

    private function queueUrl(string $model): string
    {
        return self::QUEUE_BASE_URL.'/'.$model;
    }

    public function webhookUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/webhooks/fal-ai';
    }
}
