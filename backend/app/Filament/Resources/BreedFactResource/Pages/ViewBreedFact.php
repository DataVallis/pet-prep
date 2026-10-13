<?php

namespace App\Filament\Resources\BreedFactResource\Pages;

use App\Filament\Resources\BreedFactResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

/**
 * One fact: metadata, source + verbatim quote, and a preview of the child
 * card and the parent card (EN + SL), with the review actions.
 */
class ViewBreedFact extends ViewRecord
{
    protected static string $resource = BreedFactResource::class;

    protected function getHeaderActions(): array
    {
        return [
            BreedFactResource::verifyAction(Actions\Action::make('verify')),
            BreedFactResource::approveAction(Actions\Action::make('approve')),
            BreedFactResource::rejectAction(Actions\Action::make('reject')),
        ];
    }
}
