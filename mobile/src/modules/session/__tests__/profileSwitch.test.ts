/**
 * M2-11 profile switching (David 2026-10-14): »Igraj kot« activates the child and SIGNS THE
 * PARENT OUT (back = a real parent login); the child token is stored per parent and reused;
 * another parent's sign-in drops foreign tokens; failures abort before the parent is revoked;
 * one switch at a time; logout / restore housekeeping; push for both profiles.
 */
import * as Notifications from 'expo-notifications';
import * as SecureStore from 'expo-secure-store';

import { ApiError, api } from '@/api/client';
import { registerForPush, resetPushRegistration } from '@/modules/push/pushRegistration';
import { resetPurchasesIdentity } from '@/modules/purchases/purchases';
import { logout } from '@/modules/session/logout';
import {
  ProfileSwitchError,
  afterParentSignIn,
  openParentLogin,
  resetProfileSwitchForTests,
  switchToChild,
} from '@/modules/session/profileSwitch';
import { CHILD_SESSIONS_KEY, LEGACY_PARKED_PARENT_KEY, PENDING_REVOKES_KEY, loadChildSessions, retryPendingRevokes } from '@/modules/session/profiles';
import { migrateLegacyProfiles, restoreSession } from '@/modules/session/restoreSession';
import { STEP_WINDOWS_KEY, loadStepWindows } from '@/modules/steps/activeWindows';
import { unregisterStepSyncTask } from '@/modules/steps/backgroundSteps';
import { useAppStore } from '@/store/appStore';
import { makePet } from '@/test-utils/fixtures';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: {
      ...actual.api,
      getUser: jest.fn(),
      getUserWithToken: jest.fn(),
      childDeviceSession: jest.fn(),
      logout: jest.fn(async () => ({ message: 'ok' })),
      logoutToken: jest.fn(async () => ({ message: 'ok' })),
      registerDevice: jest.fn(async () => ({})),
      unregisterDevice: jest.fn(async () => null),
    },
  };
});

jest.mock('@/modules/purchases/purchases', () => ({ resetPurchasesIdentity: jest.fn(async () => undefined) }));

jest.mock('@/modules/steps/backgroundSteps', () => ({
  unregisterStepSyncTask: jest.fn(async () => undefined),
  registerStepSyncTask: jest.fn(async () => undefined),
}));

jest.mock('expo-constants', () => ({
  __esModule: true,
  default: { expoConfig: { version: '1.0.0', extra: { eas: { projectId: 'project-uuid' }, appVersion: '1.0.0', gitSha: 'abc1234' } }, easConfig: null },
}));

const getUser = api.getUser as jest.Mock;
const getUserWithToken = api.getUserWithToken as jest.Mock;
const childDeviceSession = api.childDeviceSession as jest.Mock;
const logoutToken = api.logoutToken as jest.Mock;
const apiLogout = api.logout as jest.Mock;
const registerDevice = api.registerDevice as jest.Mock;

const AUTH_KEY = 'petprep_auth_token';
const PARENT = { id: 1, name: 'Mama', email: 'mama@example.com', role: 'parent' as const };
const PET = makePet({ id: 7, born_at: '2026-10-01T08:00:00Z' });

let vault: Map<string, string>;
/** Keys whose writes SecureStore refuses (storage failure tests). */
let failingKeys: Set<string>;

function childUser(id: number) {
  return { id, name: id === 2 ? 'Maja' : 'Luka', email: null, role: 'child' as const, pet: { ...PET, id: id * 10 }, awaiting_contract: false };
}

function deviceSessionBody(childId: number, token: string) {
  return {
    token,
    abilities: ['child'],
    user: { id: childId, name: 'Maja', role: 'child' },
    mode: 'relogin',
    joined_existing: true,
    family_id: 1,
    pet: { id: childId * 10, breed_type: 'mutt', species: 'dog', hunger_level: 60, thirst_level: 60, energy_level: 60, hygiene_level: 60, born_at: '2026-10-01T08:00:00Z', awaiting_contract: false, is_active: true, is_game_over: false, current_video_url: null },
    awaiting_contract: false,
  };
}

