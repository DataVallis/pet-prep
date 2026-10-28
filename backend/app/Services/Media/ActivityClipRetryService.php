<?php

namespace App\Services\Media;

use App\Enums\AiCallFailure;
use App\Jobs\GeneratePetKeyframe;
use App\Jobs\SendActivityMediaAlert;
use App\Jobs\SubmitPetStateVideo;
use App\Models\Pet;
use App\Models\PetMedia;
use App\Services\FalAiService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * No dead end for a locked school / game (David 2026-10-28, »home first, school and
 * games locked until their own clips are ready, never playable without them«).
 *
 * A school / game phase clip — or a pose image it is made from — that FAILED keeps its
 * activity locked (GameMediaReadiness). Budget / fal-balance failures are already
 * re-queued by the nightly `media:retry`; this service (`media:retry-activity-clips`,
 * hourly) re-queues the other transient failures of the pet's OWN slots (fal 4xx/5xx,
 * no usable result, lost webhook, fal reported a failed generation) — at most until
 * the slot has been claimed MAX_ATTEMPTS times, at least RETRY_AFTER_MINUTES after the
 * failure. Only items the pet gets anyway (MediaEntitlementService via
 * PetMediaService::wantsPoseMedia) — never anything extra; a shared look's row is
 * re-claimed through the pet's slot (paid once).
 *
 * A slot that is out of retries — or needs a person (»Needs review« quality_failed,
 * `disabled`) — is reported ONCE per generation to the admin Discord channel (counts
 * only, no pet / family / child; the AI media panel of the pet lists the locked
 * activity and its failed clips). The admin's Regenerate / Generate missing /
 * »Approve anyway« unlocks it. The activity is never opened without its clips.
 */
class ActivityClipRetryService
{
    /** Claims of one slot (the first try included) before the admin is told instead. */
    public const MAX_ATTEMPTS = 3;

    /** A failed slot waits this long before the automatic retry. */
    public const RETRY_AFTER_MINUTES = 30;

    /** Failures worth an automatic retry (budget / balance: the nightly `media:retry`). */
    public const RETRY_REASONS = [
        AiCallFailure::HttpError,
        AiCallFailure::InvalidResponse,
        AiCallFailure::TimedOut,
        AiCallFailure::GenerationFailed,
    ];

    /** Failures only a person resolves (reported at once, never retried here). */
    public const ADMIN_REASONS = [
        AiCallFailure::QualityFailed,
        AiCallFailure::Disabled,
    ];

    /** How long one report of a slot generation is remembered (no repeated posts). */
    public const ALERT_TTL_DAYS = 14;

    public function __construct(
        private readonly FalAiService $fal,
        private readonly MediaEntitlementService $entitlements,
        private readonly PetMediaService $media,
    ) {}

    /**
     * Failed pose / phase slots of active pets with phase clips (the pet's own slots,
     * not look rows), oldest first.
     *
     * @return Builder<PetMedia>
     */
    public function candidates(): Builder
    {
        $reasons = array_map(fn (AiCallFailure $r) => $r->value, [...self::RETRY_REASONS, ...self::ADMIN_REASONS]);

        return PetMedia::query()
            ->whereNotNull('pet_id')
            ->whereIn('kind', [PetMedia::KIND_POSE, PetMedia::KIND_PHASE])
            ->where('status', PetMedia::STATUS_FAILED)
            ->whereIn('error_reason', $reasons)
            ->whereHas('pet', fn (Builder $q) => $q->where('is_active', true)
                ->where('state_media_version', '>=', MediaEntitlementService::PHASE_CLIPS_VERSION))
            ->orderBy('id');
    }

    /**
     * @return array{retried: int, reported: int, waiting: int, items: list<array{pet_id: int, kind: string, clip: string, reason: string|null, attempts: int, action: string}>}
     */
    public function run(bool $dryRun = false, int $limit = 200): array
    {
        $out = ['retried' => 0, 'reported' => 0, 'waiting' => 0, 'items' => []];

        // Nothing can be made now: no retry and no report (the switches / balance explain it).
        if (! $this->fal->isEnabled() || FalGateway::balanceExhaustedAt() !== null
            || ! $this->entitlements->stateMediaEnabled(fresh: true) || ! $this->entitlements->phaseClipsEnabled(fresh: true)) {
            return $out;
        }

        $retryReasons = array_map(fn (AiCallFailure $r) => $r->value, self::RETRY_REASONS);
        $cutoff = now()->subMinutes(self::RETRY_AFTER_MINUTES);
        $pets = [];

        foreach ($this->candidates()->limit($limit)->get() as $slot) {
            $pet = $pets[$slot->pet_id] ??= Pet::find($slot->pet_id);
            // Only what the pet gets anyway (its phase clips now, both switches on).
            if ($pet === null || ! $this->media->wantsPoseMedia($pet, $slot)) {
                continue;
            }

            $retryable = in_array($slot->error_reason, $retryReasons, true) && (int) $slot->attempts < self::MAX_ATTEMPTS;
            $action = match (true) {
                $retryable && $slot->completed_at !== null && $slot->completed_at->greaterThan($cutoff) => 'waiting',
                $retryable => 'retry',
                default => 'report',
            };

            $out['items'][] = [
                'pet_id' => (int) $slot->pet_id,
                'kind' => (string) $slot->kind,
                'clip' => (string) $slot->state,
                'reason' => $slot->error_reason,
                'attempts' => (int) $slot->attempts,
                'action' => $action,
            ];

            if ($dryRun) {
                $out[$action === 'retry' ? 'retried' : ($action === 'report' ? 'reported' : 'waiting')]++;

                continue;
            }

            if ($action === 'waiting') {
                $out['waiting']++;
            } elseif ($action === 'retry') {
                if ($this->media->resetForNewGeneration($slot, bump: $slot->isServable() || $slot->request_id !== null)) {
                    $slot->isPose() ? GeneratePetKeyframe::dispatch($slot->id) : SubmitPetStateVideo::dispatch($slot->id);
                    $out['retried']++;
                }
            } elseif (Cache::add("activity-clip-alert:{$slot->id}:{$slot->generation}", true, now()->addDays(self::ALERT_TTL_DAYS))) {
                $out['reported']++;
                Log::warning('Activity clip needs an admin — school / game stays locked', [
                    'pet_id' => $slot->pet_id,
                    'slot_id' => $slot->id,
                    'clip' => $slot->state,
                    'reason' => $slot->error_reason,
                    'attempts' => $slot->attempts,
                ]);
            }
        }

        if (! $dryRun && $out['reported'] > 0) {
            SendActivityMediaAlert::dispatch($out['reported']);
        }
        if ($out['retried'] > 0) {
            Log::info('ActivityClipRetryService: re-queued failed school / game clips', ['slots' => $out['retried'], 'dry_run' => $dryRun]);
        }

        return $out;
    }
}
