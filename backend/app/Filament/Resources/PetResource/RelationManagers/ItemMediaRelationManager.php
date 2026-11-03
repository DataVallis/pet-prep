<?php

namespace App\Filament\Resources\PetResource\RelationManagers;

use App\Filament\Resources\ItemMediaResource;
use App\Models\Pet;
use App\Models\User;
use App\Services\Media\ItemMediaService;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * M6-W03: the AI content of the pet's »Želje« items (superadmin) — its own rows, or the rows of
 * its shared look (made once for every pet of the look). Regenerate per row; »Generate missing«
 * starts a new chain for failed rows of the pet's current content and queues what is missing
 * (also for a shared look). Never needed — `media:item-media` heals by itself.
 */
class ItemMediaRelationManager extends RelationManager
{
    protected static string $relationship = 'itemMediaRows';

    protected static ?string $title = 'Item media';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isSuperadmin();
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return ItemMediaResource::configure($table)
            ->headerActions([
                Tables\Actions\Action::make('generateMissingItems')
                    ->label('Generate missing')
                    ->icon('heroicon-o-sparkles')
                    ->requiresConfirmation()
                    ->modalDescription('Queues the AI content of every item this pet owns that is missing for its current picture (also starts a new try for failed ones). A shared look\'s files are made once for every pet of the look. Every call goes through the AI budget.')
                    ->action(function (): void {
                        $pet = $this->getOwnerRecord();
                        $n = $pet instanceof Pet ? app(ItemMediaService::class)->generateMissing($pet) : 0;
                        Notification::make()->title($n > 0 ? "Queued {$n} item file(s)" : 'Nothing missing (or the pet\'s picture is not ready yet)')->success()->send();
                    }),
            ]);
    }
}
