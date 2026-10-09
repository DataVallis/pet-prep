<?php

namespace App\Services;

use App\Enums\AiCallFailure;
use App\Enums\AiSpendPurpose;
use App\Enums\BreedType;
use App\Enums\PetStateEnum;
use App\Enums\Species;
use App\Models\Pet;
use App\Services\Media\AiCallException;
use App\Services\Media\FalGateway;
use App\Services\Media\MediaProfiles;
use App\Services\Media\PetAppearancePrompt;
use Illuminate\Support\Facades\Log;

/**
 * FalAiService — pet media on fal.ai.
 *
 * - Reference image (Pet DNA anchor): synchronous call (profile
 *   media.reference_image_profile), only ever executed from a queued job
 *   (never inside an HTTP request / DB transaction).
 * - State videos (image-to-video, profile media.state_video_profile): submitted
 *   to the queue API with our signed webhook; matched via pet_media.request_id
 *   (M4-03). The pipeline itself lives in Media\PetMediaService.
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
        private readonly PetAppearancePrompt $prompts,
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
        // Legacy DNA v1 has dog prompts only (M5-R06-01 / M5-R06-07): a cat never
        // gets one — PairingService builds DNA v2 for every cat.
        if ($breed->species() !== Species::Dog) {
            throw new \InvalidArgumentException("Legacy pet DNA v1 has no prompts for {$breed->value}.");
        }

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

            // Unreachable: generateInitialPetDna() refuses non-dogs.
            BreedType::DomesticCat, BreedType::MaineCoon => throw new \InvalidArgumentException("No DNA v1 prompt for {$breed->value}."),
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
            // Unreachable: generateInitialPetDna() refuses non-dogs.
            BreedType::DomesticCat, BreedType::MaineCoon => throw new \InvalidArgumentException("No DNA v1 traits for {$breed->value}."),
        };
    }

    // ──────────────────────────────────────────────────────────────
    //  Reference image (synchronous — call only from a queued job)
    // ──────────────────────────────────────────────────────────────

    /**
     * Generate the canonical reference image for a pet with the configured
     * profile (config media.reference_image_profile — Nano Banana Pro since
     * 2026-10-05). Every call passes the spend cap first and is recorded in
     * ai_spend_ledger (M4-07), linked to the pet_media slot.
     *
     * @return array{url: string, profile: string}|null fal.media URL of the image, or null on a retryable failure
     *
     * @throws AiCallException for failures a retry cannot fix (budget cap, fal balance, profile disabled)
     */
    public function generateReferenceImage(string $prompt, int $seed, ?int $petId = null, ?string $negativePrompt = null, ?int $petMediaId = null): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $profile = $this->profiles->referenceImage();

        try {
            $result = $this->gateway->run(
                $profile,
                $profile->imageInput($prompt, $seed, $negativePrompt),
                AiSpendPurpose::ReferenceImage,
                petId: $petId,
                petMediaId: $petMediaId,
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

        return ['url' => $url, 'profile' => $profile->key];
    }

    /**
     * Life-stage growth (M5-R01): the next reference image as an EDIT of the
     * previous one (image-to-image, profile media.stage_edit_profile — Nano
     * Banana Pro Edit), so the dog keeps its identity. Same budget / ledger
     * as a reference image. Returns null when the profile is disabled (the
     * caller falls back to text-to-image) or on a retryable failure.
     *
     * @return array{url: string, profile: string}|null
     *
     * @throws AiCallException budget / balance / non-retryable failures
     */
    public function editReferenceImage(string $prompt, string $sourceImageUrl, int $seed, ?int $petId = null, ?int $petMediaId = null): ?array
    {
        $profile = $this->profiles->stageEdit();

        if (! $this->isEnabled() || $profile === null) {
            return null;
        }

        try {
            $result = $this->gateway->run(
                $profile,
                $profile->editInput($prompt, $sourceImageUrl, $seed),
                AiSpendPurpose::ReferenceImage,
                petId: $petId,
                petMediaId: $petMediaId,
            );
        } catch (AiCallException $e) {
            if ($e->retryable()) {
                Log::error('FalAiService: stage image edit failed', ['pet_id' => $petId, 'reason' => $e->reason->value]);

                return null;
            }

            throw $e;
        }

        $url = $result['body']['images'][0]['url'] ?? null;

        if (! is_string($url) || ! $this->isAllowedMediaUrl($url)) {
            Log::error('FalAiService: stage image edit response had no usable URL', ['pet_id' => $petId]);

            return null;
        }

        return ['url' => $url, 'profile' => $profile->key];
    }

    // ──────────────────────────────────────────────────────────────
    //  State videos (asynchronous via queue API + signed webhook)
    // ──────────────────────────────────────────────────────────────

    /**
     * Submit an image-to-video job (profile media.state_video_profile — Kling
     * 3.0 Pro, 5 s, no audio) for one pet_media video slot. The result
     * arrives on the signed webhook and is matched by request_id. Call only
     * from a queued job (SubmitPetStateVideo), never inside a transaction.
     *
     * @return array{request_id: string, profile: string, duration_seconds: int}
     *
     * @throws AiCallException
     */
    public function submitStateVideo(Pet $pet, PetStateEnum $state, string $startImageUrl, ?int $petMediaId = null): array
    {
        $profile = $this->profiles->stateVideo();
        $dna = is_array($pet->pet_dna) ? $pet->pet_dna : [];
        $breedKey = (string) ($dna['breed'] ?? $pet->breed_type->value);
        // DNA v2 traits describe the dog; v1 pets rely on the start image alone.
        $traits = (int) ($dna['version'] ?? 1) >= 2 && is_array($dna['traits'] ?? null) ? $dna['traits'] : [];
        // M5-R06-07: cat templates for a cat (videoPrompt throws for a state the species never has).
        $species = PetAppearancePrompt::speciesOf($breedKey);

        $submitted = $this->gateway->submit(
            $profile,
            $profile->videoInput($startImageUrl, $this->prompts->videoPrompt($breedKey, $state, $traits, $pet->life_stage), $this->prompts->videoNegativePrompt($species)),
            AiSpendPurpose::StateVideo,
            $this->webhookUrl(),
            petId: $pet->id,
            petMediaId: $petMediaId,
        );

        return [
            'request_id' => $submitted['request_id'],
            'profile' => $profile->key,
            'duration_seconds' => $profile->durationSeconds,
        ];
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
     * @return array{ok: bool, video_url: string|null, error: string|null, reason: AiCallFailure|null}
     */
    public function parseWebhookResult(array $body): array
    {
        if (($body['status'] ?? null) !== 'OK') {
            $error = $body['error'] ?? 'fal.ai reported an error';

            return ['ok' => false, 'video_url' => null, 'error' => is_string($error) ? $error : 'fal.ai reported an error', 'reason' => AiCallFailure::GenerationFailed];
        }

        $payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];
        $videoUrl = $payload['video']['url'] ?? null;

        if (! is_string($videoUrl) || ! $this->isAllowedMediaUrl($videoUrl)) {
            return ['ok' => false, 'video_url' => null, 'error' => 'Missing or untrusted video URL in payload', 'reason' => AiCallFailure::InvalidResponse];
        }

        return ['ok' => true, 'video_url' => $videoUrl, 'error' => null, 'reason' => null];
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
