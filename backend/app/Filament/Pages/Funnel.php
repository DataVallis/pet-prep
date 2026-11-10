<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Funnel\FunnelService;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Funnel (M5-08, VALIDATION_PLAN §4.1, David 2026-11-10, superadmin only):
 * families per weekly sign-up cohort at every step from registration to the
 * certificate — see FunnelService for the step definitions. Numbers only.
 */
class Funnel extends Page
{
    public const WEEK_OPTIONS = [8, 12, 26, 52];

    protected static ?string $navigationIcon = 'heroicon-o-funnel';

    protected static ?string $slug = 'funnel';

    protected static ?int $navigationSort = -2;

    protected static string $view = 'filament.pages.funnel';

    public int $weeks = 12;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.system');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.funnel.navigation');
    }

    public function getTitle(): string
    {
        return __('admin.funnel.title');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isSuperadmin();
    }

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    public function updatedWeeks(): void
    {
        $this->authorizeAccess();
        if (! in_array($this->weeks, self::WEEK_OPTIONS, true)) {
            $this->weeks = 12;
        }
    }

    /**
     * @return array{rows: list<array<string, int|string>>, total: array<string, int>, from: string}
     */
    public function getFunnel(): array
    {
        $this->authorizeAccess();

        return app(FunnelService::class)->cohorts($this->weeks);
    }

    private function authorizeAccess(): void
    {
        if (! static::canAccess()) {
            throw new AuthorizationException;
        }
    }
}
