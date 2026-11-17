<?php

namespace App\Filament\Resources\EmailContactResource\Pages;

use App\Filament\Resources\EmailContactResource;
use App\Services\EmailContactCsvExport;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListEmailContacts extends ListRecords
{
    protected static string $resource = EmailContactResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // CSV of exactly what the list shows (search + filters applied), for Klaviyo / partners' import.
            Action::make('export_csv')
                ->label(__('admin.email_signups.export_csv'))
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (): bool => EmailContactResource::canViewAny())
                ->action(function (): StreamedResponse {
                    $query = $this->getFilteredTableQuery();

                    return response()->streamDownload(
                        function () use ($query): void {
                            $out = fopen('php://output', 'w');
                            app(EmailContactCsvExport::class)->write($query, $out);
                            fclose($out);
                        },
                        'email-contacts-'.now()->format('Y-m-d-His').'.csv',
                        ['Content-Type' => 'text/csv; charset=UTF-8'],
                    );
                }),
        ];
    }
}
