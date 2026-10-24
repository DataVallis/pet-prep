<?php

namespace App\Console\Commands;

use App\Services\Media\MediaStorage;
use App\Services\Media\ObjectStorageMover;
use Illuminate\Console\Command;
use Illuminate\Support\Number;

/**
 * M4-05: copy the pet media files from the local disk (storage/app/pet-media) to the
 * private object storage bucket (disk pet_media_s3, PET_MEDIA_S3_*) — same relative
 * paths, no DB change, zero AI spend. Only files a DB row points at (pet / look media,
 * candidates, growth album, contact sheets); orphans are reported, never copied. Manual only (David, runbook DEPLOYMENT.md D23).
 *
 *   --dry-run      list what would be copied; writes nothing
 *   --limit=N      copy at most N files this run (resumable: the next run continues)
 *   --verify       also compare MD5 of every file with the object (re-uploads a mismatch once)
 *   --prune-local  after a fully clean --verify run, delete the LOCAL copies that were
 *                  verified — only while PET_MEDIA_DISK=s3 is live; asks first (--force skips)
 *
 * Exit 1 when anything failed / still mismatches, or when the setup is not ready.
 */
class MoveMediaToObjectStorageCommand extends Command
{
    protected $signature = 'media:move-to-object-storage
        {--dry-run : Only report, write nothing}
        {--limit= : Copy at most this many files in this run}
        {--verify : Compare checksums of every file with its object}
        {--prune-local : After a clean --verify run, delete the verified LOCAL copies (PET_MEDIA_DISK=s3 only)}
        {--force : Do not ask before --prune-local}';

    protected $description = 'Copy pet media from the local disk to object storage (idempotent, verifiable; never deletes unless --prune-local)';

    public function handle(ObjectStorageMover $mover, MediaStorage $storage): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $verify = (bool) $this->option('verify');
        $prune = (bool) $this->option('prune-local');
        $limit = $this->option('limit');
        $limit = $limit === null || $limit === '' ? null : max(0, (int) $limit);

        if (($problem = $mover->problem()) !== null) {
            $this->error($problem);

            return self::FAILURE;
        }

        if ($prune && (! $verify || $dryRun || $limit !== null)) {
            $this->error('--prune-local needs a full --verify run (no --dry-run, no --limit).');

            return self::FAILURE;
        }
        if ($prune && ! $storage->usesObjectStorage()) {
            $this->error('PET_MEDIA_DISK is not s3 — the local files are still being served; refusing to prune.');

            return self::FAILURE;
        }

        // QA #374 M1: prove the keys can write (put + read + delete of a probe object).
        $status = $storage->status(fresh: true, write: true);
        if ($status['reachable'] !== true) {
            $this->error('Bucket not reachable: '.($status['error'] ?? 'unknown error'));

            return self::FAILURE;
        }

        $this->info(sprintf('%s pet media: local → bucket %s%s', $dryRun ? 'DRY RUN —' : 'Copying', (string) $status['bucket'], $verify ? ' (with checksum verify)' : ''));

        $counts = $mover->run($dryRun, $limit, $verify, function (string $path, string $outcome): void {
            if ($this->output->isVerbose() || ! in_array($outcome, ['copied', 'would copy'], true)) {
                $this->line("{$outcome}: {$path}");
            }
        });

        $this->table(['files', 'copied', 'already there', 'would copy', 'remaining (limit)', 'verified', 'mismatched', 'repaired', 'failed', 'bytes moved', 'DB paths on neither disk', 'orphans (not copied)', 'vanished'], [[
            $counts['total'], $counts['copied'], $counts['already'], $counts['would_copy'], $counts['remaining'],
            $verify ? $counts['verified'] : '—', $verify ? $counts['mismatched'] : '—', $verify ? $counts['repaired'] : '—',
            $counts['failed'], Number::fileSize($counts['bytes'], 1), $counts['missing_in_db'], $counts['orphans'], $counts['vanished'],
        ]]);

        $unresolved = $counts['failed'] + ($counts['mismatched'] - $counts['repaired']);
        if ($unresolved > 0) {
            $this->error("{$unresolved} file(s) failed or still mismatch — run again (nothing local was deleted).");

            return self::FAILURE;
        }

        if ($counts['missing_in_db'] > 0) {
            $this->warn("{$counts['missing_in_db']} path(s) in the DB exist on neither disk (were missing before the move).");
        }

        if (! $prune) {
            return self::SUCCESS;
        }

        $verified = count($mover->verifiedPaths());
        if ($verified !== $counts['total']) {
            $this->error("Only {$verified} of {$counts['total']} files verified — not pruning.");

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Delete {$verified} verified LOCAL file(s)? The bucket copies stay.", false)) {
            $this->warn('Not pruned.');

            return self::SUCCESS;
        }

        $this->info('Deleted '.$mover->pruneVerified().' local file(s).');

        return self::SUCCESS;
    }
}
