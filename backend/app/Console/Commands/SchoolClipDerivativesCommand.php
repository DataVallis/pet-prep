<?php

namespace App\Console\Commands;

use App\Jobs\GenerateSchoolClipDerivative;
use App\Models\PetMedia;
use App\Services\Media\SchoolClipDerivativeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * School smooth playback (David 2026-10-28): queue the ffmpeg-derived school clips
 * (attention / success loops, release = reversed performing) of stored school phase
 * clips that have none yet or whose source file changed. Zero AI cost (no fal call).
 * Scheduled every 10 minutes with a small batch (routes/console.php) so production fills
 * existing looks by itself after a deploy — idempotent (unique job per source row, an
 * up-to-date clip is skipped; a source that failed MAX_ATTEMPTS times is left alone).
 */
class SchoolClipDerivativesCommand extends Command
{
    public const DEFAULT_LIMIT = 12;

    protected $signature = 'media:school-derivatives
        {--pet= : Only the school clips this pet shows (its own or its look\'s)}
        {--look= : Only the school clips of this shared look}
        {--limit='.self::DEFAULT_LIMIT.' : At most this many source clips per run}
        {--dry-run : Only count / list what would be queued}';

    protected $description = 'Queue the ffmpeg-derived school clips (loops, release) of stored school phase clips (0 $ AI cost)';

    public function handle(SchoolClipDerivativeService $derivatives): int
    {
        $pet = $this->option('pet') !== null ? (int) $this->option('pet') : null;
        $look = $this->option('look') !== null ? (int) $this->option('look') : null;
        $limit = max(1, (int) $this->option('limit'));

        $query = $derivatives->dueSources($pet, $look);
        $due = (clone $query)->count();
        $batch = $query->limit($limit)->get(['id', 'state', 'pet_id', 'pet_look_id']);

        foreach ($batch as $source) {
            /** @var PetMedia $source */
            $this->line(sprintf('%s #%d %s (%s)', $this->option('dry-run') ? 'would queue' : 'queued', $source->id, $source->state,
                $source->pet_look_id !== null ? 'look '.$source->pet_look_id : 'pet '.$source->pet_id));
            if (! $this->option('dry-run')) {
                GenerateSchoolClipDerivative::dispatch((int) $source->id);
            }
        }

        $counts = ['due' => $due, 'queued' => $this->option('dry-run') ? 0 : $batch->count(), 'dry_run' => (bool) $this->option('dry-run')];
        $this->info(sprintf('%d source clip(s) due, %d queued in this run.', $due, $counts['queued']));
        if ($due > 0) {
            Log::info('media:school-derivatives', $counts);
        }

        return self::SUCCESS;
    }
}
