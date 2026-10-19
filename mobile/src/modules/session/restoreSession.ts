/**
 * Session restore on app launch (M1-12).
 *
 * SecureStore token → `GET /api/user` → the same payload a login produces.
 * - no token            → anonymous (login screen)
 * - 401 (revoked/expired) → token deleted, anonymous
 * - network / 5xx       → offline: the token is KEPT so a retry can succeed
 * - Keychain unreadable → locked: never a sign-out (fix 2026-10-17). iOS launches the app
 *   in the background while the phone is locked (step sync, pushes); treating that as
 *   "no session" sent the child to the login screen every few days. The bootstrap
 *   retries (with backoff in the foreground, else on the next foreground).
 *
 * Boot-hang fix 2026-10-19: every await here is bounded. A Keychain read that doesn't
 * settle counts as `locked`, `/api/user` gives up after {@link USER_REQUEST_TIMEOUT_MS}
 * (`offline`, token kept), the optional reads fall back to their defaults. Before, the
 * splash could spin forever on a request that never settled (expo/fetch sets no request
 * timeout on iOS).
 */

import * as SecureStore from 'expo-secure-store';

import { ApiError, api, clearAuthToken, getAuthToken } from '@/api/client';
import type { SignInPayload } from '@/store/appStore';
import { closeStepWindows } from '@/modules/steps/activeWindows';
import { unregisterStepSyncTask } from '@/modules/steps/backgroundSteps';
import { clearLiveSteps } from '@/modules/steps/stepCounter';
import { loadFamilyTimezone } from './familyTimezone';
import { loadSignInMethod } from './signInMethod';
import { migrateSessionKeychain } from './keychainMigration';
import { forgetChildToken, loadChildSessions, revokeStoredToken, takeLegacyTokens } from './profiles';
import { withTimeout, withTimeoutOr } from '@/utils/withTimeout';

/** A Keychain read slower than this is treated like a locked Keychain (retried, never a sign-out). */
export const KEYCHAIN_READ_TIMEOUT_MS = 4_000;
/** `GET /api/user` at launch: give up after this long → the offline screen (token kept). */
export const USER_REQUEST_TIMEOUT_MS = 10_000;
/** Optional session extras (family zone, sign-in method) and the Keychain migration. */
export const OPTIONAL_READ_TIMEOUT_MS = 2_000;

/** Once per app start (bootstrap): tokens of the first M2-11 draft (a parked parent) are revoked and deleted. */
export async function migrateLegacyProfiles(): Promise<void> {
  const legacy = await takeLegacyTokens();
  if (legacy.length > 0) await Promise.all(legacy.map((t) => revokeStoredToken(t)));
}

export type RestoreResult =
  | { status: 'anonymous' }
  | { status: 'authenticated'; session: SignInPayload }
  | { status: 'offline'; error: unknown }
  | { status: 'locked' };

/**
 * M2-11 (shared phone): the ACTIVE profile is restored; `sharedSession` when it is a child
 * session a parent opened here (»Igraj kot«). A rejected shared child token is forgotten,
 * its step window closed and its live step total cleared (QA m4).
 */
export async function restoreSession(): Promise<RestoreResult> {
  let token: string | null;
  let sharedChildId: string | null;
  try {
    token = await withTimeout(getAuthToken(), KEYCHAIN_READ_TIMEOUT_MS);
    if (!token) return { status: 'anonymous' };
    const sessions = await withTimeout(loadChildSessions(), KEYCHAIN_READ_TIMEOUT_MS);
    sharedChildId = Object.entries(sessions).find(([, s]) => s.token === token)?.[0] ?? null;
  } catch {
    // Keychain unavailable (phone locked) or not answering — the session is still there, try again later.
    return { status: 'locked' };
  }

  try {
    // `awaiting_contract` is per child (M2-02): it must not end up on the user.
    const { pet, awaiting_contract: awaitingContract, ...user } = await api.getUser({ timeoutMs: USER_REQUEST_TIMEOUT_MS });
    // Hotfix 2026-10-06: the lock overlay needs the family zone before the child state loads.
    const familyTimezone = user.role === 'child' ? await withTimeoutOr(loadFamilyTimezone(), OPTIONAL_READ_TIMEOUT_MS, null) : null;
    // M2-10c: password vs. Google / Apple decides how a parent confirms a deletion.
    const signInMethod =
      user.role === 'parent' ? await withTimeoutOr(loadSignInMethod(), OPTIONAL_READ_TIMEOUT_MS, 'password' as const) : 'password';
    const sharedSession = user.role === 'child' && sharedChildId !== null;
    // Older builds stored the session with WHEN_UNLOCKED; move it before any request runs.
    // Never throws; a slow one is left to finish on its own (retried at the next start).
    await withTimeoutOr(migrateSessionKeychain(), KEYCHAIN_READ_TIMEOUT_MS, false);
    return {
      status: 'authenticated',
      session: { token, user, pet, awaitingContract, familyTimezone, signInMethod, ...(sharedSession ? { sharedSession } : {}) },
    };
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) {
      await clearAuthToken();
      if (sharedChildId !== null) {
        // The shared child session is gone (revoked / child deleted).
        await forgetChildToken(token);
        await closeStepWindows();
        await clearLiveSteps(Number(sharedChildId), SecureStore);
      }
      // M3-06: no background step sync with a revoked token (never throws).
      await unregisterStepSyncTask();
      return { status: 'anonymous' };
    }
    return { status: 'offline', error };
  }
}
