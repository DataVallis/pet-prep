<?php

namespace App\Filament\Widgets;

use App\Enums\PetPlan;
use App\Enums\Species;
use App\Filament\Resources\PetResource;
use App\Models\Pet;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Str;

/**
 * The 8 newest pets; a row opens the pet in PetResource.
 */
class LatestPets extends TableWidget
{
    protected static ?int $sort = 6;

    protected static ?string $pollingInterval = '60s';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('admin.dashboard.latest_pets.heading'))
            ->query(Pet::query()->latest('id')->limit(8))
            ->paginated(false)
            ->recordUrl(fn (Pet $record): string => PetResource::getUrl('edit', ['record' => $record]))
            ->columns([
                Tables\Columns\TextColumn::make('id')->label(__('admin.dashboard.latest_pets.id')),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('admin.dashboard.latest_pets.name'))
                    ->placeholder(__('admin.dashboard.latest_pets.unnamed')),
                Tables\Columns\TextColumn::make('species')
                    ->label(__('admin.dashboard.latest_pets.species'))
                    ->badge()
                    ->formatStateUsing(fn (?Species $state): string => $state === Species::Cat ? __('admin.dashboard.latest_pets.species_cat') : __('admin.dashboard.latest_pets.species_dog'))
                    ->color(fn (?Species $state): string => $state === Species::Cat ? 'info' : 'warning'),
                Tables\Columns\TextColumn::make('breed_type')
                    ->label(__('admin.dashboard.latest_pets.breed'))
                    ->formatStateUsing(fn ($state): string => Str::headline((string) ($state->value ?? $state))),
                Tables\Columns\TextColumn::make('plan')
                    ->label(__('admin.dashboard.latest_pets.plan'))
                    ->badge()
                    ->formatStateUsing(fn (?PetPlan $state, Pet $record): string => match (true) {
                        $state === PetPlan::Challenge && $record->challenge_paid_at !== null => __('admin.dashboard.plans.challenge_paid'),
                        $state === PetPlan::Challenge => __('admin.dashboard.plans.challenge_unpaid'),
                        default => __('admin.dashboard.plans.free'),
                    })
                    ->color(fn (?PetPlan $state, Pet $record): string => match (true) {
                        $state === PetPlan::Challenge && $record->challenge_paid_at !== null => 'success',
                        $state === PetPlan::Challenge => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('pet_state')
                    ->label(__('admin.dashboard.latest_pets.state'))
                    ->badge()
                    ->formatStateUsing(function ($state): string {
                        $value = (string) ($state->value ?? $state);
                        $key = "admin.dashboard.latest_pets.states.{$value}";

                        return trans()->has($key) ? __($key) : Str::headline($value);
                    })
                    ->color(fn ($state): string => ($state->value ?? $state) === 'sick' ? 'danger' : 'gray'),
                Tables\Columns\TextColumn::make('family_id')->label(__('admin.dashboard.latest_pets.family'))->prefix('#'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('admin.dashboard.latest_pets.created'))
                    ->since()
                    ->dateTimeTooltip('Y-m-d H:i', 'Europe/Ljubljana'),
            ]);
    }
}
