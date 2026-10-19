import { act, renderHook } from '@testing-library/react-native';
import { AppState, type AppStateStatus } from 'react-native';

import {
  BOOT_WATCHDOG_MS,
  LOCKED_RETRY_DELAYS_MS,
  MAX_FOREGROUND_LOCKED,
  useSessionBootstrap,
} from '@/modules/session/useSessionBootstrap';
import { restoreSession, type RestoreResult } from '@/modules/session/restoreSession';
import { useAppStore } from '@/store/appStore';

jest.mock('@/modules/session/restoreSession', () => ({ restoreSession: jest.fn(), migrateLegacyProfiles: jest.fn(async () => undefined) }));
jest.mock('@/modules/session/logout', () => ({ logout: jest.fn(() => Promise.resolve()) }));

const restore = restoreSession as jest.Mock;

function deferred() {
  let resolve: (value: RestoreResult) => void = () => undefined;
  const promise = new Promise<RestoreResult>((r) => (resolve = r));
  return { promise, resolve };
}

const PARENT: RestoreResult = {
  status: 'authenticated',
  session: { token: 'new', user: { id: 1, name: 'Starš', email: 'p@x.si', role: 'parent' }, pet: null },
};
const CHILD: RestoreResult = {
  status: 'authenticated',
  session: { token: 'old', user: { id: 2, name: 'Otrok', email: 'c@x.si', role: 'child' }, pet: null },
};

