<?php

namespace App\Services\Media;

use App\Enums\AiCallFailure;
use App\Enums\PetPhaseClip;
use App\Enums\Species;
use App\Events\PetUpdated;
use App\Jobs\CompressPetMediaCandidate;
use App\Jobs\SubmitPetStateVideo;
use App\Models\AppSettingChange;
use App\Models\Pet;
use App\Models\PetMedia;
use App\Models\PetMediaQualityCheck;
use App\Models\User;
use App\Services\AppSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Automatic quality check of AI clips (David 2026-10-23). Every downloaded clip (state
 * video, transition, school / game phase clip — pet slot or shared look row, checked once
 * per row) is a CANDIDATE (`pet_media.candidate_path`) while the old file stays servable:
 *
 *   StorePetMedia → candidate → CheckPetMediaQuality → checkCandidate():
 *     1. claim: one `pet_media_quality_checks` row per (media, generation, trigger) — a
 *        duplicate job never measures or pays twice;
 *     2. technical check (MediaClipAnalyzer, free, ffmpeg);
 *     3. AI vision check (MediaVisionCheck, production budget) — only when 2 passed;
 *        no budget / profile off / bad answer → the technical result decides and the file
 *        is `passed_technical` (»not AI-checked«);
 *     4. pass → promote (PetMediaService::promoteCandidate: the candidate becomes the
 *        servable file, one broadcast, look fan-out);
 *        fail, first time in this generation chain → ONE automatic regeneration (a new
 *        generation = a new fal request through the normal pipeline + budget guard;
 *        `quality_retry_generation` marks it so it never retries again);
 *        fail of that retry → »Needs review«: slot `failed` (error_reason quality_failed,
 *        the app uses its fallback like for any failed slot), candidate kept for
 *        »Approve anyway« (approve()).
 *   Switch off (/admin → Feature switches »Quality check«) → the candidate is promoted
 *   unchecked at once (the old behaviour).
 *
 * Stored clips: checkStored() (media:quality-check) runs the same checks on a READY
 * file; a failure sets `quality = flagged` (not shown — PetMedia::isShown) and
 * regenerateFlagged() re-queues it once.
 */
class MediaQualityService
{
    /** A `running` check row older than this belongs to a dead worker and may be taken over. */
    public const STALE_CHECK_MINUTES = 10;

    /** PetUpdated event type when a clip is withdrawn by the check (needs review / flagged). */
    public const EVENT_FAILED = 'video_quality_failed';

    /** app_setting_changes key of an »Approve anyway«. */
    public const AUDIT_APPROVE = 'media_quality_approve';

    /** app_setting_changes key of a media:quality-check real run. */
    public const AUDIT_COMMAND = 'media_quality_check';

    public function __construct(
        private readonly PetMediaService $media,
        private readonly MediaClipAnalyzer $analyzer,
        private readonly MediaVisionCheck $vision,
        private readonly VideoCompressionService $compression,
    ) {}

    // ──────────────────────────────────────────────────────────────
    //  Pipeline (CheckPetMediaQuality)
    // ──────────────────────────────────────────────────────────────

    /**
     * Check the slot's candidate and act on the verdict. Idempotent; returns the outcome.
     *
     * @return 'promoted'|'compressing'|'retrying'|'needs_review'|'skipped'|'busy'
     */
    public function checkCandidate(int $slotId): string
    {
        $slot = PetMedia::find($slotId);

        if ($slot === null || $slot->status !== PetMedia::STATUS_RUNNING || blank($slot->candidate_path) || ! $slot->isClip()) {
            return 'skipped';
        }

        $candidate = (string) $slot->candidate_path;

        if (! $this->media->qualityCheckEnabled()) {
            // Switched off (or staged only for compression, David 2026-10-30): served unchecked.
            return $this->release($slot, $candidate, null, null);
        }

        $check = $this->claim($slot, PetMediaQualityCheck::TRIGGER_PIPELINE);

        if ($check === null) {
            return 'busy';
        }

        if ($check->status !== PetMediaQualityCheck::STATUS_DECIDED) {
            $this->runChecks($slot, $check, $candidate, withAi: true);
        }

        return $this->apply($slot->fresh() ?? $slot, $check->fresh() ?? $check, $candidate);
    }

