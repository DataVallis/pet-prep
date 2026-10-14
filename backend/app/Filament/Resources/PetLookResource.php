<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PetLookResource\Pages;
use App\Models\PetLook;
use App\Models\PetMedia;
use App\Models\User;
use App\Services\Media\PetMediaService;
use App\Services\Results\LookRegeneration;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared looks (M4-10 free pool + M5-R21 paid breed + colour) — read only,
 * with one action: regenerate the look's media (superadmin). One look's
 * files are shown by every pet of the look; regenerating costs one fal call
 * per look row, not per pet (PetMediaService::regenerateLook).
 */
class PetLookResource extends Resource
{
    protected static ?string $model = PetLook::class;

    protected static ?string $navigationIcon = 'heroicon-o-swatch';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.ai_media');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.looks.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('admin.looks.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.looks.navigation');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isSuperadmin();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount([
                'pets',
                'media as ready_media_count' => fn (Builder $q) => $q->where('status', PetMedia::STATUS_READY),
            ]))
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('breed_type')->label(__('admin.looks.breed'))
                    ->formatStateUsing(fn ($state) => is_object($state) ? $state->value : (string) $state)->sortable(),
                Tables\Columns\TextColumn::make('colour_key')->label(__('admin.looks.colour'))
                    ->placeholder(__('admin.looks.pool')),
                Tables\Columns\TextColumn::make('pool_index')->label('#'),
                Tables\Columns\TextColumn::make('pets_count')->label(__('admin.looks.pets')),
                Tables\Columns\TextColumn::make('ready_media_count')->label(__('admin.looks.ready_media')),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('colour_key')->label(__('admin.looks.kind'))
                    ->nullable()
                    ->trueLabel(__('admin.looks.kind_shared'))
                    ->falseLabel(__('admin.looks.kind_pool')),
            ])
            ->actions([
                Tables\Actions\Action::make('regenerate')
                    ->label(__('admin.looks.regenerate'))
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->modalDescription(__('admin.looks.regenerate_confirm'))
                    ->visible(fn (): bool => static::canAccess())
                    ->action(function (PetLook $record): void {
                        $result = app(PetMediaService::class)->regenerateLook($record);
                        $stages = implode(', ', $result->stages);

                        $notification = match ($result->outcome) {
                            LookRegeneration::QUEUED => Notification::make()->success()
                                ->title(__('admin.looks.regenerate_queued', ['count' => $result->pets]))
                                ->body(__('admin.looks.regenerate_stages', ['stages' => $stages])),
                            LookRegeneration::BUSY => Notification::make()->warning()
                                ->title(__('admin.looks.regenerate_busy'))
                                ->body(__('admin.looks.regenerate_busy_body', ['stages' => $stages])),
                            LookRegeneration::NO_ACTIVE_PETS => Notification::make()->warning()
                                ->title(__('admin.looks.regenerate_no_pets')),
                            default => Notification::make()->danger()
                                ->title(__('admin.looks.regenerate_ai_off')),
                        };

                        $notification->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPetLooks::route('/'),
        ];
    }
}
