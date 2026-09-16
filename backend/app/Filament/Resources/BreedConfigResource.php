<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BreedConfigResource\Pages;
use App\Models\BreedConfig;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class BreedConfigResource extends Resource
{
    protected static ?string $model = BreedConfig::class;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Breed Configs';

    protected static ?string $navigationGroup = 'Configuration';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('breed_slug')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255)
                    ->alphaDash()
                    ->helperText('Unique slug identifying the breed (e.g., mutt, border-collie).'),

                Forms\Components\TextInput::make('daily_steps_required')
                    ->required()
                    ->integer()
                    ->minValue(0)
                    ->helperText('Number of steps the child must walk each day.'),

                Forms\Components\TextInput::make('hunger_decay_rate')
                    ->required()
                    ->numeric()
                    ->step(0.01)
                    ->helperText('Hunger decay per hour as a decimal (e.g., 0.08 for -8%/hr).'),

                Forms\Components\Toggle::make('premium_unlock')
                    ->helperText('Whether this breed requires a premium/paid unlock.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                Tables\Columns\TextColumn::make('breed_slug')
                    ->badge()
                    ->colors([
                        'primary' => 'mutt',
                        'warning' => 'border-collie',
                        'success' => fn ($state): bool => ! in_array($state, ['mutt', 'border-collie'], true),
                    ])
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('daily_steps_required')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('hunger_decay_rate')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\IconColumn::make('premium_unlock')
                    ->boolean(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
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
            'index' => Pages\ListBreedConfigs::route('/'),
            'create' => Pages\CreateBreedConfig::route('/create'),
            'edit' => Pages\EditBreedConfig::route('/{record}/edit'),
        ];
    }
}
