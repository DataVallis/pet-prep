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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
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
 *  Item CLIPS get the free technical check (MediaClipAnalyzer: length, frozen, jumps; the clip
 *  check's mode) and, since David 2026-11-19, the AI realism check (contact sheet + start frame,
 *  rubric `item_clip`, the ITEM mode while `clip_ai` is on) — decideItemClip(). Item key images use
 *  the `item` rubric (grounded / integrated / scale). Stored files: recheckItemRealism() (media:item-recheck).
 *  The reference image is NOT checked: it is generated during pairing, its failure path (pets.
 *  media_status, stage remakes) is separate, and every key / pose image made from it is checked.
 *
 * Modes (independent of the clip mode, which stays shadow while it is calibrated):
 * `app_settings.quality_check.stills_mode` for ITEM key images + catalogue pictures (default
 * enforce, env AI_QUALITY_STILLS_MODE) and `pet_stills_mode` for PET / look key + pose images
 * (QA #736 M2: default shadow until David has looked at the verdicts, env AI_QUALITY_PET_STILLS_MODE):
 *  - enforce: pass → stored; fail → a NEW request (fresh seed; poses / items: the next prompt
 *    variant) ≤ `max_still_retries` (2) per chain; then »Needs review« (failed `quality_failed`,
 *    candidate kept for »Approve anyway«) and the existing self-healing fallback: pose → the
 *    activity's simple mode (ActivityClipRetryService), item → shown without that content
 *    (ItemMediaService::heal(), one alert per run), key image → no loop for that state + one
 *    Discord post per ALERT_WINDOW_MINUTES with the count (SendStillQualityAlert, counts only);
 *  - a »Needs review« still (own slot or shared look row) is never claimed again by an automatic
 *    path (QA #736 B1 — PetMediaService::claim / generateLookKeyframe / queuePoseStill); only an
 *    explicit Regenerate (new generation, new seed) or »Approve anyway« touches it;
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

    /** Cache keys + window of the aggregated key image alert (QA #736 m1). */
    public const ALERT_COUNT_KEY = 'still-quality-alert:count';

    public const ALERT_WINDOW_KEY = 'still-quality-alert:window';

    public const ALERT_WINDOW_MINUTES = 15;

    /** off | shadow | enforce of the ITEM still check (fresh read — workers follow the switch at once). */
    public function stillsMode(): string
    {
        return $this->settings->qualityStillsMode(fresh: true);
    }

    /** off | shadow | enforce of the PET / look key + pose image check (QA #736 M2; fresh read). */
    public function petStillsMode(): string
    {
        return $this->settings->qualityPetStillsMode(fresh: true);
    }

    /**
     * QA #736 m1: count one given-up key image and send ONE Discord post per window with the count
     * (the first one opens the window and queues the post, delayed to its end).
     */
    public static function countAlert(): void
    {
        Cache::add(self::ALERT_COUNT_KEY, 0, now()->addDay());
        Cache::increment(self::ALERT_COUNT_KEY);

        if (Cache::add(self::ALERT_WINDOW_KEY, true, now()->addMinutes(self::ALERT_WINDOW_MINUTES + 5))) {
            SendStillQualityAlert::dispatch()->delay(now()->addMinutes(self::ALERT_WINDOW_MINUTES));
        }
    }

    /** The count of the window that ends now (the window closes first, so a new failure opens the next one). */
    public static function takeAlertCount(): int
    {
        Cache::forget(self::ALERT_WINDOW_KEY);

        return (int) Cache::pull(self::ALERT_COUNT_KEY, 0);
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
        $mode = $this->petStillsMode();

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
            self::countAlert();
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
     * QA #1414 m2: a vision call without a usable verdict (timeout, malformed answer, a dead
     * worker's call) is not used silently — the check is released and the job tries again later
     * ('retry'), at most VISION_ERROR_RETRIES times per generation; then the file is used
     * »not AI-checked« (quality unchecked, System health).
     *
     * @return 'promoted'|'retrying'|'needs_review'|'fallback'|'waiting'|'busy'|'skipped'|'retry'
     */
    public function checkItemCandidate(int $rowId): string
    {
        $row = ItemMedia::find($rowId);

        if ($row === null || $row->status !== ItemMedia::STATUS_RUNNING || blank($row->candidate_path)) {
            return 'skipped';
        }

        $candidate = (string) $row->candidate_path;
        // David 2026-11-19: an item clip has two parts — the free technical check (clip `mode`) and the
        // AI realism check (the ITEM mode `stills_mode`, while `clip_ai` is on).
        $clipModes = $row->isClip() ? $this->itemClipModes() : null;
        $mode = $clipModes !== null ? $this->strongest($clipModes['technical'], $clipModes['ai']) : $this->stillsMode();

        if ($mode === AppSettingsService::QUALITY_OFF) {
            return $this->items->promoteCandidate($row, $candidate, null, null) ? 'promoted' : 'skipped';
        }

        $check = ItemMediaQualityCheck::query()->where('item_media_id', $row->id)->where('generation', $row->generation)
            ->where('trigger', PetMediaQualityCheck::TRIGGER_PIPELINE)->first();

        if ($check === null || $check->status !== PetMediaQualityCheck::STATUS_DECIDED) {
            $gate = $row->isStill() || $clipModes['ai'] !== AppSettingsService::QUALITY_OFF ? $this->gate() : 'ok';
            $aiOffReason = $clipModes !== null && $clipModes['ai'] === AppSettingsService::QUALITY_OFF
                ? ($this->settings->qualityClipAi(fresh: true) ? 'item check switched off' : 'clip AI call switched off')
                : null;
            if ($gate === 'disabled') {
                if ($row->isStill()) {
                    return $this->items->promoteCandidate($row, $candidate, PetMedia::QUALITY_UNCHECKED, 'Not AI-checked (no vision profile / fal disabled)') ? 'promoted' : 'skipped';
                }
                $clipModes['ai'] = AppSettingsService::QUALITY_OFF; // no vision → the technical check alone
                $aiOffReason = 'no vision profile / fal disabled';
            }
            if ($gate === 'waiting') {
                return 'waiting';
            }

            $check = $this->claim(ItemMediaQualityCheck::class, 'item_media_id', $row->id, $row->generation, PetMediaQualityCheck::TRIGGER_PIPELINE);
            if ($check === null) {
                return 'busy';
            }

            if ($check->status !== PetMediaQualityCheck::STATUS_DECIDED) {
                if ($row->isClip()) {
                    if ($this->decideItemClip($row, $check, $candidate, $clipModes['technical'], $clipModes['ai'], $row->target_path, $aiOffReason) === 'waiting') {
                        return 'waiting';
                    }
                } elseif ($this->judge($check, fn (): array => $this->vision->judgeStill(...$this->itemRubric($row), imageUrl: $this->itemCandidateUrl($row), petId: $row->pet_id, itemMediaId: $row->id), $this->itemRubric($row)['rubric']) === 'waiting') {
                    return 'waiting';
                }
            }
            $check->refresh();

            if ($this->retryAfterVisionError($row, $check)) {
                return 'retry';
            }
        }

        $fresh = $row->fresh();
        if ($fresh === null || $fresh->status !== ItemMedia::STATUS_RUNNING || $fresh->candidate_path !== $candidate) {
            return 'skipped';
        }

        if ($check->verdict === PetMediaQualityCheck::VERDICT_PASS) {
            return $this->items->promoteCandidate($fresh, $candidate, self::passQuality($check), $check->reason) ? 'promoted' : 'skipped';
        }

        // The mode of the part that failed (a clip: the AI part → the item mode, else the clip mode).
        $failMode = $clipModes === null ? $mode
            : ($check->ai_status === PetMediaQualityCheck::AI_FAILED ? $this->itemClipModes()['ai'] : $this->itemClipModes()['technical']);

        if ($failMode !== AppSettingsService::QUALITY_ENFORCE) {
            Log::warning('StillQualityService: item file would fail the check (shadow mode — used anyway)', ['item_media_id' => $row->id, 'reason' => $check->reason]);

            return $this->items->promoteCandidate($fresh, $candidate, PetMedia::QUALITY_SHADOW_FAILED, $check->reason) ? 'promoted' : 'skipped';
        }

        if ($fresh->quality_retries < self::maxRetries()) {
            // David 2026-11-19: the clip check found the OBJECT wrong (floating / pasted / too small) —
            // that comes from its start frame: the key image is regenerated, the clip follows it.
            if ($fresh->isClip() && self::placementFailed($check) && $this->items->regenerateKeyImageOf($fresh, $candidate, (string) $check->reason)) {
                Log::warning('StillQualityService: item clip shows the object wrong — regenerating its key image', ['item_media_id' => $row->id, 'reason' => $check->reason]);

                return 'retrying';
            }
            $retried = $this->items->retryAfterCheck($fresh, $candidate, (string) $check->reason);
            if ($retried) {
                Log::warning('StillQualityService: item file failed the check — regenerating with a new seed', ['item_media_id' => $row->id, 'retry' => $fresh->quality_retries + 1, 'reason' => $check->reason]);
            }

            return $retried ? 'retrying' : 'skipped';
        }

        // David 2026-11-19: a durable item (bed, bowls, toys) is given up AT ONCE — its last passed key
        // image is shown (or the item without content), media_ready true, one counting alert; no review wait.
        if ($this->items->givesUpAtOnce($fresh)) {
            $given = $this->items->giveUpQuality($fresh, $candidate, (string) $check->reason);
            if ($given) {
                Log::warning('StillQualityService: durable item file failed the check after its retries — given up, fallback shown', ['item_media_id' => $row->id, 'reason' => $check->reason]);
            }

            return $given ? 'fallback' : 'skipped';
        }

        // Treats / catalogue pictures: heal() gives the chain up (fallback / locked + one alert per run);
        // »Approve anyway« shows the candidate.
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

        $species = $this->itemSpecies($row);

        // David 2026-11-19: the item rubric — the animal questions + grounded / integrated / scale.
        return ['rubric' => MediaVisionCheck::RUBRIC_ITEM, 'species' => $species, 'scene' => $this->itemSceneOf($row, $species)];
    }

    private function itemSpecies(ItemMedia $row): Species
    {
        return $row->scope === ItemMedia::SCOPE_LOOK
            ? ($row->look?->breed_type?->species() ?? Species::Dog)
            : ($row->pet?->speciesValue() ?? Species::Dog);
    }

    /** What the item key image / clip should show — the prompt's action + the size rule of the breed. */
    private function itemSceneOf(ItemMedia $row, Species $species): string
    {
        $description = ItemDesigns::description($row->design) ?? $row->design;
        $breedKey = $row->scope === ItemMedia::SCOPE_LOOK ? $row->look?->breed_type?->value : $row->pet?->breed_type?->value;

        // 2026-11-20: sized for the animal's CURRENT stage (a puppy's bowls / bed for the puppy as shown).
        return PetAppearancePrompt::itemScene($row->item_key, $species, $description, $breedKey !== null ? $this->items->sizeOf($breedKey, $species, $row->stage()) : null, $row->stage());
    }

    /** David 2026-11-20: the scene of the bowls' drinking clip (the animal at the bowls, head over the water bowl). */
    private function itemDrinkSceneOf(ItemMedia $row, Species $species): string
    {
        $description = ItemDesigns::description($row->design) ?? $row->design;
        $breedKey = $row->scope === ItemMedia::SCOPE_LOOK ? $row->look?->breed_type?->value : $row->pet?->breed_type?->value;

        return PetAppearancePrompt::itemDrinkScene($species, $description, $breedKey !== null ? $this->items->sizeOf($breedKey, $species, $row->stage()) : null, $row->stage());
    }

    /** QA #1459 m4: the scene of the bowls' EATING clip (neutral start pose, then into the bowl with kibble). */
    private function itemEatSceneOf(ItemMedia $row, Species $species): string
    {
        $description = ItemDesigns::description($row->design) ?? $row->design;
        $breedKey = $row->scope === ItemMedia::SCOPE_LOOK ? $row->look?->breed_type?->value : $row->pet?->breed_type?->value;

        return PetAppearancePrompt::itemEatScene($species, $description, $breedKey !== null ? $this->items->sizeOf($breedKey, $species, $row->stage()) : null, $row->stage());
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

    /**
     * David 2026-11-19: the modes of an item clip's two checks — `technical` = the clip `mode` (free
     * MediaClipAnalyzer check, shadow until calibrated), `ai` = the ITEM mode `stills_mode` (default
     * enforce) while the clip check's AI call `clip_ai` is on, else off. Fresh reads.
     *
     * @return array{technical: string, ai: string}
     */
    public function itemClipModes(): array
    {
        return [
            'technical' => $this->settings->qualityCheckMode(fresh: true),
            'ai' => $this->settings->qualityClipAi(fresh: true) ? $this->stillsMode() : AppSettingsService::QUALITY_OFF,
        ];
    }

    /** The stricter of two modes (off < shadow < enforce). */
    private function strongest(string $a, string $b): string
    {
        $rank = [AppSettingsService::QUALITY_OFF => 0, AppSettingsService::QUALITY_SHADOW => 1, AppSettingsService::QUALITY_ENFORCE => 2];

        return ($rank[$a] ?? 0) >= ($rank[$b] ?? 0) ? $a : $b;
    }

    /** QA #1414 m2: tries of an item check whose vision call gave no verdict (per row + generation). */
    public const VISION_ERROR_RETRIES = 2;

    /**
     * A vision error of a call that was made (not a budget wait, not a missing contact sheet / stale
     * start frame — those would only repeat) releases the decided check so the job can run it again, ≤ VISION_ERROR_RETRIES
     * per generation (cache counter). True = released (the caller answers 'retry').
     */
    private function retryAfterVisionError(ItemMedia $row, ItemMediaQualityCheck $check): bool
    {
        // Only a vision call that was really made (it cost something) and gave no verdict; a check
        // that never called (no contact sheet, stale start frame, ffmpeg missing) would only repeat.
        $reason = (string) $check->reason;
        if ($check->ai_status !== PetMediaQualityCheck::AI_ERROR || (float) $check->ai_cost_usd <= 0.0) {
            return false;
        }

        $key = "item-check-vision-error:{$row->id}:{$row->generation}";
        Cache::add($key, 0, now()->addDay());
        if ((int) Cache::increment($key) > self::VISION_ERROR_RETRIES) {
            return false;
        }

        if (filled($check->sheet_path)) {
            $this->media->disk()->delete((string) $check->sheet_path);
        }
        ItemMediaQualityCheck::query()->whereKey($check->id)->delete();
        Log::warning('StillQualityService: the item check got no verdict — trying the check again', ['item_media_id' => $row->id, 'reason' => $reason]);

        return true;
    }

    /** Did an item check fail on the OBJECT's placement (grounded / integrated / scale)? */
    public static function placementFailed(ItemMediaQualityCheck $check): bool
    {
        $verdict = is_array($check->ai_verdict) ? $check->ai_verdict : [];

        return $check->ai_status === PetMediaQualityCheck::AI_FAILED
            && collect(MediaVisionCheck::ITEM_PLACEMENT_KEYS)->contains(fn (string $k): bool => array_key_exists($k, $verdict) && $verdict[$k] !== true);
    }

    /**
     * Check an item clip file (a CLAIMED check row) and decide the row: (1) the free technical check
     * (MediaClipAnalyzer: length, frozen, jumps; the bed is low motion) + a contact sheet; (2) the AI
     * realism check (MediaVisionCheck::judgeItemClip: contact sheet + the start frame = its key image)
     * unless the AI part is off or the technical part already failed in enforce. Verdict fail = a
     * failed AI part, or a failed technical part while the clip mode is on. The paid call is marked
     * `calling` first (a stale takeover never repeats it); a refusal that cost nothing (budget) deletes
     * the claim → 'waiting'. The contact sheet is kept only for a failed clip.
     *
     * @return 'decided'|'waiting'
     */
    private function decideItemClip(ItemMedia $row, ItemMediaQualityCheck $check, string $path, string $techMode, string $aiMode, ?string $madeFrom, ?string $aiOffReason = null): string
    {
        $wantAi = $aiMode !== AppSettingsService::QUALITY_OFF;
        $tech = $this->itemClipTechnical($row, $check, $path, $wantAi);
        $ai = ['status' => PetMediaQualityCheck::AI_NOT_RUN, 'verdict' => null, 'reason' => null, 'cost_usd' => 0.0, 'reported_cost_usd' => null];
        $skipAi = $tech['ok'] === false && $techMode === AppSettingsService::QUALITY_ENFORCE; // regenerated anyway

        if ($check->ai_status === PetMediaQualityCheck::AI_CALLING) {
            $ai = ['status' => PetMediaQualityCheck::AI_ERROR, 'verdict' => null, 'reason' => 'the vision call of a dead worker (not repeated)', 'cost_usd' => (float) $check->ai_cost_usd, 'reported_cost_usd' => null];
        } elseif ($wantAi && ! $skipAi) {
            $image = $this->items->keyImageOf($row);
            if ($tech['sheet_path'] === null || $image === null) {
                $ai = ['status' => PetMediaQualityCheck::AI_ERROR, 'verdict' => null, 'reason' => 'no contact sheet / start frame', 'cost_usd' => 0.0, 'reported_cost_usd' => null];
            } elseif ($madeFrom === null || $image->storage_path !== $madeFrom || ! $image->isShown()) {
                // QA #1414 m4: the start frame sent must be the key image the clip was made from (and shown).
                $ai = ['status' => PetMediaQualityCheck::AI_ERROR, 'verdict' => null, 'reason' => 'the clip was not made from the current key image', 'cost_usd' => 0.0, 'reported_cost_usd' => null];
            } else {
                $check->update(['sheet_path' => $tech['sheet_path'], 'rubric' => MediaVisionCheck::RUBRIC_ITEM_CLIP, 'ai_status' => PetMediaQualityCheck::AI_CALLING]);
                $species = $this->itemSpecies($row);
                // David 2026-11-20: the bowls' drinking clip is judged on drinking, not on eating.
                $ai = $this->vision->judgeItemClip($species,
                    $row->isDrinkClip() ? PetAppearancePrompt::itemDrinkClipIntent($species) : PetAppearancePrompt::itemClipIntent($row->item_key, $species),
                    $row->isDrinkClip() ? $this->itemDrinkSceneOf($row, $species)
                        : ($row->item_key === 'bowls' ? $this->itemEatSceneOf($row, $species) : $this->itemSceneOf($row, $species)),
                    $this->itemSheetUrl($check), $this->items->falFetchUrl($image), $row->pet_id, (int) $row->id);

                if (in_array($ai['status'], [PetMediaQualityCheck::AI_SKIPPED_BUDGET, PetMediaQualityCheck::AI_DISABLED], true) && (float) $ai['cost_usd'] === 0.0) {
                    $this->media->disk()->delete($tech['sheet_path']);
                    ItemMediaQualityCheck::query()->whereKey($check->id)->where('status', PetMediaQualityCheck::STATUS_RUNNING)->delete();

                    return 'waiting';
                }
            }
        }

        $techFails = $tech['ok'] === false && $techMode !== AppSettingsService::QUALITY_OFF;
        $aiFails = $ai['status'] === PetMediaQualityCheck::AI_FAILED;
        $pass = ! $techFails && ! $aiFails;
        $reason = match (true) {
            $aiFails => (string) $ai['reason'],
            $techFails => 'Technical check: '.$tech['reason'],
            $tech['ok'] === null => 'Not checked: '.($tech['reason'] ?? 'ffmpeg / ffprobe not available'),
            $ai['status'] === PetMediaQualityCheck::AI_PASSED || $skipAi => null,
            // QA #1414 m1: like a pet clip without its AI call — »not AI-checked«, with why.
            ! $wantAi => 'Not AI-checked ('.($aiOffReason ?? 'clip AI call or item check switched off').')',
            default => 'Not AI-checked ('.$ai['status'].($ai['reason'] !== null ? ': '.$ai['reason'] : '').')',
        };

        $check->update([
            'status' => PetMediaQualityCheck::STATUS_DECIDED,
            'technical' => ['metrics' => $tech['metrics'], 'failures' => $tech['failures']],
            'technical_ok' => $tech['ok'],
            'ai_status' => $ai['status'],
            'ai_verdict' => $ai['verdict'],
            'ai_cost_usd' => round((float) $ai['cost_usd'], 4),
            'ai_reported_cost_usd' => $ai['reported_cost_usd'],
            'verdict' => $pass ? PetMediaQualityCheck::VERDICT_PASS : PetMediaQualityCheck::VERDICT_FAIL,
            'reason' => $reason !== null ? mb_substr($reason, 0, 2000) : null,
            // The AI realism part was due (it decides `quality` — passQuality()); null = technical only.
            'rubric' => $wantAi && ! $skipAi ? MediaVisionCheck::RUBRIC_ITEM_CLIP : null,
            'sheet_path' => $pass ? null : $tech['sheet_path'],
            'decided_at' => now(),
        ]);

        // The contact sheet is kept only for a failed clip (admin review); a pass frees the disk.
        if ($pass && $tech['sheet_path'] !== null) {
            $this->media->disk()->delete($tech['sheet_path']);
        }

        if ($tech['ok'] === null || ($wantAi && ! $skipAi && ! in_array($ai['status'], [PetMediaQualityCheck::AI_PASSED, PetMediaQualityCheck::AI_FAILED], true))) {
            Log::error('StillQualityService: item clip not fully checked (used unchecked)', ['item_media_id' => $row->id, 'ai' => $ai['status'], 'reason' => $reason]);
        }
        Log::info('StillQualityService: item clip checked', ['item_media_id' => $row->id, 'generation' => $check->generation, 'verdict' => $pass ? 'pass' : 'fail', 'ai' => $ai['status'], 'reason' => $reason]);

        return 'decided';
    }

    /**
     * The free technical check of an item clip file (+ its contact sheet when the AI part needs one).
     *
     * @return array{ok: bool|null, reason: string|null, failures: list<string>, metrics: array<string, mixed>, sheet_path: string|null}
     */
    private function itemClipTechnical(ItemMedia $row, ItemMediaQualityCheck $check, string $path, bool $withSheet): array
    {
        $local = $this->localCopy($path);
        $tech = ['ok' => null, 'reason' => null, 'failures' => [], 'metrics' => [], 'sheet_path' => null];

        try {
            if ($local === null) {
                return ['ok' => false, 'reason' => 'the clip file is missing', 'failures' => ['missing file'], 'metrics' => [], 'sheet_path' => null];
            }
            if (($measured = $this->analyzer->measure($local, null)) === null) {
                $tech['reason'] = 'ffmpeg / ffprobe not available';

                return $tech;
            }
            $low = in_array($row->item_key, (array) config('media.quality_check.low_motion_items', []), true)
                || in_array($row->kind, (array) config('media.quality_check.low_motion_item_kinds', []), true);
            $result = MediaClipAnalyzer::evaluate($measured, $row->duration_seconds, false, $low);
            $tech = [
                'ok' => $result['ok'],
                'reason' => $result['ok'] === null ? 'no video frames could be measured' : ($result['failures'] === [] ? null : implode('; ', $result['failures'])),
                'failures' => $result['failures'],
                'metrics' => $result['metrics'],
                'sheet_path' => null,
            ];

            if ($withSheet && $result['ok'] !== null) {
                $base = tempnam(sys_get_temp_dir(), MediaDownloader::TEMP_PREFIX);
                $tmp = $base.'.jpg';
                $sheet = (array) config('media.quality_check.item_sheet', []);
                if ($this->analyzer->contactSheet($local, (float) ($measured['duration'] ?? $row->duration_seconds ?? 3), $tmp, $sheet['frames'] ?? null, $sheet['columns'] ?? null, $sheet['frame_width'] ?? null)) {
                    $tech['sheet_path'] = sprintf('%s/qc-item-%d-g%d-%s.jpg', dirname($path), $row->id, $check->generation, $check->trigger);
                    $this->media->disk()->put($tech['sheet_path'], (string) file_get_contents($tmp));
                }
                @unlink($tmp);
                @unlink($base);
            }

            return $tech;
        } catch (Throwable $e) {
            return ['ok' => null, 'reason' => 'technical check failed to run', 'failures' => [], 'metrics' => ['error' => mb_substr($e->getMessage(), 0, 200)], 'sheet_path' => $tech['sheet_path']];
        } finally {
            if ($local !== null) {
                @unlink($local);
            }
        }
    }

    /** Signed URL fal fetches an item clip's contact sheet from (GET /api/media/item-quality-sheets/{check}). */
    public function itemSheetUrl(ItemMediaQualityCheck $check): string
    {
        $path = URL::temporarySignedRoute('media.item-quality-sheet', $this->media->urlExpiry(30), ['check' => $check->id], absolute: false);

        return rtrim((string) config('app.url'), '/').$path;
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
     * Check one STORED item key image. Fail + stills mode enforce → the file is flagged and HIDDEN at
     * once (David 2026-11-19: `flagged_at`, ItemMedia::isShown(); its pets get one PetUpdated) and the
     * row is regenerated (a new chain whose first try counts as one of its `max_still_retries`; the
     * clip follows the new key image) — the item reads »Na poti …« until then; shadow → only recorded.
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
            && $this->judge($check, fn (): array => $this->vision->judgeStill(...$this->itemRubric($row), imageUrl: $this->items->falFetchUrl($row), petId: $row->pet_id, itemMediaId: $row->id), $this->itemRubric($row)['rubric']) === 'waiting') {
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
     * David 2026-11-19 (media:item-recheck): STORED item key images and clips (pet / look rows) whose
     * current generation was never decided by the CURRENT item rubric (`item` / `item_clip`) nor
     * checked by an earlier recheck (an AI error is not retried by every run — QA #1414 m2) — every
     * file made before the realism check. Approved and already flagged files are left alone. Key
     * images first (a failing key image regenerates its clip too), then clips. The SQL part only —
     * recheckCandidates() keeps what an owner's pet shows now.
     *
     * @return Builder<ItemMedia>
     */
    public function itemsToRecheck(): Builder
    {
        return ItemMedia::query()->whereIn('kind', [ItemMedia::KIND_IMAGE, ...ItemMedia::CLIP_KINDS])
            ->whereIn('scope', [ItemMedia::SCOPE_LOOK, ItemMedia::SCOPE_PET])
            ->where('status', ItemMedia::STATUS_READY)->whereNotNull('storage_path')
            ->where(fn ($q) => $q->whereNull('quality')->orWhereNotIn('quality', [PetMedia::QUALITY_APPROVED, PetMedia::QUALITY_FLAGGED]))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('item_media_quality_checks')
                ->whereColumn('item_media_quality_checks.item_media_id', 'item_media.id')
                ->whereColumn('item_media_quality_checks.generation', 'item_media.generation')
                ->where('item_media_quality_checks.status', PetMediaQualityCheck::STATUS_DECIDED)
                ->where(fn ($q) => $q->where(fn ($q) => $q->whereIn('item_media_quality_checks.rubric', [MediaVisionCheck::RUBRIC_ITEM, MediaVisionCheck::RUBRIC_ITEM_CLIP])
                    ->whereIn('item_media_quality_checks.ai_status', [PetMediaQualityCheck::AI_PASSED, PetMediaQualityCheck::AI_FAILED]))
                    ->orWhere('item_media_quality_checks.trigger', ItemMediaQualityCheck::TRIGGER_RECHECK)))
            ->orderByRaw("CASE WHEN kind = 'image' THEN 0 ELSE 1 END")->orderBy('id');
    }

    /**
     * QA #1414 M2: the files media:item-recheck may check — itemsToRecheck() rows that an active
     * owner's pet SHOWS now (owns the item, an action item only while in use, the current stage's
     * row, the current content of its state — ItemMediaService::shownToAnOwner()). Nobody pays for a
     * file no child sees; a clip whose start frame is not its current key image is left out too.
     *
     * @return Collection<int, ItemMedia>
     */
    public function recheckCandidates(int $limit = 0): Collection
    {
        $out = collect();
        $this->itemsToRecheck()->chunkById(200, function ($rows) use ($out, $limit): bool {
            foreach ($rows as $row) {
                if ($this->items->shownToAnOwner($row)) {
                    $out->push($row);
                    if ($limit > 0 && $out->count() >= $limit) {
                        return false;
                    }
                }
            }

            return true;
        });

        // chunkById pages by id — keep the key images first.
        return $out->sortBy(fn (ItemMedia $r) => [$r->isClip() ? 1 : 0, $r->id])->values();
    }

    /**
     * Re-check one STORED item key image / clip with the current realism rubric (trigger `recheck`,
     * one paid vision call). Fail + item mode enforce → the file is flagged (never shown again) and
     * regenerated: a key image as a new chain (its clip follows), a clip whose OBJECT is wrong
     * through a new key image (regenerateKeyImageOf), any other clip fail as a new clip. Shadow →
     * recorded only (`shadow_failed`). A clip whose key image is no longer shown (flagged / being
     * regenerated) is skipped — it follows the new key image anyway. QA #1414: a file no owner's pet
     * shows now is skipped (M2); a failing STORED clip always starts a fresh key image chain — never
     * a new clip from the same start frame (M4; only an approved key image keeps it); an AI error is
     * reported as `error` (never `passed`), nothing is recorded on the file (m2); every flag tells
     * the pets at once (M1).
     *
     * @return array{outcome: string, reason: string|null, cost_usd: float}
     */
    public function recheckItemRealism(ItemMedia $row): array
    {
        $path = (string) $row->storage_path;

        if ($row->isClip() && (($image = $this->items->keyImageOf($row)) === null || ! $image->isReady() || ! $image->isShown())) {
            return ['outcome' => 'skipped', 'reason' => 'its key image is being regenerated', 'cost_usd' => 0.0];
        }
        if (! $this->items->shownToAnOwner($row)) {
            return ['outcome' => 'skipped', 'reason' => 'no active owner shows it now', 'cost_usd' => 0.0];
        }
        if ($row->isClip() && ! $this->settings->qualityClipAi(fresh: true)) {
            return ['outcome' => 'unavailable', 'reason' => 'the clip AI check is switched off (Feature switches)', 'cost_usd' => 0.0];
        }
        if (($gate = $this->gate()) !== 'ok') {
            return ['outcome' => $gate === 'waiting' ? 'waiting' : 'unavailable', 'reason' => 'no vision call possible now', 'cost_usd' => 0.0];
        }

        $check = $this->claim(ItemMediaQualityCheck::class, 'item_media_id', $row->id, $row->generation, ItemMediaQualityCheck::TRIGGER_RECHECK);
        if ($check === null) {
            return ['outcome' => 'busy', 'reason' => null, 'cost_usd' => 0.0];
        }

        if ($check->status !== PetMediaQualityCheck::STATUS_DECIDED) {
            $decided = $row->isClip()
                // The stored clip was served already — only the realism (AI) part decides now.
                ? $this->decideItemClip($row, $check, $path, AppSettingsService::QUALITY_OFF, AppSettingsService::QUALITY_ENFORCE, $row->source_path)
                : $this->judge($check, fn (): array => $this->vision->judgeStill(...$this->itemRubric($row), imageUrl: $this->items->falFetchUrl($row), petId: $row->pet_id, itemMediaId: $row->id), MediaVisionCheck::RUBRIC_ITEM);
            if ($decided === 'waiting') {
                return ['outcome' => 'waiting', 'reason' => 'no AI budget now', 'cost_usd' => 0.0];
            }
        }
        $check->refresh();
        $cost = (float) $check->ai_cost_usd;

        if (! in_array($check->ai_status, [PetMediaQualityCheck::AI_PASSED, PetMediaQualityCheck::AI_FAILED], true)) {
            return ['outcome' => 'error', 'reason' => $check->reason, 'cost_usd' => $cost];
        }

        if ($check->verdict === PetMediaQualityCheck::VERDICT_PASS) {
            $this->items->recordQuality($row, $path, self::passQuality($check), $check->reason);

            return ['outcome' => 'passed', 'reason' => $check->reason, 'cost_usd' => $cost];
        }

        if ($this->stillsMode() !== AppSettingsService::QUALITY_ENFORCE) {
            $this->items->recordQuality($row, $path, PetMedia::QUALITY_SHADOW_FAILED, $check->reason);

            return ['outcome' => 'failed', 'reason' => $check->reason, 'cost_usd' => $cost];
        }

        $fresh = $row->fresh();
        if ($fresh === null || $fresh->status !== ItemMedia::STATUS_READY || $fresh->storage_path !== $path) {
            return ['outcome' => 'failed', 'reason' => $check->reason, 'cost_usd' => $cost];
        }

        if ($fresh->isClip() && $this->items->regenerateKeyImageOf($fresh, null, (string) $check->reason, freshChain: true)) {
            return ['outcome' => 'regenerating_key_image', 'reason' => $check->reason, 'cost_usd' => $cost];
        }

        $this->items->recordQuality($fresh, $path, PetMedia::QUALITY_FLAGGED, $check->reason);
        // A new chain whose first try counts as one retry (like media:still-check); the clip of a key image follows it.
        $this->items->resetChain($fresh->fresh() ?? $fresh, null, qualityRetries: min(1, self::maxRetries()));
        GenerateItemMedia::dispatch((int) $fresh->id);

        return ['outcome' => 'regenerating', 'reason' => $check->reason, 'cost_usd' => $cost];
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
    private function judge(PetMediaQualityCheck|ItemMediaQualityCheck $check, callable $call, ?string $rubric = null): string
    {
        if ($rubric !== null && $check instanceof ItemMediaQualityCheck) {
            $check->update(['rubric' => $rubric]);
        }

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

        // QA #736 m2: no usable verdict (malformed answer, refusal, timeout, dead worker) → the still is
        // used UNCHECKED — an error the System health tile counts (quality = unchecked, 24 h).
        if (! in_array($ai['status'], [PetMediaQualityCheck::AI_PASSED, PetMediaQualityCheck::AI_FAILED], true)) {
            Log::error('StillQualityService: the AI still check gave no verdict — used unchecked', ['check' => $check::class, 'id' => $check->id, 'ai' => $ai['status'], 'reason' => $ai['reason']]);
        }

        return 'decided';
    }

    /** `quality` of a passed check: AI passed → passed; anything not AI-judged → unchecked / passed_technical. */
    private static function passQuality(PetMediaQualityCheck|ItemMediaQualityCheck $check): string
    {
        return match (true) {
            $check->ai_status === PetMediaQualityCheck::AI_PASSED => PetMedia::QUALITY_PASSED,
            // David 2026-11-19: an item clip whose AI realism check was wanted but gave no verdict is
            // »used unchecked« (System health counts it), not »passed the technical check«.
            $check instanceof ItemMediaQualityCheck && $check->rubric === MediaVisionCheck::RUBRIC_ITEM_CLIP => PetMedia::QUALITY_UNCHECKED,
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
