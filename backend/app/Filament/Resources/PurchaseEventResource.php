<?php

namespace App\Filament\Resources;

use App\Enums\PurchaseEventOutcome;
use App\Filament\Resources\PurchaseEventResource\Pages;
use App\Models\PurchaseEvent;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * RevenueCat webhook ledger (M3-08) — read only. Rows are written only by
 * RevenueCatWebhookService; the raw payload stays out of the table.
 */
class PurchaseEventResource extends Resource
{
    protected static ?string $model = PurchaseEvent::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationLabel = 'Purchase events';

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
                Tables\Columns\TextColumn::make('created_at')->label('Received')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('type')->badge()->searchable(),
                Tables\Columns\TextColumn::make('outcome')->badge()
                    ->formatStateUsing(fn (?PurchaseEventOutcome $state) => $state?->value ?? '—'),
                Tables\Columns\TextColumn::make('family_id')->label('Family')->placeholder('—')->sortable(),
                Tables\Columns\TextColumn::make('app_user_id')->label('App user')->searchable()->placeholder('—'),
                Tables\Columns\TextColumn::make('product_id')->label('Product')->searchable()->placeholder('—'),
                Tables\Columns\TextColumn::make('store')->placeholder('—'),
                Tables\Columns\TextColumn::make('environment')->badge()->placeholder('—'),
                Tables\Columns\TextColumn::make('expiration_at')->dateTime()->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('event_id')->label('RevenueCat event')->searchable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('transaction_id')->searchable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->defaultKeySort()
            ->filters([
                Tables\Filters\SelectFilter::make('outcome')->options(PurchaseEventOutcome::class),
                Tables\Filters\SelectFilter::make('environment')->options(['PRODUCTION' => 'PRODUCTION', 'SANDBOX' => 'SANDBOX']),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchaseEvents::route('/'),
        ];
    }
}
