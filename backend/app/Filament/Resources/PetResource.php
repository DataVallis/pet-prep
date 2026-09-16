<?php

namespace App\Filament\Resources;

use App\Enums\BreedType;
use App\Enums\PetStateEnum;
use App\Filament\Resources\PetResource\Pages;
use App\Models\Pet;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PetResource extends Resource
{
    protected static ?string $model = Pet::class;

    protected static ?string $navigationIcon = 'heroicon-o-heart';

    protected static ?string $navigationLabel = 'Pets';

    protected static ?string $navigationGroup = 'Management';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Ownership')
                    ->schema([
                        Forms\Components\Select::make('user_id')
                            ->label('Owner')
                            ->relationship('user', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\Select::make('breed_type')
                            ->label('Breed')
                            ->options(BreedType::class)
                            ->required(),
                        Forms\Components\Select::make('pet_state')
                            ->label('State')
                            ->options(PetStateEnum::class)
                            ->required(),
                    ])
                    ->columns(3),

                Forms\Components\Section::make('Vital Levels')
                    ->schema([
                        Forms\Components\TextInput::make('hunger_level')
                            ->label('Hunger')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->required(),
                        Forms\Components\TextInput::make('thirst_level')
                            ->label('Thirst')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->required(),
                        Forms\Components\TextInput::make('energy_level')
                            ->label('Energy')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->required(),
                        Forms\Components\TextInput::make('hygiene_level')
                            ->label('Hygiene')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->required(),
                    ])
                    ->columns(4),

                Forms\Components\Section::make('Simulation State')
                    ->schema([
                        Forms\Components\Toggle::make('is_active')
                            ->label('Active'),
                        Forms\Components\Toggle::make('is_game_over')
                            ->label('Game Over'),
                        Forms\Components\Select::make('escalation_level')
                            ->label('Escalation Level')
                            ->options([
                                0 => '0 - None',
                                1 => '1 - Warning',
                                2 => '2 - Critical',
                                3 => '3 - Terminal',
                            ])
                            ->required(),
                        Forms\Components\DateTimePicker::make('illness_until')
                            ->label('Illness Until')
                            ->nullable(),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Pet DNA & Media')
                    ->schema([
                        Forms\Components\KeyValue::make('pet_dna')
                            ->label('Pet DNA')
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('current_video_url')
                            ->label('Current Video URL')
                            ->url()
                            ->maxLength(2048)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Owner')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('breed_type')
                    ->label('Breed')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('pet_state')
                    ->label('State')
                    ->badge()
                    ->colors([
                        'gray' => PetStateEnum::Idle->value,
                        'info' => PetStateEnum::Sleeping->value,
                        'warning' => PetStateEnum::LowEnergy->value,
                        'warning' => PetStateEnum::Hungry->value,
                        'danger' => PetStateEnum::Sick->value,
                        'success' => PetStateEnum::Playing->value,
                    ])
                    ->sortable(),
                Tables\Columns\TextColumn::make('hunger_level')
                    ->label('Hunger')
                    ->numeric()
                    ->color(fn (Pet $record): string => match (true) {
                        $record->hunger_level <= 25 => 'danger',
                        $record->hunger_level <= 50 => 'warning',
                        default => 'success',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('thirst_level')
                    ->label('Thirst')
                    ->numeric()
                    ->color(fn (Pet $record): string => match (true) {
                        $record->thirst_level <= 25 => 'danger',
                        $record->thirst_level <= 50 => 'warning',
                        default => 'success',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('energy_level')
                    ->label('Energy')
                    ->numeric()
                    ->color(fn (Pet $record): string => match (true) {
                        $record->energy_level <= 25 => 'danger',
                        $record->energy_level <= 50 => 'warning',
                        default => 'success',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('hygiene_level')
                    ->label('Hygiene')
                    ->numeric()
                    ->color(fn (Pet $record): string => match (true) {
                        $record->hygiene_level <= 25 => 'danger',
                        $record->hygiene_level <= 50 => 'warning',
                        default => 'success',
                    })
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_game_over')
                    ->label('Game Over')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('escalation_level')
                    ->label('Escalation')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('born_at')
                    ->label('Born At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('breed_type')
                    ->label('Breed')
                    ->options(BreedType::class),
                Tables\Filters\SelectFilter::make('pet_state')
                    ->label('State')
                    ->options(PetStateEnum::class),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active'),
                Tables\Filters\TernaryFilter::make('is_game_over')
                    ->label('Game Over'),
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
            'index' => Pages\ListPets::route('/'),
            'create' => Pages\CreatePet::route('/create'),
            'edit' => Pages\EditPet::route('/{record}/edit'),
        ];
    }
}
