<?php

namespace App\Services\Media;

use App\Enums\PetPhaseClip;
use App\Enums\TrainingCommand;
use App\Models\Pet;
use App\Models\PetMedia;
use App\Models\PetMediaDerivative;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\File;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * School smooth playback (David 2026-10-28, docs/engineering/SCHOOL_SMOOTH_PLAYBACK.md §A):
 * clips DERIVED from a stored school phase clip with ffmpeg on our own server — ZERO AI
 * cost. Never calls fal, never touches AiSpendGuard / ai_spend_ledger.
 *
 *  - `school_<cmd>_attention` → `school_<cmd>_attention_loop`: forward + reversed
 *    (ping-pong), the turn frame and the seam frame not doubled → a seamless loop that
 *    starts / ends on the clip's first frame (the standing reference).
 *  - `school_<cmd>_success` → `school_<cmd>_success_loop`: the same ping-pong (holds the pose).
 *  - `school_<cmd>_performing` → `school_<cmd>_release`: reversed (pose → standing), sped up
 *    so it lasts at most RELEASE_MAX_SECONDS (setpts).
 *
 * Encoding: H.264 High, yuv420p, CRF 23, the source's fps as constant frame rate, no audio,
 * `+faststart`; long side capped at MAX_LONG_SIDE (the `reverse` filter buffers every frame
 * in memory — measured ~370 MB peak RSS for a 5 s 1080 × 1920 source, see ARCHITECTURE).
 *
 * One derived clip per FILE: made from the row that owns the source file (a shared-look row
 * or a pet's unique row — never a pool pet's row pointing at a look), stored next to it on
 * the media disk (local or `pet_media_s3`: the source is copied to a temp file, the result
 * written through the disk). Stale (source storage_path changed) → made again.
 * Triggers: PetMediaService::afterStored (new / replaced / approved source) → the queued
 * GenerateSchoolClipDerivative job; `media:school-derivatives` (scheduled backfill, every
 * 10 min, small batches).
 */
class SchoolClipDerivativeService
{
    public const MAX_LONG_SIDE = 1280;

    public const RELEASE_MAX_SECONDS = 2.5;

    public const CRF = 23;

    /** A source that failed this many times (same file) is left alone by the backfill. */
    public const MAX_ATTEMPTS = 3;

    /** Per ffmpeg / ffprobe call (3 calls stay below the job's 85 s timeout; measured ≤ 2.5 s for 5 s 1080p). */
    public const PROCESS_TIMEOUT_SECONDS = 25;

    /** Defaults for the school schedule when a pet has no stored clip (contract §B). */
    public const DEFAULT_PERFORMING_MS = 3000;

    public const DEFAULT_RELEASE_MS = 2500;

    public const SUFFIX_LOOP = 'loop';

    public const SUFFIX_RELEASE = 'release';

    public function __construct(
        private readonly PetMediaService $media,
        private readonly MediaClipAnalyzer $analyzer,
    ) {}

    // ──────────────────────────────────────────────────────────────
    //  Keys
    // ──────────────────────────────────────────────────────────────

    /** The derived payload key of a school phase clip (null for every other clip). */
    public static function keyFor(?PetPhaseClip $clip): ?string
    {
        if ($clip === null || $clip->group() !== PetPhaseClip::GROUP_SCHOOL) {
            return null;
        }

        return match ($clip->phase()) {
            PetPhaseClip::PHASE_PERFORMING => 'school_'.$clip->command()?->value.'_'.self::SUFFIX_RELEASE,
            default => $clip->value.'_'.self::SUFFIX_LOOP,
        };
    }

    /** @return list<string> the school phase clips derived clips are made from */
    public static function sourceStates(): array
    {
        return array_values(array_map(fn (PetPhaseClip $c) => $c->value, PetPhaseClip::ofGroup(PetPhaseClip::GROUP_SCHOOL)));
    }

    /** @return list<string> every derived key */
    public static function keys(): array
    {
        return array_values(array_map(fn (PetPhaseClip $c) => (string) self::keyFor($c), PetPhaseClip::ofGroup(PetPhaseClip::GROUP_SCHOOL)));
    }

