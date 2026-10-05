<?php

namespace App\Filament\Resources\BreedStageParamResource\RelationManagers;

use App\Models\BreedStageParamChange;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only audit of a life-stage value (M5-R01): who changed what, when.
 */
class ChangesRelationManager extends RelationManager
{
    protected static string $relationship = 'auditTrail';

    protected static ?string $title = 'Changes';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $json = fn (?array $v): string => $v === null ? '—' : (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->dateTime(),
                Tables\Columns\TextColumn::make('action')->badge(),
                Tables\Columns\TextColumn::make('by')->label('By')->state(fn (BreedStageParamChange $r): string => $r->user?->name ?? $r->actor ?? 'system'),
                Tables\Columns\TextColumn::make('old')->state(fn (BreedStageParamChange $r): string => $json($r->old))->wrap(),
                Tables\Columns\TextColumn::make('new')->state(fn (BreedStageParamChange $r): string => $json($r->new))->wrap(),
            ]);
    }
}
