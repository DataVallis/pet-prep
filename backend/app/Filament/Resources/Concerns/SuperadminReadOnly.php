<?php

namespace App\Filament\Resources\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Superadmin-only, read-only resource (M6-S09 shop): list + view, no create / edit / delete. */
trait SuperadminReadOnly
{
    protected static function isSuperadmin(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isSuperadmin();
    }

    public static function canAccess(): bool
    {
        return static::isSuperadmin();
    }

    public static function canViewAny(): bool
    {
        return static::isSuperadmin();
    }

    public static function canView(Model $record): bool
    {
        return static::isSuperadmin();
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

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
