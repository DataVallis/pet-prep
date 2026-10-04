<?php

namespace App\Services;

use App\Enums\AiSpendPurpose;
use App\Enums\BreedType;
use App\Enums\PetStateEnum;
use App\Models\Pet;
use App\Models\PetMediaJob;
use App\Services\Media\AiCallException;
use App\Services\Media\FalGateway;
use App\Services\Media\MediaProfiles;
use Illuminate\Support\Facades\Log;

/**
 * FalAiService — pet media on fal.ai.
 *
 * - Reference image (Pet DNA anchor): synchronous call (profile
 *   media.reference_image_profile), only ever executed from a queued job
 *   (never inside an HTTP request / DB transaction).
 * - State videos (image-to-video, profile media.state_video_profile): submitted
 *   to the queue API with our signed webhook; matched via pet_media_jobs.
 *
 * All HTTP goes through Media\FalGateway (spend caps + ledger, M4-07).
 * Pet DNA v2 (unique traits) lives in Media\PetDnaService; generateInitialPetDna()
 * below is the pre-M4 v1 builder (AI_PET_DNA_VERSION=1).
 */
class FalAiService
{
    public function __construct(
        private readonly FalGateway $gateway,
        private readonly MediaProfiles $profiles,
    ) {}

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
     * Generate the canonical reference image for a pet with the configured
     * profile (config media.reference_image_profile, default flux_schnell —
     * the pre-M4 request body). Every call passes the spend cap first and is
     * recorded in ai_spend_ledger (M4-07).
     *
     * @return string|null Public URL of the image, or null if disabled or a retryable failure.
     *
     * @throws AiCallException for failures a retry cannot fix (budget cap, fal balance, profile disabled)
     */
    public function generateReferenceImage(string $promptAnchor, int $seed, ?int $petId = null, ?string $negativePrompt = null): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $profile = $this->profiles->referenceImage();

        try {
            $result = $this->gateway->run(
                $profile,
                $profile->imageInput($promptAnchor, $seed, $negativePrompt),
                AiSpendPurpose::ReferenceImage,
                petId: $petId,
            );
        } catch (AiCallException $e) {
            if ($e->retryable()) {
                Log::error('FalAiService: reference image generation failed', ['pet_id' => $petId, 'reason' => $e->reason->value]);

                return null;
            }

            throw $e;
        }

        $url = $result['body']['images'][0]['url'] ?? null;

        if (! is_string($url) || ! $this->isAllowedMediaUrl($url)) {
            Log::error('FalAiService: reference image response had no usable URL', ['pet_id' => $petId]);

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

        $profile = $this->profiles->stateVideo();

        try {
            $submitted = $this->gateway->submit(
                $profile,
                $profile->videoInput($referenceImageUrl, $this->buildVideoPrompt($pet, $state), $pet->pet_dna['negative_prompt'] ?? null),
                AiSpendPurpose::StateVideo,
                $this->webhookUrl(),
                petId: $pet->id,
            );
        } catch (AiCallException $e) {
            Log::error('FalAiService: video generation was not accepted', [
                'pet_id' => $pet->id,
                'state' => $state->value,
                'reason' => $e->reason->value,
            ]);

            return null;
        }

        $requestId = $submitted['request_id'];

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
    //  Config helpers
    // ──────────────────────────────────────────────────────────────

    public function webhookUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/webhooks/fal-ai';
    }
}
