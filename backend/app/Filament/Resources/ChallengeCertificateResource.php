<?php

namespace App\Filament\Resources;

use App\Enums\CertificateOutcome;
use App\Enums\CertificateReason;
use App\Filament\Resources\ChallengeCertificateResource\Pages;
use App\Models\ChallengeCertificate;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Finished 12-week challenges (M2-01d) — read only. Rows are written by the
 * tick (ChallengeCertificateService); parents generate the PDF in the app.
 * No printed child name here (ids only) — the admin needs no child data.
 */
class ChallengeCertificateResource extends Resource
{
    protected static ?string $model = ChallengeCertificate::class;

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.management');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.certificates');
    }

    public static function getModelLabel(): string
    {
        return __('admin.navigation.certificate_model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.navigation.certificates');
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
            ->columns([
                Tables\Columns\TextColumn::make('number')->placeholder('—')->searchable(),
                Tables\Columns\TextColumn::make('family_id')->label('Family')->sortable(),
                Tables\Columns\TextColumn::make('pet_id')->label('Pet')->sortable(),
                Tables\Columns\TextColumn::make('child_user_id')->label('Child')->sortable(),
                Tables\Columns\TextColumn::make('outcome')->badge()
                    ->formatStateUsing(fn (?CertificateOutcome $state) => $state?->value ?? '—'),
                Tables\Columns\TextColumn::make('reason')->badge()->placeholder('—')
                    ->formatStateUsing(fn (?CertificateReason $state) => $state?->value ?? '—'),
                Tables\Columns\TextColumn::make('care_score')->label('Care Score')->placeholder('—')->sortable(),
                Tables\Columns\TextColumn::make('breed_type')->label('Breed')
                    ->formatStateUsing(fn ($state) => $state instanceof \BackedEnum ? $state->value : (string) $state),
                Tables\Columns\TextColumn::make('completed_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('generated_at')->dateTime()->placeholder('—')->sortable(),
                Tables\Columns\TextColumn::make('locale')->placeholder('—'),
                Tables\Columns\TextColumn::make('notified_at')->dateTime()->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('completed_at', 'desc')
            ->defaultKeySort()
            ->filters([
                Tables\Filters\SelectFilter::make('outcome')->options([
                    CertificateOutcome::Certificate->value => 'certificate',
                    CertificateOutcome::Report->value => 'report',
                ]),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListChallengeCertificates::route('/'),
        ];
    }
}
