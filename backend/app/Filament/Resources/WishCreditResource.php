<?php

namespace App\Filament\Resources;

use App\Enums\ChallengeCreditRevokeReason;
use App\Filament\Resources\WishCreditResource\Pages;
use App\Models\WishCredit;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Wish credits (M6-W02 »Želje«) — read only. Written only by the RevenueCat
 * webhook and the parent's redeem call (WishCreditService).
 */
class WishCreditResource extends Resource
{
    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.payments');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.wish_credits');
    }

    public static function getModelLabel(): string
    {
        return __('admin.navigation.wish_credit_model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.navigation.wish_credits');
    }

    protected static ?string $model = WishCredit::class;

    protected static ?string $navigationIcon = 'heroicon-o-gift';

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
                Tables\Columns\TextColumn::make('pet_id')->label('Pet')->placeholder('—')->sortable(),
                Tables\Columns\TextColumn::make('assigned_via')->badge()->placeholder('unassigned'),
                Tables\Columns\TextColumn::make('product_id')->label('Product'),
                Tables\Columns\TextColumn::make('item_key')->label('Item')->badge(),
                Tables\Columns\TextColumn::make('store')->placeholder('—'),
                Tables\Columns\TextColumn::make('environment')->badge()->placeholder('—'),
                Tables\Columns\TextColumn::make('purchased_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('assigned_at')->dateTime()->placeholder('—')->sortable(),
                Tables\Columns\TextColumn::make('revoked_at')->dateTime()->placeholder('—')->sortable(),
                Tables\Columns\TextColumn::make('revoke_reason')->badge()
                    ->formatStateUsing(fn (?ChallengeCreditRevokeReason $state) => $state?->value ?? '—'),
                Tables\Columns\TextColumn::make('transferred_from_family_id')->label('Transferred from')->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('purchased_at', 'desc')
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
            'index' => Pages\ListWishCredits::route('/'),
        ];
    }
}
