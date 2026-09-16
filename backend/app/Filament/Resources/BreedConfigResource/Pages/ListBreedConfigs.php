<?php

namespace App\Filament\Resources\BreedConfigResource\Pages;

use App\Filament\Resources\BreedConfigResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBreedConfigs extends ListRecords
{
    protected static string $resource = BreedConfigResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