describe('useSessionBootstrap', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    useAppStore.setState(useAppStore.getInitialState(), true);
  });

  it('ignores a stale run that finishes after a newer one (double bootstrap)', async () => {
    const first = deferred();
    const second = deferred();
    restore.mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise);

    const { result } = renderHook(() => useSessionBootstrap());
    act(() => result.current.retry());

    await act(async () => {
      second.resolve(PARENT);
      await second.promise;
    });
    expect(useAppStore.getState().user?.role).toBe('parent');

    await act(async () => {
      first.resolve(CHILD);
      await first.promise;
    });
    expect(useAppStore.getState().user?.role).toBe('parent');
    expect(useAppStore.getState().authToken).toBe('new');
  });

  it('a stale offline result cannot overwrite a newer successful restore', async () => {
    const first = deferred();
    const second = deferred();
    restore.mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise);

    const { result } = renderHook(() => useSessionBootstrap());
    act(() => result.current.retry());

    await act(async () => {
      second.resolve(PARENT);
      first.resolve({ status: 'offline', error: new TypeError('Network request failed') });
      await Promise.all([first.promise, second.promise]);
    });
    expect(useAppStore.getState().bootStatus).toBe('ready');
    expect(useAppStore.getState().user?.role).toBe('parent');
  });

  it('ignores a result that arrives after unmount', async () => {
    const first = deferred();
    restore.mockReturnValueOnce(first.promise);

    const { unmount } = renderHook(() => useSessionBootstrap());
    unmount();
    await act(async () => {
      first.resolve(PARENT);
      await first.promise;
    });
    expect(useAppStore.getState().authToken).toBeNull();
  });

  describe('fix 2026-10-17: a locked-phone launch never signs the child out', () => {
    let listeners: Array<(state: AppStateStatus) => void>;

    function setAppState(state: AppStateStatus) {
      Object.defineProperty(AppState, 'currentState', { value: state, configurable: true, writable: true });
    }

    beforeEach(() => {
      listeners = [];
      jest.spyOn(AppState, 'addEventListener').mockImplementation((_type, handler) => {
        listeners.push(handler as (state: AppStateStatus) => void);
        return { remove: jest.fn() } as unknown as ReturnType<typeof AppState.addEventListener>;
      });
    });

    afterEach(() => {
      jest.restoreAllMocks();
      setAppState('active');
    });

    it('background launch does not restore at all; the foreground restores the session', async () => {
      setAppState('background');
      restore.mockResolvedValueOnce(CHILD);

      renderHook(() => useSessionBootstrap());
      await act(async () => undefined);
      expect(useAppStore.getState().bootStatus).toBe('restoring');
      expect(useAppStore.getState().user).toBeNull();
      expect(restore).not.toHaveBeenCalled();

      setAppState('active');
      await act(async () => {
        listeners.forEach((l) => l('active'));
      });
      expect(restore).toHaveBeenCalledTimes(1);
      expect(useAppStore.getState().user?.role).toBe('child');
      expect(useAppStore.getState().authToken).toBe('old');
    });

    it('a locked Keychain in an inactive launch also restores on the next active', async () => {
      setAppState('inactive');
      restore.mockResolvedValueOnce({ status: 'locked' }).mockResolvedValueOnce(CHILD);
      renderHook(() => useSessionBootstrap());
      await act(async () => undefined);
      expect(useAppStore.getState().bootStatus).toBe('restoring');
      setAppState('active');
      await act(async () => {
        listeners.forEach((l) => l('active'));
      });
      expect(restore).toHaveBeenCalledTimes(2);
      expect(useAppStore.getState().user?.role).toBe('child');
    });

    it('a foreground return after a normal restore does not re-run the restore', async () => {
      setAppState('active');
      restore.mockResolvedValueOnce(CHILD);
      renderHook(() => useSessionBootstrap());
      await act(async () => undefined);
      await act(async () => {
        listeners.forEach((l) => l('active'));
      });
      expect(restore).toHaveBeenCalledTimes(1);
    });

    it('in the foreground retries shortly and shows the offline screen (never login) after repeated locks', async () => {
      jest.useFakeTimers();
      try {
        setAppState('active');
        restore.mockResolvedValue({ status: 'locked' });
        renderHook(() => useSessionBootstrap());
        for (let i = 1; i < MAX_FOREGROUND_LOCKED; i += 1) {
          await act(async () => undefined);
          expect(useAppStore.getState().bootStatus).toBe('restoring');
          await act(async () => {
            await jest.advanceTimersByTimeAsync(LOCKED_RETRY_DELAYS_MS[i - 1]);
          });
        }
        await act(async () => undefined);
        expect(restore).toHaveBeenCalledTimes(MAX_FOREGROUND_LOCKED);
        expect(useAppStore.getState().bootStatus).toBe('offline');
        expect(useAppStore.getState().user).toBeNull();
      } finally {
        restore.mockReset();
        jest.useRealTimers();
      }
    });
  });

  describe('boot-hang fix 2026-10-19: the splash never spins forever', () => {
    function setAppState(state: AppStateStatus) {
      Object.defineProperty(AppState, 'currentState', { value: state, configurable: true, writable: true });
    }
    let listeners: Array<(state: AppStateStatus) => void>;

    beforeEach(() => {
      jest.useFakeTimers();
      listeners = [];
      jest.spyOn(AppState, 'addEventListener').mockImplementation((_type, handler) => {
        listeners.push(handler as (state: AppStateStatus) => void);
        return { remove: jest.fn() } as unknown as ReturnType<typeof AppState.addEventListener>;
      });
      setAppState('active');
    });

    afterEach(() => {
      restore.mockReset();
      jest.useRealTimers();
      jest.restoreAllMocks();
      setAppState('active');
    });

    it('a restore that never settles shows the offline screen after the watchdog — token untouched, no logout', async () => {
      const { logout } = jest.requireMock<{ logout: jest.Mock }>('@/modules/session/logout');
      const stuck = deferred();
      restore.mockReturnValueOnce(stuck.promise);
      renderHook(() => useSessionBootstrap());

      await act(async () => {
        await jest.advanceTimersByTimeAsync(BOOT_WATCHDOG_MS - 1);
      });
      expect(useAppStore.getState().bootStatus).toBe('restoring');
      await act(async () => {
        await jest.advanceTimersByTimeAsync(1);
      });
      expect(useAppStore.getState().bootStatus).toBe('offline');
      expect(logout).not.toHaveBeenCalled();

      // The abandoned run finishing late must not flip the screen.
      await act(async () => {
        stuck.resolve(CHILD);
        await stuck.promise;
      });
      expect(useAppStore.getState().bootStatus).toBe('offline');
      expect(useAppStore.getState().user).toBeNull();
    });

    it('"Poskusi znova" after the watchdog restores normally', async () => {
      restore.mockReturnValueOnce(deferred().promise).mockResolvedValueOnce(CHILD);
      const { result } = renderHook(() => useSessionBootstrap());
      await act(async () => {
        await jest.advanceTimersByTimeAsync(BOOT_WATCHDOG_MS);
      });
      expect(useAppStore.getState().bootStatus).toBe('offline');
      await act(async () => {
        result.current.retry();
        await jest.advanceTimersByTimeAsync(0);
      });
      expect(useAppStore.getState().bootStatus).toBe('ready');
      expect(useAppStore.getState().user?.role).toBe('child');
    });

    it('a retry gets a fresh watchdog budget (does not inherit the old one)', async () => {
      restore.mockReturnValueOnce(deferred().promise).mockReturnValueOnce(deferred().promise);
      const { result } = renderHook(() => useSessionBootstrap());
      await act(async () => {
        await jest.advanceTimersByTimeAsync(BOOT_WATCHDOG_MS - 1_000);
        result.current.retry();
        await jest.advanceTimersByTimeAsync(2_000);
      });
      expect(useAppStore.getState().bootStatus).toBe('restoring');
      await act(async () => {
        await jest.advanceTimersByTimeAsync(BOOT_WATCHDOG_MS);
      });
      expect(useAppStore.getState().bootStatus).toBe('offline');
    });

    it('a locked Keychain with the app ACTIVE retries by itself with backoff — no foreground event needed', async () => {
      restore.mockResolvedValueOnce({ status: 'locked' }).mockResolvedValueOnce({ status: 'locked' }).mockResolvedValueOnce(CHILD);
      renderHook(() => useSessionBootstrap());
      await act(async () => {
        await jest.advanceTimersByTimeAsync(0);
      });
      expect(restore).toHaveBeenCalledTimes(1);
      await act(async () => {
        await jest.advanceTimersByTimeAsync(LOCKED_RETRY_DELAYS_MS[0]);
      });
      expect(restore).toHaveBeenCalledTimes(2);
      await act(async () => {
        await jest.advanceTimersByTimeAsync(LOCKED_RETRY_DELAYS_MS[1]);
      });
      expect(restore).toHaveBeenCalledTimes(3);
      expect(useAppStore.getState().bootStatus).toBe('ready');
      expect(useAppStore.getState().user?.role).toBe('child');
    });

    it('a locked Keychain in an inactive launch retries by itself too (the active event may never come)', async () => {
      setAppState('inactive');
      restore.mockResolvedValueOnce({ status: 'locked' }).mockResolvedValueOnce(CHILD);
      renderHook(() => useSessionBootstrap());
      await act(async () => {
        await jest.advanceTimersByTimeAsync(LOCKED_RETRY_DELAYS_MS[0]);
      });
      expect(restore).toHaveBeenCalledTimes(2);
      expect(useAppStore.getState().user?.role).toBe('child');
    });

    it('a locked Keychain that never opens ends on the offline screen well within the watchdog, never on login', async () => {
      restore.mockResolvedValue({ status: 'locked' });
      renderHook(() => useSessionBootstrap());
      const total = LOCKED_RETRY_DELAYS_MS.reduce((a, b) => a + b, 0);
      expect(total).toBeLessThan(BOOT_WATCHDOG_MS);
      await act(async () => {
        await jest.advanceTimersByTimeAsync(total);
      });
      expect(restore).toHaveBeenCalledTimes(MAX_FOREGROUND_LOCKED);
      expect(useAppStore.getState().bootStatus).toBe('offline');
      expect(useAppStore.getState().authToken).toBeNull();
      // No stray retry afterwards.
      await act(async () => {
        await jest.advanceTimersByTimeAsync(60_000);
      });
      expect(restore).toHaveBeenCalledTimes(MAX_FOREGROUND_LOCKED);
    });

    it('a background launch runs no watchdog; the foreground starts a fresh run with a full budget', async () => {
      setAppState('background');
      renderHook(() => useSessionBootstrap());
      await act(async () => {
        await jest.advanceTimersByTimeAsync(BOOT_WATCHDOG_MS * 3);
      });
      expect(useAppStore.getState().bootStatus).toBe('restoring');
      expect(restore).not.toHaveBeenCalled();

      setAppState('active');
      restore.mockReturnValueOnce(deferred().promise);
      await act(async () => {
        listeners.forEach((l) => l('active'));
        await jest.advanceTimersByTimeAsync(BOOT_WATCHDOG_MS - 1);
      });
      expect(restore).toHaveBeenCalledTimes(1);
      expect(useAppStore.getState().bootStatus).toBe('restoring');
      await act(async () => {
        await jest.advanceTimersByTimeAsync(1);
      });
      expect(useAppStore.getState().bootStatus).toBe('offline');
    });

    it('a restore that throws (bug) shows the offline screen at once', async () => {
      restore.mockRejectedValueOnce(new Error('boom'));
      renderHook(() => useSessionBootstrap());
      await act(async () => {
        await jest.advanceTimersByTimeAsync(0);
      });
      expect(useAppStore.getState().bootStatus).toBe('offline');
    });

    it('a normal restore leaves no timer behind', async () => {
      restore.mockResolvedValueOnce(PARENT);
      renderHook(() => useSessionBootstrap());
      await act(async () => {
        await jest.advanceTimersByTimeAsync(0);
      });
      expect(useAppStore.getState().bootStatus).toBe('ready');
      expect(jest.getTimerCount()).toBe(0);
    });
  });
});
