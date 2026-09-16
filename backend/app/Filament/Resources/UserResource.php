<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
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

                Forms\Components\TextInput::make('email')
                    ->email()
                    ->required()
                    ->unique(User::class, 'email', ignoreRecord: true)
                    ->maxLength(255),

                Forms\Components\Select::make('role')
                    ->options(UserRole::class)
                    ->enum(UserRole::class)
                    ->required()
                    ->native(false),

                Forms\Components\Toggle::make('is_superadmin')
                    ->label('Superadmin')
                    ->default(false),

                Forms\Components\Select::make('parent_id')
                    ->label('Parent')
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

                Tables\Columns\TextColumn::make('parent.name')
                    ->label('Parent')
                    ->placeholder('—')
                    ->visible(fn (User $record): bool => $record->role === UserRole::Child)
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
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
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