function signInParent(user = PARENT, token = 'parent-token') {
  vault.set(AUTH_KEY, token);
  useAppStore.getState().signIn({ token, user, pet: null, signInMethod: 'google' });
}

function storedSessions(value: Record<string, { token: string; parentUserId: number }>) {
  vault.set(CHILD_SESSIONS_KEY, JSON.stringify(value));
}

beforeEach(() => {
  jest.clearAllMocks();
  resetProfileSwitchForTests();
  vault = new Map();
  failingKeys = new Set();
  jest.mocked(SecureStore.getItemAsync).mockImplementation(async (key: string) => vault.get(key) ?? null);
  jest.mocked(SecureStore.setItemAsync).mockImplementation(async (key: string, value: string) => {
    if (failingKeys.has(key)) throw new Error('keychain full');
    vault.set(key, value);
  });
  jest.mocked(SecureStore.deleteItemAsync).mockImplementation(async (key: string) => {
    vault.delete(key);
  });
  useAppStore.setState(useAppStore.getInitialState(), true);
  // `GET /api/user` answers for whichever token is active.
  getUser.mockImplementation(async () => {
    const token = vault.get(AUTH_KEY) ?? '';
    if (token.startsWith('child-2')) return childUser(2);
    if (token.startsWith('child-5')) return childUser(5);
    return { ...PARENT, pet: null, has_password: false, linked_providers: ['google'] };
  });
});

