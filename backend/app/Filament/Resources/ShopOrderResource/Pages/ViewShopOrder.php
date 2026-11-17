<?php

namespace App\Filament\Resources\ShopOrderResource\Pages;

use App\Filament\Resources\ShopOrderResource;
use App\Models\ShopOrder;
use App\Models\User;
use App\Services\Shop\ShopAdminService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * M6-S09: order details + the audited admin actions (spec §3.7).
 *
 * @property ShopOrder $record
 */
class ViewShopOrder extends ViewRecord
{
    protected static string $resource = ShopOrderResource::class;

    protected function getHeaderActions(): array
    {
        $t = fn (string $k): string => __('admin.shop_admin.'.$k);
        /** @var ShopOrder $order */
        $order = $this->record;

        return [
            Action::make('showCode')->label($t('show_code'))->icon('heroicon-o-eye')
                ->visible(fn (): bool => $order->voucher !== null)
                ->modalHeading($t('show_code'))
                ->modalDescription($t('show_code_note'))
                ->modalSubmitActionLabel($t('show_code'))
                ->action(function () use ($order, $t): void {
                    $code = app(ShopAdminService::class)->revealCode($this->admin(), $order->voucher);
                    Notification::make()->title($t('code').': '.$code)->persistent()->success()->send();
                }),
            Action::make('resend')->label($t('send_again'))->icon('heroicon-o-paper-airplane')
                ->requiresConfirmation()
                ->visible(fn (): bool => $order->delivers())
                ->action(function () use ($order, $t): void {
                    $email = app(ShopAdminService::class)->resend($this->admin(), $order);
                    Notification::make()->title($email === null ? $t('no_email') : $t('send_again_done'))
                        ->{$email === null ? 'warning' : 'success'}()->send();
                }),
            Action::make('changeEmail')->label($t('change_email'))->icon('heroicon-o-pencil-square')
                ->form([TextInput::make('email')->label($t('new_email'))->email()->required()->maxLength(254)])
                ->action(function (array $data) use ($order, $t): void {
                    app(ShopAdminService::class)->changeEmailAndResend($this->admin(), $order, (string) $data['email']);
                    $this->record->refresh();
                    Notification::make()->title($t('send_again_done'))->success()->send();
                }),
            Action::make('stripe')->label($t('open_stripe'))->icon('heroicon-o-arrow-top-right-on-square')->color('gray')
                ->visible(fn (): bool => ShopOrderResource::stripePaymentUrl($order) !== null)
                ->url(fn (): ?string => ShopOrderResource::stripePaymentUrl($order), shouldOpenInNewTab: true),
        ];
    }

    private function admin(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
