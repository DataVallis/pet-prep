<?php

namespace App\Filament\Resources\BreedConfigResource\Pages;

use App\Filament\Resources\BreedConfigResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBreedConfig extends EditRecord
{
    protected static string $resource = BreedConfigResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
