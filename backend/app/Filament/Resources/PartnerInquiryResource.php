<?php

namespace App\Filament\Resources;

use App\Enums\PartnerInquiryType;
use App\Filament\Resources\PartnerInquiryResource\Pages;
use App\Models\PartnerInquiry;
use App\Models\User;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Partner inquiries (M6-S03, WEBSITE_SALES_SPEC §5.2) — superadmin only,
 * read only (list + view); delete is allowed. Written only by
 * PartnerInquiryController (POST /api/partner-inquiries); pruned after
 * 12 months.
 */
class PartnerInquiryResource extends Resource
{
    protected static ?string $model = PartnerInquiry::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox-arrow-down';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.management');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.partner_inquiries');
    }

    public static function getModelLabel(): string
    {
        return __('admin.navigation.partner_inquiry_model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.navigation.partner_inquiries');
    }

    private static function isSuperadmin(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isSuperadmin();
    }

    public static function canAccess(): bool
    {
        return self::isSuperadmin();
    }

    public static function canViewAny(): bool
    {
        return self::isSuperadmin();
    }

    public static function canView(Model $record): bool
    {
        return self::isSuperadmin();
    }

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
        return self::isSuperadmin();
    }

    public static function canDeleteAny(): bool
    {
        return self::isSuperadmin();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label(__('admin.partner_inquiries.received'))->dateTime('Y-m-d H:i', 'Europe/Ljubljana')->sortable(),
                Tables\Columns\TextColumn::make('company')->label(__('admin.partner_inquiries.company'))->searchable(),
                Tables\Columns\TextColumn::make('contact_name')->label(__('admin.partner_inquiries.contact_name'))->searchable(),
                Tables\Columns\TextColumn::make('email')->label(__('admin.partner_inquiries.email'))->searchable(),
                Tables\Columns\TextColumn::make('type')->label(__('admin.partner_inquiries.type'))->badge()
                    ->formatStateUsing(fn (PartnerInquiryType|string|null $state): string => self::typeLabel($state)),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options(collect(PartnerInquiryType::cases())->mapWithKeys(fn (PartnerInquiryType $t): array => [$t->value => self::typeLabel($t)])->all()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('created_at')->label(__('admin.partner_inquiries.received'))->dateTime('Y-m-d H:i', 'Europe/Ljubljana'),
            TextEntry::make('company')->label(__('admin.partner_inquiries.company')),
            TextEntry::make('contact_name')->label(__('admin.partner_inquiries.contact_name')),
            TextEntry::make('email')->label(__('admin.partner_inquiries.email'))->copyable(),
            TextEntry::make('type')->label(__('admin.partner_inquiries.type'))->badge()
                ->formatStateUsing(fn (PartnerInquiryType|string|null $state): string => self::typeLabel($state)),
            TextEntry::make('locale')->label(__('admin.partner_inquiries.locale'))->placeholder('—'),
            TextEntry::make('message')->label(__('admin.partner_inquiries.message'))->columnSpanFull()->extraAttributes(['class' => 'whitespace-pre-line']),
            TextEntry::make('retention')->hiddenLabel()->state(__('admin.partner_inquiries.retention_note'))->columnSpanFull()->color('gray'),
        ]);
    }

    private static function typeLabel(PartnerInquiryType|string|null $state): string
    {
        $value = $state instanceof PartnerInquiryType ? $state->value : (string) $state;

        return __('admin.partner_inquiries.types.'.$value);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPartnerInquiries::route('/'),
            'view' => Pages\ViewPartnerInquiry::route('/{record}'),
        ];
    }
}
