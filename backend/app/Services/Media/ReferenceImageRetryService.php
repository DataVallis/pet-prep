<?php

namespace App\Services\Media;

use App\Enums\AiCallFailure;
use App\Enums\AiSpendPurpose;
use App\Jobs\GeneratePetReferenceImage;
use App\Jobs\RegeneratePetStageMedia;
use App\Jobs\SubmitPetStateVideo;
use App\Models\Pet;
use App\Models\PetMedia;
use App\Services\FalAiService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Re-queues AI media blocked by the production budget or an exhausted fal
 * balance (PR #22 review; videos since M4-03): daily via `media:retry` and
 * per pet from the Filament PetResource action. Reference images first —
 * videos need a stored image.
 *
 * Life stages (M5-R01): a pet whose stored image shows an earlier stage than
 * the pet is in (RegeneratePetStageMedia lost or given up) gets the stage
 * transition re-queued; a stage image that failed on the budget / balance
 * (its slot still points at the archived previous image) is retried like a
 * missing reference image.
 */
class ReferenceImageRetryService
{
    /** Failures that resolve themselves once the budget / balance allows. */
    public const AUTO_RETRY_REASONS = [
        AiCallFailure::BudgetDaily,
        AiCallFailure::BudgetMonthly,
        AiCallFailure::FalBalance,
    ];

    public function __construct(
        private readonly FalAiService $fal,
        private readonly AiSpendGuard $guard,
        private readonly MediaProfiles $profiles,
        private readonly PetMediaService $media,
    ) {}

    /**
     * @return list<string>
     */
    private static function reasons(): array
    {
        return array_map(fn (AiCallFailure $r) => $r->value, self::AUTO_RETRY_REASONS);
    }

    /**
     * @return Builder<Pet>
     */
    public function autoRetryCandidates(): Builder
    {
        return Pet::query()
            ->where('is_active', true)
            ->where('media_status', 'failed')
            ->whereIn('media_error', self::reasons())
            ->orderBy('id');
    }

    /**
     * Born, active pets with a profile whose stored (ready) reference image
     * shows another life stage than the pet's; PetMediaService::
     * stageImageBehind() keeps only the ones that are BEHIND (forward only).
     *
     * @return Builder<Pet>
     */
    public function stageRetryCandidates(): Builder
    {
        return Pet::query()
            ->where('is_active', true)
            ->whereNotNull('born_at')
            ->whereNotNull('arrival_age_months')
            ->whereNotNull('life_stage')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('pet_media as img')
                ->whereColumn('img.pet_id', 'pets.id')
                ->where('img.kind', PetMedia::KIND_IMAGE)
                ->where('img.status', PetMedia::STATUS_READY)
                ->whereNotNull('img.life_stage')
                ->whereColumn('img.life_stage', '<>', 'pets.life_stage'))
            ->orderBy('id');
    }

    /**
     * Video slots blocked by budget / balance whose pet is active and whose image is stored.
     *
     * @return Builder<PetMedia>
     */
    public function videoRetryCandidates(): Builder
    {
        return PetMedia::query()
            ->videos()
            ->where('status', PetMedia::STATUS_FAILED)
            ->whereIn('error_reason', self::reasons())
            ->whereHas('pet', fn (Builder $q) => $q->where('is_active', true))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('pet_media as img')
                ->whereColumn('img.pet_id', 'pet_media.pet_id')
                ->where('img.kind', PetMedia::KIND_IMAGE)
                ->whereNotNull('img.storage_path'))
            ->orderBy('id');
    }

    /**
     * Queue as many blocked pets as today's production budget allows.
     * Nothing while fal is disabled or the balance is still flagged as exhausted.
     */
    public function retryDue(int $limit = 100): int
    {
        return $this->retryDueWithVideos($limit)['images'];
    }

    /**
     * Images first, then stage images (M5-R01), then videos, within today's
     * production budget.
     *
     * @return array{images: int, stages: int, videos: int}
     */
    public function retryDueWithVideos(int $limit = 100): array
    {
        if (! $this->fal->isEnabled() || FalGateway::balanceExhaustedAt() !== null) {
            return ['images' => 0, 'stages' => 0, 'videos' => 0];
        }

        $imageCost = $this->profiles->referenceImage()->estimatedCostUsd();
        $stageCost = $this->media->stageImageCostUsd();
        $videoCost = $this->profiles->stateVideo()->estimatedCostUsd();
        $planned = 0.0;
        $images = 0;
        $stages = 0;
        $videos = 0;

        foreach ($this->autoRetryCandidates()->limit($limit)->get() as $pet) {
            // Leave room for everything queued in this sweep (reserve() is still the real gate).
            if ($this->guard->refusalFor($planned + $imageCost, AiSpendPurpose::ReferenceImage) !== null) {
                break;
            }

            if ($this->retry($pet)) {
                $planned += $imageCost;
                $images++;
            }
        }

        foreach ($this->stageRetryCandidates()->limit(max(0, $limit - $images))->get() as $pet) {
            if (! $this->media->stageImageBehind($pet)) {
                continue;
            }
            if ($this->guard->refusalFor($planned + $stageCost, AiSpendPurpose::ReferenceImage) !== null) {
                break;
            }

            RegeneratePetStageMedia::dispatch($pet->id);
            $planned += $stageCost;
            $stages++;
        }

        foreach ($this->videoRetryCandidates()->limit(max(0, $limit - $images - $stages))->get() as $slot) {
            if ($this->guard->refusalFor($planned + $videoCost, AiSpendPurpose::StateVideo) !== null) {
                break;
            }

            $slot->update(['status' => PetMedia::STATUS_PENDING, 'request_id' => null, 'error_reason' => null, 'error' => null]);
            SubmitPetStateVideo::dispatch($slot->id);
            $planned += $videoCost;
            $videos++;
        }

        if ($images + $stages + $videos > 0) {
            Log::info('ReferenceImageRetryService: re-queued AI media', ['images' => $images, 'stages' => $stages, 'videos' => $videos]);
        }

        return ['images' => $images, 'stages' => $stages, 'videos' => $videos];
    }

    /**
     * Re-queue one pet whose reference image failed (any reason — the admin action).
     */
    public function retry(Pet $pet): bool
    {
        if (! $this->canRetry($pet)) {
            return false;
        }

        $pet->updateQuietly(['media_status' => 'pending', 'media_error' => null]);
        PetMedia::query()->where('pet_id', $pet->id)->images()->where('status', PetMedia::STATUS_FAILED)
            ->update(['status' => PetMedia::STATUS_PENDING, 'error_reason' => null, 'error' => null]);
        GeneratePetReferenceImage::dispatch($pet->id)->afterCommit();

        return true;
    }

    /**
     * Failed and no CURRENT stored image. A file archived at a life-stage
     * change (pet_media_history) is not current: the failed stage image is
     * retried (and grows from that file again).
     */
    public function canRetry(Pet $pet): bool
    {
        return $this->fal->isEnabled()
            && $pet->is_active
            && $pet->media_status === 'failed'
            && ! PetMedia::query()->where('pet_id', $pet->id)->images()->whereNotNull('storage_path')
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('pet_media_history as h')
                    ->whereColumn('h.storage_path', 'pet_media.storage_path'))
                ->exists();
    }
}
