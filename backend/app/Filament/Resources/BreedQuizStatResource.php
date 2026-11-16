<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BreedQuizStatResource\Pages;
use App\Models\BreedQuizStat;
use App\Models\User;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Anonymous counters of the petprep.si breed quiz (M6-S06) — superadmin only,
 * read-only. Written only by BreedQuizStatService (POST /api/breed-quiz/events).
 */
class BreedQuizStatResource extends Resource
{
    protected static ?string $model = BreedQuizStat::class;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.management');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.breed_quiz_stats');
    }

    public static function getModelLabel(): string
    {
        return __('admin.navigation.breed_quiz_stat_model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.navigation.breed_quiz_stats');
    }

    private static function isSuperadmin(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isSuperadmin();
    }

    public static function canAccess(): bool
    {
        return self::isSuperadmin();
    }

    public static function canViewAny(): bool
    {
        return self::isSuperadmin();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->description(__('admin.breed_quiz_stats.note'))
            ->columns([
                Tables\Columns\TextColumn::make('day')->label(__('admin.breed_quiz_stats.day'))->date('Y-m-d')->sortable(),
                Tables\Columns\TextColumn::make('event')->label(__('admin.breed_quiz_stats.event'))->sortable(),
                Tables\Columns\TextColumn::make('breed')->label(__('admin.breed_quiz_stats.breed'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('rank')->label(__('admin.breed_quiz_stats.rank'))->placeholder('—')->sortable(),
                Tables\Columns\TextColumn::make('locale')->label(__('admin.breed_quiz_stats.locale'))->sortable(),
                Tables\Columns\TextColumn::make('count')->label(__('admin.breed_quiz_stats.count'))->numeric()->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label(__('admin.breed_quiz_stats.total'))),
            ])
            ->defaultSort('day', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('event')->options(array_combine(BreedQuizStat::EVENTS, BreedQuizStat::EVENTS)),
                Tables\Filters\SelectFilter::make('locale')
                    ->options(fn (): array => BreedQuizStat::query()->distinct()->orderBy('locale')->pluck('locale', 'locale')->all()),
                Tables\Filters\Filter::make('day')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label(__('admin.breed_quiz_stats.from')),
                        Forms\Components\DatePicker::make('until')->label(__('admin.breed_quiz_stats.until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $d): Builder => $q->whereDate('day', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, string $d): Builder => $q->whereDate('day', '<=', $d))),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBreedQuizStats::route('/'),
        ];
    }
}
