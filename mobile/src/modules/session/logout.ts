/**
 * The one logout path for both roles: revoke the token on the server (best
 * effort), delete it from SecureStore, drop cached queries, reset the store.
 */

import * as SecureStore from 'expo-secure-store';

import { api, clearAuthToken } from '@/api/client';
import { clearLiveSteps } from '@/modules/steps/stepCounter';
import { queryClient } from '@/api/queryClient';
import { useAppStore } from '@/store/appStore';

/** The server revoke is best effort: give up after this long so "Odjava" never hangs offline. */
export const REVOKE_TIMEOUT_MS = 5_000;

interface LogoutOptions {
  /** Call `POST /api/logout` first. False when the server already rejected the token (401). */
  revoke?: boolean;
}

export async function logout({ revoke = true }: LogoutOptions = {}): Promise<void> {
  const userId = useAppStore.getState().user?.id ?? null;
  if (revoke) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), REVOKE_TIMEOUT_MS);
    try {
      await api.logout(controller.signal);
    } catch {
      // Offline, aborted, or token already invalid — local logout must still happen.
    } finally {
      clearTimeout(timer);
    }
  }
  try {
    await clearAuthToken();
  } catch {
    // Nothing more we can do; the in-memory session is cleared below regardless.
  }
  // Android live step total of this child: the next child on this phone starts at 0.
  await clearLiveSteps(userId, SecureStore);
  queryClient.clear();
  useAppStore.getState().reset();
}

/** Re-read the active pet after a child pairs (parent side) — keeps `/api/user` the source of truth. */
export async function refreshSessionPet(): Promise<void> {
  const { pet } = await api.getUser();
  const store = useAppStore.getState();
  store.setPet(pet);
  store.setPairingStatus(pet ? 'paired' : 'unpaired');
}
