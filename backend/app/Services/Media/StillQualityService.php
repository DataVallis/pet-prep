<?php

namespace App\Services\Media;

use App\Enums\AiCallFailure;
use App\Enums\PetPose;
use App\Enums\Species;
use App\Events\PetUpdated;
use App\Jobs\GenerateItemMedia;
use App\Jobs\GeneratePetKeyframe;
use App\Jobs\SendStillQualityAlert;
use App\Models\AppSettingChange;
use App\Models\ItemMedia;
use App\Models\ItemMediaQualityCheck;
use App\Models\Pet;
use App\Models\PetMedia;
use App\Models\PetMediaQualityCheck;
use App\Models\User;
use App\Services\AppSettingsService;
use App\Services\Shop\ItemDesigns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * AI check of still images BEFORE a video is made from them (David 2026-11-04, production: a
 * bought bed's key image had a 4th paw under the bed and two ghost paws of the original pose on
 * the floor; the clip from it had them too and nothing caught it).
 *
 * What is checked (vision profile `media.quality_check.vision_profile`, FalGateway, purpose
 * `quality_check` = production budget, one picture per call, MediaVisionCheck::judgeStill):
 *  - pet KEY images and POSE images (pet slots and shared look rows) — PetMediaService stages the
 *    download as a candidate; loops / transitions / phase clips wait for a STORED still;
 *  - item KEY images and CATALOGUE pictures (ItemMediaService) — the clip waits for the key image,
 *    the key images wait for their catalogue picture.
 *  Item CLIPS get the free technical check (MediaClipAnalyzer: length, frozen, jumps) here too
 *  (item_media is not pet_media; mode = the clip check's mode).
 *  The reference image is NOT checked: it is generated during pairing, its failure path (pets.
 *  media_status, stage remakes) is separate, and every key / pose image made from it is checked.
 *
 * Mode `app_settings.quality_check.stills_mode` (default enforce, env AI_QUALITY_STILLS_MODE —
 * independent of the clip mode, which stays shadow while it is calibrated):
 *  - enforce: pass → stored; fail → a NEW request (fresh seed; poses / items: the next prompt
 *    variant) ≤ `max_still_retries` (2) per chain; then »Needs review« (failed `quality_failed`,
 *    candidate kept for »Approve anyway«) and the existing self-healing fallback: pose → the
 *    activity's simple mode (ActivityClipRetryService), item → shown without that content
 *    (ItemMediaService::heal(), one alert per run), key image → no loop for that state + ONE
 *    Discord alert (SendStillQualityAlert, counts only);
 *  - shadow: verdict recorded (`quality = shadow_failed`), the still is used anyway;
 *  - off: stored unchecked (the old behaviour).
 * Without a vision profile (off / fal disabled) a still is stored `unchecked`; without budget it
 * WAITS (nothing claimed or paid — media:sweep / the item sweep re-runs the check hourly).
 *
 * Never pays twice: one check row per (row, generation, trigger) (`pet_media_quality_checks` /
 * `item_media_quality_checks`) is the claim; `ai_status = calling` before the paid call — a
 * stale takeover never repeats it (counted as not AI-checked); a duplicate job / webhook
 * redelivery only applies the decided verdict.
 */
class StillQualityService
{
    /** app_setting_changes key of an item »Approve anyway«. */
    public const AUDIT_APPROVE_ITEM = 'item_media_quality_approve';

    /** app_setting_changes key of a media:still-check real run. */
    public const AUDIT_COMMAND = 'media_still_check';

    public function __construct(
        private readonly PetMediaService $media,
        private readonly ItemMediaService $items,
        private readonly MediaVisionCheck $vision,
        private readonly MediaClipAnalyzer $analyzer,
        private readonly AppSettingsService $settings,
    ) {}

    public static function maxRetries(): int
    {
        return max(0, (int) config('media.quality_check.max_still_retries', 2));
    }

    /** off | shadow | enforce of the still check (fresh read — workers follow the switch at once). */
    public function stillsMode(): string
    {
        return $this->settings->qualityStillsMode(fresh: true);
    }

    /** Estimated cost of one still check (0 without a vision profile). */
    public function costUsd(): float
    {
        return $this->vision->costUsd();
    }

    /** Why a vision call would not run now (null = it would). */
    public function unavailable(): ?string
    {
        return $this->vision->unavailableStatus();
    }

    // ──────────────────────────────────────────────────────────────
    //  Pet key / pose images (CheckPetMediaQuality → MediaQualityService::checkCandidate)
    // ──────────────────────────────────────────────────────────────

    /**
     * Check a pet / look key or pose image candidate and act on the verdict. Idempotent.
     *
     * @return 'promoted'|'retrying'|'needs_review'|'waiting'|'busy'|'skipped'
     */
    public function checkPetCandidate(PetMedia $slot): string
    {
        if ($slot->status !== PetMedia::STATUS_RUNNING || blank($slot->candidate_path) || ! $slot->isPoseStill()) {
            return 'skipped';
        }

        $candidate = (string) $slot->candidate_path;
        $mode = $this->stillsMode();

        if ($mode === AppSettingsService::QUALITY_OFF) {
            return $this->promotePet($slot, $candidate, null, null);
        }

        $check = PetMediaQualityCheck::query()->where('pet_media_id', $slot->id)->where('generation', $slot->generation)
            ->where('trigger', PetMediaQualityCheck::TRIGGER_PIPELINE)->first();

        if ($check === null || $check->status !== PetMediaQualityCheck::STATUS_DECIDED) {
            $gate = $this->gate();
            if ($gate === 'disabled') {
                return $this->promotePet($slot, $candidate, PetMedia::QUALITY_UNCHECKED, 'Not AI-checked (no vision profile / fal disabled)');
            }
            if ($gate === 'waiting') {
                Log::info('StillQualityService: still waits for the AI budget before its check', ['pet_media_id' => $slot->id]);

                return 'waiting';
            }

            $check = $this->claim(PetMediaQualityCheck::class, 'pet_media_id', $slot->id, $slot->generation, PetMediaQualityCheck::TRIGGER_PIPELINE);
            if ($check === null) {
                return 'busy';
            }

            if ($check->status !== PetMediaQualityCheck::STATUS_DECIDED
                && $this->judge($check, fn (): array => $this->vision->judgeStill(...$this->petRubric($slot), imageUrl: $this->petCandidateUrl($slot), petId: $slot->pet_id, petMediaId: $slot->id)) === 'waiting') {
                return 'waiting';
            }
            $check->refresh();
        }

        $fresh = $slot->fresh();
        if ($fresh === null || $fresh->status !== PetMedia::STATUS_RUNNING || $fresh->candidate_path !== $candidate) {
            return 'skipped'; // regenerated / decided meanwhile
        }

        if ($check->verdict === PetMediaQualityCheck::VERDICT_PASS) {
            return $this->promotePet($fresh, $candidate, self::passQuality($check), $check->reason);
        }

        if ($mode === AppSettingsService::QUALITY_SHADOW) {
            Log::warning('StillQualityService: still would fail the check (shadow mode — used anyway)', ['pet_media_id' => $slot->id, 'reason' => $check->reason]);

            return $this->promotePet($fresh, $candidate, PetMedia::QUALITY_SHADOW_FAILED, $check->reason);
        }

        if ($fresh->still_retries < self::maxRetries()) {
            return $this->retryPet($fresh, $candidate, (string) $check->reason) ? 'retrying' : 'skipped';
        }

        return $this->needsReviewPet($fresh, $candidate, (string) $check->reason) ? 'needs_review' : 'skipped';
    }

    /** promoteCandidate → stored, broadcast, its loops / clips follow (afterStored). */
    private function promotePet(PetMedia $slot, string $candidate, ?string $quality, ?string $reason): string
    {
        return $this->media->promoteCandidate($slot, $candidate, $quality, $reason) ? 'promoted' : 'skipped';
    }

    /** A failed still with retries left: a NEW request (fresh seed, a pose's next prompt variant). */
    private function retryPet(PetMedia $slot, string $candidate, string $reason): bool
    {
        $variant = $slot->isPose() ? min((int) $slot->prompt_variant + 1, PetAppearancePrompt::POSE_PROMPT_VARIANTS - 1) : (int) $slot->prompt_variant;

        $retried = PetMedia::query()->whereKey($slot->id)->where('status', PetMedia::STATUS_RUNNING)->where('candidate_path', $candidate)
            ->update([
                'status' => PetMedia::STATUS_PENDING,
                'generation' => DB::raw('generation + 1'),
                'seed_override' => PetMedia::freshSeed(),
                'still_retries' => DB::raw('still_retries + 1'),
                'prompt_variant' => $variant,
                'request_id' => null,
                'status_url' => null,
                'response_url' => null,
                'source_url' => null,
                'candidate_path' => null,
                'candidate_bytes' => null,
                'candidate_mime' => null,
                'error_reason' => null,
                'error' => mb_substr('Still check failed, regenerating automatically: '.$reason, 0, 2000),
                'updated_at' => now(),
            ]) === 1;

        if (! $retried) {
            return false;
        }

        if ($candidate !== $slot->storage_path) {
            $this->media->disk()->delete($candidate);
        }

        GeneratePetKeyframe::dispatch((int) $slot->id);
        Log::warning('StillQualityService: still failed the check — regenerating with a new seed', ['pet_media_id' => $slot->id, 'retry' => $slot->still_retries + 1, 'reason' => $reason]);

        return true;
    }

    /** Retries used up: not used (fails like any failed still), candidate kept for »Approve anyway«. */
    private function needsReviewPet(PetMedia $slot, string $candidate, string $reason): bool
    {
        $marked = DB::transaction(function () use ($slot, $candidate, $reason): bool {
            $locked = PetMedia::whereKey($slot->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== PetMedia::STATUS_RUNNING || $locked->candidate_path !== $candidate) {
                return false;
            }

            // fail(): status failed + quality_failed; a look row fails the pets waiting for it.
            $this->media->fail($locked, AiCallFailure::QualityFailed, 'Still check failed '.(self::maxRetries() + 1).'× — needs review: '.$reason);

            return true;
        });

        if (! $marked) {
            return false;
        }

        $this->broadcastFor($slot, MediaQualityService::EVENT_FAILED);
        Log::warning('StillQualityService: still failed the check after its retries — needs review', ['pet_media_id' => $slot->id, 'reason' => $reason]);

        // Poses: ActivityClipRetryService reports the chain once (simple mode + its alert). Key images
        // have no other fallback report: one informative Discord post (counts only).
        if ($slot->isKeyframe()) {
            SendStillQualityAlert::dispatch(1);
        }

        return true;
    }

    /**
     * Rubric, species and wanted scene of a pet / look still.
     *
     * @return array{rubric: string, species: Species, scene: string}
     */
    private function petRubric(PetMedia $slot): array
    {
        $species = $slot->isLookMedia() ? ($slot->look?->breed_type?->species() ?? Species::Dog) : ($slot->pet?->speciesValue() ?? Species::Dog);

        if (($pose = $slot->pose()) instanceof PetPose) {
            return $pose === PetPose::Empty
                ? ['rubric' => MediaVisionCheck::RUBRIC_EMPTY, 'species' => $species, 'scene' => 'the same room as in the pet\'s photographs, with nothing in the middle of the floor']
                : ['rubric' => MediaVisionCheck::RUBRIC_ANIMAL, 'species' => $species, 'scene' => $pose->prompt()];
        }

        $state = $slot->petState();

        return ['rubric' => MediaVisionCheck::RUBRIC_ANIMAL, 'species' => $species, 'scene' => $state !== null ? $state->keyPose($species) : 'in a natural pose'];
    }

    /** Signed URL fal fetches a pet slot's candidate from (GET /api/media/candidates/{media}). */
    private function petCandidateUrl(PetMedia $slot): string
    {
        $path = URL::temporarySignedRoute('media.candidate', $this->media->urlExpiry(30), ['media' => $slot->id, 'v' => substr(sha1((string) $slot->candidate_path), 0, 10)], absolute: false);

        return rtrim((string) config('app.url'), '/').$path;
    }

    // ──────────────────────────────────────────────────────────────
    //  Item media (CheckItemMediaQuality)
    // ──────────────────────────────────────────────────────────────

    /**
     * Check an item row's candidate (catalogue picture / key image: AI vision; clip: technical)
     * and act on the verdict. Idempotent.
     *
     * @return 'promoted'|'retrying'|'needs_review'|'waiting'|'busy'|'skipped'
     */
    public function checkItemCandidate(int $rowId): string
    {
        $row = ItemMedia::find($rowId);

        if ($row === null || $row->status !== ItemMedia::STATUS_RUNNING || blank($row->candidate_path)) {
            return 'skipped';
        }

        $candidate = (string) $row->candidate_path;
        $mode = $row->isClip() ? $this->settings->qualityCheckMode(fresh: true) : $this->stillsMode();

        if ($mode === AppSettingsService::QUALITY_OFF) {
            return $this->items->promoteCandidate($row, $candidate, null, null) ? 'promoted' : 'skipped';
        }

        $check = ItemMediaQualityCheck::query()->where('item_media_id', $row->id)->where('generation', $row->generation)
            ->where('trigger', PetMediaQualityCheck::TRIGGER_PIPELINE)->first();

        if ($check === null || $check->status !== PetMediaQualityCheck::STATUS_DECIDED) {
            if ($row->isStill()) {
                $gate = $this->gate();
                if ($gate === 'disabled') {
                    return $this->items->promoteCandidate($row, $candidate, PetMedia::QUALITY_UNCHECKED, 'Not AI-checked (no vision profile / fal disabled)') ? 'promoted' : 'skipped';
                }
                if ($gate === 'waiting') {
                    return 'waiting';
                }
            }

            $check = $this->claim(ItemMediaQualityCheck::class, 'item_media_id', $row->id, $row->generation, PetMediaQualityCheck::TRIGGER_PIPELINE);
            if ($check === null) {
                return 'busy';
            }

            if ($check->status !== PetMediaQualityCheck::STATUS_DECIDED) {
                if ($row->isClip()) {
                    $this->technicalItemClip($row, $check, $candidate);
                } elseif ($this->judge($check, fn (): array => $this->vision->judgeStill(...$this->itemRubric($row), imageUrl: $this->itemCandidateUrl($row), petId: $row->pet_id, itemMediaId: $row->id)) === 'waiting') {
                    return 'waiting';
                }
            }
            $check->refresh();
        }

        $fresh = $row->fresh();
        if ($fresh === null || $fresh->status !== ItemMedia::STATUS_RUNNING || $fresh->candidate_path !== $candidate) {
            return 'skipped';
        }

        if ($check->verdict === PetMediaQualityCheck::VERDICT_PASS) {
            return $this->items->promoteCandidate($fresh, $candidate, self::passQuality($check), $check->reason) ? 'promoted' : 'skipped';
        }

        if ($mode === AppSettingsService::QUALITY_SHADOW) {
            Log::warning('StillQualityService: item file would fail the check (shadow mode — used anyway)', ['item_media_id' => $row->id, 'reason' => $check->reason]);

            return $this->items->promoteCandidate($fresh, $candidate, PetMedia::QUALITY_SHADOW_FAILED, $check->reason) ? 'promoted' : 'skipped';
        }

        if ($fresh->quality_retries < self::maxRetries()) {
            $retried = $this->items->retryAfterCheck($fresh, $candidate, (string) $check->reason);
            if ($retried) {
                Log::warning('StillQualityService: item file failed the check — regenerating with a new seed', ['item_media_id' => $row->id, 'retry' => $fresh->quality_retries + 1, 'reason' => $check->reason]);
            }

            return $retried ? 'retrying' : 'skipped';
        }

        // heal() gives the chain up (fallback + one alert per run); »Approve anyway« shows the candidate.
        $failed = $this->items->failQuality($fresh, $candidate, (string) $check->reason);
        if ($failed) {
            Log::warning('StillQualityService: item file failed the check after its retries — needs review', ['item_media_id' => $row->id, 'reason' => $check->reason]);
        }

        return $failed ? 'needs_review' : 'skipped';
    }

    /**
     * Superadmin »Approve anyway« of an item candidate that failed its check (audited). The file is
     * stored and used (a key image → its clip follows).
     */
    public function approveItem(ItemMedia $row, User $actor): bool
    {
        $row->refresh();

        if (! $row->needsReview()) {
            return false;
        }

        $approved = $this->items->promoteCandidate($row, (string) $row->candidate_path, PetMedia::QUALITY_APPROVED, "Approved by superadmin #{$actor->id}", [ItemMedia::STATUS_FAILED]);

        if ($approved) {
            AppSettingChange::create([
                'key' => self::AUDIT_APPROVE_ITEM,
                'user_id' => $actor->id,
                'old' => ['quality' => 'needs_review', 'reason' => $row->error],
                'new' => ['item_media_id' => $row->id, 'scope' => $row->scope, 'kind' => $row->kind, 'design' => $row->design, 'generation' => $row->generation, 'quality' => PetMedia::QUALITY_APPROVED],
            ]);
        }

        return $approved;
    }

    /**
     * @return array{rubric: string, species: Species, scene: string}
     */
    private function itemRubric(ItemMedia $row): array
    {
        $description = ItemDesigns::description($row->design) ?? $row->design;

        if ($row->isPicture()) {
            return ['rubric' => MediaVisionCheck::RUBRIC_OBJECT, 'species' => Species::Dog, 'scene' => $description];
        }

        $species = $row->scope === ItemMedia::SCOPE_LOOK
            ? ($row->look?->breed_type?->species() ?? Species::Dog)
            : ($row->pet?->speciesValue() ?? Species::Dog);

        return ['rubric' => MediaVisionCheck::RUBRIC_ANIMAL, 'species' => $species, 'scene' => PetAppearancePrompt::itemScene($row->item_key, $species, $description)];
    }

    /** Signed URL fal fetches an item candidate from (GET /api/media/items/{itemMedia}/candidate). */
    private function itemCandidateUrl(ItemMedia $row): string
    {
        $path = URL::temporarySignedRoute('media.item-candidate', $this->media->urlExpiry(30), ['itemMedia' => $row->id, 'v' => substr(sha1((string) $row->candidate_path), 0, 10)], absolute: false);

        return rtrim((string) config('app.url'), '/').$path;
    }

    /** Superadmin link to an item candidate (Item media list), null without one. */
    public function itemCandidateLink(ItemMedia $row): ?string
    {
        return filled($row->candidate_path) ? $this->itemCandidateUrl($row) : null;
    }

    /** Free technical check of an item clip candidate (MediaClipAnalyzer) — decides the check row. */
    private function technicalItemClip(ItemMedia $row, ItemMediaQualityCheck $check, string $candidate): void
    {
        $local = $this->localCopy($candidate);
        $tech = ['ok' => null, 'reason' => null, 'failures' => [], 'metrics' => []];

        try {
            if ($local === null) {
                $tech = ['ok' => false, 'reason' => 'the clip file is missing', 'failures' => ['missing file'], 'metrics' => []];
            } elseif (($measured = $this->analyzer->measure($local, null)) === null) {
                $tech['reason'] = 'ffmpeg / ffprobe not available';
            } else {
                $low = in_array($row->item_key, (array) config('media.quality_check.low_motion_items', []), true);
                $result = MediaClipAnalyzer::evaluate($measured, $row->duration_seconds, false, $low);
                $tech = [
                    'ok' => $result['ok'],
                    'reason' => $result['ok'] === null ? 'no video frames could be measured' : ($result['failures'] === [] ? null : implode('; ', $result['failures'])),
                    'failures' => $result['failures'],
                    'metrics' => $result['metrics'],
                ];
            }
        } catch (Throwable $e) {
            $tech = ['ok' => null, 'reason' => 'technical check failed to run', 'failures' => [], 'metrics' => ['error' => mb_substr($e->getMessage(), 0, 200)]];
        } finally {
            if ($local !== null) {
                @unlink($local);
            }
        }

        $pass = $tech['ok'] !== false;
        $reason = match (true) {
            $tech['ok'] === false => 'Technical check: '.$tech['reason'],
            $tech['ok'] === null => 'Not checked: '.($tech['reason'] ?? 'ffmpeg / ffprobe not available'),
            default => null,
        };

        if ($tech['ok'] === null) {
            Log::error('StillQualityService: item clip could not be checked (used unchecked)', ['item_media_id' => $row->id, 'reason' => $reason]);
        }

        $check->update([
            'status' => PetMediaQualityCheck::STATUS_DECIDED,
            'technical' => ['metrics' => $tech['metrics'], 'failures' => $tech['failures']],
            'technical_ok' => $tech['ok'],
            'verdict' => $pass ? PetMediaQualityCheck::VERDICT_PASS : PetMediaQualityCheck::VERDICT_FAIL,
            'reason' => $reason !== null ? mb_substr($reason, 0, 2000) : null,
            'decided_at' => now(),
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    //  Stored stills (media:still-check)
    // ──────────────────────────────────────────────────────────────

    /**
     * READY item key images whose stored generation was never checked (made before 2026-11-04) — the
     * one-shot re-check (scheduled daily, self-limiting: a checked generation is never selected again).
     *
     * @return Builder<ItemMedia>
     */
    public function uncheckedItemImages(): Builder
    {
        return ItemMedia::query()->where('kind', ItemMedia::KIND_IMAGE)->where('status', ItemMedia::STATUS_READY)
            ->whereNotNull('storage_path')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('item_media_quality_checks')
                ->whereColumn('item_media_quality_checks.item_media_id', 'item_media.id')
                ->whereColumn('item_media_quality_checks.generation', 'item_media.generation'))
            ->orderBy('id');
    }

    /**
     * READY pet key / pose images (file owners: own-media slots and look rows) never checked — only
     * through `media:still-check --pets` (David: no bulk re-check of pet key images; cost shown first).
     *
     * @return Builder<PetMedia>
     */
    public function uncheckedPetStills(): Builder
    {
        return PetMedia::query()->whereIn('kind', [PetMedia::KIND_KEYFRAME, PetMedia::KIND_POSE])
            ->where('status', PetMedia::STATUS_READY)->whereNotNull('storage_path')->whereNull('look_media_id')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('pet_media_quality_checks')
                ->whereColumn('pet_media_quality_checks.pet_media_id', 'pet_media.id')
                ->whereColumn('pet_media_quality_checks.generation', 'pet_media.generation'))
            ->orderBy('id');
    }

    /**
     * Check one STORED item key image. Fail + stills mode enforce → the row is regenerated (a new
     * chain whose first try counts as one of its `max_still_retries`; the clip follows the new key
     * image); shadow → only recorded. The stored file stays shown until a new one passes.
     *
     * @return array{outcome: string, reason: string|null, cost_usd: float}
     */
    public function recheckItem(ItemMedia $row): array
    {
        $path = (string) $row->storage_path;
        $check = $this->claim(ItemMediaQualityCheck::class, 'item_media_id', $row->id, $row->generation, PetMediaQualityCheck::TRIGGER_COMMAND);

        if ($check === null) {
            return ['outcome' => 'busy', 'reason' => null, 'cost_usd' => 0.0];
        }

        if ($check->status !== PetMediaQualityCheck::STATUS_DECIDED
            && $this->judge($check, fn (): array => $this->vision->judgeStill(...$this->itemRubric($row), imageUrl: $this->items->falFetchUrl($row), petId: $row->pet_id, itemMediaId: $row->id)) === 'waiting') {
            return ['outcome' => 'waiting', 'reason' => 'no AI budget now', 'cost_usd' => 0.0];
        }
        $check->refresh();

        if ($check->verdict === PetMediaQualityCheck::VERDICT_PASS) {
            $this->items->recordQuality($row, $path, self::passQuality($check), $check->reason);

            return ['outcome' => 'passed', 'reason' => $check->reason, 'cost_usd' => (float) $check->ai_cost_usd];
        }

        $enforce = $this->stillsMode() === AppSettingsService::QUALITY_ENFORCE;
        $this->items->recordQuality($row, $path, $enforce ? PetMedia::QUALITY_FLAGGED : PetMedia::QUALITY_SHADOW_FAILED, $check->reason);

        if ($enforce && ($fresh = $row->fresh()) !== null && $fresh->status === ItemMedia::STATUS_READY && $fresh->storage_path === $path) {
            $this->items->resetChain($fresh, null, qualityRetries: min(1, self::maxRetries()));
            GenerateItemMedia::dispatch((int) $fresh->id);

            return ['outcome' => 'regenerating', 'reason' => $check->reason, 'cost_usd' => (float) $check->ai_cost_usd];
        }

        return ['outcome' => 'failed', 'reason' => $check->reason, 'cost_usd' => (float) $check->ai_cost_usd];
    }

    /**
     * Check one STORED pet key / pose image — verdict recorded only (fail = `shadow_failed`, never
     * hidden, »Approve anyway« / Regenerate in /admin). Never regenerates on its own.
     *
     * @return array{outcome: string, reason: string|null, cost_usd: float}
     */
    public function recheckPet(PetMedia $row): array
    {
        $path = (string) $row->storage_path;
        $check = $this->claim(PetMediaQualityCheck::class, 'pet_media_id', $row->id, $row->generation, PetMediaQualityCheck::TRIGGER_COMMAND);

        if ($check === null) {
            return ['outcome' => 'busy', 'reason' => null, 'cost_usd' => 0.0];
        }

        if ($check->status !== PetMediaQualityCheck::STATUS_DECIDED
            && $this->judge($check, fn (): array => $this->vision->judgeStill(...$this->petRubric($row), imageUrl: $this->media->falFetchUrl($row), petId: $row->pet_id, petMediaId: $row->id)) === 'waiting') {
            return ['outcome' => 'waiting', 'reason' => 'no AI budget now', 'cost_usd' => 0.0];
        }
        $check->refresh();

        $ok = $check->verdict === PetMediaQualityCheck::VERDICT_PASS;
        PetMedia::query()->whereKey($row->id)->where('storage_path', $path)
            ->where(fn ($q) => $q->whereNull('quality')->orWhere('quality', '!=', PetMedia::QUALITY_APPROVED))
            ->update(['quality' => $ok ? self::passQuality($check) : PetMedia::QUALITY_SHADOW_FAILED, 'quality_reason' => $check->reason]);

        return ['outcome' => $ok ? 'passed' : 'failed', 'reason' => $check->reason, 'cost_usd' => (float) $check->ai_cost_usd];
    }

    // ──────────────────────────────────────────────────────────────
    //  Shared
    // ──────────────────────────────────────────────────────────────

    /** 'ok' = a vision call may run; 'disabled' = no profile / fal off; 'waiting' = no budget / balance. */
    private function gate(): string
    {
        return match ($this->vision->unavailableStatus()) {
            null => 'ok',
            PetMediaQualityCheck::AI_DISABLED => 'disabled',
            default => 'waiting',
        };
    }

    /**
     * The claim: one check row per (row, generation, trigger). Null = another worker runs it now;
     * a dead worker's `running` row (older than MediaQualityService::STALE_CHECK_MINUTES) is taken over.
     *
     * @template T of PetMediaQualityCheck|ItemMediaQualityCheck
     *
     * @param  class-string<T>  $model
     * @return T|null
     */
    private function claim(string $model, string $column, int $id, int $generation, string $trigger): PetMediaQualityCheck|ItemMediaQualityCheck|null
    {
        $inserted = $model::query()->insertOrIgnore([
            $column => $id,
            'generation' => $generation,
            'trigger' => $trigger,
            'status' => PetMediaQualityCheck::STATUS_RUNNING,
            'ai_status' => PetMediaQualityCheck::AI_NOT_RUN,
            'ai_cost_usd' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $check = $model::query()->where($column, $id)->where('generation', $generation)->where('trigger', $trigger)->first();

        if ($check === null) {
            return null;
        }

        if ($inserted === 1 || $check->status === PetMediaQualityCheck::STATUS_DECIDED) {
            return $check;
        }

        $stale = $model::query()->whereKey($check->id)->where('status', PetMediaQualityCheck::STATUS_RUNNING)
            ->where('updated_at', '<', now()->subMinutes(MediaQualityService::STALE_CHECK_MINUTES))
            ->update(['updated_at' => now()]) === 1;

        return $stale ? $check->fresh() : null;
    }

    /**
     * Run the paid vision call of a CLAIMED check once and decide it. A dead worker's call in flight
     * (`calling`) is never repeated (decided as not AI-checked). A refusal that cost nothing (budget /
     * balance hit between the gate and the call) releases the claim → 'waiting'.
     *
     * @param  callable(): array{status: string, verdict: array<string, mixed>|null, reason: string|null, cost_usd: float, reported_cost_usd: float|null}  $call
     * @return 'decided'|'waiting'
     */
    private function judge(PetMediaQualityCheck|ItemMediaQualityCheck $check, callable $call): string
    {
        if ($check->ai_status === PetMediaQualityCheck::AI_CALLING) {
            $ai = ['status' => PetMediaQualityCheck::AI_ERROR, 'verdict' => null, 'reason' => 'the vision call of a dead worker (not repeated)', 'cost_usd' => (float) $check->ai_cost_usd, 'reported_cost_usd' => null];
        } else {
            $check->update(['ai_status' => PetMediaQualityCheck::AI_CALLING]);
            $ai = $call();

            if (in_array($ai['status'], [PetMediaQualityCheck::AI_SKIPPED_BUDGET, PetMediaQualityCheck::AI_DISABLED], true) && (float) $ai['cost_usd'] === 0.0) {
                $check::query()->whereKey($check->id)->where('status', PetMediaQualityCheck::STATUS_RUNNING)->delete();

                return 'waiting';
            }
        }

        $failed = $ai['status'] === PetMediaQualityCheck::AI_FAILED;
        $reason = match (true) {
            $failed => (string) $ai['reason'],
            $ai['status'] === PetMediaQualityCheck::AI_PASSED => null,
            default => 'Not AI-checked ('.$ai['status'].($ai['reason'] !== null ? ': '.$ai['reason'] : '').')',
        };

        $check->update([
            'status' => PetMediaQualityCheck::STATUS_DECIDED,
            'ai_status' => $ai['status'],
            'ai_verdict' => $ai['verdict'],
            'ai_cost_usd' => round((float) $ai['cost_usd'], 4),
            'ai_reported_cost_usd' => $ai['reported_cost_usd'],
            'verdict' => $failed ? PetMediaQualityCheck::VERDICT_FAIL : PetMediaQualityCheck::VERDICT_PASS,
            'reason' => $reason !== null ? mb_substr($reason, 0, 2000) : null,
            'decided_at' => now(),
        ]);

        Log::info('StillQualityService: checked', ['check' => $check::class, 'id' => $check->id, 'verdict' => $failed ? 'fail' : 'pass', 'ai' => $ai['status'], 'reason' => $reason]);

        return 'decided';
    }

    /** `quality` of a passed check: AI passed → passed; anything not AI-judged → unchecked / passed_technical. */
    private static function passQuality(PetMediaQualityCheck|ItemMediaQualityCheck $check): string
    {
        return match (true) {
            $check->ai_status === PetMediaQualityCheck::AI_PASSED => PetMedia::QUALITY_PASSED,
            $check->technical_ok === true => PetMedia::QUALITY_PASSED_TECHNICAL,
            default => PetMedia::QUALITY_UNCHECKED,
        };
    }

    /** One PetUpdated per pet that shows this row (its own pet, or every pet linked to the look row). */
    private function broadcastFor(PetMedia $row, string $eventType): void
    {
        $petIds = $row->isLookMedia()
            ? PetMedia::query()->where('look_media_id', $row->id)->pluck('pet_id')->filter()->unique()->all()
            : array_filter([$row->pet_id]);

        foreach (Pet::query()->whereIn('id', $petIds)->get() as $pet) {
            PetUpdated::afterCommit($pet, $eventType);
        }
    }

    /** A temp copy of a media file (ffmpeg needs a path). */
    private function localCopy(string $path): ?string
    {
        $disk = $this->media->disk();

        if ($path === '' || ! $disk->exists($path)) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), MediaDownloader::TEMP_PREFIX);
        $in = $disk->readStream($path);
        $out = fopen($tmp, 'wb');

        if ($in === null || $out === false) {
            @unlink($tmp);

            return null;
        }

        stream_copy_to_stream($in, $out);
        fclose($out);
        if (is_resource($in)) {
            fclose($in);
        }

        return $tmp;
    }
}
