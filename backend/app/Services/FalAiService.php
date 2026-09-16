<?php

namespace App\Services;

use App\Enums\BreedType;
use App\Enums\PetStateEnum;
use App\Models\Pet;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * FalAiService — Encapsulates all interactions with the fal.ai REST API.
 *
 * fal.ai is our primary AI model orchestrator, handling:
 * - Kling 3.0 for video generation (state-dependent video loops)
 * - Flux / NanoBanana for image generation (canonical reference images)
 *
 * Every pet generated in PetPrep must be 100% visually consistent across
 * every image and video state. This is achieved via the "Pet DNA" architecture:
 * a fixed seed + prompt anchor + reference image URL that are passed to every
 * fal.ai generation request.
 */
class FalAiService
{
    /**
     * The fal.ai API base URL.
     */
    private const FAL_AI_BASE_URL = 'https://queue.fal.run';

    /**
     * The fal.ai model endpoints.
     */
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
    //  Pet DNA Generation
    // ──────────────────────────────────────────────────────────────

    /**
     * Generate the initial Pet DNA payload for a new pet.
     *
     * This creates:
     * - A unique fixed seed for reproducible generation
     * - A prompt anchor describing the pet's exact visual features
     * - Visual traits (color, markings, eye color, fur texture)
     * - A canonical reference image URL (generated via Flux)
     *
     * @return array{
     *     seed: int,
     *     visual_traits: array<string, string>,
     *     prompt_anchor: string,
     *     reference_image_url: string|null
     * }
     */
    public function generateInitialPetDna(BreedType $breed): array
    {
        // Generate a unique deterministic seed
        $seed = random_int(1, 4294967295);

        // Build the breed-specific prompt anchor
        $promptAnchor = $this->buildPromptAnchor($breed);
        $visualTraits = $this->buildVisualTraits($breed, $seed);

        // Generate the canonical reference image via fal.ai
        $referenceImageUrl = $this->generateReferenceImage($promptAnchor, $seed);

        return [
            'seed' => $seed,
            'visual_traits' => $visualTraits,
            'prompt_anchor' => $promptAnchor,
            'reference_image_url' => $referenceImageUrl,
        ];
    }

    /**
     * Build a breed-specific prompt anchor describing the pet's exact appearance.
     * This is the base prompt used for ALL subsequent image/video generations
     * to maintain 100% visual consistency.
     */
    private function buildPromptAnchor(BreedType $breed): string
    {
        return match ($breed) {
            BreedType::Mutt => 'A friendly medium-sized mutt dog with a mix of golden brown and white fur, '
                . 'short smooth coat, amber eyes, floppy ears, white blaze on chest, '
                . 'photorealistic, studio quality, natural lighting',

            BreedType::BorderCollie => 'A beautiful Border Collie dog with classic black and white markings, '
                . 'medium-length double coat, bright intelligent brown eyes, erect expressive ears, '
                . 'white blaze on face, photorealistic, studio quality, natural lighting',
        };
    }

