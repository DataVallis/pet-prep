<?php

namespace App\Services\Media;

use App\Models\PetMedia;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * The FREE technical part of the clip quality check (David 2026-10-23) — ffmpeg /
 * ffprobe on a local copy of a downloaded clip. No network, no cost.
 *
 *  - measure(): duration (ffprobe), per-frame luma difference (ffmpeg `signalstats` YDIF on
 *    a 128 px wide grey copy, 0–255) and, for a clip with an end frame, the SSIM of its last
 *    frame against the end image (144 × 256 grey).
 *  - evaluate() is PURE (unit-tested with fake numbers): frozen (mean frame difference below
 *    `min_mean_frame_diff` — production media 126 had ~0.08), a sudden jump (one frame
 *    difference ≥ `spike_min_frame_diff` and ≥ `spike_ratio` × the median), motion bunched up
 *    in the last quarter of a clip that ends in an end frame (production media 123: the dog
 *    stood, then »melted« into the sit in the last ~0.7 s), a last frame that does not match
 *    the end image, a length far from the requested one.
 *  - contactSheet(): `frames` evenly spaced frames tiled into one JPEG for the vision check.
 *
 * Production image: Debian `ffmpeg` (backend/docker/production/Dockerfile). Without the
 * binaries measure() returns null and the caller marks the clip `unchecked`.
 */
class MediaClipAnalyzer
{
    /** @var array<string, bool> binaries already probed in this worker process */
    private static array $available = [];

    public function available(): bool
    {
        $key = $this->ffmpeg().'|'.$this->ffprobe();

        if (! array_key_exists($key, self::$available)) {
            try {
                self::$available[$key] = Process::timeout(5)->run([$this->ffprobe(), '-version'])->successful()
                    && Process::timeout(5)->run([$this->ffmpeg(), '-version'])->successful();
            } catch (Throwable) {
                self::$available[$key] = false;
            }
        }

        return self::$available[$key];
    }

    /**
     * @return array{duration: float|null, frames: int, diffs: list<float>, end_ssim: float|null}|null null = ffmpeg / ffprobe not usable
     */
    public function measure(string $clipPath, ?string $endImagePath = null): ?array
    {
        if (! $this->available()) {
            return null;
        }

        $timeout = (int) config('media.quality_check.timeout_seconds', 12);

        $probe = Process::timeout($timeout)->run([
            $this->ffprobe(), '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=nw=1:nk=1', $clipPath,
        ]);
        $duration = $probe->successful() && is_numeric(trim($probe->output())) ? (float) trim($probe->output()) : null;

        $stats = Process::timeout($timeout)->run([
            $this->ffmpeg(), '-v', 'error', '-i', $clipPath,
            '-vf', 'scale=128:-2,format=gray,signalstats,metadata=print:key=lavfi.signalstats.YDIF:file=-',
            '-an', '-f', 'null', '-',
        ]);

        $diffs = [];
        if ($stats->successful() && preg_match_all('/lavfi\.signalstats\.YDIF=([0-9.]+)/', $stats->output(), $m) > 0) {
            // The first frame has nothing to compare with (YDIF 0).
            $diffs = array_map('floatval', array_slice($m[1], 1));
        }

        $endSsim = null;
        if ($endImagePath !== null) {
            $ssim = Process::timeout($timeout)->run([
                $this->ffmpeg(), '-v', 'info', '-sseof', '-0.15', '-i', $clipPath, '-i', $endImagePath,
                '-filter_complex', '[0:v]scale=144:256,format=gray[a];[1:v]scale=144:256,format=gray[b];[a][b]ssim',
                '-frames:v', '1', '-an', '-f', 'null', '-',
            ]);
            if (preg_match('/SSIM .*All:([0-9.]+)/', $ssim->errorOutput().$ssim->output(), $s) === 1) {
                $endSsim = (float) $s[1];
            }
        }

        return ['duration' => $duration, 'frames' => count($diffs) + 1, 'diffs' => $diffs, 'end_ssim' => $endSsim];
    }

