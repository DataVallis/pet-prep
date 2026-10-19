import * as SecureStore from 'expo-secure-store';

import { ApiError, api } from '@/api/client';
import {
  KEYCHAIN_READ_TIMEOUT_MS,
  OPTIONAL_READ_TIMEOUT_MS,
  USER_REQUEST_TIMEOUT_MS,
  resetRestoreForTests,
  restoreSession,
} from '@/modules/session/restoreSession';
import { KEYCHAIN_MIGRATION_KEY, resetKeychainMigrationForTests } from '@/modules/session/keychainMigration';
import { TimeoutError } from '@/utils/withTimeout';
import { unregisterStepSyncTask } from '@/modules/steps/backgroundSteps';
import { makePet } from '@/test-utils/fixtures';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, getUser: jest.fn(), logout: jest.fn() } };
});

const getItem = SecureStore.getItemAsync as jest.Mock;
const deleteItem = SecureStore.deleteItemAsync as jest.Mock;
const getUser = api.getUser as jest.Mock;

jest.mock('@/modules/steps/backgroundSteps', () => ({ unregisterStepSyncTask: jest.fn(async () => undefined) }));

describe('restoreSession', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    resetRestoreForTests();
    resetKeychainMigrationForTests();
  });

  it('is anonymous without a saved token and never calls the API', async () => {
    getItem.mockResolvedValueOnce(null);
    await expect(restoreSession()).resolves.toEqual({ status: 'anonymous' });
    expect(getUser).not.toHaveBeenCalled();
  });

  it('is locked — never signed out — when the Keychain is unreadable (phone locked)', async () => {
    getItem.mockRejectedValueOnce(new Error('keychain locked'));
    await expect(restoreSession()).resolves.toEqual({ status: 'locked' });
    expect(getUser).not.toHaveBeenCalled();
    expect(deleteItem).not.toHaveBeenCalled();
  });

  it('returns the flat /api/user payload as a sign-in session', async () => {
    const pet = makePet();
    getItem.mockResolvedValueOnce('tok-123');
    getUser.mockResolvedValueOnce({ id: 2, name: 'Otrok', email: 'c@x.si', role: 'child', pet });

    await expect(restoreSession()).resolves.toEqual({
      status: 'authenticated',
      session: { token: 'tok-123', user: { id: 2, name: 'Otrok', email: 'c@x.si', role: 'child' }, pet, familyTimezone: null, signInMethod: 'password' },
    });
  });

  it('passes the per-child awaiting_contract on as awaitingContract, never on the user (M2-02)', async () => {
    const pet = makePet();
    getItem.mockResolvedValueOnce('tok-9');
    getUser.mockResolvedValueOnce({ id: 3, name: 'Bor', email: null, role: 'child', pet, awaiting_contract: true });

    const result = await restoreSession();
    expect(result).toEqual({
      status: 'authenticated',
      session: { token: 'tok-9', user: { id: 3, name: 'Bor', email: null, role: 'child' }, pet, awaitingContract: true, familyTimezone: null, signInMethod: 'password' },
    });
    if (result.status === 'authenticated') expect(result.session.user).not.toHaveProperty('awaiting_contract');
  });

  it('hotfix 2026-10-06: a child session carries the remembered family timezone (lock overlay before the state loads)', async () => {
    getItem.mockImplementation((key: string) =>
      Promise.resolve(key === 'petprep.family_timezone' ? 'Europe/Ljubljana' : 'tok-5'),
    );
    getUser.mockResolvedValueOnce({ id: 4, name: 'Ana', email: null, role: 'child', pet: makePet() });
    const result = await restoreSession();
    expect(result.status === 'authenticated' && result.session.familyTimezone).toBe('Europe/Ljubljana');

    // A garbage value is ignored; a parent never reads it.
    getItem.mockImplementation((key: string) => Promise.resolve(key === 'petprep.family_timezone' ? '../etc' : 'tok-5'));
    getUser.mockResolvedValueOnce({ id: 4, name: 'Ana', email: null, role: 'child', pet: makePet() });
    const garbage = await restoreSession();
    expect(garbage.status === 'authenticated' && garbage.session.familyTimezone).toBeNull();
    getItem.mockImplementation(() => Promise.resolve(null));
    getItem.mockResolvedValue(null);
  });

  it('migrates the Keychain only after a successful restore, never on 401', async () => {
    const set = SecureStore.setItemAsync as jest.Mock;
    getItem.mockResolvedValueOnce('revoked');
    getUser.mockRejectedValueOnce(new ApiError('Unauthenticated.', 401));
    await restoreSession();
    expect(set).not.toHaveBeenCalledWith('petprep_keychain_after_first_unlock', '1', expect.anything());
  });

  it('clears the token on 401', async () => {
    getItem.mockResolvedValueOnce('revoked');
    getUser.mockRejectedValueOnce(new ApiError('Unauthenticated.', 401));

    await expect(restoreSession()).resolves.toEqual({ status: 'anonymous' });
    expect(deleteItem).toHaveBeenCalledWith('petprep_auth_token');
    expect(unregisterStepSyncTask).toHaveBeenCalledTimes(1); // M3-06: no background sync with a revoked token
  });

  it('keeps the token when the server is unreachable (offline)', async () => {
    getItem.mockResolvedValueOnce('tok-123');
    getUser.mockRejectedValueOnce(new TypeError('Network request failed'));

    const result = await restoreSession();
    expect(result.status).toBe('offline');
    expect(deleteItem).not.toHaveBeenCalled();
    expect(unregisterStepSyncTask).not.toHaveBeenCalled();
  });

  it('keeps the token on a 5xx', async () => {
    getItem.mockResolvedValueOnce('tok-123');
    getUser.mockRejectedValueOnce(new ApiError('Server Error', 503));

    expect((await restoreSession()).status).toBe('offline');
    expect(deleteItem).not.toHaveBeenCalled();
  });

  it('M2-10c: a parent session carries the stored sign-in method (Google / Apple → re-auth for deletions)', async () => {
    const values: Record<string, string> = { petprep_auth_token: 'tok-p', petprep_sign_in_method: 'apple' };
    getItem.mockImplementation(async (key: string) => values[key] ?? null);
    getUser.mockResolvedValueOnce({ id: 1, name: 'Mama', email: 'm@x.si', role: 'parent', pet: null, awaiting_contract: null });
    const result = await restoreSession();
    expect(result.status === 'authenticated' && result.session.signInMethod).toBe('apple');

    values.petprep_sign_in_method = 'something-else';
    getUser.mockResolvedValueOnce({ id: 1, name: 'Mama', email: 'm@x.si', role: 'parent', pet: null, awaiting_contract: null });
    const unknown = await restoreSession();
    expect(unknown.status === 'authenticated' && unknown.session.signInMethod).toBe('password');
    getItem.mockImplementation(() => Promise.resolve(null));
  });

  it('passes a request timeout to /api/user (a launch never waits forever)', async () => {
    getItem.mockResolvedValueOnce('tok-1');
    getUser.mockResolvedValueOnce({ id: 1, name: 'Mama', email: 'm@x.si', role: 'parent', pet: null });
    await restoreSession();
    expect(getUser).toHaveBeenCalledWith({ timeoutMs: USER_REQUEST_TIMEOUT_MS });
  });

  it('a timed-out /api/user is offline — the token is kept', async () => {
    getItem.mockResolvedValueOnce('tok-1');
    getUser.mockRejectedValueOnce(new TimeoutError());
    expect((await restoreSession()).status).toBe('offline');
    expect(deleteItem).not.toHaveBeenCalled();
  });

  describe('boot-hang fix 2026-10-19: a native call that never settles', () => {
    const never = () => new Promise<never>(() => undefined);

    beforeEach(() => jest.useFakeTimers());
    afterEach(() => {
      jest.useRealTimers();
      getItem.mockReset();
      getItem.mockResolvedValue(null);
    });

    it('a Keychain token read that hangs → locked (retried, never a sign-out)', async () => {
      getItem.mockImplementationOnce(never);
      const result = restoreSession();
      await jest.advanceTimersByTimeAsync(KEYCHAIN_READ_TIMEOUT_MS);
      await expect(result).resolves.toEqual({ status: 'locked' });
      expect(getUser).not.toHaveBeenCalled();
      expect(deleteItem).not.toHaveBeenCalled();
    });

    it('a hanging child-sessions read (M2-11 shared phone) → locked', async () => {
      getItem.mockResolvedValueOnce('tok-child').mockImplementationOnce(never);
      const result = restoreSession();
      await jest.advanceTimersByTimeAsync(KEYCHAIN_READ_TIMEOUT_MS);
      await expect(result).resolves.toEqual({ status: 'locked' });
      expect(getUser).not.toHaveBeenCalled();
    });

    it('a hanging family-zone read does not block the restore', async () => {
      getItem.mockImplementation((key: string) => {
        if (key === 'petprep_auth_token') return Promise.resolve('tok-c');
        if (key === 'petprep_child_sessions') return Promise.resolve(null);
        if (key === KEYCHAIN_MIGRATION_KEY) return Promise.resolve('1');
        return never(); // family zone hangs
      });
      getUser.mockResolvedValueOnce({ id: 4, name: 'Ana', email: null, role: 'child', pet: makePet() });
      const result = restoreSession();
      await jest.advanceTimersByTimeAsync(OPTIONAL_READ_TIMEOUT_MS);
      const settled = await result;
      expect(settled.status).toBe('authenticated');
      expect(settled.status === 'authenticated' && settled.session.familyTimezone).toBeNull();
    });

    describe('QA M1: never a session (or the login screen) while the migration may have deleted the token', () => {
      /** Keychain whose migration stalls between delete and re-add of the token. */
      function stallingVault() {
        const vault = new Map<string, string>([['petprep_auth_token', 'tok-c']]);
        let releaseSet: () => void = () => undefined;
        getItem.mockImplementation(async (key: string) => vault.get(key) ?? null);
        deleteItem.mockImplementation(async (key: string) => {
          vault.delete(key);
        });
        (SecureStore.setItemAsync as jest.Mock).mockImplementation(
          (key: string, value: string) =>
            new Promise<void>((resolve) => {
              releaseSet = () => {
                vault.set(key, value);
                resolve();
              };
            }),
        );
        return { vault, release: () => releaseSet() };
      }

      afterEach(() => {
        deleteItem.mockReset();
        (SecureStore.setItemAsync as jest.Mock).mockReset();
      });

      it('a migration slower than its budget → locked (not authenticated); the next restore waits for it', async () => {
        const keychain = stallingVault();
        getUser.mockResolvedValue({ id: 4, name: 'Ana', email: null, role: 'parent', pet: null });

        const first = restoreSession();
        await jest.advanceTimersByTimeAsync(KEYCHAIN_READ_TIMEOUT_MS);
        await expect(first).resolves.toEqual({ status: 'locked' });
        expect(keychain.vault.has('petprep_auth_token')).toBe(false); // mid delete + re-add

        // "Poskusi znova" now: must not read the missing token as "signed out".
        const second = restoreSession();
        await jest.advanceTimersByTimeAsync(100);
        keychain.release(); // token re-added
        await jest.advanceTimersByTimeAsync(0);
        keychain.release(); // child sessions key (absent → nothing) / marker
        await jest.advanceTimersByTimeAsync(0);
        const result = await second;
        expect(result).toMatchObject({ status: 'authenticated', session: { token: 'tok-c' } });
        expect(deleteItem).not.toHaveBeenCalledWith('petprep_child_sessions');
      });

      it('a migration that never finishes → locked on every retry, never anonymous', async () => {
        stallingVault();
        getUser.mockResolvedValue({ id: 4, name: 'Ana', email: null, role: 'parent', pet: null });
        const first = restoreSession();
        await jest.advanceTimersByTimeAsync(KEYCHAIN_READ_TIMEOUT_MS);
        await expect(first).resolves.toEqual({ status: 'locked' });
        const retry = restoreSession();
        await jest.advanceTimersByTimeAsync(KEYCHAIN_READ_TIMEOUT_MS);
        await expect(retry).resolves.toEqual({ status: 'locked' });
      });
    });

    it('single-flight: "Poskusi znova" mid-/api/user joins the running restore (one request, one Keychain read)', async () => {
      getItem.mockImplementation(async (key: string) => (key === 'petprep_auth_token' ? 'tok-c' : key === KEYCHAIN_MIGRATION_KEY ? '1' : null));
      let answer: (value: unknown) => void = () => undefined;
      getUser.mockReturnValueOnce(new Promise((resolve) => (answer = resolve)));
      const first = restoreSession();
      await jest.advanceTimersByTimeAsync(10);
      const second = restoreSession();
      expect(second).toBe(first);
      answer({ id: 1, name: 'Mama', email: 'm@x.si', role: 'parent', pet: null });
      await expect(second).resolves.toMatchObject({ status: 'authenticated' });
      expect(getUser).toHaveBeenCalledTimes(1);
      // Finished → the next call starts a new restore.
      getUser.mockResolvedValueOnce({ id: 1, name: 'Mama', email: 'm@x.si', role: 'parent', pet: null });
      expect(restoreSession()).not.toBe(first);
    });
  });
});
