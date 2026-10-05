<?php

namespace App\Services\Push;

use App\Enums\DevicePlatform;
use App\Models\DevicePushToken;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Push device registry (M3-02): `POST /api/devices` / `DELETE /api/devices/{token}`.
 *
 * An Expo token belongs to an app install. Registering it again (app start,
 * login) refreshes it; registering it from another account (a parent signs in
 * on the child's phone) moves it, so a phone never gets another account's
 * pushes. The row is tied to the Sanctum token that registered it: deleting
 * that token (logout, revoke, prune) deletes the row (FK cascade).
 */
class PushDeviceService
{
    public function register(
        User $user,
        string $expoPushToken,
        DevicePlatform $platform,
        ?string $appVersion,
        ?PersonalAccessToken $accessToken,
    ): DevicePushToken {
        $now = now();
        $accessTokenId = $accessToken !== null && $accessToken->exists ? $accessToken->getKey() : null;

        // Atomic insert-or-update on the unique token (ON CONFLICT): two
        // concurrent registrations of one install can't create two rows.
        DevicePushToken::upsert([[
            'user_id' => $user->id,
            'personal_access_token_id' => $accessTokenId,
            'expo_push_token' => $expoPushToken,
            'platform' => $platform->value,
            'app_version' => $appVersion,
            'last_seen_at' => $now,
            'disabled_at' => null,
            'disabled_reason' => null,
        ]], ['expo_push_token'], [
            'user_id', 'personal_access_token_id', 'platform', 'app_version',
            'last_seen_at', 'disabled_at', 'disabled_reason', 'updated_at',
        ]);

        return DevicePushToken::where('expo_push_token', $expoPushToken)->firstOrFail();
    }

    /**
     * Remove the caller's own registration of this token. Someone else's
     * token (or an unknown one) is left alone — the answer is the same.
     */
    public function unregister(User $user, string $expoPushToken): int
    {
        return DevicePushToken::where('expo_push_token', $expoPushToken)
            ->where('user_id', $user->id)
            ->delete();
    }

    /**
     * Expo says the install can't receive pushes any more (app removed,
     * notifications turned off for good): stop sending until it registers again.
     */
    public function disable(int $deviceId, string $reason): void
    {
        $updated = DevicePushToken::whereKey($deviceId)
            ->whereNull('disabled_at')
            ->update(['disabled_at' => now(), 'disabled_reason' => mb_substr($reason, 0, 64)]);

        if ($updated > 0) {
            Log::info('Push: device disabled', ['device_push_token_id' => $deviceId, 'reason' => $reason]);
        }
    }

    /**
     * Account / child profile deletion (M2-08).
     *
     * @param  list<int>  $userIds
     */
    public function removeForUsers(array $userIds): int
    {
        if ($userIds === []) {
            return 0;
        }

        return DevicePushToken::whereIn('user_id', $userIds)->delete();
    }
}
