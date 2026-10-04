<?php

namespace App\Services\Media;

use App\Enums\AiCallFailure;
use App\Enums\AiSpendPurpose;
use App\Jobs\GeneratePetReferenceImage;
use App\Models\Pet;
use App\Services\FalAiService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Re-queues reference images that were blocked by the production budget or an
 * exhausted fal balance (PR #22 review): daily via `media:retry-references`
 * and per pet from the Filament PetResource action.
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
    ) {}

    /**
     * @return Builder<Pet>
     */
    public function autoRetryCandidates(): Builder
    {
        return Pet::query()
            ->where('is_active', true)
            ->where('media_status', 'failed')
            ->whereIn('media_error', array_map(fn (AiCallFailure $r) => $r->value, self::AUTO_RETRY_REASONS))
            ->orderBy('id');
    }

    /**
     * Queue as many blocked pets as today's production budget allows.
     * Nothing while fal is disabled or the balance is still flagged as exhausted.
     */
    public function retryDue(int $limit = 100): int
    {
        if (! $this->fal->isEnabled() || FalGateway::balanceExhaustedAt() !== null) {
            return 0;
        }

        $cost = $this->profiles->referenceImage()->estimatedCostUsd();
        $queued = 0;

        foreach ($this->autoRetryCandidates()->limit($limit)->get() as $pet) {
            // Leave room for everything queued in this sweep (reserve() is still the real gate).
            if ($this->guard->refusalFor($cost * ($queued + 1), AiSpendPurpose::ReferenceImage) !== null) {
                break;
            }

            if ($this->retry($pet)) {
                $queued++;
            }
        }

        if ($queued > 0) {
            Log::info('ReferenceImageRetryService: re-queued reference images', ['count' => $queued]);
        }

        return $queued;
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
        GeneratePetReferenceImage::dispatch($pet->id)->afterCommit();

        return true;
    }

    public function canRetry(Pet $pet): bool
    {
        return $this->fal->isEnabled()
            && $pet->is_active
            && $pet->media_status === 'failed'
            && empty($pet->pet_dna['reference_image_url'] ?? null);
    }
}
