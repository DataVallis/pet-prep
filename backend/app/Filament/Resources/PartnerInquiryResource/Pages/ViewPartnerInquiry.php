<?php

namespace App\Filament\Resources\PartnerInquiryResource\Pages;

use App\Filament\Resources\PartnerInquiryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPartnerInquiry extends ViewRecord
{
    protected static string $resource = PartnerInquiryResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
