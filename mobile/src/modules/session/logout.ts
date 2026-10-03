/**
 * The one logout path for both roles: revoke the token on the server (best
 * effort), delete it from SecureStore, drop cached queries, reset the store.
 */

import { api, clearAuthToken } from '@/api/client';
import { queryClient } from '@/api/queryClient';
import { useAppStore } from '@/store/appStore';

interface LogoutOptions {
  /** Call `POST /api/logout` first. False when the server already rejected the token (401). */
  revoke?: boolean;
}

export async function logout({ revoke = true }: LogoutOptions = {}): Promise<void> {
  if (revoke) {
    try {
      await api.logout();
    } catch {
      // Offline or token already invalid — local logout must still happen.
    }
  }
  try {
    await clearAuthToken();
  } catch {
    // Nothing more we can do; the in-memory session is cleared below regardless.
  }
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
