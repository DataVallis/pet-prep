<?php

namespace App\Filament\Widgets;

use App\Enums\CatsAvailability;
use App\Enums\PetStateEnum;
use App\Filament\Pages\FeatureSwitches;
use App\Filament\Resources\PetResource;
use App\Models\Pet;
use App\Services\AppSettingsService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * "Needs attention" row of the dashboard (David 2026-10-11): pets that are
 * sick, paused waiting for payment or game over, plus the current cats
 * switch with a link to Feature switches. Counts are "right now" (the
 * period filter does not apply).
 */
class AttentionOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected static ?string $pollingInterval = '60s';

    protected int|string|array $columnSpan = 'full';

    protected function getHeading(): ?string
    {
        return __('admin.dashboard.attention.heading');
    }

    /**
     * @return list<Stat>
     */
    protected function getStats(): array
    {
        $active = Pet::where('is_active', true);
        $sick = $active->clone()->where('pet_state', PetStateEnum::Sick->value)->count();
        $locked = $active->clone()->whereNotNull('payment_locked_at')->count();
        $gameOver = Pet::where('is_game_over', true)->count();
        $hardStopped = Pet::where('is_hard_stopped', true)->count();
        $cats = app(AppSettingsService::class)->catsMode();

        return [
            Stat::make(__('admin.dashboard.attention.sick'), $sick)
                ->description(__('admin.dashboard.attention.sick_desc'))
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($sick > 0 ? 'danger' : 'success')
                ->url(PetResource::getUrl()),
            Stat::make(__('admin.dashboard.attention.locked'), $locked)
                ->description(__('admin.dashboard.attention.locked_desc'))
                ->descriptionIcon('heroicon-m-lock-closed')
                ->color($locked > 0 ? 'warning' : 'success')
                ->url(PetResource::getUrl()),
            Stat::make(__('admin.dashboard.attention.game_over'), $gameOver)
                ->description(__('admin.dashboard.attention.game_over_desc', ['hard' => $hardStopped, 'total' => Pet::count()]))
                ->descriptionIcon('heroicon-m-x-circle')
                ->color($gameOver > 0 ? 'gray' : 'success'),
            Stat::make(__('admin.dashboard.attention.cats'), $cats->label())
                ->description(__('admin.dashboard.attention.cats_desc'))
                ->descriptionIcon('heroicon-m-adjustments-horizontal')
                ->color(match ($cats) {
                    CatsAvailability::Everyone => 'success',
                    CatsAvailability::TestFamilies => 'warning',
                    CatsAvailability::Off => 'gray',
                })
                ->url(FeatureSwitches::getUrl()),
        ];
    }
}
