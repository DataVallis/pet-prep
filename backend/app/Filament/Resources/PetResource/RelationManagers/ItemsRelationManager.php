<?php

namespace App\Filament\Resources\PetResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * M6-W01 »Želje«: items the pet owns (treat packs with what is left; revoked =
 * store refund) — read only.
 */
class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Items';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('item_key')->label('Item')->badge(),
                Tables\Columns\TextColumn::make('variant'),
                Tables\Columns\TextColumn::make('quantity_left')->label('Left')
                    ->formatStateUsing(fn ($state, $record) => $record->kind === 'consumable' ? $state.' / '.$record->quantity_total : '—'),
                Tables\Columns\TextColumn::make('wish_credit_id')->label('Credit')->placeholder('—'),
                Tables\Columns\TextColumn::make('acquired_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('revoked_at')->dateTime()->placeholder('—'),
            ])
            ->defaultSort('acquired_at', 'desc')
            ->defaultKeySort()
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
