<?php

namespace App\Console\Commands;

use App\Models\PetMedia;
use App\Services\Media\MediaStorage;
use App\Services\Media\ObjectStorageMover;
use App\Services\Media\PetMediaService;
use App\Services\Media\VideoCompressionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * READ-ONLY report of the media disk (David 2026-10-30, with `media:compress`): usage by
 * kind from `pet_media.bytes` (owning rows — a shared look file counted once), derived
 * school clips, the growth album and clip candidates; what compression saved; the actual
 * files on the active media disk; files no DB row references (orphans — the same reference
 * list as `media:move-to-object-storage`); candidate files older than `--stale-hours`;
 * leftover compression temp files. **Never deletes anything** — deleting production data
 * needs David.
 */
class MediaDiskReportCommand extends Command
{
    protected $signature = 'media:disk-report
        {--stale-hours=24 : A clip candidate older than this counts as old}
        {--list=20 : Print at most this many orphan / old candidate paths}';

    protected $description = 'Read-only report of media disk usage by kind, compression savings, orphans and old candidates (never deletes)';

    public function handle(PetMediaService $media, MediaStorage $storage, ObjectStorageMover $mover): int
    {
        $mb = fn (int|float $bytes): string => number_format($bytes / 1048576, 1, '.', '').' MB';
        $listMax = max(0, (int) $this->option('list'));

        // ── From the DB ─────────────────────────────────────────
        $byKind = DB::table('pet_media')
            ->whereNull('look_media_id')->whereNotNull('storage_path')
            ->groupBy('kind')->orderBy('kind')
            ->selectRaw('kind, count(*) as files, coalesce(sum(bytes), 0) as bytes')
            ->selectRaw('count(*) filter (where compression_status = ? and compression_path = storage_path) as compressed', [VideoCompressionService::STATUS_COMPRESSED])
            ->selectRaw('coalesce(sum(original_bytes - bytes) filter (where compression_status = ? and compression_path = storage_path), 0) as saved', [VideoCompressionService::STATUS_COMPRESSED])
            ->get();

        $rows = $byKind->map(fn ($r): array => [$r->kind, (int) $r->files, $mb((int) $r->bytes), (int) $r->compressed, $mb((int) $r->saved)])->all();
        $derived = DB::table('pet_media_derivatives')->whereNotNull('storage_path')->selectRaw('count(*) as files, coalesce(sum(bytes), 0) as bytes')->first();
        $history = DB::table('pet_media_history')->selectRaw('count(distinct storage_path) as files, coalesce(sum(bytes), 0) as bytes')->first();
        $candidates = DB::table('pet_media')->whereNotNull('candidate_path')->selectRaw('count(*) as files, coalesce(sum(candidate_bytes), 0) as bytes')->first();
        $rows[] = ['derived school clips', (int) $derived->files, $mb((int) $derived->bytes), '–', '–'];
        $rows[] = ['growth album (history)', (int) $history->files, $mb((int) $history->bytes), '–', '–'];
        $rows[] = ['open clip candidates', (int) $candidates->files, $mb((int) $candidates->bytes), '–', '–'];

        $this->info('Media by kind (pet_media.bytes; a shared look file counted once):');
        $this->table(['kind', 'files', 'size', 'compressed', 'saved'], $rows);

        $status = DB::table('pet_media')->whereIn('kind', VideoCompressionService::KINDS)->whereNull('look_media_id')->whereNotNull('storage_path')
            ->selectRaw('count(*) filter (where compression_path is distinct from storage_path) as todo')
            ->selectRaw('count(*) filter (where compression_path = storage_path and compression_status = ?) as rejected', [VideoCompressionService::STATUS_REJECTED])
            ->selectRaw('count(*) filter (where compression_path = storage_path and compression_status = ?) as failed', [VideoCompressionService::STATUS_FAILED])
            ->first();
        $this->line(sprintf('Compression: not tried yet %d, original kept %d, failed %d.', (int) $status->todo, (int) $status->rejected, (int) $status->failed));

        // ── The disk ────────────────────────────────────────────
        $disk = $media->disk();
        $this->line('');
        $this->info('Active media disk: '.($storage->usesObjectStorage() ? 'object storage' : 'local'));

        try {
            $files = array_values(array_filter($disk->allFiles(), fn (string $p): bool => ! str_starts_with(basename($p), '.')));
        } catch (Throwable $e) {
            $this->error('Could not list the media disk: '.$e->getMessage());

            return self::FAILURE;
        }

        $referenced = array_flip($mover->referencedPaths());
        $total = 0;
        $orphans = [];
        $orphanBytes = 0;
        $temps = [];
        foreach ($files as $path) {
            $size = (int) $disk->size($path);
            $total += $size;
            if (str_ends_with($path, VideoCompressionService::TEMP_SUFFIX)) {
                $temps[] = $path;
            }
            if (! isset($referenced[$path])) {
                $orphans[] = $path;
                $orphanBytes += $size;
            }
        }
        $this->line(sprintf('Files on disk: %d, %s.', count($files), $mb($total)));
        $this->line(sprintf('Orphans (no pet_media / history / derivative / contact sheet row): %d, %s.', count($orphans), $mb($orphanBytes)));
        foreach (array_slice($orphans, 0, $listMax) as $path) {
            $this->line('  orphan '.$path);
        }
        if (count($temps) > 0) {
            $this->line(sprintf('Leftover compression temp files: %d.', count($temps)));
        }

        $staleHours = max(1, (int) $this->option('stale-hours'));
        $old = PetMedia::query()->whereNotNull('candidate_path')->where('updated_at', '<', now()->subHours($staleHours))
            ->orderBy('id')->get(['id', 'kind', 'state', 'status', 'candidate_path', 'candidate_bytes', 'updated_at']);
        $this->line(sprintf('Clip candidates older than %d h: %d, %s.', $staleHours, $old->count(), $mb((int) $old->sum('candidate_bytes'))));
        foreach ($old->take($listMax) as $row) {
            $this->line(sprintf('  #%d %s %s (%s) %s since %s', $row->id, $row->kind, $row->state, $row->status, $row->candidate_path, $row->updated_at?->toIso8601String()));
        }

        $this->line('');
        $this->comment('Report only — nothing was deleted. Deleting production media needs David.');

        return self::SUCCESS;
    }
}
