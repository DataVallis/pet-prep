<?php

namespace App\Filament\Resources;

use App\Enums\BreedType;
use App\Enums\Species;
use App\Filament\Resources\BreedConfigResource\Pages;
use App\Models\BreedConfig;
use App\Services\BreedCatalogService;
use Closure;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

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
                    ->helperText('Unique slug identifying the breed (e.g., mutt, border-collie, labrador-retriever, golden-retriever, french-bulldog, german-shepherd-dog, cavalier-king-charles-spaniel, beagle, poodle-standard, dachshund, australian-shepherd, havanese, west-highland-white-terrier, bernese-mountain-dog, siberian-husky).'),

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
                    // QA PR #91 M1: same rule as BreedConfig::saving (exactly one free breed per species).
                    ->rules([
                        fn (Get $get, ?BreedConfig $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                            $candidate = $record !== null ? clone $record : new BreedConfig;
                            $candidate->breed_slug = (string) $get('breed_slug');
                            $candidate->premium_unlock = (bool) $value;
                            if (($violation = app(BreedCatalogService::class)->freeBreedViolation($candidate)) !== null) {
                                $fail($violation);
                            }
                        },
                    ])
                    ->helperText('Paid breed (12-week challenge). Since M5-R06-01 the ONLY source of free / paid, read LIVE for every pet of this breed — existing pets too (plan default, refunds, Free / Paid display). Each species must keep exactly one free breed, so the free breed cannot be switched while breeds are enum-based (a change that breaks it is refused).'),

                // M5-R06-01 picker catalogue (GET /api/breeds).
                Forms\Components\Select::make('species')
                    ->options(collect(Species::cases())->mapWithKeys(fn (Species $s): array => [$s->value => ucfirst($s->value)])->all())
                    ->default(Species::Dog->value)
                    ->required()
                    ->helperText('Dog / cat. For a breed the app knows (mutt, border-collie, labrador-retriever, golden-retriever, french-bulldog, german-shepherd-dog, cavalier-king-charles-spaniel, beagle, poodle-standard, dachshund, australian-shepherd, havanese, west-highland-white-terrier, bernese-mountain-dog, siberian-husky, domestic-cat, maine-coon) the species is fixed by the code and this value is ignored.'),

                Forms\Components\TextInput::make('sort_order')
                    ->integer()
                    ->default(0)
                    ->required()
                    ->helperText('Picker order: free breed first, then paid breeds by this number.'),

                Forms\Components\TextInput::make('label_key')
                    ->maxLength(64)
                    ->nullable()
                    ->helperText('i18n key of the breed name in the apps (e.g. breeds.maine_coon). Empty = breeds.<breed>.'),

                Forms\Components\TagsInput::make('search_keywords')
                    ->default([])
                    ->helperText('Search synonyms in the picker (lower case, with and without č/š/ž).'),
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
                    // Free breed primary, paid (challenge) breed warning — from premium_unlock;
                    // a slug the app does not know (not in the catalogue) success (M5-R06-01).
                    ->color(fn (BreedConfig $record): string => match (true) {
                        BreedType::fromSlug((string) $record->breed_slug) === null => 'success',
                        (bool) $record->premium_unlock => 'warning',
                        default => 'primary',
                    })
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('species')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof Species ? $state->value : (string) $state)
                    ->sortable(),

                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Order')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

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
                Tables\Filters\SelectFilter::make('species')
                    ->options(collect(Species::cases())->mapWithKeys(fn (Species $s): array => [$s->value => ucfirst($s->value)])->all()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->before(function (Tables\Actions\DeleteBulkAction $action, Collection $records): void {
                            self::refuseBreakingDelete($action, $records->modelKeys());
                        }),
                ]),
            ]);
    }

    /**
     * QA PR #91 M1: a delete that would leave a species without exactly one
     * free breed is cancelled with a notification (BreedConfig::deleting
     * refuses it anyway).
     *
     * @param  list<int|string>  $ids
     */
    public static function refuseBreakingDelete(Action|BulkAction $action, array $ids): void
    {
        $violation = app(BreedCatalogService::class)->freeBreedViolation(null, array_map('intval', $ids));
        if ($violation === null) {
            return;
        }

        Notification::make()->danger()->title('Not deleted')->body($violation)->send();
        $action->cancel();
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
