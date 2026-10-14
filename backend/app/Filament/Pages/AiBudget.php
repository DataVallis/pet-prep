<?php

namespace App\Filament\Pages;

use App\Models\AppSettingChange;
use App\Models\User;
use App\Services\AppSettingsService;
use App\Services\Media\AiSpendGuard;
use App\Services\Media\FalGateway;
use App\Services\Media\ReferenceImageRetryService;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Production AI budget (M4-07b, David 2026-10-14, superadmin only): the
 * daily / monthly cap on ESTIMATED fal.ai spend for pet images and videos,
 * set here instead of in the server .env. Empty = the env cap. Stored in
 * app_settings via AppSettingsService (cached ≤ 60 s, busted on save, every
 * change audited). "Retry blocked media now" re-queues what the budget /
 * fal balance refused — the same as the nightly `media:retry`, within the
 * budget left today. The AI Lab budget stays in the env.
 *
 * @property Form $form
 */
class AiBudget extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $slug = 'ai-budget';

    protected static string $view = 'filament.pages.ai-budget';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.configuration');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.ai_budget.navigation');
    }

    public function getTitle(): string
    {
        return __('admin.ai_budget.title');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isSuperadmin();
    }

    public function mount(AppSettingsService $settings): void
    {
        $this->authorizeAccess();
        $stored = $settings->storedAiBudget();
        $this->form->fill([
            'daily_usd' => $stored['daily_usd'],
            'monthly_usd' => $stored['monthly_usd'],
        ]);
    }

    public function form(Form $form): Form
    {
        $guard = app(AiSpendGuard::class);

        return $form
            ->statePath('data')
            ->schema([
                Section::make(__('admin.ai_budget.section'))
                    ->description(__('admin.ai_budget.description'))
                    ->schema([
                        Placeholder::make('spent')
                            ->label(__('admin.ai_budget.spent'))
                            ->content(fn (): string => __('admin.ai_budget.spent_value', [
                                'today' => sprintf('%.2f', $guard->spentTodayUsd()),
                                'daily' => sprintf('%.2f', $guard->dailyCapUsd()),
                                'month' => sprintf('%.2f', $guard->spentThisMonthUsd()),
                                'monthly' => sprintf('%.2f', $guard->monthlyCapUsd()),
                                'tz' => $guard->timezone(),
                            ])),
                        TextInput::make('daily_usd')
                            ->label(__('admin.ai_budget.daily'))
                            ->numeric()
                            ->prefix('$')
                            ->minValue(0)
                            ->maxValue(AppSettingsService::AI_BUDGET_MAX_DAILY_USD)
                            ->step(0.01)
                            ->nullable()
                            ->placeholder(sprintf('%.2f', $guard->envDailyCapUsd()))
                            ->helperText(__('admin.ai_budget.env_default', ['value' => sprintf('%.2f', $guard->envDailyCapUsd()), 'env' => 'AI_DAILY_BUDGET_USD'])),
                        TextInput::make('monthly_usd')
                            ->label(__('admin.ai_budget.monthly'))
                            ->numeric()
                            ->prefix('$')
                            ->minValue(0)
                            ->maxValue(AppSettingsService::AI_BUDGET_MAX_MONTHLY_USD)
                            ->step(0.01)
                            ->nullable()
                            ->placeholder(sprintf('%.2f', $guard->envMonthlyCapUsd()))
                            ->helperText(__('admin.ai_budget.env_default', ['value' => sprintf('%.2f', $guard->envMonthlyCapUsd()), 'env' => 'AI_MONTHLY_BUDGET_USD'])),
                        Placeholder::make('help')
                            ->hiddenLabel()
                            ->content(__('admin.ai_budget.help')),
                    ]),
            ]);
    }

    public function save(AppSettingsService $settings): void
    {
        $this->authorizeAccess();
        $data = $this->form->getState();

        try {
            $settings->updateAiBudget($this->admin(), self::amount($data['daily_usd'] ?? null), self::amount($data['monthly_usd'] ?? null));
        } catch (InvalidArgumentException $e) {
            Notification::make()->title(__('admin.ai_budget.not_saved'))->body($e->getMessage())->danger()->send();

            return;
        }

        $guard = app(AiSpendGuard::class);
        Notification::make()
            ->title(__('admin.ai_budget.saved'))
            ->body(__('admin.ai_budget.saved_body', ['daily' => sprintf('%.2f', $guard->dailyCapUsd()), 'monthly' => sprintf('%.2f', $guard->monthlyCapUsd())]))
            ->success()
            ->send();
    }

    /**
     * Re-queue images / stage images / videos refused by the budget or the fal
     * balance now (instead of waiting for the nightly media:retry).
     */
    public function retryBlocked(ReferenceImageRetryService $retries): void
    {
        $this->authorizeAccess();

        if (FalGateway::balanceExhaustedAt() !== null) {
            Notification::make()->title(__('admin.ai_budget.retry_balance'))->warning()->send();

            return;
        }

        $queued = $retries->retryDueWithVideos(100);
        $total = $queued['images'] + $queued['stages'] + $queued['videos'];

        Notification::make()
            ->title($total > 0 ? __('admin.ai_budget.retry_done') : __('admin.ai_budget.retry_none'))
            ->body(__('admin.ai_budget.retry_body', $queued))
            ->color($total > 0 ? 'success' : 'warning')
            ->send();
    }

    /**
     * @return Collection<int, AppSettingChange>
     */
    public function getChanges(): Collection
    {
        return AppSettingChange::with('user:id,name,email')
            ->where('key', AppSettingsService::AI_BUDGET_KEY)
            ->latest('id')->limit(20)->get();
    }

    private static function amount(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
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
