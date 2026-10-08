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
            Actions\DeleteAction::make()
                ->before(fn (Actions\DeleteAction $action) => BreedConfigResource::refuseBreakingDelete($action, [$this->getRecord()->getKey()])),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['feed_windows'] = BreedConfigResource::feedWindowsToForm($data['feed_windows'] ?? []);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['feed_windows'] = BreedConfigResource::feedWindowsFromForm($data['feed_windows'] ?? []);

        return $data;
    }
}
