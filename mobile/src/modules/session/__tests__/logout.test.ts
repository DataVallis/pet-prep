import * as SecureStore from 'expo-secure-store';

import { api } from '@/api/client';
import { logout, REVOKE_TIMEOUT_MS } from '@/modules/session/logout';
import { useAppStore } from '@/store/appStore';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, logout: jest.fn() } };
});

const apiLogout = api.logout as jest.Mock;
const deleteItem = SecureStore.deleteItemAsync as jest.Mock;

function signedIn() {
  useAppStore.getState().signIn({
    token: 'tok',
    user: { id: 1, name: 'Starš', email: 'p@x.si', role: 'parent' },
    pet: null,
  });
}

describe('logout', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    jest.useFakeTimers();
    useAppStore.setState(useAppStore.getInitialState(), true);
    signedIn();
  });

  afterEach(() => {
    jest.useRealTimers();
  });

  it('aborts a hanging server revoke after the timeout and still logs out locally', async () => {
    let signal: AbortSignal | undefined;
    apiLogout.mockImplementation(
      (s?: AbortSignal) =>
        new Promise((_, reject) => {
          signal = s;
          s?.addEventListener('abort', () => reject(new Error('Aborted')));
        }),
    );

    const done = logout();
    await jest.advanceTimersByTimeAsync(REVOKE_TIMEOUT_MS - 1);
    expect(signal?.aborted).toBe(false);
    expect(useAppStore.getState().authToken).toBe('tok');

    await jest.advanceTimersByTimeAsync(1);
    await done;
    expect(signal?.aborted).toBe(true);
    expect(deleteItem).toHaveBeenCalledWith('petprep_auth_token');
    expect(useAppStore.getState().authToken).toBeNull();
  });

  it('clears the abort timer when the revoke succeeds', async () => {
    apiLogout.mockResolvedValueOnce({ message: 'ok' });
    await logout();
    expect(jest.getTimerCount()).toBe(0);
    expect(useAppStore.getState().user).toBeNull();
  });

  it('skips the server call with revoke: false (token already rejected)', async () => {
    await logout({ revoke: false });
    expect(apiLogout).not.toHaveBeenCalled();
    expect(deleteItem).toHaveBeenCalled();
  });
});