describe('switchToChild', () => {
  it('child active (as after a PIN login), parent signed out, token stored for this parent, step window open', async () => {
    signInParent();
    vault.set('petprep_sign_in_method', 'google');
    childDeviceSession.mockResolvedValueOnce(deviceSessionBody(2, 'child-2-a'));

    await switchToChild({ id: 2 });

    expect(childDeviceSession).toHaveBeenCalledWith(2, expect.any(String));
    expect(vault.get(AUTH_KEY)).toBe('child-2-a');
    expect(logoutToken).toHaveBeenCalledWith('parent-token', expect.anything());
    expect(resetPurchasesIdentity).toHaveBeenCalled();
    expect(vault.has('petprep_sign_in_method')).toBe(false);
    expect(await loadChildSessions()).toEqual({ '2': { token: 'child-2-a', parentUserId: 1 } });
    const state = useAppStore.getState();
    expect(state.user).toEqual({ id: 2, name: 'Maja', email: null, role: 'child' });
    expect(state.sharedSession).toBe(true);
    const windows = await loadStepWindows();
    expect(windows['2']).toHaveLength(1);
    expect(windows['2'][0].end).toBeNull();
    // Nothing of the parent is stored any more (no e-mail anywhere extra).
    expect([...vault.values()].join(' ')).not.toContain('mama@example.com');
    expect([...vault.values()].join(' ')).not.toContain('parent-token');
  });

  it("reuses this parent's stored token; another parent's stored token is never reused", async () => {
    signInParent();
    storedSessions({ '2': { token: 'child-2-old', parentUserId: 1 }, '5': { token: 'child-5-foreign', parentUserId: 99 } });
    getUserWithToken.mockResolvedValueOnce(childUser(2));

    await switchToChild({ id: 2 });
    expect(getUserWithToken).toHaveBeenCalledWith('child-2-old');
    expect(childDeviceSession).not.toHaveBeenCalled();
    expect(vault.get(AUTH_KEY)).toBe('child-2-old');

    // Parent 1 back, then »Igraj kot« for child 5: the other parent's token is not offered.
    signInParent();
    childDeviceSession.mockResolvedValueOnce(deviceSessionBody(5, 'child-5-new'));
    await switchToChild({ id: 5 });
    expect(getUserWithToken).not.toHaveBeenCalledWith('child-5-foreign');
    expect(childDeviceSession).toHaveBeenCalledWith(5, expect.any(String));
    expect((await loadChildSessions())['5']).toEqual({ token: 'child-5-new', parentUserId: 1 });
  });

  it('a stored token the server rejects (401) → one new device session', async () => {
    signInParent();
    storedSessions({ '2': { token: 'child-2-old', parentUserId: 1 } });
    getUserWithToken.mockRejectedValueOnce(new ApiError('Unauthenticated.', 401, null));
    childDeviceSession.mockResolvedValueOnce(deviceSessionBody(2, 'child-2-new'));

    await switchToChild({ id: 2 });

    expect(childDeviceSession).toHaveBeenCalledTimes(1);
    expect(await loadChildSessions()).toEqual({ '2': { token: 'child-2-new', parentUserId: 1 } });
  });

  it('409 no_pet → error, the parent stays signed in', async () => {
    signInParent();
    childDeviceSession.mockRejectedValueOnce(new ApiError('No pet', 409, { message: 'No pet', reason: 'no_pet' }));

    await expect(switchToChild({ id: 2 })).rejects.toEqual(new ProfileSwitchError('no_pet'));
    expect(vault.get(AUTH_KEY)).toBe('parent-token');
    expect(logoutToken).not.toHaveBeenCalled();
    expect(useAppStore.getState().user?.role).toBe('parent');
  });

  it('QA m2: the child session can\'t be stored → abort BEFORE the parent is revoked (new child token revoked)', async () => {
    signInParent();
    failingKeys.add(CHILD_SESSIONS_KEY);
    childDeviceSession.mockResolvedValueOnce(deviceSessionBody(2, 'child-2-a'));

    await expect(switchToChild({ id: 2 })).rejects.toMatchObject({ kind: 'failed' });
    expect(logoutToken).not.toHaveBeenCalledWith('parent-token', expect.anything());
    expect(logoutToken).toHaveBeenCalledWith('child-2-a', expect.anything());
    expect(vault.get(AUTH_KEY)).toBe('parent-token');
    expect(useAppStore.getState().user?.role).toBe('parent');
  });

  it('QA m2: the active token can\'t be written → abort, parent untouched', async () => {
    signInParent();
    failingKeys.add(AUTH_KEY);
    childDeviceSession.mockResolvedValueOnce(deviceSessionBody(2, 'child-2-a'));

    await expect(switchToChild({ id: 2 })).rejects.toMatchObject({ kind: 'failed' });
    expect(logoutToken).not.toHaveBeenCalledWith('parent-token', expect.anything());
    expect(vault.get(AUTH_KEY)).toBe('parent-token');
    expect(useAppStore.getState().user?.role).toBe('parent');
  });

  it('QA m1: a second tap while a switch runs is refused — token and UI stay on the same child', async () => {
    signInParent();
    let release: () => void = () => undefined;
    childDeviceSession.mockImplementationOnce(
      (id: number) => new Promise((resolve) => (release = () => resolve(deviceSessionBody(id, `child-${id}-a`)))),
    );

    const first = switchToChild({ id: 2 });
    await expect(switchToChild({ id: 5 })).rejects.toMatchObject({ kind: 'busy' });
    for (let i = 0; i < 50 && childDeviceSession.mock.calls.length === 0; i += 1) await Promise.resolve();
    release();
    await first;

    expect(childDeviceSession).toHaveBeenCalledTimes(1);
    expect(vault.get(AUTH_KEY)).toBe('child-2-a');
    expect(useAppStore.getState().user?.id).toBe(2);
  });
});