    /** The row owning a slot's file (a pool pet's row → its look row). */
    public static function ownerId(PetMedia $slot): int
    {
        return (int) ($slot->look_media_id ?? $slot->id);
    }

    /** A row derived clips are made from: a shown school phase clip that owns its file. */
    public function isSource(PetMedia $slot): bool
    {
        return $slot->isPhase() && self::keyFor($slot->phaseClip()) !== null
            && $slot->look_media_id === null && $slot->isShown();
    }

    // ──────────────────────────────────────────────────────────────
    //  Backfill selection
    // ──────────────────────────────────────────────────────────────

    /**
     * Source rows whose derived clip is missing or stale (and not given up on).
     *
     * @return Builder<PetMedia>
     */
    public function dueSources(?int $petId = null, ?int $lookId = null): Builder
    {
        return PetMedia::query()
            ->where('kind', PetMedia::KIND_PHASE)
            ->whereIn('state', self::sourceStates())
            ->whereNull('look_media_id')
            ->whereNotNull('storage_path')
            ->where(fn (Builder $q) => $q->whereNull('quality')->orWhere('quality', '!=', PetMedia::QUALITY_FLAGGED))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('pet_media_derivatives as d')
                ->whereColumn('d.pet_media_id', 'pet_media.id')
                ->whereColumn('d.source_path', 'pet_media.storage_path')
                ->where(fn ($q) => $q->where('d.status', PetMediaDerivative::STATUS_READY)->orWhere('d.attempts', '>=', self::MAX_ATTEMPTS)))
            ->when($petId !== null, fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('pet_id', $petId)
                ->orWhereIn('id', PetMedia::query()->select('look_media_id')->where('pet_id', $petId)->whereNotNull('look_media_id'))))
            ->when($lookId !== null, fn (Builder $q) => $q->where('pet_look_id', $lookId))
            ->orderBy('id');
    }

    // ──────────────────────────────────────────────────────────────
    //  Making a derived clip
    // ──────────────────────────────────────────────────────────────

    /**
     * Make (or re-make) the derived clip of one source row. Idempotent: an up-to-date clip
     * is left alone; a parallel run for the same row returns `busy`.
     *
     * @return 'made'|'up_to_date'|'not_source'|'busy'|'no_ffmpeg'|'failed'|'stale'
     */
    public function make(int $sourceId): string
    {
        $lock = Cache::lock('school-derivative:'.$sourceId, 300);
        if (! $lock->get()) {
            return 'busy';
        }

        try {
            return $this->makeLocked($sourceId);
        } finally {
            $lock->release();
        }
    }

    private function makeLocked(int $sourceId): string
    {
        $source = PetMedia::find($sourceId);
        if ($source === null || ! $this->isSource($source)) {
            return 'not_source';
        }

        $key = (string) self::keyFor($source->phaseClip());
        $sourcePath = (string) $source->storage_path;
        $existing = PetMediaDerivative::where('pet_media_id', $source->id)->where('key', $key)->first();
        if ($existing?->isCurrentFor($sourcePath)) {
            return 'up_to_date';
        }

        if (! $this->analyzer->available()) {
            Log::warning('SchoolClipDerivativeService: ffmpeg / ffprobe not available, derived clip not made', ['pet_media_id' => $source->id]);

            return 'no_ffmpeg';
        }

        $release = $source->phaseClip()?->phase() === PetPhaseClip::PHASE_PERFORMING;
        $in = null;
        $out = null;

        try {
            $in = $this->localCopy($sourcePath);
            if ($in === null) {
                return $this->recordFailure($source, $key, $existing, 'source file missing on the media disk');
            }

            $probe = $this->probe($in);
            if ($probe === null || $probe['frames'] < 3) {
                return $this->recordFailure($source, $key, $existing, 'source not measurable (ffprobe)');
            }

            $out = $in.'-derived.mp4';
            $result = Process::timeout(self::PROCESS_TIMEOUT_SECONDS)->run(self::ffmpegArgs(
                (string) config('media.quality_check.ffmpeg', 'ffmpeg'), $in, $out, $probe, $release,
            ));
            if (! $result->successful() || ! is_file($out) || filesize($out) === 0) {
                return $this->recordFailure($source, $key, $existing, 'ffmpeg failed: '.mb_substr(trim($result->errorOutput()), -500));
            }

            $made = $this->probe($out);
            $bytes = (int) filesize($out);
            $path = self::derivedPath($sourcePath, $release ? self::SUFFIX_RELEASE : self::SUFFIX_LOOP);
            $this->media->disk()->putFileAs(dirname($path), new File($out), basename($path));

            $previous = null;
            $stored = DB::transaction(function () use ($source, $key, $sourcePath, $path, $bytes, $made, &$previous): bool {
                $locked = PetMedia::whereKey($source->id)->lockForUpdate()->first();
                if ($locked === null || $locked->storage_path !== $sourcePath) {
                    return false; // replaced meanwhile — its own job makes the new one
                }

                $row = PetMediaDerivative::where('pet_media_id', $source->id)->where('key', $key)->lockForUpdate()->first();
                $previous = $row?->storage_path;
                PetMediaDerivative::updateOrCreate(['pet_media_id' => $source->id, 'key' => $key], [
                    'status' => PetMediaDerivative::STATUS_READY,
                    'source_path' => $sourcePath,
                    'storage_path' => $path,
                    'bytes' => $bytes,
                    'duration_seconds' => $made === null ? null : round($made['duration'], 3),
                    'attempts' => 0,
                    'error' => null,
                ]);

                return true;
            });

            if (! $stored) {
                $this->media->disk()->delete($path);

                return 'stale';
            }

            if ($previous !== null && $previous !== $path) {
                $this->media->disk()->delete($previous);
            }

            Log::info('SchoolClipDerivativeService: derived clip stored', [
                'pet_media_id' => $source->id, 'key' => $key, 'bytes' => $bytes, 'seconds' => $made['duration'] ?? null,
            ]);

            return 'made';
        } catch (Throwable $e) {
            return $this->recordFailure($source, $key, $existing, $e->getMessage());
        } finally {
            foreach ([$in, $out] as $tmp) {
                if ($tmp !== null && is_file($tmp)) {
                    @unlink($tmp);
                }
            }
        }
    }

    /**
     * The ffmpeg command (pure — unit-tested). Long side ≤ MAX_LONG_SIDE (never upscaled),
     * constant frame rate = the source's, H.264 High yuv420p CRF 23, no audio, faststart.
     *
     * @param  array{frames: int, fps: float, duration: float, width: int, height: int}  $probe
     * @return list<string>
     */
    public static function ffmpegArgs(string $ffmpeg, string $in, string $out, array $probe, bool $release): array
    {
        $scale = self::scaleFilter($probe['width'], $probe['height']);
        $fps = $probe['fps'] > 0 ? $probe['fps'] : 24.0;

        if ($release) {
            // Reversed (pose → standing). Sped up to ≤ RELEASE_MAX_SECONDS; two frames of
            // headroom because the CFR muxer may round up by a frame.
            $factor = $probe['duration'] > self::RELEASE_MAX_SECONDS
                ? max(0.1, (self::RELEASE_MAX_SECONDS - 2 / $fps) / $probe['duration'])
                : 1.0;
            $filter = ['-vf', $scale.'reverse,setpts='.sprintf('%.5F', $factor).'*PTS'];
        } else {
            // Ping-pong: frames 0 … N−1, then N−2 … 1 — no doubled turn frame, and the loop
            // restarts on frame 0 without a doubled seam frame.
            $n = $probe['frames'];
            $filter = ['-filter_complex', sprintf(
                '[0:v]%ssplit[f][b];[b]reverse,trim=start_frame=1:end_frame=%d,setpts=PTS-STARTPTS[r];[f][r]concat=n=2:v=1:a=0[v]',
                $scale, $n - 1,
            ), '-map', '[v]'];
        }

        return [
            $ffmpeg, '-v', 'error', '-y', '-i', $in, '-an',
            ...$filter,
            '-r', self::fpsArg($fps),
            '-c:v', 'libx264', '-profile:v', 'high', '-pix_fmt', 'yuv420p',
            '-crf', (string) self::CRF, '-preset', 'veryfast', '-threads', '2',
            '-movflags', '+faststart',
            $out,
        ];
    }

    /** `scale=…,` when the long side exceeds MAX_LONG_SIDE (even dimensions), else ''. */
    public static function scaleFilter(int $width, int $height): string
    {
        if (max($width, $height) <= self::MAX_LONG_SIDE) {
            return '';
        }

        return $width >= $height
            ? 'scale='.self::MAX_LONG_SIDE.':-2,'
            : 'scale=-2:'.self::MAX_LONG_SIDE.',';
    }

    /** `media/1/school_sit_attention-g2.mp4` → `media/1/school_sit_attention-g2-loop.mp4`. */
    public static function derivedPath(string $sourcePath, string $suffix): string
    {
        $dir = dirname($sourcePath);
        $name = pathinfo($sourcePath, PATHINFO_FILENAME);

        return ($dir === '.' ? '' : $dir.'/').$name.'-'.$suffix.'.mp4';
    }

    private static function fpsArg(float $fps): string
    {
        return rtrim(rtrim(sprintf('%.3F', $fps), '0'), '.');
    }

    /**
     * Frames, fps, duration and size of a local clip (ffprobe), null when not measurable.
     *
     * @return array{frames: int, fps: float, duration: float, width: int, height: int}|null
     */
    public function probe(string $path): ?array
    {
        $result = Process::timeout(self::PROCESS_TIMEOUT_SECONDS)->run([
            (string) config('media.quality_check.ffprobe', 'ffprobe'), '-v', 'error', '-select_streams', 'v:0', '-count_packets',
            '-show_entries', 'stream=width,height,r_frame_rate,nb_read_packets:format=duration', '-of', 'json', $path,
        ]);
        if (! $result->successful()) {
            return null;
        }

        $json = json_decode($result->output(), true);
        $stream = $json['streams'][0] ?? null;
        if (! is_array($stream)) {
            return null;
        }

        [$num, $den] = array_pad(array_map('floatval', explode('/', (string) ($stream['r_frame_rate'] ?? '0/1'))), 2, 1.0);
        $fps = $den > 0 ? $num / $den : 0.0;
        $duration = (float) ($json['format']['duration'] ?? 0);

        if ($duration <= 0 || (int) ($stream['width'] ?? 0) <= 0) {
            return null;
        }

        return [
            'frames' => (int) ($stream['nb_read_packets'] ?? 0),
            'fps' => $fps,
            'duration' => $duration,
            'width' => (int) $stream['width'],
            'height' => (int) $stream['height'],
        ];
    }

    private function recordFailure(PetMedia $source, string $key, ?PetMediaDerivative $existing, string $error): string
    {
        $sourcePath = (string) $source->storage_path;
        // Attempts count per source file: a new source file starts again at 1.
        $attempts = $existing !== null && $existing->source_path === $sourcePath && $existing->status === PetMediaDerivative::STATUS_FAILED
            ? $existing->attempts + 1 : 1;
        $previous = $existing?->storage_path;

        PetMediaDerivative::updateOrCreate(['pet_media_id' => $source->id, 'key' => $key], [
            'status' => PetMediaDerivative::STATUS_FAILED,
            'source_path' => $sourcePath,
            'storage_path' => null,
            'bytes' => null,
            'duration_seconds' => null,
            'attempts' => $attempts,
            'error' => mb_substr($error, 0, 1000),
        ]);

        // An old derived file of an earlier source is not served any more.
        if ($previous !== null) {
            $this->media->disk()->delete($previous);
        }

        Log::warning('SchoolClipDerivativeService: derived clip not made', ['pet_media_id' => $source->id, 'key' => $key, 'attempts' => $attempts, 'error' => mb_substr($error, 0, 300)]);

        return 'failed';
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

    // ──────────────────────────────────────────────────────────────
    //  Payload + serving
    // ──────────────────────────────────────────────────────────────

    /**
     * Derived clips of a pet's SHOWN school phase slots: [key => [url, seconds]] (one query).
     *
     * @param  Collection<int, PetMedia>  $slots  the pet's shown school phase slots
     * @return array<string, array{url: string, seconds: float}>
     */
    public function payloadFor(Collection $slots, CarbonImmutable $expires): array
    {
        $sources = $slots->filter(fn (PetMedia $m) => $m->isPhase() && self::keyFor($m->phaseClip()) !== null && $m->isShown());
        if ($sources->isEmpty()) {
            return [];
        }

        $rows = PetMediaDerivative::query()
            ->whereIn('pet_media_id', $sources->map(fn (PetMedia $m) => self::ownerId($m))->unique()->values()->all())
            ->where('status', PetMediaDerivative::STATUS_READY)
            ->get()
            ->groupBy('pet_media_id');

        $out = [];
        foreach ($sources as $slot) {
            $key = (string) self::keyFor($slot->phaseClip());
            $row = ($rows->get(self::ownerId($slot)) ?? collect())->firstWhere('key', $key);
            if ($row instanceof PetMediaDerivative && $row->isCurrentFor($slot->storage_path)) {
                $out[$key] = [
                    'url' => $this->signedUrl($slot, $key, (string) $row->storage_path, $expires),
                    'seconds' => (float) ($row->duration_seconds ?? 0),
                ];
            }
        }

        return $out;
    }

    /** Signed URL to GET /api/media/{media}/derived/{key} (the pet's own slot: its authz). */
    public function signedUrl(PetMedia $slot, string $key, string $storagePath, CarbonImmutable $expires): string
    {
        $path = URL::temporarySignedRoute(
            'media.derived',
            $expires,
            ['media' => $slot->id, 'key' => $key, 'v' => substr(sha1($storagePath), 0, 10)],
            absolute: false,
        );

        return rtrim((string) config('app.url'), '/').$path;
    }

    /** The current derived row a slot may serve under $key (null = 404). */
    public function servable(PetMedia $slot, string $key): ?PetMediaDerivative
    {
        if (! $slot->isShown() || self::keyFor($slot->phaseClip()) !== $key) {
            return null;
        }

        $row = PetMediaDerivative::where('pet_media_id', self::ownerId($slot))->where('key', $key)->first();

        return $row?->isCurrentFor($slot->storage_path) ? $row : null;
    }

    // ──────────────────────────────────────────────────────────────
    //  School schedule timings (TrainingService, contract §B)
    // ──────────────────────────────────────────────────────────────

    /**
     * Per command: P = the pet's stored performing clip length (ms, DEFAULT_PERFORMING_MS
     * without one), R = its release clip length (ms, DEFAULT_RELEASE_MS without one). The
     * same rule as the payload's `phase_seconds` (shown slots only). Two queries.
     *
     * @return array<string, array{performing_ms: int, release_ms: int}>
     */
    public function timingsFor(Pet $pet): array
    {
        $performing = array_map(fn (TrainingCommand $c) => 'school_'.$c->value.'_performing', TrainingCommand::cases());
        $slots = PetMedia::query()->where('pet_id', $pet->id)->where('kind', PetMedia::KIND_PHASE)
            ->whereIn('state', $performing)->get()
            ->filter(fn (PetMedia $m) => $m->isShown())
            ->keyBy('state');
        $derived = $this->payloadFor($slots->values(), CarbonImmutable::now()->addHour());

        $out = [];
        foreach (TrainingCommand::cases() as $command) {
            $slot = $slots->get('school_'.$command->value.'_performing');
            $seconds = $slot === null ? null : (float) ($slot->stored_duration_seconds ?? $slot->duration_seconds ?? 3);
            $release = $derived['school_'.$command->value.'_'.self::SUFFIX_RELEASE]['seconds'] ?? null;

            $out[$command->value] = [
                'performing_ms' => $seconds === null ? self::DEFAULT_PERFORMING_MS : (int) round($seconds * 1000),
                'release_ms' => $release === null || $release <= 0 ? self::DEFAULT_RELEASE_MS : (int) round($release * 1000),
            ];
        }

        return $out;
    }
}
