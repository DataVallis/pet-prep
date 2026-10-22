<?php

namespace App\Filament\Pages;

use App\Models\AppSettingChange;
use App\Models\User;
use App\Services\AppSettingsService;
use App\Services\FalAiService;
use App\Services\Media\AiBudgetMonitor;
use App\Services\Media\AiSpendGuard;
use App\Services\Media\FalGateway;
use App\Services\Media\ReferenceImageRetryService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Production AI budget (M4-07b, David 2026-10-14, superadmin only): the
 * daily / monthly cap on ESTIMATED fal.ai spend for pet images and videos,
 * set here instead of in the server .env. Empty = the env cap. Stored in
 * app_settings via AppSettingsService (cached ≤ 60 s, busted on save, every
 * change audited). "Retry blocked media now" re-queues what the budget /
 * fal balance refused — the same as the nightly `media:retry`, within the
 * budget left today. The AI Lab budget has its own section (M4-07c); M5-T15
 * adds the fal.ai balance and its low-balance warning threshold. Warnings at
 * ≥ 80 % / 100 % of every cap: AiBudgetMonitor (banner + notifications).
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
            ...self::prefixed('lab_', $settings->storedAiLabBudget()),
            'fal_balance_alert_usd' => $settings->storedFalBalanceAlertUsd(),
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
                // M4-07c: the separate AI Lab budget (model comparisons, breed portraits).
                Section::make(__('admin.ai_budget.lab_section'))
                    ->description(__('admin.ai_budget.lab_description'))
                    ->schema([
                        Placeholder::make('lab_spent')
                            ->label(__('admin.ai_budget.spent'))
                            ->content(fn (): string => __('admin.ai_budget.spent_value', [
                                'today' => sprintf('%.2f', $guard->spentTodayUsd(true)),
                                'daily' => sprintf('%.2f', $guard->labDailyCapUsd()),
                                'month' => sprintf('%.2f', $guard->spentThisMonthUsd(true)),
                                'monthly' => sprintf('%.2f', $guard->labMonthlyCapUsd()),
                                'tz' => $guard->timezone(),
                            ])),
                        self::amountInput('lab_daily_usd', __('admin.ai_budget.daily'), AppSettingsService::AI_LAB_MAX_DAILY_USD, $guard->envLabDailyCapUsd(), 'AI_LAB_DAILY_USD'),
                        self::amountInput('lab_monthly_usd', __('admin.ai_budget.monthly'), AppSettingsService::AI_LAB_MAX_MONTHLY_USD, $guard->envLabMonthlyCapUsd(), 'AI_LAB_MONTHLY_USD'),
                        self::amountInput('lab_max_run_usd', __('admin.ai_budget.lab_max_run'), AppSettingsService::AI_LAB_MAX_RUN_CEILING_USD, $guard->envLabMaxRunUsd(), 'AI_LAB_MAX_RUN_USD'),
                    ]),
                // M5-T15: the fal.ai account balance (Platform API) + its low-balance warning.
                Section::make(__('admin.ai_budget.fal_section'))
                    ->description(__('admin.ai_budget.fal_description'))
                    ->schema([
                        Placeholder::make('fal_balance')
                            ->label(__('admin.ai_budget.fal_balance'))
                            ->content(function (): string {
                                $balance = app(AiBudgetMonitor::class)->falBalance();
                                if ($balance === null || $balance['status'] !== 'ok') {
                                    return __('admin.ai_alerts.banner.fal_unknown_'.($balance['status'] ?? 'never'));
                                }

                                return sprintf('$%.2f', $balance['balance_usd']).' · '.Carbon::parse($balance['checked_at'])->timezone('Europe/Ljubljana')->format('Y-m-d H:i');
                            }),
                        TextInput::make('fal_balance_alert_usd')
                            ->label(__('admin.ai_budget.fal_alert'))
                            ->numeric()
                            ->prefix('$')
                            ->minValue(0)
                            ->maxValue(AppSettingsService::FAL_BALANCE_ALERT_MAX_USD)
                            ->step(0.01)
                            ->nullable()
                            ->placeholder(sprintf('%.2f', AppSettingsService::FAL_BALANCE_ALERT_DEFAULT_USD))
                            ->helperText(__('admin.ai_budget.fal_alert_help', ['value' => sprintf('%.2f', AppSettingsService::FAL_BALANCE_ALERT_DEFAULT_USD)])),
                    ]),
            ]);
    }

    private static function amountInput(string $name, string $label, float $max, float $envDefault, string $envKey): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->numeric()
            ->prefix('$')
            ->minValue(0)
            ->maxValue($max)
            ->step(0.01)
            ->nullable()
            ->placeholder(sprintf('%.2f', $envDefault))
            ->helperText(__('admin.ai_budget.env_default', ['value' => sprintf('%.2f', $envDefault), 'env' => $envKey]));
    }

    /**
     * @param  array<string, float|null>  $values
     * @return array<string, float|null>
     */
    private static function prefixed(string $prefix, array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $out[$prefix.$key] = $value;
        }

        return $out;
    }

    public function save(AppSettingsService $settings): void
    {
        $this->authorizeAccess();
        $data = $this->form->getState();

        try {
            // Both or neither (QA m1): one invalid value must not leave the other saved.
            DB::transaction(function () use ($settings, $data): void {
                $settings->updateAiBudget($this->admin(), self::amount($data['daily_usd'] ?? null), self::amount($data['monthly_usd'] ?? null));
                $settings->updateAiLabBudget(
                    $this->admin(),
                    self::amount($data['lab_daily_usd'] ?? null),
                    self::amount($data['lab_monthly_usd'] ?? null),
                    self::amount($data['lab_max_run_usd'] ?? null),
                );
                $settings->updateFalBalanceAlert($this->admin(), self::amount($data['fal_balance_alert_usd'] ?? null));
            });
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

        // QA m5: a daily cap above the monthly one never takes effect past the month's cap.
        if ($guard->dailyCapUsd() > $guard->monthlyCapUsd()) {
            Notification::make()
                ->title(__('admin.ai_budget.daily_above_monthly'))
                ->body(__('admin.ai_budget.daily_above_monthly_body', ['monthly' => sprintf('%.2f', $guard->monthlyCapUsd()), 'month' => sprintf('%.2f', $guard->spentThisMonthUsd())]))
                ->warning()
                ->persistent()
                ->send();
        }

        if ($guard->labDailyCapUsd() > $guard->labMonthlyCapUsd()) {
            Notification::make()
                ->title(__('admin.ai_budget.lab_daily_above_monthly'))
                ->body(__('admin.ai_budget.daily_above_monthly_body', ['monthly' => sprintf('%.2f', $guard->labMonthlyCapUsd()), 'month' => sprintf('%.2f', $guard->spentThisMonthUsd(true))]))
                ->warning()
                ->persistent()
                ->send();
        }
    }

    /**
     * "Retry blocked media now" — spends money, so it asks first (QA m4) and
     * shows how much of today's budget is left.
     */
    public function retryBlockedAction(): Action
    {
        return Action::make('retryBlocked')
            ->label(__('admin.ai_budget.retry'))
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->visible(fn (): bool => static::canAccess())
            ->requiresConfirmation()
            ->modalHeading(__('admin.ai_budget.retry'))
            ->modalDescription(function (): string {
                $guard = app(AiSpendGuard::class);

                return __('admin.ai_budget.retry_confirm', [
                    'left' => sprintf('%.2f', max(0.0, min($guard->dailyCapUsd() - $guard->spentTodayUsd(), $guard->monthlyCapUsd() - $guard->spentThisMonthUsd()))),
                ]);
            })
            ->action(fn () => $this->runRetry(app(ReferenceImageRetryService::class), app(FalAiService::class)));
    }

    /**
     * Re-queue images / stage images / videos refused by the budget or the fal
     * balance now (instead of waiting for the nightly media:retry).
     */
    protected function runRetry(ReferenceImageRetryService $retries, FalAiService $fal): void
    {
        $this->authorizeAccess();

        if (! $fal->isEnabled()) {
            Notification::make()->title(__('admin.ai_budget.retry_disabled'))->warning()->send();

            return;
        }

        if (FalGateway::balanceExhaustedAt() !== null) {
            Notification::make()->title(__('admin.ai_budget.retry_balance'))->warning()->send();

            return;
        }

        $queued = $retries->retryDueWithVideos(100);
        $total = $queued['images'] + $queued['stages'] + $queued['videos'];

        Notification::make()
            ->title($total > 0 ? __('admin.ai_budget.retry_done') : __('admin.ai_budget.retry_none'))
            ->body(__('admin.ai_budget.retry_body', $queued))
            ->status($total > 0 ? 'success' : 'warning')
            ->send();
    }

    /**
     * @return Collection<int, AppSettingChange>
     */
    public function getChanges(): Collection
    {
        return AppSettingChange::with('user:id,name,email')
            ->whereIn('key', [AppSettingsService::AI_BUDGET_KEY, AppSettingsService::AI_LAB_BUDGET_KEY, AppSettingsService::FAL_BALANCE_ALERT_KEY])
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