    /**
     * Generate visual traits based on breed and seed.
     *
     * @return array<string, string>
     */
    private function buildVisualTraits(BreedType $breed, int $seed): array
    {
        // Use the seed to deterministically select trait variations
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
    //  Image Generation (Flux)
    // ──────────────────────────────────────────────────────────────

    /**
     * Generate the canonical reference image for a pet using Flux.
     * This image becomes the Image-to-Video anchor for all Kling 3.0 video generation.
     *
     * @return string|null The public URL of the generated image, or null if disabled/failed.
     */
    public function generateReferenceImage(string $promptAnchor, int $seed): ?string
    {
        if (! $this->isEnabled()) {
            Log::info('FalAiService: Skipping reference image generation (disabled in this environment).');

            return null;
        }

        try {
            $response = $this->http()
                ->post($this->modelUrl(self::MODEL_IMAGE), [
                    'prompt' => $promptAnchor,
                    'seed' => $seed,
                    'image_size' => [
                        'width' => 1024,
                        'height' => 1024,
                    ],
                    'num_inference_steps' => 4,
                    'format' => 'jpeg',
                ]);

            if ($response->successful()) {
                return $response->json('images.0.url');
            }

            Log::error('FalAiService: Reference image generation failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('FalAiService: Reference image generation exception', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  Video Generation (Kling 3.0)
    // ──────────────────────────────────────────────────────────────

    /**
     * Trigger a Kling 3.0 Image-to-Video generation job on fal.ai.
     *
     * Uses the pet's reference_image_url as the visual anchor and pet_dna.seed
     * to maintain exact pet appearance consistency across all video states.
     *
     * The generation runs asynchronously. When the video is ready, fal.ai will
     * call our webhook (POST /api/webhooks/fal-ai) with the result.
     *
     * @return string|null The fal.ai request ID for tracking, or null if disabled/failed.
     */
    public function generatePetVideoState(Pet $pet, PetStateEnum $state): ?string
    {
        if (! $this->isEnabled()) {
            Log::info('FalAiService: Skipping video generation (disabled in this environment).', [
                'pet_id' => $pet->id,
                'state' => $state->value,
            ]);

            return null;
        }

        $referenceImageUrl = $pet->pet_dna['reference_image_url'] ?? null;

        if (! $referenceImageUrl) {
            Log::warning('FalAiService: Cannot generate video — no reference image URL in pet_dna', [
                'pet_id' => $pet->id,
            ]);

            return null;
        }

        $prompt = $this->buildVideoPrompt($pet, $state);

        try {
            $response = $this->http()
                ->withQueryParameters([
                    'fal_webhook' => $this->webhookUrl($pet->id),
                ])
                ->post($this->modelUrl(self::MODEL_VIDEO), [
                    'image_url' => $referenceImageUrl,
                    'prompt' => $prompt,
                    'seed' => $pet->pet_dna['seed'] ?? random_int(1, 4294967295),
                    'duration' => '5',
                    'aspect_ratio' => '9:16',
                    'cfg_scale' => 0.7,
                ]);

            if ($response->successful()) {
                return $response->json('request_id');
            }

            Log::error('FalAiService: Video generation request failed', [
                'pet_id' => $pet->id,
                'state' => $state->value,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('FalAiService: Video generation exception', [
                'pet_id' => $pet->id,
                'state' => $state->value,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Build the full video generation prompt by combining the pet's
     * prompt_anchor with the state-specific prompt modifier.
     */
    private function buildVideoPrompt(Pet $pet, PetStateEnum $state): string
    {
        $promptAnchor = $pet->pet_dna['prompt_anchor'] ?? '';

        return $promptAnchor . '. ' . $state->promptModifier() . '. '
            . 'Maintain exact visual consistency with the reference image. '
            . 'Cinematic quality, smooth motion, 5 second loop.';
    }

    // ──────────────────────────────────────────────────────────────
    //  Webhook Payload Processing
    // ──────────────────────────────────────────────────────────────

    /**
     * Process an incoming fal.ai webhook payload.
     *
     * Extracts the video URL from the completed webhook and returns it
     * for the controller to update the pet and broadcast.
     *
     * @param  array<string, mixed>  $payload
     * @return array{video_url: string|null, request_id: string|null}
     */
    public function processWebhookPayload(array $payload): array
    {
        $requestId = $payload['request_id'] ?? null;
        $status = $payload['status'] ?? null;

        // fal.ai webhook sends status 'COMPLETED' when the job is done
        if ($status !== 'COMPLETED') {
            Log::info('FalAiService: Webhook received with non-completed status', [
                'request_id' => $requestId,
                'status' => $status,
            ]);

            return ['video_url' => null, 'request_id' => $requestId];
        }

        // Extract the video URL from the payload
        $videoUrl = $payload['video']['url']
            ?? $payload['output']['video_url']
            ?? $payload['data']['video']['url']
            ?? null;

        return ['video_url' => $videoUrl, 'request_id' => $requestId];
    }

    // ──────────────────────────────────────────────────────────────
    //  HTTP & Config Helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * Get an authenticated HTTP client instance for fal.ai API calls.
     */
    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => 'Key ' . config('services.fal_ai.key'),
            'Content-Type' => 'application/json',
        ])->timeout(120);
    }

    /**
     * Build the full URL for a fal.ai model endpoint.
     */
    private function modelUrl(string $model): string
    {
        return self::FAL_AI_BASE_URL . '/' . $model;
    }

    /**
     * Build the webhook URL that fal.ai should call when a job completes.
     */
    private function webhookUrl(int $petId): string
    {
        $baseUrl = rtrim(config('app.url'), '/');

        return $baseUrl . '/api/webhooks/fal-ai?pet_id=' . $petId;
    }
}
