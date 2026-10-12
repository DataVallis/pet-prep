<?php

namespace App\Filament\Widgets;

use App\Enums\ActivityType;
use App\Enums\Species;
use App\Filament\Widgets\Concerns\UsesDashboardPeriod;
use App\Models\ActivityLog;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Logged care actions per day in the dashboard period, stacked by group, with
 * its own species filter (all / dogs / cats). Groups come from ActivityType;
 * a type missing here (e.g. signed_contract) is simply not charted.
 */
class CareActivityChart extends ChartWidget
{
    use UsesDashboardPeriod;

    protected static ?int $sort = 3;

    protected static ?string $pollingInterval = null;

    protected static ?string $maxHeight = '280px';

    public ?string $filter = 'all';

    /** @var array<string, array{color: string, types: list<ActivityType>}> */
    private const GROUPS = [
        'feeding' => ['color' => '#f59e0b', 'types' => [ActivityType::FedPet, ActivityType::WateredPet, ActivityType::ParentFedPet]],
        'outside' => ['color' => '#3b82f6', 'types' => [ActivityType::WalkedPet, ActivityType::TookOutPet]],
        'hygiene' => ['color' => '#14b8a6', 'types' => [ActivityType::CleanedPoop, ActivityType::ScoopedLitter, ActivityType::ChangedLitter, ActivityType::GroomedPet, ActivityType::ResolvedChewing, ActivityType::ResolvedScratching]],
        'fun' => ['color' => '#a855f7', 'types' => [ActivityType::TrainedPet, ActivityType::PlayedWithPet, ActivityType::CuddledPet, ActivityType::PlayedWand, ActivityType::SocialisedPet]],
        'incidents' => ['color' => '#ef4444', 'types' => [ActivityType::PetAccident, ActivityType::PetChewed, ActivityType::PetScratched, ActivityType::PetLitterAccident, ActivityType::PetCoatMatted, ActivityType::IgnoredWarning]],
    ];

    /**
     * Activity types the dashboard counts as care (also the "Care actions" stat).
     *
     * @return list<string>
     */
    public static function chartedTypes(): array
    {
        return array_merge(...array_map(
            fn (array $group): array => array_map(fn (ActivityType $t): string => $t->value, $group['types']),
            array_values(self::GROUPS),
        ));
    }

    public function getHeading(): string
    {
        return __('admin.dashboard.care_chart.heading');
    }

    public function getDescription(): ?string
    {
        return __('admin.dashboard.care_chart.description');
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
        $byType = $this->dailyCountsBy(
            $this->baseQuery()->whereIn('activities_log.activity_type', self::chartedTypes()),
            'activities_log.activity_type',
        );
        $zero = array_fill(0, $this->periodDays(), 0);

        $datasets = [];
        foreach (self::GROUPS as $key => $group) {
            $data = $zero;
            foreach ($group['types'] as $type) {
                foreach ($byType[$type->value] ?? [] as $i => $n) {
                    $data[$i] += $n;
                }
            }
            $datasets[] = [
                'label' => __("admin.dashboard.care_chart.{$key}"),
                'data' => $data,
                'backgroundColor' => $group['color'],
                'borderRadius' => 3,
            ];
        }

        return [
            'datasets' => $datasets,
            'labels' => array_map(fn (string $d): string => self::dayLabel($d), $this->periodDates()),
        ];
    }

    /**
     * @return Builder<ActivityLog>
     */
    private function baseQuery(): Builder
    {
        $query = ActivityLog::query();
        $species = Species::tryFrom((string) $this->filter);
        if ($species !== null) {
            $query->whereHas('pet', fn (Builder $q) => $q->where('species', $species->value));
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => true, 'position' => 'bottom']],
            'scales' => [
                'x' => ['stacked' => true],
                'y' => ['stacked' => true, 'beginAtZero' => true, 'ticks' => ['precision' => 0]],
            ],
            'interaction' => ['mode' => 'index', 'intersect' => false],
        ];
    }
}
