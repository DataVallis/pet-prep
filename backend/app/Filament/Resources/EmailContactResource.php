<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EmailContactResource\Pages;
use App\Models\EmailContact;
use App\Models\EmailSignup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * E-mails (website) (M6-S09): every e-mail left on petprep.si, one contact per address with every
 * signup (what form, what for, which page + section, targeting context). Superadmin only: list with
 * filters, contact detail with every signup row, CSV export of the filtered list (ListEmailContacts),
 * delete on request (removes the contact and its signups). Written only by EmailSignupService;
 * never deleted automatically. Replaced the »Parent E-mails (website)« list of summary_leads.
 */
class EmailContactResource extends Resource
{
    protected static ?string $model = EmailContact::class;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.management');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.email_contacts');
    }

    public static function getModelLabel(): string
    {
        return __('admin.navigation.email_contact_model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.navigation.email_contacts');
    }

    private static function isSuperadmin(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isSuperadmin();
    }

    public static function canAccess(): bool
    {
        return self::isSuperadmin();
    }

    public static function canViewAny(): bool
    {
        return self::isSuperadmin();
    }

    public static function canView(Model $record): bool
    {
        return self::isSuperadmin();
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
        return self::isSuperadmin();
    }

    public static function canDeleteAny(): bool
    {
        return self::isSuperadmin();
    }

    /**
     * Distinct values of one signup column, key = value, label via $label (an unknown value shows as stored).
     *
     * @param  callable(string): string|null  $label
     * @return array<string, string>
     */
    private static function signupOptions(string $column, ?callable $label = null): array
    {
        return EmailSignup::query()->whereNotNull($column)->distinct()->orderBy($column)->pluck($column)
            ->mapWithKeys(fn (string $v): array => [$v => $label ? $label($v) : $v])->all();
    }

    /**
     * Distinct values of a column over the contact's signups, in order of first use.
     *
     * @return Collection<int, string>
     */
    private static function distinct(EmailContact $contact, string $column): Collection
    {
        return $contact->signups->pluck($column)->filter()->unique()->values();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->description(__('admin.email_signups.retention_note'))
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('signups'))
            ->columns([
                Tables\Columns\TextColumn::make('email')->label(__('admin.email_signups.email'))->searchable()->copyable(),
                Tables\Columns\TextColumn::make('sources')->label(__('admin.email_signups.source'))->badge()
                    ->state(fn (EmailContact $r): array => self::distinct($r, 'source')->map(fn (string $s): string => EmailSignup::sourceLabel($s))->all()),
                Tables\Columns\TextColumn::make('purposes')->label(__('admin.email_signups.purpose'))->badge()->color('gray')
                    ->state(fn (EmailContact $r): array => self::distinct($r, 'purpose')->map(fn (string $p): string => EmailSignup::purposeLabel($p))->all()),
                Tables\Columns\TextColumn::make('page')->label(__('admin.email_signups.page'))
                    ->state(fn (EmailContact $r): ?string => ($s = $r->latestSignup()) === null ? null : trim($s->page_path.($s->section ? ' · '.$s->section : '')))
                    ->placeholder('—')->wrap(),
                Tables\Columns\TextColumn::make('breed')->label(__('admin.email_signups.breed'))
                    ->state(fn (EmailContact $r): ?string => $r->latestSignup()?->breed)->placeholder('—'),
                Tables\Columns\TextColumn::make('stage')->label(__('admin.email_signups.stage'))
                    ->state(fn (EmailContact $r): ?string => $r->latestSignup()?->stage)->placeholder('—'),
                Tables\Columns\TextColumn::make('quiz')->label(__('admin.email_signups.quiz'))
                    ->state(fn (EmailContact $r): ?string => $r->latestSignup()?->quizText())->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('locale')->label(__('admin.email_signups.locale'))
                    ->state(fn (EmailContact $r): ?string => $r->latestSignup()?->locale)->placeholder('—'),
                Tables\Columns\TextColumn::make('utm')->label(__('admin.email_signups.utm'))
                    ->state(fn (EmailContact $r): ?string => $r->latestSignup()?->utmText())->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('first_seen_at')->label(__('admin.email_signups.first_seen'))->dateTime('Y-m-d H:i', 'Europe/Ljubljana')->sortable(),
                Tables\Columns\TextColumn::make('last_seen_at')->label(__('admin.email_signups.last_seen'))->dateTime('Y-m-d H:i', 'Europe/Ljubljana')->sortable(),
                Tables\Columns\TextColumn::make('signups_count')->label(__('admin.email_signups.count'))->numeric()->sortable(),
                Tables\Columns\TextColumn::make('consent_version')->label(__('admin.email_signups.consent'))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('last_seen_at', 'desc')
            ->filters([
                // ONE filter so every condition applies to the SAME signup (source = quiz AND breed = x
                // means one signup that is both, not two different ones).
                Tables\Filters\Filter::make('signup')
                    ->form([
                        Select::make('source')->label(__('admin.email_signups.source'))->options(fn (): array => self::signupOptions('source', fn (string $s): string => EmailSignup::sourceLabel($s))),
                        Select::make('purpose')->label(__('admin.email_signups.purpose'))->options(collect(EmailSignup::PURPOSES)->mapWithKeys(fn (string $p): array => [$p => EmailSignup::purposeLabel($p)])->all()),
                        Select::make('breed')->label(__('admin.email_signups.breed'))->searchable()->options(fn (): array => self::signupOptions('breed')),
                        Select::make('locale')->label(__('admin.email_signups.locale'))->options(fn (): array => self::signupOptions('locale')),
                        DatePicker::make('from')->label(__('admin.email_signups.from')),
                        DatePicker::make('until')->label(__('admin.email_signups.until')),
                    ])
                    ->columns(3)
                    ->query(function (Builder $query, array $data): Builder {
                        $equals = collect(['source', 'purpose', 'breed', 'locale'])->filter(fn (string $k): bool => filled($data[$k] ?? null));
                        $from = filled($data['from'] ?? null) ? CarbonImmutable::parse($data['from'], 'Europe/Ljubljana')->startOfDay()->utc() : null;
                        $until = filled($data['until'] ?? null) ? CarbonImmutable::parse($data['until'], 'Europe/Ljubljana')->endOfDay()->utc() : null;
                        if ($equals->isEmpty() && $from === null && $until === null) {
                            return $query;
                        }

                        return $query->whereHas('signups', function (Builder $s) use ($equals, $data, $from, $until): void {
                            foreach ($equals as $key) {
                                $s->where($key, $data[$key]);
                            }
                            if ($from !== null) {
                                $s->where('created_at', '>=', $from);
                            }
                            if ($until !== null) {
                                $s->where('created_at', '<=', $until);
                            }
                        });
                    })
                    ->indicateUsing(function (array $data): array {
                        $out = [];
                        foreach (['source', 'purpose', 'breed', 'locale'] as $key) {
                            if (filled($data[$key] ?? null)) {
                                $out[] = __('admin.email_signups.'.$key).': '.($key === 'source' ? EmailSignup::sourceLabel($data[$key]) : ($key === 'purpose' ? EmailSignup::purposeLabel($data[$key]) : $data[$key]));
                            }
                        }
                        if (filled($data['from'] ?? null)) {
                            $out[] = __('admin.email_signups.from').': '.$data['from'];
                        }
                        if (filled($data['until'] ?? null)) {
                            $out[] = __('admin.email_signups.until').': '.$data['until'];
                        }

                        return $out;
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $dt = 'Y-m-d H:i';

        return $infolist->schema([
            TextEntry::make('email')->label(__('admin.email_signups.email'))->copyable(),
            TextEntry::make('signups_count')->label(__('admin.email_signups.count')),
            TextEntry::make('first_seen_at')->label(__('admin.email_signups.first_seen'))->dateTime($dt, 'Europe/Ljubljana'),
            TextEntry::make('last_seen_at')->label(__('admin.email_signups.last_seen'))->dateTime($dt, 'Europe/Ljubljana'),
            TextEntry::make('consent_version')->label(__('admin.email_signups.consent')),
            TextEntry::make('consented_at')->label(__('admin.email_signups.consented_at'))->dateTime($dt, 'Europe/Ljubljana'),
            TextEntry::make('unsubscribed_at')->label(__('admin.email_signups.unsubscribed_at'))->dateTime($dt, 'Europe/Ljubljana')->placeholder('—'),
            TextEntry::make('provider_synced_at')->label(__('admin.email_signups.provider_synced_at'))->dateTime($dt, 'Europe/Ljubljana')->placeholder(__('admin.email_signups.not_synced')),
            RepeatableEntry::make('signups')->label(__('admin.email_signups.signups'))->columnSpanFull()->columns(4)->schema([
                TextEntry::make('created_at')->label(__('admin.email_signups.left_at'))->dateTime($dt, 'Europe/Ljubljana'),
                TextEntry::make('source')->label(__('admin.email_signups.source'))->badge()
                    ->formatStateUsing(fn (string $state): string => EmailSignup::sourceLabel($state)),
                TextEntry::make('purpose')->label(__('admin.email_signups.purpose'))->badge()->color('gray')
                    ->formatStateUsing(fn (string $state): string => EmailSignup::purposeLabel($state)),
                TextEntry::make('locale')->label(__('admin.email_signups.locale'))->placeholder('—'),
                TextEntry::make('page_path')->label(__('admin.email_signups.page'))->placeholder('—')->columnSpan(2),
                TextEntry::make('section')->label(__('admin.email_signups.section'))->placeholder('—'),
                TextEntry::make('species')->label(__('admin.email_signups.species'))->placeholder('—'),
                TextEntry::make('breed')->label(__('admin.email_signups.breed'))->placeholder('—'),
                TextEntry::make('stage')->label(__('admin.email_signups.stage'))->placeholder('—'),
                TextEntry::make('quiz')->label(__('admin.email_signups.quiz'))->state(fn (EmailSignup $s): ?string => $s->quizText())->placeholder('—'),
                TextEntry::make('quiz_answers')->label(__('admin.email_signups.quiz_answers'))->placeholder('—'),
                TextEntry::make('utm')->label(__('admin.email_signups.utm'))->state(fn (EmailSignup $s): ?string => $s->utmText())->placeholder('—')->columnSpan(2),
                TextEntry::make('referrer_host')->label(__('admin.email_signups.referrer_host'))->placeholder('—'),
                TextEntry::make('consent_version')->label(__('admin.email_signups.consent')),
            ]),
        ])->columns(4);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmailContacts::route('/'),
            'view' => Pages\ViewEmailContact::route('/{record}'),
        ];
    }
}
