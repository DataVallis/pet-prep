<?php

namespace App\Filament\Resources;

use App\Enums\BreedType;
use App\Enums\PetStateEnum;
use App\Filament\Resources\PetResource\Pages;
use App\Filament\Resources\PetResource\RelationManagers;
use App\Models\Pet;
use App\Services\Media\ReferenceImageRetryService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

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

                // M5-R01: parent's choice at creation + age / stage (read-only; the
                // stage follows the age and the sourced breed_stage_params).
                Forms\Components\Section::make('Profile (origin, age, life stage)')
                    ->schema([
                        Forms\Components\Placeholder::make('origin_view')
                            ->label('Origin')
                            ->content(fn (?Pet $record): string => $record?->origin?->value ?? '—'),
                        Forms\Components\Placeholder::make('arrival_age_view')
                            ->label('Age at arrival (months)')
                            ->content(fn (?Pet $record): string => $record !== null ? (string) $record->arrival_age_months : '—'),
                        Forms\Components\Placeholder::make('age_view')
                            ->label('Age now (months)')
                            ->content(fn (?Pet $record): string => $record !== null ? (string) $record->ageMonths() : '—'),
                        Forms\Components\Placeholder::make('life_stage_view')
                            ->label('Life stage (last tick)')
                            ->content(fn (?Pet $record): string => $record?->life_stage?->value ?? '—'),
                    ])
                    ->columns(4)
                    ->visibleOn('edit'),

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
                        // Read-only (PR #22 review): DNA v2 is nested JSON (traits, prompt…) that a
                        // KeyValue field would flatten / overwrite on save. Never edited here.
                        Forms\Components\Placeholder::make('pet_dna_view')
                            ->label('Pet DNA (read-only)')
                            ->content(fn (?Pet $record): HtmlString => new HtmlString(
                                '<pre style="white-space:pre-wrap;font-size:12px;max-height:24rem;overflow:auto">'
                                .e((string) json_encode($record?->pet_dna, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
                                .'</pre>'
                            ))
                            ->columnSpanFull(),
                        Forms\Components\Placeholder::make('media_error_view')
                            ->label('Media error')
                            ->content(fn (?Pet $record): string => $record?->media_error ?? '—'),
                        // Media files, status, cost and regeneration: "AI media" panel below (M4-05).
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
                Tables\Columns\TextColumn::make('life_stage')
                    ->label('Stage')
                    ->badge()
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('origin')
                    ->label('Origin')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
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
                // PR #22 review: re-queue a reference image that failed (budget, fal balance, errors).
                Tables\Actions\Action::make('retryMedia')
                    ->label('Retry image')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->modalDescription('Queues the reference image again. It still passes the AI budget check.')
                    ->visible(fn (Pet $record): bool => app(ReferenceImageRetryService::class)->canRetry($record))
                    ->action(function (Pet $record): void {
                        $queued = app(ReferenceImageRetryService::class)->retry($record);

                        Notification::make()
                            ->title($queued ? 'Reference image queued' : 'Cannot retry this pet')
                            ->status($queued ? 'success' : 'warning')
                            ->send();
                    }),
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
            RelationManagers\MediaRelationManager::class,
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
