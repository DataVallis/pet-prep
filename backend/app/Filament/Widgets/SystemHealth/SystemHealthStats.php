<?php

namespace App\Filament\Widgets\SystemHealth;

use App\Filament\Resources\PetResource;
use App\Models\User;
use App\Services\AppErrors\AppErrorReportService;
use App\Services\Media\MediaStorage;
use App\Services\SystemHealth\SystemHealthService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * Status tiles of /admin/system-health (David 2026-10-19). Only on that
 * page (not discovered for the dashboard).
 */
class SystemHealthStats extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected static ?string $pollingInterval = '60s';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isSuperadmin();
    }

    /** Livewire requests reach a widget directly: check again, not only when the page lists it. */
    public function boot(): void
    {
        abort_unless(static::canView(), 403);
    }

    protected function getColumns(): int
    {
        return 4;
    }

    /**
     * @return list<Stat>
     */
    protected function getStats(): array
    {
        $health = app(SystemHealthService::class);

        $open24 = $health->openErrorCount(24);
        $open7d = $health->openErrorCount(24 * 7);
        $failedJobs = $health->failedJobCount();
        $failedJobs24 = $health->failedJobCount(24);
        $backlog = $health->queueBacklog();
        $known = array_filter($backlog, fn (?int $n): bool => $n !== null);
        $stuck = $health->stuckLedgerPetIds();
        $media = $health->failedMediaCount(24);
        $needsReview = $health->needsReviewMediaCount();
        $unchecked = $health->uncheckedMediaCount(24);
        $beat = $health->lastHeartbeat();
        $stale = $health->heartbeatIsStale();
        $disk = $health->diskUsage();
        // M5-05: mobile app crash / render-error reports.
        $appErrors = app(AppErrorReportService::class)->windowSummary(24);
        // M4-05: where pet media live + whether the bucket answers (cached 60 s).
        $storage = app(MediaStorage::class)->status();
        $bucket = (string) ($storage['bucket'] ?? '');

        return [
            Stat::make(__('admin.system_health.stats.errors'), $open24)
                ->description(__('admin.system_health.stats.errors_desc', ['week' => $open7d]))
                ->descriptionIcon('heroicon-m-bug-ant')
                ->color($open24 > 0 ? 'danger' : ($open7d > 0 ? 'warning' : 'success')),
            Stat::make(__('admin.system_health.stats.failed_jobs'), $failedJobs)
                ->description(__('admin.system_health.stats.failed_jobs_desc', ['day' => $failedJobs24]))
                ->descriptionIcon('heroicon-m-queue-list')
                ->color($failedJobs24 > 0 ? 'danger' : ($failedJobs > 0 ? 'warning' : 'success')),
            Stat::make(__('admin.system_health.stats.backlog'), $known === [] ? '—' : (string) array_sum($known))
                ->description($known === []
                    ? __('admin.system_health.stats.backlog_unknown')
                    : collect($known)->map(fn (int $n, string $q): string => "{$q}: {$n}")->implode(' · '))
                ->descriptionIcon('heroicon-m-inbox-stack')
                ->color($known === [] ? 'gray' : (array_sum($known) > 100 ? 'warning' : 'success')),
            Stat::make(__('admin.system_health.stats.heartbeat'), $beat === null ? __('admin.system_health.stats.never') : $beat->diffForHumans())
                ->description($beat === null
                    ? __('admin.system_health.stats.heartbeat_never_desc')
                    : __('admin.system_health.stats.heartbeat_desc', ['at' => $beat->copy()->timezone('Europe/Ljubljana')->format('Y-m-d H:i:s')]))
                ->descriptionIcon('heroicon-m-clock')
                ->color($stale ? 'danger' : 'success'),
            Stat::make(__('admin.system_health.stats.stuck_ledger'), $stuck->count())
                ->description($stuck->isEmpty()
                    ? __('admin.system_health.stats.stuck_ledger_desc', ['days' => (int) config('system_health.stuck_ledger_days', 2)])
                    : __('admin.system_health.stats.stuck_ledger_ids', ['ids' => $stuck->take(10)->map(fn (int $id): string => "#{$id}")->implode(', ')]))
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color($stuck->isEmpty() ? 'success' : 'danger')
                ->url(PetResource::getUrl()),
            Stat::make(__('admin.system_health.stats.media'), $media)
                ->description(__('admin.system_health.stats.media_desc'))
                ->descriptionIcon('heroicon-m-photo')
                ->color($media > 0 ? 'warning' : 'success'),
            // Quality check (David 2026-10-23): clips that failed the check twice / were flagged.
            Stat::make(__('admin.system_health.stats.needs_review'), $needsReview)
                ->description(__('admin.system_health.stats.needs_review_desc'))
                ->descriptionIcon('heroicon-m-eye')
                ->color($needsReview > 0 ? 'warning' : 'success')
                ->url(SystemHealthService::needsReviewUrl()),
            // QA #363 m1: clips served without a check (ffmpeg missing / nothing measurable).
            Stat::make(__('admin.system_health.stats.unchecked'), $unchecked)
                ->description(__('admin.system_health.stats.unchecked_desc'))
                ->descriptionIcon('heroicon-m-question-mark-circle')
                ->color($unchecked > 0 ? 'danger' : 'success'),
            Stat::make(__('admin.app_errors.stats'), $appErrors['occurrences'])
                ->description(__('admin.app_errors.stats_desc', ['count' => $appErrors['fingerprints']]))
                ->descriptionIcon('heroicon-m-device-phone-mobile')
                ->color($appErrors['occurrences'] > 0 ? 'warning' : 'success'),
            Stat::make(__('admin.system_health.stats.disk'), $disk === null ? '—' : $disk['used_percent'].' %')
                ->description($disk === null
                    ? __('admin.system_health.stats.disk_unknown')
                    : __('admin.system_health.stats.disk_desc', ['free' => Number::fileSize($disk['free'], 1), 'total' => Number::fileSize($disk['total'], 1)]))
                ->descriptionIcon('heroicon-m-server')
                ->color($disk === null ? 'gray' : ($disk['used_percent'] >= (int) config('system_health.disk_warn_percent', 90) ? 'danger' : 'success')),
            Stat::make(__('admin.system_health.stats.storage'), $storage['driver'] === 's3' ? __('admin.system_health.stats.storage_s3') : __('admin.system_health.stats.storage_local'))
                ->description(match ($storage['reachable']) {
                    true => __('admin.system_health.stats.storage_bucket_ok', ['bucket' => $bucket]),
                    false => __('admin.system_health.stats.storage_bucket_down', ['bucket' => $bucket, 'error' => (string) $storage['error']]),
                    null => __('admin.system_health.stats.storage_bucket_none'),
                })
                ->descriptionIcon('heroicon-m-circle-stack')
                // Object storage live but the bucket down = media broken → red; a configured
                // but unused bucket that fails = warning (fix before switching).
                ->color(match (true) {
                    $storage['reachable'] === false => $storage['driver'] === 's3' ? 'danger' : 'warning',
                    $storage['driver'] === 's3' && $storage['reachable'] !== true => 'danger',
                    default => 'success',
                }),
        ];
    }
}
