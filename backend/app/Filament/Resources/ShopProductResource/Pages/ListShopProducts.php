<?php

namespace App\Filament\Resources\ShopProductResource\Pages;

use App\Filament\Resources\ShopProductResource;
use App\Models\ShopAuditLog;
use App\Models\User;
use App\Services\Shop\ShopFiles;
use App\Services\Shop\ShopProductSync;
use App\Services\Shop\StripeException;
use App\Support\WebsiteLocale;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ListShopProducts extends ListRecords
{
    protected static string $resource = ShopProductResource::class;

    protected function getHeaderActions(): array
    {
        $t = fn (string $k): string => __('admin.shop_admin.'.$k);

        return [
            Action::make('sync')->label($t('sync'))->icon('heroicon-o-arrow-path')
                ->action(function () use ($t): void {
                    try {
                        $result = app(ShopProductSync::class)->run();
                    } catch (StripeException $e) {
                        report($e);
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }
                    Notification::make()->title($t('sync_done'))
                        ->body(collect($result)->map(fn (string $s, string $k): string => $k.': '.$s)->implode(' · '))->success()->send();
                }),
            Action::make('upload')->label($t('upload'))->icon('heroicon-o-arrow-up-tray')
                ->form([
                    Placeholder::make('have')->hiddenLabel()->content(fn (): string => $t('files').': '.ShopProductResource::filesOf('promise_kit')
                        .' · '.$t('sample_files').': '.ShopProductResource::filesOf(ShopFiles::SAMPLE)),
                    Select::make('kind')->label($t('file_kind'))->required()->options([
                        'promise_kit' => $t('file_kind_product'),
                        ShopFiles::SAMPLE => $t('file_kind_sample'),
                    ]),
                    Select::make('locale')->label($t('locale'))->required()->options(array_combine(WebsiteLocale::ALL, WebsiteLocale::ALL)),
                    FileUpload::make('file')->label($t('file'))->required()->acceptedFileTypes(['application/pdf'])
                        ->maxSize(20480)->storeFiles(false),
                ])
                ->action(function (array $data) use ($t): void {
                    $file = $data['file'];
                    if (! $file instanceof TemporaryUploadedFile || ! str_starts_with((string) file_get_contents($file->getRealPath(), false, null, 0, 5), '%PDF-')) {
                        Notification::make()->title('PDF?')->danger()->send();

                        return;
                    }
                    $kind = (string) $data['kind'];
                    $locale = (string) $data['locale'];
                    ShopFiles::disk()->put(ShopFiles::path($kind, $locale), (string) file_get_contents($file->getRealPath()));
                    /** @var User $user */
                    $user = auth()->user();
                    ShopAuditLog::record($user, 'upload_file', 'shop_product', 0, ['kind' => $kind, 'locale' => $locale]);
                    Notification::make()->title($t('upload_done'))->success()->send();
                }),
        ];
    }
}
