<?php

namespace App\Services\Media;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Copies the pet media files from the local disk to the object disk (M4-05,
 * `media:move-to-object-storage`). Every file of the local `pet_media` disk is a
 * candidate — pet images / videos (`{pet_id}/…`), shared look files (`looks/{id}/…`),
 * the growth album (pet_media_history), clip candidates and quality contact sheets —
 * with the SAME relative path, so no DB row changes and switching PET_MEDIA_DISK
 * is the only step that moves serving.
 *
 *  - only files a DB row points at are copied (orphans — e.g. left behind by a deleted
 *    pet — are reported and never uploaded);
 *  - idempotent / resumable: a file whose object already exists with the same size is
 *    skipped; a crash or `--limit` simply continues on the next run;
 *  - `verify`: MD5 of the local file vs the object's bytes (streamed back — S3 ETags of
 *    multipart uploads are not an MD5); a mismatch is re-uploaded once and verified again;
 *  - `dryRun` writes nothing anywhere;
 *  - local files are NEVER deleted here except by prune(), which deletes only files
 *    that were verified equal in the same run (David runs it, after the switch).
 *
 * No fal call, no DB write (zero AI spend).
 */
class ObjectStorageMover
{
    /** @var list<string> */
    private array $verified = [];

    public function __construct(private readonly MediaStorage $storage) {}

    public function source(): Filesystem
    {
        return Storage::disk($this->storage->localDiskName());
    }

    public function target(): Filesystem
    {
        return Storage::disk($this->storage->objectDiskName());
    }

    /** Why the copy must not start, or null. */
    public function problem(): ?string
    {
        $local = $this->storage->localDiskName();
        $object = $this->storage->objectDiskName();

        if ($this->storage->driver($local) !== 'local') {
            return "Source disk '{$local}' is not a local disk.";
        }
        if ($this->storage->driver($object) !== 's3') {
            return "Target disk '{$object}' is not an s3 disk.";
        }
        if (! $this->storage->objectStorageConfigured()) {
            return 'Object storage is not configured (PET_MEDIA_S3_BUCKET / PET_MEDIA_S3_KEY / PET_MEDIA_S3_SECRET).';
        }

        return null;
    }

    /**
     * Relative paths of every file to move (sorted, stable for --limit): local files the DB
     * points at. Files no row references (a deleted pet whose files were left behind, an old
     * generation) are NOT copied — they are counted as orphans and stay where they are, so
     * a deleted family's media never reach the bucket.
     *
     * @return list<string>
     */
    public function files(): array
    {
        $referenced = array_flip($this->referencedPaths());
        $files = array_values(array_filter($this->localFiles(), fn (string $path): bool => isset($referenced[$path])));
        sort($files);

        return $files;
    }

    /**
     * Local media files no DB row references (reported, never copied or deleted here).
     *
     * @return list<string>
     */
    public function orphans(): array
    {
        $referenced = array_flip($this->referencedPaths());

        return array_values(array_filter($this->localFiles(), fn (string $path): bool => ! isset($referenced[$path])));
    }

    /**
     * Every file of the local disk except dot files (.gitignore, probes).
     *
     * @return list<string>
     */
    private function localFiles(): array
    {
        return array_values(array_filter(
            $this->source()->allFiles(),
            fn (string $path): bool => ! str_starts_with(basename($path), '.'),
        ));
    }

    /**
     * Paths stored in the DB: pet / look media, clip candidates, growth album, contact sheets.
     *
     * @return list<string>
     */
    public function referencedPaths(): array
    {
        return DB::table('pet_media')->whereNotNull('storage_path')->pluck('storage_path')
            ->merge(DB::table('pet_media')->whereNotNull('candidate_path')->pluck('candidate_path'))
            ->merge(DB::table('pet_media_history')->whereNotNull('storage_path')->pluck('storage_path'))
            ->merge(DB::table('pet_media_quality_checks')->whereNotNull('sheet_path')->pluck('sheet_path'))
            ->filter(fn ($p): bool => is_string($p) && $p !== '')
            ->unique()->values()->all();
    }

