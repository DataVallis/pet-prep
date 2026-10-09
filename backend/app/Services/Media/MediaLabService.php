<?php

namespace App\Services\Media;

use App\Enums\AiCallFailure;
use App\Enums\AiSpendPurpose;
use App\Enums\BreedType;
use App\Enums\LifeStage;
use App\Enums\PetStateEnum;
use App\Enums\Species;
use App\Jobs\PollMediaLabResult;
use App\Jobs\RunMediaLabImage;
use App\Jobs\SubmitMediaLabVideo;
use App\Models\MediaLabResult;
use App\Models\MediaLabRun;
use App\Models\User;
use App\Services\FalAiService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * AI Lab (admin only, M4-02): compare fal.ai model profiles on DNA v2
 * prompts before David picks production models. No child or pet data:
 * samples are drawn from breed appearance config with fresh seeds.
 *
 * Image run: N samples (1–4) × M image profiles. Every sample has ONE trait
 * set + seed rendered by every chosen profile (a fair side-by-side).
 * Video run: one completed lab image × M video profiles × one pet state.
 *
 * Cats (M5-R06-07): the breed decides the species templates; an image run
 * may add a life-stage cue (kitten look, young Maine Coon); a video run
 * refuses a state the species never has. perPetCostUsd() shows what one
 * pet's media would cost with the production profiles, so the cat cost is
 * checked here before cats are switched on (CAT_SPEC §8).
 */
class MediaLabService
{
    public function __construct(
        private readonly MediaProfiles $profiles,
        private readonly PetDnaService $dna,
        private readonly PetAppearancePrompt $prompts,
        private readonly AiSpendGuard $guard,
        private readonly FalAiService $fal,
    ) {}

    /**
     * @param  list<string>  $profileKeys
     */
    public function estimateImageRunUsd(array $profileKeys, int $samples): float
    {
        return round(array_sum(array_map(
            fn (string $key) => $this->profiles->image($key)->estimatedCostUsd(),
            $profileKeys,
        )) * $samples, 4);
    }

    /**
     * @param  list<string>  $profileKeys
     */
    public function estimateVideoRunUsd(array $profileKeys): float
    {
        return round(array_sum(array_map(
            fn (string $key) => $this->profiles->video($key)->estimatedCostUsd(),
            $profileKeys,
        )), 4);
    }

    /**
     * @param  array<string, string|null>  $fixedTraits
     * @param  list<string>  $profileKeys
     *
     * @throws AiCallException when the run would exceed the per-run / daily / monthly cap
     */
    public function startImageRun(User $admin, string $breedKey, array $fixedTraits, int $samples, array $profileKeys, ?LifeStage $stage = null): MediaLabRun
    {
        $this->assertAdmin($admin);

        $breed = BreedType::tryFrom($breedKey);

        if ($breed === null) {
            throw new InvalidArgumentException("Unknown breed {$breedKey}.");
        }

        $negative = $this->prompts->negativePrompt($breed->species());

        if ($samples < 1 || $samples > (int) config('media.lab.max_samples', 4)) {
            throw new InvalidArgumentException('Samples must be between 1 and '.config('media.lab.max_samples', 4).'.');
        }

        $profiles = $this->labProfiles(ModelProfile::KIND_IMAGE, $profileKeys);
        $fixed = array_filter($fixedTraits, fn ($v) => is_string($v) && $v !== '');
        $estimate = $this->estimateImageRunUsd(array_keys($profiles), $samples);
        $this->assertAffordable($estimate);

        // Sample first (validates fixed traits) — pure, no DB.
        $drawn = [];
        $fingerprints = [];
        for ($i = 0; $i < $samples; $i++) {
            $seed = random_int(1, 4_294_967_295);
            $sample = $this->dna->sample($breedKey, $seed, $fixed, $fingerprints);
            $fingerprints[] = $sample['fingerprint'];
            // No stage = the DNA v2 prompt exactly as a new pet stores it (imagePrompt).
            $prompt = $stage === null
                ? $this->prompts->imagePrompt($breedKey, $sample['traits'])
                : $this->prompts->stagedImagePrompt($breedKey, $sample['traits'], $stage);
            $drawn[] = ['seed' => $seed, 'traits' => $sample['traits'], 'prompt' => $prompt];
        }

        $run = DB::transaction(function () use ($admin, $breedKey, $fixed, $samples, $profiles, $estimate, $drawn, $negative) {
            $run = MediaLabRun::create([
                'user_id' => $admin->id,
                'kind' => MediaLabRun::KIND_IMAGE,
                'breed' => $breedKey,
                'fixed_traits' => $fixed,
                'samples' => $samples,
                'profiles' => array_keys($profiles),
                'estimated_cost_usd' => $estimate,
            ]);

            foreach ($drawn as $index => $sample) {
                foreach ($profiles as $profile) {
                    $result = $run->results()->create([
                        'kind' => MediaLabRun::KIND_IMAGE,
                        'profile' => $profile->key,
                        'endpoint' => $profile->endpoint,
                        'sample_index' => $index,
                        'seed' => $sample['seed'],
                        'traits' => $sample['traits'],
                        'prompt' => $sample['prompt'],
                        'negative_prompt' => $profile->supportsNegativePrompt ? $negative : null,
                        'params' => $profile->imageInput($sample['prompt'], $sample['seed'], $negative),
                        'status' => MediaLabResult::STATUS_QUEUED,
                    ]);

                    RunMediaLabImage::dispatch($result->id)->afterCommit();
                }
            }

            return $run;
        });

        return $run;
    }

