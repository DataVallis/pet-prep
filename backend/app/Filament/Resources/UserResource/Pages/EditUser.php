<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /**
     * No plain user delete (M2-08) — it would bypass AccountDeletionService
     * (cascades through deprecated mirrors, orphaned media files). A family is
     * deleted as a whole with "Delete family" (superadmin; families with a
     * superadmin member can't be deleted here).
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('deleteFamily')
                ->label('Delete family')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn (User $record): bool => UserResource::deletableFamilyOf($record) !== null)
                ->requiresConfirmation()
                ->modalHeading('Delete the whole family?')
                ->modalDescription(fn (User $record): string => UserResource::deleteFamilyDescription($record))
                ->modalSubmitActionLabel('Delete family permanently')
                ->action(function (User $record): void {
                    UserResource::runDeleteFamily($record);
                    $this->redirect(UserResource::getUrl('index'));
                }),
        ];
    }
}
