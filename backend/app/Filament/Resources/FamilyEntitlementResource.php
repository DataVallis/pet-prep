<?php

namespace App\Filament\Resources;

use App\Enums\EntitlementRevokeReason;
use App\Filament\Resources\FamilyEntitlementResource\Pages;
use App\Models\FamilyEntitlement;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Family entitlements (M3-08) — read only. Written only by the RevenueCat
 * webhook through EntitlementService.
 */
class FamilyEntitlementResource extends Resource
{
    protected static ?string $model = FamilyEntitlement::class;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationLabel = 'Family entitlements';

    protected static ?string $navigationGroup = 'Payments';

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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('family_id')->label('Family')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('entitlement')->badge(),
                Tables\Columns\IconColumn::make('active')->boolean()
                    ->getStateUsing(fn (FamilyEntitlement $record): bool => $record->isActive()),
                Tables\Columns\TextColumn::make('product_id')->label('Product')->placeholder('—'),
                Tables\Columns\TextColumn::make('store')->placeholder('—'),
                Tables\Columns\TextColumn::make('environment')->badge()->placeholder('—'),
                Tables\Columns\TextColumn::make('granted_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('expires_at')->dateTime()->placeholder('lifetime')->sortable(),
                Tables\Columns\TextColumn::make('revoked_at')->dateTime()->placeholder('—')->sortable(),
                Tables\Columns\TextColumn::make('revoke_reason')->badge()
                    ->formatStateUsing(fn (?EntitlementRevokeReason $state) => $state?->value ?? '—'),
                Tables\Columns\TextColumn::make('last_event_id')->label('Last event')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('granted_at', 'desc')
            ->defaultKeySort()
            ->filters([
                Tables\Filters\TernaryFilter::make('revoked')
                    ->nullable()
                    ->attribute('revoked_at')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('revoked_at'),
                        false: fn (Builder $q) => $q->whereNull('revoked_at'),
                    ),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFamilyEntitlements::route('/'),
        ];
    }
}
