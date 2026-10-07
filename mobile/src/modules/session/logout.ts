/**
 * The one logout path for both roles: unregister this install from pushes and revoke
 * the token on the server (best effort), delete it from SecureStore, drop cached
 * queries, reset the store.
 */

import * as SecureStore from 'expo-secure-store';

import { api, clearAuthToken } from '@/api/client';
import { clearLiveSteps } from '@/modules/steps/stepCounter';
import { unregisterFromPush } from '@/modules/push/pushRegistration';
import { resetPurchasesIdentity } from '@/modules/purchases/purchases';
import { queryClient } from '@/api/queryClient';
import { useAppStore } from '@/store/appStore';
import { channelAuthGate, childPetFetchGate } from '@/modules/childPet/refetchGovernor';
import { forgetFamilyTimezone } from './familyTimezone';

/** The server revoke is best effort: give up after this long so "Odjava" never hangs offline. */
export const REVOKE_TIMEOUT_MS = 5_000;

/** The push unregister (M3-02, PR #35) gets its own short budget before the revoke's. */
export const UNREGISTER_TIMEOUT_MS = 2_000;

interface LogoutOptions {
  /** Call `POST /api/logout` first. False when the server already rejected the token (401). */
  revoke?: boolean;
}

export async function logout({ revoke = true }: LogoutOptions = {}): Promise<void> {
  const userId = useAppStore.getState().user?.id ?? null;
  if (revoke) {
    // M3-02: no more pushes to this phone (needs the token, so before the revoke);
    // at most 2 s, never eats into the revoke's 5 s. Never throws.
    await unregisterFromPush({ timeoutMs: UNREGISTER_TIMEOUT_MS });
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
  if (!revoke) {
    // The server already dropped the token (and the push registration with it).
    await unregisterFromPush({ callServer: false });
  }
  // M3-07: the parent stops being the RevenueCat app user on this install (≤ 2 s, never
  // throws; no-op when no parent was identified, e.g. a child session).
  await resetPurchasesIdentity();
  try {
    await clearAuthToken();
  } catch {
    // Nothing more we can do; the in-memory session is cleared below regardless.
  }
  // Android live step total of this child: the next child on this phone starts at 0.
  await clearLiveSteps(userId, SecureStore);
  await forgetFamilyTimezone(SecureStore);
  // The next user starts with a fresh fetch budget (the server limit is per user).
  childPetFetchGate.reset();
  channelAuthGate.reset();
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
