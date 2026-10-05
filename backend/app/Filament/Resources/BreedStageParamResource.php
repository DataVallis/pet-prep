<?php

namespace App\Filament\Resources;

use App\Enums\LifeStage;
use App\Enums\StageParamKey;
use App\Filament\Resources\BreedStageParamResource\Pages;
use App\Filament\Resources\BreedStageParamResource\RelationManagers\ChangesRelationManager;
use App\Models\BreedConfig;
use App\Models\BreedStageParam;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Sourced life-stage data (M5-R01, `breed_stage_params`). One row = one value
 * with its source id (docs/research/dog-data/sources.md), confidence and
 * `verified` flag — unverified rows are UNSOURCED proposals and are shown
 * as such. Edits are allowed and audited (who, old → new) in the "Changes"
 * panel; the deploy seeder never overwrites an existing row.
 */
class BreedStageParamResource extends Resource
{
    protected static ?string $model = BreedStageParam::class;

    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationLabel = 'Life-stage data';

    protected static ?string $navigationGroup = 'Configuration';

    protected static ?string $modelLabel = 'life-stage value';

    /**
     * JSON text of a value for the form.
     */
    public static function valueToForm(mixed $value): string
    {
        return $value === null ? 'null' : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function valueFromForm(?string $text): mixed
    {
        return json_decode(trim((string) $text), true);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Value')
                ->schema([
                    Forms\Components\Select::make('breed_slug')
                        ->label('Breed')
                        ->options(fn (): array => BreedConfig::query()->pluck('breed_slug', 'breed_slug')->all())
                        ->required(),
                    Forms\Components\Select::make('stage')
                        ->options(array_merge(
                            array_combine(array_map(fn (LifeStage $s) => $s->value, LifeStage::ordered()), array_map(fn (LifeStage $s) => $s->value, LifeStage::ordered())),
                            [BreedStageParam::STAGE_ALL => 'all (breed level)'],
                        ))
                        ->required(),
                    Forms\Components\TextInput::make('age_from_months')
                        ->label('From age (months)')
                        ->helperText('Sub-band inside the stage (e.g. puppy meals 0 / 3 / 6). 0 = whole stage.')
                        ->integer()->minValue(0)->maxValue(600)->default(0)->required(),
                    Forms\Components\Select::make('key')
                        ->options(array_combine(
                            array_map(fn (StageParamKey $k) => $k->value, StageParamKey::cases()),
                            array_map(fn (StageParamKey $k) => $k->value, StageParamKey::cases()),
                        ))
                        ->required()
                        ->live(),
                    Forms\Components\Textarea::make('value')
                        ->label('Value (JSON)')
                        ->helperText('Number, [min, max], [["HH:MM","HH:MM"], …] for feed windows, or null.')
                        ->rows(3)
                        ->required()
                        ->rules([
                            fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                $decoded = json_decode(trim((string) $value), true);
                                if ($decoded === null && strtolower(trim((string) $value)) !== 'null') {
                                    $fail('Not valid JSON.');

                                    return;
                                }
                                $key = StageParamKey::tryFrom((string) $get('key'));
                                if ($key !== null && ($error = $key->validate($decoded)) !== null) {
                                    $fail($error);
                                }
                            },
                        ])
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('unit')->maxLength(255),
                ])
                ->columns(2),

            Forms\Components\Section::make('Provenance')
                ->schema([
                    Forms\Components\TextInput::make('source_id')
                        ->label('Source id(s)')
                        ->helperText('From docs/research/dog-data/sources.md, e.g. S18 or S11,S15. Empty = unsourced.')
                        ->regex('/^S\d+(,S\d+)*$/')
                        ->maxLength(255),
                    Forms\Components\Select::make('confidence')
                        ->options(['high' => 'high', 'medium' => 'medium', 'low' => 'low'])
                        ->required(),
                    Forms\Components\Toggle::make('verified')
                        ->helperText('Off = UNSOURCED proposal: never shown to parents / children as a fact.'),
                    Forms\Components\TextInput::make('data_ref')
                        ->label('data.json path')
                        ->maxLength(255),
                    Forms\Components\Textarea::make('quote')->rows(2)->columnSpanFull(),
                    Forms\Components\Textarea::make('notes')->rows(3)->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('breed_slug')
            ->columns([
                Tables\Columns\TextColumn::make('breed_slug')->label('Breed')->badge()->sortable()->searchable(),
                Tables\Columns\TextColumn::make('stage')->badge()->sortable(),
                Tables\Columns\TextColumn::make('age_from_months')->label('From (mo)')->numeric()->sortable(),
                Tables\Columns\TextColumn::make('key')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('value')
                    ->state(fn (BreedStageParam $record): string => self::valueToForm($record->value))
                    ->wrap(),
                Tables\Columns\TextColumn::make('unit')->toggleable(),
                Tables\Columns\TextColumn::make('source_id')->label('Source')->placeholder('none'),
                Tables\Columns\TextColumn::make('confidence')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'high' => 'success',
                        'medium' => 'warning',
                        default => 'danger',
                    }),
                Tables\Columns\TextColumn::make('verified')
                    ->label('Status')
                    ->badge()
                    // A verified row without a source is a recorded decision (e.g. David 2026-10-05), not literature.
                    ->state(fn (BreedStageParam $record): string => $record->verified ? ($record->source_id === null ? 'verified — decision' : 'verified') : 'UNSOURCED — proposal')
                    ->color(fn (BreedStageParam $record): string => $record->verified ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('editor.name')->label('Last edited by')->placeholder('seeder')->toggleable(),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('breed_slug')
                    ->label('Breed')
                    ->options(fn (): array => BreedConfig::query()->pluck('breed_slug', 'breed_slug')->all()),
                Tables\Filters\SelectFilter::make('stage')
                    ->options(['puppy' => 'puppy', 'young' => 'young', 'adult' => 'adult', 'senior' => 'senior', 'all' => 'all']),
                Tables\Filters\TernaryFilter::make('verified')->label('Verified'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ChangesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBreedStageParams::route('/'),
            'create' => Pages\CreateBreedStageParam::route('/create'),
            'edit' => Pages\EditBreedStageParam::route('/{record}/edit'),
        ];
    }
}
