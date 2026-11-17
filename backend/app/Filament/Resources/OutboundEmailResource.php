<?php

namespace App\Filament\Resources;

use App\Filament\Resources\Concerns\SuperadminReadOnly;
use App\Filament\Resources\OutboundEmailResource\Pages;
use App\Models\OutboundEmail;
use App\Models\ShopAuditLog;
use App\Models\User;
use App\Services\Mail\OutboundEmailSender;
use App\Services\Shop\ShopAdminService;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * The e-mail queue (M6-S09, WEBSITE_SALES_SPEC §3.6) — superadmin: list, HTML preview,
 * »Send again« (new attempt series) and »Cancel«. Sent by `emails:send`.
 */
class OutboundEmailResource extends Resource
{
    use SuperadminReadOnly;

    protected static ?string $model = OutboundEmail::class;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $slug = 'emails';

    protected static ?int $navigationSort = -8;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.payments');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.outbound_emails');
    }

    public static function getModelLabel(): string
    {
        return __('admin.navigation.outbound_email_model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.navigation.outbound_emails');
    }

    public static function getNavigationBadge(): ?string
    {
        $failed = OutboundEmail::where('status', OutboundEmail::STATUS_FAILED)->count();

        return $failed > 0 ? (string) $failed : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        $t = fn (string $k): string => __('admin.shop_admin.'.$k);
        $admin = function (): User {
            /** @var User $user */
            $user = auth()->user();

            return $user;
        };

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label($t('date'))->dateTime('Y-m-d H:i', 'Europe/Ljubljana')->sortable(),
                Tables\Columns\TextColumn::make('kind')->label($t('kind'))->badge()
                    ->formatStateUsing(fn (string $state): string => __('admin.shop_admin.products.'.$state)),
                Tables\Columns\TextColumn::make('to_address')->label($t('to'))->searchable(),
                Tables\Columns\TextColumn::make('subject')->label($t('subject'))->limit(40),
                Tables\Columns\TextColumn::make('status')->label($t('status'))->badge()
                    ->formatStateUsing(fn (string $state): string => __('admin.shop_admin.email_statuses.'.$state))
                    ->color(fn (string $state): string => match ($state) {
                        OutboundEmail::STATUS_SENT => 'success',
                        OutboundEmail::STATUS_FAILED => 'danger',
                        OutboundEmail::STATUS_CANCELLED => 'gray',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('attempts')->label($t('attempts')),
                Tables\Columns\TextColumn::make('next_attempt_at')->label($t('next_attempt'))->dateTime('Y-m-d H:i', 'Europe/Ljubljana')->placeholder('—'),
                Tables\Columns\TextColumn::make('sent_at')->label($t('sent_at'))->dateTime('Y-m-d H:i', 'Europe/Ljubljana')->placeholder('—'),
                Tables\Columns\TextColumn::make('last_error')->label($t('last_error'))->limit(50)->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('order.number')->label($t('number'))->placeholder('—')
                    ->url(fn (OutboundEmail $r): ?string => $r->shop_order_id === null ? null : ShopOrderResource::getUrl('view', ['record' => $r->shop_order_id])),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label($t('status'))
                    ->options(collect(OutboundEmail::STATUSES)->mapWithKeys(fn (string $s): array => [$s => __('admin.shop_admin.email_statuses.'.$s)])->all()),
            ])
            ->actions([
                Tables\Actions\Action::make('preview')->label($t('preview'))->icon('heroicon-o-eye')
                    ->modalSubmitAction(false)->modalWidth('3xl')
                    ->modalContent(fn (OutboundEmail $r): HtmlString => new HtmlString(
                        '<iframe sandbox="" style="width:100%;height:70vh;border:0;background:#fff" srcdoc="'.e($r->html).'"></iframe>'
                    )),
                Tables\Actions\Action::make('requeue')->label($t('requeue'))->icon('heroicon-o-arrow-path')->requiresConfirmation()
                    ->visible(fn (OutboundEmail $r): bool => in_array($r->status, [OutboundEmail::STATUS_FAILED, OutboundEmail::STATUS_CANCELLED, OutboundEmail::STATUS_SENT], true))
                    ->action(function (OutboundEmail $r) use ($t, $admin): void {
                        app(OutboundEmailSender::class)->requeue($r);
                        ShopAuditLog::record($admin(), 'resend_email', 'outbound_email', $r->id);
                        Notification::make()->title($t('send_again_done'))->success()->send();
                    }),
                Tables\Actions\Action::make('cancel')->label($t('cancel'))->icon('heroicon-o-x-mark')->color('danger')->requiresConfirmation()
                    ->visible(fn (OutboundEmail $r): bool => in_array($r->status, [OutboundEmail::STATUS_PENDING, OutboundEmail::STATUS_FAILED], true))
                    ->action(function (OutboundEmail $r) use ($t, $admin): void {
                        app(ShopAdminService::class)->cancelEmail($admin(), $r);
                        Notification::make()->title($t('cancelled'))->success()->send();
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOutboundEmails::route('/'),
        ];
    }
}
