<?php

namespace App\Filament\Widgets\SystemHealth;

use App\Models\FailedJob;
use App\Models\User;
use App\Services\SystemHealth\ErrorText;
use App\Services\SystemHealth\SystemHealthService;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Collection;

/**
 * Laravel's failed_jobs on /admin/system-health (David 2026-10-19), with
 * Retry (= `queue:retry`) and Forget (= `queue:forget`). Superadmin only.
 * The payload is never shown; the exception only scrubbed.
 */
class FailedJobsTable extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isSuperadmin();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('admin.system_health.jobs.heading'))
            ->query(FailedJob::query())
            ->defaultSort('failed_at', 'desc')
            ->defaultKeySort()
            ->paginated([10, 25, 50])
            ->columns([
                Tables\Columns\TextColumn::make('failed_at')
                    ->label(__('admin.system_health.jobs.failed_at'))
                    ->since()
                    ->dateTimeTooltip('Y-m-d H:i', 'Europe/Ljubljana')
                    ->sortable(),
                Tables\Columns\TextColumn::make('job')
                    ->label(__('admin.system_health.jobs.job'))
                    ->state(fn (FailedJob $record): string => class_basename($record->displayName()))
                    ->description(fn (FailedJob $record): string => ErrorText::scrub($record->exceptionHeadline(), 160))
                    ->wrap(),
                Tables\Columns\TextColumn::make('queue')
                    ->label(__('admin.system_health.jobs.queue'))
                    ->badge(),
            ])
            ->actions([
                Tables\Actions\Action::make('retry')
                    ->label(__('admin.system_health.jobs.retry'))
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->authorize(fn (): bool => self::canView())
                    ->action(function (FailedJob $record): void {
                        app(SystemHealthService::class)->retryFailedJob($record);
                        Notification::make()->title(__('admin.system_health.jobs.retried'))->success()->send();
                    }),
                Tables\Actions\Action::make('forget')
                    ->label(__('admin.system_health.jobs.forget'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->authorize(fn (): bool => self::canView())
                    ->action(function (FailedJob $record): void {
                        app(SystemHealthService::class)->forgetFailedJob($record);
                        Notification::make()->title(__('admin.system_health.jobs.forgotten'))->success()->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('retry')
                    ->label(__('admin.system_health.jobs.retry'))
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->authorize(fn (): bool => self::canView())
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records): void {
                        $records->each(fn (FailedJob $job) => app(SystemHealthService::class)->retryFailedJob($job));
                        Notification::make()->title(__('admin.system_health.jobs.retried'))->success()->send();
                    }),
                Tables\Actions\BulkAction::make('forget')
                    ->label(__('admin.system_health.jobs.forget'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->authorize(fn (): bool => self::canView())
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records): void {
                        $records->each(fn (FailedJob $job) => app(SystemHealthService::class)->forgetFailedJob($job));
                        Notification::make()->title(__('admin.system_health.jobs.forgotten'))->success()->send();
                    }),
            ])
            ->emptyStateHeading(__('admin.system_health.jobs.empty'))
            ->emptyStateIcon('heroicon-o-check-badge');
    }
}
