/**
 * Profile switching on one phone (M2-11, David 2026-10-14 "Preklop profilov v aplikaciji").
 *
 * »Igraj kot {ime}« (parent dashboard) → the phone becomes that child's app, exactly as after
 * a PIN login. The PARENT IS SIGNED OUT (their token revoked — David: going back is a real
 * parent login, e-mail + password or Google / Apple, no maths gate). The child token is
 * stored (`profiles.ts`, per parent), so the next »Igraj kot« for that child needs no new
 * request. In the shared child session »Starš?« / »Nazaj k staršu« opens the parent login
 * (`appStore.parentLoginOpen`); a successful login makes the parent active again and
 * `afterParentSignIn` tidies up. On the child's OWN phone (PIN login) nothing changes:
 * parental gate → logout.
 *
 * Steps (`steps/activeWindows.ts`): the shared child is credited only steps taken while
 * their profile is active — a window opens here and closes when a parent signs in, another
 * profile becomes active or the child session ends.
 *
 * One switch at a time (module-level mutex): a second tap while one runs is refused, so
 * the UI and the active token can never end up on two different children.
 */

import { ApiError, api, getAuthToken, saveAuthToken, type UserResponse } from '@/api/client';
import { queryClient } from '@/api/queryClient';
import { channelAuthGate, childPetFetchGate } from '@/modules/childPet/refetchGovernor';
import { deviceName } from '@/modules/pairing/deviceName';
import { sessionFromPinLogin } from '@/modules/pairing/pinLogin';
import { resetPurchasesIdentity } from '@/modules/purchases/purchases';
import { closeStepWindows, openStepWindow } from '@/modules/steps/activeWindows';
import { unregisterStepSyncTask } from '@/modules/steps/backgroundSteps';
import { useAppStore, type SignInPayload } from '@/store/appStore';
import { loadFamilyTimezone } from './familyTimezone';
import { childSessionFor, forgetChildToken, revokeStoredToken, saveChildSession, takeChildSessions } from './profiles';
import { forgetSignInMethod } from './signInMethod';

export type ProfileSwitchErrorKind =
  /** The child has no pet yet — the first pairing still needs the PIN (409 `no_pet`). */
  | 'no_pet'
  /** Not a child of this family any more (404 `child_not_found`). */
  | 'not_found'
  /** A cat this build can't show (422 `app_update_required`). */
  | 'update_required'
  | 'throttled'
  | 'offline'
  /** Another switch is still running (double tap) — ignored, no message. */
  | 'busy'
  | 'failed';

export class ProfileSwitchError extends Error {
  constructor(public readonly kind: ProfileSwitchErrorKind) {
    super(kind);
    this.name = 'ProfileSwitchError';
  }
}

function reasonOf(error: ApiError): string | null {
  const data = error.data;
  if (typeof data === 'object' && data !== null && 'reason' in data) {
    const { reason } = data as { reason: unknown };
    return typeof reason === 'string' ? reason : null;
  }
  return null;
}

export function classifySwitchError(error: unknown): ProfileSwitchErrorKind {
  if (error instanceof ProfileSwitchError) return error.kind;
  if (!(error instanceof ApiError)) return 'offline';
  const reason = reasonOf(error);
  if (error.status === 409 || reason === 'no_pet') return 'no_pet';
  if (error.status === 404) return 'not_found';
  if (reason === 'app_update_required') return 'update_required';
  if (error.status === 429) return 'throttled';
  return 'failed';
}

/** A new profile starts with an empty query cache and fresh fetch budgets (as after a login). */
function resetProfileCaches(): void {
  childPetFetchGate.reset();
  channelAuthGate.reset();
  queryClient.clear();
}

/** The child's session from a stored token's `GET /api/user` (same shape as a restore). */
function childSessionFromUser(token: string, user: UserResponse): SignInPayload {
  const { pet, awaiting_contract: awaitingContract } = user;
  return {
    token,
    user: { id: user.id, name: user.name, email: null, role: 'child' },
    pet: pet && typeof awaitingContract === 'boolean' ? { ...pet, awaiting_contract: awaitingContract } : pet,
    awaitingContract,
  };
}

