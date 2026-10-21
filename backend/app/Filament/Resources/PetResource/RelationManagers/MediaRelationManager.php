<?php

namespace App\Filament\Resources\PetResource\RelationManagers;

use App\Models\Pet;
use App\Models\PetMedia;
use App\Models\User;
use App\Services\AppSettingsService;
use App\Services\Media\MediaProfiles;
use App\Services\Media\PetMediaService;
use App\Services\Media\PetPreparationService;
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
 * M5-T08: also the state key images (kind keyframe), how each video was made
 * (loop / from key image / from reference image) and the transition clips.
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

    /**
     * M5-T06 (APP_REFINEMENT_SPEC §13): measured times.
     *  - Preparation = pets.preparation_ready_at − pets.preparation_started_at
     *    (birth / purchase → last entitled item stored; what the child waits for).
     *  - Media pipeline = max(pet_media.completed_at) − min(pet_media.created_at)
     *    over the stored slots (first slot created at pairing → last file stored).
     */
    public static function timingLine(Model $pet): string
    {
        if (! $pet instanceof Pet) {
            return '';
        }

        $timing = app(PetPreparationService::class)->timing($pet);
        $fmt = fn (?int $s): string => $s === null ? '—' : sprintf('%d min %02d s', intdiv($s, 60), $s % 60);

        $preparation = match (true) {
            $timing['started_at'] === null => 'not shown (ready at birth, or before M5-T06)',
            $timing['ready_at'] === null => 'running since '.$timing['started_at']->setTimezone('Europe/Ljubljana')->format('Y-m-d H:i:s'),
            default => $fmt($timing['preparation_seconds']),
        };

        return sprintf('Preparation (birth / purchase → everything stored): %s · Media pipeline (first slot → last file stored): %s', $preparation, $fmt($timing['pipeline_seconds']));
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            // M5-T08: 1 image + 5 key images + 6 loops + 2 transitions (+ behaviour); M5-T09 / T10:
            // + 7 pose images + 15 school / ball clips (dog) — one page.
            ->paginated([50, 100])
            ->defaultPaginationPageOption(50)
            // M5-T06: how long the media took (measured, no external call).
            ->description(fn (): string => self::timingLine($this->getOwnerRecord()))
            ->columns([
                Tables\Columns\ImageColumn::make('preview')
                    ->label('Preview')
                    ->getStateUsing(fn (PetMedia $record): ?string => $record->isStill() && $record->isServable()
                        ? app(PetMediaService::class)->signedUrl($record)
                        : null)
                    ->height(64),
                // M5-T08: image (reference) · keyframe (state key image) · video (state loop) · transition.
                Tables\Columns\TextColumn::make('kind')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        PetMedia::KIND_KEYFRAME => 'info',
                        PetMedia::KIND_TRANSITION => 'warning',
                        // M5-T09 / T10: pose images of the phase clips, school / game phase clips.
                        PetMedia::KIND_POSE => 'info',
                        PetMedia::KIND_PHASE => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('state')->placeholder('—'),
                Tables\Columns\TextColumn::make('recipe')->label('Made as')
                    ->getStateUsing(fn (PetMedia $record): ?string => match (true) {
                        $record->isVideo() && $record->recipe === PetMedia::RECIPE_LOOP => 'loop (key image → key image)',
                        $record->isVideo() && $record->recipe === PetMedia::RECIPE_POSE => 'from key image',
                        $record->isVideo() => 'from reference image',
                        $record->isTransition() => 'pose → pose',
                        $record->isPhase() => sprintf('phase clip %ss (pose → pose, plays once)', rtrim(rtrim(number_format((float) $record->duration_seconds, 1), '0'), '.') ?: '3'),
                        $record->isKeyframe(), $record->isPose() => 'edit of reference',
                        default => null,
                    })
                    ->placeholder('—'),
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
                // M5-T08b (QA minor 7): "Generate missing" never upgrades a pet from before
                // state media; the upgrade is its own action with its cost.
                Tables\Actions\Action::make('generateMissing')
                    ->label('Generate missing')
                    ->icon('heroicon-o-sparkles')
                    ->requiresConfirmation()
                    ->modalDescription(fn (): string => self::planText(app(PetMediaService::class)->planMissing($this->pet(), upgradeStateMedia: false)))
                    ->visible(fn (): bool => self::isSuperadmin())
                    ->action(function (): void {
                        $done = app(PetMediaService::class)->generateMissing($this->pet(), upgradeStateMedia: false);
                        self::queuedNotification($done);
                    }),
                Tables\Actions\Action::make('upgradeStateMedia')
                    ->label('Upgrade to state media')
                    ->icon('heroicon-o-arrow-up-circle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription(fn (): string => self::planText(app(PetMediaService::class)->planMissing($this->pet(), upgradeStateMedia: true)))
                    // Only a pet from before state media that would get them now.
                    ->visible(fn (): bool => self::isSuperadmin() && app(PetMediaService::class)->planMissing($this->pet(), upgradeStateMedia: true)['upgrade'])
                    ->action(function (): void {
                        $done = app(PetMediaService::class)->generateMissing($this->pet(), upgradeStateMedia: true);
                        self::queuedNotification($done);
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('regenerate')
                    ->label('Regenerate')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->modalDescription(function (PetMedia $record): string {
                        $profiles = app(MediaProfiles::class);

                        return match (true) {
                            $record->isImage() => sprintf('New reference image (~$%.2f), then the key images and videos are regenerated from it (~$%.2f per key image, ~$%.2f per video). The current files stay visible until the new ones are stored.',
                                $profiles->referenceImage()->estimatedCostUsd(), app(PetMediaService::class)->keyframeCostUsd(), $profiles->stateVideo()->estimatedCostUsd()),
                            $record->isKeyframe() => sprintf('New %s key image (~$%.2f), then its loop and transitions are remade from it (~$%.2f each). The current files stay visible until the new ones are stored.',
                                $record->state, app(PetMediaService::class)->keyframeCostUsd(), $profiles->stateVideo()->estimatedCostUsd()),
                            $record->isPose() => sprintf('New %s pose image (~$%.2f), then the school / game phase clips through it are remade (~$%.2f each). The current files stay visible until the new ones are stored.',
                                $record->state, app(PetMediaService::class)->keyframeCostUsd(), $profiles->clipVideo('school')->estimatedCostUsd()),
                            $record->isPhase() => sprintf('New %s phase clip (~$%.2f). The current clip stays visible until the new one is stored.',
                                $record->state, $profiles->clipVideo('school')->estimatedCostUsd()),
                            default => sprintf('New %s clip (~$%.2f). The current clip stays visible until the new one is stored.',
                                $record->state, $profiles->stateVideo()->estimatedCostUsd()),
                        };
                    })
                    // M5-T08b (QA m1): no key image / transition regenerate while state media is switched off.
                    ->visible(fn (PetMedia $record): bool => self::isSuperadmin() && ! $record->isInFlight()
                        && (! ($record->isPoseStill() || $record->isPoseClip()) || app(AppSettingsService::class)->stateMedia()))
                    ->action(function (PetMedia $record): void {
                        $queued = app(PetMediaService::class)->regenerate($record);

                        Notification::make()
                            ->title($queued ? 'Regeneration queued' : 'Cannot regenerate (fal disabled, pet inactive or in progress)')
                            ->status($queued ? 'success' : 'warning')
                            ->send();
                    }),
            ]);
    }

    private function pet(): Pet
    {
        /** @var Pet $pet */
        $pet = $this->getOwnerRecord();

        return $pet;
    }

    /**
     * @param  array{image: string, videos: list<string>, keyframes: list<string>, transitions: list<string>, poses?: list<string>, phases?: list<string>, upgrade: bool, cost_usd: float}  $plan
     */
    private static function planText(array $plan): string
    {
        return sprintf(
            'Image: %s. Videos: %s. Key images: %s. Transitions: %s. Pose images: %s. School / game clips: %s.%s Estimated cost $%.2f (counts against the AI budget).',
            $plan['image'],
            implode(', ', $plan['videos']) ?: 'none',
            implode(', ', $plan['keyframes']) ?: 'none',
            implode(', ', $plan['transitions']) ?: 'none',
            implode(', ', $plan['poses'] ?? []) ?: 'none',
            count($plan['phases'] ?? []) ?: 'none',
            $plan['upgrade'] ? ' This pet is from before state key images: it gets them now (videos are remade as loops).' : '',
            $plan['cost_usd'],
        );
    }

    /** @param  array{image: bool, videos: int}  $done */
    private static function queuedNotification(array $done): void
    {
        Notification::make()
            ->title($done['image'] ? 'Reference image queued (videos follow)' : "{$done['videos']} item(s) queued (key images first, their loops and transitions follow)")
            ->status($done['image'] || $done['videos'] > 0 ? 'success' : 'warning')
            ->send();
    }
}
