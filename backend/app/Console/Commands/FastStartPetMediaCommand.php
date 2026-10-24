<?php

namespace App\Console\Commands;

use App\Services\Media\MediaStorage;
use App\Services\Media\Mp4FastStart;
use App\Services\Media\PetMediaService;
use Illuminate\Console\Command;

/**
 * Rewrites stored pet videos whose MP4 index (moov) sits after the media data
 * so it comes first (incident 2026-10-20: the app's player could not open
 * them). Same path, same content, only the box order changes — no DB writes.
 * Reads only box headers of files that are already fine, so it is scheduled
 * hourly as a safety net; `--dry-run` lists what would change.
 */
class FastStartPetMediaCommand extends Command
{
    protected $signature = 'media:faststart {--dry-run : Only list the videos that need it}';

    protected $description = 'Move the MP4 index (moov) to the front of stored pet videos';

    public function handle(PetMediaService $media, Mp4FastStart $fastStart, MediaStorage $storage): int
    {
        // M4-05: on object storage there is no local path to rewrite in place (and an hourly
        // LIST of the bucket would cost requests). New videos are converted before the upload
        // (PetMediaService::storeResult); files moved by media:move-to-object-storage were
        // checked here while they were still local.
        if ($storage->usesObjectStorage()) {
            $this->info('Pet media are on object storage — nothing to rewrite (videos are made faststart before the upload).');

            return self::SUCCESS;
        }

        $disk = $media->disk();
        $counts = [Mp4FastStart::RESULT_FIXED => 0, Mp4FastStart::RESULT_ALREADY => 0, Mp4FastStart::RESULT_SKIPPED => 0];

        foreach ($disk->allFiles() as $relative) {
            if (! str_ends_with(strtolower($relative), '.mp4')) {
                continue;
            }

            $path = $disk->path($relative);

            if ($this->option('dry-run')) {
                $needs = $fastStart->fileLayout($path);
                if ($needs === true) {
                    $this->line("would fix {$relative}");
                    $counts[Mp4FastStart::RESULT_FIXED]++;
                } else {
                    $counts[$needs === false ? Mp4FastStart::RESULT_ALREADY : Mp4FastStart::RESULT_SKIPPED]++;
                }

                continue;
            }

            $result = $fastStart->process($path);
            $counts[$result]++;
            if ($result === Mp4FastStart::RESULT_FIXED) {
                $this->line("fixed {$relative}");
            } elseif ($result === Mp4FastStart::RESULT_SKIPPED) {
                $this->warn("skipped {$relative}");
            }
        }

        $verb = $this->option('dry-run') ? 'Would fix' : 'Fixed';
        $this->info("{$verb} {$counts['fixed']}, already faststart {$counts['already']}, skipped {$counts['skipped']}.");

        return self::SUCCESS;
    }
}
