<?php

namespace App\Services\Media;

use App\Enums\AiCallFailure;
use App\Enums\AiSpendPurpose;
use App\Events\PetUpdated;
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
 * them; a failed clip stays locked, is retried automatically, then the admin is told« — the end
 * of that rule is replaced by the self-healing / simple mode of 2026-11-02, below).
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
 * pet id, and the pet's AI media panel. Budget / fal-balance failures stay with the nightly
 * `media:retry`.
 *
 * Self-healing (David 2026-11-02, option B: »it must heal itself — I cannot click thousands of
 * products«; replaces »locked until an admin acts«):
 *  - Deterministic refusal: fal answered `no_media_generated` (PetMedia::isNoMediaRefusal) →
 *    the identical request is NEVER sent again. A pose image moves to its next prompt variant
 *    (`pet_media.prompt_variant`, PetAppearancePrompt::POSE_PROMPT_VARIANTS — on a shared look
 *    the LOOK row); with no variant left (or for a clip / reference image) the chain is
 *    exhausted at once. Variant retries are capped by the variants, not by MAX_AUTO_RETRIES.
 *  - Simple mode: every reported (= exhausted) chain switches the activities it keeps from
 *    showing to SIMPLE mode (`pets.simple_activities`, GameMediaReadiness::simpleGames()): the
 *    activity is ready with its classic list and the app plays its no-phase-group stage; one
 *    `media_mode_changed` PetUpdated per switch, and the run's single Discord post counts the
 *    pets switched (counts only). Once every phase clip of the activity is shown again (any
 *    path: a later retry, a stage remake, an admin action) the full mode returns by itself and
 *    the run drops the stale switch ({@see clearRestored()}).
 *  - Chains reported before this change (`recovery_version` < RECOVERY_VERSION) are picked up
 *    ONCE ({@see pickUpLegacy()}): their pets go to simple mode at once and a retryable chain
 *    gets exactly one more automatic try with the new strategies (new prompt / variant) —
 *    a shared look counted once, every try planned against the budget like any other retry.
 * Admin »Generate missing« / Regenerate / »Approve anyway« still work but are never needed.
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

    /**
     * Recovery generation of a reported chain (`pet_media.recovery_version`): 1 = the
     * self-healing strategies of 2026-11-02. A reported row below it is picked up once.
     */
    public const RECOVERY_VERSION = 1;

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
     * @return array{retried: int, reported: int, skipped_budget: int, simple: int, legacy: int, restored: int, items: list<array{pet_id: int, kind: string, clip: string, reason: string|null, retries: int, action: string}>}
     */
    public function run(bool $dryRun = false, int $limit = self::DEFAULT_LIMIT): array
    {
        $out = ['retried' => 0, 'reported' => 0, 'skipped_budget' => 0, 'simple' => 0, 'legacy' => 0, 'restored' => 0, 'items' => []];

        // Free bookkeeping: activities whose clips are all shown again lose the simple switch.
        $out['restored'] = $this->clearRestored($dryRun);

        // Nothing can be made now: no retry and no report (the switches / balance explain it).
        if (! $this->fal->isEnabled() || FalGateway::balanceExhaustedAt() !== null
            || ! $this->entitlements->stateMediaEnabled(fresh: true) || ! $this->entitlements->phaseClipsEnabled(fresh: true)) {
            return $out;
        }

        // Chains reported before the self-healing strategies: simple mode now + one more try (once).
        $this->pickUpLegacy($dryRun, $out);

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
                // David 2026-11-02: a deterministic refusal is never retried identically — a pose
                // image takes its next prompt variant, anything else is exhausted at once.
                $variant = ! $lookSeen && $chain->isNoMediaRefusal();
                $retryable = $slot->status === PetMedia::STATUS_FAILED
                    && in_array($slot->error_reason, $retryReasons, true)
                    && ($lookSeen || ($variant
                        ? $slot->isPose() && (int) $chain->prompt_variant + 1 < PetAppearancePrompt::POSE_PROMPT_VARIANTS
                        : (int) $chain->auto_retries < self::MAX_AUTO_RETRIES));
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
                    if (! $dryRun && $this->retry($pet, $slot, $look, $lookSeen, $variant)) {
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
                // One report per chain, atomically (m3) — handled by this recovery generation.
                if (PetMedia::query()->whereKey($slot->id)->whereNull('auto_alerted_at')
                    ->update(['auto_alerted_at' => now(), 'recovery_version' => self::RECOVERY_VERSION]) === 1) {
                    $out['reported']++;
                    // David 2026-11-02: automatic recovery exhausted → the activity plays in simple mode.
                    if ($this->enterSimpleMode($pet, $slot)) {
                        $out['simple']++;
                    }
                    Log::warning('Activity clip recovery exhausted — the activity runs in simple mode', [
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

        if (! $dryRun && ($out['reported'] > 0 || $out['simple'] > 0)) {
            SendActivityMediaAlert::dispatch($out['reported'], $out['simple']);
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

    /**
     * Switch to simple mode (David 2026-11-02) the activities of this pet that the EXHAUSTED
     * row $slot keeps from showing (QA #547 m1 — only the activities whose failed list holds
     * this row's clip / pose, or `reference` for the reference image). Only ADDS activities —
     * an earlier switch is never dropped here (QA #547 M1). True when at least one activity was
     * newly switched (then one `media_mode_changed` PetUpdated — the app refetches).
     */
    public function enterSimpleMode(Pet $pet, PetMedia $slot): bool
    {
        $key = $slot->isImage() ? 'reference' : (string) $slot->state;
        $games = [];
        foreach ($this->readiness()->activities($pet, $pet->media()->get()) as $game => $state) {
            if (! $state['ready'] && in_array($key, $state['failed'], true)) {
                $games[] = $game;
            }
        }
        if ($games === []) {
            return false;
        }

        $new = DB::transaction(function () use ($pet, $games): array {
            $map = self::simpleMapOf(DB::table('pets')->where('id', $pet->id)->lockForUpdate()->value('simple_activities'));
            $new = array_values(array_diff($games, array_keys($map)));
            if ($new === []) {
                return [];
            }
            foreach ($new as $game) {
                $map[$game] = now()->toIso8601String();
            }
            DB::table('pets')->where('id', $pet->id)->update(['simple_activities' => json_encode($map)]);

            return $new;
        });

        if ($new !== []) {
            $fresh = $pet->fresh();
            Log::warning('Activity switched to simple mode (automatic clip recovery exhausted)', ['pet_id' => $pet->id, 'activities' => $new]);
            if ($fresh !== null) {
                PetUpdated::afterCommit($fresh, 'media_mode_changed');
            }
        }

        return $new !== [];
    }

    /**
     * The `pets.simple_activities` map from a raw column value (JSON string) or an already
     * cast array (QA #547 M1: never assume one form).
     *
     * @return array<string, string>
     */
    private static function simpleMapOf(mixed $raw): array
    {
        $map = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_array($map) ? $map : [];
    }

    /**
     * Drop the simple switch of activities whose phase clips are all shown again (the full
     * mode already returned — GameMediaReadiness::simpleGames()); keeps the column tidy so a
     * later failure walks the normal recovery again. Free (no fal call). Returns the pets changed.
     */
    private function clearRestored(bool $dryRun): int
    {
        $changed = 0;
        Pet::query()->whereNotNull('simple_activities')->chunkById(100, function ($pets) use (&$changed, $dryRun): void {
            foreach ($pets as $pet) {
                /** @var Pet $pet */
                $slots = $pet->media()->get();
                // Which switched activities are full again (read-only, outside the lock).
                $restored = array_keys(array_filter(self::simpleMapOf($pet->simple_activities), fn ($at, string $game) => $this->readiness()->phaseClipsOf($pet, $game) !== []
                    && $this->readiness()->phasesMissing($pet, $game, $slots) === [], ARRAY_FILTER_USE_BOTH));
                if ($restored === []) {
                    continue;
                }
                $changed++;
                if ($dryRun) {
                    continue;
                }
                // QA #547 m4: drop only those keys from the LOCKED current value (a concurrent switch survives).
                DB::transaction(function () use ($pet, $restored): void {
                    $map = self::simpleMapOf(DB::table('pets')->where('id', $pet->id)->lockForUpdate()->value('simple_activities'));
                    $keep = array_diff_key($map, array_flip($restored));
                    DB::table('pets')->where('id', $pet->id)->update(['simple_activities' => $keep === [] ? null : json_encode($keep)]);
                });
            }
        });

        return $changed;
    }

    /**
     * Chains reported BEFORE the self-healing strategies (`recovery_version` below
     * RECOVERY_VERSION — e.g. pet 19's empty room, 2026-11-02) are handled once, without any
     * command: the pet goes to simple mode now, and a chain that failed for a retryable reason
     * gets exactly one more automatic try (auto_retries = MAX − 1, report flag cleared) — the
     * main pass of this run then sends it with the new prompt / variant, planned against the
     * budget, a shared look once (its LOOK row carries the count). Timed-out / quality-failed /
     * flagged rows stay reported (never resubmitted), only simple mode.
     *
     * @param  array{simple: int, legacy: int}  $out
     */
    private function pickUpLegacy(bool $dryRun, array &$out): void
    {
        $retry = self::values(self::RETRY_REASONS);
        $query = PetMedia::query()
            ->whereNotNull('pet_id')
            ->whereNotNull('auto_alerted_at')
            ->where('recovery_version', '<', self::RECOVERY_VERSION)
            ->whereIn('kind', [PetMedia::KIND_POSE, PetMedia::KIND_PHASE, PetMedia::KIND_IMAGE])
            ->whereHas('pet', fn (Builder $q) => $q->where('is_active', true)
                ->where('state_media_version', '>=', MediaEntitlementService::PHASE_CLIPS_VERSION));

        $query->chunkById(100, function ($rows) use (&$out, $retry, $dryRun): void {
            foreach ($rows as $slot) {
                /** @var PetMedia $slot */
                $pet = Pet::find($slot->pet_id);
                // Only a row that keeps an activity of the pet from showing now (else it waits).
                if ($pet === null || ! $this->locksActivity($pet, $slot)) {
                    continue;
                }
                // QA #547 m5: the dry run counts with the same filters, changes nothing.
                if ($dryRun) {
                    $out['legacy']++;

                    continue;
                }
                // Once per chain, atomically (two overlapping runs never both reopen it).
                if (PetMedia::query()->whereKey($slot->id)->where('recovery_version', '<', self::RECOVERY_VERSION)
                    ->update(['recovery_version' => self::RECOVERY_VERSION]) !== 1) {
                    continue;
                }
                $out['legacy']++;
                if ($this->enterSimpleMode($pet, $slot)) {
                    $out['simple']++;
                }

                if ($slot->status !== PetMedia::STATUS_FAILED || ! in_array($slot->error_reason, $retry, true) || $slot->storage_path !== null) {
                    continue; // reported for a reason only a person / a new generation resolves
                }

                $once = ['auto_retries' => self::MAX_AUTO_RETRIES - 1];
                if ($slot->look_media_id !== null) {
                    // The look row carries the chain: one more try for every pet of the look (counted once).
                    PetMedia::query()->whereKey($slot->look_media_id)->where('status', PetMedia::STATUS_FAILED)
                        ->where('auto_retries', '>=', self::MAX_AUTO_RETRIES)->update($once);
                    $once = [];
                }
                PetMedia::query()->whereKey($slot->id)->update([...$once, 'auto_alerted_at' => null]);
            }
        });
    }

    private ?GameMediaReadiness $readiness = null;

    private function readiness(): GameMediaReadiness
    {
        return $this->readiness ??= app(GameMediaReadiness::class);
    }

    /**
     * Re-queue one slot (the cap is part of the conditional UPDATE). `$variant`: fal refused
     * the chain's last request deterministically — the next try takes the next prompt variant.
     */
    private function retry(Pet $pet, PetMedia $slot, ?PetMedia $look, bool $lookCountedThisRun, bool $variant = false): bool
    {
        // Shared look: count the retry once per run on the LOOK row (the pet's job re-claims it).
        if ($look !== null && ! $lookCountedThisRun && $look->status === PetMedia::STATUS_FAILED
            && PetMedia::query()->whereKey($look->id)
                ->when($variant, fn ($q) => $q->where('prompt_variant', '<', PetAppearancePrompt::POSE_PROMPT_VARIANTS - 1))
                ->when(! $variant, fn ($q) => $q->where('auto_retries', '<', self::MAX_AUTO_RETRIES))
                ->update([
                    'auto_retries' => DB::raw('auto_retries + 1'),
                    ...($variant ? ['prompt_variant' => DB::raw('prompt_variant + 1')] : []),
                ]) !== 1) {
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
            ? ($variant
                ? $this->media->resetForNewGeneration($slot, bump: $bump, variantBelow: PetAppearancePrompt::POSE_PROMPT_VARIANTS - 1)
                : $this->media->resetForNewGeneration($slot, bump: $bump, autoRetryBelow: self::MAX_AUTO_RETRIES))
            // The pet row of a shared look: the look row carries the chain; keep the pet's own alert flag.
            : PetMedia::query()->whereKey($slot->id)->where('status', PetMedia::STATUS_FAILED)
                ->update(['status' => PetMedia::STATUS_PENDING, 'request_id' => null, 'status_url' => null, 'response_url' => null, 'source_url' => null, 'error_reason' => null, 'error' => null, 'updated_at' => now()]) === 1;

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
