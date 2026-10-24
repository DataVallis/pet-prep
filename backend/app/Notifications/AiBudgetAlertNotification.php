<?php

namespace App\Notifications;

use App\Enums\AdminAlertKind;
use App\Models\User;
use App\Services\Alerts\AdminAlert;
use App\Services\Media\AiBudgetMonitor;
use App\Services\Media\AiBudgetStatus;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * M5-T15: one AI budget / fal balance warning to a superadmin — a Filament
 * database notification (the bell in /admin), plus an e-mail when a real
 * mailer is configured (MAIL_MAILER not `log` / `array`). Amounts only, no
 * pet, family or child data. Sent synchronously from the queued
 * CheckAiBudgetAlerts job (already off the request and outside transactions),
 * the database channel first and mail separately (a mail failure is only reported).
 * M5-T19: toAdminAlert() is the same warning for the admin Discord channel
 * (App\Services\Alerts\DiscordWebhook), posted by AiBudgetMonitor after the bell.
 */
class AiBudgetAlertNotification extends Notification
{
    /**
     * @param  array<string, string>  $params  translation parameters
     */
    private function __construct(
        public readonly string $titleKey,
        public readonly string $bodyKey,
        public readonly array $params,
        public readonly bool $danger,
        public readonly AdminAlertKind $kind,
        /** @var array<string, string> field label key => value */
        public readonly array $fields = [],
    ) {}

    public static function budget(AiBudgetStatus $status, int $threshold): self
    {
        $blocked = $threshold >= AiBudgetMonitor::BLOCK_PERCENT;

        return new self(
            titleKey: 'admin.ai_alerts.'.($blocked ? 'blocked_title' : 'warning_title'),
            bodyKey: 'admin.ai_alerts.'.($blocked ? 'blocked_body' : 'warning_body'),
            params: [
                'budget' => __('admin.ai_alerts.budgets.'.$status->budget.'_'.$status->period),
                'percent' => (string) min(999, (int) floor($status->percent())),
                'spent' => sprintf('%.2f', $status->spentUsd),
                'cap' => sprintf('%.2f', $status->capUsd),
                'left' => sprintf('%.2f', $status->leftUsd()),
                'period' => $status->periodKey,
            ],
            danger: $blocked,
            kind: $blocked ? AdminAlertKind::AiBudgetBlocked : AdminAlertKind::AiBudgetWarning,
            fields: [
                'budget' => __('admin.ai_alerts.budgets.'.$status->budget.'_'.$status->period).' ('.$status->periodKey.')',
                'used' => min(999, (int) floor($status->percent())).' %',
                'spent' => '$'.sprintf('%.2f', $status->spentUsd).' / $'.sprintf('%.2f', $status->capUsd),
                'left' => '$'.sprintf('%.2f', $status->leftUsd()),
            ],
        );
    }

    public static function falBalance(string $level, ?float $balanceUsd, float $thresholdUsd): self
    {
        $exhausted = $level === 'exhausted';

        return new self(
            titleKey: 'admin.ai_alerts.'.($exhausted ? 'fal_exhausted_title' : 'fal_low_title'),
            bodyKey: 'admin.ai_alerts.'.($exhausted ? 'fal_exhausted_body' : 'fal_low_body'),
            params: [
                'balance' => $balanceUsd === null ? '—' : sprintf('%.2f', $balanceUsd),
                'threshold' => sprintf('%.2f', $thresholdUsd),
            ],
            danger: $exhausted,
            kind: $exhausted ? AdminAlertKind::FalBalanceExhausted : AdminAlertKind::FalBalanceLow,
            fields: [
                'balance' => $balanceUsd === null ? '—' : '$'.sprintf('%.2f', $balanceUsd),
                'threshold' => '$'.sprintf('%.2f', $thresholdUsd),
            ],
        );
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        // AiBudgetMonitor sends the channels one by one (database first, then mail) via sendNow($u, $n, [channel]).
        return self::mailConfigured() && filled($notifiable->email) ? ['database', 'mail'] : ['database'];
    }

    /** A real mailer in this environment (production default is `log` — PRODUCTION_ENV.md). */
    public static function mailConfigured(): bool
    {
        return ! in_array((string) config('mail.default'), ['log', 'array', ''], true);
    }

    public function title(): string
    {
        return __($this->titleKey, $this->params);
    }

    public function body(): string
    {
        return __($this->bodyKey, $this->params);
    }

    /** The same warning for the admin Discord channel (M5-T19) — amounts only. */
    public function toAdminAlert(): AdminAlert
    {
        $fields = [];
        foreach ($this->fields as $key => $value) {
            $fields[__('admin.ai_alerts.fields.'.$key)] = $value;
        }

        return new AdminAlert($this->kind, $this->title(), $this->body(), $fields);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $notification = FilamentNotification::make()
            ->title($this->title())
            ->body($this->body())
            ->icon($this->danger ? 'heroicon-o-x-circle' : 'heroicon-o-exclamation-triangle')
            ->actions([
                Action::make('open')
                    ->label(__('admin.ai_alerts.open'))
                    ->url(url('/admin/ai-budget'))
                    ->markAsRead(),
            ]);

        $this->danger ? $notification->danger() : $notification->warning();

        return $notification->getDatabaseMessage();
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('PetPrep: '.$this->title())
            ->line($this->body())
            ->action(__('admin.ai_alerts.open'), url('/admin/ai-budget'));
    }
}
