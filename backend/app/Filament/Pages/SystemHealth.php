<?php

namespace App\Filament\Pages;

use App\Enums\AdminAlertKind;
use App\Filament\Widgets\AiBudgetBanner;
use App\Filament\Widgets\SystemHealth\ErrorsPerHourChart;
use App\Filament\Widgets\SystemHealth\FailedJobsTable;
use App\Filament\Widgets\SystemHealth\SystemHealthStats;
use App\Models\SystemError;
use App\Models\User;
use App\Services\Alerts\AdminAlert;
use App\Services\Alerts\DiscordWebhook;
use App\Services\Alerts\DiscordWebhookException;
use App\Services\SystemHealth\SystemHealthService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * System health (David 2026-10-19, superadmin only): whether production has
 * errors, at a glance and without SSH — status tiles (errors, failed jobs,
 * queue backlog, stuck routine ledgers, AI media failures, scheduler
 * heartbeat, disk), the AI budget / fal balance banner (M5-T15), errors per hour, the error list (from `system_errors`,
 * written by ErrorRecorder) with details + resolve, and failed jobs with
 * retry / forget. Chosen over Sentry / Grafana: no external service and no
 * error data leaves the server. M5-T19: header action »Test Discord« posts one
 * test message to the admin Discord channel (superadmin only, shows the result;
 * the webhook URL is never shown).
 */
class SystemHealth extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-heart';

    protected static ?string $slug = 'system-health';

    protected static ?int $navigationSort = -1;

    protected static string $view = 'filament.pages.system-health';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.system');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.system_health.navigation');
    }

    public function getTitle(): string
    {
        return __('admin.system_health.title');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isSuperadmin();
    }

    /** One COUNT per request for the badge and its colour. */
    private static ?string $badge = null;

    private static ?int $badgeRequest = null;

    public static function getNavigationBadge(): ?string
    {
        $request = spl_object_id(request());
        if (self::$badgeRequest !== $request) {
            self::$badgeRequest = $request;
            try {
                self::$badge = (string) app(SystemHealthService::class)->openErrorCount(24);
            } catch (Throwable) {
                self::$badge = null;
            }
        }

        return self::$badge;
    }

    public static function getNavigationBadgeColor(): string
    {
        return static::getNavigationBadge() === '0' ? 'success' : 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('admin.system_health.badge_tooltip');
    }

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    private function authorizeAccess(): void
    {
        if (! static::canAccess()) {
            throw new AuthorizationException;
        }
    }

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('testDiscord')
                ->label(__('admin.system_health.discord.test'))
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('gray')
                ->authorize(fn (): bool => static::canAccess())
                ->requiresConfirmation()
                ->modalHeading(__('admin.system_health.discord.test_heading'))
                ->modalDescription(__('admin.system_health.discord.test_description'))
                ->action(fn () => $this->sendDiscordTest()),
        ];
    }

    public function sendDiscordTest(): void
    {
        $this->authorizeAccess();
        $discord = app(DiscordWebhook::class);

        if (! $discord->configured()) {
            Notification::make()
                ->title(__('admin.system_health.discord.not_configured'))
                ->body(__('admin.system_health.discord.not_configured_body'))
                ->warning()
                ->send();

            return;
        }

        try {
            $discord->send(new AdminAlert(
                AdminAlertKind::Test,
                __('admin.system_health.discord.test_title'),
                __('admin.system_health.discord.test_body'),
            ));
        } catch (DiscordWebhookException $e) {
            report($e);
            Notification::make()
                ->title(__('admin.system_health.discord.failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('admin.system_health.discord.sent'))
            ->success()
            ->send();
    }

    protected function getHeaderWidgets(): array
    {
        // M5-T15: AI budget / fal balance banner first.
        return [AiBudgetBanner::class, SystemHealthStats::class, ErrorsPerHourChart::class];
    }

    protected function getFooterWidgets(): array
    {
        return [FailedJobsTable::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return 1;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('admin.system_health.errors.heading'))
            ->description(__('admin.system_health.errors.description'))
            ->query(SystemError::query())
            ->defaultSort('last_seen_at', 'desc')
            ->defaultKeySort()
            ->poll('60s')
            ->columns([
                Tables\Columns\IconColumn::make('resolved_at')
                    ->label('')
                    ->state(fn (SystemError $record): bool => $record->isResolved())
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-exclamation-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),
                Tables\Columns\TextColumn::make('last_seen_at')
                    ->label(__('admin.system_health.errors.last_seen'))
                    ->since()
                    ->dateTimeTooltip('Y-m-d H:i', 'Europe/Ljubljana')
                    ->sortable(),
                Tables\Columns\TextColumn::make('occurrences')
                    ->label(__('admin.system_health.errors.occurrences'))
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('exception_class')
                    ->label(__('admin.system_health.errors.error'))
                    ->formatStateUsing(fn (?string $state, SystemError $record): string => $state !== null ? class_basename($state) : strtoupper($record->level))
                    ->description(fn (SystemError $record): string => Str::limit($record->message, 140))
                    ->wrap()
                    ->searchable(['message', 'exception_class', 'file']),
                Tables\Columns\TextColumn::make('source')
                    ->label(__('admin.system_health.errors.where'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("admin.system_health.sources.{$state}"))
                    ->description(fn (SystemError $record): string => Str::limit($record->contextSummary(), 60)),
                Tables\Columns\TextColumn::make('first_seen_at')
                    ->label(__('admin.system_health.errors.first_seen'))
                    ->dateTime('Y-m-d H:i', 'Europe/Ljubljana')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('admin.system_health.errors.status'))
                    ->options([
                        'open' => __('admin.system_health.errors.open'),
                        'resolved' => __('admin.system_health.errors.resolved'),
                    ])
                    ->default('open')
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'open' => $query->whereNull('resolved_at'),
                        'resolved' => $query->whereNotNull('resolved_at'),
                        default => $query,
                    }),
                Tables\Filters\SelectFilter::make('source')
                    ->label(__('admin.system_health.errors.where'))
                    ->options(collect(['http', 'command', 'job', 'log', 'other'])->mapWithKeys(fn (string $s): array => [$s => __("admin.system_health.sources.{$s}")])->all()),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label(__('admin.system_health.errors.view'))
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn (SystemError $record): string => $record->exception_class !== null ? class_basename($record->exception_class) : strtoupper($record->level))
                    ->modalContent(fn (SystemError $record) => view('filament.pages.system-health.error-details', ['error' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('admin.system_health.close'))
                    ->modalWidth('5xl'),
                Tables\Actions\Action::make('resolve')
                    ->label(__('admin.system_health.errors.resolve'))
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (SystemError $record): bool => ! $record->isResolved())
                    ->authorize(fn (): bool => static::canAccess())
                    ->action(function (SystemError $record): void {
                        $this->resolveErrors([$record]);
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('resolve')
                    ->label(__('admin.system_health.errors.resolve_selected'))
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->authorize(fn (): bool => static::canAccess())
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records): void {
                        $this->resolveErrors($records);
                    }),
            ])
            ->emptyStateHeading(__('admin.system_health.errors.empty'))
            ->emptyStateIcon('heroicon-o-check-badge');
    }

    /**
     * @param  iterable<SystemError>  $records
     */
    private function resolveErrors(iterable $records): void
    {
        $this->authorizeAccess();
        /** @var User $user */
        $user = auth()->user();

        $count = app(SystemHealthService::class)->resolve($records, $user);

        Notification::make()
            ->title(__('admin.system_health.errors.resolved_notice', ['count' => $count]))
            ->success()
            ->send();
    }
}
