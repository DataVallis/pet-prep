<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\Concerns\DashboardPeriod;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Form;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;

/**
 * Superadmin dashboard (David 2026-10-11): replaces Filament's default page
 * (Welcome + Filament info cards). One period filter drives every widget in
 * App\Filament\Widgets (stats, charts, tables); widgets poll on their own.
 */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    public static function getNavigationLabel(): string
    {
        return __('admin.dashboard.title');
    }

    public function getTitle(): string
    {
        return __('admin.dashboard.title');
    }

    public function filtersForm(Form $form): Form
    {
        return $form->schema([
            Section::make()
                ->schema([
                    ToggleButtons::make('period')
                        ->label(__('admin.dashboard.period'))
                        ->options(collect(DashboardPeriod::DAYS)->mapWithKeys(fn (int $d): array => [(string) $d => __("admin.dashboard.periods.{$d}")])->all())
                        ->default((string) DashboardPeriod::DEFAULT)
                        ->inline()
                        ->grouped(),
                ]),
        ]);
    }

    public function getColumns(): int|string|array
    {
        return ['default' => 1, 'lg' => 2];
    }
}
