<?php

namespace App\Services\Media;

use App\Enums\AiCallFailure;
use App\Enums\AiSpendPurpose;
use App\Enums\LifeStage;
use App\Enums\PetPhaseClip;
use App\Enums\PetPose;
use App\Enums\PetStateEnum;
use App\Enums\PetTransition;
use App\Enums\Species;
use App\Events\PetUpdated;
use App\Jobs\CheckPetMediaQuality;
use App\Jobs\GeneratePetKeyframe;
use App\Jobs\GeneratePetReferenceImage;
use App\Jobs\GenerateSchoolClipDerivative;
use App\Jobs\RegeneratePetStageMedia;
use App\Jobs\StorePetMedia;
use App\Jobs\SubmitPetStateVideo;
use App\Models\AiSpendLedger;
use App\Models\Pet;
use App\Models\PetLook;
use App\Models\PetMedia;
use App\Models\PetMediaHistory;
use App\Services\AppSettingsService;
use App\Services\FalAiService;
use App\Services\Results\LookRegeneration;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\File;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * The AI media pipeline of a pet (M4-03 / M4-05).
 *
 *   pairing → GeneratePetReferenceImage (sync fal call, image slot)
 *           → StorePetMedia (download to the private pet-media disk)
 *           → queueStateVideos(): one SubmitPetStateVideo per entitled state
 *             (MediaEntitlementService) — fal queue + signed webhook — only once
 *             the pet is born (first contract: PetActivityService::signContract)
 *   webhook → recordVideoResult() → StorePetMedia → ready → PetUpdated
 *
 * Slots: one pet_media row per (pet, kind, state). Every step is idempotent
 * (atomic status claims, request_id matching, unique slot index); fal calls
 * only happen in queued jobs through FalGateway (budget caps, ledger).
 * Apps only ever see our signed URLs ({@see payloadFor()}), never fal URLs.
 *
 * Shared looks (M4-10, David 2026-10-09): a free pool pet (`pets.pet_look_id`)
 * never generates media of its own. Its slots point at the LOOK rows
 * (`pet_media` with `pet_look_id`, one per kind / state / life stage) and
 * copy their file path once the look row is stored ("link"). A look row is
 * generated through the same claims, budget, ledger, webhook and download as
 * a pet slot — once: a pet whose look row is already stored links it with no
 * fal call; a pet whose look row is in flight waits (its slot `running` with
 * `look_media_id`) and is linked by the fan-out when the file is stored (or
 * failed with the look row's reason). Look files live under `looks/{id}/`,
 * are never replaced or deleted by the pipeline, and survive every pet /
 * family deletion. See the "Shared looks" section.
 */
class PetMediaService
{
    /** A `running` slot without a fal answer for this long belongs to a dead worker. */
    public const STALE_CLAIM_MINUTES = 10;

    /** A submitted video (since 2026-10-28 also a queued key / pose image) without a webhook for this long is given up (sweep). */
    public const WEBHOOK_TIMEOUT_MINUTES = 120;

    /** Fix 2026-10-28: a queued key / pose image without a webhook for this long is polled at fal (free) by media:sweep. */
    public const IMAGE_POLL_AFTER_MINUTES = 10;

    public function __construct(
        private readonly MediaEntitlementService $entitlements,
        private readonly FalAiService $fal,
        private readonly MediaProfiles $profiles,
        private readonly MediaDownloader $downloader,
        private readonly PetAppearancePrompt $prompts,
        private readonly Mp4FastStart $fastStart,
        private readonly PetPreparationService $preparation,
        private readonly AppSettingsService $settings,
        private readonly FalGateway $gateway,
    ) {}

    public function disk(): Filesystem
    {
        return Storage::disk((string) config('media.storage.disk', 'pet_media'));
    }

    // ──────────────────────────────────────────────────────────────
    //  Slots
    // ──────────────────────────────────────────────────────────────

    public function imageSlot(Pet $pet): PetMedia
    {
        return $this->slot($pet, PetMedia::KIND_IMAGE, null);
    }

    /**
     * The slot row, created as `pending` if missing (race-safe: insert-or-ignore on the unique slot index).
     */
    public function slot(Pet $pet, string $kind, PetStateEnum|PetTransition|PetPose|PetPhaseClip|null $state): PetMedia
    {
        $query = PetMedia::query()->where('pet_id', $pet->id)->where('kind', $kind)
            ->when($state === null, fn ($q) => $q->whereNull('state'), fn ($q) => $q->where('state', $state->value));

        $existing = (clone $query)->first();

        if ($existing !== null) {
            return $existing;
        }

        PetMedia::query()->insertOrIgnore([
            'pet_id' => $pet->id,
            'kind' => $kind,
            'state' => $state?->value,
            'status' => PetMedia::STATUS_PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $query->firstOrFail();
    }

    // ──────────────────────────────────────────────────────────────
    //  Reference image (GeneratePetReferenceImage)
    // ──────────────────────────────────────────────────────────────

    /**
     * Generate (or, for pets from before M4-05, just download) the reference
     * image. Returns false when the queue should retry (transient failure).
     */
    public function generateReferenceImage(Pet $pet): bool
    {
        // M5-R06-01: no appearance data for the breed → no media at all (never a
        // dog prompt); the pet stays playable without media. M5-R06-07: a cat
        // without DNA v2 (created before cats had appearance data) likewise —
        // the legacy v1 prompt path is for dogs only; never rewrite its DNA.
        $dnaVersion = is_array($pet->pet_dna) ? (int) ($pet->pet_dna['version'] ?? 1) : 0;
        if (! PetDnaService::hasAppearance($pet->breed_type->value) || ($pet->isCat() && $dnaVersion < PetDnaService::VERSION)) {
            if ($pet->media_status !== 'ready') {
                $pet->updateQuietly(['media_status' => 'disabled']);
            }

            return true;
        }

        if (! $this->fal->isEnabled()) {
            if ($pet->media_status !== 'ready') {
                $pet->updateQuietly(['media_status' => 'disabled']);
            }

            return true;
        }

        $slot = $this->imageSlot($pet);

        if ($slot->status === PetMedia::STATUS_READY) {
            $pet->updateQuietly(['media_status' => 'ready', 'media_error' => null]);
            $this->queueStateVideos($pet);

            return true;
        }

        // M4-10: a pool pet shows its look's image of its stage (generated once per look).
        if ($pet->usesLookPool()) {
            return $this->referenceImageFromLook($pet, $slot);
        }

        // Pets from before M4-05 already have a fal URL: download it, no new cost.
        $legacyUrl = $pet->pet_dna['reference_image_url'] ?? null;

        if (is_string($legacyUrl) && $this->fal->isAllowedMediaUrl($legacyUrl) && $slot->generation === 1) {
            if ($this->claim($slot, [PetMedia::STATUS_PENDING, PetMedia::STATUS_FAILED], ['source_url' => $legacyUrl, 'profile' => $slot->profile ?? 'legacy'])) {
                $pet->updateQuietly(['media_status' => 'pending', 'media_error' => null]);
                StorePetMedia::dispatch($slot->id);
            }

            return true;
        }

        // Life-stage growth (M5-R01): the stored file was archived at the stage
        // change → its successor is an EDIT of it (same dog, older), unless
        // the edit profile is off (then text-to-image, same seed + DNA + stage).
        $growFrom = $slot->storage_path !== null
            && $pet->life_stage !== null
            && PetMediaHistory::where('pet_id', $pet->id)->where('storage_path', $slot->storage_path)->exists();

        if (! $this->claim($slot, [PetMedia::STATUS_PENDING, PetMedia::STATUS_FAILED], ['source_url' => null, 'life_stage' => $pet->life_stage?->value])) {
            return true; // another worker has it
        }

        $dna = is_array($pet->pet_dna) ? $pet->pet_dna : [];
        // DNA v2: breed + traits + stage + origin cues (never personal data);
        // DNA v1 pets: their stored prompt anchor + the same cues. A
        // legacy-profile pet (pre-M5, grandfathered) keeps its stored prompt.
        $prompt = match (true) {
            $pet->isLegacyProfile() => (string) ($dna['prompt'] ?? $dna['prompt_anchor'] ?? ''),
            (int) ($dna['version'] ?? 1) >= 2 => $this->prompts->imagePromptForPet($pet),
            default => $this->prompts->legacyPromptForPet($pet),
        };

        try {
            if ($growFrom && $this->profiles->stageEdit() !== null) {
                $result = $this->fal->editReferenceImage(
                    $this->prompts->stageEditPrompt($pet, $pet->life_stage),
                    $this->falFetchUrl($slot),
                    (int) ($dna['seed'] ?? 0),
                    $pet->id,
                    $slot->id,
                );
            } else {
                $result = $this->fal->generateReferenceImage(
                    $prompt,
                    (int) ($dna['seed'] ?? 0),
                    $pet->id,
                    isset($dna['negative_prompt']) ? (string) $dna['negative_prompt'] : null,
                    $slot->id,
                );
            }
        } catch (AiCallException $e) {
            // Budget cap / fal balance / disabled profile: a retry cannot help now. The pet
            // keeps working without media (M4-07, fail closed); the daily retry picks it up.
            $this->fail($slot, $e->reason, $e->getMessage());
            $pet->updateQuietly(['media_status' => 'failed', 'media_error' => $e->reason->value]);
            PetUpdated::afterCommit($pet->fresh(), 'reference_image_failed');
            Log::warning('PetMediaService: reference image not generated', ['pet_id' => $pet->id, 'reason' => $e->reason->value]);

            return true;
        }

        $this->syncCost($slot);

        if ($result === null) {
            // Retryable: give the slot back so the next attempt can claim it.
            $slot->update(['status' => PetMedia::STATUS_PENDING]);

            return false;
        }

        $dna['reference_image_url'] = $result['url']; // fal URL, server side only (debug / Filament)
        $pet->updateQuietly(['pet_dna' => $dna]);
        $slot->update(['source_url' => $result['url'], 'profile' => $result['profile']]);

        StorePetMedia::dispatch($slot->id);

        return true;
    }

    // ──────────────────────────────────────────────────────────────
    //  State videos
    // ──────────────────────────────────────────────────────────────

    /**
     * Create the entitled video slots and queue the ones that need a video
     * from the CURRENT reference image: new slots, slots never submitted, and
     * finished slots made from an older image generation. With $includeFailed
     * (backfill / admin) failed slots of the current image are retried too.
     *
     * Only once the reference image is stored AND the pet is born (signContract()
     * calls this after the birth commits). Returns how many were queued.
     *
     * M5-T06 (APP_REFINEMENT_SPEC §13): queued in preparation order — what the
     * child sees in the first minutes first (idle, sleeping, hungry), then the
     * games (playing), then the rest (PetPreparationService::order). Only the
     * order changes: the same entitled states, the same claims.
     *
     * Home first (David 2026-10-28): 1. every home item (state key images + loops,
     * then transitions) → 2. the pose images of the school / game clips (dispatched
     * at once, behind the home key images) → 3. the game clips, then the school clips,
     * submitted only once no home key image is on its way. The games and the school
     * stay locked until their own clips are shown (GameMediaReadiness).
     */
    public function queueStateVideos(Pet $pet, bool $includeFailed = false): int
    {
        $image = $this->imageSlot($pet);

        // Videos only for a BORN pet (first contract signed — orchestrator decision 2026-10-05,
        // waiting for David): an unborn pet whose child never signs costs only the image.
        if (! $this->fal->isEnabled() || $image->status !== PetMedia::STATUS_READY || ! $pet->is_active || $pet->isUnborn()) {
            return 0;
        }

        // M5-T08 (APP_REFINEMENT_SPEC §7): loops from / to the state's key image, then transitions.
        $loops = $this->entitlements->usesStateMedia($pet);
        $essentials = array_map('strval', (array) config('media.preparation_essentials', []));
        $essentialWaiting = false;
        // Home first (David 2026-10-28): a key image of ANY home state still on its way means
        // its loop is not submitted yet — no school / game clip is submitted before it.
        $homeWaiting = false;
        $queued = 0;

        foreach (PetPreparationService::order($this->entitlements->videoStatesFor($pet)) as $state) {
            $slot = $this->slot($pet, PetMedia::KIND_VIDEO, $state);

            if ($loops && $state !== PetStateEnum::Idle && $this->poseImage($pet, $state, $image) === null) {
                // The loop starts in the state's key image (and ends close to it): that comes first
                // (in the same preparation order); the video follows once it is stored.
                [$key, $dispatched] = $this->queuePoseStill($pet, PetMedia::KIND_KEYFRAME, $state, $image, $includeFailed);
                $queued += $dispatched ? 1 : 0;
                $essentialWaiting = $essentialWaiting || (in_array($state->value, $essentials, true) && $key->isInFlight());
                $homeWaiting = $homeWaiting || $key->isInFlight();

                continue;
            }

            // A clip made the pre-M5-T08 way (from the reference image) is remade as a loop
            // (from its key image, start frame only since 2026-10-23).
            $recipeStale = $loops && $slot->status === PetMedia::STATUS_READY && $slot->recipe === null;

            if ($this->queueClip($slot, $image, $includeFailed, $recipeStale)) {
                $queued++;
            }
        }

        // Transitions after the first-minutes clips (§13 order): queued once every
        // essential state video is on its way, and only from stored key images.
        // Frugal clips (David 2026-10-30): a frugal target left to its source is not generated
        // (come / place / potty attention = sit's). sleeping_to_idle is always generated (fal) —
        // the reversal was dropped; a legacy marked slot shows the reversal until its clip is stored.
        $this->markFrugalTargets($pet);
        foreach ($essentialWaiting ? [] : $this->entitlements->transitionsToGenerateFor($pet, null, fresh: true) as $transition) {
            if ($this->poseImage($pet, $transition->fromState(), $image) === null || $this->poseImage($pet, $transition->toState(), $image) === null) {
                continue; // its key images are queued above; the next call (key image stored) queues it
            }

            if ($this->queueClip($this->slot($pet, PetMedia::KIND_TRANSITION, $transition), $image, $includeFailed)) {
                $queued++;
            }
        }

        // M5-T09 / T10 (§13 order): game, then school phase clips (QA m1). Home first (David
        // 2026-10-28): every home item above is dispatched before anything here. The POSE images
        // only need the reference image, so they are dispatched at once (after the home key
        // images — the queue keeps that order) instead of waiting for the first-minutes set;
        // a phase CLIP is submitted only once no home key image is on its way any more (every
        // home loop is submitted) and both of its frames are stored. Same items, same claims —
        // only the order / overlap changes, never what a pet gets.
        $clipsAllowed = ! $homeWaiting;
        foreach ($this->entitlements->phaseClipsToGenerateFor($pet, null, fresh: true) as $clip) {
            $framesReady = true;

            foreach ([$clip->startPose(), $clip->endPose()] as $pose) {
                if ($pose === null || $this->frameImage($pet, $pose, $image) !== null) {
                    continue;
                }
                $framesReady = false;
                [, $dispatched] = $this->queuePoseStill($pet, PetMedia::KIND_POSE, $pose, $image, $includeFailed);
                $queued += $dispatched ? 1 : 0;
            }

            if ($clipsAllowed && $framesReady && $this->queueClip($this->slot($pet, PetMedia::KIND_PHASE, $clip), $image, $includeFailed)) {
                $queued++;
            }
        }

        return $queued;
    }

    /**
     * Frugal clips (David 2026-10-30): with the switch on, every frugal target of the pet's set
     * without a clip of its own gets its slot marked `frugal_source` (= served from that source,
     * also after the switch is turned off, until it has its own clip). Nothing else changes.
     */
    private function markFrugalTargets(Pet $pet): void
    {
        if (! $this->entitlements->frugalFor($pet->speciesValue(), fresh: true)) {
            return;
        }

        $slots = $pet->media()->get();
        // Only the attention phase clips (David 2026-10-30: the reversed sleeping_to_idle was dropped).
        $targets = array_map(fn (PetPhaseClip $c) => [PetMedia::KIND_PHASE, $c], $this->entitlements->phaseClipsFor($pet));

        foreach ($targets as [$kind, $target]) {
            $source = MediaEntitlementService::frugalSourceOf($kind, $target->value);
            if ($source === null || ! $this->entitlements->leftToSource($pet, $kind, $target->value, $slots, fresh: true)) {
                continue;
            }

            // The pet's slot (also one with a previous stage's file — QA #451 B1): marked; a slot
            // that waits for a look row which will not be made goes back to pending (no stuck
            // `running`, the sweep never reclaims it).
            $slot = $this->slot($pet, $kind, $target);
            $this->markLeftToSource($slot, $source);

            // QA #451 m3: the look row of the pet's stage carries the marker too.
            if ($pet->usesLookPool() && $pet->look !== null && $pet->life_stage !== null) {
                $row = $this->lookSlot($pet->look, $kind, $target, $pet->life_stage);
                if (! $row->isServable() && $row->status !== PetMedia::STATUS_RUNNING) {
                    PetMedia::query()->whereKey($row->id)->whereNull('frugal_source')->update(['frugal_source' => $source]);
                }
            }
        }
    }

    /**
     * Frugal clips (QA #451 m2): the ping-pong (C) or a LEGACY reversal (B) of `$source` failed
     * for good — never a hard cut: C remakes the short loop the normal way (a 5 s loop —
     * MediaEntitlementService::shortLoop is false for a file whose ping-pong failed); B re-runs
     * the pipeline of every pet that shows the source, which generates sleeping_to_idle through
     * fal (nothing is reversed since 2026-10-30).
     */
    public function fallBackFromDerivative(PetMedia $source): void
    {
        if ($source->isVideo()) {
            if ($this->resetForNewGeneration($source, bump: true)) {
                SubmitPetStateVideo::dispatch((int) $source->id);
            }

            return;
        }

        if (! $source->isTransition()) {
            return;
        }

        $petIds = $source->pet_look_id !== null
            ? PetMedia::query()->where('look_media_id', $source->id)->whereNotNull('pet_id')->distinct()->pluck('pet_id')->all()
            : array_filter([$source->pet_id]);

        foreach (Pet::query()->whereIn('id', $petIds)->where('is_active', true)->get() as $pet) {
            $this->queueStateVideos($pet, includeFailed: true);
        }
    }

    /**
     * Frugal clips: mark one pet slot as served from `$source` (QA #451 B1: also when it shows a
     * previous stage's file) and release it when it waits for a look row (`running` without a
     * request) — it falls back to the source instead of staying stuck.
     */
    private function markLeftToSource(PetMedia $slot, string $source): void
    {
        PetMedia::query()->whereKey($slot->id)->whereNull('frugal_source')->update(['frugal_source' => $source]);
        // Only a slot waiting for a look row (never a unique pet's live claim).
        PetMedia::query()->whereKey($slot->id)->where('status', PetMedia::STATUS_RUNNING)->whereNull('request_id')
            ->whereNotNull('look_media_id')
            ->update(['status' => PetMedia::STATUS_PENDING, 'updated_at' => now()]);
    }

    /**
     * Queue one clip slot (state video / transition) when it needs a clip from
     * the current reference image generation. Returns whether it was queued.
     */
    private function queueClip(PetMedia $slot, PetMedia $image, bool $includeFailed, bool $recipeStale = false): bool
    {
        $neverSubmitted = $slot->status === PetMedia::STATUS_PENDING && $slot->request_id === null;
        $stale = ! $slot->isInFlight() && ((int) $slot->source_generation < $image->generation || $recipeStale);
        // QA #363 M4: »Needs review« (quality_failed) is never retried / remade automatically.
        $reviewing = $slot->status === PetMedia::STATUS_FAILED && $slot->error_reason === AiCallFailure::QualityFailed->value;
        $retry = $includeFailed && $slot->status === PetMedia::STATUS_FAILED && ! $reviewing;

        // A new reference image (life-stage growth) starts a new chain — that one still remakes it.
        $newImage = ! $slot->isInFlight() && (int) $slot->source_generation < $image->generation;

        if (! $neverSubmitted && ! $retry && (! $stale || ($reviewing && ! $newImage))) {
            return false;
        }

        if (! $neverSubmitted && ! $this->resetForNewGeneration($slot, bump: $slot->isServable() || $slot->request_id !== null)) {
            return false; // another worker moved it meanwhile (PR #24 review m7)
        }

        SubmitPetStateVideo::dispatch($slot->id);

        return true;
    }

    /**
     * M5-T08: queue the key image of a state (M5-T09 / T10: or a pose image of a
     * phase clip) when it is missing or older than the current reference image.
     * Pending slots are (re)dispatched — the job is unique per slot and the claim
     * makes a duplicate harmless.
     *
     * @return array{0: PetMedia, 1: bool} the slot, whether a job was dispatched
     */
    private function queuePoseStill(Pet $pet, string $kind, PetStateEnum|PetPose $state, PetMedia $image, bool $includeFailed): array
    {
        $key = $this->slot($pet, $kind, $state);

        if ($key->status === PetMedia::STATUS_RUNNING) {
            // QA: a slot waiting for a shared look row (e.g. while the look image was
            // regenerated) is re-run now — the job is unique per slot and the claims make
            // it a no-op for a live claim, so this never pays twice.
            if ($key->look_media_id !== null) {
                GeneratePetKeyframe::dispatch($key->id);
            }

            return [$key, false];
        }

        $stale = ! $key->isInFlight() && (int) $key->source_generation < $image->generation;
        $retry = $includeFailed && $key->status === PetMedia::STATUS_FAILED;

        if ($key->status !== PetMedia::STATUS_PENDING && ! $stale && ! $retry) {
            return [$key, false];
        }

        if ($key->status !== PetMedia::STATUS_PENDING && ! $this->resetForNewGeneration($key, bump: $key->isServable())) {
            return [$key, false];
        }

        GeneratePetKeyframe::dispatch($key->id);

        return [$key, true];
    }

    /**
     * M5-T08: the stored picture of a state's pose made from the CURRENT
     * reference image — the image itself for idle, else the state's key image
     * (ready and not older than the image). Null while it is missing.
     */
    public function poseImage(Pet $pet, PetStateEnum $state, ?PetMedia $image = null): ?PetMedia
    {
        $image ??= $this->imageSlot($pet);

        if ($image->status !== PetMedia::STATUS_READY || ! $image->isServable()) {
            return null;
        }

        if ($state === PetStateEnum::Idle) {
            return $image;
        }

        $key = PetMedia::query()->where('pet_id', $pet->id)->keyframes()->where('state', $state->value)->first();

        return $key !== null && $key->status === PetMedia::STATUS_READY && $key->isServable()
            && (int) $key->source_generation >= $image->generation ? $key : null;
    }

    /**
     * M5-T09 / T10: the stored picture of a clip frame made from the CURRENT reference
     * image — null = the reference image itself (idle pose), a PetStateEnum = that
     * state's pose (poseImage()), a PetPose = its pose image (ready, not older than the
     * image). Null while it is missing.
     */
    public function frameImage(Pet $pet, PetStateEnum|PetPose|null $frame, ?PetMedia $image = null): ?PetMedia
    {
        $image ??= $this->imageSlot($pet);

        if (! $frame instanceof PetPose) {
            return $this->poseImage($pet, $frame ?? PetStateEnum::Idle, $image);
        }

        if ($image->status !== PetMedia::STATUS_READY || ! $image->isServable()) {
            return null;
        }

        $row = PetMedia::query()->where('pet_id', $pet->id)->where('kind', PetMedia::KIND_POSE)->where('state', $frame->value)->first();

        return $row !== null && $row->status === PetMedia::STATUS_READY && $row->isServable()
            && (int) $row->source_generation >= $image->generation ? $row : null;
    }

    /**
     * M5-T09 / T10: is a pose image / phase clip (or key image / transition) part of the pet's set now?
     * Frugal clips (David 2026-10-30): a frugal target left to its source is not (switch read fresh —
     * this guards the fal submits).
     */
    public function wantsPoseMedia(Pet $pet, PetMedia $slot): bool
    {
        return match ($slot->kind) {
            PetMedia::KIND_POSE => in_array($slot->pose(), $this->entitlements->posesFor($pet), true),
            PetMedia::KIND_PHASE => in_array($slot->phaseClip(), $this->entitlements->phaseClipsFor($pet), true)
                && ! $this->entitlements->slotLeftToSource($slot, fresh: true),
            PetMedia::KIND_TRANSITION => $this->entitlements->usesStateMedia($pet)
                && ! $this->entitlements->slotLeftToSource($slot, fresh: true),
            default => $this->entitlements->usesStateMedia($pet),
        };
    }

    // ──────────────────────────────────────────────────────────────
    //  Key images (M5-T08, GeneratePetKeyframe)
    // ──────────────────────────────────────────────────────────────

    /**
     * Generate one state key image — an EDIT of the pet's current reference
     * image (Nano Banana Pro Edit) in the state's pose, so the animal, room,
     * light and framing stay identical. A pool / shared-look pet links its
     * look's key image of its stage (0 $ when stored). Returns false when the
     * queue should retry.
     */
    public function generateKeyframe(int $slotId): bool
    {
        $slot = PetMedia::find($slotId);

        // M5-T09 / T10: pose images of the phase clips take the same path as key images.
        if ($slot === null || ! $slot->isPoseStill()) {
            return true;
        }

        // M5-T08b: state media switched off in /admin — nothing queued before is generated
        // (fresh read, no cache). The slot stays pending; switching it on again resumes it
        // (ResumeStateMedia job on the switch, media:sweep for stale pending slots).
        if (! $this->entitlements->stateMediaEnabled(fresh: true)) {
            return true;
        }

        // QA M2: a pose image while »School & game clips« is off — no fal call, the slot waits.
        if ($slot->isPose() && ! $this->entitlements->phaseClipsEnabled(fresh: true)) {
            return true;
        }

        if ($slot->isLookMedia()) {
            return $slot->look !== null ? $this->generateLookKeyframe($slot, $slot->look) : true;
        }

        $pet = $slot->pet;
        $state = $slot->petState();
        $pose = $slot->pose();

        if ($pet === null || ! $pet->is_active || ($state === null && $pose === null)
            || ($state !== null && ! $state->appliesTo($pet->speciesValue()))
            || ($pose !== null && $pose->species() !== $pet->speciesValue())) {
            if ($slot->status !== PetMedia::STATUS_READY) {
                $this->fail($slot, null, 'Pet inactive or pose not available for the species — not generated.');
            }

            return true;
        }

        // M5-T08b: a key image only for a pet that uses state media now (left pending otherwise).
        // M5-T09 / T10: a pose image only while one of the pet's phase clips needs it.
        if (! $this->wantsPoseMedia($pet, $slot)) {
            return true;
        }

        if ($pet->usesLookPool()) {
            return $this->keyframeFromLook($pet, $slot);
        }

        $image = $this->imageSlot($pet);

        if ($image->status !== PetMedia::STATUS_READY || ! $image->isServable()) {
            return true; // queueStateVideos() runs again once the image is stored
        }

        if ($this->profiles->keyframe() === null) {
            $this->fail($slot, AiCallFailure::Disabled, 'No key image profile enabled.');

            return true;
        }

        // A stale `running` claim = a worker died; its submit may already have been accepted
        // (and paid) by fal before the request id reached the slot (adopted below, no 2nd submit).
        $deadClaimSince = $slot->status === PetMedia::STATUS_RUNNING ? $slot->updated_at : null;

        // A `running` slot WITH a request id is never claimed (claim()): fal is working on it and
        // the webhook / media:sweep finishes it — a duplicate job never pays twice (fix 2026-10-28).
        if (! $this->claim($slot, [PetMedia::STATUS_PENDING, PetMedia::STATUS_FAILED], [
            'source_url' => null,
            'request_id' => null,
            'status_url' => null,
            'response_url' => null,
            'source_generation' => $image->generation,
            'life_stage' => $image->life_stage,
        ])) {
            return true; // another worker has it
        }

        if ($deadClaimSince !== null && $this->adoptAcceptedRequest($slot, $deadClaimSince)) {
            return true;
        }

        [$breedKey, $traits] = $this->prompts->subjectOf($pet);
        $dna = is_array($pet->pet_dna) ? $pet->pet_dna : [];

        return $this->submitPoseStill($slot, fn () => $this->fal->submitKeyframe(
            $pose !== null
                ? $this->prompts->posePrompt($breedKey, $pose, $traits, $pet->life_stage)
                : $this->prompts->keyframePrompt($breedKey, $state, $traits, $pet->life_stage),
            $this->falFetchUrl($image),
            (int) ($dna['seed'] ?? 0),
            $pet->id,
            $slot->id,
        ), ['pet_id' => $pet->id]);
    }

    /**
     * Fix 2026-10-28 (David): submit a CLAIMED key / pose image slot (pet slot or look row)
     * to fal's queue — the result comes on the signed webhook (recordVideoResult →
     * StorePetMedia → keyframe_ready → queueStateVideos, as before), a lost webhook is
     * polled / timed out by media:sweep. Exactly one ledger entry per submission.
     *
     * A refusal that reached nothing (connect error, fal 4xx / 5xx → ledger void) puts the
     * slot back to `pending` and returns false (the job retries — nothing was paid). A
     * submit that was sent but whose answer was lost (cost kept) or a budget / balance /
     * disabled refusal fails the slot — never resubmitted automatically (no double pay).
     *
     * @param  callable(): array{request_id: string, status_url: string|null, response_url: string|null, profile: string}  $submit
     * @param  array<string, mixed>  $logContext
     */
    private function submitPoseStill(PetMedia $slot, callable $submit, array $logContext): bool
    {
        try {
            $submitted = $submit();
        } catch (AiCallException $e) {
            $this->syncCost($slot);

            if ($e->retryable() && ! $e->outcomeUnknown) {
                $slot->update(['status' => PetMedia::STATUS_PENDING]);

                return false;
            }

            $this->fail($slot, $e->reason, $e->getMessage()); // a look row fails its waiting pets too
            Log::warning('PetMediaService: key / pose image not submitted', $logContext + ['pet_media_id' => $slot->id, 'state' => $slot->state, 'reason' => $e->reason->value]);

            return true;
        }

        $slot->update([
            'request_id' => $submitted['request_id'],
            'status_url' => $submitted['status_url'],
            'response_url' => $submitted['response_url'],
            'profile' => $submitted['profile'],
        ]);
        $this->syncCost($slot);

        return true;
    }

    /**
     * Submit one video slot to fal (SubmitPetStateVideo). Returns false when
     * the queue should retry (transient failure before fal accepted it).
     */
    public function submitVideo(int $slotId): bool
    {
        $slot = PetMedia::find($slotId);

        // M5-T08b: state media switched off (or the pet does not use it) — a queued transition
        // (M5-T09 / T10: or phase clip) is not submitted (stays pending; look rows: submitLookVideo).
        // Frugal clips (QA #451 B1): a target left to its source is marked and — if it waits for a
        // look row — released (never stuck `running`, never reclaimed by the sweep).
        if ($slot !== null && $slot->isPoseClip() && $slot->pet_id !== null && $this->entitlements->slotLeftToSource($slot, fresh: true)) {
            $this->markLeftToSource($slot, (string) MediaEntitlementService::frugalSourceOf((string) $slot->kind, (string) $slot->state));

            return true;
        }

        if ($slot !== null && $slot->isPoseClip() && (! $this->entitlements->stateMediaEnabled(fresh: true)
            || ($slot->isPhase() && ! $this->entitlements->phaseClipsEnabled(fresh: true))
            || ($slot->pet !== null && ! $this->wantsPoseMedia($slot->pet, $slot)))) {
            return true;
        }

        // M4-10: a look row (sweep reclaim) / a pool pet's slot go through the look.
        // M5-T08: transitions take the same path as state videos.
        if ($slot !== null && $slot->isClip() && $slot->isLookMedia()) {
            return $this->submitLookVideo($slot);
        }

        if ($slot !== null && $slot->isClip() && $slot->pet?->usesLookPool()) {
            return $this->videoFromLook($slot->pet, $slot);
        }

        // A stale `running` claim = a worker died; its submit may already have been
        // accepted (and paid) by fal before the request id reached the slot.
        $deadClaimSince = $slot?->status === PetMedia::STATUS_RUNNING ? $slot->updated_at : null;

        if ($slot === null || ! $slot->isClip() || ! $this->claim($slot, [PetMedia::STATUS_PENDING], [], requireNoRequest: true)) {
            return true;
        }

        if ($deadClaimSince !== null && $this->adoptAcceptedRequest($slot, $deadClaimSince)) {
            return true; // no second paid submit (PR #24 review m1)
        }

        if ($slot->isPoseClip()) {
            return $this->submitTransition($slot);
        }

        $pet = $slot->pet;
        $state = $slot->petState();

        if ($pet === null || ! $pet->is_active || $state === null) {
            $this->fail($slot, null, 'Pet inactive or unknown state — not generated.');

            return true;
        }

        // M5-R06-07 (QA): a state the species never has (a cat's accident / chewing,
        // a dog's scratching — e.g. a slot from an admin regenerate) — fail it
        // cleanly instead of throwing in the prompt builder; nothing reaches fal.
        if (! $state->appliesTo($pet->speciesValue())) {
            $this->fail($slot, null, "A {$pet->speciesValue()->value} has no '{$state->value}' video — not generated.");

            return true;
        }

        $image = $this->imageSlot($pet);

        if (! $image->isServable()) {
            // queueStateVideos() runs again once the image is stored.
            $slot->update(['status' => PetMedia::STATUS_PENDING]);

            return true;
        }

        // M5-T08: a loop from the state's key image (idle: the reference image) — start frame only since 2026-10-23.
        $loops = $this->entitlements->usesStateMedia($pet);
        $pose = $loops ? $this->poseImage($pet, $state, $image) : null;

        if ($loops && $pose === null) {
            // Its key image is not stored yet: queueStateVideos() queues this again once it is.
            $slot->update(['status' => PetMedia::STATUS_PENDING]);

            return true;
        }

        try {
            if ($pose !== null) {
                [$breedKey, $traits] = $this->prompts->subjectOf($pet);
                // Frugal clips C (David 2026-10-30): low-motion loops 3 s (served as a ping-pong).
                $submitted = $this->fal->submitLoopVideoFor($breedKey, $traits, $pet->life_stage, $state, $this->falFetchUrl($pose), $pet->id, $slot->id,
                    short: $this->entitlements->shortLoop($pet->speciesValue(), $state, $slot, fresh: true));
            } else {
                $submitted = $this->fal->submitStateVideo($pet, $state, $this->falFetchUrl($image), $slot->id) + ['recipe' => null];
            }
        } catch (AiCallException $e) {
            $this->syncCost($slot);

            if ($e->retryable() && ! $e->outcomeUnknown) {
                $slot->update(['status' => PetMedia::STATUS_PENDING]);

                return false;
            }

            // Budget / balance / disabled — or sent but the answer was lost (cost kept, no
            // request_id to match a webhook): fail; the daily retry / backfill picks it up.
            $this->fail($slot, $e->reason, $e->getMessage());

            return true;
        }

        $slot->update([
            'request_id' => $submitted['request_id'],
            'profile' => $submitted['profile'],
            'duration_seconds' => $submitted['duration_seconds'],
            'source_generation' => $image->generation,
            'recipe' => $submitted['recipe'],
        ]);
        $this->syncCost($slot);

        return true;
    }

    /**
     * M5-T08: submit a claimed transition slot of a pet with its own media —
     * start = pose of the source state, end = pose of the target state (both
     * from the current reference image). Waits (pending) while a pose is missing.
     * M5-T09 / T10: phase clips the same way (start / end = their pose images, 3 s).
     */
    private function submitTransition(PetMedia $slot): bool
    {
        $pet = $slot->pet;
        $transition = $slot->transition();
        $clip = $slot->phaseClip();
        $frames = $slot->frames();

        if (! $this->entitlements->stateMediaEnabled(fresh: true)
            || ($clip !== null && ! $this->entitlements->phaseClipsEnabled(fresh: true))) {
            $slot->update(['status' => PetMedia::STATUS_PENDING]); // M5-T08b / QA M2: switched off — release the claim, no fal call

            return true;
        }

        if ($pet === null || ! $pet->is_active || $frames === null || ($clip !== null && $clip->species() !== $pet->speciesValue())) {
            $this->fail($slot, null, 'Pet inactive or unknown transition / phase clip — not generated.');

            return true;
        }

        $image = $this->imageSlot($pet);
        $start = $this->frameImage($pet, $frames[0], $image);
        $end = $this->frameImage($pet, $frames[1], $image);

        if ($start === null || $end === null) {
            $slot->update(['status' => PetMedia::STATUS_PENDING]);

            return true;
        }

        [$breedKey, $traits] = $this->prompts->subjectOf($pet);

        try {
            $submitted = $clip !== null
                ? $this->fal->submitPhaseClipFor($breedKey, $traits, $pet->life_stage, $clip, $this->falFetchUrl($start), $this->falFetchUrl($end), $pet->id, $slot->id)
                : $this->fal->submitTransitionFor($breedKey, $traits, $pet->life_stage, $transition, $this->falFetchUrl($start), $this->falFetchUrl($end), $pet->id, $slot->id);
        } catch (AiCallException $e) {
            $this->syncCost($slot);

            if ($e->retryable() && ! $e->outcomeUnknown) {
                $slot->update(['status' => PetMedia::STATUS_PENDING]);

                return false;
            }

            $this->fail($slot, $e->reason, $e->getMessage());

            return true;
        }

        $slot->update([
            'request_id' => $submitted['request_id'],
            'profile' => $submitted['profile'],
            'duration_seconds' => $submitted['duration_seconds'],
            'source_generation' => $image->generation,
            // Fix 2026-10-23: `start_only` for a same-pose phase clip (no end frame), else null.
            'recipe' => $submitted['recipe'],
        ]);
        $this->syncCost($slot);

        return true;
    }

    /**
     * A dead worker's submit that fal accepted: the ledger row (committed with a
     * request id, linked to this slot, made after that worker's claim) is the
     * proof. Adopt its request id so the webhook matches — fal is not called again.
     */
    private function adoptAcceptedRequest(PetMedia $slot, \DateTimeInterface $claimedAt): bool
    {
        // Fix 2026-10-28: queued key / pose images are adopted the same way (purpose reference_image).
        $ledger = AiSpendLedger::query()
            ->where('pet_media_id', $slot->id)
            ->where('purpose', $slot->isPoseStill() ? AiSpendPurpose::ReferenceImage->value : AiSpendPurpose::StateVideo->value)
            ->where('status', AiSpendLedger::STATUS_COMMITTED)
            ->whereNotNull('request_id')
            ->where('created_at', '>=', $claimedAt)
            ->latest('id')
            ->first();

        if ($ledger === null || PetMedia::where('request_id', $ledger->request_id)->exists()) {
            return false;
        }

        $image = match (true) {
            $slot->isLookMedia() => $this->lookImageRow($slot),
            $slot->pet !== null => $this->imageSlot($slot->pet),
            default => null,
        };
        $slot->update($slot->isPoseStill()
            // A key / pose image: no clip fields. Its queue URLs died with the worker — rebuilt from
            // the profile's endpoint so media:sweep can still poll it (QA #433).
            ? ['request_id' => $ledger->request_id, 'profile' => $ledger->profile, 'source_generation' => $image?->generation]
                + $this->adoptedQueueUrls((string) $ledger->profile, (string) $ledger->request_id)
            : [
                'request_id' => $ledger->request_id,
                'profile' => $ledger->profile,
                'source_generation' => $image?->generation,
                'duration_seconds' => (float) $ledger->units,
                // M5-T08: the dead worker submitted the clip the same way this one would have.
                'recipe' => $this->adoptedRecipe($slot),
            ]);
        $this->syncCost($slot);
        Log::warning('PetMediaService: adopted the request id of a dead worker', ['pet_media_id' => $slot->id, 'request_id' => $ledger->request_id]);

        return true;
    }

    /**
     * Queue URLs of an adopted key / pose image request (see FalGateway::queueUrlsFor()).
     *
     * @return array{status_url: string|null, response_url: string|null}
     */
    private function adoptedQueueUrls(string $profileKey, string $requestId): array
    {
        $profile = $this->profiles->keyframe();

        if ($profile === null || $profile->key !== $profileKey) {
            try {
                $profile = $this->profiles->image($profileKey);
            } catch (\Throwable) {
                return ['status_url' => null, 'response_url' => null];
            }
        }

        return FalGateway::queueUrlsFor($profile->endpoint, $requestId);
    }

    /**
     * M5-T08: the recipe a clip submit of this slot uses now (see submitVideo / submitLookVideo /
     * submitTransition). Fix 2026-10-23: loops and same-pose phase clips are `start_only`.
     */
    private function adoptedRecipe(PetMedia $slot): ?string
    {
        if ($slot->isPhase()) {
            return ($slot->phaseClip()?->sharesFrames() ?? false) ? PetMedia::RECIPE_START_ONLY : null;
        }

        if (! $slot->isVideo()) {
            return null;
        }

        if ($slot->isLookMedia()) {
            // A look row marked for a loop (recipe set) is submitted as a start-only loop.
            return $slot->recipe !== null ? PetMedia::RECIPE_START_ONLY : null;
        }

        $pet = $slot->pet;
        $state = $slot->petState();

        if ($pet === null || $state === null || ! $this->entitlements->usesStateMedia($pet) || $this->poseImage($pet, $state) === null) {
            return null;
        }

        return PetMedia::RECIPE_START_ONLY;
    }

    /**
     * Signed fal webhook for a pet_media request (FalAiWebhookController, inside
     * its transaction with the slot row locked). Idempotent. Clips and — since
     * 2026-10-28 — queued key / pose images (`url` = the allowlisted fal media URL;
     * media:sweep's poll of a lost image webhook lands here too).
     *
     * @param  array{ok: bool, url?: string|null, video_url?: string|null, error: string|null, reason?: AiCallFailure|null}  $result
     * @return 'recorded'|'failed'|'already_processed'
     */
    public function recordVideoResult(PetMedia $slot, array $result): string
    {
        // The slot was found by THIS request id, so it is still the current generation.
        // A slot the sweep failed (timed_out) or any other failed one still takes a
        // successful late result (PR #24 review m2); a late ERROR changes nothing.
        $lateSuccess = $slot->status === PetMedia::STATUS_FAILED && $slot->source_url === null && $result['ok'];

        if (($slot->status !== PetMedia::STATUS_RUNNING && ! $lateSuccess) || $slot->source_url !== null) {
            return 'already_processed';
        }

        if ($lateSuccess) {
            $slot->update(['status' => PetMedia::STATUS_RUNNING, 'error_reason' => null, 'error' => null, 'completed_at' => null]);
        }

        if (! $result['ok']) {
            $this->fail($slot, $result['reason'] ?? AiCallFailure::GenerationFailed, $result['error']);
            Log::warning('PetMediaService: video generation failed', ['pet_id' => $slot->pet_id, 'state' => $slot->state, 'error' => $result['error']]);

            return 'failed';
        }

        $slot->update(['source_url' => $result['url'] ?? $result['video_url'] ?? null]);
        StorePetMedia::dispatch($slot->id)->afterCommit();

        return 'recorded';
    }

    // ──────────────────────────────────────────────────────────────
    //  Storage (StorePetMedia)
    // ──────────────────────────────────────────────────────────────

    /**
     * Download the slot's fal result and store it on the pet-media disk.
     * Returns false when the queue should retry.
     */
    public function storeResult(int $slotId): bool
    {
        $slot = PetMedia::find($slotId);

        if ($slot === null || $slot->status !== PetMedia::STATUS_RUNNING || blank($slot->source_url)) {
            return true;
        }

        // Quality check (David 2026-10-23): already downloaded and waiting for its check
        // (a lost check job, re-run by media:sweep) — check it, never download it again.
        if (filled($slot->candidate_path)) {
            CheckPetMediaQuality::dispatch((int) $slot->id);

            return true;
        }

        $sourceUrl = (string) $slot->source_url;
        // M5-T08: a key image is a picture too (image mimes / limits), a transition a clip.
        $image = $slot->isStill();

        try {
            $file = $this->downloader->download(
                $sourceUrl,
                (int) config($image ? 'media.storage.max_image_bytes' : 'media.storage.max_video_bytes'),
                (array) config($image ? 'media.storage.image_mimes' : 'media.storage.video_mimes'),
            );
        } catch (MediaDownloadException $e) {
            if (! $e->permanent) {
                Log::warning('PetMediaService: download failed, will retry', ['pet_media_id' => $slot->id, 'error' => $e->getMessage()]);

                return false;
            }

            $this->failStore($slot, $e->getMessage());

            return true;
        }

        // 2026-10-20: fal's Kling MP4s now carry the index (moov) at the end — the app's
        // player can't open them. Store every video with moov first (Mp4FastStart).
        if (! $image && $this->fastStart->process($file['path']) === Mp4FastStart::RESULT_SKIPPED) {
            Log::warning('PetMediaService: MP4 not converted to faststart', ['pet_media_id' => $slot->id]);
        }

        // M5-T08: key images `key-<state>`, transitions `<from>_to_<to>` (never a state name).
        $name = match ($slot->kind) {
            PetMedia::KIND_IMAGE => 'reference',
            PetMedia::KIND_KEYFRAME => 'key-'.$slot->state,
            // M5-T09 / T10: pose images `pose-<pose>`, phase clips `<group>_<…>` (never a state name).
            PetMedia::KIND_POSE => 'pose-'.$slot->state,
            default => (string) $slot->state,
        };
        $path = $slot->isLookMedia()
            // M4-10: one file per look, stage and generation — shared by every pet of the look.
            ? sprintf('looks/%d/%s-%s-g%d.%s', $slot->pet_look_id, $name, $slot->life_stage, $slot->generation, MediaDownloader::extensionFor($file['mime']))
            : sprintf('%d/%s-g%d.%s', $slot->pet_id, $name, $slot->generation, MediaDownloader::extensionFor($file['mime']));

        try {
            $this->disk()->putFileAs(dirname($path), new File($file['path']), basename($path));
        } finally {
            @unlink($file['path']);
        }

        // Quality check (David 2026-10-23): a clip is a CANDIDATE until it passes the
        // technical + AI vision check (MediaQualityService); the servable file stays the old
        // one until then. Switch off (/admin → Feature switches) = served at once, as before —
        // unless video compression is on (David 2026-10-30): then the clip is still staged so
        // CheckPetMediaQuality → CompressPetMediaCandidate compresses it before it is shown.
        if ($slot->isClip() && ($this->qualityCheckEnabled() || $this->settings->videoCompression(fresh: true))) {
            return $this->stageCandidate($slot, $sourceUrl, $path, $file['bytes'], $file['mime']);
        }

        $previous = null;
        $stored = DB::transaction(function () use ($slot, $sourceUrl, $path, $file, &$previous): bool {
            $locked = PetMedia::whereKey($slot->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== PetMedia::STATUS_RUNNING || $locked->source_url !== $sourceUrl) {
                return false; // regenerated / finished meanwhile
            }

            $previous = $locked->storage_path;
            $this->markStored($locked, $path, $file['bytes'], $file['mime'], null);

            return true;
        });

        if (! $stored) {
            $this->discardDownloaded($slot, $path, $previous);

            return true;
        }

        $this->afterStored($slot, $previous);

        return true;
    }

    /** Quality check on — shadow or enforce (fresh read — a worker stops checking the moment it is switched off). */
    public function qualityCheckEnabled(): bool
    {
        return $this->settings->qualityCheck(fresh: true);
    }

    /** QA #363 M1: off | shadow | enforce (fresh read). */
    public function qualityCheckMode(): string
    {
        return $this->settings->qualityCheckMode(fresh: true);
    }

    /**
     * Quality check: remember the downloaded clip as the slot's candidate (the slot stays
     * `running`, the old `storage_path` servable) and queue its check. Idempotent.
     */
    private function stageCandidate(PetMedia $slot, string $sourceUrl, string $path, int $bytes, string $mime): bool
    {
        $staged = DB::transaction(function () use ($slot, $sourceUrl, $path, $bytes, $mime): bool {
            $locked = PetMedia::whereKey($slot->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== PetMedia::STATUS_RUNNING || $locked->source_url !== $sourceUrl || filled($locked->candidate_path)) {
                return false;
            }

            $locked->update(['candidate_path' => $path, 'candidate_bytes' => $bytes, 'candidate_mime' => $mime]);

            return true;
        });

        if (! $staged) {
            $fresh = $slot->fresh();
            $this->discardDownloaded($slot, $path, $fresh?->storage_path, $fresh?->candidate_path);

            return true;
        }

        CheckPetMediaQuality::dispatch((int) $slot->id);

        return true;
    }

    /**
     * Quality check passed (or »Approve anyway«): the candidate becomes the servable file.
     * Conditional on the row still holding this candidate in one of `$fromStatuses`
     * (running = a fresh check, failed = an approval of a »Needs review« slot).
     *
     * @param  list<string>  $fromStatuses
     */
    public function promoteCandidate(PetMedia $slot, string $candidatePath, ?string $quality, ?string $reason, array $fromStatuses = [PetMedia::STATUS_RUNNING]): bool
    {
        $previous = null;
        $promoted = DB::transaction(function () use ($slot, $candidatePath, $quality, $reason, $fromStatuses, &$previous): bool {
            $locked = PetMedia::whereKey($slot->id)->lockForUpdate()->first();

            if ($locked === null || ! in_array($locked->status, $fromStatuses, true) || $locked->candidate_path !== $candidatePath) {
                return false;
            }

            $previous = $locked->storage_path;
            $this->markStored($locked, $candidatePath, (int) $locked->candidate_bytes, (string) $locked->candidate_mime, $quality, $reason);

            return true;
        });

        if (! $promoted) {
            return false;
        }

        $this->afterStored($slot->fresh() ?? $slot, $previous);

        return true;
    }

    /**
     * Write a stored file into a LOCKED slot row (inside the caller's transaction) and
     * broadcast once after commit.
     */
    private function markStored(PetMedia $locked, string $path, int $bytes, string $mime, ?string $quality, ?string $qualityReason = null): void
    {
        $locked->update([
            'status' => PetMedia::STATUS_READY,
            'storage_path' => $path,
            // QA #361 M1: how THIS file was made — only now, never at submit (a failed remake keeps the old one).
            'stored_recipe' => $locked->recipe,
            // Quality check / David 2026-10-23: the length of THIS file (5 s school performing vs older 3 s).
            'stored_duration_seconds' => $locked->duration_seconds,
            // Frugal clips: a target that got its own clip is no longer served from its source.
            'frugal_source' => null,
            'bytes' => $bytes,
            'mime' => $mime,
            'candidate_path' => null,
            'candidate_bytes' => null,
            'candidate_mime' => null,
            'quality' => $quality,
            'quality_reason' => $quality === null ? null : $qualityReason,
            'error_reason' => null,
            'error' => null,
            'completed_at' => now(),
        ]);

        $pet = Pet::find($locked->pet_id);

        if ($pet !== null) {
            if ($locked->isImage()) {
                $pet->updateQuietly(['media_status' => 'ready', 'media_error' => null]);
            }

            PetUpdated::afterCommit($pet, self::readyEvent($locked));
        }
    }

    /** A downloaded file nobody takes (regenerated / finished meanwhile). */
    private function discardDownloaded(PetMedia $slot, string $path, ?string ...$keep): void
    {
        if (! in_array($path, $keep, true)) {
            $this->disk()->delete($path);
        }

        // The pet was deleted while we downloaded (M2-08): its directory may
        // already be gone or hold only this file — leave nothing behind.
        if ($slot->pet_id !== null && ! Pet::whereKey($slot->pet_id)->exists()) {
            $this->deleteFilesOf($slot->pet_id);
        }
    }

    /** What follows a newly stored file (old file, look fan-out, dependants, preparation). */
    private function afterStored(PetMedia $slot, ?string $previous): void
    {
        $path = (string) PetMedia::whereKey($slot->id)->value('storage_path');
        $image = $slot->isStill();

        // School smooth playback (David 2026-10-28): a new / replaced school phase clip gets its
        // ffmpeg-derived clip (loop / release) — 0 $, never through fal. Owners only (look row
        // or a unique pet row); the job re-checks everything.
        // Frugal clips (David 2026-10-30): also idle_to_sleeping (→ sleeping_to_idle reversed) and
        // the low-motion loops (→ their ping-pong); the job decides whether one is due (make()).
        // QA #451 m5: no no-op job — a transition only when its reversal is wanted, a video only
        // when it is a short loop (the stored row decides).
        if ($slot->look_media_id === null && SchoolClipDerivativeService::mayDerive($slot)) {
            $stored = PetMedia::find($slot->id);
            $due = $stored !== null && match (true) {
                $stored->isPhase() => true,
                $stored->isTransition() => app(SchoolClipDerivativeService::class)->reversalWanted($stored),
                default => SchoolClipDerivativeService::isShortLoop($stored),
            };
            if ($due) {
                GenerateSchoolClipDerivative::dispatch((int) $slot->id);
            }
        }

        // M4-10: a look file is never replaced (pets of the look point at it);
        // the waiting pets get the new file.
        if ($slot->isLookMedia()) {
            $this->fanOutReady((int) $slot->id);
            // Legacy reversal (David 2026-10-30): the look's own sleeping_to_idle replaced it —
            // the reversed derivative is deleted once no pet shows it any more (0 $, no fal).
            $this->releaseLegacyReversal($slot);

            return;
        }

        // An image archived at a life-stage change (M5-R01) stays on the disk
        // (pet_media_history, growth album); anything else is replaced.
        if ($previous !== null && $previous !== $path && ! PetMediaHistory::where('storage_path', $previous)->exists()) {
            $this->disk()->delete($previous);
        }

        // The videos are made from the image; M5-T08 loops / transitions from the key images.
        if ($image && ($pet = Pet::find($slot->pet_id)) !== null) {
            $this->queueStateVideos($pet);
        }

        // Legacy reversal: the pet's own sleeping_to_idle replaced it (derivative deleted).
        $this->releaseLegacyReversal($slot);

        // M5-T06: the last item of a running preparation → »… je pripravljen!« (once).
        if ($slot->pet_id !== null) {
            $this->preparation->settle((int) $slot->pet_id);
        }
    }

    /**
     * Legacy reversal (David 2026-10-30): a stored / linked `sleeping_to_idle` may leave the
     * reversed derivative of its `idle_to_sleeping` unreferenced — FrugalUnreverseService
     * deletes it once no active pet is still served from it. Cheap no-op for every other row.
     * Never inside a transaction (the media disk may be S3).
     */
    private function releaseLegacyReversal(PetMedia $slot): void
    {
        if (! $slot->isTransition() || ! isset(MediaEntitlementService::LEGACY_REVERSED_TRANSITIONS[(string) $slot->state])) {
            return;
        }

        try {
            app(FrugalUnreverseService::class)->releaseFor($slot);
        } catch (\Throwable $e) {
            report($e); // a leftover derivative is only disk space — the next run cleans it
        }
    }

    private function failStore(PetMedia $slot, string $message): void
    {
        Log::error('PetMediaService: result not stored', ['pet_media_id' => $slot->id, 'error' => $message]);
        $this->fail($slot, AiCallFailure::InvalidResponse, $message);

        if ($slot->isImage() && ($pet = Pet::find($slot->pet_id)) !== null) {
            // A dead legacy / fal URL must not be reused: the next attempt generates a new image.
            $dna = is_array($pet->pet_dna) ? $pet->pet_dna : [];
            $dna['reference_image_url'] = null;
            $pet->updateQuietly(['pet_dna' => $dna, 'media_status' => 'failed', 'media_error' => AiCallFailure::InvalidResponse->value]);
            PetUpdated::afterCommit($pet->fresh(), 'reference_image_failed');
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  Life-stage growth (M5-R01, RegeneratePetStageMedia)
    // ──────────────────────────────────────────────────────────────

    /**
     * The pet reached a new life stage: archive the stored reference image
     * (pet_media_history — the file stays) and generate the image of the new
     * stage as an edit of it; the state videos of the pet's entitlement
     * follow automatically once the new image is stored (their source
     * generation is older). Budget / ledger as every reference image.
     *
     * @return 'started'|'up_to_date'|'busy'|'no_image'|'disabled'
     */
    public function startStageTransition(Pet $pet): string
    {
        $stage = $pet->life_stage;

        if (! $this->fal->isEnabled() || ! $pet->is_active || $pet->isUnborn() || $stage === null || $pet->isLegacyProfile()) {
            return 'disabled';
        }

        $slot = $this->imageSlot($pet);

        // Same stage, or the image already shows a LATER stage (an admin edit
        // moved the boundaries back): images only ever grow forward.
        if ($slot->life_stage === $stage->value || self::stageRank($slot->life_stage) > self::stageRank($stage->value)) {
            return 'up_to_date';
        }

        if ($slot->isInFlight()) {
            // A first image (or an admin regeneration) is running: it is
            // generated for the stage of its claim; try again later.
            return 'busy';
        }

        if (! $slot->isServable()) {
            // No image to grow from (failed / never generated): the normal
            // retry / backfill path generates one for the current stage.
            return 'no_image';
        }

        PetMediaHistory::query()->insertOrIgnore([
            'pet_id' => $pet->id,
            'kind' => PetMedia::KIND_IMAGE,
            'life_stage' => $slot->life_stage,
            'generation' => $slot->generation,
            'storage_path' => $slot->storage_path,
            'bytes' => $slot->bytes,
            'mime' => $slot->mime,
            'archived_at' => now(),
            // M5-R04 growth album: when this picture became the pet's image.
            'taken_at' => $this->storedAt($slot),
        ]);

        if (! $this->resetForNewGeneration($slot, bump: true)) {
            return 'busy';
        }

        GeneratePetReferenceImage::dispatch($pet->id);

        return 'started';
    }

    /**
     * The stored reference image shows an EARLIER life stage than the pet
     * is in (a lost / given-up RegeneratePetStageMedia): media:retry and
     * media:backfill re-queue the stage transition. Never for a legacy,
     * unborn or inactive pet, never backward.
     */
    public function stageImageBehind(Pet $pet, ?PetMedia $image = null): bool
    {
        if ($pet->isLegacyProfile() || $pet->isUnborn() || ! $pet->is_active || $pet->life_stage === null) {
            return false;
        }

        $image ??= PetMedia::query()->where('pet_id', $pet->id)->images()->first();

        return $image !== null
            && $image->status === PetMedia::STATUS_READY
            && $image->life_stage !== null
            && self::stageRank($image->life_stage) < self::stageRank($pet->life_stage->value);
    }

    /** Position of a stage value in age order (-1 for null / unknown). */
    public static function stageRank(?string $stage): int
    {
        foreach (LifeStage::ordered() as $i => $s) {
            if ($s->value === $stage) {
                return $i;
            }
        }

        return -1;
    }

    /** Estimated cost of one stage image (the edit profile, else text-to-image). */
    public function stageImageCostUsd(): float
    {
        return ($this->profiles->stageEdit() ?? $this->profiles->referenceImage())->estimatedCostUsd();
    }

    // ──────────────────────────────────────────────────────────────
    //  Admin: regenerate / generate missing
    // ──────────────────────────────────────────────────────────────

    /**
     * Superadmin "Regenerate" (Filament): a new generation of one slot. The old
     * file stays servable until the new one is stored. Regenerating the image
     * also regenerates the videos afterwards (they are made from it).
     */
    public function regenerate(PetMedia $slot): bool
    {
        $pet = $slot->pet;

        // M4-10: a pool pet's media belong to its shared look (other pets show them) — no per-pet regenerate.
        if ($pet === null || ! $pet->is_active || ! $this->fal->isEnabled() || $slot->isInFlight() || $pet->usesLookPool()) {
            return false;
        }

        // M5-T08b (QA m1): no key image / transition while state media is switched off.
        if (($slot->isPoseStill() || $slot->isPoseClip()) && ! $this->entitlements->stateMediaEnabled(fresh: true)) {
            return false;
        }

        // QA M2: no pose image / phase clip while »School & game clips« is off.
        if (($slot->isPose() || $slot->isPhase()) && ! $this->entitlements->phaseClipsEnabled(fresh: true)) {
            return false;
        }

        if (! $this->resetForNewGeneration($slot, bump: true)) {
            return false;
        }

        if ($slot->isImage()) {
            $dna = is_array($pet->pet_dna) ? $pet->pet_dna : [];
            $dna['reference_image_url'] = null;
            $pet->updateQuietly(['pet_dna' => $dna, 'media_status' => 'pending', 'media_error' => null]);
            GeneratePetReferenceImage::dispatch($pet->id);

            return true;
        }

        if ($slot->isKeyframe()) {
            // M5-T08: the state's loop and the transitions through this pose are made
            // from the key image — they follow the new one (queueStateVideos once it is stored).
            $state = $slot->petState();
            $through = array_map(fn (PetTransition $t) => $t->value, array_filter(PetTransition::cases(), fn (PetTransition $t) => $t->fromState() === $state || $t->toState() === $state));
            PetMedia::query()->where('pet_id', $pet->id)
                ->where(fn ($q) => $q->where(fn ($q) => $q->where('kind', PetMedia::KIND_VIDEO)->where('state', $slot->state))
                    ->orWhere(fn ($q) => $q->where('kind', PetMedia::KIND_TRANSITION)->whereIn('state', $through)))
                ->get()
                ->each(fn (PetMedia $dependant) => $this->resetForNewGeneration($dependant, bump: true));
            GeneratePetKeyframe::dispatch($slot->id);

            return true;
        }

        if ($slot->isPose()) {
            // M5-T09 / T10: the phase clips starting or ending in this pose follow the new image.
            $pose = $slot->pose();
            $through = array_map(fn (PetPhaseClip $c) => $c->value, array_filter(PetPhaseClip::cases(), fn (PetPhaseClip $c) => $c->startPose() === $pose || $c->endPose() === $pose));
            PetMedia::query()->where('pet_id', $pet->id)->where('kind', PetMedia::KIND_PHASE)->whereIn('state', $through)
                ->get()
                ->each(fn (PetMedia $dependant) => $this->resetForNewGeneration($dependant, bump: true));
            GeneratePetKeyframe::dispatch($slot->id);

            return true;
        }

        SubmitPetStateVideo::dispatch($slot->id);

        return true;
    }

    /**
     * Put a finished slot (ready / failed) back to `pending` for a new generation —
     * conditional (`WHERE status NOT IN (pending, running)`), so a slot another
     * worker just claimed is never reset under it (PR #24 review m7).
     */
    public function resetForNewGeneration(PetMedia $slot, bool $bump, ?int $autoRetryBelow = null): bool
    {
        $candidate = PetMedia::whereKey($slot->id)->value('candidate_path');

        $reset = PetMedia::query()
            ->whereKey($slot->id)
            ->whereNotIn('status', [PetMedia::STATUS_PENDING, PetMedia::STATUS_RUNNING])
            // David 2026-10-28 (QA #434 M1 / m5): an automatic activity retry counts in the same
            // conditional UPDATE (no race past the cap); every other reset starts a new chain.
            ->when($autoRetryBelow !== null, fn ($q) => $q->where('auto_retries', '<', $autoRetryBelow))
            ->update([
                ...($autoRetryBelow !== null
                    ? ['auto_retries' => DB::raw('auto_retries + 1')]
                    : ['auto_retries' => 0, 'auto_alerted_at' => null]),
                'status' => PetMedia::STATUS_PENDING,
                'generation' => DB::raw('generation + '.($bump ? 1 : 0)),
                'request_id' => null,
                'status_url' => null,
                'response_url' => null,
                'source_url' => null,
                'error_reason' => null,
                'error' => null,
                // Quality check: a »Needs review« candidate is dropped with the new generation
                // (an admin Regenerate starts a new chain with its own one automatic retry).
                // `quality` stays: it describes the STORED file, which stays servable.
                'candidate_path' => null,
                'candidate_bytes' => null,
                'candidate_mime' => null,
                'updated_at' => now(),
            ]) === 1;

        if ($reset) {
            $slot->refresh();

            if (is_string($candidate) && $candidate !== '' && $candidate !== $slot->storage_path) {
                $this->disk()->delete($candidate);
            }
        }

        return $reset;
    }

    /**
     * What generating the missing media of a pet would do (media:backfill, Filament).
     *
     * `stage` = the stored image shows an earlier life stage (M5-R01): the
     * stage transition is re-queued (videos follow the new image).
     *
     * M5-T08: `keyframes` / `transitions` = the state key images and transition
     * clips that would be made; for a pet from before M5-T08 (state media
     * version 1) with `$upgradeStateMedia` the plan is that of version 2
     * (`upgrade` true) — the admin "Upgrade to state media" / media:backfill
     * --state-media are the only ways an existing pet gets them (M5-T08b).
     *
     * M5-T09 / T10: `poses` / `phases` = the pose images and school / game phase clips
     * that would be made (3 s clips, priced per purpose).
     *
     * M5-T16 (QA M2): `$stageRemake` prices a pet whose image is behind its life stage
     * (`stage`) with the FULL set of its new stage — the stage image re-queues every clip /
     * key image of the pet once it is stored, so all of them count (look rows that already
     * exist at the new stage stay free). Default off: »Generate missing« / media:backfill
     * keep listing only the stage image for such a pet.
     *
     * @return array{image: 'ok'|'stage'|'in_flight'|'download'|'generate', videos: list<string>, keyframes: list<string>, transitions: list<string>, poses: list<string>, phases: list<string>, upgrade: bool, cost_usd: float}
     */
    public function planMissing(Pet $pet, bool $upgradeStateMedia = false, bool $stageRemake = false): array
    {
        // M5-T09 / T10: the upgrade also moves a version-2 pet to 3 (school / game phase clips).
        $upgrade = $upgradeStateMedia && (int) ($pet->state_media_version ?? 1) < MediaEntitlementService::CURRENT_MEDIA_VERSION && ! $pet->isLegacyProfile();
        $planned = $upgrade ? (clone $pet)->forceFill(['state_media_version' => MediaEntitlementService::CURRENT_MEDIA_VERSION]) : $pet;
        $upgrade = $upgrade && $this->entitlements->usesStateMedia($planned);
        $planned = $upgrade ? $planned : $pet;

        // M5-T16 (QA m7): the bulk preview eager-loads `media` for all eligible pets.
        $slots = $pet->relationLoaded('media') ? $pet->media : $pet->media()->get();
        $image = $slots->first(fn (PetMedia $m) => $m->isImage());
        $legacyUrl = $pet->pet_dna['reference_image_url'] ?? null;

        $imageAction = match (true) {
            $image !== null && $this->stageImageBehind($pet, $image) => 'stage',
            $image?->status === PetMedia::STATUS_READY => 'ok',
            $image !== null && $image->isInFlight() && ($image->attempts > 0 || $image->source_url !== null) => 'in_flight',
            is_string($legacyUrl) && $this->fal->isAllowedMediaUrl($legacyUrl) && ($image === null || $image->generation === 1) => 'download',
            default => 'generate',
        };

        // A stage remake makes a new image generation: every stored clip of the pet is redone.
        $currentImageGeneration = $imageAction === 'ok' ? $image->generation : PHP_INT_MAX;
        $remake = $stageRemake && $imageAction === 'stage';
        $loops = $this->entitlements->usesStateMedia($planned);
        // QA #363 M4: a clip the quality check failed twice (»Needs review«) is never re-made here —
        // only an explicit Regenerate or »Approve anyway« touches it (no re-spend in a loop).
        $missing = fn (?PetMedia $slot, bool $recipeStale = false): bool => $slot === null
            || ($slot->status === PetMedia::STATUS_FAILED && $slot->error_reason !== AiCallFailure::QualityFailed->value)
            || ($slot->status === PetMedia::STATUS_PENDING && $slot->request_id === null)
            || (! $slot->isInFlight() && ((int) $slot->source_generation < $currentImageGeneration || $recipeStale));
        $videos = [];
        $keyframes = [];
        $transitions = [];

        // Unborn pets get no videos yet (they follow the first contract); a
        // stage image re-queues its videos itself once stored.
        $later = $pet->isUnborn() || ($imageAction === 'stage' && ! $remake);

        foreach ($later ? [] : $this->entitlements->videoStatesFor($planned) as $state) {
            $slot = $slots->first(fn (PetMedia $m) => $m->isVideo() && $m->state === $state->value);

            if ($missing($slot, $loops && $slot?->status === PetMedia::STATUS_READY && $slot->recipe === null)) {
                $videos[] = $state->value;
            }
        }

        foreach ($later ? [] : $this->entitlements->keyframeStatesFor($planned) as $state) {
            if ($missing($slots->first(fn (PetMedia $m) => $m->isKeyframe() && $m->state === $state->value))) {
                $keyframes[] = $state->value;
            }
        }

        // Frugal clips (David 2026-10-30): what the switch leaves to a source costs nothing.
        // QA #451 m9 / B1: the switch read fresh like the submit; a stage remake makes every stored clip stale.
        $remakeGeneration = $remake ? PHP_INT_MAX : null;
        foreach ($later ? [] : $this->entitlements->transitionsToGenerateFor($planned, $slots, fresh: true, imageGeneration: $remakeGeneration) as $transition) {
            if ($missing($slots->first(fn (PetMedia $m) => $m->isTransition() && $m->state === $transition->value))) {
                $transitions[] = $transition->value;
            }
        }

        $poses = [];
        $phases = [];
        foreach ($later ? [] : $this->entitlements->posesFor($planned) as $pose) {
            if ($missing($slots->first(fn (PetMedia $m) => $m->isPose() && $m->state === $pose->value))) {
                $poses[] = $pose->value;
            }
        }
        foreach ($later ? [] : $this->entitlements->phaseClipsToGenerateFor($planned, $slots, fresh: true, imageGeneration: $remakeGeneration) as $clip) {
            if ($missing($slots->first(fn (PetMedia $m) => $m->isPhase() && $m->state === $clip->value))) {
                $phases[] = $clip->value;
            }
        }

        if ($pet->usesLookPool()) {
            // M4-10: only what the shared look does not have yet costs anything.
            $cost = $this->lookPlanCostUsd($pet, $imageAction, $videos, $keyframes, $transitions, $loops, $poses, $phases);
        } else {
            $cost = match ($imageAction) {
                'generate' => $this->profiles->referenceImage()->estimatedCostUsd(),
                'stage' => $this->stageImageCostUsd(),
                default => 0.0,
            } + array_sum(array_map(fn (string $state) => $this->videoCostUsd(PetStateEnum::from($state), $loops, $pet->speciesValue()), $videos))
                + count($transitions) * $this->profiles->clipVideo('transition')->estimatedCostUsd()
                + (count($keyframes) + count($poses)) * $this->keyframeCostUsd()
                + array_sum(array_map(fn (string $c) => $this->phaseClipCostUsd(PetPhaseClip::from($c)), $phases));
        }

        return ['image' => $imageAction, 'videos' => $videos, 'keyframes' => $keyframes, 'transitions' => $transitions, 'poses' => $poses, 'phases' => $phases, 'upgrade' => $upgrade, 'cost_usd' => round($cost, 4)];
    }

    /** M5-T08b: the superadmin state media switch (MediaEntitlementService::stateMediaEnabled). */
    public function stateMediaEnabled(): bool
    {
        return $this->entitlements->stateMediaEnabled();
    }

    /** QA M2: the superadmin »School & game clips« switch (MediaEntitlementService::phaseClipsEnabled). */
    public function phaseClipsEnabled(): bool
    {
        return $this->entitlements->phaseClipsEnabled();
    }

    /**
     * Estimated cost of one state video: a frugal short loop (switch on, low-motion state, a
     * loop — David 2026-10-30) is priced at its 3 s, every other video at the profile's 5 s.
     */
    public function videoCostUsd(PetStateEnum $state, bool $loop, Species $species = Species::Dog): float
    {
        return $loop && $this->entitlements->shortLoop($species, $state, fresh: true)
            ? $this->profiles->clipVideo('state_short')->estimatedCostUsd()
            : $this->profiles->stateVideo()->estimatedCostUsd();
    }

    /**
     * The nightly retry's estimate for a failed video slot: a state loop of a pet with state
     * media at its frugal length (videoCostUsd), anything else `$default` (the 5 s video).
     */
    public function retryVideoCostUsd(PetMedia $slot, float $default): float
    {
        $state = $slot->petState();
        if (! $slot->isVideo() || $state === null || $slot->pet === null) {
            return $default;
        }

        return $this->videoCostUsd($state, $this->entitlements->usesStateMedia($slot->pet), $slot->pet->speciesValue());
    }

    /** M5-T09 / T10: estimated cost of one phase clip (3 s school / game clip). */
    public function phaseClipCostUsd(PetPhaseClip $clip): float
    {
        return $this->profiles->clipVideo($clip->purpose())->estimatedCostUsd();
    }

    /** M5-T08: estimated cost of one key image (0 when no key image profile is enabled). */
    public function keyframeCostUsd(): float
    {
        return $this->profiles->keyframe()?->estimatedCostUsd() ?? 0.0;
    }

    /**
     * Queue whatever is missing for one pet. Videos follow automatically once
     * a new / downloaded image is stored. Returns what was queued.
     *
     * M5-T08: a pet from before M5-T08 is moved to state media version 2 here only
     * when asked (`$upgradeStateMedia`: admin "Upgrade to state media", media:backfill
     * --state-media; M5-T08b: admin "Generate missing" never upgrades) — never automatically.
     *
     * @return array{image: bool, videos: int}
     */
    public function generateMissing(Pet $pet, bool $upgradeStateMedia = false): array
    {
        if (! $this->fal->isEnabled() || ! $pet->is_active) {
            return ['image' => false, 'videos' => 0];
        }

        $plan = $this->planMissing($pet, $upgradeStateMedia);

        if ($plan['upgrade']) {
            $pet->forceFill(['state_media_version' => MediaEntitlementService::CURRENT_MEDIA_VERSION])->saveQuietly();
        }

        if ($plan['image'] === 'stage') {
            RegeneratePetStageMedia::dispatch($pet->id);

            return ['image' => true, 'videos' => 0];
        }

        if (in_array($plan['image'], ['download', 'generate'], true)) {
            $slot = $this->imageSlot($pet);

            if ($slot->status === PetMedia::STATUS_FAILED) {
                // An admin »Generate missing« starts a new failure chain (QA #434 M1).
                $slot->update(['status' => PetMedia::STATUS_PENDING, 'error_reason' => null, 'error' => null, 'auto_retries' => 0, 'auto_alerted_at' => null]);
            }

            $pet->updateQuietly(['media_status' => 'pending', 'media_error' => null]);
            GeneratePetReferenceImage::dispatch($pet->id);

            return ['image' => true, 'videos' => 0];
        }

        return ['image' => false, 'videos' => $this->queueStateVideos($pet, includeFailed: true)];
    }

    // ──────────────────────────────────────────────────────────────
    //  API payload + signed URLs
    // ──────────────────────────────────────────────────────────────

    /**
     * `media` object of the pet state (child state, dashboard, pairing, PetUpdated).
     *
     * status: disabled | pending | failed (no reference image yet / not possible) |
     *         partial (image stored, some entitled videos missing) | ready (everything stored)
     *
     * @param  Collection<int, PetMedia>|null  $slots
     * @return array{status: string, reference_image_url: string|null, videos: object, current_video_url: string|null, states: list<string>, expires_at: string|null}
     */
    public function payloadFor(Pet $pet, ?Collection $slots = null): array
    {
        return $this->mediaFor($pet, $slots)->toArray();
    }

    /**
     * @param  Collection<int, PetMedia>|null  $slots
     */
    public function mediaFor(Pet $pet, ?Collection $slots = null): PetMediaPayload
    {
        $slots ??= $pet->relationLoaded('media') ? $pet->getRelation('media') : $pet->media()->get();
        $expires = $this->urlExpiry();
        $states = array_map(fn (PetStateEnum $s) => $s->value, $this->entitlements->videoStatesFor($pet));

        $image = $slots->first(fn (PetMedia $m) => $m->isImage());
        $imageUrl = $image?->isServable() ? $this->signedUrl($image, $expires) : null;

        $videos = [];
        foreach (PetStateEnum::cases() as $state) {
            // M5-R02: a behaviour video the pet is not entitled to any more (the
            // puppy's `accident` after puppy → young) is kept but not served.
            // M3-11 P6: judged by whether the event can happen, not by the tier, so a
            // pet whose tier dropped (grandfathered → basic) keeps serving what it has.
            // M5-R06-07: the same for the cat's `scratching` (behaviourApplies is per species).
            if ($state->isBehaviour() && ! $this->entitlements->behaviourApplies($pet, $state)) {
                continue;
            }
            $slot = $slots->first(fn (PetMedia $m) => $m->isVideo() && $m->state === $state->value);

            // Quality check (David 2026-10-23): a clip flagged by media:quality-check is not shown.
            if ($slot?->isShown()) {
                $videos[$state->value] = $this->signedUrl($slot, $expires);
            }
        }

        // M5-T08: key images of the states (idle = the reference image), transition
        // clips and which stored videos are loops made from the key image (PetMedia::isStateLoop).
        $keyframes = [];
        $loops = [];
        foreach ($slots as $m) {
            $state = $m->petState();

            if ($state === null || ! $m->isShown() || ($state->isBehaviour() && ! $this->entitlements->behaviourApplies($pet, $state))) {
                continue;
            }

            if ($m->isKeyframe()) {
                $keyframes[$state->value] = $this->signedUrl($m, $expires);
            } elseif ($m->isStateLoop() && isset($videos[$state->value])) { // the stored file (stored_recipe)
                $loops[] = $state->value;
            }
        }

        $transitions = [];
        // M5-T09 / T10: pose images and school / game phase clips (only the pet's own species).
        $poses = [];
        $phases = [];
        $phaseSeconds = [];
        // School smooth playback + frugal clips: the shown rows clips are derived from.
        $derivedSources = new Collection;
        $species = $pet->speciesValue();
        foreach ($slots as $m) {
            if (! $m->isShown()) {
                continue;
            }
            if (($m->isTransition() || $m->isVideo()) && SchoolClipDerivativeService::derivedKeyOf($m) !== null) {
                $derivedSources->push($m);
            }
            if ($m->isTransition() && $m->transition() !== null) {
                $transitions[$m->state] = $this->signedUrl($m, $expires);
            } elseif ($m->isPose() && $m->pose()?->species() === $species) {
                $poses[$m->state] = $this->signedUrl($m, $expires);
            } elseif ($m->isPhase() && $m->phaseClip()?->species() === $species) {
                $phases[$m->state] = $this->signedUrl($m, $expires);
                // David 2026-10-23: school performing clips are 5 s now, older stored ones 3 s.
                $phaseSeconds[$m->state] = (float) ($m->stored_duration_seconds ?? $m->duration_seconds ?? 3);
                // School clips + games phase 1 (ball_waiting → loop, ball_running → exit side).
                if (SchoolClipDerivativeService::keyFor($m->phaseClip()) !== null) {
                    $derivedSources->push($m);
                }
            }
        }

        // School smooth playback (David 2026-10-28): ffmpeg-derived clips (attention / success
        // loops, release) next to their source, like any phase clip (URL + real length).
        // Games phase 1 (GAMES_SPEC §8.2 / §8.4): `ball_waiting_loop` the same way, and the
        // measured / admin-set `phase_meta.ball_running.exit_side`. Missing = the app's fallback
        // (loop ball_waiting, ball to the centre) — never a lock.
        ['clips' => $derived, 'meta' => $phaseMeta] = app(SchoolClipDerivativeService::class)->derivedFor($derivedSources, $expires);
        foreach ($derived as $key => $clip) {
            if (str_starts_with($key, PetPhaseClip::GROUP_SCHOOL.'_') || $key === SchoolClipDerivativeService::KEY_BALL_WAITING_LOOP) {
                $phases[$key] = $clip['url'];
                $phaseSeconds[$key] = $clip['seconds'];
            }
        }

        $this->applyFrugalSources($pet, $slots, $derived, $videos, $transitions, $phases, $phaseSeconds);

        $currentState = $pet->pet_state instanceof PetStateEnum ? $pet->pet_state->value : (string) $pet->pet_state;

        $status = match (true) {
            $imageUrl !== null => count(array_diff($states, array_keys($videos))) === 0 ? 'ready' : 'partial',
            $image?->status === PetMedia::STATUS_FAILED, $pet->media_status === 'failed' => 'failed',
            // M5-T06: an image slot without a file (created at birth by queueStateVideos) does not
            // make a pet without AI media `pending` — it never gets any (`media_status` stays disabled).
            $pet->media_status === 'disabled' => 'disabled',
            default => 'pending',
        };

        return new PetMediaPayload(
            status: $status,
            referenceImageUrl: $imageUrl,
            videos: $videos,
            currentVideoUrl: $videos[$currentState] ?? $videos[PetStateEnum::Idle->value] ?? null,
            states: array_values($states),
            expiresAt: ($imageUrl !== null || $videos !== []) ? $expires->toIso8601String() : null,
            keyframes: $keyframes,
            transitions: $transitions,
            loops: array_values(array_intersect(array_keys($videos), $loops)),
            poses: $poses,
            phases: $phases,
            phaseSeconds: $phaseSeconds,
            phaseMeta: $phaseMeta,
            // QA m2: the same switch-aware source as readiness / preparation (phaseClipsFor) —
            // a switch off → [] → the app shows the M5-T08 stage, the server checks the v2 rules.
            phaseGroups: array_values(array_unique(array_map(fn (PetPhaseClip $c) => $c->group(), $this->entitlements->phaseClipsFor($pet)))),
            // M5-T08b (QA m3): the pet's set, not the switch — off never downgrades stored media.
            stateMedia: $this->entitlements->hasStateMediaSet($pet),
        );
    }

    /**
     * Frugal clips (David 2026-10-30) in the payload — the keys the app already reads:
     *  A. a come / place / potty attention clip served from the sit attention clip (+ its
     *     ffmpeg attention loop) — MediaEntitlementService::phaseSourceOf();
     *  B. `transitions.sleeping_to_idle` = idle_to_sleeping reversed (only once that derived
     *     clip exists — missing = the app's usual fallback for a missing transition);
     *  C. `videos.<state>` of a short low-motion loop = its ping-pong once made (the stored
     *     3 s loop until then).
     * A target with media of its own keeps them (existing pets / looks as before).
     *
     * @param  Collection<int, PetMedia>  $slots
     * @param  array<string, array{url: string, seconds: float}>  $derived
     * @param  array<string, string>  $videos
     * @param  array<string, string>  $transitions
     * @param  array<string, string>  $phases
     * @param  array<string, float>  $phaseSeconds
     */
    private function applyFrugalSources(Pet $pet, Collection $slots, array $derived, array &$videos, array &$transitions, array &$phases, array &$phaseSeconds): void
    {
        foreach (MediaEntitlementService::FRUGAL_PHASE_SOURCES as $target => $source) {
            $clip = PetPhaseClip::from($target);
            if (! isset($phases[$source]) || $this->entitlements->phaseSourceOf($pet, $clip, $slots)->value !== $source) {
                continue;
            }
            $phases[$target] = $phases[$source];
            $phaseSeconds[$target] = $phaseSeconds[$source];
            $targetLoop = SchoolClipDerivativeService::keyFor($clip);
            $sourceLoop = SchoolClipDerivativeService::keyFor(PetPhaseClip::from($source));
            if ($targetLoop !== null && $sourceLoop !== null && isset($phases[$sourceLoop])) {
                $phases[$targetLoop] = $phases[$sourceLoop];
                $phaseSeconds[$targetLoop] = $phaseSeconds[$sourceLoop];
            }
        }

        foreach (MediaEntitlementService::LEGACY_REVERSED_TRANSITIONS as $target => $source) {
            // Legacy reversal (marked slots only): derived clip first — no extra query without one.
            if (isset($derived[$target]) && $this->entitlements->transitionSourceOf($pet, PetTransition::from($target), $slots) !== null) {
                $transitions[$target] = $derived[$target]['url'];
            }
        }

        foreach (MediaEntitlementService::shortLoopStates() as $state) {
            if (isset($videos[$state], $derived[$state.'_'.SchoolClipDerivativeService::SUFFIX_LOOP])) {
                $videos[$state] = $derived[$state.'_'.SchoolClipDerivativeService::SUFFIX_LOOP]['url'];
            }
        }
    }

    /**
     * When the slot's stored file was stored (growth album, M5-R04):
     * `completed_at` of a READY slot; otherwise (a later generation failed or
     * is running and the old file is kept) the file's modification time —
     * `completed_at` then belongs to the failed attempt. Null without a file.
     */
    public function storedAt(PetMedia $slot): ?CarbonImmutable
    {
        if (! $slot->isServable()) {
            return null;
        }

        if ($slot->status === PetMedia::STATUS_READY && $slot->completed_at !== null) {
            return CarbonImmutable::instance($slot->completed_at);
        }

        return $this->fileModifiedAt((string) $slot->storage_path);
    }

    /**
     * Modification time of a file on the pet-media disk (null if missing / unreadable).
     * On object storage always null (QA #374 m4: no HEAD per row — callers use DB times).
     */
    public function fileModifiedAt(string $path): ?CarbonImmutable
    {
        if (app(MediaStorage::class)->usesObjectStorage()) {
            return null;
        }

        try {
            return $this->disk()->exists($path)
                ? CarbonImmutable::createFromTimestampUTC($this->disk()->lastModified($path))
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Expiry for app URLs, bucketed to half the TTL: the same URL for ~30 min
     * (no player restart on every state poll), valid for 60–90 min (default TTL).
     */
    public function urlExpiry(?int $ttlMinutes = null): CarbonImmutable
    {
        $ttl = 60 * ($ttlMinutes ?? (int) config('media.storage.url_ttl_minutes', 60));
        $bucket = max(60, intdiv($ttl, 2));

        return CarbonImmutable::createFromTimestampUTC((intdiv(now()->getTimestamp() + $ttl, $bucket) + 1) * $bucket);
    }

    /**
     * Absolute signed URL to GET /api/media/{media}. Relative signature, so it
     * survives the reverse proxy (scheme / host); `v` changes with every stored file.
     */
    public function signedUrl(PetMedia $slot, ?CarbonImmutable $expires = null): string
    {
        $path = URL::temporarySignedRoute(
            'media.show',
            $expires ?? $this->urlExpiry(),
            ['media' => $slot->id, 'v' => substr(sha1((string) $slot->storage_path), 0, 10)],
            absolute: false,
        );

        return rtrim((string) config('app.url'), '/').$path;
    }

    /**
     * Absolute signed URL to GET /api/media/history/{history} — an archived
     * reference image of an earlier life stage (growth album, M5-R04). Same
     * capability rules as signedUrl().
     */
    public function signedHistoryUrl(PetMediaHistory $entry, ?CarbonImmutable $expires = null): string
    {
        $path = URL::temporarySignedRoute(
            'media.history.show',
            $expires ?? $this->urlExpiry(),
            ['history' => $entry->id, 'v' => substr(sha1($entry->storage_path), 0, 10)],
            absolute: false,
        );

        return rtrim((string) config('app.url'), '/').$path;
    }

    /**
     * URL fal fetches the stored reference image from (video start frame). Our
     * own copy, so a video always starts from exactly the image the app shows.
     */
    public function falFetchUrl(PetMedia $image): string
    {
        return $this->signedUrl($image, $this->urlExpiry((int) config('media.storage.fal_fetch_ttl_minutes', 360)));
    }

    // ──────────────────────────────────────────────────────────────
    //  Sweep (media:sweep, hourly) + cleanup
    // ──────────────────────────────────────────────────────────────

    /**
     * Recover slots a lost job / webhook left behind:
     *  - submitted videos without a webhook after 2 h → failed `timed_out`
     *    (cost kept; media:backfill / Filament can regenerate);
     *  - fix 2026-10-28: queued key / pose images without a webhook after 10 min are POLLED at
     *    fal (free status read): a finished result is recorded like the webhook would
     *    (→ StorePetMedia), a fal failure fails the slot, still running → waits; after 2 h
     *    (or with nothing to poll) → failed `timed_out`. Never resubmitted here (no double pay);
     *  - a result URL whose download job got lost → StorePetMedia again (quality check: a
     *    downloaded candidate whose check job got lost → its check again, no new download);
     *  - a claim of a dead worker → its job again (which adopts an accepted request id);
     *  - stale download temp files are deleted.
     *
     *  - M5-T08b: state media slots left pending while the switch was off → the pet's pipeline again.
     *
     * @return array{timed_out: int, polled: int, downloads: int, reclaimed: int, resumed: int, temp_files: int}
     */
    public function sweepStale(): array
    {
        $timedOut = 0;
        $polled = 0;
        PetMedia::query()->whereIn('kind', [PetMedia::KIND_KEYFRAME, PetMedia::KIND_POSE])
            ->where('status', PetMedia::STATUS_RUNNING)
            ->whereNotNull('request_id')
            ->whereNull('source_url')
            ->where('updated_at', '<', now()->subMinutes(self::IMAGE_POLL_AFTER_MINUTES))
            ->orderBy('id')
            ->each(function (PetMedia $slot) use (&$timedOut, &$polled): void {
                match ($this->pollPoseStill($slot)) {
                    'recorded', 'failed' => $polled++,
                    'timed_out' => $timedOut++,
                    default => null,
                };
            });

        PetMedia::query()->clips()
            ->where('status', PetMedia::STATUS_RUNNING)
            ->whereNotNull('request_id')
            ->whereNull('source_url')
            ->where('updated_at', '<', now()->subMinutes(self::WEBHOOK_TIMEOUT_MINUTES))
            ->each(function (PetMedia $slot) use (&$timedOut): void {
                $this->fail($slot, AiCallFailure::TimedOut, 'No fal webhook within '.self::WEBHOOK_TIMEOUT_MINUTES.' minutes.');
                $timedOut++;
            });

        $downloads = 0;
        PetMedia::query()
            ->where('status', PetMedia::STATUS_RUNNING)
            ->whereNotNull('source_url')
            ->where('updated_at', '<', now()->subMinutes(60))
            ->each(function (PetMedia $slot) use (&$downloads): void {
                $slot->touch();
                StorePetMedia::dispatch($slot->id);
                $downloads++;
            });

        $reclaimed = 0;
        PetMedia::query()
            ->where('status', PetMedia::STATUS_RUNNING)
            ->whereNull('request_id')
            ->whereNull('source_url')
            ->where('updated_at', '<', now()->subMinutes(self::STALE_CLAIM_MINUTES))
            // M5-T08b: state media switched off — key images and transitions are not re-queued.
            ->when(! $this->entitlements->stateMediaEnabled(fresh: true), fn ($q) => $q->whereNotIn('kind', [PetMedia::KIND_KEYFRAME, PetMedia::KIND_TRANSITION, PetMedia::KIND_POSE, PetMedia::KIND_PHASE]))
            // QA M2: »School & game clips« off — pose images / phase clips are not re-queued.
            ->when(! $this->entitlements->phaseClipsEnabled(fresh: true), fn ($q) => $q->whereNotIn('kind', [PetMedia::KIND_POSE, PetMedia::KIND_PHASE]))
            ->each(function (PetMedia $slot) use (&$reclaimed): void {
                // M4-10: a look image has no job of its own — the waiting pets' slots
                // (reclaimed here too) run it again; the next pet needing it reclaims it.
                if ($slot->isLookMedia() && $slot->isImage()) {
                    return;
                }
                match ($slot->kind) {
                    PetMedia::KIND_IMAGE => GeneratePetReferenceImage::dispatch($slot->pet_id),
                    PetMedia::KIND_KEYFRAME, PetMedia::KIND_POSE => GeneratePetKeyframe::dispatch($slot->id),
                    default => SubmitPetStateVideo::dispatch($slot->id),
                };
                $reclaimed++;
            });

        // M5-T08b (QA M1): slots left `pending` while state media was off (their job ran and
        // returned without a fal call) are resumed once it is on again.
        $resumed = $this->entitlements->stateMediaEnabled(fresh: true)
            ? $this->resumeStateMedia(staleOnly: true)
            : 0;

        return ['timed_out' => $timedOut, 'polled' => $polled, 'downloads' => $downloads, 'reclaimed' => $reclaimed, 'resumed' => $resumed, 'temp_files' => $this->cleanupTempFiles()];
    }

    /**
     * Fix 2026-10-28: media:sweep's fallback for a queued key / pose image whose webhook
     * did not come — a FREE status read at fal (FalGateway::poll, outside any transaction),
     * then the same recording as the webhook under the row lock (matched by request id, so a
     * webhook that won the race or a regenerate in between is left alone). Never submits.
     *
     * @return 'recorded'|'failed'|'pending'|'timed_out'|'skipped'
     */
    private function pollPoseStill(PetMedia $slot): string
    {
        $requestId = (string) $slot->request_id;
        $canPoll = $this->fal->isEnabled() && filled($slot->status_url) && filled($slot->response_url)
            && str_contains((string) $slot->status_url, $requestId);

        $poll = $canPoll ? $this->gateway->poll((string) $slot->status_url, (string) $slot->response_url) : null;

        return DB::transaction(function () use ($slot, $requestId, $poll): string {
            $locked = PetMedia::query()->whereKey($slot->id)->where('request_id', $requestId)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== PetMedia::STATUS_RUNNING || $locked->source_url !== null) {
                return 'skipped'; // the webhook / a regenerate got there first
            }

            if ($poll !== null && $poll['state'] === 'completed') {
                $result = $this->fal->parseWebhookResult(['status' => 'OK', 'payload' => $poll['body']], image: true);
                Log::warning('PetMediaService: key / pose image recovered by polling (webhook lost)', ['pet_media_id' => $locked->id, 'ok' => $result['ok']]);

                return $this->recordVideoResult($locked, $result) === 'recorded' ? 'recorded' : 'failed';
            }

            if ($poll !== null && $poll['state'] === 'failed') {
                $this->fail($locked, AiCallFailure::GenerationFailed, 'fal.ai: '.($poll['error'] ?? 'request failed'));

                return 'failed';
            }

            if ($locked->updated_at !== null && $locked->updated_at->gte(now()->subMinutes(self::WEBHOOK_TIMEOUT_MINUTES))) {
                return 'pending'; // fal is still working on it (or the status read failed) — next sweep
            }

            $this->fail($locked, AiCallFailure::TimedOut, 'No fal webhook within '.self::WEBHOOK_TIMEOUT_MINUTES.' minutes.');

            return 'timed_out';
        });
    }

    /**
     * M5-T08b (QA M1): re-run the media pipeline (queueStateVideos) of every active, born
     * pet that uses state media and has work left — key images, loops and transitions
     * in the usual order; the slot claims and unique jobs make a duplicate harmless.
     *
     * `staleOnly` (media:sweep): only pets with a key image / transition / state video slot
     * that is `pending` without a fal request for longer than STALE_CLAIM_MINUTES — a slot
     * whose job ran while the switch was off. Without it (the admin switch turned on, job
     * ResumeStateMedia) every such pet — which also remakes clips made the old way while
     * the switch was off as loops (costs, shown in the switch description).
     *
     * @return int pets whose pipeline was re-run
     */
    public function resumeStateMedia(bool $staleOnly = false): int
    {
        if (! $this->fal->isEnabled() || ! $this->entitlements->stateMediaEnabled(fresh: true)) {
            return 0;
        }

        $resumed = 0;

        Pet::query()
            ->where('is_active', true)
            ->whereNotNull('born_at')
            ->where('state_media_version', '>=', 2)
            ->when($staleOnly, fn ($q) => $q->whereExists(fn ($q) => $q->selectRaw('1')->from('pet_media as s')
                ->whereColumn('s.pet_id', 'pets.id')
                ->whereIn('s.kind', [PetMedia::KIND_KEYFRAME, PetMedia::KIND_TRANSITION, PetMedia::KIND_VIDEO, PetMedia::KIND_POSE, PetMedia::KIND_PHASE])
                ->where('s.status', PetMedia::STATUS_PENDING)
                ->whereNull('s.request_id')
                // Frugal clips: a slot the switch left to its source waits on purpose (not stale).
                ->whereNull('s.frugal_source')
                ->where('s.updated_at', '<', now()->subMinutes(self::STALE_CLAIM_MINUTES))))
            ->orderBy('id')
            ->each(function (Pet $pet) use (&$resumed): void {
                if ($this->entitlements->usesStateMedia($pet) && $this->queueStateVideos($pet) > 0) {
                    $resumed++;
                }
            });

        if ($resumed > 0) {
            Log::info('PetMediaService: state media resumed', ['pets' => $resumed, 'stale_only' => $staleOnly]);
        }

        return $resumed;
    }

    /**
     * Download temp files (`petmedia-*`) older than 2 h — left behind only when a
     * worker died mid-download (PR #24 review n3).
     */
    public function cleanupTempFiles(int $olderThanMinutes = 120): int
    {
        $deleted = 0;
        $cutoff = now()->subMinutes($olderThanMinutes)->getTimestamp();

        foreach (glob(rtrim(sys_get_temp_dir(), '/').'/'.MediaDownloader::TEMP_PREFIX.'*') ?: [] as $file) {
            if (is_file($file) && (int) @filemtime($file) < $cutoff && @unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Stored files of a deleted pet (rows go with the FK cascade) on EVERY media disk (M4-05):
     * the active one, local copies left from before the move (until `--prune-local`) and a
     * configured bucket that is not active yet — a deleted family's media stay nowhere.
     */
    public function deleteFilesOf(int $petId): void
    {
        $this->disk()->deleteDirectory((string) $petId);

        $storage = app(MediaStorage::class);
        $active = $storage->activeDiskName();
        $local = $storage->localDiskName();
        if ($local !== $active && $storage->driver($local) === 'local') {
            Storage::disk($local)->deleteDirectory((string) $petId);
        }

        // QA #374 M2: a configured but inactive bucket (after the copy, before the switch, or
        // after a rollback) may hold copies too — they go as well. A bucket error fails the
        // job (it retries; visible in System health) instead of leaving a family's media there.
        $object = $storage->objectDiskName();
        if ($object !== $active && $storage->objectStorageConfigured()) {
            Storage::disk($object)->deleteDirectory((string) $petId);
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  Shared looks (M4-10, free-pet pool)
    // ──────────────────────────────────────────────────────────────

    /**
     * The look row of (look, kind, state, stage), created as `pending` if
     * missing (race-safe: insert-or-ignore on pet_media_look_slot_unique).
     */
    public function lookSlot(PetLook $look, string $kind, PetStateEnum|PetTransition|PetPose|PetPhaseClip|null $state, LifeStage $stage): PetMedia
    {
        $query = PetMedia::query()->where('pet_look_id', $look->id)->where('kind', $kind)->where('life_stage', $stage->value)
            ->when($state === null, fn ($q) => $q->whereNull('state'), fn ($q) => $q->where('state', $state->value));

        $existing = (clone $query)->first();

        if ($existing !== null) {
            return $existing;
        }

        PetMedia::query()->insertOrIgnore([
            'pet_id' => null,
            'pet_look_id' => $look->id,
            'kind' => $kind,
            'state' => $state?->value,
            'life_stage' => $stage->value,
            'status' => PetMedia::STATUS_PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $query->firstOrFail();
    }

    /**
     * Regenerate the media of a shared look (M5-R21 admin action; also works
     * for a free pool look). Only the look rows of the life stages its ACTIVE
     * pets are at get a new generation; rows of other stages stay READY (so a
     * later-stage edit still grows from the older-stage image, and pets that
     * reach a stage later reuse its file). Every active pet at those stages
     * waits for the new files — its current ones stay visible until they are
     * stored (one fal call per look row, not per pet). Same seed and traits
     * (the look's DNA is never rewritten).
     *
     * Busy = a row of those stages is really being generated: `running` with a
     * live claim, or `pending` with a fal request (a submitted video). A
     * `pending` row without a request, or a dead claim (running, no request,
     * older than STALE_CLAIM_MINUTES), is stale and does not block.
     */
    public function regenerateLook(PetLook $look): LookRegeneration
    {
        if (! $this->fal->isEnabled()) {
            return new LookRegeneration(LookRegeneration::AI_OFF);
        }

        $pets = Pet::query()->where('pet_look_id', $look->id)->where('is_active', true)->whereNotNull('life_stage')->orderBy('id')->get();
        $stages = $pets->map(fn (Pet $pet) => $pet->life_stage instanceof LifeStage ? $pet->life_stage->value : (string) $pet->life_stage)
            ->unique()->values()->all();

        if ($stages === []) {
            return new LookRegeneration(LookRegeneration::NO_ACTIVE_PETS);
        }

        $rows = PetMedia::query()->where('pet_look_id', $look->id)->whereIn('life_stage', $stages)->get();

        if ($rows->contains(fn (PetMedia $r) => self::lookRowBusy($r))) {
            return new LookRegeneration(LookRegeneration::BUSY, 0, $stages);
        }

        foreach ($rows as $row) {
            // A stale pending row is already pending (no bump needed): its next claim generates it.
            $this->resetForNewGeneration($row, bump: true);
        }

        // M5-T08b (QA minor 6): a clip marked as a loop is remade as a loop only while an active
        // state-media pet of the look is at that stage; else it goes back to the pre-M5-T08 clip.
        foreach ($stages as $stage) {
            if (! $this->lookStateMediaWanted($look, $stage)) {
                PetMedia::query()->where('pet_look_id', $look->id)->where('life_stage', $stage)
                    ->where('kind', PetMedia::KIND_VIDEO)->whereNotNull('recipe')
                    ->where('status', PetMedia::STATUS_PENDING)->whereNull('request_id')
                    ->update(['recipe' => null]);
            }
        }

        $queued = 0;

        foreach ($pets as $pet) {
            $image = $this->imageSlot($pet);

            if ($image->isInFlight() || ! $this->resetForNewGeneration($image, bump: true)) {
                continue;
            }

            // The videos follow once the new image is linked (queueStateVideos: older source generation).
            $pet->updateQuietly(['media_status' => 'pending', 'media_error' => null]);
            GeneratePetReferenceImage::dispatch($pet->id);
            $queued++;
        }

        Log::info('PetMediaService: shared look regenerated', ['pet_look_id' => $look->id, 'pets' => $queued, 'stages' => $stages]);

        return new LookRegeneration(LookRegeneration::QUEUED, $queued, $stages);
    }

    /** A look row fal is working on right now (see regenerateLook). */
    private static function lookRowBusy(PetMedia $row): bool
    {
        if ($row->status === PetMedia::STATUS_PENDING) {
            return $row->request_id !== null;
        }

        if ($row->status !== PetMedia::STATUS_RUNNING) {
            return false;
        }

        $deadClaim = $row->request_id === null && $row->source_url === null
            && $row->updated_at !== null && $row->updated_at->lt(now()->subMinutes(self::STALE_CLAIM_MINUTES));

        return ! $deadClaim;
    }

    /**
     * M5-T08b: does an active pet of the look at this stage use state media (so the
     * look's key images / transitions of that stage are needed and its clips are made
     * as loops)? False while the switch is off.
     */
    public function lookStateMediaWanted(PetLook $look, string $stage, ?PetMedia $row = null): bool
    {
        // M5-T09 / T10: a pose / phase look row only while a pet of the look at the stage wants it
        // (state media version 3 + the clip's group) — never for a look only older pets use.
        if (! $this->entitlements->stateMediaEnabled(fresh: true)
            || ($row !== null && ($row->isPose() || $row->isPhase()) && ! $this->entitlements->phaseClipsEnabled(fresh: true))) {
            return false;
        }

        // The pet's stage, or the stage of the look image it shows (a stage change in progress).
        return Pet::query()->where('pet_look_id', $look->id)->where('is_active', true)
            ->where('state_media_version', '>=', 2)
            ->where(fn ($q) => $q->where('life_stage', $stage)
                ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('pet_media as img')
                    ->whereColumn('img.pet_id', 'pets.id')->where('img.kind', PetMedia::KIND_IMAGE)->where('img.life_stage', $stage)))
            ->get()
            ->contains(fn (Pet $pet): bool => $row !== null && ($row->isPose() || $row->isPhase())
                ? $this->wantsPoseMedia($pet, $row)
                : $this->entitlements->usesStateMedia($pet));
    }

    /** The look's stored image of the stage a look video row is made for (start frame). */
    /**
     * Quality check (David 2026-10-23): the stored still a clip ENDS in when it was made with
     * an end frame (transition / performing / ball running …) — the end pose picture of the
     * pet (or of the look at the row's stage). Null for start-only clips (loops, same-pose
     * phase clips) or when the picture is not stored.
     */
    public function clipEndFrameImage(PetMedia $clip, bool $stored = false): ?PetMedia
    {
        // `$stored`: the STORED file (stored_recipe); else the candidate being checked (recipe).
        $recipe = $stored ? $clip->stored_recipe : $clip->recipe;

        if (! $clip->isPoseClip() || $recipe === PetMedia::RECIPE_START_ONLY || ($clip->phaseClip()?->sharesFrames() ?? false)) {
            return null;
        }

        $frames = $clip->frames();
        if ($frames === null) {
            return null;
        }

        if ($clip->isLookMedia()) {
            $image = $this->lookImageRow($clip);
            $look = $clip->look;
            $end = $image !== null && $look !== null && $clip->life_stage !== null && $image->isServable()
                ? $this->lookPoseRow($look, $frames[1], LifeStage::from((string) $clip->life_stage), $image)
                : null;
        } else {
            $end = $clip->pet !== null ? $this->frameImage($clip->pet, $frames[1]) : null;
        }

        // QA #363 m8: only the pose image version the clip was made from — a pose stored after the
        // clip's submit (stored check: after the clip's file) would be compared with the wrong picture.
        $madeAt = $stored
            ? $clip->completed_at
            : AiSpendLedger::query()->where('pet_media_id', $clip->id)->where('purpose', 'state_video')->latest('id')->first()?->created_at;

        if ($end !== null && $madeAt !== null && $end->completed_at !== null && $end->completed_at->greaterThan($madeAt)) {
            return null;
        }

        return $end;
    }

    /** Quality check: the stored reference image of a clip's animal (pet or look stage), for »same animal«. */
    public function clipReferenceImage(PetMedia $clip): ?PetMedia
    {
        $image = $clip->isLookMedia() ? $this->lookImageRow($clip) : ($clip->pet !== null ? $this->imageSlot($clip->pet) : null);

        return $image?->isServable() ? $image : null;
    }

    public function lookImageRow(PetMedia $row): ?PetMedia
    {
        return PetMedia::query()->where('pet_look_id', $row->pet_look_id)->images()
            ->where('life_stage', $row->life_stage)->first();
    }

    /**
     * A pool pet's reference image: link the look's image of the pet's stage,
     * or wait for it (and generate it if nobody does). Returns false when the
     * queue should retry (transient fal failure).
     */
    private function referenceImageFromLook(Pet $pet, PetMedia $slot): bool
    {
        $look = $pet->look;
        $stage = $pet->life_stage;

        if ($look === null || $stage === null) {
            // Not reachable for a pool pet (a profile is required); never a unique image instead.
            Log::error('PetMediaService: pool pet without look / life stage', ['pet_id' => $pet->id]);

            return true;
        }

        $row = $this->lookSlot($look, PetMedia::KIND_IMAGE, null, $stage);

        if ($this->linkToLook($slot, $row)) {
            return true; // 0 $: the look already has this stage's image
        }

        $this->awaitLook($slot, $row, ['life_stage' => $stage->value]);

        if ($pet->media_status !== 'ready') {
            $pet->updateQuietly(['media_status' => 'pending', 'media_error' => null]); // a stage change keeps showing the old image
        }

        // Stored between the read and the wait (its fan-out did not see us yet).
        if ($this->linkToLook($slot, $row->refresh())) {
            return true;
        }

        return $this->generateLookImage($row, $look);
    }

    /**
     * Generate a look's reference image of one stage (once: the row claim).
     * An earlier stage's stored image of the same look is EDITED (same
     * animal, older); otherwise text-to-image with the look's seed and
     * traits. No origin cue — the look is shared by bought and adopted pets.
     * The ledger row links the look row (no pet).
     */
    private function generateLookImage(PetMedia $row, PetLook $look): bool
    {
        if (! $this->claim($row, [PetMedia::STATUS_PENDING, PetMedia::STATUS_FAILED], ['source_url' => null])) {
            return true; // another worker generates it; the fan-out links the waiting pets
        }

        $stage = LifeStage::from((string) $row->life_stage);
        $breedKey = $look->breed_type->value;
        $traits = $look->traits();
        $growFrom = PetMedia::query()->where('pet_look_id', $look->id)->images()
            ->where('status', PetMedia::STATUS_READY)->whereNotNull('storage_path')
            ->whereIn('life_stage', array_map(fn (LifeStage $s) => $s->value, array_slice(LifeStage::ordered(), 0, self::stageRank($stage->value))))
            ->get()
            ->sortByDesc(fn (PetMedia $m) => self::stageRank($m->life_stage))
            ->first();

        try {
            $result = $growFrom !== null && $this->profiles->stageEdit() !== null
                ? $this->fal->editReferenceImage(
                    $this->prompts->stageEditPromptFor($breedKey, $traits, $stage),
                    $this->falFetchUrl($growFrom),
                    $look->seed(),
                    null,
                    $row->id,
                )
                : $this->fal->generateReferenceImage(
                    $this->prompts->stagedImagePrompt($breedKey, $traits, $stage),
                    $look->seed(),
                    null,
                    isset($look->dna['negative_prompt']) ? (string) $look->dna['negative_prompt'] : null,
                    $row->id,
                );
        } catch (AiCallException $e) {
            $this->fail($row, $e->reason, $e->getMessage()); // fails the waiting pets too
            Log::warning('PetMediaService: look image not generated', ['pet_look_id' => $look->id, 'life_stage' => $stage->value, 'reason' => $e->reason->value]);

            return true;
        }

        $this->syncCost($row);

        if ($result === null) {
            $row->update(['status' => PetMedia::STATUS_PENDING]);

            return false;
        }

        $row->update(['source_url' => $result['url'], 'profile' => $result['profile']]);
        StorePetMedia::dispatch($row->id);

        return true;
    }

    /**
     * A pool pet's state video: link the look's video of (state, stage of the
     * pet's current image), or wait for it (and submit it if nobody has).
     */
    private function videoFromLook(Pet $pet, PetMedia $slot): bool
    {
        // M5-T09 / T10: phase clips take the transition path (start / end frame).
        if ($slot->isPoseClip()) {
            return $this->transitionFromLook($pet, $slot);
        }

        $state = $slot->petState();

        if (! $pet->is_active || $state === null || ! $state->appliesTo($pet->speciesValue())) {
            if ($slot->status !== PetMedia::STATUS_READY) {
                $this->fail($slot, null, 'Pet inactive or state not available for the species — not generated.');
            }

            return true;
        }

        $image = $this->imageSlot($pet);

        if ($image->status !== PetMedia::STATUS_READY || $image->look_media_id === null || $image->life_stage === null || $pet->look === null) {
            // queueStateVideos() runs again once the pet's (look) image is stored.
            if ($slot->status !== PetMedia::STATUS_READY) {
                $slot->update(['status' => PetMedia::STATUS_PENDING, 'request_id' => null]);
            }

            return true;
        }

        $stage = LifeStage::from($image->life_stage);
        $row = $this->lookSlot($pet->look, PetMedia::KIND_VIDEO, $state, $stage);

        // M5-T08: this pet wants a loop from the state's key image (start frame only since 2026-10-23).
        if ($this->entitlements->usesStateMedia($pet)) {
            if ($state !== PetStateEnum::Idle && $this->poseImage($pet, $state, $image) === null) {
                // The key image comes first (queueStateVideos); this clip follows once it is stored.
                if ($slot->status !== PetMedia::STATUS_READY) {
                    $slot->update(['status' => PetMedia::STATUS_PENDING, 'request_id' => null]);
                }

                return true;
            }

            // The look's clip was made the pre-M5-T08 way: remake it once as a loop
            // (every pet of the look gets the new file — 0 $ for them).
            // Quality check: a look row waiting for review is never remade by another pet.
            if ($row->recipe === null && ! $row->needsReview()) {
                if ($row->status === PetMedia::STATUS_READY || $row->status === PetMedia::STATUS_FAILED) {
                    $this->resetForNewGeneration($row, bump: $row->isServable() || $row->request_id !== null);
                }

                PetMedia::query()->whereKey($row->id)->where('status', PetMedia::STATUS_PENDING)->whereNull('request_id')
                    ->update(['recipe' => PetMedia::RECIPE_START_ONLY]);
                $row->refresh();
            }
        }

        if ($this->linkToLook($slot, $row, $image->generation)) {
            return true; // 0 $: the look already has this video
        }

        if ($slot->status === PetMedia::STATUS_READY && $slot->look_media_id === $row->id) {
            return true;
        }

        $this->awaitLook($slot, $row, ['source_generation' => $image->generation]);

        if ($this->linkToLook($slot, $row->refresh(), $image->generation)) {
            return true;
        }

        return $this->submitLookVideo($row);
    }

    /**
     * Submit a look's state video to fal (once: the row claim). Same failure
     * handling as a pet slot (submitVideo()); the webhook finds the look row
     * by its request id and StorePetMedia fans the file out.
     */
    private function submitLookVideo(PetMedia $row): bool
    {
        if ($row->status === PetMedia::STATUS_READY) {
            $this->fanOutReady((int) $row->id);

            return true;
        }

        // M5-T08b: state media switched off, or no active state-media pet of the look at this
        // stage — a look transition is not submitted (stays as it is).
        if ($row->isPoseClip() && ($row->look === null || ! $this->lookStateMediaWanted($row->look, (string) $row->life_stage, $row))) {
            return true;
        }

        // Frugal clips (David 2026-10-30): a look's frugal target without media of its own is left
        // to its source while the switch is on (no fal call; the pets show the source).
        if ($row->isPoseClip() && $this->entitlements->slotLeftToSource($row, fresh: true)) {
            // QA #451 B1: release the pet slots waiting for it — they show the source instead.
            $source = MediaEntitlementService::frugalSourceOf((string) $row->kind, (string) $row->state);
            PetMedia::query()->whereKey($row->id)->whereNull('frugal_source')->update(['frugal_source' => $source]);
            PetMedia::query()->where('look_media_id', $row->id)->whereNotNull('pet_id')->orderBy('id')
                ->each(fn (PetMedia $waiting) => $this->markLeftToSource($waiting, (string) $source));

            return true;
        }

        // M5-T08b (QA minor 6): a look clip marked as a loop that no pet can use as a loop any
        // more (switch off, or no active state-media pet of the look at this stage — e.g. the
        // only buyer left) goes back to the pre-M5-T08 clip, so pets from before M5-T08 waiting
        // for it are not stuck behind a key image that will never come.
        if ($row->isVideo() && $row->recipe !== null && $row->request_id === null && $row->look !== null
            && ! $this->lookStateMediaWanted($row->look, (string) $row->life_stage)) {
            PetMedia::query()->whereKey($row->id)->whereIn('status', [PetMedia::STATUS_PENDING, PetMedia::STATUS_FAILED])
                ->whereNull('request_id')->update(['recipe' => null]);
            $row->refresh();
        }

        // Quality check (David 2026-10-23): a look clip that failed the check twice waits for a
        // superadmin (»Needs review«) — another pet of the look never regenerates it (no loop,
        // no spend); the waiting pet slots fail with it (the app's fallback).
        if ($row->needsReview()) {
            $this->fanOutFailure($row);

            return true;
        }

        if ($row->status === PetMedia::STATUS_FAILED
            && ! $this->resetForNewGeneration($row, bump: $row->request_id !== null || $row->isServable())) {
            return true; // another worker moved it meanwhile
        }

        $deadClaimSince = $row->status === PetMedia::STATUS_RUNNING ? $row->updated_at : null;

        if (! $this->claim($row, [PetMedia::STATUS_PENDING], [], requireNoRequest: true)) {
            return true; // submitted / being submitted by another worker
        }

        if ($deadClaimSince !== null && $this->adoptAcceptedRequest($row, $deadClaimSince)) {
            return true;
        }

        $look = $row->look;
        $state = $row->petState();
        $transition = $row->transition();
        $clip = $row->phaseClip();
        $frames = $row->frames();

        if ($look === null || ($frames === null && ($state === null || ! $state->appliesTo($look->breed_type->species())))
            || ($clip !== null && $clip->species() !== $look->breed_type->species())) {
            $this->fail($row, null, 'Look video state not available for the species — not generated.');

            return true;
        }

        $image = $this->lookImageRow($row);

        // M5-R21: also wait while the look image is being regenerated (its old
        // file is still servable) — a video is always made from the CURRENT image.
        if ($image === null || ! $image->isServable() || $image->status !== PetMedia::STATUS_READY) {
            $row->update(['status' => PetMedia::STATUS_PENDING]);

            return true;
        }

        $stage = LifeStage::from((string) $row->life_stage);

        // M5-T08: a transition from pose A to pose B, or a loop from / to the state's key image.
        // M5-T09 / T10: a phase clip from its start pose to its end pose.
        $start = $frames !== null ? $this->lookPoseRow($look, $frames[0], $stage, $image) : null;
        $end = $frames !== null ? $this->lookPoseRow($look, $frames[1], $stage, $image) : null;
        // A row a state-media pet asked for (recipe set) is always made from its key image.
        $pose = $frames === null && $row->recipe !== null ? $this->lookPoseRow($look, $state, $stage, $image) : null;

        if (($frames !== null && ($start === null || $end === null)) || ($frames === null && $row->recipe !== null && $pose === null)) {
            $row->update(['status' => PetMedia::STATUS_PENDING]); // the next pet that needs it re-runs it

            return true;
        }

        try {
            $submitted = match (true) {
                $clip !== null => $this->fal->submitPhaseClipFor(
                    $look->breed_type->value, $look->traits(), $stage, $clip,
                    $this->falFetchUrl($start), $this->falFetchUrl($end), null, $row->id,
                ),
                $transition !== null => $this->fal->submitTransitionFor(
                    $look->breed_type->value, $look->traits(), $stage, $transition,
                    $this->falFetchUrl($start), $this->falFetchUrl($end), null, $row->id,
                ),
                $pose !== null => $this->fal->submitLoopVideoFor(
                    $look->breed_type->value, $look->traits(), $stage, $state, $this->falFetchUrl($pose), null, $row->id,
                    short: $this->entitlements->shortLoop($look->breed_type->species(), $state, $row, fresh: true),
                ),
                default => $this->fal->submitStateVideoFor(
                    $look->breed_type->value, $look->traits(), $stage, $state, $this->falFetchUrl($image), null, $row->id,
                ) + ['recipe' => null],
            };
        } catch (AiCallException $e) {
            $this->syncCost($row);

            if ($e->retryable() && ! $e->outcomeUnknown) {
                $row->update(['status' => PetMedia::STATUS_PENDING]);

                return false;
            }

            $this->fail($row, $e->reason, $e->getMessage());

            return true;
        }

        $row->update([
            'request_id' => $submitted['request_id'],
            'profile' => $submitted['profile'],
            'duration_seconds' => $submitted['duration_seconds'],
            'source_generation' => $image->generation,
            // Fix 2026-10-23: `start_only` for loops / same-pose phase clips, null otherwise.
            'recipe' => $submitted['recipe'],
        ]);
        $this->syncCost($row);

        return true;
    }

    /**
     * M5-T08: the look's stored picture of a state's pose at a stage — the look
     * image for idle, else the look's key image made from that look image
     * generation. Null while it is missing.
     */
    private function lookPoseRow(PetLook $look, PetStateEnum|PetPose|null $state, LifeStage $stage, PetMedia $lookImage): ?PetMedia
    {
        // M5-T09 / T10: null = the look image (idle pose); a PetPose = the look's pose image.
        if ($state === null || $state === PetStateEnum::Idle) {
            return $lookImage;
        }

        $key = PetMedia::query()->where('pet_look_id', $look->id)
            ->where('kind', $state instanceof PetPose ? PetMedia::KIND_POSE : PetMedia::KIND_KEYFRAME)
            ->where('state', $state->value)->where('life_stage', $stage->value)->first();

        return $key !== null && $key->status === PetMedia::STATUS_READY && $key->isServable()
            && (int) $key->source_generation >= $lookImage->generation ? $key : null;
    }

    /**
     * M5-T08: a pool / shared-look pet's key image — link the look's key image
     * of the pet's stage (0 $), or wait for it and generate it once.
     */
    private function keyframeFromLook(Pet $pet, PetMedia $slot): bool
    {
        $image = $this->imageSlot($pet);
        // M5-T09 / T10: a pose image the same way (kind pose).
        $state = $slot->petState() ?? $slot->pose();

        if ($image->status !== PetMedia::STATUS_READY || $image->look_media_id === null || $image->life_stage === null || $pet->look === null || $state === null) {
            if ($slot->status !== PetMedia::STATUS_READY) {
                $slot->update(['status' => PetMedia::STATUS_PENDING]);
            }

            return true; // queueStateVideos() runs again once the image is linked
        }

        $look = $pet->look;
        $row = $this->lookSlot($look, $slot->kind, $state, LifeStage::from($image->life_stage));
        $lookImage = $this->lookImageRow($row);

        // A key image of an older look image generation (admin regenerate) is remade once.
        if ($lookImage !== null && $row->status === PetMedia::STATUS_READY && (int) $row->source_generation < $lookImage->generation) {
            $this->resetForNewGeneration($row, bump: true);
        }

        if ($this->linkToLook($slot, $row, $image->generation)) {
            return true; // 0 $: the look already has this key image
        }

        $this->awaitLook($slot, $row, ['source_generation' => $image->generation]);

        if ($this->linkToLook($slot, $row->refresh(), $image->generation)) {
            return true;
        }

        return $this->generateLookKeyframe($row, $look);
    }

    /**
     * Generate a look's key image of one stage (once: the row claim) as an
     * edit of the look's image of that stage. The ledger row links the look row.
     */
    private function generateLookKeyframe(PetMedia $row, PetLook $look): bool
    {
        $state = $row->petState();
        $pose = $row->pose();
        $image = $this->lookImageRow($row);

        // M5-T08b: switched off, or no active state-media pet of the look at this stage
        // (e.g. a sweep reclaim on a look only older pets use) — the row stays as it is, no fal call.
        if (! $this->lookStateMediaWanted($look, (string) $row->life_stage, $row)) {
            return true;
        }

        if (($state === null && $pose === null) || $image === null || $image->status !== PetMedia::STATUS_READY || ! $image->isServable()) {
            return true; // the waiting pets are re-run once the look image is stored
        }

        if ($pose !== null && $pose->species() !== $look->breed_type->species()) {
            $this->fail($row, null, 'Pose not available for the species — not generated.');

            return true;
        }

        if ($this->profiles->keyframe() === null) {
            $this->fail($row, AiCallFailure::Disabled, 'No key image profile enabled.');

            return true;
        }

        // Fix 2026-10-28: a running row WITH a request id (queued at fal) is never claimed again.
        $deadClaimSince = $row->status === PetMedia::STATUS_RUNNING ? $row->updated_at : null;

        if (! $this->claim($row, [PetMedia::STATUS_PENDING, PetMedia::STATUS_FAILED], [
            'source_url' => null,
            'request_id' => null,
            'status_url' => null,
            'response_url' => null,
            'source_generation' => $image->generation,
        ])) {
            return true; // another worker generates it; the fan-out links the waiting pets
        }

        if ($deadClaimSince !== null && $this->adoptAcceptedRequest($row, $deadClaimSince)) {
            return true;
        }

        return $this->submitPoseStill($row, fn () => $this->fal->submitKeyframe(
            $pose !== null
                ? $this->prompts->posePrompt($look->breed_type->value, $pose, $look->traits(), LifeStage::from((string) $row->life_stage))
                : $this->prompts->keyframePrompt($look->breed_type->value, $state, $look->traits(), LifeStage::from((string) $row->life_stage)),
            $this->falFetchUrl($image),
            $look->seed(),
            null,
            $row->id,
        ), ['pet_look_id' => $look->id]);
    }

    /**
     * M5-T08: a pool / shared-look pet's transition clip — link the look's
     * clip of its stage, or wait for it and submit it once (from the look's poses).
     */
    private function transitionFromLook(Pet $pet, PetMedia $slot): bool
    {
        $frames = $slot->frames();
        $image = $this->imageSlot($pet);

        // M5-T09 / T10: a phase clip from its start pose to its end pose, like a transition.
        $posesReady = $frames !== null
            && $this->frameImage($pet, $frames[0], $image) !== null
            && $this->frameImage($pet, $frames[1], $image) !== null;

        if (! $pet->is_active || ! $posesReady || $image->look_media_id === null || $image->life_stage === null || $pet->look === null) {
            if ($slot->status !== PetMedia::STATUS_READY) {
                $slot->update(['status' => PetMedia::STATUS_PENDING, 'request_id' => null]);
            }

            return true;
        }

        $row = $this->lookSlot($pet->look, $slot->kind, $slot->transition() ?? $slot->phaseClip(), LifeStage::from($image->life_stage));

        if ($this->linkToLook($slot, $row, $image->generation)) {
            return true;
        }

        if ($slot->status === PetMedia::STATUS_READY && $slot->look_media_id === $row->id) {
            return true;
        }

        $this->awaitLook($slot, $row, ['source_generation' => $image->generation]);

        if ($this->linkToLook($slot, $row->refresh(), $image->generation)) {
            return true;
        }

        return $this->submitLookVideo($row);
    }

    /**
     * Mark a pool pet's slot as waiting for a look row (`running`, no request of
     * its own). A stale wait is re-run by the sweep like a dead claim.
     *
     * @param  array<string, mixed>  $extra
     */
    private function awaitLook(PetMedia $slot, PetMedia $row, array $extra = []): void
    {
        PetMedia::query()->whereKey($slot->id)
            ->where('status', '!=', PetMedia::STATUS_READY)
            ->update(array_merge([
                'status' => PetMedia::STATUS_RUNNING,
                'look_media_id' => $row->id,
                'request_id' => null,
                'status_url' => null,
                'response_url' => null,
                'source_url' => null,
                'error_reason' => null,
                'error' => null,
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ], $extra));

        $slot->refresh();
    }

    /**
     * Point a pool pet's slot at a STORED look row (copy of its file path —
     * no new file, no fal call) and tell the apps once. Returns false when the
     * look row has no stored file yet. Idempotent (an already linked slot is
     * left alone, no second broadcast).
     */
    private function linkToLook(PetMedia $slot, PetMedia $row, ?int $sourceGeneration = null): bool
    {
        if ($row->status !== PetMedia::STATUS_READY || ! $row->isServable()) {
            return false;
        }

        $linkedPet = DB::transaction(function () use ($slot, $row, $sourceGeneration): ?Pet {
            $locked = PetMedia::whereKey($slot->id)->lockForUpdate()->first();

            if ($locked === null || $locked->pet_id === null
                || ($locked->status === PetMedia::STATUS_READY && $locked->look_media_id === $row->id && $locked->storage_path === $row->storage_path)) {
                return null;
            }

            $locked->update([
                'status' => PetMedia::STATUS_READY,
                'look_media_id' => $row->id,
                'storage_path' => $row->storage_path,
                'bytes' => $row->bytes,
                'mime' => $row->mime,
                'profile' => $row->profile,
                'duration_seconds' => $row->duration_seconds,
                'life_stage' => $row->life_stage,
                'source_generation' => $locked->isImage() ? null : $sourceGeneration,
                'recipe' => $row->recipe,
                'stored_recipe' => $row->stored_recipe,
                'stored_duration_seconds' => $row->stored_duration_seconds,
                // Frugal clips: the look's own clip — not served from a source any more.
                'frugal_source' => null,
                // Quality check: the look row's verdict (checked once per look row).
                'quality' => $row->quality,
                'quality_reason' => $row->quality_reason,
                'candidate_path' => null,
                'candidate_bytes' => null,
                'candidate_mime' => null,
                'request_id' => null,
                'status_url' => null,
                'response_url' => null,
                'source_url' => null,
                'error_reason' => null,
                'error' => null,
                'completed_at' => now(),
            ]);

            $pet = Pet::find($locked->pet_id);

            if ($pet !== null) {
                if ($locked->isImage()) {
                    $pet->updateQuietly(['media_status' => 'ready', 'media_error' => null]);
                }

                PetUpdated::afterCommit($pet, self::readyEvent($locked));
            }

            return $pet;
        });

        $slot->refresh();

        // M5-T08: a linked key image unlocks its loop / transitions too.
        if ($linkedPet !== null && $slot->isStill()) {
            $this->queueStateVideos($linkedPet->fresh());
        }

        // Legacy reversal: the pet now shows the look's own sleeping_to_idle.
        if ($linkedPet !== null) {
            $this->releaseLegacyReversal($slot);
        }

        // M5-T06: a shared look's file completed the pet's preparation → announce once.
        if ($linkedPet !== null) {
            $this->preparation->settle($linkedPet->id);
        }

        return true;
    }

    /**
     * A look row was stored: link every pet slot waiting for it — and every
     * slot the look row failed earlier (a late successful webhook after the
     * sweep's `timed_out`, QA M4-10 m1).
     */
    private function fanOutReady(int $rowId): void
    {
        $row = PetMedia::find($rowId);

        if ($row === null || $row->status !== PetMedia::STATUS_READY) {
            return;
        }

        PetMedia::query()->where('look_media_id', $row->id)
            ->where(fn ($q) => $q->whereIn('status', [PetMedia::STATUS_RUNNING, PetMedia::STATUS_FAILED])
                // M5-T08: pets still showing the row's previous clip (a loop replaced an older clip).
                ->orWhere(fn ($q) => $q->where('status', PetMedia::STATUS_READY)->whereIn('kind', [PetMedia::KIND_VIDEO, PetMedia::KIND_TRANSITION, PetMedia::KIND_PHASE])
                    ->where('storage_path', '!=', $row->storage_path)))
            ->orderBy('id')
            ->each(function (PetMedia $waiting) use ($row): void {
                if ($waiting->isImage()) {
                    $this->linkToLook($waiting, $row);

                    return;
                }

                $pet = $waiting->pet;
                $image = $pet !== null ? $this->imageSlot($pet) : null;

                if ($image !== null && $image->status === PetMedia::STATUS_READY && $image->life_stage === $row->life_stage) {
                    $this->linkToLook($waiting, $row, $image->generation);

                    return;
                }

                // The pet moved to another stage meanwhile: queueStateVideos() re-queues it from its new image.
                $waiting->update(['status' => PetMedia::STATUS_PENDING, 'look_media_id' => null]);
            });
    }

    /** A look row failed: the pet slots waiting for it fail with the same reason. */
    private function fanOutFailure(PetMedia $row): void
    {
        PetMedia::query()->where('look_media_id', $row->id)->where('status', PetMedia::STATUS_RUNNING)->orderBy('id')
            ->each(function (PetMedia $waiting) use ($row): void {
                $waiting->update([
                    'status' => PetMedia::STATUS_FAILED,
                    'error_reason' => $row->error_reason,
                    'error' => $row->error,
                    'completed_at' => now(),
                ]);

                $pet = $waiting->isImage() ? $waiting->pet : null;

                if ($pet !== null) {
                    $pet->updateQuietly(['media_status' => 'failed', 'media_error' => $row->error_reason ?? AiCallFailure::GenerationFailed->value]);
                    PetUpdated::afterCommit($pet->fresh(), 'reference_image_failed');
                }
            });
    }

    /**
     * planMissing() cost of a pool pet: only look media of the pet's stage
     * that are not stored yet (a stored look row is reused for 0 $).
     *
     * @param  list<string>  $videos
     * @param  list<string>  $keyframes
     * @param  list<string>  $transitions
     * @param  list<string>  $poses
     * @param  list<string>  $phases
     */
    private function lookPlanCostUsd(Pet $pet, string $imageAction, array $videos, array $keyframes = [], array $transitions = [], bool $loops = false, array $poses = [], array $phases = []): float
    {
        return array_sum(array_column($this->lookPlanItems($pet, $imageAction, $videos, $keyframes, $transitions, $loops, $poses, $phases), 'cost_usd'));
    }

    /**
     * M5-T16: what a (union) plan of pets of ONE shared look + life stage still costs at
     * look level — every look item once, however many pets show the look (the claims make
     * one fal call per look row). `$pet` is any pet of the look at that stage; `$plan` has
     * the planMissing() shape (lists merged over the pets).
     *
     * @param  array{image: string, videos: list<string>, keyframes: list<string>, transitions: list<string>, poses: list<string>, phases: list<string>, upgrade: bool}  $plan
     * @return list<array{kind: string, state: string|null, cost_usd: float}>
     */
    public function lookMissingItems(Pet $pet, array $plan): array
    {
        $planned = $plan['upgrade'] ? (clone $pet)->forceFill(['state_media_version' => MediaEntitlementService::CURRENT_MEDIA_VERSION]) : $pet;

        return $this->lookPlanItems($pet, $plan['image'], $plan['videos'], $plan['keyframes'], $plan['transitions'],
            $this->entitlements->usesStateMedia($planned), $plan['poses'], $plan['phases']);
    }

    /**
     * The look items of a plan that the look does not have yet, each with its price.
     *
     * @param  list<string>  $videos
     * @param  list<string>  $keyframes
     * @param  list<string>  $transitions
     * @param  list<string>  $poses
     * @param  list<string>  $phases
     * @return list<array{kind: string, state: string|null, cost_usd: float}>
     */
    private function lookPlanItems(Pet $pet, string $imageAction, array $videos, array $keyframes = [], array $transitions = [], bool $loops = false, array $poses = [], array $phases = []): array
    {
        $look = $pet->look;
        $stage = $pet->life_stage;

        if ($look === null || $stage === null) {
            return [];
        }

        // Stored rows are reused for free; a row being generated right now (claimed,
        // `running`) is already paid by the pet that claimed it (QA M4-10 n1).
        // M5-T08: a stored look clip made the pre-M5-T08 way is remade once as a loop.
        $covered = PetMedia::query()->where('pet_look_id', $look->id)->where('life_stage', $stage->value)
            ->where(fn ($q) => $q->whereIn('status', [PetMedia::STATUS_READY, PetMedia::STATUS_RUNNING])
                // QA #363 M4: a look row in »Needs review« is not re-made (or priced) by a plan.
                ->orWhere('error_reason', AiCallFailure::QualityFailed->value))->get();
        $has = fn (string $kind, ?string $state) => $covered->contains(fn (PetMedia $m) => $m->kind === $kind && $m->state === $state
            && ! ($loops && $kind === PetMedia::KIND_VIDEO && $m->status === PetMedia::STATUS_READY && $m->recipe === null));

        // An image of a later stage is an edit of the look's earlier stage (stageImageCostUsd).
        $growsFromEarlier = PetMedia::query()->where('pet_look_id', $look->id)->images()
            ->where('status', PetMedia::STATUS_READY)
            ->whereIn('life_stage', array_map(fn (LifeStage $s) => $s->value, array_slice(LifeStage::ordered(), 0, self::stageRank($stage->value))))
            ->exists();

        $items = [];
        $add = function (string $kind, ?string $state, float $cost) use (&$items, $has): void {
            if (! $has($kind, $state)) {
                $items[] = ['kind' => $kind, 'state' => $state, 'cost_usd' => $cost];
            }
        };

        if (in_array($imageAction, ['generate', 'stage', 'download'], true)) {
            $add(PetMedia::KIND_IMAGE, null, $growsFromEarlier ? $this->stageImageCostUsd() : $this->profiles->referenceImage()->estimatedCostUsd());
        }

        foreach ($videos as $state) {
            $add(PetMedia::KIND_VIDEO, $state, $this->videoCostUsd(PetStateEnum::from($state), $loops, $pet->speciesValue()));
        }

        foreach ($keyframes as $state) {
            $add(PetMedia::KIND_KEYFRAME, $state, $this->keyframeCostUsd());
        }

        foreach ($transitions as $transition) {
            $add(PetMedia::KIND_TRANSITION, $transition, $this->profiles->clipVideo('transition')->estimatedCostUsd());
        }

        foreach ($poses as $pose) {
            $add(PetMedia::KIND_POSE, $pose, $this->keyframeCostUsd());
        }

        foreach ($phases as $phase) {
            $add(PetMedia::KIND_PHASE, $phase, $this->phaseClipCostUsd(PetPhaseClip::from($phase)));
        }

        return $items;
    }

    // ──────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * Atomically move a slot from one of $from to `running` (+ attempts) — the
     * claim that makes duplicate jobs harmless.
     *
     * @param  list<string>  $from
     * @param  array<string, mixed>  $extra
     */
    private function claim(PetMedia $slot, array $from, array $extra = [], bool $requireNoRequest = false): bool
    {
        $claimed = PetMedia::query()
            ->whereKey($slot->id)
            ->where(fn ($q) => $q->whereIn('status', $from)
                // A worker died mid-call (no fal answer recorded): the slot may be claimed again.
                ->orWhere(fn ($q) => $q->where('status', PetMedia::STATUS_RUNNING)
                    ->whereNull('request_id')
                    ->whereNull('source_url')
                    ->where('updated_at', '<', now()->subMinutes(self::STALE_CLAIM_MINUTES))))
            ->when($requireNoRequest, fn ($q) => $q->whereNull('request_id'))
            ->update(array_merge([
                'status' => PetMedia::STATUS_RUNNING,
                'attempts' => DB::raw('attempts + 1'),
                'error_reason' => null,
                'error' => null,
                'updated_at' => now(),
            ], $extra)) === 1;

        if ($claimed) {
            $slot->refresh();
        }

        return $claimed;
    }

    public function fail(PetMedia $slot, ?AiCallFailure $reason, ?string $message = null): void
    {
        $slot->update([
            'status' => PetMedia::STATUS_FAILED,
            'error_reason' => $reason?->value,
            'error' => $message !== null ? mb_substr($message, 0, 2000) : $reason?->label(),
            'completed_at' => now(),
        ]);

        // M4-10: the pets waiting for this look row fail with it (the daily retry /
        // backfill re-runs them, which re-claims the look row).
        if ($slot->isLookMedia()) {
            $this->fanOutFailure($slot);
        }
    }

    /** PetUpdated event type of a stored / linked file (one per file). */
    private static function readyEvent(PetMedia $slot): string
    {
        return match ($slot->kind) {
            PetMedia::KIND_IMAGE => 'reference_image_ready',
            PetMedia::KIND_KEYFRAME, PetMedia::KIND_POSE => 'keyframe_ready',
            default => 'video_ready',
        };
    }

    /** pet_media.cost_usd = estimated spend that counted for this slot (all generations). */
    public function syncCost(PetMedia $slot): void
    {
        $slot->update(['cost_usd' => round((float) AiSpendLedger::query()->counted()->where('pet_media_id', $slot->id)->sum('cost_usd'), 4)]);
    }
}
