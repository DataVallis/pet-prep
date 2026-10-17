import { act, renderHook } from '@testing-library/react-native';
import { AppState, type AppStateStatus } from 'react-native';

import { MAX_FOREGROUND_LOCKED, LOCKED_RETRY_MS, useSessionBootstrap } from '@/modules/session/useSessionBootstrap';
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

    it('a locked Keychain in the foreground (inactive launch) waits for the next active', async () => {
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
            await jest.advanceTimersByTimeAsync(LOCKED_RETRY_MS);
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
});
