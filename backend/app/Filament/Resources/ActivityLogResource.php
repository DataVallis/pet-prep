<?php

namespace App\Filament\Resources;

use App\Enums\ActivityType;
use App\Filament\Resources\ActivityLogResource\Pages;
use App\Models\ActivityLog;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ActivityLogResource extends Resource
{
    protected static ?string $model = ActivityLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Activity Log';

    protected static ?string $navigationGroup = 'Audit';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('pet_id')
                    ->label('Pet')
                    ->relationship('pet', 'id')
                    ->searchable()
                    ->preload()
                    ->required(),

                Forms\Components\Select::make('activity_type')
                    ->label('Activity Type')
                    ->options(ActivityType::class)
                    ->enum(ActivityType::class)
                    ->required(),

                Forms\Components\TextInput::make('value')
                    ->numeric()
                    ->nullable(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                Tables\Columns\TextColumn::make('pet.user.name')
                    ->label('Owner')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('activity_type')
                    ->badge()
                    ->colors([
                        'success' => ActivityType::FedPet->value,
                        'info' => ActivityType::WateredPet->value,
                        'warning' => ActivityType::WalkedPet->value,
                        'primary' => ActivityType::CleanedPoop->value,
                        'danger' => ActivityType::IgnoredWarning->value,
                    ])
                    ->sortable(),

                Tables\Columns\TextColumn::make('value')
                    ->numeric()
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->defaultKeySort()   // stable pages when rows share a created_at
            ->filters([
                Tables\Filters\SelectFilter::make('activity_type')
                    ->options(ActivityType::class),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActivityLogs::route('/'),
            'create' => Pages\CreateActivityLog::route('/create'),
            'edit' => Pages\EditActivityLog::route('/{record}/edit'),
        ];
    }
}
