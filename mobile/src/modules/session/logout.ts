/**
 * The one logout path for both roles: unregister this install from pushes and revoke
 * the token on the server (best effort), delete it from SecureStore, drop cached
 * queries, reset the store.
 *
 * M2-11 (shared phone, `profileSwitch.ts`): a shared CHILD session (opened by a parent
 * here) is forgotten with it and its step window closes; the other stored child sessions
 * stay for their parent. Any other logout (parent, or before the user is known) revokes
 * and forgets every child session stored on this phone.
 */

import * as SecureStore from 'expo-secure-store';

import { api, clearAuthToken, getAuthToken } from '@/api/client';
import { clearLiveSteps } from '@/modules/steps/stepCounter';
import { unregisterStepSyncTask } from '@/modules/steps/backgroundSteps';
import { unregisterFromPush } from '@/modules/push/pushRegistration';
import { resetPurchasesIdentity } from '@/modules/purchases/purchases';
import { queryClient } from '@/api/queryClient';
import { useAppStore } from '@/store/appStore';
import { channelAuthGate, childPetFetchGate } from '@/modules/childPet/refetchGovernor';
import { forgetFamilyTimezone } from './familyTimezone';
import { forgetSignInMethod } from './signInMethod';
import { readAuthMethods } from './authMethods';
import { forgetChildToken, isStoredChildToken, revokeStoredToken, takeChildSessions } from './profiles';
import { closeStepWindows } from '@/modules/steps/activeWindows';

/** The server revoke is best effort: give up after this long so "Odjava" never hangs offline. */
export const REVOKE_TIMEOUT_MS = 5_000;

/** The push unregister (M3-02, PR #35) gets its own short budget before the revoke's. */
export const UNREGISTER_TIMEOUT_MS = 2_000;

interface LogoutOptions {
  /** Call `POST /api/logout` first. False when the server already rejected the token (401). */
  revoke?: boolean;
}

/** M2-11: revoke + forget every child session stored on this phone (parent logout). */
export async function revokeStoredChildSessions(activeToken: string | null): Promise<void> {
  const tokens = (await takeChildSessions()).filter((t) => t !== activeToken);
  await Promise.all(tokens.map((t) => revokeStoredToken(t)));
}

export async function logout({ revoke = true }: LogoutOptions = {}): Promise<void> {
  const user = useAppStore.getState().user;
  const userId = user?.id ?? null;
  const active = await getAuthToken().catch(() => null);
  if (user?.role === 'child') {
    // M2-11: a shared child session ends here — forget it; other children's stay.
    if (active && (await isStoredChildToken(active))) await forgetChildToken(active);
  } else {
    // A parent logout ends the child sessions opened on this phone too.
    await revokeStoredChildSessions(active);
  }
  // No step window stays open without an active child profile.
  await closeStepWindows();
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
  // M3-06: no background step sync for a signed-out phone (never throws).
  await unregisterStepSyncTask();
  await forgetFamilyTimezone(SecureStore);
  // M2-10c: the next parent on this phone states their own sign-in method.
  await forgetSignInMethod(SecureStore);
  // The next user starts with a fresh fetch budget (the server limit is per user).
  childPetFetchGate.reset();
  channelAuthGate.reset();
  queryClient.clear();
  useAppStore.getState().reset();
}

/** Re-read the active pet after a child pairs (parent side) — keeps `/api/user` the source of truth. */
export async function refreshSessionPet(): Promise<void> {
  const user = await api.getUser();
  const { pet } = user;
  const store = useAppStore.getState();
  // M2-10c: keep the parent's sign-in methods current (a provider linked meanwhile).
  const methods = readAuthMethods(user);
  if (methods) store.setAuthMethods(methods);
  store.setPet(pet);
  store.setPairingStatus(pet ? 'paired' : 'unpaired');
}