describe('back to the parent = real login', () => {
  it('openParentLogin only in a shared child session', async () => {
    signInParent();
    childDeviceSession.mockResolvedValueOnce(deviceSessionBody(2, 'child-2-a'));
    await switchToChild({ id: 2 });

    openParentLogin();
    expect(useAppStore.getState().parentLoginOpen).toBe(true);

    useAppStore.setState({ parentLoginOpen: false, sharedSession: false });
    openParentLogin();
    expect(useAppStore.getState().parentLoginOpen).toBe(false);
  });

  it('afterParentSignIn: closes step windows, stops the child step task, revokes other parents\' child tokens, keeps own', async () => {
    storedSessions({ '2': { token: 'child-2-own', parentUserId: 1 }, '5': { token: 'child-5-foreign', parentUserId: 99 } });
    vault.set(STEP_WINDOWS_KEY, JSON.stringify({ '2': [{ start: Date.now() - 60_000, end: null }] }));

    await afterParentSignIn(1);

    expect(logoutToken).toHaveBeenCalledWith('child-5-foreign', expect.anything());
    expect(logoutToken).not.toHaveBeenCalledWith('child-2-own', expect.anything());
    expect(await loadChildSessions()).toEqual({ '2': { token: 'child-2-own', parentUserId: 1 } });
    expect((await loadStepWindows())['2'][0].end).not.toBeNull();
    expect(unregisterStepSyncTask).toHaveBeenCalled();
  });
});

describe('logout', () => {
  it('a parent logout revokes and forgets every stored child token', async () => {
    signInParent();
    storedSessions({ '2': { token: 'child-2-a', parentUserId: 1 }, '5': { token: 'child-5-b', parentUserId: 1 } });

    await logout();

    expect(logoutToken).toHaveBeenCalledWith('child-2-a', expect.anything());
    expect(logoutToken).toHaveBeenCalledWith('child-5-b', expect.anything());
    expect(apiLogout).toHaveBeenCalledTimes(1);
    expect(vault.has(CHILD_SESSIONS_KEY)).toBe(false);
    expect(useAppStore.getState().user).toBeNull();
  });

  it('a shared child session ending (e.g. 401) forgets only its own token and closes its window', async () => {
    signInParent();
    storedSessions({ '5': { token: 'child-5-b', parentUserId: 1 } });
    childDeviceSession.mockResolvedValueOnce(deviceSessionBody(2, 'child-2-a'));
    await switchToChild({ id: 2 });

    await logout({ revoke: false });

    expect(await loadChildSessions()).toEqual({ '5': { token: 'child-5-b', parentUserId: 1 } });
    expect((await loadStepWindows())['2'][0].end).not.toBeNull();
    expect(useAppStore.getState().user).toBeNull();
  });

  it("the child's own phone (PIN login): logout unchanged, no stored sessions touched", async () => {
    vault.set(AUTH_KEY, 'own-child-token');
    useAppStore.getState().signIn({ token: 'own-child-token', user: { id: 2, name: 'Maja', email: null, role: 'child' }, pet: PET });

    await logout();

    expect(apiLogout).toHaveBeenCalledTimes(1);
    expect(logoutToken).not.toHaveBeenCalled();
    expect(useAppStore.getState().sharedSession).toBe(false);
  });
});

describe('restoreSession', () => {
  it('restores the active shared child session with the flag', async () => {
    signInParent();
    childDeviceSession.mockResolvedValueOnce(deviceSessionBody(2, 'child-2-a'));
    await switchToChild({ id: 2 });

    const result = await restoreSession();

    expect(result.status === 'authenticated' && result.session).toMatchObject({ token: 'child-2-a', user: { role: 'child' }, sharedSession: true });
  });

  it('QA m4: a rejected shared child token → signed out, token forgotten, window closed, step task + live steps cleared', async () => {
    signInParent();
    childDeviceSession.mockResolvedValueOnce(deviceSessionBody(2, 'child-2-a'));
    await switchToChild({ id: 2 });
    vault.set('petprep_live_steps_today_2', JSON.stringify({ date: '2026-10-14', steps: 500 }));
    getUser.mockRejectedValueOnce(new ApiError('Unauthenticated.', 401, null));

    await expect(restoreSession()).resolves.toEqual({ status: 'anonymous' });

    expect(await loadChildSessions()).toEqual({});
    expect((await loadStepWindows())['2'][0].end).not.toBeNull();
    expect(vault.has('petprep_live_steps_today_2')).toBe(false);
    expect(unregisterStepSyncTask).toHaveBeenCalled();
  });

  it('migrates the first draft: a parked parent and bare-string child tokens are revoked and deleted', async () => {
    vault.set(LEGACY_PARKED_PARENT_KEY, JSON.stringify({ token: 'old-parked-parent', user: PARENT }));
    vault.set(CHILD_SESSIONS_KEY, JSON.stringify({ '2': 'old-child-token', '5': { token: 'child-5-b', parentUserId: 1 } }));

    await migrateLegacyProfiles();

    expect(logoutToken).toHaveBeenCalledWith('old-parked-parent', expect.anything());
    expect(logoutToken).toHaveBeenCalledWith('old-child-token', expect.anything());
    expect(vault.has(LEGACY_PARKED_PARENT_KEY)).toBe(false);
    expect(await loadChildSessions()).toEqual({ '5': { token: 'child-5-b', parentUserId: 1 } });
  });
});

