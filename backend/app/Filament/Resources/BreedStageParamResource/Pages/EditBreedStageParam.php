<?php

namespace App\Filament\Resources\BreedStageParamResource\Pages;

use App\Filament\Resources\BreedStageParamResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBreedStageParam extends EditRecord
{
    protected static string $resource = BreedStageParamResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['value'] = BreedStageParamResource::valueToForm($this->getRecord()->value);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['value'] = BreedStageParamResource::valueFromForm($data['value'] ?? null);

        return $data;
    }
}
