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

    /**
     * QA M1: the calculation the admin confirms — frozen (and signed) when the confirmation
     * opens, so »Upgrade« can never queue more pets or money than the modal showed. Public
     * Livewire state survives the round trip; the signature makes a tampered copy useless.
     *
     * @var array{filters: array<string, mixed>, pet_ids: list<int>, cost_usd: float, pets: int, looks: int, left_today_usd: float, today_usd: float, today_share_percent: int|null, days: int|null}|array{}
     */
    public array $confirmed = [];

    public string $confirmedSignature = '';

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
            ->mountUsing(fn () => $this->freezeConfirmation())
            ->modalHeading(__('admin.state_upgrade.confirm_heading'))
            ->modalDescription(function (): string {
                $c = $this->confirmed;
                if ($c === []) {
                    return '';
                }

                return __('admin.state_upgrade.confirm', [
                    'pets' => $c['pets'],
                    'looks' => $c['looks'],
                    'cost' => sprintf('%.2f', $c['cost_usd']),
                    'today' => sprintf('%.2f', $c['today_usd']),
                    'left' => sprintf('%.2f', $c['left_today_usd']),
                    'share' => $c['today_share_percent'] === null ? __('admin.state_upgrade.share_none') : $c['today_share_percent'].' %',
                    'days' => $c['days'] ?? '∞',
                ]);
            })
            ->modalSubmitActionLabel(__('admin.state_upgrade.upgrade'))
            ->action(fn () => $this->runUpgrade());
    }

    /** QA M1: freeze the numbers the confirmation shows (fresh calculation at modal mount). */
    protected function freezeConfirmation(): void
    {
        $this->previewCache = null;
        $p = $this->getPreview();

        $this->confirmed = [
            'filters' => $this->currentFilters(),
            'pet_ids' => $p['pet_ids'],
            'cost_usd' => (float) $p['cost_usd'],
            'pets' => $p['pets'],
            'looks' => $p['looks'],
            'left_today_usd' => (float) $p['budget']['left_today_usd'],
            'today_usd' => (float) $p['budget']['today_usd'],
            'today_share_percent' => $p['budget']['today_share_percent'],
            'days' => $p['budget']['days'],
        ];
        $this->confirmedSignature = $this->sign($this->confirmed);
    }

    protected function runUpgrade(): void
    {
        $this->authorizeAccess();
        $confirmed = $this->confirmed;
        $valid = $confirmed !== [] && hash_equals($this->sign($confirmed), $this->confirmedSignature);
        $this->confirmed = [];
        $this->confirmedSignature = '';
        $this->previewCache = null;

        $result = $valid
            ? app(StateMediaUpgradePlanner::class)->upgrade($this->admin(), $confirmed['filters'], $confirmed['pet_ids'], (float) $confirmed['cost_usd'])
            : ['status' => 'changed', 'pets' => 0, 'cost_usd' => 0.0, 'reason' => null];

        $notification = match ($result['status']) {
            'queued' => Notification::make()->title(__('admin.state_upgrade.queued'))->success()
                ->body(__('admin.state_upgrade.queued_body', ['pets' => $result['pets'], 'cost' => sprintf('%.2f', $result['cost_usd'])])),
            'changed' => Notification::make()->title(__('admin.state_upgrade.changed'))->danger(),
            'blocked' => Notification::make()->title(__('admin.state_upgrade.blocked.'.$result['reason']))->warning(),
            'busy' => Notification::make()->title(__('admin.state_upgrade.busy'))->warning(),
            default => Notification::make()->title(__('admin.state_upgrade.nothing'))->warning(),
        };
        $notification->send();
    }

    /** @return array{species: string|null, breed: string|null, only_complete: bool} */
    private function currentFilters(): array
    {
        return [
            'species' => Species::tryFrom((string) ($this->filters['species'] ?? ''))?->value,
            'breed' => BreedType::tryFrom((string) ($this->filters['breed'] ?? ''))?->value,
            'only_complete' => (bool) ($this->filters['only_complete'] ?? false),
        ];
    }

    /** @param  array<string, mixed>  $confirmed */
    private function sign(array $confirmed): string
    {
        // Only what upgrade() acts on, normalised (Livewire turns 0.0 into 0 on the round trip).
        $canonical = [
            'filters' => [
                'species' => $confirmed['filters']['species'] ?? null,
                'breed' => $confirmed['filters']['breed'] ?? null,
                'only_complete' => (bool) ($confirmed['filters']['only_complete'] ?? false),
            ],
            'pet_ids' => array_map('intval', (array) ($confirmed['pet_ids'] ?? [])),
            'cost' => sprintf('%.2f', (float) ($confirmed['cost_usd'] ?? 0)),
        ];

        return hash_hmac('sha256', self::class.'|'.auth()->id().'|'.json_encode($canonical), (string) config('app.key'));
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