describe('push', () => {
  beforeEach(() => {
    resetPushRegistration();
    jest.mocked(Notifications.getPermissionsAsync).mockResolvedValue({ status: 'granted', granted: true, canAskAgain: true, expires: 'never' } as Notifications.NotificationPermissionsStatus);
    jest.mocked(Notifications.getExpoPushTokenAsync).mockResolvedValue({ type: 'expo', data: 'ExponentPushToken[shared]' });
  });

  it('registers for the child after the switch and for the parent again after the re-login', async () => {
    signInParent();
    await registerForPush();
    childDeviceSession.mockResolvedValueOnce(deviceSessionBody(2, 'child-2-a'));
    await switchToChild({ id: 2 });
    await registerForPush(); // the push effect re-runs for the new user id
    expect(registerDevice).toHaveBeenCalledTimes(2);
    signInParent(PARENT, 'parent-token-2'); // parent signed in again
    jest.useFakeTimers({ doNotFake: ['nextTick', 'setImmediate'] });
    try {
      // The `POST /api/devices` limiter (2, then 1 / min) delays a quick third one — it retries.
      await registerForPush();
      await jest.advanceTimersByTimeAsync(61_000);
    } finally {
      jest.useRealTimers();
    }
    expect(registerDevice).toHaveBeenCalledTimes(3);
    expect(registerDevice).toHaveBeenLastCalledWith(expect.objectContaining({ expo_push_token: 'ExponentPushToken[shared]' }));
  });
});

describe('QA n1: revokes that fail are queued and retried', () => {
  it('an offline parent revoke during the switch is queued; a later retry clears it (also on 401), keeps real failures', async () => {
    signInParent();
    childDeviceSession.mockResolvedValueOnce(deviceSessionBody(2, 'child-2-a'));
    logoutToken.mockRejectedValueOnce(new TypeError('Network request failed'));

    await switchToChild({ id: 2 });

    expect(useAppStore.getState().user?.role).toBe('child'); // the switch itself went through
    expect(JSON.parse(vault.get(PENDING_REVOKES_KEY) ?? '[]')).toEqual(['parent-token']);

    // Still offline at the next foreground → kept.
    logoutToken.mockRejectedValueOnce(new TypeError('Network request failed'));
    await retryPendingRevokes();
    expect(JSON.parse(vault.get(PENDING_REVOKES_KEY) ?? '[]')).toEqual(['parent-token']);

    // Back online → revoked and dropped.
    await retryPendingRevokes();
    expect(logoutToken).toHaveBeenLastCalledWith('parent-token', expect.anything());
    expect(vault.has(PENDING_REVOKES_KEY)).toBe(false);
  });

  it('a 401 (already gone) also drops the entry; only bare tokens are stored', async () => {
    vault.set(PENDING_REVOKES_KEY, JSON.stringify(['gone-token', 42, '']));
    logoutToken.mockRejectedValueOnce(new ApiError('Unauthenticated.', 401, null));

    await retryPendingRevokes();

    expect(logoutToken).toHaveBeenCalledTimes(1);
    expect(vault.has(PENDING_REVOKES_KEY)).toBe(false);
  });
});

