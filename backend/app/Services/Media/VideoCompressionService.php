<?php

namespace App\Services\Media;

use App\Models\PetMedia;
use App\Models\PetMediaHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Stored video compression (David 2026-10-30): the AI clips fal.ai delivers (Kling, very
 * likely 1080 × 1920) are stored as delivered and take a lot of disk. Each stored clip FILE
 * is re-encoded ONCE — H.264 High, yuv420p, CRF `media.compression.crf` (21), preset slow,
 * same resolution and frame rate (constant), no audio, `+faststart` — and replaced at the
 * SAME path / key only when
 *   - the result is ≥ `min_saving_percent` (30) smaller,
 *   - SSIM against the original ≥ `min_ssim` (0.98, ffmpeg `ssim` filter),
 *   - the duration is the same (± DURATION_TOLERANCE_SECONDS) and so are the frame count
 *     and the size.
 * Otherwise the original stays and the decision is remembered (`compression_status`
 * rejected) so it is never tried again. A compressed file is never re-encoded (no
 * generation loss): `compression_path` = the storage_path the decision applies to — a new
 * generation (new path) is a candidate again, so the generation / quality-check pipeline
 * is untouched. Same path → signed URLs, derived school clips (`source_path`) and pool pets'
 * rows stay valid; `pet_media.bytes` of every row pointing at the file is updated.
 *
 * One file = the row that OWNS it (a shared-look row or a pet's unique row — never a pool
 * pet's row with `look_media_id`). ffmpeg-derived school clips (`pet_media_derivatives`)
 * are not candidates — they are already CRF 23 / 1280 px. Works on the local disk and on
 * `pet_media_s3` (temp copy in, result written through the disk; never `->path()`).
 */
class VideoCompressionService
{
    public const STATUS_COMPRESSED = 'compressed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_FAILED = 'failed';

    /** Clip kinds whose files are compressed (state videos / loops, transitions, phase clips). */
    public const KINDS = [PetMedia::KIND_VIDEO, PetMedia::KIND_TRANSITION, PetMedia::KIND_PHASE];

    /** A file whose compression FAILED (ffmpeg / disk error) this many times is left alone. */
    public const MAX_ATTEMPTS = 3;

    public const DURATION_TOLERANCE_SECONDS = 0.05;

    /** Suffix of the temp key next to the file while it is replaced (local disk: rename = atomic). */
    public const TEMP_SUFFIX = '.compress-tmp';

    public function __construct(
        private readonly PetMediaService $media,
        private readonly MediaClipAnalyzer $analyzer,
        private readonly MediaStorage $storage,
    ) {}

    // ──────────────────────────────────────────────────────────────
    //  Selection
    // ──────────────────────────────────────────────────────────────

    /**
     * Rows owning a stored, shown MP4 clip that was not compressed / rejected yet (or failed
     * fewer than MAX_ATTEMPTS times on this path), not in flight and without an open candidate.
     *
     * @return Builder<PetMedia>
     */
    public function candidates(?int $petId = null, ?int $lookId = null): Builder
    {
        return PetMedia::query()
            ->whereIn('kind', self::KINDS)
            ->whereNull('look_media_id')
            ->where('status', PetMedia::STATUS_READY)
            ->whereNotNull('storage_path')
            ->whereNull('candidate_path')
            ->whereRaw('lower(storage_path) like ?', ['%.mp4'])
            ->where(fn (Builder $q) => $q->whereNull('quality')->orWhere('quality', '!=', PetMedia::QUALITY_FLAGGED))
            ->where(fn (Builder $q) => $q->whereNull('compression_path')
                ->orWhereColumn('compression_path', '!=', 'storage_path')
                ->orWhere(fn (Builder $q) => $q->where('compression_status', self::STATUS_FAILED)->where('compression_attempts', '<', self::MAX_ATTEMPTS)))
            ->when($petId !== null, fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('pet_id', $petId)
                ->orWhereIn('id', PetMedia::query()->select('look_media_id')->where('pet_id', $petId)->whereNotNull('look_media_id'))))
            ->when($lookId !== null, fn (Builder $q) => $q->where('pet_look_id', $lookId));
    }

    /** The row is a candidate right now (re-checked on the fresh row before any work). */
    public function isCandidate(PetMedia $row): bool
    {
        return in_array($row->kind, self::KINDS, true)
            && $row->look_media_id === null
            && $row->status === PetMedia::STATUS_READY
            && $row->isShown()
            && blank($row->candidate_path)
            && str_ends_with(strtolower((string) $row->storage_path), '.mp4')
            && ($row->compression_path !== $row->storage_path
                || ($row->compression_status === self::STATUS_FAILED && $row->compression_attempts < self::MAX_ATTEMPTS));
    }

    public function available(): bool
    {
        return $this->analyzer->available();
    }

    // ──────────────────────────────────────────────────────────────
    //  Compress one file
    // ──────────────────────────────────────────────────────────────

    /**
     * Compress the file of one owning row. `$replace = false` (`--sample`) encodes to a temp
     * file and only measures — nothing on the disk or in the DB changes.
     *
     * Outcomes: compressed | rejected | failed | would_compress | would_reject (sample) |
     * not_candidate | busy | no_ffmpeg | stale (the row got a new file meanwhile).
     *
     * @return array{outcome: string, id: int, label: string, path: string|null, before: int|null, after: int|null, saving_percent: float|null, ssim: float|null, note: string|null}
     */
    public function compress(int $id, bool $replace = true): array
    {
        $lock = Cache::lock('media-compress:'.$id, 900);
        if (! $lock->get()) {
            return self::result('busy', $id);
        }

        try {
            return $this->compressLocked($id, $replace);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{outcome: string, id: int, label: string, path: string|null, before: int|null, after: int|null, saving_percent: float|null, ssim: float|null, note: string|null}
     */
    private function compressLocked(int $id, bool $replace): array
    {
        $row = PetMedia::find($id);
        if ($row === null || ! $this->isCandidate($row)) {
            return self::result('not_candidate', $id, $row);
        }

        if (! $this->available()) {
            return self::result('no_ffmpeg', $id, $row);
        }

        $path = (string) $row->storage_path;
        $in = null;
        $out = null;
        $tempKey = null;

        try {
            $in = $this->localCopy($path);
            if ($in === null) {
                return $this->failed($row, $replace, 'source file missing on the media disk');
            }

            $before = (int) filesize($in);
            $source = $this->probe($in);
            if ($source === null || $source['frames'] < 2) {
                return $this->failed($row, $replace, 'source not measurable (ffprobe)');
            }

            if ($source['audio']) {
                // Our clips are made without audio; never drop a sound track silently.
                return $this->rejected($row, $replace, $before, null, null, 'source has an audio stream');
            }

            $out = $in.'-compressed.mp4';
            $encode = Process::timeout($this->timeout())->run($this->encodeArgs($in, $out, $source));
            if (! $encode->successful() || ! is_file($out) || filesize($out) === 0) {
                return $this->failed($row, $replace, 'ffmpeg failed: '.mb_substr(trim($encode->errorOutput()), -300));
            }

            $after = (int) filesize($out);
            $made = $this->probe($out);
            $saving = $before > 0 ? round((1 - $after / $before) * 100, 1) : 0.0;

            if ($made === null) {
                return $this->failed($row, $replace, 'result not measurable (ffprobe)');
            }

            $mismatch = match (true) {
                $made['width'] !== $source['width'] || $made['height'] !== $source['height'] => sprintf('size %dx%d ≠ %dx%d', $made['width'], $made['height'], $source['width'], $source['height']),
                $made['frames'] !== $source['frames'] => sprintf('frames %d ≠ %d', $made['frames'], $source['frames']),
                abs($made['duration'] - $source['duration']) > self::DURATION_TOLERANCE_SECONDS => sprintf('duration %.3f s ≠ %.3f s', $made['duration'], $source['duration']),
                default => null,
            };
            if ($mismatch !== null) {
                return $this->rejected($row, $replace, $before, $after, null, $mismatch);
            }

            $ssim = $this->ssim($out, $in);
            if ($ssim === null) {
                return $this->failed($row, $replace, 'SSIM not measurable');
            }

            $minSaving = (float) config('media.compression.min_saving_percent', 30);
            $minSsim = (float) config('media.compression.min_ssim', 0.98);
            $note = sprintf('-%s %%, SSIM %.4f', rtrim(rtrim(number_format($saving, 1, '.', ''), '0'), '.'), $ssim);

            if ($saving < $minSaving) {
                return $this->rejected($row, $replace, $before, $after, $ssim, "saving {$saving} % < {$minSaving} % (SSIM ".sprintf('%.4f', $ssim).')');
            }
            if ($ssim < $minSsim) {
                return $this->rejected($row, $replace, $before, $after, $ssim, sprintf('SSIM %.4f < %.4f (saving %s %%)', $ssim, $minSsim, $saving));
            }

            if (! $replace) {
                return self::result('would_compress', $id, $row, $before, $after, $saving, $ssim, $note);
            }

            // Cheap re-check before touching the disk (the row lock comes after the write).
            if (PetMedia::whereKey($id)->value('storage_path') !== $path) {
                return self::result('stale', $id, $row, $before, $after, $saving, $ssim, 'replaced meanwhile');
            }

            $tempKey = $this->replaceFile($path, $out);

            $stored = DB::transaction(function () use ($id, $path, $before, $after, $note): bool {
                $locked = PetMedia::whereKey($id)->lockForUpdate()->first();
                if ($locked === null || $locked->storage_path !== $path) {
                    return false;
                }

                $locked->forceFill([
                    'bytes' => $after,
                    'original_bytes' => $before,
                    'compressed_at' => now(),
                    'compression_path' => $path,
                    'compression_status' => self::STATUS_COMPRESSED,
                    'compression_attempts' => 0,
                    'compression_note' => $note,
                ])->saveQuietly();

                // Pool pets' rows show the same file (linkToLook copies `bytes`).
                PetMedia::query()->where('look_media_id', $id)->where('storage_path', $path)->update(['bytes' => $after]);

                return true;
            });

            if (! $stored) {
                // The row got a new file while we encoded: the compressed copy at the old path is
                // still a faithful copy of that file; delete it only when nothing references it.
                $this->deleteIfUnreferenced($path);

                return self::result('stale', $id, $row, $before, $after, $saving, $ssim, 'replaced meanwhile');
            }

            Log::info('VideoCompressionService: file compressed', ['pet_media_id' => $id, 'before' => $before, 'after' => $after, 'saving_percent' => $saving, 'ssim' => $ssim]);

            return self::result(self::STATUS_COMPRESSED, $id, $row, $before, $after, $saving, $ssim, $note);
        } catch (Throwable $e) {
            return $this->failed($row, $replace, $e->getMessage());
        } finally {
            foreach ([$in, $out] as $tmp) {
                if ($tmp !== null && is_file($tmp)) {
                    @unlink($tmp);
                }
            }
            if ($tempKey !== null && $this->media->disk()->exists($tempKey)) {
                $this->media->disk()->delete($tempKey);
            }
        }
    }

    /**
     * Put the compressed file at `$path`. Local disk: written next to it under a temp key and
     * renamed over it (atomic — a reader gets the old or the new file, never half of one).
     * Object storage: one PUT of the same key (an object replace is atomic).
     *
     * @return string|null the temp key used (cleaned up by the caller if it is left over)
     */
    private function replaceFile(string $path, string $localFile): ?string
    {
        $disk = $this->media->disk();

        if ($this->storage->usesObjectStorage()) {
            $disk->putFileAs(dirname($path), new File($localFile), basename($path));

            return null;
        }

        $tempKey = $path.self::TEMP_SUFFIX;
        $disk->putFileAs(dirname($tempKey), new File($localFile), basename($tempKey));
        if (! $disk->move($tempKey, $path)) {
            throw new \RuntimeException('could not move the compressed file into place');
        }

        return $tempKey;
    }

    private function deleteIfUnreferenced(string $path): void
    {
        $referenced = PetMedia::where('storage_path', $path)->orWhere('candidate_path', $path)->exists()
            || PetMediaHistory::where('storage_path', $path)->exists();

        if (! $referenced) {
            $this->media->disk()->delete($path);
        }
    }

    /**
     * @return array{outcome: string, id: int, label: string, path: string|null, before: int|null, after: int|null, saving_percent: float|null, ssim: float|null, note: string|null}
     */
    private function rejected(PetMedia $row, bool $replace, int $before, ?int $after, ?float $ssim, string $why): array
    {
        $saving = $after !== null && $before > 0 ? round((1 - $after / $before) * 100, 1) : null;

        if (! $replace) {
            return self::result('would_reject', (int) $row->id, $row, $before, $after, $saving, $ssim, $why);
        }

        $this->record($row, self::STATUS_REJECTED, $why, 0);
        Log::info('VideoCompressionService: original kept', ['pet_media_id' => $row->id, 'reason' => $why]);

        return self::result(self::STATUS_REJECTED, (int) $row->id, $row, $before, $after, $saving, $ssim, $why);
    }

    /**
     * @return array{outcome: string, id: int, label: string, path: string|null, before: int|null, after: int|null, saving_percent: float|null, ssim: float|null, note: string|null}
     */
    private function failed(PetMedia $row, bool $replace, string $why): array
    {
        $why = mb_substr($why, 0, 450);

        if ($replace) {
            // Failures count per file: a new file (path) starts again at 1.
            $attempts = $row->compression_path === $row->storage_path && $row->compression_status === self::STATUS_FAILED
                ? $row->compression_attempts + 1 : 1;
            $this->record($row, self::STATUS_FAILED, $why, $attempts);
            Log::warning('VideoCompressionService: compression failed', ['pet_media_id' => $row->id, 'attempts' => $attempts, 'error' => $why]);
        }

        return self::result(self::STATUS_FAILED, (int) $row->id, $row, null, null, null, null, $why);
    }

    /** Remember the decision for this file — only while the row still holds it. */
    private function record(PetMedia $row, string $status, string $note, int $attempts): void
    {
        PetMedia::whereKey($row->id)->where('storage_path', $row->storage_path)->update([
            'compression_path' => $row->storage_path,
            'compression_status' => $status,
            'compression_attempts' => $attempts,
            'compression_note' => mb_substr($note, 0, 500),
        ]);
    }

    /**
     * @return array{outcome: string, id: int, label: string, path: string|null, before: int|null, after: int|null, saving_percent: float|null, ssim: float|null, note: string|null}
     */
    private static function result(string $outcome, int $id, ?PetMedia $row = null, ?int $before = null, ?int $after = null, ?float $saving = null, ?float $ssim = null, ?string $note = null): array
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
            'note' => $note,
        ];
    }

    // ──────────────────────────────────────────────────────────────
    //  ffmpeg
    // ──────────────────────────────────────────────────────────────

    /**
     * The encode command (pure — unit-tested): niced, limited threads, H.264 High yuv420p,
     * CRF / preset from config, the source's frame rate as constant rate, same size (no
     * scale filter), video only, faststart.
     *
     * @param  array{rate: string}  $source
     * @return list<string>
     */
    public function encodeArgs(string $in, string $out, array $source): array
    {
        return [
            ...$this->nicePrefix(),
            $this->ffmpeg(), '-v', 'error', '-nostdin', '-y', '-i', $in,
            '-map', '0:v:0', '-an', '-sn', '-dn',
            '-c:v', 'libx264', '-profile:v', 'high', '-pix_fmt', 'yuv420p',
            '-crf', (string) (int) config('media.compression.crf', 21),
            '-preset', (string) config('media.compression.preset', 'slow'),
            '-threads', (string) max(1, (int) config('media.compression.threads', 2)),
            '-fps_mode', 'cfr', '-r', $source['rate'],
            '-movflags', '+faststart',
            $out,
        ];
    }

    /** SSIM (All) of `$distorted` against `$reference`, null when ffmpeg gives none. */
    public function ssim(string $distorted, string $reference): ?float
    {
        $result = Process::timeout($this->timeout())->run([
            ...$this->nicePrefix(),
            $this->ffmpeg(), '-hide_banner', '-nostats', '-nostdin', '-i', $distorted, '-i', $reference,
            '-lavfi', '[0:v][1:v]ssim', '-threads', (string) max(1, (int) config('media.compression.threads', 2)), '-f', 'null', '-',
        ]);
        if (! $result->successful()) {
            return null;
        }

        return self::parseSsim($result->errorOutput().$result->output());
    }

    /** `[Parsed_ssim_0 …] SSIM Y:0.99 U:… All:0.991234 (20.5)` → 0.991234. */
    public static function parseSsim(string $log): ?float
    {
        return preg_match('/SSIM .*All:([0-9.]+)/', $log, $m) === 1 ? (float) $m[1] : null;
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

    /** @return list<string> */
    private function nicePrefix(): array
    {
        $nice = (int) config('media.compression.nice', 19);

        return $nice > 0 ? ['nice', '-n', (string) min(19, $nice)] : [];
    }

    private function ffmpeg(): string
    {
        return (string) config('media.quality_check.ffmpeg', 'ffmpeg');
    }

    private function timeout(): int
    {
        return max(30, (int) config('media.compression.process_timeout_seconds', 300));
    }

    /** A temp copy of a media-disk file (ffmpeg needs a local path; works for `pet_media_s3`). */
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
