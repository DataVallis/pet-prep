<?php

namespace App\Filament\Resources;

use App\Enums\BreedType;
use App\Enums\PetStateEnum;
use App\Filament\Resources\PetResource\Pages;
use App\Models\Pet;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PetResource extends Resource
{
    protected static ?string $model = Pet::class;

    /**
     * Metric field: shows the displayed integer and is only saved when the
     * admin actually changed that integer, so saving other fields never
     * overwrites the precise stored value with a rounded one.
     */
    public static function metricInput(string $metric, string $label): Forms\Components\TextInput
    {
        return Forms\Components\TextInput::make($metric)
            ->label($label)
            ->numeric()
            ->formatStateUsing(fn ($state): ?int => $state === null ? null : Pet::displayValue($state))
            ->dehydrated(fn (?Pet $record, $state): bool => $record === null
                || $state === null
                || (float) $state !== (float) $record->displayMetric($metric))
            ->minValue(0)
            ->maxValue(100)
            ->required();
    }

    /**
     * Metric column: displayed integer, colour thresholds on the displayed value.
     */
    public static function metricColumn(string $metric, string $label): Tables\Columns\TextColumn
    {
        return Tables\Columns\TextColumn::make($metric)
            ->label($label)
            ->formatStateUsing(fn ($state): int => Pet::displayValue($state))
            ->color(fn (Pet $record): string => match (true) {
                $record->displayMetric($metric) <= 25 => 'danger',
                $record->displayMetric($metric) <= 50 => 'warning',
                default => 'success',
            })
            ->sortable();
    }

    protected static ?string $navigationIcon = 'heroicon-o-heart';

    protected static ?string $navigationLabel = 'Pets';

    protected static ?string $navigationGroup = 'Management';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Ownership')
                    ->schema([
                        Forms\Components\Placeholder::make('family_caretakers')
                            ->label('Family / caretakers')
                            ->content(fn (?Pet $record): string => $record === null
                                ? 'Set by the owner\'s family'
                                : "#{$record->family_id} — ".($record->caretakers()->pluck('name')->implode(', ') ?: '—')),
                        Forms\Components\Select::make('user_id')
                            ->label('Owner (primary caretaker)')
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
                        self::metricInput('hunger_level', 'Hunger'),
                        self::metricInput('thirst_level', 'Thirst'),
                        self::metricInput('energy_level', 'Energy'),
                        self::metricInput('hygiene_level', 'Hygiene'),
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
                // Family model (M2-01): family + every caretaker child.
                Tables\Columns\TextColumn::make('family_id')
                    ->label('Family')
                    ->sortable(),
                Tables\Columns\TextColumn::make('caretakers.name')
                    ->label('Caretakers')
                    ->badge()
                    ->placeholder('—'),
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
                self::metricColumn('hunger_level', 'Hunger'),
                self::metricColumn('thirst_level', 'Thirst'),
                self::metricColumn('energy_level', 'Energy'),
                self::metricColumn('hygiene_level', 'Hygiene'),
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
                Tables\Columns\TextColumn::make('media_status')
                    ->label('Media')
                    ->badge()
                    ->description(fn (Pet $record): ?string => $record->media_error)
                    ->color(fn (string $state): string => match ($state) {
                        'ready' => 'success',
                        'failed' => 'danger',
                        'pending' => 'warning',
                        default => 'gray',
                    })
                    ->toggleable(),
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
