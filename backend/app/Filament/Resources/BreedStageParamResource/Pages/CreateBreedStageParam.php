<?php

namespace App\Filament\Resources\BreedStageParamResource\Pages;

use App\Filament\Resources\BreedStageParamResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBreedStageParam extends CreateRecord
{
    protected static string $resource = BreedStageParamResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['value'] = BreedStageParamResource::valueFromForm($data['value'] ?? null);

        return $data;
    }
}
