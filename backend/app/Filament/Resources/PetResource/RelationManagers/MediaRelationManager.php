<?php

namespace App\Filament\Resources\PetResource\RelationManagers;

use App\Enums\PetPhaseClip;
use App\Models\Pet;
use App\Models\PetMedia;
use App\Models\User;
use App\Services\AppSettingsService;
use App\Services\Media\FrozenClipRegenerator;
use App\Services\Media\GameMediaReadiness;
use App\Services\Media\MediaEntitlementService;
use App\Services\Media\MediaProfiles;
use App\Services\Media\MediaQualityService;
use App\Services\Media\PetMediaService;
use App\Services\Media\PetPreparationService;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;

/**
 * Pet media panel (M4-03 / M4-05) on the pet edit page: reference image and
 * state videos with status, cost (estimated), size, error, preview links
 * (signed, expiring) and — superadmin only — Regenerate / Generate missing.
 * M5-T08: also the state key images (kind keyframe), how each video was made
 * (loop / from key image / from reference image) and the transition clips.
 * Quality check (David 2026-10-23): a »Quality« column per clip, a »Needs review« filter
 * and »Approve anyway« (superadmin, audited) for a clip that failed the check twice or
 * was flagged by media:quality-check; »Regenerate« starts a new chain (one auto retry).
 */
class MediaRelationManager extends RelationManager
{
    protected static string $relationship = 'media';