    /**
     * PURE: judge measured numbers. `$expectedSeconds` = the requested clip length,
     * `$hasEndFrame` = the clip was made with an end frame (a transition / performing /
     * ball running …), `$endSsim` only for such clips. `$lowMotion` = a clip that should
     * barely move (sleeping, sick, waiting — its own lower frozen threshold, QA #363 M1).
     * ok null = nothing could be measured (never a fail — the caller logs it).
     *
     * @param  array{duration: float|null, frames: int, diffs: list<float>, end_ssim: float|null}  $m
     * @return array{ok: bool|null, failures: list<string>, metrics: array<string, float|int|null>}
     */
    public static function evaluate(array $m, ?float $expectedSeconds, bool $hasEndFrame, bool $lowMotion = false): array
    {
        $cfg = (array) config('media.quality_check', []);
        $minMean = (float) ($lowMotion ? ($cfg['min_mean_frame_diff_low_motion'] ?? 0.03) : ($cfg['min_mean_frame_diff'] ?? 0.25));
        $diffs = array_values($m['diffs']);
        $n = count($diffs);
        $mean = $n > 0 ? array_sum($diffs) / $n : 0.0;
        $max = $n > 0 ? max($diffs) : 0.0;
        $median = self::median($diffs);
        $total = array_sum($diffs);
        $tail = $n > 0 ? array_sum(array_slice($diffs, (int) floor($n * 0.75))) : 0.0;
        $tailShare = $total > 0 ? $tail / $total : 0.0;

        $failures = [];

        $metrics = [
            'duration' => $m['duration'] !== null ? round($m['duration'], 3) : null,
            'frames' => $m['frames'],
            'mean_diff' => round($mean, 3),
            'median_diff' => round($median, 3),
            'max_diff' => round($max, 3),
            'tail_motion_share' => round($tailShare, 3),
            'end_ssim' => $m['end_ssim'] !== null ? round($m['end_ssim'], 3) : null,
            'low_motion' => $lowMotion ? 1 : 0,
        ];

        // QA #363 M1: no metrics is not a verdict.
        if ($m['duration'] === null || $n < 2) {
            return ['ok' => null, 'failures' => [], 'metrics' => $metrics];
        }

        if ($expectedSeconds !== null && abs($m['duration'] - $expectedSeconds) > (float) ($cfg['max_duration_drift_seconds'] ?? 1.0)) {
            $failures[] = sprintf('duration %.2f s instead of %.0f s', $m['duration'], $expectedSeconds);
        }

        if ($mean < $minMean) {
            $failures[] = sprintf('frozen: mean frame difference %.2f / 255 (minimum %.2f%s)', $mean, $minMean, $lowMotion ? ', low-motion clip' : '');
        }

        if ($max >= (float) ($cfg['spike_min_frame_diff'] ?? 12.0) && $max >= (float) ($cfg['spike_ratio'] ?? 8.0) * max($median, 0.05)) {
            $failures[] = sprintf('sudden jump: one frame difference %.1f vs median %.2f (cut / morph)', $max, $median);
        }

        if ($hasEndFrame && $n >= 8 && $mean >= $minMean && $tailShare > (float) ($cfg['max_tail_motion_share'] ?? 0.7)) {
            $failures[] = sprintf('motion bunched at the end: %.0f %% of all motion in the last quarter (morph into the end frame)', $tailShare * 100);
        }

        if ($hasEndFrame && $m['end_ssim'] !== null && $m['end_ssim'] < (float) ($cfg['min_end_frame_ssim'] ?? 0.45)) {
            $failures[] = sprintf('last frame does not match the end image (SSIM %.2f)', $m['end_ssim']);
        }

        return ['ok' => $failures === [], 'failures' => $failures, 'metrics' => $metrics];
    }

    /**
     * `frames` evenly spaced frames of the clip tiled into one JPEG (`sheet_columns` per row).
     * Returns false when ffmpeg failed.
     */
    public function contactSheet(string $clipPath, float $durationSeconds, string $outPath): bool
    {
        $frames = max(2, (int) config('media.quality_check.frames', 6));
        $columns = max(1, (int) config('media.quality_check.sheet_columns', 3));
        $rows = (int) ceil($frames / $columns);
        $width = max(64, (int) config('media.quality_check.sheet_frame_width', 240));
        // Sample in the middle of each of `frames` equal slices of the clip.
        $rate = $frames / max(0.5, $durationSeconds);

        try {
            $result = Process::timeout((int) config('media.quality_check.timeout_seconds', 12))->run([
                $this->ffmpeg(), '-v', 'error', '-y', '-i', $clipPath,
                '-vf', sprintf('fps=%.5f,scale=%d:-2,tile=%dx%d', $rate, $width, $columns, $rows),
                '-frames:v', '1', '-q:v', '4', $outPath,
            ]);
        } catch (Throwable) {
            return false;
        }

        return $result->successful() && is_file($outPath) && filesize($outPath) > 0;
    }

    /** QA #363 M1: a clip that should barely move (config low_motion_states / low_motion_phases). */
    public static function isLowMotion(PetMedia $clip): bool
    {
        $cfg = (array) config('media.quality_check', []);

        return match (true) {
            $clip->isVideo() => in_array($clip->state, (array) ($cfg['low_motion_states'] ?? []), true),
            $clip->isPhase() => in_array($clip->state, (array) ($cfg['low_motion_phases'] ?? []), true),
            default => false,
        };
    }

    /** @param  list<float>  $values */
    private static function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $mid = intdiv(count($values), 2);

        return count($values) % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    private function ffmpeg(): string
    {
        return (string) config('media.quality_check.ffmpeg', 'ffmpeg');
    }

    private function ffprobe(): string
    {
        return (string) config('media.quality_check.ffprobe', 'ffprobe');
    }
}
