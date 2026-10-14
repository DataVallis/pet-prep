/**
 * Session restore on app launch (M1-12).
 *
 * SecureStore token → `GET /api/user` → the same payload a login produces.
 * - no token            → anonymous (login screen)
 * - 401 (revoked/expired) → token deleted, anonymous
 * - network / 5xx       → offline: the token is KEPT so a retry can succeed
 */

import * as SecureStore from 'expo-secure-store';

import { ApiError, api, clearAuthToken, getAuthToken } from '@/api/client';
import type { SignInPayload } from '@/store/appStore';
import { closeStepWindows } from '@/modules/steps/activeWindows';
import { unregisterStepSyncTask } from '@/modules/steps/backgroundSteps';
import { clearLiveSteps } from '@/modules/steps/stepCounter';
import { loadFamilyTimezone } from './familyTimezone';
import { loadSignInMethod } from './signInMethod';
import { forgetChildToken, loadChildSessions, revokeStoredToken, takeLegacyTokens } from './profiles';

/** Once per app start (bootstrap): tokens of the first M2-11 draft (a parked parent) are revoked and deleted. */
export async function migrateLegacyProfiles(): Promise<void> {
  const legacy = await takeLegacyTokens();
  if (legacy.length > 0) await Promise.all(legacy.map((t) => revokeStoredToken(t)));
}

export type RestoreResult =
  | { status: 'anonymous' }
  | { status: 'authenticated'; session: SignInPayload }
  | { status: 'offline'; error: unknown };

/**
 * M2-11 (shared phone): the ACTIVE profile is restored; `sharedSession` when it is a child
 * session a parent opened here (»Igraj kot«). A rejected shared child token is forgotten,
 * its step window closed and its live step total cleared (QA m4).
 */
export async function restoreSession(): Promise<RestoreResult> {
  let token: string | null;
  try {
    token = await getAuthToken();
  } catch {
    // Keychain unavailable (e.g. device locked before first unlock) → treat as signed out.
    return { status: 'anonymous' };
  }
  if (!token) return { status: 'anonymous' };

  const sharedChildId = Object.entries(await loadChildSessions()).find(([, s]) => s.token === token)?.[0] ?? null;

  try {
    // `awaiting_contract` is per child (M2-02): it must not end up on the user.
    const { pet, awaiting_contract: awaitingContract, ...user } = await api.getUser();
    // Hotfix 2026-10-06: the lock overlay needs the family zone before the child state loads.
    const familyTimezone = user.role === 'child' ? await loadFamilyTimezone() : null;
    // M2-10c: password vs. Google / Apple decides how a parent confirms a deletion.
    const signInMethod = user.role === 'parent' ? await loadSignInMethod() : 'password';
    const sharedSession = user.role === 'child' && sharedChildId !== null;
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
