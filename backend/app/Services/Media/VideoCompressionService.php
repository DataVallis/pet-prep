<?php

namespace App\Services\Media;

use App\Models\PetMedia;
use App\Models\PetMediaDerivative;
use App\Models\PetMediaHistory;
use App\Services\AppSettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\File;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Stored video compression (David 2026-10-30). The AI clips fal.ai delivers are stored as
 * delivered — measured on production: Kling H.264 1076 × 1924, 24 fps, ~23 Mbps (5 s ≈
 * 14–15 MB). Each stored clip FILE is re-encoded ONCE — H.264 High, yuv420p, CRF
 * `media.compression.crf` (21), preset slow, same resolution and frame rate (constant), no
 * audio, `+faststart` — into a NEW key `<name>-c.mp4`, and used only when
 *   - the result is ≥ `min_saving_percent` (30) smaller,
 *   - mean SSIM vs. the original ≥ `min_ssim` (0.98) and every frame ≥ `min_frame_ssim`
 *     (0.95) (ffmpeg `ssim` filter),
 *   - size, frame count and video duration (± DURATION_TOLERANCE_SECONDS) are unchanged,
 *   - the source has no audio stream.
 * Otherwise the original stays and the decision is remembered (`rejected`, never retried).
 *
 * Swap (QA #450 m1/m2): encode + measure in temp files → pre-swap marker on the owner row
 * (`compression_status = replacing` + expected key / size) → write the new key → ONE DB
 * transaction under the owner row lock switches `storage_path` / `bytes` of the owner AND
 * of every pool pet row showing the file, and `pet_media_derivatives.source_path` (derived
 * school clips stay current — never regenerated). The new path changes the signed URL `v`
 * and the app's file-cache name, so players and caches pick up the new file cleanly. The
 * original key is deleted by a later run after `original_grace_minutes` (longer than a
 * signed URL lives), only when no row references it. A marker left by a crash is finished
 * or undone by `recoverInterrupted()` at the start of the next run — never counted as a
 * failure.
 *
 * One file = the row that OWNS it (a shared-look row or a pet's unique row — never a pool
 * pet's row). Never the same file twice: a compressed file (`compression_path =
 * storage_path`, status compressed) is never a candidate again (no generation loss); a new
 * generation (new path) is. ffmpeg-derived school clips (`pet_media_derivatives`) are not
 * candidates. Works on the local disk and on `pet_media_s3` (temp copy in, result written
 * through the disk; never `->path()`). ffmpeg runs niced, with limited threads and an
 * address-space cap (`prlimit --as`, `memory_limit_mb`).
 */
class VideoCompressionService
{
    public const STATUS_COMPRESSED = 'compressed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REPLACING = 'replacing';

    /** Clip kinds whose files are compressed (state videos / loops, transitions, phase clips). */
    public const KINDS = [PetMedia::KIND_VIDEO, PetMedia::KIND_TRANSITION, PetMedia::KIND_PHASE];

    /** A file whose compression FAILED (ffmpeg / disk error) this many times is left alone (`--retry-failed`). */
    public const MAX_ATTEMPTS = 3;

    public const DURATION_TOLERANCE_SECONDS = 0.05;

    /** Suffix of the compressed key: `looks/7/idle-puppy-g1.mp4` → `looks/7/idle-puppy-g1-c.mp4`. */
    public const KEY_SUFFIX = '-c';

    /** Local temp files of this service (swept after TEMP_MAX_AGE_MINUTES). */
    public const TEMP_PREFIX = MediaDownloader::TEMP_PREFIX.'c-';

    public const TEMP_MAX_AGE_MINUTES = 60;

    private ?bool $prlimit = null;

    /** Pipeline run (compressCandidate): `preset` / `timeout` override the backfill config. */
    private array $overrides = [];

    public function __construct(
        protected readonly PetMediaService $media,
        protected readonly MediaClipAnalyzer $analyzer,
        protected readonly MediaStorage $storage,
    ) {}

    // ──────────────────────────────────────────────────────────────
    //  Selection
    // ──────────────────────────────────────────────────────────────

    /**
     * Rows owning a stored, shown MP4 clip that was not compressed / rejected yet (or failed
     * fewer than MAX_ATTEMPTS times on this path — any number with `$retryFailed`), not in
     * flight, without an open candidate and whose file no row has compressed yet.
     *
     * @return Builder<PetMedia>
     */
    public function candidates(?int $petId = null, ?int $lookId = null, bool $retryFailed = false): Builder
    {
        return PetMedia::query()
            ->whereIn('kind', self::KINDS)
            ->whereNull('look_media_id')
            ->where('status', PetMedia::STATUS_READY)
            ->whereNotNull('storage_path')
            ->whereNull('candidate_path')
            ->whereRaw('lower(storage_path) like ?', ['%.mp4'])
            ->whereRaw('lower(storage_path) not like ?', ['%'.self::KEY_SUFFIX.'.mp4'])
            // A pet row showing a look file is a pool row, whatever its look_media_id says.
            ->where(fn (Builder $q) => $q->whereNull('pet_id')->orWhere('storage_path', 'not like', 'looks/%'))
            ->where(fn (Builder $q) => $q->whereNull('quality')->orWhere('quality', '!=', PetMedia::QUALITY_FLAGGED))
            ->where(fn (Builder $q) => $q->whereNull('compression_path')
                ->orWhereColumn('compression_path', '!=', 'storage_path')
                ->orWhere(fn (Builder $q) => $q->where('compression_status', self::STATUS_FAILED)
                    ->when(! $retryFailed, fn (Builder $q) => $q->where('compression_attempts', '<', self::MAX_ATTEMPTS))))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('pet_media as done')
                ->whereColumn('done.storage_path', 'pet_media.storage_path')
                ->whereColumn('done.compression_path', 'done.storage_path')
                ->where('done.compression_status', self::STATUS_COMPRESSED))
            ->when($petId !== null, fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('pet_id', $petId)
                ->orWhereIn('id', PetMedia::query()->select('look_media_id')->where('pet_id', $petId)->whereNotNull('look_media_id'))))
            ->when($lookId !== null, fn (Builder $q) => $q->where('pet_look_id', $lookId));
    }

    /** The row is a candidate right now (re-checked on the fresh row before any work). */
    public function isCandidate(PetMedia $row, bool $retryFailed = false): bool
    {
        return $this->candidates(retryFailed: $retryFailed)->whereKey($row->id)->exists();
    }

    public function available(): bool
    {
        return $this->analyzer->available();
    }

    /** `looks/7/idle-puppy-g1.mp4` → `looks/7/idle-puppy-g1-c.mp4`. */
    public static function targetPath(string $path): string
    {
        $dir = dirname($path);
        $name = pathinfo($path, PATHINFO_FILENAME);

        return ($dir === '.' ? '' : $dir.'/').$name.self::KEY_SUFFIX.'.mp4';
    }

    /** Cache-lock TTL per row: 4 ffmpeg / ffprobe calls at most + margin (QA #450 m6). */
    public function lockSeconds(): int
    {
        return 4 * $this->timeout() + 60;
    }

    // ──────────────────────────────────────────────────────────────
    //  Compress one file
    // ──────────────────────────────────────────────────────────────

    /**
     * Compress the file of one owning row. `$replace = false` (`--sample`) encodes to temp
     * files and only measures — nothing on the disk or in the DB changes. `$recordFailures
     * = false` returns a failure without writing it (the command records it later unless
     * the run stops as a system fault — QA #450 m5).
     *
     * Outcomes: compressed | rejected | failed | would_compress | would_reject (sample) |
     * not_candidate | busy | no_ffmpeg | stale (the row got a new file meanwhile).
     *
     * @return array{outcome: string, id: int, label: string, path: string|null, before: int|null, after: int|null, saving_percent: float|null, ssim: float|null, min_ssim: float|null, note: string|null}
     */
    public function compress(int $id, bool $replace = true, bool $recordFailures = true, bool $retryFailed = false): array
    {
        $lock = Cache::lock('media-compress:'.$id, $this->lockSeconds());
        if (! $lock->get()) {
            return self::result('busy', $id);
        }

        try {
            return $this->compressLocked($id, $replace, $recordFailures, $retryFailed);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{outcome: string, id: int, label: string, path: string|null, before: int|null, after: int|null, saving_percent: float|null, ssim: float|null, min_ssim: float|null, note: string|null}
     */
    private function compressLocked(int $id, bool $replace, bool $recordFailures, bool $retryFailed): array
    {
        $row = PetMedia::find($id);
        if ($row === null || ! $this->isCandidate($row, $retryFailed)) {
            return self::result('not_candidate', $id, $row);
        }

        if (! $this->available()) {
            return self::result('no_ffmpeg', $id, $row);
        }

        $record = $replace && $recordFailures;
        $path = (string) $row->storage_path;
        $target = self::targetPath($path);
        $in = null;
        $out = null;
        $stats = null;
        $marked = false;

        try {
            $in = $this->localCopy($path);
            if ($in === null) {
                return $this->failed($row, $record, 'source file missing on the media disk');
            }

            $m = $this->measure($in);
            $out = $m['out'];
            $stats = $m['stats'];
            [$before, $after, $saving, $mean, $worst, $note] = [(int) $m['before'], (int) $m['after'], (float) $m['saving'], $m['mean'], $m['worst'], (string) $m['note']];
            if ($m['verdict'] === 'fail') {
                return $this->failed($row, $record, (string) $m['reason']);
            }
            if ($m['verdict'] === 'reject') {
                return $this->rejected($row, $replace, $before, $m['after'], $mean, $worst, (string) $m['reason']);
            }

            if (! $replace) {
                return self::result('would_compress', $id, $row, $before, $after, $saving, $mean, $worst, $note);
            }

            // Pre-swap marker (QA #450 m2): only while the row still holds this file.
            $marked = PetMedia::whereKey($id)->where('storage_path', $path)->where('status', PetMedia::STATUS_READY)->whereNull('candidate_path')
                ->update([
                    'compression_status' => self::STATUS_REPLACING,
                    'compression_path' => $path,
                    'compression_target_path' => $target,
                    'compression_target_bytes' => $after,
                    'compression_note' => $note,
                ]) === 1;
            if (! $marked) {
                return self::result('stale', $id, $row, $before, $after, $saving, $mean, $worst, 'replaced meanwhile');
            }

            $this->beforeUpload($row);
            $this->media->disk()->putFileAs(dirname($target), new File($out), basename($target));
            $this->afterUpload($row);

            if (! $this->commitSwap($id, $path, $target, $before, $after, $note)) {
                $this->abandonMarker($id, $path, $target);

                return self::result('stale', $id, $row, $before, $after, $saving, $mean, $worst, 'replaced meanwhile');
            }

            Log::info('VideoCompressionService: file compressed', ['pet_media_id' => $id, 'path' => $target, 'before' => $before, 'after' => $after, 'saving_percent' => $saving, 'ssim' => $mean, 'min_frame_ssim' => $worst]);

            return self::result(self::STATUS_COMPRESSED, $id, $row->fresh() ?? $row, $before, $after, $saving, $mean, $worst, $note);
        } catch (Throwable $e) {
            if ($marked) {
                // The swap did not commit: undo the marker and the new key (never a half state).
                $this->abandonMarker($id, $path, $target);
            }

            return $this->failed($row->fresh() ?? $row, $record, $e->getMessage());
        } finally {
            foreach ([$in, $out, $stats] as $tmp) {
                if ($tmp !== null && is_file($tmp)) {
                    @unlink($tmp);
                }
            }
        }
    }

    /**
     * Encode a local copy and apply the accept rules (shared by the backfill and the pipeline).
     * The caller deletes `out` / `stats` (temp files next to `$in`).
     *
     * @return array{verdict: 'accept'|'reject'|'fail', reason: string|null, before: int, after: int|null, saving: float|null, mean: float|null, worst: float|null, note: string|null, out: string|null, stats: string|null}
     */
    private function measure(string $in): array
    {
        $before = (int) filesize($in);
        $r = ['verdict' => 'fail', 'reason' => null, 'before' => $before, 'after' => null, 'saving' => null, 'mean' => null, 'worst' => null, 'note' => null, 'out' => null, 'stats' => null];

        $source = $this->probe($in);
        if ($source === null || $source['frames'] < 2) {
            return [...$r, 'reason' => 'source not measurable (ffprobe)'];
        }
        if ($source['audio']) {
            // Our clips are made without audio; never drop a sound track silently.
            return [...$r, 'verdict' => 'reject', 'reason' => 'source has an audio stream'];
        }

        $r['out'] = $in.'-out.mp4';
        $encode = Process::timeout($this->timeout())->run($this->encodeArgs($in, $r['out'], $source));
        if (! $encode->successful() || ! is_file($r['out']) || filesize($r['out']) === 0) {
            return [...$r, 'reason' => 'ffmpeg failed: '.mb_substr(trim($encode->errorOutput()), -300)];
        }

        $r['after'] = (int) filesize($r['out']);
        $r['saving'] = $before > 0 ? round((1 - $r['after'] / $before) * 100, 1) : 0.0;
        $made = $this->probe($r['out']);
        if ($made === null) {
            return [...$r, 'reason' => 'result not measurable (ffprobe)'];
        }

        $mismatch = self::mismatch($source, $made);
        if ($mismatch !== null) {
            return [...$r, 'verdict' => 'reject', 'reason' => $mismatch];
        }

        $r['stats'] = $in.'-ssim.log';
        $ssim = $this->ssim($r['out'], $in, $r['stats']);
        if ($ssim === null) {
            return [...$r, 'reason' => 'SSIM not measurable'];
        }
        [$mean, $worst] = $ssim;
        $saving = (float) $r['saving'];
        $r = [...$r, 'mean' => $mean, 'worst' => $worst, 'note' => sprintf('-%s %%, SSIM %.4f (min frame %.4f)', self::number($saving), $mean, $worst)];

        $minSaving = (float) config('media.compression.min_saving_percent', 30);
        $minSsim = (float) config('media.compression.min_ssim', 0.98);
        $minFrame = (float) config('media.compression.min_frame_ssim', 0.95);

        $why = match (true) {
            $saving < $minSaving => sprintf('saving %s %% < %s %% (SSIM %.4f)', self::number($saving), self::number($minSaving), $mean),
            $mean < $minSsim => sprintf('SSIM %.4f < %.4f (saving %s %%)', $mean, $minSsim, self::number($saving)),
            $worst < $minFrame => sprintf('frame SSIM %.4f < %.4f (saving %s %%)', $worst, $minFrame, self::number($saving)),
            default => null,
        };

        return $why === null ? [...$r, 'verdict' => 'accept'] : [...$r, 'verdict' => 'reject', 'reason' => $why];
    }

    // ──────────────────────────────────────────────────────────────
    //  In the generation pipeline (David 2026-10-30: new clips compressed before they are shown)
    // ──────────────────────────────────────────────────────────────

    /**
     * Should this candidate be compressed before it is promoted? Switch on (fresh), a clip,
     * an MP4 candidate not compressed / tried already (a re-dispatch after a failed or
     * rejected try promotes directly — never a loop), ffmpeg there.
     */
    public function wantsCandidate(PetMedia $slot, string $candidate): bool
    {
        $lower = strtolower($candidate);

        return $slot->isClip()
            && str_ends_with($lower, '.mp4') && ! str_ends_with($lower, self::KEY_SUFFIX.'.mp4')
            && $slot->compression_path !== $candidate
            && app(AppSettingsService::class)->videoCompression(fresh: true)
            && $this->available();
    }

    /**
     * Compress a slot's CANDIDATE (the downloaded clip that passed its quality check / was
     * stored with the check off) and promote it — the compressed key when it passes the
     * accept rules, otherwise the original. Never leaves the slot stuck: every outcome
     * except `busy` / `skipped` (decided elsewhere meanwhile) ends in a promotion.
     * Fast preset `media.compression.pipeline_preset` and short process timeouts (the job
     * has 85 s); the original candidate is deleted after the swap — it was never shown.
     *
     * @return 'compressed'|'rejected'|'failed'|'promoted'|'skipped'|'busy'
     */
    public function compressCandidate(int $id, string $candidate, ?string $quality, ?string $reason): string
    {
        $lock = Cache::lock('media-compress:'.$id, $this->lockSeconds());
        if (! $lock->get()) {
            return 'busy';
        }

        $this->overrides = [
            'preset' => (string) config('media.compression.pipeline_preset', 'veryfast'),
            'timeout' => max(5, (int) config('media.compression.pipeline_process_timeout_seconds', 20)),
        ];
        $in = null;
        $m = null;
        $target = null;

        try {
            $slot = PetMedia::find($id);
            if ($slot === null || $slot->status !== PetMedia::STATUS_RUNNING || $slot->candidate_path !== $candidate) {
                return 'skipped';
            }
            if (! $this->wantsCandidate($slot, $candidate)) {
                return $this->promoteOriginal($id, $candidate, $quality, $reason) ? 'promoted' : 'skipped';
            }

            $in = $this->localCopy($candidate);
            $m = $in === null
                ? ['verdict' => 'fail', 'reason' => 'candidate file missing on the media disk', 'out' => null, 'stats' => null]
                : $this->measure($in);

            if ($m['verdict'] !== 'accept') {
                $status = $m['verdict'] === 'reject' ? self::STATUS_REJECTED : self::STATUS_FAILED;
                $this->recordOnCandidate($id, $candidate, $status, (string) $m['reason']);
                Log::info('VideoCompressionService: new clip shown uncompressed', ['pet_media_id' => $id, 'status' => $status, 'reason' => $m['reason']]);

                return $this->promoteOriginal($id, $candidate, $quality, $reason) ? $status : 'skipped';
            }

            $target = self::targetPath($candidate);
            $this->media->disk()->putFileAs(dirname($target), new File((string) $m['out']), basename($target));

            $swapped = DB::transaction(function () use ($id, $candidate, $target, $m): bool {
                $locked = PetMedia::whereKey($id)->lockForUpdate()->first();
                if ($locked === null || $locked->status !== PetMedia::STATUS_RUNNING || $locked->candidate_path !== $candidate) {
                    return false;
                }

                $locked->forceFill([
                    'candidate_path' => $target,
                    'candidate_bytes' => (int) $m['after'],
                    'compression_path' => $target,
                    'compression_status' => self::STATUS_COMPRESSED,
                    'compression_attempts' => 0,
                    'compression_target_path' => null,
                    'compression_target_bytes' => null,
                    'compressed_at' => now(),
                    'original_bytes' => (int) $m['before'],
                    'compression_note' => $m['note'],
                ])->saveQuietly();

                return true;
            });

            if (! $swapped || ! $this->media->promoteCandidate(PetMedia::findOrFail($id), $target, $quality, $reason)) {
                $this->deleteIfUnreferenced($target);

                return 'skipped';
            }

            // The original was only ever a candidate (never served to the apps).
            $this->deleteIfUnreferenced($candidate);
            Log::info('VideoCompressionService: new clip compressed before it was shown', ['pet_media_id' => $id, 'before' => $m['before'], 'after' => $m['after'], 'note' => $m['note']]);

            return self::STATUS_COMPRESSED;
        } catch (Throwable $e) {
            if ($target !== null) {
                $this->deleteIfUnreferenced($target);
            }
            $this->recordOnCandidate($id, $candidate, self::STATUS_FAILED, $e->getMessage());

            return $this->promoteOriginal($id, $candidate, $quality, $reason) ? self::STATUS_FAILED : 'skipped';
        } finally {
            $this->overrides = [];
            foreach ([$in, $m['out'] ?? null, $m['stats'] ?? null] as $tmp) {
                if ($tmp !== null && is_file($tmp)) {
                    @unlink($tmp);
                }
            }
            $lock->release();
        }
    }

    /**
     * Promote the original candidate (rejected / failed / switched off / the job gave up).
     * Also marks a failure on the candidate so a re-dispatch promotes directly; the
     * nightly backfill may try the stored file again later.
     */
    public function promoteOriginal(int $id, string $candidate, ?string $quality, ?string $reason, ?string $failure = null): bool
    {
        if ($failure !== null) {
            $this->recordOnCandidate($id, $candidate, self::STATUS_FAILED, $failure);
        }

        $slot = PetMedia::find($id);

        return $slot !== null && $slot->candidate_path === $candidate && $this->media->promoteCandidate($slot, $candidate, $quality, $reason);
    }

    /** Remember a pipeline decision on the candidate (it becomes the storage_path when promoted). */
    private function recordOnCandidate(int $id, string $candidate, string $status, string $note): void
    {
        PetMedia::whereKey($id)->where('candidate_path', $candidate)->update([
            'compression_path' => $candidate,
            'compression_status' => $status,
            'compression_attempts' => $status === self::STATUS_FAILED ? 1 : 0,
            'compression_target_path' => null,
            'compression_target_bytes' => null,
            'compression_note' => mb_substr($note, 0, 500),
        ]);
    }

    /** Test seam: runs right after the pre-swap marker, before the new key is written. */
    protected function beforeUpload(PetMedia $row): void {}

    /** Test seam: runs after the new key is written, before the DB swap. */
    protected function afterUpload(PetMedia $row): void {}

    /**
     * The swap: one transaction under the owner row lock — owner + pool rows → the new key,
     * derived school clips follow (`source_path`). False when the row no longer holds the
     * marker for this file (replaced / regenerated meanwhile).
     */
    private function commitSwap(int $id, string $old, string $new, int $before, int $after, ?string $note): bool
    {
        return DB::transaction(function () use ($id, $old, $new, $before, $after, $note): bool {
            $locked = PetMedia::whereKey($id)->lockForUpdate()->first();
            if ($locked === null || $locked->storage_path !== $old || $locked->compression_status !== self::STATUS_REPLACING
                || $locked->compression_target_path !== $new || filled($locked->candidate_path)) {
                return false;
            }

            $locked->forceFill([
                'storage_path' => $new,
                'bytes' => $after,
                'original_bytes' => $before,
                'original_path' => $old,
                'compressed_at' => now(),
                'compression_path' => $new,
                'compression_status' => self::STATUS_COMPRESSED,
                'compression_attempts' => 0,
                'compression_target_path' => null,
                'compression_target_bytes' => null,
                'compression_note' => $note,
            ])->saveQuietly();

            // Pool pets' rows show the same file (linkToLook copies storage_path + bytes).
            PetMedia::query()->where('look_media_id', $id)->where('storage_path', $old)->update(['storage_path' => $new, 'bytes' => $after]);
            // School smooth playback: the derived clip belongs to the same picture — keep it current.
            PetMediaDerivative::query()->where('pet_media_id', $id)->where('source_path', $old)->update(['source_path' => $new]);

            return true;
        });
    }

    /** Undo a marker that did not lead to a swap: drop the new key (if nothing uses it) and the marker. */
    private function abandonMarker(int $id, string $old, string $target): void
    {
        $this->deleteIfUnreferenced($target);
        PetMedia::whereKey($id)->where('compression_status', self::STATUS_REPLACING)->where('compression_target_path', $target)->update([
            'compression_status' => null,
            'compression_path' => null,
            'compression_target_path' => null,
            'compression_target_bytes' => null,
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    //  Housekeeping (start of every real run)
    // ──────────────────────────────────────────────────────────────

    /**
     * Finish or undo swaps interrupted after the pre-swap marker (QA #450 m2): the row still
     * holds the original and the new key is on the disk with the expected size → the swap is
     * committed; otherwise the new key is dropped and the file is a candidate again. Never
     * counted as a failure.
     *
     * @return array{completed: int, reset: int, busy: int}
     */
    public function recoverInterrupted(): array
    {
        $counts = ['completed' => 0, 'reset' => 0, 'busy' => 0];

        PetMedia::query()->where('compression_status', self::STATUS_REPLACING)
            ->chunkById(100, function (Collection $rows) use (&$counts): void {
                foreach ($rows as $row) {
                    $lock = Cache::lock('media-compress:'.$row->id, $this->lockSeconds());
                    if (! $lock->get()) {
                        $counts['busy']++;

                        continue;
                    }

                    try {
                        $counts[$this->recoverLocked((int) $row->id)]++;
                    } finally {
                        $lock->release();
                    }
                }
            });

        if ($counts['completed'] + $counts['reset'] > 0) {
            Log::warning('VideoCompressionService: interrupted swaps recovered', $counts);
        }

        return $counts;
    }

    /** @return 'completed'|'reset' */
    private function recoverLocked(int $id): string
    {
        $row = PetMedia::find($id);
        if ($row === null || $row->compression_status !== self::STATUS_REPLACING) {
            return 'reset';
        }

        $old = (string) $row->compression_path;
        $target = (string) $row->compression_target_path;
        $disk = $this->media->disk();

        try {
            if ($target !== '' && $row->storage_path === $old && $disk->exists($target)
                && (int) $disk->size($target) === (int) $row->compression_target_bytes
                && $this->commitSwap($id, $old, $target, (int) $row->bytes, (int) $row->compression_target_bytes, $row->compression_note)) {
                return 'completed';
            }
        } catch (Throwable $e) {
            Log::warning('VideoCompressionService: recovery could not finish the swap', ['pet_media_id' => $id, 'error' => $e->getMessage()]);
        }

        if ($target !== '') {
            $this->abandonMarker($id, $old, $target);
        }

        return 'reset';
    }

    /**
     * Delete originals replaced by a compressed key once the grace period is over and no
     * row references them any more (QA #450 m1). A still referenced original (a pool row
     * linked during the swap, history) stays and is checked again by the next run.
     */
    public function deleteCompressedOriginals(): int
    {
        $deleted = 0;
        $cutoff = now()->subMinutes(max(1, (int) config('media.compression.original_grace_minutes', 180)));

        PetMedia::query()->whereNotNull('original_path')->where('compressed_at', '<=', $cutoff)
            ->chunkById(100, function (Collection $rows) use (&$deleted): void {
                foreach ($rows as $row) {
                    $original = (string) $row->original_path;
                    if ($this->isReferenced($original)) {
                        continue;
                    }

                    $this->media->disk()->delete($original);
                    PetMedia::whereKey($row->id)->where('original_path', $original)->update(['original_path' => null]);
                    $deleted++;
                }
            });

        if ($deleted > 0) {
            Log::info('VideoCompressionService: compressed-away originals deleted', ['files' => $deleted]);
        }

        return $deleted;
    }

    /** Local temp files of this service older than TEMP_MAX_AGE_MINUTES (a run killed mid-encode). */
    public function cleanupTempFiles(int $olderThanMinutes = self::TEMP_MAX_AGE_MINUTES): int
    {
        $deleted = 0;
        $cutoff = now()->subMinutes($olderThanMinutes)->getTimestamp();

        foreach (glob(rtrim(sys_get_temp_dir(), '/').'/'.self::TEMP_PREFIX.'*') ?: [] as $file) {
            if (is_file($file) && (int) @filemtime($file) < $cutoff && @unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /** A media key some row still points at (storage / candidate / history / derived clip). */
    public function isReferenced(string $path): bool
    {
        return PetMedia::where('storage_path', $path)->orWhere('candidate_path', $path)->exists()
            || PetMediaHistory::where('storage_path', $path)->exists()
            || PetMediaDerivative::where('storage_path', $path)->orWhere('source_path', $path)->exists();
    }

    private function deleteIfUnreferenced(string $path): void
    {
        try {
            if (! $this->isReferenced($path)) {
                $this->media->disk()->delete($path);
            }
        } catch (Throwable $e) {
            Log::warning('VideoCompressionService: could not delete an unused key', ['path' => $path, 'error' => $e->getMessage()]);
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  Recording decisions
    // ──────────────────────────────────────────────────────────────

    /**
     * @return array{outcome: string, id: int, label: string, path: string|null, before: int|null, after: int|null, saving_percent: float|null, ssim: float|null, min_ssim: float|null, note: string|null}
     */
    private function rejected(PetMedia $row, bool $replace, int $before, ?int $after, ?float $ssim, ?float $worst, string $why): array
    {
        $saving = $after !== null && $before > 0 ? round((1 - $after / $before) * 100, 1) : null;

        if (! $replace) {
            return self::result('would_reject', (int) $row->id, $row, $before, $after, $saving, $ssim, $worst, $why);
        }

        $this->record((int) $row->id, (string) $row->storage_path, self::STATUS_REJECTED, $why, 0);
        Log::info('VideoCompressionService: original kept', ['pet_media_id' => $row->id, 'reason' => $why]);

        return self::result(self::STATUS_REJECTED, (int) $row->id, $row, $before, $after, $saving, $ssim, $worst, $why);
    }

    /**
     * @return array{outcome: string, id: int, label: string, path: string|null, before: int|null, after: int|null, saving_percent: float|null, ssim: float|null, min_ssim: float|null, note: string|null}
     */
    private function failed(PetMedia $row, bool $record, string $why): array
    {
        $why = mb_substr($why, 0, 450);
        if ($record) {
            $this->recordFailure((int) $row->id, (string) $row->storage_path, $why);
        }

        return self::result(self::STATUS_FAILED, (int) $row->id, $row, null, null, null, null, null, $why);
    }

    /** Count a failure on this file (a new file starts again at 1). */
    public function recordFailure(int $id, string $path, string $why): void
    {
        $row = PetMedia::find($id);
        if ($row === null || $row->storage_path !== $path) {
            return;
        }

        $attempts = $row->compression_path === $path && $row->compression_status === self::STATUS_FAILED ? $row->compression_attempts + 1 : 1;
        $this->record($id, $path, self::STATUS_FAILED, $why, $attempts);
        Log::warning('VideoCompressionService: compression failed', ['pet_media_id' => $id, 'attempts' => $attempts, 'error' => mb_substr($why, 0, 300)]);
    }

    /** Remember the decision for this file — only while the row still holds it. */
    private function record(int $id, string $path, string $status, string $note, int $attempts): void
    {
        PetMedia::whereKey($id)->where('storage_path', $path)->update([
            'compression_path' => $path,
            'compression_status' => $status,
            'compression_attempts' => $attempts,
            'compression_target_path' => null,
            'compression_target_bytes' => null,
            'compression_note' => mb_substr($note, 0, 500),
        ]);
    }

    /**
     * @return array{outcome: string, id: int, label: string, path: string|null, before: int|null, after: int|null, saving_percent: float|null, ssim: float|null, min_ssim: float|null, note: string|null}
     */
    private static function result(string $outcome, int $id, ?PetMedia $row = null, ?int $before = null, ?int $after = null, ?float $saving = null, ?float $ssim = null, ?float $worst = null, ?string $note = null): array
    {
        return [
            'outcome' => $outcome,
            'id' => $id,
            'label' => $row === null ? '#'.$id : sprintf('#%d %s (%s)', $id, $row->label(), $row->pet_look_id !== null ? 'look '.$row->pet_look_id : 'pet '.$row->pet_id),
            'path' => $row?->storage_path,
            'before' => $before,
            'after' => $after,
            'saving_percent' => $saving,
            'ssim' => $ssim,
            'min_ssim' => $worst,
            'note' => $note,
        ];
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }

    // ──────────────────────────────────────────────────────────────
    //  ffmpeg
    // ──────────────────────────────────────────────────────────────

    /**
     * Why the result does not match the source (size, frame count, duration), null when it does.
     *
     * @param  array{frames: int, duration: float, width: int, height: int}  $source
     * @param  array{frames: int, duration: float, width: int, height: int}  $made
     */
    public static function mismatch(array $source, array $made): ?string
    {
        return match (true) {
            $made['width'] !== $source['width'] || $made['height'] !== $source['height'] => sprintf('size %dx%d ≠ %dx%d', $made['width'], $made['height'], $source['width'], $source['height']),
            $made['frames'] !== $source['frames'] => sprintf('frames %d ≠ %d', $made['frames'], $source['frames']),
            abs($made['duration'] - $source['duration']) > self::DURATION_TOLERANCE_SECONDS => sprintf('duration %.3f s ≠ %.3f s', $made['duration'], $source['duration']),
            default => null,
        };
    }

    /**
     * The encode command (pure — unit-tested): niced, memory-capped, limited threads, H.264
     * High yuv420p, CRF / preset from config, the source's frame rate as constant rate, same
     * size (no scale filter), video only, faststart.
     *
     * @param  array{rate: string}  $source
     * @return list<string>
     */
    public function encodeArgs(string $in, string $out, array $source): array
    {
        return [
            ...$this->limits(),
            $this->ffmpeg(), '-v', 'error', '-nostdin', '-y', '-i', $in,
            '-map', '0:v:0', '-an', '-sn', '-dn',
            '-c:v', 'libx264', '-profile:v', 'high', '-pix_fmt', 'yuv420p',
            '-crf', (string) (int) config('media.compression.crf', 21),
            '-preset', (string) ($this->overrides['preset'] ?? config('media.compression.preset', 'slow')),
            '-threads', (string) max(1, (int) config('media.compression.threads', 2)),
            '-fps_mode', 'cfr', '-r', $source['rate'],
            '-movflags', '+faststart',
            $out,
        ];
    }

    /**
     * The SSIM command (pure): one thread, per-frame values into `$statsFile`.
     *
     * @return list<string>
     */
    public function ssimArgs(string $distorted, string $reference, string $statsFile): array
    {
        return [
            ...$this->limits(),
            $this->ffmpeg(), '-hide_banner', '-nostats', '-nostdin', '-i', $distorted, '-i', $reference,
            '-lavfi', '[0:v][1:v]ssim=stats_file='.$statsFile, '-threads', '1', '-f', 'null', '-',
        ];
    }

    /**
     * [mean SSIM (All), worst frame SSIM] of `$distorted` against `$reference`, null when
     * ffmpeg gives none.
     *
     * @return array{0: float, 1: float}|null
     */
    public function ssim(string $distorted, string $reference, string $statsFile): ?array
    {
        $result = Process::timeout($this->timeout())->run($this->ssimArgs($distorted, $reference, $statsFile));
        if (! $result->successful()) {
            return null;
        }

        $mean = self::parseSsim($result->errorOutput().$result->output());
        $worst = is_file($statsFile) ? self::parseSsimStats((string) file_get_contents($statsFile)) : null;

        return $mean === null || $worst === null ? null : [$mean, $worst];
    }

    /** `[Parsed_ssim_0 …] SSIM Y:0.99 U:… All:0.991234 (20.5)` → 0.991234. */
    public static function parseSsim(string $log): ?float
    {
        return preg_match('/SSIM .*All:([0-9.]+)/', $log, $m) === 1 ? (float) $m[1] : null;
    }

    /** The lowest per-frame `All:` of an ssim stats file (`n:1 Y:… All:0.98 (…)` per line). */
    public static function parseSsimStats(string $stats): ?float
    {
        if (preg_match_all('/\bAll:([0-9.]+)/', $stats, $m) < 1) {
            return null;
        }

        return min(array_map('floatval', $m[1]));
    }

    /**
     * Video stream facts of a local clip (ffprobe), null when not measurable.
     *
     * @return array{frames: int, rate: string, duration: float, width: int, height: int, audio: bool}|null
     */
    public function probe(string $path): ?array
    {
        $result = Process::timeout($this->timeout())->run([
            (string) config('media.quality_check.ffprobe', 'ffprobe'), '-v', 'error', '-count_packets',
            '-show_entries', 'stream=codec_type,width,height,r_frame_rate,nb_read_packets,duration:format=duration', '-of', 'json', $path,
        ]);
        if (! $result->successful()) {
            return null;
        }

        $json = json_decode($result->output(), true);
        $streams = is_array($json['streams'] ?? null) ? $json['streams'] : [];
        $video = collect($streams)->firstWhere('codec_type', 'video');
        if (! is_array($video) || (int) ($video['width'] ?? 0) <= 0) {
            return null;
        }

        $rate = (string) ($video['r_frame_rate'] ?? '');
        if (preg_match('#^\d+(/\d+)?$#', $rate) !== 1 || str_starts_with($rate, '0')) {
            return null;
        }

        $duration = (float) ($video['duration'] ?? $json['format']['duration'] ?? 0);
        if ($duration <= 0) {
            return null;
        }

        return [
            'frames' => (int) ($video['nb_read_packets'] ?? 0),
            'rate' => $rate,
            'duration' => $duration,
            'width' => (int) $video['width'],
            'height' => (int) $video['height'],
            'audio' => collect($streams)->contains(fn ($s) => is_array($s) && ($s['codec_type'] ?? null) === 'audio'),
        ];
    }

    /**
     * `nice -n 19 prlimit --as=<bytes>` (each when configured / available).
     *
     * @return list<string>
     */
    private function limits(): array
    {
        $prefix = [];
        $nice = (int) config('media.compression.nice', 19);
        if ($nice > 0) {
            $prefix = ['nice', '-n', (string) min(19, $nice)];
        }

        $mb = (int) config('media.compression.memory_limit_mb', 2048);
        if ($mb > 0 && $this->hasPrlimit()) {
            $prefix = [...$prefix, 'prlimit', '--as='.($mb * 1024 * 1024)];
        }

        return $prefix;
    }

    private function hasPrlimit(): bool
    {
        if ($this->prlimit === null) {
            $this->prlimit = Process::run(['prlimit', '--version'])->successful();
            if (! $this->prlimit) {
                Log::warning('VideoCompressionService: prlimit not available — ffmpeg runs without a memory cap');
            }
        }

        return $this->prlimit;
    }

    private function ffmpeg(): string
    {
        return (string) config('media.quality_check.ffmpeg', 'ffmpeg');
    }

    private function timeout(): int
    {
        if (isset($this->overrides['timeout'])) {
            return (int) $this->overrides['timeout'];
        }

        return max(30, (int) config('media.compression.process_timeout_seconds', 300));
    }

    /** A temp copy of a media-disk file (ffmpeg needs a local path; works for `pet_media_s3`). */
    private function localCopy(string $path): ?string
    {
        $disk = $this->media->disk();
        if ($path === '' || ! $disk->exists($path)) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), self::TEMP_PREFIX);
        if ($tmp === false) {
            throw new RuntimeException('no temp file');
        }
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
