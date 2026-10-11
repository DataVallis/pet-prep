<?php

namespace App\Filament\Widgets;

use App\Enums\BreedType;
use App\Enums\Species;
use App\Models\Pet;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Str;

/**
 * Active pets per breed (horizontal bars, most popular first) with its own
 * species filter. All time — the dashboard period does not apply.
 */
class PetsByBreedChart extends ChartWidget
{
    protected static ?int $sort = 4;

    protected static ?string $pollingInterval = null;

    protected static ?string $maxHeight = '320px';

    public ?string $filter = 'all';

    public function getHeading(): string
    {
        return __('admin.dashboard.breeds.heading');
    }

    public function getDescription(): ?string
    {
        return __('admin.dashboard.breeds.description');
    }

    /**
     * @return array<string, string>
     */
    protected function getFilters(): ?array
    {
        return [
            'all' => __('admin.dashboard.all'),
            Species::Dog->value => __('admin.dashboard.dog'),
            Species::Cat->value => __('admin.dashboard.cat'),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $query = Pet::where('is_active', true);
        $species = Species::tryFrom((string) $this->filter);
        if ($species !== null) {
            $query->where('species', $species->value);
        }

        /** @var array<string, int> $counts */
        $counts = $query->toBase()
            ->selectRaw('breed_type, count(*) as aggregate')
            ->groupBy('breed_type')
            ->orderByDesc('aggregate')
            ->orderBy('breed_type')
            ->pluck('aggregate', 'breed_type')
            ->map(fn ($n): int => (int) $n)
            ->all();

        $colors = array_map(
            fn (string $breed): string => BreedType::tryFrom($breed)?->species() === Species::Cat ? '#a855f7' : '#f59e0b',
            array_keys($counts),
        );

        return [
            'datasets' => [[
                'label' => __('admin.dashboard.breeds.pets'),
                'data' => array_values($counts),
                'backgroundColor' => $colors,
                'borderRadius' => 3,
            ]],
            'labels' => array_map(fn (string $breed): string => Str::headline($breed), array_keys($counts)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['x' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
        ];
    }
}
