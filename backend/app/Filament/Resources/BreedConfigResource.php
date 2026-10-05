<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BreedConfigResource\Pages;
use App\Models\BreedConfig;
use Closure;
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

    /**
     * 24-hour HH:MM.
     */
    public const TIME_REGEX = '/^([01]\d|2[0-3]):[0-5]\d$/';

    /**
     * breed_configs.feed_windows stores [["06:00","10:00"], …]; the repeater
     * edits [{start, end}, …].
     *
     * @param  list<array{0: string, 1: string}>|null  $windows
     * @return list<array{start: string, end: string}>
     */
    public static function feedWindowsToForm(?array $windows): array
    {
        return array_values(array_map(
            fn (array $window): array => ['start' => (string) ($window[0] ?? ''), 'end' => (string) ($window[1] ?? '')],
            $windows ?? [],
        ));
    }

    /**
     * @param  array<array-key, array{start?: string, end?: string}>|null  $items
     * @return list<array{0: string, 1: string}>
     */
    public static function feedWindowsFromForm(?array $items): array
    {
        return array_values(array_map(
            fn (array $item): array => [(string) ($item['start'] ?? ''), (string) ($item['end'] ?? '')],
            $items ?? [],
        ));
    }

    /**
     * True if two [start, end) windows share a minute. A window whose end is
     * not after its start runs over midnight. Malformed entries are ignored
     * here (the per-field regex reports them).
     *
     * @param  list<array{0: string, 1: string}>  $windows
     */
    public static function feedWindowsOverlap(array $windows): bool
    {
        $segments = [];
        foreach ($windows as [$start, $end]) {
            if (! preg_match(self::TIME_REGEX, $start) || ! preg_match(self::TIME_REGEX, $end) || $start === $end) {
                continue;
            }

            $s = (int) substr($start, 0, 2) * 60 + (int) substr($start, 3, 2);
            $e = (int) substr($end, 0, 2) * 60 + (int) substr($end, 3, 2);
            $parts = $e > $s ? [[$s, $e]] : [[$s, 1440], [0, $e]];

            foreach ($parts as [$a, $b]) {
                foreach ($segments as [$c, $d]) {
                    if ($a < $d && $c < $b) {
                        return true;
                    }
                }
            }
            array_push($segments, ...$parts);
        }

        return false;
    }

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
                    ->helperText('Pre-M5 daily step goal — used only for a breed WITHOUT life-stage data. With data the goal is exercise minutes × steps per minute of the dog\'s life stage (Life-stage data).'),

                Forms\Components\TextInput::make('daily_steps_cap')
                    ->label('Daily step goal cap')
                    ->integer()
                    ->minValue(1)
                    ->nullable()
                    ->helperText('Optional upper limit for the life-stage step goal (M5-R01). Empty = no cap (David 2026-10-05).'),

                Forms\Components\TextInput::make('hunger_decay_rate')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->helperText('Hunger decay in percentage points per hour outside quiet hours (e.g. 8 for −8 %/h; 10 % of it during quiet hours).'),

                Forms\Components\TextInput::make('thirst_decay_rate')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->helperText('Thirst decay in percentage points per hour outside quiet hours (e.g. 10 for −10 %/h).'),

                Forms\Components\TextInput::make('poops_per_day')
                    ->label('Hygiene events per day')
                    ->required()
                    ->integer()
                    ->minValue(0)
                    ->maxValue(10)
                    ->helperText('Random "mess" events per family-local day, outside quiet hours; each drops hygiene to 0 %. Applies from the next unscheduled day.'),

                Forms\Components\Repeater::make('feed_windows')
                    ->label('Feeding windows (family-local time)')
                    ->schema([
                        Forms\Components\TextInput::make('start')
                            ->required()
                            ->regex(self::TIME_REGEX)
                            ->placeholder('06:00'),
                        Forms\Components\TextInput::make('end')
                            ->required()
                            ->regex(self::TIME_REGEX)
                            ->different('start')
                            ->placeholder('10:00'),
                    ])
                    ->columns(2)
                    ->defaultItems(0)
                    ->minItems(1)
                    ->rules([
                        fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            if (is_array($value) && self::feedWindowsOverlap(self::feedWindowsFromForm($value))) {
                                $fail('Feeding windows must not overlap.');
                            }
                        },
                    ])
                    ->helperText('At least one window. Feeding is allowed only inside these [start, end) windows, HH:MM; an end before the start runs over midnight; windows must not overlap (child API, M1-07).'),

                Forms\Components\TextInput::make('water_times_per_day')
                    ->required()
                    ->integer()
                    ->minValue(0)
                    ->helperText('Maximum water refills per family-local day (M1-07).'),

                Forms\Components\TextInput::make('water_min_gap_minutes')
                    ->required()
                    ->integer()
                    ->minValue(0)
                    ->helperText('Minimum minutes between two water refills (M1-07).'),

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

                Tables\Columns\TextColumn::make('daily_steps_cap')
                    ->label('Step cap')
                    ->numeric()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('hunger_decay_rate')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('thirst_decay_rate')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('poops_per_day')
                    ->label('Hygiene events / day')
                    ->numeric(),

                Tables\Columns\TextColumn::make('feed_windows')
                    ->label('Feeding windows')
                    ->state(fn (BreedConfig $record): string => collect($record->feed_windows ?? [])
                        ->map(fn (array $window): string => ($window[0] ?? '?').'–'.($window[1] ?? '?'))
                        ->join(', ')),

                Tables\Columns\TextColumn::make('water_times_per_day')
                    ->label('Water / day')
                    ->numeric(),

                Tables\Columns\TextColumn::make('water_min_gap_minutes')
                    ->label('Water gap (min)')
                    ->numeric(),

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
