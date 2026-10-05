<?php

namespace App\Filament\Resources\BreedStageParamResource\Pages;

use App\Filament\Resources\BreedStageParamResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBreedStageParams extends ListRecords
{
    protected static string $resource = BreedStageParamResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
