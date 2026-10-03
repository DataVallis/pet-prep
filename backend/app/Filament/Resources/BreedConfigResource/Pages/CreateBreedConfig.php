<?php

namespace App\Filament\Resources\BreedConfigResource\Pages;

use App\Filament\Resources\BreedConfigResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBreedConfig extends CreateRecord
{
    protected static string $resource = BreedConfigResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['feed_windows'] = BreedConfigResource::feedWindowsFromForm($data['feed_windows'] ?? []);

        return $data;
    }
}