    /**
     * @param  callable(string, string): void|null  $report  ($path, outcome) per file
     * @return array{total: int, copied: int, already: int, would_copy: int, failed: int, verified: int, mismatched: int, repaired: int, bytes: int, missing_in_db: int, remaining: int, orphans: int}
     */
    public function run(bool $dryRun = false, ?int $limit = null, bool $verify = false, ?callable $report = null): array
    {
        $report ??= static function (string $path, string $outcome): void {};
        $this->verified = [];
        $source = $this->source();
        $target = $this->target();
        $counts = ['total' => 0, 'copied' => 0, 'already' => 0, 'would_copy' => 0, 'failed' => 0, 'verified' => 0, 'mismatched' => 0, 'repaired' => 0, 'bytes' => 0, 'missing_in_db' => 0, 'remaining' => 0, 'orphans' => 0];

        $budget = $limit !== null ? max(0, $limit) : null;

        foreach ($this->files() as $path) {
            $counts['total']++;
            $size = (int) $source->size($path);

            try {
                $present = $this->sameSize($target, $path, $size);
            } catch (Throwable $e) {
                $counts['failed']++;
                $report($path, 'failed: '.$this->reason($e));

                continue;
            }

            if (! $present) {
                if ($budget !== null && $budget === 0) {
                    $counts['remaining']++;

                    continue;
                }
                if ($dryRun) {
                    $counts['would_copy']++;
                    $counts['bytes'] += $size;
                    $budget = $budget !== null ? $budget - 1 : null;
                    $report($path, 'would copy');

                    continue;
                }

                try {
                    $this->copy($path);
                } catch (Throwable $e) {
                    $counts['failed']++;
                    $report($path, 'failed: '.$this->reason($e));

                    continue;
                }

                $counts['copied']++;
                $counts['bytes'] += $size;
                $budget = $budget !== null ? $budget - 1 : null;
                $report($path, 'copied');
            } else {
                $counts['already']++;
            }

            if (! $verify) {
                continue;
            }

            try {
                if ($this->matches($path)) {
                    $counts['verified']++;
                    $this->verified[] = $path;

                    continue;
                }

                $counts['mismatched']++;
                if ($dryRun) {
                    $report($path, 'MISMATCH (dry run: not repaired)');

                    continue;
                }

                $this->copy($path);
                if ($this->matches($path)) {
                    $counts['repaired']++;
                    $counts['verified']++;
                    $this->verified[] = $path;
                    $report($path, 'mismatch repaired');
                } else {
                    $report($path, 'MISMATCH after re-upload');
                }
            } catch (Throwable $e) {
                $counts['failed']++;
                $report($path, 'verify failed: '.$this->reason($e));
            }
        }

        $counts['missing_in_db'] = count($this->referencedMissing());
        $counts['orphans'] = count($this->orphans());

        return $counts;
    }

    /**
     * Delete local copies verified equal by the last run() — only while the active disk is
     * the object disk (otherwise the app still serves from local). Returns the count.
     */
    public function pruneVerified(): int
    {
        if (! $this->storage->usesObjectStorage()) {
            throw new RuntimeException('PET_MEDIA_DISK is not s3 — the local files are still being served; refusing to prune.');
        }

        $source = $this->source();
        $deleted = 0;
        foreach ($this->verified as $path) {
            if ($source->delete($path)) {
                $deleted++;
            }
        }
        $this->verified = [];

        return $deleted;
    }

    /** @return list<string> */
    public function verifiedPaths(): array
    {
        return $this->verified;
    }

    /**
     * Paths the DB points at that are on NEITHER disk (already broken before the move —
     * reported, never fixed here).
     *
     * @return list<string>
     */
    public function referencedMissing(): array
    {
        $source = $this->source();
        $target = $this->target();
        $missing = [];
        foreach ($this->referencedPaths() as $path) {
            if ($source->exists($path)) {
                continue;
            }
            try {
                if ($target->exists($path)) {
                    continue;
                }
            } catch (Throwable) {
                // Unknown — not counted as missing.
                continue;
            }
            $missing[] = $path;
        }

        return $missing;
    }

    private function sameSize(Filesystem $target, string $path, int $size): bool
    {
        return $target->exists($path) && (int) $target->size($path) === $size;
    }

    private function copy(string $path): void
    {
        $in = $this->source()->readStream($path);
        if (! is_resource($in)) {
            throw new RuntimeException('cannot read the local file');
        }

        try {
            if (! $this->target()->writeStream($path, $in, ['visibility' => 'private'])) {
                throw new RuntimeException('upload refused');
            }
        } finally {
            if (is_resource($in)) {
                fclose($in);
            }
        }
    }

    private function matches(string $path): bool
    {
        return $this->md5($this->source(), $path) === $this->md5($this->target(), $path);
    }

    private function md5(Filesystem $disk, string $path): string
    {
        $stream = $disk->readStream($path);
        if (! is_resource($stream)) {
            throw new RuntimeException('cannot read '.$path);
        }

        try {
            $ctx = hash_init('md5');
            hash_update_stream($ctx, $stream);

            return hash_final($ctx);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function reason(Throwable $e): string
    {
        return class_basename($e).': '.mb_substr(strtok($e->getMessage(), "\n") ?: '', 0, 160);
    }
}
