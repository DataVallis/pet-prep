<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ItemMediaResource\Pages;
use App\Models\ItemMedia;
use App\Models\User;
use App\Services\Media\ItemMediaService;
use App\Services\Media\MediaProfiles;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * M6-W03 »Želje« item content (ItemMediaService) — superadmin only: every catalogue picture
 * and every look / pet row with its status, cost and self-healing state. The one action is
 * Regenerate (a new chain; a shared look row serves every pet of the look, 0 $ for them).
 * Nothing here is ever needed — the scheduled `media:item-media` heals by itself.
 */
class ItemMediaResource extends Resource
{
    protected static ?string $model = ItemMedia::class;

    protected static ?string $navigationIcon = 'heroicon-o-gift-top';

    protected static ?string $navigationLabel = 'Item media';

    protected static ?string $modelLabel = 'item media';

    protected static ?string $pluralModelLabel = 'Item media';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.ai_media');
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
        return self::configure($table)
            ->filters([
                Tables\Filters\SelectFilter::make('scope')->options([
                    ItemMedia::SCOPE_CATALOGUE => 'Catalogue picture',
                    ItemMedia::SCOPE_LOOK => 'Shared look',
                    ItemMedia::SCOPE_PET => 'Own pet media',
                ]),
                Tables\Filters\SelectFilter::make('status')->options([
                    ItemMedia::STATUS_PENDING => 'pending',
                    ItemMedia::STATUS_RUNNING => 'running',
                    ItemMedia::STATUS_READY => 'ready',
                    ItemMedia::STATUS_FAILED => 'failed',
                ]),
                Tables\Filters\Filter::make('exhausted')->label('Given up (shown without content)')
                    ->query(fn ($q) => $q->whereNotNull('exhausted_at')),
            ]);
    }

    /** Columns + Regenerate, shared with the pet's »Item media« relation manager. */
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('scope')->badge(),
                Tables\Columns\TextColumn::make('owner')->label('Owner')
                    ->getStateUsing(fn (ItemMedia $r): string => $r->pet_look_id !== null ? 'look #'.$r->pet_look_id : ($r->pet_id !== null ? 'pet #'.$r->pet_id : '—')),
                Tables\Columns\TextColumn::make('design')->searchable(),
                Tables\Columns\TextColumn::make('kind')->badge(),
                Tables\Columns\TextColumn::make('life_stage')->label('Stage')->placeholder('—'),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        ItemMedia::STATUS_READY => 'success',
                        ItemMedia::STATUS_FAILED => 'danger',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('error')->label('Error')->limit(60)->placeholder('—')
                    ->getStateUsing(fn (ItemMedia $r): ?string => $r->error_reason !== null ? $r->error_reason.': '.$r->error : null),
                Tables\Columns\TextColumn::make('auto_retries')->label('Retries'),
                Tables\Columns\TextColumn::make('prompt_variant')->label('Variant'),
                Tables\Columns\TextColumn::make('exhausted_at')->label('Given up')->dateTime()->placeholder('—'),
                Tables\Columns\TextColumn::make('generation')->label('Gen.'),
                Tables\Columns\TextColumn::make('cost_usd')->label('Cost (est.)')->money('USD'),
                Tables\Columns\TextColumn::make('file')->label('File')
                    ->getStateUsing(fn (ItemMedia $r): ?string => $r->isServable() ? 'open' : null)->placeholder('—')
                    ->url(fn (ItemMedia $r): ?string => $r->isServable()
                        ? app(ItemMediaService::class)->signedUrl($r, $r->scope === ItemMedia::SCOPE_PET ? $r->pet : $r->look?->pets()->first())
                        : null, shouldOpenInNewTab: true),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->defaultKeySort()
            ->actions([
                Tables\Actions\Action::make('regenerate')
                    ->label('Regenerate')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->modalDescription(function (ItemMedia $r): string {
                        $profiles = app(MediaProfiles::class);
                        $cost = match ($r->kind) {
                            ItemMedia::KIND_PICTURE => $profiles->itemPicture()?->estimatedCostUsd(),
                            ItemMedia::KIND_IMAGE => $profiles->itemImage()?->estimatedCostUsd(),
                            default => $profiles->clipVideo('item')->estimatedCostUsd(),
                        } ?? 0.0;

                        return sprintf('A new AI generation of this %s (≈ $%.2f, AI budget). ', $r->label(), $cost)
                            .($r->scope === ItemMedia::SCOPE_LOOK ? 'Every pet of the look gets the new file (0 $ for them). ' : '')
                            .'The current file stays visible until the new one is stored; a new key image also remakes its clip.';
                    })
                    ->action(function (ItemMedia $r): void {
                        $done = app(ItemMediaService::class)->regenerate($r);
                        Notification::make()->title($done ? 'Queued' : 'Not now — it is being generated')->{$done ? 'success' : 'warning'}()->send();
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListItemMedia::route('/'),
        ];
    }
}
