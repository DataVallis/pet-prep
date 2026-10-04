/**
 * Session restore on app launch (M1-12).
 *
 * SecureStore token → `GET /api/user` → the same payload a login produces.
 * - no token            → anonymous (login screen)
 * - 401 (revoked/expired) → token deleted, anonymous
 * - network / 5xx       → offline: the token is KEPT so a retry can succeed
 */

import { ApiError, api, clearAuthToken, getAuthToken } from '@/api/client';
import type { SignInPayload } from '@/store/appStore';

export type RestoreResult =
  | { status: 'anonymous' }
  | { status: 'authenticated'; session: SignInPayload }
  | { status: 'offline'; error: unknown };

export async function restoreSession(): Promise<RestoreResult> {
  let token: string | null;
  try {
    token = await getAuthToken();
  } catch {
    // Keychain unavailable (e.g. device locked before first unlock) → treat as signed out.
    return { status: 'anonymous' };
  }
  if (!token) return { status: 'anonymous' };

  try {
    // `awaiting_contract` is per child (M2-02): it must not end up on the user.
    const { pet, awaiting_contract: awaitingContract, ...user } = await api.getUser();
    return { status: 'authenticated', session: { token, user, pet, awaitingContract } };
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) {
      await clearAuthToken();
      return { status: 'anonymous' };
    }
    return { status: 'offline', error };
  }
}
