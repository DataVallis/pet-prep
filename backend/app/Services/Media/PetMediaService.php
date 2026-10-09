<?php

namespace App\Services\Media;

use App\Enums\AiCallFailure;
use App\Enums\LifeStage;
use App\Enums\PetStateEnum;
use App\Events\PetUpdated;
use App\Jobs\GeneratePetReferenceImage;
use App\Jobs\RegeneratePetStageMedia;
use App\Jobs\StorePetMedia;
use App\Jobs\SubmitPetStateVideo;
use App\Models\AiSpendLedger;
use App\Models\Pet;
use App\Models\PetLook;
use App\Models\PetMedia;
use App\Models\PetMediaHistory;
use App\Services\FalAiService;
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

    /** A submitted video without a webhook for this long is given up (sweep). */
    public const WEBHOOK_TIMEOUT_MINUTES = 120;

    public function __construct(
        private readonly MediaEntitlementService $entitlements,
        private readonly FalAiService $fal,
        private readonly MediaProfiles $profiles,
        private readonly MediaDownloader $downloader,
        private readonly PetAppearancePrompt $prompts,
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
    public function slot(Pet $pet, string $kind, ?PetStateEnum $state): PetMedia
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
     */
    public function queueStateVideos(Pet $pet, bool $includeFailed = false): int
    {
        $image = $this->imageSlot($pet);

        // Videos only for a BORN pet (first contract signed — orchestrator decision 2026-10-05,
        // waiting for David): an unborn pet whose child never signs costs only the image.
        if (! $this->fal->isEnabled() || $image->status !== PetMedia::STATUS_READY || ! $pet->is_active || $pet->isUnborn()) {
            return 0;
        }

        $queued = 0;

        foreach ($this->entitlements->videoStatesFor($pet) as $state) {
            $slot = $this->slot($pet, PetMedia::KIND_VIDEO, $state);

            $neverSubmitted = $slot->status === PetMedia::STATUS_PENDING && $slot->request_id === null;
            $stale = ! $slot->isInFlight() && (int) $slot->source_generation < $image->generation;
            $retry = $includeFailed && $slot->status === PetMedia::STATUS_FAILED;

            if (! $neverSubmitted && ! $stale && ! $retry) {
                continue;
            }

            if (! $neverSubmitted && ! $this->resetForNewGeneration($slot, bump: $slot->isServable() || $slot->request_id !== null)) {
                continue; // another worker moved it meanwhile (PR #24 review m7)
            }

            SubmitPetStateVideo::dispatch($slot->id);
            $queued++;
        }

        return $queued;
    }

    /**
     * Submit one video slot to fal (SubmitPetStateVideo). Returns false when
     * the queue should retry (transient failure before fal accepted it).
     */
    public function submitVideo(int $slotId): bool
    {
        $slot = PetMedia::find($slotId);

        // M4-10: a look row (sweep reclaim) / a pool pet's slot go through the look.
        if ($slot !== null && $slot->isVideo() && $slot->isLookMedia()) {
            return $this->submitLookVideo($slot);
        }

        if ($slot !== null && $slot->isVideo() && $slot->pet?->usesLookPool()) {
            return $this->videoFromLook($slot->pet, $slot);
        }

        // A stale `running` claim = a worker died; its submit may already have been
        // accepted (and paid) by fal before the request id reached the slot.
        $deadClaimSince = $slot?->status === PetMedia::STATUS_RUNNING ? $slot->updated_at : null;

        if ($slot === null || ! $slot->isVideo() || ! $this->claim($slot, [PetMedia::STATUS_PENDING], [], requireNoRequest: true)) {
            return true;
        }

        if ($deadClaimSince !== null && $this->adoptAcceptedRequest($slot, $deadClaimSince)) {
            return true; // no second paid submit (PR #24 review m1)
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

        try {
            $submitted = $this->fal->submitStateVideo($pet, $state, $this->falFetchUrl($image), $slot->id);
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
        $ledger = AiSpendLedger::query()
            ->where('pet_media_id', $slot->id)
            ->where('purpose', 'state_video')
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
        $slot->update([
            'request_id' => $ledger->request_id,
            'profile' => $ledger->profile,
            'source_generation' => $image?->generation,
            'duration_seconds' => (float) $ledger->units,
        ]);
        $this->syncCost($slot);
        Log::warning('PetMediaService: adopted the request id of a dead worker', ['pet_media_id' => $slot->id, 'request_id' => $ledger->request_id]);

        return true;
    }

    /**
     * Signed fal webhook for a pet_media request (FalAiWebhookController, inside
     * its transaction with the slot row locked). Idempotent.
     *
     * @param  array{ok: bool, video_url: string|null, error: string|null, reason?: AiCallFailure}  $result
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

        $slot->update(['source_url' => $result['video_url']]);
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

        $sourceUrl = (string) $slot->source_url;
        $image = $slot->isImage();

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

        $path = $slot->isLookMedia()
            // M4-10: one file per look, stage and generation — shared by every pet of the look.
            ? sprintf('looks/%d/%s-%s-g%d.%s', $slot->pet_look_id, $image ? 'reference' : $slot->state, $slot->life_stage, $slot->generation, MediaDownloader::extensionFor($file['mime']))
            : sprintf('%d/%s-g%d.%s', $slot->pet_id, $image ? 'reference' : $slot->state, $slot->generation, MediaDownloader::extensionFor($file['mime']));

        try {
            $this->disk()->putFileAs(dirname($path), new File($file['path']), basename($path));
        } finally {
            @unlink($file['path']);
        }

        $previous = null;
        $stored = DB::transaction(function () use ($slot, $sourceUrl, $path, $file, &$previous): bool {
            $locked = PetMedia::whereKey($slot->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== PetMedia::STATUS_RUNNING || $locked->source_url !== $sourceUrl) {
                return false; // regenerated / finished meanwhile
            }

            $previous = $locked->storage_path;
            $locked->update([
                'status' => PetMedia::STATUS_READY,
                'storage_path' => $path,
                'bytes' => $file['bytes'],
                'mime' => $file['mime'],
                'error_reason' => null,
                'error' => null,
                'completed_at' => now(),
            ]);

            $pet = Pet::find($locked->pet_id);

            if ($pet !== null) {
                if ($locked->isImage()) {
                    $pet->updateQuietly(['media_status' => 'ready', 'media_error' => null]);
                }

                PetUpdated::afterCommit($pet, $locked->isImage() ? 'reference_image_ready' : 'video_ready');
            }

            return true;
        });

        if (! $stored) {
            if ($path !== $previous) {
                $this->disk()->delete($path);
            }

            // The pet was deleted while we downloaded (M2-08): its directory may
            // already be gone or hold only this file — leave nothing behind.
            if ($slot->pet_id !== null && ! Pet::whereKey($slot->pet_id)->exists()) {
                $this->deleteFilesOf($slot->pet_id);
            }

            return true;
        }

        // M4-10: a look file is never replaced (pets of the look point at it);
        // the waiting pets get the new file.
        if ($slot->isLookMedia()) {
            $this->fanOutReady((int) $slot->id);

            return true;
        }

        // An image archived at a life-stage change (M5-R01) stays on the disk
        // (pet_media_history, growth album); anything else is replaced.
        if ($previous !== null && $previous !== $path && ! PetMediaHistory::where('storage_path', $previous)->exists()) {
            $this->disk()->delete($previous);
        }

        if ($image && ($pet = Pet::find($slot->pet_id)) !== null) {
            $this->queueStateVideos($pet);
        }

        return true;
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

        SubmitPetStateVideo::dispatch($slot->id);

        return true;
    }

    /**
     * Put a finished slot (ready / failed) back to `pending` for a new generation —
     * conditional (`WHERE status NOT IN (pending, running)`), so a slot another
     * worker just claimed is never reset under it (PR #24 review m7).
     */
    public function resetForNewGeneration(PetMedia $slot, bool $bump): bool
    {
        $reset = PetMedia::query()
            ->whereKey($slot->id)
            ->whereNotIn('status', [PetMedia::STATUS_PENDING, PetMedia::STATUS_RUNNING])
            ->update([
                'status' => PetMedia::STATUS_PENDING,
                'generation' => DB::raw('generation + '.($bump ? 1 : 0)),
                'request_id' => null,
                'source_url' => null,
                'error_reason' => null,
                'error' => null,
                'updated_at' => now(),
            ]) === 1;

        if ($reset) {
            $slot->refresh();
        }

        return $reset;
    }

    /**
     * What generating the missing media of a pet would do (media:backfill, Filament).
     *
     * `stage` = the stored image shows an earlier life stage (M5-R01): the
     * stage transition is re-queued (videos follow the new image).
     *
     * @return array{image: 'ok'|'stage'|'in_flight'|'download'|'generate', videos: list<string>, cost_usd: float}
     */
    public function planMissing(Pet $pet): array
    {
        $slots = $pet->media()->get();
        $image = $slots->first(fn (PetMedia $m) => $m->isImage());
        $legacyUrl = $pet->pet_dna['reference_image_url'] ?? null;

        $imageAction = match (true) {
            $image !== null && $this->stageImageBehind($pet, $image) => 'stage',
            $image?->status === PetMedia::STATUS_READY => 'ok',
            $image !== null && $image->isInFlight() && ($image->attempts > 0 || $image->source_url !== null) => 'in_flight',
            is_string($legacyUrl) && $this->fal->isAllowedMediaUrl($legacyUrl) && ($image === null || $image->generation === 1) => 'download',
            default => 'generate',
        };

        $currentImageGeneration = $imageAction === 'ok' ? $image->generation : PHP_INT_MAX;
        $videos = [];

        // Unborn pets get no videos yet (they follow the first contract); a
        // stage image re-queues its videos itself once stored.
        foreach ($pet->isUnborn() || $imageAction === 'stage' ? [] : $this->entitlements->videoStatesFor($pet) as $state) {
            $slot = $slots->first(fn (PetMedia $m) => $m->isVideo() && $m->state === $state->value);

            if ($slot === null
                || $slot->status === PetMedia::STATUS_FAILED
                || ($slot->status === PetMedia::STATUS_PENDING && $slot->request_id === null)
                || (! $slot->isInFlight() && (int) $slot->source_generation < $currentImageGeneration)) {
                $videos[] = $state->value;
            }
        }

        if ($pet->usesLookPool()) {
            // M4-10: only what the shared look does not have yet costs anything.
            $cost = $this->lookPlanCostUsd($pet, $imageAction, $videos);
        } else {
            $cost = match ($imageAction) {
                'generate' => $this->profiles->referenceImage()->estimatedCostUsd(),
                'stage' => $this->stageImageCostUsd(),
                default => 0.0,
            } + count($videos) * $this->profiles->stateVideo()->estimatedCostUsd();
        }

        return ['image' => $imageAction, 'videos' => $videos, 'cost_usd' => round($cost, 4)];
    }

    /**
     * Queue whatever is missing for one pet. Videos follow automatically once
     * a new / downloaded image is stored. Returns what was queued.
     *
     * @return array{image: bool, videos: int}
     */
    public function generateMissing(Pet $pet): array
    {
        if (! $this->fal->isEnabled() || ! $pet->is_active) {
            return ['image' => false, 'videos' => 0];
        }

        $plan = $this->planMissing($pet);

        if ($plan['image'] === 'stage') {
            RegeneratePetStageMedia::dispatch($pet->id);

            return ['image' => true, 'videos' => 0];
        }

        if (in_array($plan['image'], ['download', 'generate'], true)) {
            $slot = $this->imageSlot($pet);

            if ($slot->status === PetMedia::STATUS_FAILED) {
                $slot->update(['status' => PetMedia::STATUS_PENDING, 'error_reason' => null, 'error' => null]);
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

            if ($slot?->isServable()) {
                $videos[$state->value] = $this->signedUrl($slot, $expires);
            }
        }

        $currentState = $pet->pet_state instanceof PetStateEnum ? $pet->pet_state->value : (string) $pet->pet_state;

        $status = match (true) {
            $imageUrl !== null => count(array_diff($states, array_keys($videos))) === 0 ? 'ready' : 'partial',
            $image?->status === PetMedia::STATUS_FAILED, $pet->media_status === 'failed' => 'failed',
            $image === null && $pet->media_status === 'disabled' => 'disabled',
            default => 'pending',
        };

        return new PetMediaPayload(
            status: $status,
            referenceImageUrl: $imageUrl,
            videos: $videos,
            currentVideoUrl: $videos[$currentState] ?? $videos[PetStateEnum::Idle->value] ?? null,
            states: array_values($states),
            expiresAt: ($imageUrl !== null || $videos !== []) ? $expires->toIso8601String() : null,
        );
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

    /** Modification time of a file on the pet-media disk (null if missing / unreadable). */
    public function fileModifiedAt(string $path): ?CarbonImmutable
    {
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
     *  - a result URL whose download job got lost → StorePetMedia again;
     *  - a claim of a dead worker → its job again (which adopts an accepted request id);
     *  - stale download temp files are deleted.
     *
     * @return array{timed_out: int, downloads: int, reclaimed: int, temp_files: int}
     */
    public function sweepStale(): array
    {
        $timedOut = 0;
        PetMedia::query()->videos()
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
            ->each(function (PetMedia $slot) use (&$reclaimed): void {
                // M4-10: a look image has no job of its own — the waiting pets' slots
                // (reclaimed here too) run it again; the next pet needing it reclaims it.
                if ($slot->isLookMedia() && $slot->isImage()) {
                    return;
                }
                $slot->isImage() ? GeneratePetReferenceImage::dispatch($slot->pet_id) : SubmitPetStateVideo::dispatch($slot->id);
                $reclaimed++;
            });

        return ['timed_out' => $timedOut, 'downloads' => $downloads, 'reclaimed' => $reclaimed, 'temp_files' => $this->cleanupTempFiles()];
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

    /** Stored files of a deleted pet (rows go with the FK cascade). */
    public function deleteFilesOf(int $petId): void
    {
        $this->disk()->deleteDirectory((string) $petId);
    }

    // ──────────────────────────────────────────────────────────────
    //  Shared looks (M4-10, free-pet pool)
    // ──────────────────────────────────────────────────────────────

    /**
     * The look row of (look, kind, state, stage), created as `pending` if
     * missing (race-safe: insert-or-ignore on pet_media_look_slot_unique).
     */
    public function lookSlot(PetLook $look, string $kind, ?PetStateEnum $state, LifeStage $stage): PetMedia
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

    /** The look's stored image of the stage a look video row is made for (start frame). */
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

        $row = $this->lookSlot($pet->look, PetMedia::KIND_VIDEO, $state, LifeStage::from($image->life_stage));

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

        if ($look === null || $state === null || ! $state->appliesTo($look->breed_type->species())) {
            $this->fail($row, null, 'Look video state not available for the species — not generated.');

            return true;
        }

        $image = $this->lookImageRow($row);

        if ($image === null || ! $image->isServable()) {
            $row->update(['status' => PetMedia::STATUS_PENDING]);

            return true;
        }

        try {
            $submitted = $this->fal->submitStateVideoFor(
                $look->breed_type->value,
                $look->traits(),
                LifeStage::from((string) $row->life_stage),
                $state,
                $this->falFetchUrl($image),
                null,
                $row->id,
            );
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
        ]);
        $this->syncCost($row);

        return true;
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
                'source_generation' => $locked->isVideo() ? $sourceGeneration : null,
                'request_id' => null,
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

                PetUpdated::afterCommit($pet, $locked->isImage() ? 'reference_image_ready' : 'video_ready');
            }

            return $pet;
        });

        $slot->refresh();

        if ($linkedPet !== null && $slot->isImage()) {
            $this->queueStateVideos($linkedPet->fresh());
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
            ->whereIn('status', [PetMedia::STATUS_RUNNING, PetMedia::STATUS_FAILED])->orderBy('id')
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
     */
    private function lookPlanCostUsd(Pet $pet, string $imageAction, array $videos): float
    {
        $look = $pet->look;
        $stage = $pet->life_stage;

        if ($look === null || $stage === null) {
            return 0.0;
        }

        // Stored rows are reused for free; a row being generated right now (claimed,
        // `running`) is already paid by the pet that claimed it (QA M4-10 n1).
        $covered = PetMedia::query()->where('pet_look_id', $look->id)->where('life_stage', $stage->value)
            ->whereIn('status', [PetMedia::STATUS_READY, PetMedia::STATUS_RUNNING])->get();
        $has = fn (string $kind, ?string $state) => $covered->contains(fn (PetMedia $m) => $m->kind === $kind && $m->state === $state);

        // An image of a later stage is an edit of the look's earlier stage (stageImageCostUsd).
        $growsFromEarlier = PetMedia::query()->where('pet_look_id', $look->id)->images()
            ->where('status', PetMedia::STATUS_READY)
            ->whereIn('life_stage', array_map(fn (LifeStage $s) => $s->value, array_slice(LifeStage::ordered(), 0, self::stageRank($stage->value))))
            ->exists();

        $cost = in_array($imageAction, ['generate', 'stage', 'download'], true) && ! $has(PetMedia::KIND_IMAGE, null)
            ? ($growsFromEarlier ? $this->stageImageCostUsd() : $this->profiles->referenceImage()->estimatedCostUsd())
            : 0.0;

        foreach ($videos as $state) {
            if (! $has(PetMedia::KIND_VIDEO, $state)) {
                $cost += $this->profiles->stateVideo()->estimatedCostUsd();
            }
        }

        return $cost;
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

    /** pet_media.cost_usd = estimated spend that counted for this slot (all generations). */
    public function syncCost(PetMedia $slot): void
    {
        $slot->update(['cost_usd' => round((float) AiSpendLedger::query()->counted()->where('pet_media_id', $slot->id)->sum('cost_usd'), 4)]);
    }
}
