<?php

namespace App\Models;

use App\Enums\DevicePlatform;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An app install that can receive Expo pushes (M3-02).
 *
 * The Expo token identifies the install, not the person: registering the
 * same token from another account moves the row to that account. The row is
 * tied to the Sanctum token that registered it (FK cascade), so logout,
 * "sign out all devices" and pruning a child's old devices drop it too.
 * `disabled_at` is set when Expo answers DeviceNotRegistered; registering
 * again re-enables it. `locale` = the install's language for push texts
 * (M1-18, from the `locale` body field of POST /api/devices; rows from before
 * M1-18 and new rows without the field get 'sl'; null → default English).
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $personal_access_token_id
 * @property string $expo_push_token
 * @property DevicePlatform $platform
 * @property string|null $app_version
 * @property string|null $locale
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $disabled_at
 * @property string|null $disabled_reason
 */
class DevicePushToken extends Model
{
    /**
     * Expo push token format: ExponentPushToken[…] (or the older
     * ExpoPushToken[…]); the inner id is URL-safe characters only.
     */
    public const TOKEN_PATTERN = '/^Expo(nent)?PushToken\[[A-Za-z0-9_\-]{10,200}\]$/';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'personal_access_token_id',
        'expo_push_token',
        'platform',
        'app_version',
        'locale',
        'last_seen_at',
        'disabled_at',
        'disabled_reason',
    ];

    protected function casts(): array
    {
        return [
            'platform' => DevicePlatform::class,
            'last_seen_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<DevicePushToken>  $query
     */
    public function scopeEnabled(Builder $query): void
    {
        $query->whereNull('disabled_at');
    }
}
