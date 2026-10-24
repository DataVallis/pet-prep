<?php

namespace App\Filament\Widgets\SystemHealth;

use App\Enums\AppErrorScope;
use App\Models\AppErrorFingerprint;
use App\Models\AppErrorReport;
use App\Models\User;
use App\Services\AppErrors\AppErrorReportService;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * M5-05 »App errors« on /admin/system-health (superadmin only): the mobile
 * app's crash / render-error fingerprints with occurrences in the last 24 h
 * or 7 d (hourly buckets), app versions and platforms; Details shows every
 * (version, platform) row with its latest scrubbed sample. Read-only — the
 * rows expire after 30 days (`system-health:prune`).
 */
class AppErrorsTable extends TableWidget
{
    protected static bool $isDiscovered = false;

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

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('admin.app_errors.heading'))
            ->description(__('admin.app_errors.description'))
            ->query(AppErrorFingerprint::query()->with('reports'))
            // The window is applied here (not only in the filter) so `window_count` always exists for the sort.
            ->modifyQueryUsing(fn (Builder $query): Builder => self::applyWindow($query, (int) ($this->tableFilters['window']['value'] ?? 24)))
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('window_count')->orderByDesc('last_seen_at'))
            ->paginated([10, 25, 50])
            ->poll('60s')
            ->columns([
                Tables\Columns\TextColumn::make('error_name')
                    ->label(__('admin.app_errors.error'))
                    ->description(fn (AppErrorFingerprint $record): string => Str::limit($record->message, 140))
                    ->wrap()
                    ->searchable(['error_name', 'message']),
                Tables\Columns\TextColumn::make('window_count')
                    ->label(__('admin.app_errors.in_window'))
                    ->numeric(),
                Tables\Columns\TextColumn::make('occurrences')
                    ->label(__('admin.app_errors.total'))
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('scope')
                    ->label(__('admin.app_errors.scope'))
                    ->badge(),
                Tables\Columns\TextColumn::make('versions')
                    ->label(__('admin.app_errors.versions'))
                    ->state(fn (AppErrorFingerprint $record): string => $record->reports->pluck('app_version')->unique()->sort()->implode(', ')),
                Tables\Columns\TextColumn::make('platforms')
                    ->label(__('admin.app_errors.platforms'))
                    ->state(fn (AppErrorFingerprint $record): string => $record->reports->pluck('platform')->unique()->sort()->implode(', ')),
                Tables\Columns\TextColumn::make('last_seen_at')
                    ->label(__('admin.app_errors.last_seen'))
                    ->since()
                    ->dateTimeTooltip('Y-m-d H:i', 'Europe/Ljubljana')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('window')
                    ->label(__('admin.app_errors.window'))
                    ->options([
                        '24' => __('admin.app_errors.window_24h'),
                        '168' => __('admin.app_errors.window_7d'),
                    ])
                    ->default('24')
                    ->selectablePlaceholder(false)
                    ->query(fn (Builder $query): Builder => $query),
                Tables\Filters\SelectFilter::make('scope')
                    ->label(__('admin.app_errors.scope'))
                    ->options(collect(AppErrorScope::cases())->mapWithKeys(fn (AppErrorScope $s): array => [$s->value => $s->value])->all()),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label(__('admin.app_errors.view'))
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn (AppErrorFingerprint $record): string => $record->error_name)
                    ->modalContent(fn (AppErrorFingerprint $record) => view('filament.pages.system-health.app-error-details', [
                        'error' => $record,
                        'reports' => $record->reports->sortByDesc('last_seen_at')->values(),
                        'last24' => app(AppErrorReportService::class)->countSince($record->fingerprint, 24),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('admin.system_health.close'))
                    ->modalWidth('5xl'),
            ])
            ->emptyStateHeading(__('admin.app_errors.empty'))
            ->emptyStateIcon('heroicon-o-check-badge');
    }

    /**
     * Only fingerprints seen in the window, with `window_count` = their
     * occurrences in it (hourly buckets; 24 or 168 hours).
     *
     * @param  Builder<AppErrorFingerprint>  $query
     * @return Builder<AppErrorFingerprint>
     */
    public static function applyWindow(Builder $query, int $hours): Builder
    {
        $hours = in_array($hours, [24, 168], true) ? $hours : 24;
        $from = AppErrorReportService::windowStart($hours);
        $sum = DB::table('app_error_hours')
            ->selectRaw('SUM(app_error_hours.occurrences)')
            ->whereColumn('app_error_hours.fingerprint', 'app_error_fingerprints.fingerprint')
            ->where('app_error_hours.hour', '>=', $from);

        return $query
            ->select('app_error_fingerprints.*')
            ->selectSub($sum, 'window_count')
            ->whereExists(fn ($q) => $q->from('app_error_hours')
                ->whereColumn('app_error_hours.fingerprint', 'app_error_fingerprints.fingerprint')
                ->where('app_error_hours.hour', '>=', $from));
    }

    /** Roles of one per-version row: "parent / child / signed out". */
    public static function roles(AppErrorReport $report): string
    {
        return "{$report->parent_count} / {$report->child_count} / {$report->anonymous_count}";
    }
}