    /**
     * @return 'promoted'|'compressing'|'retrying'|'needs_review'|'skipped'
     */
    private function apply(PetMedia $slot, PetMediaQualityCheck $check, string $candidate): string
    {
        if ($slot->status !== PetMedia::STATUS_RUNNING || $slot->candidate_path !== $candidate) {
            return 'skipped'; // regenerated / decided meanwhile
        }

        if ($check->verdict === PetMediaQualityCheck::VERDICT_PASS) {
            return $this->release($slot, $candidate, self::passQuality($check), $check->reason);
        }

        // QA #363 M1: shadow mode (default until calibrated) — record the would-be verdict, always
        // serve the clip, never regenerate, never hide.
        if ($this->media->qualityCheckMode() === AppSettingsService::QUALITY_SHADOW) {
            Log::warning('MediaQualityService: clip would fail the quality check (shadow mode — served anyway)', ['pet_media_id' => $slot->id, 'reason' => $check->reason]);

            return $this->release($slot, $candidate, PetMedia::QUALITY_SHADOW_FAILED, $check->reason);
        }

        // One automatic regeneration per generation chain (never for the retry itself).
        if ((int) $slot->quality_retry_generation === (int) $slot->generation) {
            return $this->markNeedsReview($slot, $candidate, (string) $check->reason) ? 'needs_review' : 'skipped';
        }

        return $this->autoRetry($slot, $candidate, (string) $check->reason) ? 'retrying' : 'skipped';
    }

    /**
     * Show a candidate that may be shown: compressed first while the video compression switch
     * is on (David 2026-10-30 — children never get the ~14 MB original; the job promotes the
     * compressed key or, on reject / failure, the original), otherwise promoted at once.
     *
     * @return 'promoted'|'compressing'|'skipped'
     */
    private function release(PetMedia $slot, string $candidate, ?string $quality, ?string $reason): string
    {
        if ($this->compression->wantsCandidate($slot, $candidate)) {
            CompressPetMediaCandidate::dispatch((int) $slot->id, $candidate, $quality, $reason);

            return 'compressing';
        }

        return $this->media->promoteCandidate($slot, $candidate, $quality, $reason) ? 'promoted' : 'skipped';
    }

    /** The `quality` of a passed check. */
    private static function passQuality(PetMediaQualityCheck $check): string
    {
        return match (true) {
            $check->ai_status === PetMediaQualityCheck::AI_PASSED => PetMedia::QUALITY_PASSED,
            $check->technical_ok === null => PetMedia::QUALITY_UNCHECKED,
            default => PetMedia::QUALITY_PASSED_TECHNICAL,
        };
    }

    /**
     * The one automatic regeneration: a NEW generation of the slot (new fal request, normal
     * pipeline and budget guard). The old servable file stays; the failed candidate is deleted.
     */
    private function autoRetry(PetMedia $slot, string $candidate, string $reason): bool
    {
        $retried = DB::transaction(function () use ($slot, $candidate, $reason): bool {
            $locked = PetMedia::whereKey($slot->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== PetMedia::STATUS_RUNNING || $locked->candidate_path !== $candidate) {
                return false;
            }

            $next = (int) $locked->generation + 1;
            $locked->update([
                'status' => PetMedia::STATUS_PENDING,
                'generation' => $next,
                'quality_retry_generation' => $next,
                'request_id' => null,
                'source_url' => null,
                'candidate_path' => null,
                'candidate_bytes' => null,
                'candidate_mime' => null,
                'error_reason' => null,
                'error' => mb_substr('Quality check failed once, regenerating automatically: '.$reason, 0, 2000),
            ]);

            return true;
        });

        if (! $retried) {
            return false;
        }

        if ($candidate !== $slot->storage_path) {
            $this->media->disk()->delete($candidate);
        }

        SubmitPetStateVideo::dispatch((int) $slot->id);
        Log::warning('MediaQualityService: clip failed the quality check — one automatic regeneration', ['pet_media_id' => $slot->id, 'reason' => $reason]);

        return true;
    }

