<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Exceptions\AccountDeletionException;
use App\Filament\Resources\UserResource\Pages;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\FamilyService;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Users';

    protected static ?string $navigationGroup = 'Management';

    protected static ?string $modelLabel = 'User';

    protected static ?string $pluralModelLabel = 'Users';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                // M2-02: PIN-only child profiles have no e-mail.
                // M2-10a / PR #25: stored trimmed + lower case; unique ignoring case
                // (same rule as POST /api/register and the lower(email) index).
                Forms\Components\TextInput::make('email')
                    ->email()
                    ->requiredUnless('role', UserRole::Child->value)
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? User::normalizeEmail($state) : null)
                    ->rule(fn (?User $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                        if (is_string($value) && User::emailTaken($value, $record?->getKey())) {
                            $fail('This e-mail address is already used by another account (letter case is ignored).');
                        }
                    })
                    ->maxLength(255),

                Forms\Components\TextInput::make('birth_year')
                    ->label('Birth year (child, optional)')
                    ->numeric()
                    ->minValue(1900)
                    ->maxValue(2100)
                    ->visible(fn (?User $record) => $record?->role === UserRole::Child),

                Forms\Components\Select::make('role')
                    ->options(UserRole::class)
                    ->enum(UserRole::class)
                    ->required()
                    ->native(false),

                Forms\Components\Toggle::make('is_superadmin')
                    ->label('Superadmin')
                    ->default(false),

                Forms\Components\Placeholder::make('family')
                    ->label('Family')
                    ->content(fn (?User $record): string => $record?->family
                        ? "#{$record->family->id} ({$record->family->timezone})"
                        : '—'),

                Forms\Components\Select::make('parent_id')
                    ->label('Parent (deprecated, M2-01)')
                    ->relationship('parent', 'name', fn ($query) => $query->where('role', UserRole::Parent->value))
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->visible(fn (?User $record) => $record?->role === UserRole::Child),

                Forms\Components\TextInput::make('pairing_pin')
                    ->label('Pairing PIN')
                    ->maxLength(6)
                    ->length(6),

                Forms\Components\DateTimePicker::make('pin_expires_at')
                    ->label('PIN Expires At')
                    ->native(false),

                Forms\Components\TextInput::make('revenuecat_id')
                    ->label('RevenueCat ID')
                    ->unique(User::class, 'revenuecat_id', ignoreRecord: true)
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('role')
                    ->badge()
                    ->colors([
                        'success' => UserRole::Parent->value,
                        'warning' => UserRole::Child->value,
                        'danger' => 'superadmin',
                    ])
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_superadmin')
                    ->label('Superadmin')
                    ->boolean(),

                // Family model (M2-01).
                Tables\Columns\TextColumn::make('family.id')
                    ->label('Family')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('parent.name')
                    ->label('Parent')
                    ->placeholder('—')
                    ->visible(fn (?User $record): bool => $record?->role === UserRole::Child)
                    ->sortable(),

                Tables\Columns\TextColumn::make('pairing_pin')
                    ->label('Pairing PIN')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('pin_expires_at')
                    ->label('PIN Expires At')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')
                    ->options(UserRole::class),

                Tables\Filters\TernaryFilter::make('is_superadmin')
                    ->label('Superadmin'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('deleteFamily')
                    ->label('Delete family')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (User $record): bool => self::deletableFamilyOf($record) !== null)
                    ->requiresConfirmation()
                    ->modalHeading('Delete the whole family?')
                    ->modalDescription(fn (User $record): string => self::deleteFamilyDescription($record))
                    ->modalSubmitActionLabel('Delete family permanently')
                    ->action(fn (User $record) => self::runDeleteFamily($record)),
            ])
            // No plain (bulk) user delete (M2-08): deleting a user row directly
            // cascades through the deprecated parent_id / pets.user_id mirrors
            // and leaves media files behind. Families go through "Delete family".
            ->bulkActions([]);
    }

    /**
     * The user's family if a superadmin may delete it here (M2-08): it exists
     * and has no superadmin member.
     */
    public static function deletableFamilyOf(User $user): ?Family
    {
        $family = app(FamilyService::class)->familyOf($user);
        if ($family === null) {
            return null;
        }

        $hasSuperadmin = User::whereIn('id', FamilyMember::where('family_id', $family->id)->select('user_id'))
            ->where('is_superadmin', true)
            ->exists();

        return $hasSuperadmin ? null : $family;
    }

    public static function deleteFamilyDescription(User $user): string
    {
        $family = self::deletableFamilyOf($user);
        if ($family === null) {
            return 'This user has no family that can be deleted here.';
        }

        $parents = $family->parents()->count();
        $children = $family->children()->count();
        $pets = $family->pets()->count();

        return "Family #{$family->id}: {$parents} parent(s), {$children} child profile(s) and {$pets} pet(s) "
            .'with every contract, log, device and AI image / video will be deleted immediately. This cannot be undone.';
    }

    /**
     * Same service as the in-app deletion (AccountDeletionService::deleteFamily).
     */
    public static function runDeleteFamily(User $user): void
    {
        $family = self::deletableFamilyOf($user);
        if ($family === null) {
            Notification::make()->title('Nothing deleted')->body('No deletable family.')->warning()->send();

            return;
        }

        try {
            $result = app(AccountDeletionService::class)->deleteFamily($family);
        } catch (AccountDeletionException $e) {
            Notification::make()->title('Not deleted')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()
            ->title('Family deleted')
            ->body("{$result['parents']} parent(s), {$result['children']} child profile(s), {$result['pets']} pet(s).")
            ->success()
            ->send();
    }

    public static function getRelations(): array
    {
        return [
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
