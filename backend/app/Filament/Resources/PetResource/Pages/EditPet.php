<?php

namespace App\Filament\Resources\PetResource\Pages;

use App\Filament\Resources\PetResource;
use App\Models\Pet;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditPet extends EditRecord
{
    protected static string $resource = PetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Metric-changing writes take the same row lock as the decay tick
     * (backend/CLAUDE.md), so an admin save and a tick can't overwrite each other.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): Model {
            $locked = Pet::whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            $locked->update($data);
            $record->setRawAttributes($locked->getAttributes(), true);

            return $record;
        });
    }
}
