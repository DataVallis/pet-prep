<?php

namespace App\Filament\Pages;

use App\Enums\BreedType;
use App\Enums\Species;
use App\Models\AppSettingChange;
use App\Models\User;
use App\Services\Media\StateMediaUpgradePlanner;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

/**
 * M5-T16 (David 2026-10-22, superadmin only): »Upgrade existing pets to new media«.
 * Existing paid pets never get the version 3 media (key images, loops, transitions,
 * school / game phase clips) automatically — this page first shows the calculation
 * (StateMediaUpgradePlanner::preview — reads only, no fal call), then »Upgrade« (with a
 * confirmation that shows the total) queues the upgrade in chunk jobs. Filters: species,
 * breed, only looks that are already complete (0 $). Every run is audited
 * (app_setting_changes key `state_media_bulk_upgrade`).
 *
 * @property Form $form
 */
class StateMediaUpgrade extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-circle';

    protected static ?string $slug = 'state-media-upgrade';

    protected static string $view = 'filament.pages.state-media-upgrade';

    /** @var array<string, mixed>|null */
    public ?array $filters = [];

    /** @var array<string, mixed>|null */
    private ?array $previewCache = null;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.ai_media');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.state_upgrade.navigation');
    }

    public function getTitle(): string
    {
        return __('admin.state_upgrade.title');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isSuperadmin();
    }

    public function mount(): void
    {
        $this->authorizeAccess();
        $this->form->fill(['species' => null, 'breed' => null, 'only_complete' => false]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('filters')
            ->columns(3)
            ->schema([
                Select::make('species')
                    ->label(__('admin.state_upgrade.species'))
                    ->options(collect(Species::cases())->mapWithKeys(fn (Species $s) => [$s->value => $s->value])->all())
                    ->placeholder(__('admin.state_upgrade.all'))
                    ->live(),
                Select::make('breed')
                    ->label(__('admin.state_upgrade.breed'))
                    ->options(collect(BreedType::cases())->mapWithKeys(fn (BreedType $b) => [$b->value => __("breeds.{$b->value}")])->sort()->all())
                    ->searchable()
                    ->placeholder(__('admin.state_upgrade.all'))
                    ->live(),
                Toggle::make('only_complete')
                    ->label(__('admin.state_upgrade.only_complete'))
                    ->helperText(__('admin.state_upgrade.only_complete_help'))
                    ->live(),
            ]);
    }

    /**
     * The calculation for the current filters (memoised per request).
     *
     * @return array<string, mixed>
     */
    public function getPreview(): array
    {
        return $this->previewCache ??= app(StateMediaUpgradePlanner::class)->preview(
            Species::tryFrom((string) ($this->filters['species'] ?? '')),
            BreedType::tryFrom((string) ($this->filters['breed'] ?? '')),
            (bool) ($this->filters['only_complete'] ?? false),
        );
    }

    public function updatedFilters(): void
    {
        $this->previewCache = null;
    }

    public function upgradeAction(): Action
    {
        return Action::make('upgrade')
            ->label(__('admin.state_upgrade.upgrade'))
            ->icon('heroicon-o-arrow-up-circle')
            ->color('warning')
            ->visible(fn (): bool => static::canAccess())
            ->disabled(fn (): bool => $this->getPreview()['pets'] === 0 || $this->getPreview()['blocked'] !== null)
            ->requiresConfirmation()
            ->modalHeading(__('admin.state_upgrade.confirm_heading'))
            ->modalDescription(function (): string {
                $p = $this->getPreview();

                return __('admin.state_upgrade.confirm', [
                    'pets' => $p['pets'],
                    'looks' => $p['looks'],
                    'cost' => sprintf('%.2f', $p['cost_usd']),
                    'left' => sprintf('%.2f', $p['budget']['left_today_usd']),
                    'days' => $p['budget']['days'] ?? '∞',
                ]);
            })
            ->modalSubmitActionLabel(__('admin.state_upgrade.upgrade'))
            ->action(fn () => $this->runUpgrade());
    }

    protected function runUpgrade(): void
    {
        $this->authorizeAccess();
        $planner = app(StateMediaUpgradePlanner::class);
        $this->previewCache = null;
        $preview = $this->getPreview();

        if (($reason = $planner->blockedReason(fresh: true)) !== null) {
            Notification::make()->title(__('admin.state_upgrade.blocked.'.$reason))->warning()->send();

            return;
        }

        $petIds = array_merge(...array_map(fn (array $g): array => $g['pet_ids'], $preview['groups'] ?: [['pet_ids' => []]]));
        $queued = $planner->upgrade($this->admin(), $petIds, [
            'species' => $this->filters['species'] ?? null,
            'breed' => $this->filters['breed'] ?? null,
            'only_complete' => (bool) ($this->filters['only_complete'] ?? false),
        ], (float) $preview['cost_usd']);
        $this->previewCache = null;

        Notification::make()
            ->title($queued > 0 ? __('admin.state_upgrade.queued') : __('admin.state_upgrade.nothing'))
            ->body(__('admin.state_upgrade.queued_body', ['pets' => $queued, 'cost' => sprintf('%.2f', $preview['cost_usd'])]))
            ->status($queued > 0 ? 'success' : 'warning')
            ->send();
    }

    /**
     * @return Collection<int, AppSettingChange>
     */
    public function getRuns(): Collection
    {
        return AppSettingChange::with('user:id,name,email')
            ->where('key', StateMediaUpgradePlanner::AUDIT_KEY)
            ->latest('id')->limit(20)->get();
    }

    private function authorizeAccess(): void
    {
        if (! static::canAccess()) {
            throw new AuthorizationException;
        }
    }

    private function admin(): User
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            throw new AuthorizationException;
        }

        return $user;
    }
}