    /** Second failure: not shown (the slot fails like any failed slot), candidate kept for review. */
    private function markNeedsReview(PetMedia $slot, string $candidate, string $reason): bool
    {
        $marked = DB::transaction(function () use ($slot, $candidate, $reason): bool {
            $locked = PetMedia::whereKey($slot->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== PetMedia::STATUS_RUNNING || $locked->candidate_path !== $candidate) {
                return false;
            }

            // fail(): status failed + quality_failed; look rows fail the pets waiting for them.
            $this->media->fail($locked, AiCallFailure::QualityFailed, 'Quality check failed twice — needs review: '.$reason);

            return true;
        });

        if ($marked) {
            // Readiness may change (a game falls back to its older clips): tell the apps once.
            $this->broadcastFor($slot, self::EVENT_FAILED);
            Log::warning('MediaQualityService: clip failed the quality check twice — needs review', ['pet_media_id' => $slot->id, 'reason' => $reason]);
        }

        return $marked;
    }

    // ──────────────────────────────────────────────────────────────
    //  Admin: »Approve anyway«
    // ──────────────────────────────────────────────────────────────

    /**
     * Superadmin »Approve anyway«: a »Needs review« candidate becomes the servable file, or
     * a flagged stored clip is shown again. Audited (app_setting_changes `media_quality_approve`).
     */
    public function approve(PetMedia $slot, User $actor): bool
    {
        $slot->refresh();

        // A pool / shared-look pet's slot failed with its look row: approve the look row
        // (checked once per look row; every pet of the look gets the file through the fan-out).
        if (! $slot->needsReview() && ! $slot->isFlaggedQuality() && $slot->look_media_id !== null
            && ($row = PetMedia::find($slot->look_media_id)) !== null && ($row->needsReview() || $row->isFlaggedQuality())) {
            return $this->approve($row, $actor);
        }

        $approved = false;
        $what = null;
        $before = null;

        if ($slot->needsReview()) {
            $approved = $this->media->promoteCandidate($slot, (string) $slot->candidate_path, PetMedia::QUALITY_APPROVED,
                "Approved by superadmin #{$actor->id}", [PetMedia::STATUS_FAILED]);
            $what = 'candidate';
        } elseif ($slot->isFlaggedQuality() && $slot->isServable()) {
            $before = $slot->quality;
            $approved = PetMedia::whereKey($slot->id)->where('quality', $before)
                ->update(['quality' => PetMedia::QUALITY_APPROVED, 'quality_reason' => "Approved by superadmin #{$actor->id}"]) === 1;
            if ($approved) {
                $this->propagateQuality($slot->fresh() ?? $slot);
                $this->broadcastFor($slot, 'video_ready');
            }
            $what = 'flagged';
        }

        if ($approved) {
            AppSettingChange::create([
                'key' => self::AUDIT_APPROVE,
                'user_id' => $actor->id,
                'old' => ['quality' => $what === 'candidate' ? 'needs_review' : ($before ?? PetMedia::QUALITY_FLAGGED), 'reason' => $slot->error ?? $slot->quality_reason],
                'new' => ['pet_media_id' => $slot->id, 'pet_id' => $slot->pet_id, 'pet_look_id' => $slot->pet_look_id, 'kind' => $slot->kind, 'state' => $slot->state, 'generation' => $slot->generation, 'quality' => PetMedia::QUALITY_APPROVED],
            ]);
        }

        return $approved;
    }

