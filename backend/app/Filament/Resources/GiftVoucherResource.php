<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GiftVoucherResource\Pages;
use App\Models\GiftVoucher;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Website gift vouchers (M6-S01, CONTRACT §6.1) — read only, superadmin (panel
 * gate). Written only by the redeem endpoint and the Lemon Squeezy webhook. No
 * buyer data exists to show (the code is shown as its last 4 characters).
 */
class GiftVoucherResource extends Resource
{
    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.payments');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.gift_vouchers');
    }

    public static function getModelLabel(): string
    {
        return __('admin.navigation.gift_voucher_model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.navigation.gift_vouchers');
    }

    protected static ?string $model = GiftVoucher::class;

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $q) => $q->with('credit'))
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('key_last4')->label('Code')
                    ->formatStateUsing(fn (string $state): string => '…'.$state),
                Tables\Columns\TextColumn::make('ls_order_id')->label('LS order')->sortable(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->state(fn (GiftVoucher $record): string => $record->displayStatus())
                    ->color(fn (string $state): string => match ($state) {
                        GiftVoucher::STATUS_REDEEMED => 'success',
                        GiftVoucher::STATUS_PURCHASED => 'info',
                        GiftVoucher::STATUS_EXPIRED => 'gray',
                        default => 'danger',
                    }),
                Tables\Columns\TextColumn::make('environment')->badge(),
                Tables\Columns\TextColumn::make('purchased_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('expires_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('redeemed_at')->dateTime()->placeholder('—')->sortable(),
                Tables\Columns\TextColumn::make('family_id')->label('Family')->placeholder('—')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : '#'.$state),
                Tables\Columns\TextColumn::make('credit.pet_id')->label('Pet')->placeholder('unassigned')
                    ->url(fn (GiftVoucher $record): ?string => $record->credit?->pet_id !== null
                        ? PetResource::getUrl('edit', ['record' => $record->credit->pet_id]) : null),
                Tables\Columns\TextColumn::make('credit.revoked_at')->label('Credit revoked')->dateTime()->placeholder('—'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(array_combine(GiftVoucher::STATUSES, GiftVoucher::STATUSES)),
                Tables\Filters\SelectFilter::make('environment')
                    ->options(['PRODUCTION' => 'PRODUCTION', 'SANDBOX' => 'SANDBOX']),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGiftVouchers::route('/'),
        ];
    }
}