/** This parent's stored token for the child, checked with `GET /api/user` (401 → forgotten → null). */
async function reusableChildSession(childId: number, parentUserId: number): Promise<{ token: string; user: UserResponse } | null> {
  const stored = await childSessionFor(childId, parentUserId);
  if (!stored) return null;
  let user: UserResponse;
  try {
    user = await api.getUserWithToken(stored.token);
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) {
      await forgetChildToken(stored.token);
      return null;
    }
    throw new ProfileSwitchError(classifySwitchError(error));
  }
  if (user.role !== 'child' || user.id !== childId || !user.pet) {
    await forgetChildToken(stored.token);
    return null;
  }
  return { token: stored.token, user };
}

let switching: Promise<void> | null = null;

/**
 * »Igraj kot {ime}«: child token (stored or a new device session) → stored → made active →
 * only then the parent's token is revoked. Throws `ProfileSwitchError` while nothing has
 * changed yet (also when SecureStore refuses a write — the parent stays signed in).
 */
export function switchToChild(child: { id: number }): Promise<void> {
  if (switching) return Promise.reject(new ProfileSwitchError('busy'));
  const run = doSwitchToChild(child).finally(() => {
    switching = null;
  });
  switching = run;
  return run;
}

async function doSwitchToChild(child: { id: number }): Promise<void> {
  const parent = useAppStore.getState().user;
  if (parent === null || parent.role !== 'parent') throw new ProfileSwitchError('failed');
  const parentToken = await getAuthToken().catch(() => null);
  if (!parentToken) throw new ProfileSwitchError('failed');

  const existing = await reusableChildSession(child.id, parent.id);
  let token: string;
  let fresh: Awaited<ReturnType<typeof api.childDeviceSession>> | null = null;
  if (existing) {
    token = existing.token;
  } else {
    try {
      fresh = await api.childDeviceSession(child.id, deviceName());
    } catch (error) {
      throw new ProfileSwitchError(classifySwitchError(error));
    }
    token = fresh.token;
    if (!(await saveChildSession(child.id, { token, parentUserId: parent.id }))) {
      // Can't remember the child session → don't switch (the parent would be stranded).
      await revokeStoredToken(token);
      throw new ProfileSwitchError('failed');
    }
  }

  try {
    await saveAuthToken(token);
  } catch {
    throw new ProfileSwitchError('failed');
  }

  // From here the child is the session: sign the parent out (David: back = a real login).
  await revokeStoredToken(parentToken);
  await resetPurchasesIdentity();
  await forgetSignInMethod();

  // Steps: only while this child's profile is active (background included).
  await openStepWindow(child.id);

  const session = fresh ? await sessionFromPinLogin(fresh) : childSessionFromUser(token, (existing as { user: UserResponse }).user);
  resetProfileCaches();
  useAppStore.getState().signIn({ ...session, familyTimezone: await loadFamilyTimezone(), sharedSession: true });
}

/** »Starš?« / »Nazaj k staršu« in a shared child session: show the parent login. */
export function openParentLogin(): void {
  const state = useAppStore.getState();
  if (state.user?.role === 'child' && state.sharedSession) state.setParentLoginOpen(true);
}

/**
 * A parent account is active (login, sign-up, social sign-in, restore): no child's step
 * window stays open, the child's background step task stops, and child sessions another
 * parent opened on this phone are revoked and dropped (only their own may be reused).
 */
export async function afterParentSignIn(parentUserId: number): Promise<void> {
  await closeStepWindows();
  await unregisterStepSyncTask();
  const foreign = await takeChildSessions((s) => s.parentUserId !== parentUserId);
  await Promise.all(foreign.map((t) => revokeStoredToken(t)));
}

/** Tests: forget a stuck switch. */
export function resetProfileSwitchForTests(): void {
  switching = null;
}
