<?php

namespace App\Filament\Resources\PetResource\RelationManagers;

use App\Models\Pet;
use App\Models\PetMedia;
use App\Models\User;
use App\Services\Media\MediaProfiles;
use App\Services\Media\PetMediaService;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;

/**
 * Pet media panel (M4-03 / M4-05) on the pet edit page: reference image and
 * state videos with status, cost (estimated), size, error, preview links
 * (signed, expiring) and — superadmin only — Regenerate / Generate missing.
 */
class MediaRelationManager extends RelationManager
{
    protected static string $relationship = 'media';

    protected static ?string $title = 'AI media';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return self::isSuperadmin();
    }

    private static function isSuperadmin(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isSuperadmin();
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                Tables\Columns\ImageColumn::make('preview')
                    ->label('Preview')
                    ->getStateUsing(fn (PetMedia $record): ?string => $record->isImage() && $record->isServable()
                        ? app(PetMediaService::class)->signedUrl($record)
                        : null)
                    ->height(64),
                Tables\Columns\TextColumn::make('kind')->badge(),
                Tables\Columns\TextColumn::make('state')->placeholder('—'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        PetMedia::STATUS_READY => 'success',
                        PetMedia::STATUS_FAILED => 'danger',
                        PetMedia::STATUS_RUNNING => 'info',
                        default => 'warning',
                    })
                    ->description(fn (PetMedia $record): ?string => $record->error_reason ?? ($record->status === PetMedia::STATUS_FAILED ? $record->error : null)),
                Tables\Columns\TextColumn::make('profile')->placeholder('—'),
                Tables\Columns\TextColumn::make('generation')->label('Gen.'),
                Tables\Columns\TextColumn::make('cost_usd')
                    ->label('Cost (est.)')
                    ->formatStateUsing(fn ($state): string => sprintf('$%.3f', (float) $state)),
                Tables\Columns\TextColumn::make('bytes')
                    ->label('Size')
                    ->formatStateUsing(fn ($state): string => $state ? Number::fileSize((int) $state, 1) : '—'),
                Tables\Columns\TextColumn::make('open')
                    ->label('File')
                    ->getStateUsing(fn (PetMedia $record): ?string => $record->isServable() ? 'Open' : null)
                    ->placeholder('—')
                    ->url(fn (PetMedia $record): ?string => $record->isServable() ? app(PetMediaService::class)->signedUrl($record) : null, shouldOpenInNewTab: true),
                Tables\Columns\TextColumn::make('completed_at')->dateTime()->placeholder('—'),
            ])
            ->headerActions([
                Tables\Actions\Action::make('generateMissing')
                    ->label('Generate missing')
                    ->icon('heroicon-o-sparkles')
                    ->requiresConfirmation()
                    ->modalDescription(function (): string {
                        /** @var Pet $pet */
                        $pet = $this->getOwnerRecord();
                        $plan = app(PetMediaService::class)->planMissing($pet);

                        return sprintf(
                            'Image: %s. Videos: %s. Estimated cost $%.2f (counts against the AI budget).',
                            $plan['image'],
                            implode(', ', $plan['videos']) ?: 'none',
                            $plan['cost_usd'],
                        );
                    })
                    ->visible(fn (): bool => self::isSuperadmin())
                    ->action(function (): void {
                        /** @var Pet $pet */
                        $pet = $this->getOwnerRecord();
                        $done = app(PetMediaService::class)->generateMissing($pet);

                        Notification::make()
                            ->title($done['image'] ? 'Reference image queued (videos follow)' : "{$done['videos']} video(s) queued")
                            ->status($done['image'] || $done['videos'] > 0 ? 'success' : 'warning')
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('regenerate')
                    ->label('Regenerate')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->modalDescription(function (PetMedia $record): string {
                        $profiles = app(MediaProfiles::class);

                        return $record->isImage()
                            ? sprintf('New reference image (~$%.2f), then the videos are regenerated from it (~$%.2f each). The current files stay visible until the new ones are stored.',
                                $profiles->referenceImage()->estimatedCostUsd(), $profiles->stateVideo()->estimatedCostUsd())
                            : sprintf('New %s video (~$%.2f). The current video stays visible until the new one is stored.',
                                $record->state, $profiles->stateVideo()->estimatedCostUsd());
                    })
                    ->visible(fn (PetMedia $record): bool => self::isSuperadmin() && ! $record->isInFlight())
                    ->action(function (PetMedia $record): void {
                        $queued = app(PetMediaService::class)->regenerate($record);

                        Notification::make()
                            ->title($queued ? 'Regeneration queued' : 'Cannot regenerate (fal disabled, pet inactive or in progress)')
                            ->status($queued ? 'success' : 'warning')
                            ->send();
                    }),
            ]);
    }
}
