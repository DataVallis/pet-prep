<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SummaryLeadResource\Pages;
use App\Models\SummaryLead;
use App\Models\User;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Parent e-mails from the page »Koliko dela je pes« (M6-S05) — superadmin
 * only, list + delete (a parent who asks to be removed). Written only by
 * SummaryLeadService (POST /api/summary-leads); never deleted automatically.
 */
class SummaryLeadResource extends Resource
{
    protected static ?string $model = SummaryLead::class;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.management');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.summary_leads');
    }

    public static function getModelLabel(): string
    {
        return __('admin.navigation.summary_lead_model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.navigation.summary_leads');
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
        return self::isSuperadmin();
    }

    public static function canDeleteAny(): bool
    {
        return self::isSuperadmin();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->description(__('admin.summary_leads.retention_note'))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label(__('admin.summary_leads.first_seen'))->dateTime('Y-m-d H:i', 'Europe/Ljubljana')->sortable(),
                Tables\Columns\TextColumn::make('email')->label(__('admin.summary_leads.email'))->searchable()->copyable(),
                Tables\Columns\TextColumn::make('breed')->label(__('admin.summary_leads.breed'))->placeholder('—')->sortable(),
                Tables\Columns\TextColumn::make('stage')->label(__('admin.summary_leads.stage'))->placeholder('—'),
                Tables\Columns\TextColumn::make('locale')->label(__('admin.summary_leads.locale'))->placeholder('—'),
                Tables\Columns\TextColumn::make('visits')->label(__('admin.summary_leads.visits'))->numeric()->sortable(),
                Tables\Columns\TextColumn::make('last_seen_at')->label(__('admin.summary_leads.last_seen'))->dateTime('Y-m-d H:i', 'Europe/Ljubljana')->sortable(),
                Tables\Columns\TextColumn::make('consent_version')->label(__('admin.summary_leads.consent'))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('breed')
                    ->options(fn (): array => SummaryLead::query()->whereNotNull('breed')->distinct()->orderBy('breed')->pluck('breed', 'breed')->all()),
                Tables\Filters\SelectFilter::make('locale')
                    ->options(fn (): array => SummaryLead::query()->whereNotNull('locale')->distinct()->orderBy('locale')->pluck('locale', 'locale')->all()),
            ])
            ->actions([
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSummaryLeads::route('/'),
        ];
    }
}