    /**
     * @param  list<string>  $profileKeys
     *
     * @throws AiCallException
     */
    public function startVideoRun(User $admin, int $sourceResultId, array $profileKeys, PetStateEnum $state): MediaLabRun
    {
        $this->assertAdmin($admin);

        $source = MediaLabResult::query()
            ->whereKey($sourceResultId)
            ->where('kind', MediaLabRun::KIND_IMAGE)
            ->where('status', MediaLabResult::STATUS_COMPLETED)
            ->whereNotNull('result_url')
            ->first();

        if (! $source || ! $this->fal->isAllowedMediaUrl((string) $source->result_url)) {
            throw new InvalidArgumentException('Pick a completed lab image.');
        }

        $profiles = $this->labProfiles(ModelProfile::KIND_VIDEO, $profileKeys);
        $estimate = $this->estimateVideoRunUsd(array_keys($profiles));
        $this->assertAffordable($estimate);

        $breedKey = (string) $source->run->breed;
        $species = PetAppearancePrompt::speciesOf($breedKey);

        // M5-R06-07: a cat has no accident / chewing video, a dog no scratching.
        if (! $state->appliesTo($species)) {
            throw new InvalidArgumentException("A {$species->value} has no '{$state->value}' video — pick another state.");
        }

        $prompt = $this->prompts->videoPrompt($breedKey, $state, is_array($source->traits) ? $source->traits : []);
        $negative = $this->prompts->videoNegativePrompt($species);

        return DB::transaction(function () use ($admin, $source, $profiles, $estimate, $state, $prompt, $breedKey, $negative) {
            $run = MediaLabRun::create([
                'user_id' => $admin->id,
                'kind' => MediaLabRun::KIND_VIDEO,
                'breed' => $breedKey,
                'samples' => 1,
                'profiles' => array_keys($profiles),
                'pet_state' => $state->value,
                'source_result_id' => $source->id,
                'estimated_cost_usd' => $estimate,
            ]);

            foreach ($profiles as $profile) {
                $result = $run->results()->create([
                    'kind' => MediaLabRun::KIND_VIDEO,
                    'profile' => $profile->key,
                    'endpoint' => $profile->endpoint,
                    'sample_index' => 0,
                    'traits' => $source->traits,
                    'prompt' => $prompt,
                    'negative_prompt' => $profile->supportsNegativePrompt ? $negative : null,
                    'params' => $profile->videoInput((string) $source->result_url, $prompt, $negative),
                    'source_image_url' => $source->result_url,
                    'status' => MediaLabResult::STATUS_QUEUED,
                ]);

                SubmitMediaLabVideo::dispatch($result->id)->afterCommit();
            }

            return $run;
        });
    }

    /**
     * What one pet's media costs per life stage with the PRODUCTION profiles
     * (reference image + the tier's state videos the species can get) — list
     * price, no fal call. Dogs: `full` counts the puppy accident and chewing
     * (the maximum); cats: `full` = six classic states + scratching. A stage
     * change regenerates the image and the videos (M5-R01).
     *
     * @return array{images: int, videos: int, usd: float}
     */
    public function perPetCostUsd(Species $species, string $tier): array
    {
        $states = array_values(array_filter(
            MediaEntitlementService::statesOfTier($tier),
            fn (PetStateEnum $state): bool => $state->appliesTo($species),
        ));
        $usd = $this->profiles->referenceImage()->estimatedCostUsd()
            + count($states) * $this->profiles->stateVideo()->estimatedCostUsd();

        return ['images' => 1, 'videos' => count($states), 'usd' => round($usd, 4)];
    }

