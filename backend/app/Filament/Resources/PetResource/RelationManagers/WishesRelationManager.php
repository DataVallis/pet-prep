<?php

namespace App\Filament\Resources\PetResource\RelationManagers;

use App\Enums\WishStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * M6-W01 »Želje«: the children's wishes for this pet — read only (written by
 * WishService / WishCreditService).
 */
class WishesRelationManager extends RelationManager
{
    protected static string $relationship = 'wishes';

    protected static ?string $title = 'Wishes';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('item_key')->label('Item')->badge(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (?WishStatus $state) => $state?->value ?? '—'),
                Tables\Columns\TextColumn::make('child_user_id')->label('Child'),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('decided_at')->dateTime()->placeholder('—'),
                Tables\Columns\TextColumn::make('decided_by_user_id')->label('Decided by')->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->defaultKeySort()
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
