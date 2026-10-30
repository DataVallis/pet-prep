<?php

namespace App\Console\Commands;

use App\Models\PetMedia;
use App\Services\AppSettingsService;
use App\Services\Media\VideoCompressionService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Stored video compression (David 2026-10-30): re-encode stored AI clips once into a new
 * key when it saves ≥ `media.compression.min_saving_percent` and looks the same (SSIM) —
 * Media\VideoCompressionService. Scheduled hourly at night (routes/console.php) while the
 * superadmin switch /admin → Feature switches »Video compression« is on (read fresh; off →
 * no-op). A real run first sweeps stale temp files, recovers interrupted swaps and deletes
 * originals past their grace period, then reads the due set with `chunkById` and stops
 * starting new files after the time budget; `--limit` caps files processed, never rows read.
 * Three failures in a row = a system fault (disk full, ffmpeg broken): the run stops and
 * those three are NOT recorded against the files (QA #450 m5).
 *
 *  --dry-run       list the candidates and their current total MB — changes nothing
 *  --sample=N      encode N candidates to temp files and report saving % + SSIM per file —
 *                  changes nothing (this is how David decides before switching on)
 *  --retry-failed  also files that failed MAX_ATTEMPTS times
 */
class CompressPetMediaCommand extends Command
{
    /** Consecutive failures that stop a run as a system fault. */
    public const MAX_CONSECUTIVE_FAILURES = 3;

    protected $signature = 'media:compress
        {--dry-run : Only list the candidates and their total size}
        {--sample= : Encode this many candidates to temp files and report saving / SSIM (nothing replaced)}
        {--limit= : At most this many files in this run}
        {--pet= : Only the clips this pet shows (its own or its look\'s)}
        {--look= : Only the clips of this shared look}
        {--retry-failed : Also retry files that failed the maximum number of times}
        {--budget= : Stop starting new files after this many seconds (default media.compression.time_budget_seconds, 0 = none)}';

    protected $description = 'Re-encode stored AI videos once (same resolution / fps, CRF 21) when it saves enough and looks the same';

    public function handle(VideoCompressionService $compression, AppSettingsService $settings): int
    {
        $pet = $this->option('pet') !== null ? (int) $this->option('pet') : null;
        $look = $this->option('look') !== null ? (int) $this->option('look') : null;
        $retryFailed = (bool) $this->option('retry-failed');
        $query = $compression->candidates($pet, $look, $retryFailed);

        if ($this->option('dry-run')) {
            return $this->dryRun($query);
        }

        $sample = $this->option('sample') !== null ? max(1, (int) $this->option('sample')) : null;

        if ($sample === null && ! $settings->videoCompression(fresh: true)) {
            $this->info('Video compression is off (/admin → Feature switches) — nothing done. --dry-run and --sample work while it is off.');

            return self::SUCCESS;
        }

        if (! $compression->available()) {
            $this->error('ffmpeg / ffprobe not available — nothing done.');
            Log::warning('media:compress: ffmpeg / ffprobe not available');

            return self::FAILURE;
        }

        if ($sample === null) {
            $temps = $compression->cleanupTempFiles();
            $recovered = $compression->recoverInterrupted();
            $originals = $compression->deleteCompressedOriginals();
            $this->line(sprintf('Housekeeping: %d stale temp file(s), interrupted swaps finished %d / undone %d, %d original(s) deleted after the grace period.',
                $temps, $recovered['completed'], $recovered['reset'], $originals));
        }

        $limit = $sample ?? ($this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null);
        $budget = $this->option('budget') !== null ? (int) $this->option('budget') : (int) config('media.compression.time_budget_seconds', 600);
        $deadline = $budget > 0 ? now()->addSeconds($budget) : null;

        $counts = ['files' => 0, 'compressed' => 0, 'rejected' => 0, 'failed' => 0, 'other' => 0, 'bytes_before' => 0, 'bytes_after' => 0];
        $stopped = null;
        /** @var list<array{id: int, path: string, note: string}> $pending failures not recorded yet */
        $pending = [];

        $query->chunkById(50, function (Collection $rows) use ($compression, $sample, $limit, $deadline, $retryFailed, &$counts, &$stopped, &$pending): bool {
            foreach ($rows as $row) {
                /** @var PetMedia $row */
                if ($limit !== null && $counts['files'] >= $limit) {
                    $stopped = 'limit';

                    return false;
                }
                if ($deadline !== null && now()->greaterThanOrEqualTo($deadline)) {
                    $stopped = 'time budget';

                    return false;
                }

                $result = $compression->compress((int) $row->id, replace: $sample === null, recordFailures: false, retryFailed: $retryFailed);
                $counts['files']++;
                $this->line($this->describe($result));

                $outcome = $result['outcome'];
                if ($outcome === VideoCompressionService::STATUS_FAILED) {
                    $counts['failed']++;
                    if ($sample === null) {
                        $pending[] = ['id' => (int) $row->id, 'path' => (string) $result['path'], 'note' => (string) $result['note']];
                    }
                    if (count($pending) >= self::MAX_CONSECUTIVE_FAILURES) {
                        $stopped = 'system fault';

                        return false;
                    }

                    continue;
                }

                // Anything else proves the system works: the earlier failures were the files'.
                $this->flush($compression, $pending);
                if (in_array($outcome, [VideoCompressionService::STATUS_COMPRESSED, 'would_compress'], true)) {
                    $counts['compressed']++;
                    $counts['bytes_before'] += (int) $result['before'];
                    $counts['bytes_after'] += (int) $result['after'];
                } elseif (in_array($outcome, [VideoCompressionService::STATUS_REJECTED, 'would_reject'], true)) {
                    $counts['rejected']++;
                } else {
                    $counts['other']++;
                }
            }

            return true;
        });

        if ($stopped === 'system fault') {
            $this->error(sprintf('%d failures in a row — stopped as a system fault (disk, ffmpeg, memory?); they are not counted against the files.', count($pending)));
            Log::error('media:compress: stopped after consecutive failures', ['failures' => $pending]);
        } else {
            $this->flush($compression, $pending);
        }

        $mbBefore = round($counts['bytes_before'] / 1048576, 1);
        $mbAfter = round($counts['bytes_after'] / 1048576, 1);
        $verb = $sample !== null ? 'Sample (nothing replaced)' : 'Done';
        $this->info(sprintf('%s: %d file(s) — %s %d (%.1f MB → %.1f MB), kept original %d, failed %d, skipped %d%s.',
            $verb, $counts['files'], $sample !== null ? 'would compress' : 'compressed', $counts['compressed'], $mbBefore, $mbAfter,
            $counts['rejected'], $counts['failed'], $counts['other'], $stopped !== null ? " (stopped: {$stopped})" : ''));

        if ($counts['files'] > 0) {
            Log::info('media:compress', [...$counts, 'mb_before' => $mbBefore, 'mb_after' => $mbAfter, 'sample' => $sample !== null, 'stopped' => $stopped]);
        }

        return $stopped === 'system fault' ? self::FAILURE : self::SUCCESS;
    }

    /** @param list<array{id: int, path: string, note: string}> $pending */
    private function flush(VideoCompressionService $compression, array &$pending): void
    {
        foreach ($pending as $failure) {
            $compression->recordFailure($failure['id'], $failure['path'], $failure['note']);
        }
        $pending = [];
    }

    /** @param Builder<PetMedia> $query */
    private function dryRun(Builder $query): int
    {
        $count = 0;
        $bytes = 0;

        $query->chunkById(200, function (Collection $rows) use (&$count, &$bytes): void {
            foreach ($rows as $row) {
                /** @var PetMedia $row */
                $count++;
                $bytes += (int) $row->bytes;
                $this->line(sprintf('candidate #%d %s (%s) %.1f MB%s', $row->id, $row->label(),
                    $row->pet_look_id !== null ? 'look '.$row->pet_look_id : 'pet '.$row->pet_id, (int) $row->bytes / 1048576,
                    $row->compression_status === VideoCompressionService::STATUS_FAILED ? ' (failed '.$row->compression_attempts.'×)' : ''));
            }
        }, 'id');

        $replacing = PetMedia::where('compression_status', VideoCompressionService::STATUS_REPLACING)->count();
        $originals = PetMedia::whereNotNull('original_path')->count();
        $this->info(sprintf('%d candidate file(s), %.1f MB in total; %d interrupted swap(s) and %d original(s) awaiting deletion (dry run — nothing changed).',
            $count, $bytes / 1048576, $replacing, $originals));

        return self::SUCCESS;
    }

    /** @param array{outcome: string, label: string, before: int|null, after: int|null, saving_percent: float|null, ssim: float|null, min_ssim: float|null, note: string|null} $r */
    private function describe(array $r): string
    {
        $sizes = $r['before'] !== null && $r['after'] !== null
            ? sprintf(' %.2f MB → %.2f MB (-%s %%)', $r['before'] / 1048576, $r['after'] / 1048576, $r['saving_percent'])
            : '';
        $ssim = $r['ssim'] !== null ? sprintf(' SSIM %.4f (min frame %.4f)', $r['ssim'], (float) $r['min_ssim']) : '';
        $note = in_array($r['outcome'], [VideoCompressionService::STATUS_COMPRESSED, 'would_compress'], true) || $r['note'] === null ? '' : ' — '.$r['note'];

        return "{$r['outcome']} {$r['label']}{$sizes}{$ssim}{$note}";
    }
}
