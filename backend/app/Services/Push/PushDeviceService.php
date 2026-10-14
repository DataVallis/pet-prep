<?php

namespace App\Services\Push;

use App\Enums\DevicePlatform;
use App\Models\DevicePushToken;
use App\Models\FamilyMember;
use App\Models\User;
use App\Support\RequestLocale;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Push device registry (M3-02): `POST /api/devices` / `DELETE /api/devices/{token}`.
 *
 * An Expo token belongs to an app install; there is one row per (user,
 * install). Registering it again (app start, login) refreshes the caller's row.
 * M2-11 (profile switching, David 2026-10-14): a family phone may carry a
 * parent AND a child session at once — rows of OTHER users of the caller's
 * family that are tied to a live Sanctum session stay, so both get their own
 * pushes (texts by each row's audience, unchanged). Every other row of the
 * install (another family, or no live session) is moved away, so a phone never
 * gets a stranger's pushes. Each row is tied to the Sanctum token that
 * registered it: deleting that token (logout, revoke, prune) deletes the row
 * (FK cascade) — a profile signed out on the phone stops getting pushes there.
 *
 * Language (M1-18): `$locale` is the explicit `locale` body field (en | sl)
 * — stored, and refreshed on every re-registration that sends it. Without
 * it the stored language is kept; a new row gets `locales.unstated_device`
 * ('sl': every app build before M1-18 is Slovenian-only and sends none).
 */
class PushDeviceService
{
    public function register(
        User $user,
        string $expoPushToken,
        DevicePlatform $platform,
        ?string $appVersion,
        ?PersonalAccessToken $accessToken,
        ?string $locale = null,
    ): DevicePushToken {
        $now = now();
        $locale = RequestLocale::isSupported($locale) ? $locale : null;
        $unstated = config('locales.unstated_device');
        $insertLocale = $locale ?? (RequestLocale::isSupported(is_string($unstated) ? $unstated : null) ? $unstated : null);
        $accessTokenId = $accessToken !== null && $accessToken->exists ? $accessToken->getKey() : null;

        // Other accounts on this install: keep family members with a live
        // session (shared family phone, M2-11); move it away from everyone else.
        $others = DevicePushToken::where('expo_push_token', $expoPushToken)
            ->where('user_id', '!=', $user->id)
            ->get(['id', 'user_id', 'personal_access_token_id']);
        $familyId = $others->isEmpty() ? null : FamilyMember::where('user_id', $user->id)->value('family_id');
        $familyUserIds = $familyId === null ? [] : FamilyMember::where('family_id', $familyId)
            ->whereIn('user_id', $others->pluck('user_id')->all())
            ->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
        foreach ($others as $other) {
            if (in_array((int) $other->user_id, $familyUserIds, true) && $other->personal_access_token_id !== null) {
                continue;
            }
            $other->delete();
            // Audit a move to another account (PR #35 review) — ids only, never the token.
            Log::info('Push: device moved to another account', [
                'device_push_token_id' => $other->id,
                'from_user_id' => $other->user_id,
                'to_user_id' => $user->id,
            ]);
        }

        // Atomic insert-or-update on (user, token) (ON CONFLICT): two
        // concurrent registrations of one install can't create two rows.
        DevicePushToken::upsert([[
            'user_id' => $user->id,
            'personal_access_token_id' => $accessTokenId,
            'expo_push_token' => $expoPushToken,
            'platform' => $platform->value,
            'app_version' => $appVersion,
            'locale' => $insertLocale,
            'last_seen_at' => $now,
            'disabled_at' => null,
            'disabled_reason' => null,
        ]], ['user_id', 'expo_push_token'], [
            'user_id', 'personal_access_token_id', 'platform', 'app_version',
            'last_seen_at', 'disabled_at', 'disabled_reason', 'updated_at',
            ...($locale !== null ? ['locale'] : []),
        ]);

        return DevicePushToken::where('user_id', $user->id)->where('expo_push_token', $expoPushToken)->firstOrFail();
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