    // ──────────────────────────────────────────────────────────────
    //  Stored clips (media:quality-check)
    // ──────────────────────────────────────────────────────────────

    /**
     * Check one READY stored clip. Dry run = technical only, nothing written, no paid call.
     * Real run = technical + AI; a failure flags the file (`quality = flagged`, not shown).
     *
     * @return array{ok: bool|null, technical_ok: bool|null, ai_status: string, reason: string|null, metrics: array<string, mixed>, cost_usd: float}
     */
    public function checkStored(PetMedia $row, bool $dryRun): array
    {
        $path = (string) $row->storage_path;

        if ($dryRun) {
            $tech = $this->technical($row, $path, stored: true);

            return ['ok' => $tech['ok'], 'technical_ok' => $tech['ok'], 'ai_status' => PetMediaQualityCheck::AI_NOT_RUN, 'reason' => $tech['reason'], 'metrics' => $tech['metrics'], 'cost_usd' => 0.0];
        }

        $check = $this->claim($row, PetMediaQualityCheck::TRIGGER_COMMAND);

        if ($check === null) {
            return ['ok' => null, 'technical_ok' => null, 'ai_status' => PetMediaQualityCheck::AI_NOT_RUN, 'reason' => 'busy (another check of this clip is running)', 'metrics' => [], 'cost_usd' => 0.0];
        }

        if ($check->status !== PetMediaQualityCheck::STATUS_DECIDED) {
            $this->runChecks($row, $check, $path, withAi: true);
            $check->refresh();
        }

        $ok = $check->verdict === PetMediaQualityCheck::VERDICT_PASS;
        // QA #363 M1: shadow mode records the would-be verdict, never hides a stored clip.
        $enforce = $this->media->qualityCheckMode() === AppSettingsService::QUALITY_ENFORCE;
        $quality = match (true) {
            ! $ok => $enforce ? PetMedia::QUALITY_FLAGGED : PetMedia::QUALITY_SHADOW_FAILED,
            default => self::passQuality($check),
        };

        // Only the file that was checked; an approved file stays approved.
        $changed = PetMedia::whereKey($row->id)->where('storage_path', $path)
            ->where(fn ($q) => $q->whereNull('quality')->orWhere('quality', '!=', PetMedia::QUALITY_APPROVED))
            ->update(['quality' => $quality, 'quality_reason' => $check->reason]) === 1;

        if ($changed) {
            $this->propagateQuality($row->fresh() ?? $row);
            if (! $ok && $enforce) {
                // The app drops the flagged clip (its fallback) with this state.
                $this->broadcastFor($row, self::EVENT_FAILED);
            }
        }

        return ['ok' => $ok, 'technical_ok' => $check->technical_ok, 'ai_status' => $check->ai_status, 'reason' => $check->reason, 'metrics' => (array) ($check->technical['metrics'] ?? []), 'cost_usd' => (float) $check->ai_cost_usd];
    }

    /**
     * Re-queue a flagged stored clip ONCE through the normal pipeline (the new generation is
     * the chain's automatic retry: a second failure → »Needs review«). A clip that already was
     * an automatic retry is never re-queued again.
     */
    public function regenerateFlagged(PetMedia $row): bool
    {
        $row->refresh();

        if (! $row->isFlaggedQuality() || $row->isInFlight()
            || ($row->quality_retry_generation !== null && (int) $row->quality_retry_generation === (int) $row->generation)) {
            return false;
        }

        if (! $this->media->resetForNewGeneration($row, bump: true)) {
            return false;
        }

        PetMedia::whereKey($row->id)->update(['quality_retry_generation' => $row->generation]);
        SubmitPetStateVideo::dispatch((int) $row->id);

        return true;
    }

    /** Estimated cost of the vision call of one check. */
    public function visionCostUsd(): float
    {
        return $this->vision->costUsd();
    }

    /** Why the vision call would not run now (null = it would). */
    public function visionUnavailable(): ?string
    {
        return $this->vision->unavailableStatus();
    }

