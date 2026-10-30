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
 * Stored video compression (David 2026-10-30): re-encode stored AI clips once at the same
 * path when it saves ≥ `media.compression.min_saving_percent` and looks the same (SSIM) —
 * Media\VideoCompressionService. Scheduled hourly at night (routes/console.php) while the
 * superadmin switch /admin → Feature switches »Video compression« is on (read fresh; off →
 * no-op). Reads the due set with `chunkById` and stops starting new files after the time
 * budget; `--limit` caps files processed, never rows read.
 *
 *  --dry-run     list the candidates and their current total MB — changes nothing
 *  --sample=N    encode N candidates to temp files and report saving % + SSIM per file —
 *                changes nothing (this is how David decides before switching on)
 */
class CompressPetMediaCommand extends Command
{
    protected $signature = 'media:compress
        {--dry-run : Only list the candidates and their total size}
        {--sample= : Encode this many candidates to temp files and report saving / SSIM (nothing replaced)}
        {--limit= : At most this many files in this run}
        {--pet= : Only the clips this pet shows (its own or its look\'s)}
        {--look= : Only the clips of this shared look}
        {--budget= : Stop starting new files after this many seconds (default media.compression.time_budget_seconds, 0 = none)}';

    protected $description = 'Re-encode stored AI videos once (same resolution / fps, CRF 21) when it saves enough and looks the same';

    public function handle(VideoCompressionService $compression, AppSettingsService $settings): int
    {
        $pet = $this->option('pet') !== null ? (int) $this->option('pet') : null;
        $look = $this->option('look') !== null ? (int) $this->option('look') : null;
        $query = $compression->candidates($pet, $look);

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

        $limit = $sample ?? ($this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null);
        $budget = $this->option('budget') !== null ? (int) $this->option('budget') : (int) config('media.compression.time_budget_seconds', 600);
        $deadline = $budget > 0 ? microtime(true) + $budget : null;

        $counts = ['files' => 0, 'compressed' => 0, 'rejected' => 0, 'failed' => 0, 'other' => 0, 'bytes_before' => 0, 'bytes_after' => 0];
        $stopped = null;

        $query->chunkById(50, function (Collection $rows) use ($compression, $sample, $limit, $deadline, &$counts, &$stopped): bool {
            foreach ($rows as $row) {
                /** @var PetMedia $row */
                if ($limit !== null && $counts['files'] >= $limit) {
                    $stopped = 'limit';

                    return false;
                }
                if ($deadline !== null && microtime(true) >= $deadline) {
                    $stopped = 'time budget';

                    return false;
                }

                $result = $compression->compress((int) $row->id, replace: $sample === null);
                $counts['files']++;
                $this->line($this->describe($result));

                $outcome = $result['outcome'];
                if (in_array($outcome, [VideoCompressionService::STATUS_COMPRESSED, 'would_compress'], true)) {
                    $counts['compressed']++;
                    $counts['bytes_before'] += (int) $result['before'];
                    $counts['bytes_after'] += (int) $result['after'];
                } elseif (in_array($outcome, [VideoCompressionService::STATUS_REJECTED, 'would_reject'], true)) {
                    $counts['rejected']++;
                } elseif ($outcome === VideoCompressionService::STATUS_FAILED) {
                    $counts['failed']++;
                } else {
                    $counts['other']++;
                }
            }

            return true;
        });

        $mbBefore = round($counts['bytes_before'] / 1048576, 1);
        $mbAfter = round($counts['bytes_after'] / 1048576, 1);
        $verb = $sample !== null ? 'Sample (nothing replaced)' : 'Done';
        $this->info(sprintf('%s: %d file(s) — %s %d (%.1f MB → %.1f MB), kept original %d, failed %d, skipped %d%s.',
            $verb, $counts['files'], $sample !== null ? 'would compress' : 'compressed', $counts['compressed'], $mbBefore, $mbAfter,
            $counts['rejected'], $counts['failed'], $counts['other'], $stopped !== null ? " (stopped: {$stopped})" : ''));

        if ($counts['files'] > 0) {
            Log::info('media:compress', [...$counts, 'mb_before' => $mbBefore, 'mb_after' => $mbAfter, 'sample' => $sample !== null, 'stopped' => $stopped]);
        }

        return self::SUCCESS;
    }

    /** @param Builder<PetMedia> $query */
    private function dryRun($query): int
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

        $this->info(sprintf('%d candidate file(s), %.1f MB in total (dry run — nothing changed).', $count, $bytes / 1048576));

        return self::SUCCESS;
    }

    /** @param array{outcome: string, label: string, before: int|null, after: int|null, saving_percent: float|null, ssim: float|null, note: string|null} $r */
    private function describe(array $r): string
    {
        $sizes = $r['before'] !== null && $r['after'] !== null
            ? sprintf(' %.2f MB → %.2f MB (-%s %%)', $r['before'] / 1048576, $r['after'] / 1048576, $r['saving_percent'])
            : '';
        $ssim = $r['ssim'] !== null ? sprintf(' SSIM %.4f', $r['ssim']) : '';
        $note = in_array($r['outcome'], [VideoCompressionService::STATUS_COMPRESSED, 'would_compress'], true) || $r['note'] === null ? '' : ' — '.$r['note'];

        return "{$r['outcome']} {$r['label']}{$sizes}{$ssim}{$note}";
    }
}