    /**
     * M4-10: the one-off cost of filling a free breed's shared look pool with
     * the basic set at list price — pool size × 4 life stages × (image + the
     * basic videos). Upper bound: a stage nobody's pet reaches is never
     * generated, and a later stage image is an edit (same price today).
     * Once a look has a stage's media, every further free pet costs 0 $.
     *
     * @return array{looks: int, stages: int, per_stage_usd: float, usd: float}
     */
    public function lookPoolFillCostUsd(Species $species): array
    {
        $perStage = $this->perPetCostUsd($species, MediaEntitlementService::TIER_BASIC)['usd'];
        $looks = max(1, (int) config('media.look_pool.size', 20));
        $stages = count(LifeStage::ordered());

        return ['looks' => $looks, 'stages' => $stages, 'per_stage_usd' => $perStage, 'usd' => round($looks * $stages * $perStage, 2)];
    }

    /**
     * Queue a status poll for every running video of a run (fallback when the
     * signed webhook cannot reach this server, e.g. local dev).
     */
    public function pollPending(MediaLabRun $run): int
    {
        $ids = $run->results()
            ->where('status', MediaLabResult::STATUS_RUNNING)
            ->whereNotNull('request_id')
            ->pluck('id');

        foreach ($ids as $id) {
            PollMediaLabResult::dispatch((int) $id);
        }

        return $ids->count();
    }

    /**
     * Fail lab results stuck in `running` for longer than $minutes (lost webhook,
     * crashed worker). Their ledger rows stay as they are — fal may have billed.
     */
    public function sweepStuck(int $minutes = 60): int
    {
        return MediaLabResult::query()
            ->where('status', MediaLabResult::STATUS_RUNNING)
            ->where(fn ($q) => $q->where('started_at', '<', now()->subMinutes($minutes))
                ->orWhere(fn ($q) => $q->whereNull('started_at')->where('created_at', '<', now()->subMinutes($minutes))))
            ->update([
                'status' => MediaLabResult::STATUS_FAILED,
                'error_reason' => AiCallFailure::TimedOut->value,
                'error' => "No result within {$minutes} minutes.",
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Finish a running lab video from a verified webhook or a poll. Idempotent.
     * Must run inside the caller's transaction with the row locked.
     */
    public function completeVideo(MediaLabResult $result, ?string $videoUrl, ?string $error): void
    {
        if ($result->isFinished()) {
            return;
        }

        if ($videoUrl !== null && $this->fal->isAllowedMediaUrl($videoUrl)) {
            $result->update([
                'status' => MediaLabResult::STATUS_COMPLETED,
                'result_url' => $videoUrl,
                'latency_ms' => $result->started_at ? (int) $result->started_at->diffInMilliseconds(now()) : null,
                'completed_at' => now(),
            ]);

            return;
        }

        $result->update([
            'status' => MediaLabResult::STATUS_FAILED,
            'error_reason' => AiCallFailure::GenerationFailed->value,
            'error' => mb_substr($error ?? 'Missing or untrusted video URL', 0, 2000),
            'completed_at' => now(),
        ]);
    }

    /**
     * @return array{estimated_usd: float, charged_usd: float}
     */
    public function runCost(MediaLabRun $run): array
    {
        return [
            'estimated_usd' => (float) $run->estimated_cost_usd,
            'charged_usd' => round((float) $run->results()->sum('estimated_cost_usd'), 4),
        ];
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, ModelProfile>
     */
    private function labProfiles(string $kind, array $keys): array
    {
        $available = $this->profiles->forLab($kind);
        $keys = array_values(array_unique($keys));

        if ($keys === []) {
            throw new InvalidArgumentException('Pick at least one model profile.');
        }

        $picked = [];
        foreach ($keys as $key) {
            if (! isset($available[$key])) {
                throw new InvalidArgumentException("Profile {$kind}.{$key} is not available in the lab.");
            }
            $picked[$key] = $available[$key];
        }

        return $picked;
    }

    /**
     * @throws AiCallException
     */
    private function assertAffordable(float $estimate): void
    {
        $maxRun = (float) config('media.lab.max_run_usd', 3);

        if ($estimate > $maxRun + 1e-9) {
            throw new AiCallException(AiCallFailure::BudgetRun, sprintf('This run would cost ~$%.2f; the lab limit per run is $%.2f.', $estimate, $maxRun));
        }

        // The lab has its own budget (AI_LAB_DAILY_USD / AI_LAB_MONTHLY_USD): it can never starve new pets.
        $refusal = $this->guard->refusalFor($estimate, AiSpendPurpose::Lab);

        if ($refusal !== null) {
            Log::info('MediaLabService: run refused by budget', ['reason' => $refusal->value, 'estimate' => $estimate]);

            throw new AiCallException($refusal, $this->guard->describe($refusal, $estimate, 'this run'));
        }
    }

    private function assertAdmin(User $admin): void
    {
        if (! $admin->isSuperadmin() || ! config('media.lab.enabled', true)) {
            throw new AuthorizationException('The AI Lab is for superadmins only.');
        }
    }
}