    // ──────────────────────────────────────────────────────────────
    //  Checks
    // ──────────────────────────────────────────────────────────────

    /**
     * The claim: one check row per (media, generation, trigger). Null = another worker runs it
     * now. A stale `running` row (dead worker) is taken over.
     */
    private function claim(PetMedia $slot, string $trigger): ?PetMediaQualityCheck
    {
        $inserted = PetMediaQualityCheck::query()->insertOrIgnore([
            'pet_media_id' => $slot->id,
            'generation' => $slot->generation,
            'trigger' => $trigger,
            'status' => PetMediaQualityCheck::STATUS_RUNNING,
            'ai_status' => PetMediaQualityCheck::AI_NOT_RUN,
            'ai_cost_usd' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $check = PetMediaQualityCheck::query()->where('pet_media_id', $slot->id)->where('generation', $slot->generation)->where('trigger', $trigger)->first();

        if ($check === null) {
            return null;
        }

        // Ours (just inserted), or already decided (a duplicate job only applies the verdict).
        if ($inserted === 1 || $check->status === PetMediaQualityCheck::STATUS_DECIDED) {
            return $check;
        }

        // A dead worker's claim is taken over; a live one is left alone.
        $stale = PetMediaQualityCheck::whereKey($check->id)->where('status', PetMediaQualityCheck::STATUS_RUNNING)
            ->where('updated_at', '<', now()->subMinutes(self::STALE_CHECK_MINUTES))
            ->update(['updated_at' => now()]) === 1;

        return $stale ? $check->fresh() : null;
    }

    /** Technical check, then (if it passed) the AI check; writes the decided check row. */
    private function runChecks(PetMedia $slot, PetMediaQualityCheck $check, string $path, bool $withAi): void
    {
        $stored = $check->trigger === PetMediaQualityCheck::TRIGGER_COMMAND;
        $tech = $this->technical($slot, $path, $check, $stored);
        $ai = ['status' => PetMediaQualityCheck::AI_NOT_RUN, 'verdict' => null, 'reason' => null, 'cost_usd' => 0.0, 'reported_cost_usd' => null];

        // QA #363 m2: a takeover of a dead worker never pays for the vision call twice — a call
        // that was in flight (`calling`) or already settled is reused / counted as not AI-checked.
        if ($check->ai_status === PetMediaQualityCheck::AI_CALLING) {
            $ai = ['status' => PetMediaQualityCheck::AI_ERROR, 'verdict' => null, 'reason' => 'the vision call of a dead worker (not repeated)', 'cost_usd' => (float) $check->ai_cost_usd, 'reported_cost_usd' => null];
        } elseif ($check->ai_status !== PetMediaQualityCheck::AI_NOT_RUN) {
            $ai = ['status' => $check->ai_status, 'verdict' => $check->ai_verdict, 'reason' => $check->ai_status === PetMediaQualityCheck::AI_FAILED ? 'AI check: '.($check->ai_verdict['reason'] ?? 'failed the rubric') : null, 'cost_usd' => (float) $check->ai_cost_usd, 'reported_cost_usd' => $check->ai_reported_cost_usd];
        } elseif ($tech['ok'] === true && $withAi) {
            $ai = $this->aiCheck($slot, $tech['sheet'], $check, $stored);
            // Settle the paid part at once (before the decision) — a crash after this never re-calls.
            $check->update(['ai_status' => $ai['status'], 'ai_verdict' => $ai['verdict'], 'ai_cost_usd' => round((float) $ai['cost_usd'], 4), 'ai_reported_cost_usd' => $ai['reported_cost_usd']]);
        }

        $pass = $tech['ok'] !== false && $ai['status'] !== PetMediaQualityCheck::AI_FAILED;
        $reason = match (true) {
            $tech['ok'] === false => 'Technical check: '.$tech['reason'],
            $ai['status'] === PetMediaQualityCheck::AI_FAILED => (string) $ai['reason'],
            $tech['ok'] === null => 'Not checked: '.($tech['reason'] ?? 'ffmpeg / ffprobe not available'),
            $ai['status'] === PetMediaQualityCheck::AI_PASSED => null,
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
            'sheet_path' => $tech['sheet_path'],
            'decided_at' => now(),
        ]);

        // The contact sheet is kept only for a failed clip (admin review); a pass frees the disk.
        if ($pass && $tech['sheet_path'] !== null) {
            $this->media->disk()->delete($tech['sheet_path']);
            $check->update(['sheet_path' => null]);
        }

        // QA #363 m1: a clip that could not be checked at all is worth an error in System health.
        if ($tech['ok'] === null) {
            Log::error('MediaQualityService: clip could not be checked (served unchecked)', ['pet_media_id' => $slot->id, 'generation' => $slot->generation, 'reason' => $reason]);
        }

        Log::info('MediaQualityService: clip checked', ['pet_media_id' => $slot->id, 'generation' => $slot->generation, 'verdict' => $pass ? 'pass' : 'fail', 'ai' => $ai['status'], 'reason' => $reason]);
    }

    /**
     * @return array{ok: bool|null, reason: string|null, failures: list<string>, metrics: array<string, mixed>, sheet: string|null, sheet_path: string|null}
     */
    private function technical(PetMedia $slot, string $path, ?PetMediaQualityCheck $check = null, bool $stored = false): array
    {
        $disk = $this->media->disk();
        $local = $this->localCopy($path);
        $endImage = $this->media->clipEndFrameImage($slot, $stored);
        $endLocal = $endImage !== null ? $this->localCopy((string) $endImage->storage_path) : null;

        try {
            if ($local === null) {
                return ['ok' => false, 'reason' => 'the clip file is missing', 'failures' => ['missing file'], 'metrics' => [], 'sheet' => null, 'sheet_path' => null];
            }

            $measured = $this->analyzer->measure($local, $endLocal);

            if ($measured === null) {
                return ['ok' => null, 'reason' => 'ffmpeg / ffprobe not available', 'failures' => [], 'metrics' => [], 'sheet' => null, 'sheet_path' => null];
            }

            $expected = $stored ? ($slot->stored_duration_seconds ?? $slot->duration_seconds) : $slot->duration_seconds;
            $result = MediaClipAnalyzer::evaluate($measured, $expected, $endImage !== null, MediaClipAnalyzer::isLowMotion($slot));

            if ($result['ok'] === null) {
                return ['ok' => null, 'reason' => 'no video frames could be measured', 'failures' => [], 'metrics' => $result['metrics'], 'sheet' => null, 'sheet_path' => null];
            }
            $sheet = null;
            $sheetPath = null;

            if ($result['ok'] && $check !== null) {
                $base = tempnam(sys_get_temp_dir(), MediaDownloader::TEMP_PREFIX);
                $tmp = $base.'.jpg';
                if ($this->analyzer->contactSheet($local, (float) ($measured['duration'] ?? $slot->duration_seconds ?? 3), $tmp)) {
                    $sheetPath = sprintf('%s/qc-%s-g%d-%s.jpg', dirname($path), $slot->state, $slot->generation, $check->trigger);
                    $disk->put($sheetPath, (string) file_get_contents($tmp));
                    $sheet = $sheetPath;
                }
                @unlink($tmp);
                @unlink($base);
            }

            return ['ok' => $result['ok'], 'reason' => $result['failures'] === [] ? null : implode('; ', $result['failures']), 'failures' => $result['failures'], 'metrics' => $result['metrics'], 'sheet' => $sheet, 'sheet_path' => $sheetPath];
        } catch (Throwable $e) {
            Log::error('MediaQualityService: technical check failed to run', ['pet_media_id' => $slot->id, 'error' => $e->getMessage()]);

            return ['ok' => null, 'reason' => null, 'failures' => [], 'metrics' => ['error' => mb_substr($e->getMessage(), 0, 200)], 'sheet' => null, 'sheet_path' => null];
        } finally {
            foreach ([$local, $endLocal] as $file) {
                if ($file !== null) {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * @return array{status: string, verdict: array<string, mixed>|null, reason: string|null, cost_usd: float, reported_cost_usd: float|null}
     */
    private function aiCheck(PetMedia $slot, ?string $sheetPath, PetMediaQualityCheck $check, bool $stored): array
    {
        $reference = $this->media->clipReferenceImage($slot);

        if ($sheetPath === null || $reference === null) {
            return ['status' => PetMediaQualityCheck::AI_ERROR, 'verdict' => null, 'reason' => 'no contact sheet / reference image', 'cost_usd' => 0.0, 'reported_cost_usd' => null];
        }

        $end = $this->media->clipEndFrameImage($slot, $stored);
        // QA #363 m2: mark the paid call in flight — a stale takeover never repeats it.
        $check->update(['sheet_path' => $sheetPath, 'ai_status' => PetMediaQualityCheck::AI_CALLING]);
        $urls = [$this->sheetUrl($check), $this->media->falFetchUrl($reference)];
        if ($end !== null) {
            $urls[] = $this->media->falFetchUrl($end);
        }

        return $this->vision->judge($slot, $this->speciesOf($slot), $this->intentOf($slot), $urls, $end !== null);
    }

    /** Signed URL fal fetches the contact sheet from (GET /api/media/quality-sheets/{check}). */
    public function sheetUrl(PetMediaQualityCheck $check): string
    {
        $path = URL::temporarySignedRoute('media.quality-sheet', $this->media->urlExpiry(60), ['check' => $check->id], absolute: false);

        return rtrim((string) config('app.url'), '/').$path;
    }

    /** QA #363 M3: superadmin link to a slot's candidate clip (null without one). Short-lived. */
    public function candidateUrl(PetMedia $slot): ?string
    {
        if (blank($slot->candidate_path)) {
            return null;
        }

        $path = URL::temporarySignedRoute('media.candidate', $this->media->urlExpiry(30), ['media' => $slot->id, 'v' => substr(sha1((string) $slot->candidate_path), 0, 10)], absolute: false);

        return rtrim((string) config('app.url'), '/').$path;
    }

    /** QA #363 M3: the latest failed check of the slot that kept its contact sheet. */
    public function latestSheetCheck(PetMedia $slot): ?PetMediaQualityCheck
    {
        return PetMediaQualityCheck::query()->where('pet_media_id', $slot->id)->whereNotNull('sheet_path')->latest('id')->first();
    }

    /** What the clip should show (the prompt's motion) — the rubric's »action_done«. */
    public function intentOf(PetMedia $slot): string
    {
        $species = $this->speciesOf($slot);

        return match (true) {
            ($clip = $slot->phaseClip()) instanceof PetPhaseClip => $clip->motion(),
            ($transition = $slot->transition()) !== null => $transition->motion($species),
            ($state = $slot->petState()) !== null && $state->appliesTo($species) => $state->promptModifier($species),
            default => 'a natural, realistic movement of the animal',
        };
    }

    private function speciesOf(PetMedia $slot): Species
    {
        return $slot->isLookMedia() ? ($slot->look?->breed_type?->species() ?? Species::Dog) : ($slot->pet?->speciesValue() ?? Species::Dog);
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

    /** Shared look rows: copy a stored row's verdict to the pet slots that show the same file. */
    private function propagateQuality(PetMedia $row): void
    {
        if (! $row->isLookMedia()) {
            return;
        }

        PetMedia::query()->where('look_media_id', $row->id)->where('storage_path', $row->storage_path)
            ->update(['quality' => $row->quality, 'quality_reason' => $row->quality_reason]);
    }

    /** A temp copy of a pet-media file (ffmpeg needs a path). */
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
