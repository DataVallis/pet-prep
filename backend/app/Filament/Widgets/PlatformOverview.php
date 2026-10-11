<?php

namespace App\Filament\Widgets;

use App\Enums\Species;
use App\Enums\UserRole;
use App\Filament\Resources\ActivityLogResource;
use App\Filament\Resources\ChallengeCreditResource;
use App\Filament\Resources\PetResource;
use App\Filament\Resources\UserResource;
use App\Filament\Widgets\Concerns\UsesDashboardPeriod;
use App\Models\ActivityLog;
use App\Models\ChallengeCredit;
use App\Models\Family;
use App\Models\Pet;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Dashboard headline numbers (David 2026-10-11): families, parents,
 * children, active pets, paid challenges and care actions, each with a
 * per-day sparkline for the period picked in the dashboard filter.
 * Superadmins are not counted as parents.
 */
class PlatformOverview extends StatsOverviewWidget
{
    use UsesDashboardPeriod;

    protected static ?int $sort = 0;

    protected static ?string $pollingInterval = '60s';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return list<Stat>
     */
    protected function getStats(): array
    {
        $since = $this->periodStart();
        $parents = User::where('role', UserRole::Parent->value)->where('is_superadmin', false);
        $children = User::where('role', UserRole::Child->value);
        $activePets = Pet::where('is_active', true);
        $credits = ChallengeCredit::query();
        $periodCredits = ChallengeCredit::where('purchased_at', '>=', $since);

        $parentsDaily = $this->dailyCounts($parents->clone());
        $petsDaily = $this->dailyCounts(Pet::query());
        $creditsDaily = $this->dailyCounts(ChallengeCredit::query(), 'purchased_at');
        $careDaily = $this->dailyCounts(ActivityLog::whereIn('activity_type', CareActivityChart::chartedTypes()));

        return [
            Stat::make(__('admin.dashboard.stats.families'), Family::count())
                ->description(__('admin.dashboard.stats.families_desc', ['count' => Family::where('created_at', '>=', $since)->count()]))
                ->descriptionIcon('heroicon-m-home')
                ->chart($this->dailyCounts(Family::query()))
                ->color('primary'),
            Stat::make(__('admin.dashboard.stats.parents'), $parents->clone()->count())
                ->description(__('admin.dashboard.stats.parents_desc', ['count' => array_sum($parentsDaily)]))
                ->descriptionIcon('heroicon-m-user')
                ->chart($parentsDaily)
                ->color('info')
                ->url(UserResource::getUrl()),
            Stat::make(__('admin.dashboard.stats.children'), $children->clone()->count())
                ->description(__('admin.dashboard.stats.children_desc', ['count' => $children->clone()->where('created_at', '>=', $since)->count()]))
                ->descriptionIcon('heroicon-m-face-smile')
                ->chart($this->dailyCounts($children->clone()))
                ->color('info'),
            Stat::make(__('admin.dashboard.stats.pets'), $activePets->clone()->count())
                ->description(__('admin.dashboard.stats.pets_desc', [
                    'dogs' => $activePets->clone()->where('species', Species::Dog->value)->count(),
                    'cats' => $activePets->clone()->where('species', Species::Cat->value)->count(),
                    'new' => array_sum($petsDaily),
                ]))
                ->descriptionIcon('heroicon-m-heart')
                ->chart($petsDaily)
                ->color('success')
                ->url(PetResource::getUrl()),
            Stat::make(__('admin.dashboard.stats.challenges'), $credits->clone()->whereNull('revoked_at')->count())
                ->description(__('admin.dashboard.stats.challenges_desc', [
                    'period' => array_sum($creditsDaily),
                    'sandbox' => $periodCredits->clone()->where('environment', 'SANDBOX')->count(),
                    'production' => $periodCredits->clone()->where(fn ($q) => $q->whereNull('environment')->orWhere('environment', '!=', 'SANDBOX'))->count(),
                ]))
                ->descriptionIcon('heroicon-m-credit-card')
                ->chart($creditsDaily)
                ->color('warning')
                ->url(ChallengeCreditResource::getUrl()),
            Stat::make(__('admin.dashboard.stats.care'), number_format(array_sum($careDaily), 0, ',', '.'))
                ->description(__('admin.dashboard.stats.care_desc'))
                ->descriptionIcon('heroicon-m-hand-raised')
                ->chart($careDaily)
                ->color('success')
                ->url(ActivityLogResource::getUrl()),
        ];
    }
}