    protected static ?string $title = 'AI media';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return self::isSuperadmin();
    }

    /** QA nit: the cost of one phase clip made from this pose image (the wand's are priced as game clips). */
    private static function poseClipCostUsd(PetMedia $record): float
    {
        $pose = $record->pose();
        foreach (PetPhaseClip::cases() as $clip) {
            if ($pose !== null && ($clip->startPose() === $pose || $clip->endPose() === $pose)) {
                return app(PetMediaService::class)->phaseClipCostUsd($clip);
            }
        }

        return app(MediaProfiles::class)->clipVideo('school')->estimatedCostUsd();
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

        // David 2026-10-28: school / games stay locked until their own clips are shown.
        $activities = [];
        foreach (app(GameMediaReadiness::class)->activities($pet) as $game => $state) {
            $activities[] = $game.': '.match (true) {
                $state['ready'] => 'open',
                $state['failed'] !== [] => 'LOCKED — failed: '.implode(', ', $state['failed']).' (auto retry hourly; then »Generate missing« — or Regenerate for own media / Approve anyway for Needs review)',
                default => 'preparing ('.count($state['missing']).' clip(s) to come)',
            };
        }

        return sprintf('Preparation (birth / purchase → everything stored): %s · Media pipeline (first slot → last file stored): %s', $preparation, $fmt($timing['pipeline_seconds']))
            .($activities === [] ? '' : ' · Activities: '.implode(' · ', $activities));
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
                        // Frugal clips (QA #451 m6): a target served from its source (a transition: the
                        // legacy reversal, replaced by media:frugal-unreverse — David 2026-10-30).
                        $record->frugal_source !== null && $record->isPhase() => __('admin.features.frugal_made_as_phase'),
                        $record->frugal_source !== null && $record->isTransition() => __('admin.features.frugal_made_as_transition'),
                        $record->isVideo() && self::madeAs($record) === PetMedia::RECIPE_LOOP => 'loop (key image → key image, frozen — regenerate)',
                        $record->isVideo() && self::madeAs($record) === PetMedia::RECIPE_START_ONLY => 'loop (from key image, start frame only)',
                        $record->isPhase() && self::madeAs($record) === PetMedia::RECIPE_START_ONLY => 'start frame only',
                        $record->isVideo() && self::madeAs($record) === PetMedia::RECIPE_POSE => 'from key image',
                        $record->isVideo() => 'from reference image',
                        $record->isTransition() => 'pose → pose',
                        $record->isPhase() => sprintf('phase clip %ss (pose → pose, plays once)', rtrim(rtrim(number_format((float) ($record->stored_duration_seconds ?? $record->duration_seconds), 1), '0'), '.') ?: '3'),
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
                // Quality check (David 2026-10-23): the verdict of the stored file / the waiting candidate.
                Tables\Columns\TextColumn::make('quality')->label('Quality')
                    ->badge()
                    ->getStateUsing(fn (PetMedia $record): ?string => self::qualityLabel($record))
                    ->color(fn (?string $state): string => match ($state) {
                        'passed', 'approved' => 'success',
                        'needs review', 'flagged' => 'danger',
                        'would fail (shadow)' => 'warning',
                        'checking', 'retrying' => 'info',
                        null => 'gray',
                        default => 'warning',
                    })
                    ->description(fn (PetMedia $record): ?string => self::qualityDetail($record))
                    ->placeholder('—'),
                // QA #363 M3: what »Approve anyway« would approve — the candidate and the contact sheet.
                Tables\Columns\TextColumn::make('review_candidate')->label('Candidate')
                    ->getStateUsing(fn (PetMedia $record): ?string => app(MediaQualityService::class)->candidateUrl(self::qualityRow($record)) !== null ? 'Open candidate' : null)
                    ->url(fn (PetMedia $record): ?string => self::isSuperadmin() ? app(MediaQualityService::class)->candidateUrl(self::qualityRow($record)) : null, shouldOpenInNewTab: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('review_sheet')->label('Frames')
                    ->getStateUsing(fn (PetMedia $record): ?string => app(MediaQualityService::class)->latestSheetCheck(self::qualityRow($record)) !== null ? 'Open contact sheet' : null)
                    ->url(function (PetMedia $record): ?string {
                        $check = self::isSuperadmin() ? app(MediaQualityService::class)->latestSheetCheck(self::qualityRow($record)) : null;

                        return $check !== null ? app(MediaQualityService::class)->sheetUrl($check) : null;
                    }, shouldOpenInNewTab: true)
                    ->placeholder('—'),
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
            ->filters([
                Tables\Filters\Filter::make('needs_review')
                    ->label('Needs review / would fail (quality check)')
                    ->query(fn (Builder $query): Builder => $query->needingReview()),
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
                // Fix 2026-10-23: remake loops / same-pose phase clips stored the frozen way
                // (same start + end frame on Kling) — FrozenClipRegenerator, audited.
                Tables\Actions\Action::make('regenerateFrozen')
                    ->label('Regenerate frozen clips')
                    ->icon('heroicon-o-play')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription(function (): string {
                        $plan = app(FrozenClipRegenerator::class)->plan($this->pet()->id);

                        return sprintf('%d loop(s) + %d school / game clip(s) were made with the same start and end frame and play almost frozen. They are remade from the start frame only (estimated $%.2f, counts against the AI budget).%s The current clips stay visible until the new ones are stored.',
                            $plan['loops'], $plan['phases'], $plan['cost_usd'],
                            $plan['looks'] !== [] ? ' This pet shows a shared look: the look\'s clips are remade once and every pet on that look gets them.' : '');
                    })
                    ->visible(fn (): bool => self::isSuperadmin() && app(FrozenClipRegenerator::class)->plan($this->pet()->id)['rows']->isNotEmpty())
                    // QA #361 m5: superadmin only, also for a direct Livewire call (not just hidden).
                    ->authorize(fn (): bool => self::isSuperadmin())
                    ->action(function (): void {
                        $user = auth()->user();
                        $result = app(FrozenClipRegenerator::class)->regenerate($user instanceof User ? $user : null, $this->pet()->id);

                        Notification::make()
                            ->title(match ($result['outcome']) {
                                'queued' => sprintf('%d frozen clip(s) queued (~$%.2f; pre-checked against the budget, each clip is still checked by the guard)', $result['queued'], $result['cost_usd']),
                                'busy' => 'Another run is in progress — try again in a minute',
                                'budget' => sprintf('Not queued: $%.2f does not fit the AI budget now', $result['cost_usd']),
                                'ai_off' => 'Not queued: fal.ai disabled or its balance exhausted',
                                default => 'No frozen clips',
                            })
                            ->status($result['outcome'] === 'queued' ? 'success' : 'warning')
                            ->send();
                    }),
            ])
            ->actions([
                // Quality check (David 2026-10-23): show a clip that failed the check twice /
                // was flagged anyway. Superadmin only (also for a direct Livewire call), audited.
                Tables\Actions\Action::make('approveQuality')
                    ->label('Approve anyway')
                    ->icon('heroicon-o-check-badge')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription(fn (PetMedia $record): string => sprintf('This clip failed the quality check (%s). Approving shows it to the children as it is. Open the file first.',
                        self::qualityDetail($record) ?? 'no reason recorded'))
                    ->visible(fn (PetMedia $record): bool => self::isSuperadmin() && self::reviewable($record))
                    ->authorize(fn (): bool => self::isSuperadmin())
                    ->action(function (PetMedia $record): void {
                        $user = auth()->user();
                        $done = $user instanceof User && app(MediaQualityService::class)->approve($record, $user);

                        Notification::make()
                            ->title($done ? 'Approved — the clip is shown now' : 'Nothing to approve (changed meanwhile)')
                            ->status($done ? 'success' : 'warning')
                            ->send();
                    }),
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
                                $record->state, app(PetMediaService::class)->keyframeCostUsd(), self::poseClipCostUsd($record)),
                            $record->isPhase() => sprintf('New %s phase clip (~$%.2f). The current clip stays visible until the new one is stored.',
                                $record->state, $record->phaseClip() !== null ? app(PetMediaService::class)->phaseClipCostUsd($record->phaseClip()) : $profiles->clipVideo('school')->estimatedCostUsd()),
                            default => sprintf('New %s clip (~$%.2f). The current clip stays visible until the new one is stored.',
                                $record->state, $profiles->stateVideo()->estimatedCostUsd()),
                        };
                    })
                    // M5-T08b (QA m1): no key image / transition regenerate while state media is switched off.
                    ->visible(fn (PetMedia $record): bool => self::isSuperadmin() && ! $record->isInFlight()
                        && (! ($record->isPoseStill() || $record->isPoseClip()) || app(AppSettingsService::class)->stateMedia()))
                    ->action(function (PetMedia $record): void {
                        // Frugal clips (QA #451 m6): a target left to its source is not made — say why.
                        if (app(MediaEntitlementService::class)->slotLeftToSource($record, fresh: true)) {
                            Notification::make()
                                ->title(__('admin.features.frugal_regenerate_refused', ['source' => (string) MediaEntitlementService::frugalSourceOf((string) $record->kind, (string) $record->state)]))
                                ->warning()
                                ->send();

                            return;
                        }

                        $queued = app(PetMediaService::class)->regenerate($record);

                        Notification::make()
                            ->title($queued ? 'Regeneration queued' : 'Cannot regenerate (fal disabled, pet inactive or in progress)')
                            ->status($queued ? 'success' : 'warning')
                            ->send();
                    }),
            ]);
    }

    /** The row the quality check ran on: a pool pet's slot → its look row (checked once per look row). */
    private static function qualityRow(PetMedia $record): PetMedia
    {
        return $record->look_media_id !== null && $record->lookMedia !== null ? $record->lookMedia : $record;
    }

    /** A slot (or the look row it mirrors) whose clip waits for »Approve anyway«. */
    private static function reviewable(PetMedia $record): bool
    {
        $row = self::qualityRow($record);

        return $record->needsReview() || $record->isFlaggedQuality() || $row->needsReview() || $row->isFlaggedQuality();
    }

    /** Quality badge: the waiting candidate first, else the verdict of the stored file. */
    private static function qualityLabel(PetMedia $record): ?string
    {
        if (! $record->isClip()) {
            return null;
        }

        $row = self::qualityRow($record);

        return match (true) {
            $row->needsReview() => 'needs review',
            $row->status === PetMedia::STATUS_RUNNING && filled($row->candidate_path) => 'checking',
            $row->isInFlight() && $row->quality_retry_generation !== null && (int) $row->quality_retry_generation === (int) $row->generation => 'retrying',
            $row->quality === PetMedia::QUALITY_PASSED => 'passed',
            $row->quality === PetMedia::QUALITY_PASSED_TECHNICAL => 'not AI-checked',
            $row->quality === PetMedia::QUALITY_UNCHECKED => 'unchecked',
            $row->quality === PetMedia::QUALITY_FLAGGED => 'flagged',
            // QA #363 M1: shadow mode — the would-be verdict (the clip is shown).
            $row->quality === PetMedia::QUALITY_SHADOW_FAILED => 'would fail (shadow)',
            $row->quality === PetMedia::QUALITY_APPROVED => 'approved',
            default => null,
        };
    }

    private static function qualityDetail(PetMedia $record): ?string
    {
        $row = $record->look_media_id !== null && $record->lookMedia !== null ? $record->lookMedia : $record;
        $text = $row->needsReview() ? $row->error : ($row->quality_reason ?? ($row->isInFlight() && $row->quality_retry_generation === $row->generation ? $row->error : null));

        return $text !== null ? mb_strimwidth($text, 0, 160, '…') : null;
    }

    /** How the shown file was made (stored_recipe; nothing stored yet → how it is being submitted). */
    private static function madeAs(PetMedia $record): ?string
    {
        return $record->isServable() ? $record->stored_recipe : $record->recipe;
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
            $plan['upgrade'] ? ' This pet is on an older media set: it gets the missing state key images / loops / transitions and the school / game phase clips now.' : '',
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
