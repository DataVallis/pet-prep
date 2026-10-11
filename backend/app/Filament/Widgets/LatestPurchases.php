<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ChallengeCreditResource;
use App\Filament\Resources\PetResource;
use App\Models\ChallengeCredit;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * The 8 newest challenge purchases (credits); the pet column links to the pet.
 */
class LatestPurchases extends TableWidget
{
    protected static ?int $sort = 7;

    protected static ?string $pollingInterval = '60s';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('admin.dashboard.latest_purchases.heading'))
            ->query(ChallengeCredit::query()->latest('id')->limit(8))
            ->paginated(false)
            ->headerActions([
                Tables\Actions\Action::make('all')
                    ->label(__('admin.dashboard.latest_purchases.view_all'))
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->link()
                    ->url(ChallengeCreditResource::getUrl()),
            ])
            ->columns([
                Tables\Columns\TextColumn::make('purchased_at')
                    ->label(__('admin.dashboard.latest_purchases.purchased'))
                    ->since()
                    ->dateTimeTooltip('Y-m-d H:i', 'Europe/Ljubljana'),
                Tables\Columns\TextColumn::make('store')->label(__('admin.dashboard.latest_purchases.store'))->badge()->color('gray'),
                Tables\Columns\TextColumn::make('environment')
                    ->label(__('admin.dashboard.latest_purchases.environment'))
                    ->badge()
                    ->color(fn (?string $state): string => $state === 'SANDBOX' ? 'warning' : 'success'),
                Tables\Columns\TextColumn::make('family_id')->label(__('admin.dashboard.latest_purchases.family'))->prefix('#'),
                Tables\Columns\TextColumn::make('pet_id')
                    ->label(__('admin.dashboard.latest_purchases.pet'))
                    ->prefix('#')
                    ->placeholder(__('admin.dashboard.latest_purchases.unassigned'))
                    ->url(fn (ChallengeCredit $record): ?string => $record->pet_id !== null ? PetResource::getUrl('edit', ['record' => $record->pet_id]) : null),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('admin.dashboard.latest_purchases.status'))
                    ->badge()
                    ->state(fn (ChallengeCredit $record): string => $record->revoked_at !== null ? __('admin.dashboard.latest_purchases.revoked') : __('admin.dashboard.latest_purchases.active'))
                    ->color(fn (ChallengeCredit $record): string => $record->revoked_at !== null ? 'danger' : 'success'),
            ]);
    }
}
