<?php

namespace App\Services\Media;

use App\Enums\AiCallFailure;
use App\Enums\AiSpendPurpose;
use App\Jobs\GeneratePetKeyframe;
use App\Jobs\GeneratePetReferenceImage;
use App\Jobs\SendActivityMediaAlert;
use App\Jobs\SubmitPetStateVideo;
use App\Models\Pet;
use App\Models\PetMedia;
use App\Services\FalAiService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * No dead end for a locked school / game (David 2026-10-28, confirmed the same day: »home
 * first, school and games locked until their own clips are ready, never playable without
 * them; a failed clip stays locked, is retried automatically, then the admin is told«).
 *
 * `media:retry-activity-clips` (hourly) looks at the pet rows that keep an activity locked:
 *  - a failed pose image / phase clip (GameMediaReadiness — school / ball / wand),
 *  - a failed REFERENCE image of a pet that gets phase clips (no pose / phase row can ever
 *    be made without it — QA #434 M4a),
 *  - a READY pose / phase row flagged by the quality check (not shown → locked again —
 *    report only, QA #434 M4b).
 * Rows that are not locking anything are skipped in SQL (QA #434 m4: a failed slot that
 * still serves an older shown file; m2/M2: already reported chains, failures inside the
 * retry delay) and the rest are paged by id (chunkById) with a cap on ACTIONS, never on
 * rows read, so stale rows can never hide a newer failure.
 *
 * Retry = RETRY_REASONS (fal 4xx/5xx, no usable result, fal reported a failed generation),
 * at least RETRY_AFTER_MINUTES after the failure, at most MAX_AUTO_RETRIES per failure
 * CHAIN (`pet_media.auto_retries`, reset by any other new generation — stage remake, admin
 * Regenerate / Generate missing, quality retry — QA #434 M1). On a shared look the LOOK row
 * carries the count (one budget for every buyer of that breed + colour, m2) and its cost is
 * planned once per run. The cap is part of the conditional UPDATE (m5). Spend is planned
 * against today's production budget like `media:retry` (AiSpendGuard::refusalFor — m6):
 * when the budget would be exceeded, nothing more is retried in this run (the rest waits).
 *
 * `timed_out` is NOT retried (QA #434 M3): the lost-webhook request may still finish at fal
 * (status URLs are not stored for pet clips; queued pose images — 2026-10-28 — are polled by
 * media:sweep before they time out) — a resubmit could pay twice. It is reported instead, like
 * `quality_failed` / `disabled`.
 *
 * Report = once per failure chain (`pet_media.auto_alerted_at`, conditional UPDATE — m3):
 * one Discord post per run with the count (no pet / family / child), `Log::warning` with the
 * pet id, and the pet's AI media panel. The admin unlocks with »Generate missing« (shared
 * looks — the default — and own media) or Regenerate (own media) / »Approve anyway« (Needs
 * review). Budget / fal-balance failures stay with the nightly `media:retry`.
 */
class ActivityClipRetryService
{
    /** Automatic retries per failure chain (the first try + 2 retries = 3 tries). */
    public const MAX_AUTO_RETRIES = 2;

    /** A failed slot waits this long before the automatic retry. */
    public const RETRY_AFTER_MINUTES = 30;

    /** Failures worth an automatic retry (budget / balance: the nightly `media:retry`). */
    public const RETRY_REASONS = [
        AiCallFailure::HttpError,
        AiCallFailure::InvalidResponse,
        AiCallFailure::GenerationFailed,
    ];

    /** Failures only a person resolves (reported, never retried here). */
    public const REPORT_REASONS = [
        AiCallFailure::TimedOut,
        AiCallFailure::QualityFailed,
        AiCallFailure::Disabled,
    ];

    public const DEFAULT_LIMIT = 200;

    public function __construct(
        private readonly FalAiService $fal,
        private readonly MediaEntitlementService $entitlements,
        private readonly PetMediaService $media,
        private readonly AiSpendGuard $guard,
        private readonly MediaProfiles $profiles,
    ) {}

    /** @return list<string> */
    private static function values(array $reasons): array
    {
        return array_map(fn (AiCallFailure $r) => $r->value, $reasons);
    }

    /**
     * Pet rows that may keep an activity locked and still need a retry or a report.
     *
     * @return Builder<PetMedia>
     */
    public function candidates(): Builder
    {
        $retry = self::values(self::RETRY_REASONS);
        $all = [...$retry, ...self::values(self::REPORT_REASONS)];
        $cutoff = now()->subMinutes(self::RETRY_AFTER_MINUTES);

        return PetMedia::query()
            ->whereNotNull('pet_id')
            // One report per chain (m3); a reported chain only comes back after a new generation.
            ->whereNull('auto_alerted_at')
            ->where(fn (Builder $q) => $q
                // Failed pose / phase rows without an older shown file (m4).
                ->where(fn (Builder $q) => $q->whereIn('kind', [PetMedia::KIND_POSE, PetMedia::KIND_PHASE])
                    ->where('status', PetMedia::STATUS_FAILED)
                    ->whereIn('error_reason', $all)
                    ->where(fn (Builder $q) => $q->whereNull('storage_path')->orWhere('quality', PetMedia::QUALITY_FLAGGED)))
                // M4b: a stored pose / phase row the quality check flagged (not shown).
                ->orWhere(fn (Builder $q) => $q->whereIn('kind', [PetMedia::KIND_POSE, PetMedia::KIND_PHASE])
                    ->where('status', PetMedia::STATUS_READY)
                    ->where('quality', PetMedia::QUALITY_FLAGGED))
                // M4a: the reference image failed (nothing of the activity can be made without it).
                ->orWhere(fn (Builder $q) => $q->where('kind', PetMedia::KIND_IMAGE)
                    ->where('status', PetMedia::STATUS_FAILED)
                    ->whereIn('error_reason', $retry)
                    ->whereNull('storage_path')))
            // Inside the retry delay: nothing to do yet (M2 — never clogs the scan).
            ->whereNot(fn (Builder $q) => $q->where('status', PetMedia::STATUS_FAILED)
                ->whereIn('error_reason', $retry)
                ->where('completed_at', '>', $cutoff))
            ->whereHas('pet', fn (Builder $q) => $q->where('is_active', true)
                ->where('state_media_version', '>=', MediaEntitlementService::PHASE_CLIPS_VERSION));
    }

    /**
     * @return array{retried: int, reported: int, skipped_budget: int, items: list<array{pet_id: int, kind: string, clip: string, reason: string|null, retries: int, action: string}>}
     */
    public function run(bool $dryRun = false, int $limit = self::DEFAULT_LIMIT): array
    {
        $out = ['retried' => 0, 'reported' => 0, 'skipped_budget' => 0, 'items' => []];

        // Nothing can be made now: no retry and no report (the switches / balance explain it).
        if (! $this->fal->isEnabled() || FalGateway::balanceExhaustedAt() !== null
            || ! $this->entitlements->stateMediaEnabled(fresh: true) || ! $this->entitlements->phaseClipsEnabled(fresh: true)) {
            return $out;
        }

        $retryReasons = self::values(self::RETRY_REASONS);
        $pets = [];
        $seenLookRows = [];
        $planned = 0.0;
        $budgetLeft = true;
        $actions = 0;

        $this->candidates()->chunkById(100, function ($rows) use (&$out, &$pets, &$seenLookRows, &$planned, &$budgetLeft, &$actions, $limit, $dryRun, $retryReasons): bool {
            foreach ($rows as $slot) {
                if ($actions >= $limit) {
                    return false;
                }

                /** @var PetMedia $slot */
                $pet = $pets[$slot->pet_id] ??= Pet::find($slot->pet_id);
                // Only what keeps an activity of THIS pet locked (its phase clips now, both switches on).
                if ($pet === null || ! $this->locksActivity($pet, $slot)) {
                    continue;
                }

                // A shared look's row carries the chain (m2); the pet row otherwise.
                $look = $slot->look_media_id !== null ? PetMedia::find($slot->look_media_id) : null;
                $chain = $look ?? $slot;
                $lookSeen = $look !== null && isset($seenLookRows[$look->id]);
                $retryable = $slot->status === PetMedia::STATUS_FAILED
                    && in_array($slot->error_reason, $retryReasons, true)
                    && ($lookSeen || (int) $chain->auto_retries < self::MAX_AUTO_RETRIES);
                $action = $retryable ? 'retry' : 'report';

                if ($action === 'retry') {
                    // A look row already stored / in flight / retried in this run costs nothing more.
                    $lookCovered = $look !== null && ($lookSeen || $look->isInFlight() || $look->isShown());
                    $cost = $lookCovered ? 0.0 : $this->costOf($slot);
                    if ($cost > 0 && (! $budgetLeft || $this->guard->refusalFor($planned + $cost, $this->purposeOf($slot)) !== null)) {
                        $budgetLeft = false;
                        $out['skipped_budget']++;

                        continue;
                    }
                    $out['items'][] = $this->item($slot, $chain, 'retry');
                    $actions++;
                    if (! $dryRun && $this->retry($pet, $slot, $look, $lookSeen)) {
                        $planned += $cost;
                        $out['retried']++;
                    } elseif ($dryRun) {
                        $planned += $cost;
                        $out['retried']++;
                    }
                    if ($look !== null) {
                        $seenLookRows[$look->id] = true;
                    }

                    continue;
                }

                $out['items'][] = $this->item($slot, $chain, 'report');
                $actions++;
                if ($dryRun) {
                    $out['reported']++;

                    continue;
                }
                // One report per chain, atomically (m3).
                if (PetMedia::query()->whereKey($slot->id)->whereNull('auto_alerted_at')->update(['auto_alerted_at' => now()]) === 1) {
                    $out['reported']++;
                    Log::warning('Activity clip needs an admin — school / game stays locked', [
                        'pet_id' => $slot->pet_id,
                        'slot_id' => $slot->id,
                        'kind' => $slot->kind,
                        'clip' => $slot->state,
                        'status' => $slot->status,
                        'reason' => $slot->error_reason ?? $slot->quality,
                        'auto_retries' => $chain->auto_retries,
                    ]);
                }
            }

            return $actions < $limit;
        });

        if (! $dryRun && $out['reported'] > 0) {
            SendActivityMediaAlert::dispatch($out['reported']);
        }
        if (! $dryRun && $out['retried'] > 0) {
            Log::info('ActivityClipRetryService: re-queued failed school / game media', ['slots' => $out['retried'], 'planned_usd' => round($planned, 4)]);
        }

        return $out;
    }

    /** Does this row keep one of the pet's activities (school / ball / wand) locked? */
    public function locksActivity(Pet $pet, PetMedia $slot): bool
    {
        return $slot->isImage()
            ? $this->entitlements->phaseClipsFor($pet) !== []
            : $this->media->wantsPoseMedia($pet, $slot);
    }

    /** Re-queue one slot (the cap is part of the conditional UPDATE). */
    private function retry(Pet $pet, PetMedia $slot, ?PetMedia $look, bool $lookCountedThisRun): bool
    {
        // Shared look: count the retry once per run on the LOOK row (the pet's job re-claims it).
        if ($look !== null && ! $lookCountedThisRun && $look->status === PetMedia::STATUS_FAILED
            && PetMedia::query()->whereKey($look->id)->where('auto_retries', '<', self::MAX_AUTO_RETRIES)
                ->update(['auto_retries' => DB::raw('auto_retries + 1')]) !== 1) {
            return false;
        }

        if ($slot->isImage()) {
            $reset = PetMedia::query()->whereKey($slot->id)->where('status', PetMedia::STATUS_FAILED)
                ->when($look === null, fn ($q) => $q->where('auto_retries', '<', self::MAX_AUTO_RETRIES))
                ->update([
                    'status' => PetMedia::STATUS_PENDING, 'error_reason' => null, 'error' => null,
                    ...($look === null ? ['auto_retries' => DB::raw('auto_retries + 1')] : []),
                ]) === 1;
            if ($reset) {
                $pet->updateQuietly(['media_status' => 'pending', 'media_error' => null]);
                GeneratePetReferenceImage::dispatch($pet->id);
            }

            return $reset;
        }

        $bump = $slot->isServable() || $slot->request_id !== null;
        $reset = $look === null
            ? $this->media->resetForNewGeneration($slot, bump: $bump, autoRetryBelow: self::MAX_AUTO_RETRIES)
            // The pet row of a shared look: the look row carries the chain; keep the pet's own alert flag.
            : PetMedia::query()->whereKey($slot->id)->where('status', PetMedia::STATUS_FAILED)
                ->update(['status' => PetMedia::STATUS_PENDING, 'request_id' => null, 'source_url' => null, 'error_reason' => null, 'error' => null, 'updated_at' => now()]) === 1;

        if ($reset) {
            $slot->isPose() ? GeneratePetKeyframe::dispatch($slot->id) : SubmitPetStateVideo::dispatch($slot->id);
        }

        return $reset;
    }

    private function costOf(PetMedia $slot): float
    {
        return match (true) {
            $slot->isImage() => $this->profiles->referenceImage()->estimatedCostUsd(),
            $slot->isPose() => $this->media->keyframeCostUsd(),
            default => ($clip = $slot->phaseClip()) !== null ? $this->media->phaseClipCostUsd($clip) : 0.0,
        };
    }

    private function purposeOf(PetMedia $slot): AiSpendPurpose
    {
        return $slot->isPhase() ? AiSpendPurpose::StateVideo : AiSpendPurpose::ReferenceImage;
    }

    /** @return array{pet_id: int, kind: string, clip: string, reason: string|null, retries: int, action: string} */
    private function item(PetMedia $slot, PetMedia $chain, string $action): array
    {
        return [
            'pet_id' => (int) $slot->pet_id,
            'kind' => (string) $slot->kind,
            'clip' => (string) ($slot->state ?? 'reference'),
            'reason' => $slot->error_reason ?? ($slot->quality === PetMedia::QUALITY_FLAGGED ? 'flagged' : null),
            'retries' => (int) $chain->auto_retries,
            'action' => $action,
        ];
    }
}
