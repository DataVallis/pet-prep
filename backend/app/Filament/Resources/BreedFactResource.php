<?php

namespace App\Filament\Resources;

use App\Enums\BreedFactAudience;
use App\Enums\BreedFactCategory;
use App\Enums\BreedFactStatus;
use App\Enums\BreedType;
use App\Filament\Resources\BreedFactResource\Pages;
use App\Models\BreedFact;
use App\Models\User;
use App\Services\BreedFactImportService;
use App\Services\BreedFactReviewService;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * »Ali si vedel?« breed facts (M5-R09b, DID_YOU_KNOW_SPEC §5). Content is
 * written in git (docs/research/breed-facts, imported with
 * `breeds:import-facts`) — here only the review state changes: draft →
 * verified (source fields required) → approved (superadmin; records who and
 * when). Only approved facts reach the apps. View page = preview of the
 * child card and the parent card + the verbatim quote and the source link.
 */
class BreedFactResource extends Resource
{
    protected static ?string $model = BreedFact::class;

    protected static ?string $navigationIcon = 'heroicon-o-light-bulb';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.configuration');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.breed_facts');
    }

    public static function getModelLabel(): string
    {
        return __('admin.navigation.breed_fact_model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.navigation.breed_facts');
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

    /** @return array<string, string> */
    public static function breedOptions(): array
    {
        $options = [];
        foreach (BreedType::cases() as $breed) {
            if (! in_array($breed, BreedFactImportService::NON_PEDIGREE, true)) {
                $options[$breed->value] = (string) __('breeds.'.$breed->value);
            }
        }

        return $options;
    }

    /** @param  list<string>  $values  @return array<string, string> */
    private static function labelled(string $group, array $values): array
    {
        return array_combine($values, array_map(fn (string $v): string => (string) __("admin.breed_facts.{$group}.{$v}"), $values));
    }

    public static function statusColor(BreedFactStatus $status): string
    {
        return match ($status) {
            BreedFactStatus::Approved => 'success',
            BreedFactStatus::Verified => 'info',
            BreedFactStatus::Draft => 'gray',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            // QA PR #210: breed, then the display order, then id (stable pages).
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('breed')->orderBy('sort')->orderBy('id'))
            ->defaultKeySort()
            ->columns([
                Tables\Columns\TextColumn::make('key')->label(__('admin.breed_facts.key'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('breed')->label(__('admin.breed_facts.breed'))->badge()->sortable()
                    ->formatStateUsing(fn (BreedType $state): string => (string) __('breeds.'.$state->value)),
                Tables\Columns\TextColumn::make('category')->label(__('admin.breed_facts.category'))->badge()
                    ->formatStateUsing(fn (BreedFactCategory $state): string => (string) __('admin.breed_facts.categories.'.$state->value)),
                Tables\Columns\TextColumn::make('audience')->label(__('admin.breed_facts.audience'))->badge()
                    ->formatStateUsing(fn (BreedFactAudience $state): string => (string) __('admin.breed_facts.audiences.'.$state->value)),
                Tables\Columns\TextColumn::make('title_sl')->label(__('admin.breed_facts.title_sl'))->wrap()->searchable(),
                Tables\Columns\TextColumn::make('title_en')->label(__('admin.breed_facts.title_en'))->wrap()->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('source_name')->label(__('admin.breed_facts.source'))
                    ->url(fn (BreedFact $record): string => $record->source_url, shouldOpenInNewTab: true)
                    ->description(fn (BreedFact $record): string => $record->source_id),
                Tables\Columns\TextColumn::make('game_rule_key')->label(__('admin.breed_facts.game_rule'))->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('sort')->label(__('admin.breed_facts.sort'))->numeric()->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('status')->label(__('admin.breed_facts.status'))->badge()
                    ->formatStateUsing(fn (BreedFactStatus $state): string => (string) __('admin.breed_facts.statuses.'.$state->value))
                    ->color(fn (BreedFactStatus $state): string => self::statusColor($state)),
                Tables\Columns\TextColumn::make('verified_by')->label(__('admin.breed_facts.verified_by'))->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('approver.name')->label(__('admin.breed_facts.approved_by'))->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('approved_at')->label(__('admin.breed_facts.approved_at'))->dateTime()->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('breed')->label(__('admin.breed_facts.breed'))->options(self::breedOptions()),
                Tables\Filters\SelectFilter::make('status')->label(__('admin.breed_facts.status'))
                    ->options(self::labelled('statuses', BreedFactStatus::values())),
                Tables\Filters\SelectFilter::make('audience')->label(__('admin.breed_facts.audience'))
                    ->options(self::labelled('audiences', BreedFactAudience::values())),
                Tables\Filters\SelectFilter::make('category')->label(__('admin.breed_facts.category'))
                    ->options(self::labelled('categories', BreedFactCategory::values())),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                self::verifyAction(Tables\Actions\Action::make('verify')),
                self::approveAction(Tables\Actions\Action::make('approve')),
                self::rejectAction(Tables\Actions\Action::make('reject')),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('approveSelected')
                    ->label(__('admin.breed_facts.approve_selected'))
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(__('admin.breed_facts.approve_selected_help'))
                    ->visible(fn (): bool => self::admin()?->can('approveAny', BreedFact::class) === true)
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records): void {
                        $result = app(BreedFactReviewService::class)->approveMany($records, self::admin());
                        Notification::make()
                            ->title(__('admin.breed_facts.approved_many', $result))
                            ->success()
                            ->send();
                    }),
            ]);
    }

    private static function admin(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * Run a review step and report the result.
     *
     * @param  callable(BreedFactReviewService, BreedFact, User): mixed  $step
     */
    private static function run(BreedFact $record, string $done, callable $step): void
    {
        try {
            $step(app(BreedFactReviewService::class), $record, self::admin());
            Notification::make()->title(__($done))->success()->send();
        } catch (InvalidArgumentException $e) {
            Notification::make()->title(__('admin.breed_facts.not_changed'))->body($e->getMessage())->warning()->send();
        }
    }

    /** @template T of Tables\Actions\Action|\Filament\Actions\Action  @param  T  $action  @return T */
    public static function verifyAction($action)
    {
        return $action
            ->label(__('admin.breed_facts.verify'))
            ->icon('heroicon-o-magnifying-glass-circle')
            ->color('info')
            ->requiresConfirmation()
            ->modalDescription(__('admin.breed_facts.verify_help'))
            ->visible(fn (BreedFact $record): bool => $record->status === BreedFactStatus::Draft && self::admin()?->can('verify', $record) === true)
            ->action(fn (BreedFact $record) => self::run($record, 'admin.breed_facts.verified', fn ($s, $r, $u) => $s->verify($r, $u)));
    }

    /** @template T of Tables\Actions\Action|\Filament\Actions\Action  @param  T  $action  @return T */
    public static function approveAction($action)
    {
        return $action
            ->label(__('admin.breed_facts.approve'))
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(__('admin.breed_facts.approve_help'))
            ->visible(fn (BreedFact $record): bool => $record->status === BreedFactStatus::Verified && self::admin()?->can('approve', $record) === true)
            ->action(fn (BreedFact $record) => self::run($record, 'admin.breed_facts.approved', fn ($s, $r, $u) => $s->approve($r, $u)));
    }

    /** @template T of Tables\Actions\Action|\Filament\Actions\Action  @param  T  $action  @return T */
    public static function rejectAction($action)
    {
        return $action
            ->label(__('admin.breed_facts.reject'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('admin.breed_facts.reject_help'))
            ->visible(fn (BreedFact $record): bool => $record->status !== BreedFactStatus::Draft && self::admin()?->can('reject', $record) === true)
            ->action(fn (BreedFact $record) => self::run($record, 'admin.breed_facts.rejected', fn ($s, $r, $u) => $s->reject($r, $u)));
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $card = fn (string $lang, bool $parent): \Closure => function (BreedFact $record) use ($lang, $parent): string {
            $lines = [
                strtoupper((string) __('admin.breed_facts.preview_eyebrow_'.($parent ? 'parent' : 'child'), [], $lang)),
                (string) __('breeds.'.$record->breed->value, [], $lang).' · '.(string) __('admin.breed_facts.categories.'.$record->category->value, [], $lang),
                $record->titleIn($lang),
            ];
            if (($why = $record->whyIn($lang)) !== null) {
                $lines[] = $why;
            }
            $lines[] = (string) __('admin.breed_facts.preview_source', ['source' => $record->source_name], $lang);
            if ($parent && $record->category === BreedFactCategory::Health) {
                $lines[] = (string) __('admin.breed_facts.preview_not_vet_reviewed', [], $lang);
            }

            return implode("\n", $lines);
        };

        return $infolist->schema([
            Infolists\Components\Section::make(__('admin.breed_facts.fact'))
                ->schema([
                    Infolists\Components\TextEntry::make('key')->label(__('admin.breed_facts.key')),
                    Infolists\Components\TextEntry::make('status')->label(__('admin.breed_facts.status'))->badge()
                        ->formatStateUsing(fn (BreedFactStatus $state): string => (string) __('admin.breed_facts.statuses.'.$state->value))
                        ->color(fn (BreedFactStatus $state): string => self::statusColor($state)),
                    Infolists\Components\TextEntry::make('breed')->label(__('admin.breed_facts.breed'))
                        ->formatStateUsing(fn (BreedType $state): string => (string) __('breeds.'.$state->value)),
                    Infolists\Components\TextEntry::make('category')->label(__('admin.breed_facts.category'))
                        ->formatStateUsing(fn (BreedFactCategory $state): string => (string) __('admin.breed_facts.categories.'.$state->value)),
                    Infolists\Components\TextEntry::make('audience')->label(__('admin.breed_facts.audience'))
                        ->formatStateUsing(fn (BreedFactAudience $state): string => (string) __('admin.breed_facts.audiences.'.$state->value)),
                    Infolists\Components\TextEntry::make('game_rule_key')->label(__('admin.breed_facts.game_rule'))->placeholder('—'),
                    Infolists\Components\TextEntry::make('verified_by')->label(__('admin.breed_facts.verified_by'))->placeholder('—'),
                    Infolists\Components\TextEntry::make('approver.name')->label(__('admin.breed_facts.approved_by'))->placeholder('—'),
                    Infolists\Components\TextEntry::make('approved_at')->label(__('admin.breed_facts.approved_at'))->dateTime()->placeholder('—'),
                ])
                ->columns(3),
            Infolists\Components\Section::make(__('admin.breed_facts.source'))
                ->schema([
                    Infolists\Components\TextEntry::make('source_name')->label(__('admin.breed_facts.source_name'))
                        ->url(fn (BreedFact $record): string => $record->source_url, shouldOpenInNewTab: true),
                    Infolists\Components\TextEntry::make('source_id')->label(__('admin.breed_facts.source_id')),
                    Infolists\Components\TextEntry::make('source_url')->label(__('admin.breed_facts.source_url'))
                        ->url(fn (BreedFact $record): string => $record->source_url, shouldOpenInNewTab: true)->columnSpanFull(),
                    Infolists\Components\TextEntry::make('quote')->label(__('admin.breed_facts.quote'))->columnSpanFull(),
                ])
                ->columns(2),
            Infolists\Components\Section::make(__('admin.breed_facts.preview'))
                ->description(__('admin.breed_facts.preview_help'))
                ->schema([
                    Infolists\Components\TextEntry::make('preview_child_sl')->label(__('admin.breed_facts.preview_child').' · SL')
                        ->state($card('sl', false))->extraAttributes(['style' => 'white-space: pre-line'])
                        ->visible(fn (BreedFact $record): bool => $record->audience === BreedFactAudience::Child),
                    Infolists\Components\TextEntry::make('preview_child_en')->label(__('admin.breed_facts.preview_child').' · EN')
                        ->state($card('en', false))->extraAttributes(['style' => 'white-space: pre-line'])
                        ->visible(fn (BreedFact $record): bool => $record->audience === BreedFactAudience::Child),
                    Infolists\Components\TextEntry::make('preview_parent_sl')->label(__('admin.breed_facts.preview_parent').' · SL')
                        ->state($card('sl', true))->extraAttributes(['style' => 'white-space: pre-line']),
                    Infolists\Components\TextEntry::make('preview_parent_en')->label(__('admin.breed_facts.preview_parent').' · EN')
                        ->state($card('en', true))->extraAttributes(['style' => 'white-space: pre-line']),
                ])
                ->columns(2),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBreedFacts::route('/'),
            'view' => Pages\ViewBreedFact::route('/{record}'),
        ];
    }
}
