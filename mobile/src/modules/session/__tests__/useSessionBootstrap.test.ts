import { act, renderHook } from '@testing-library/react-native';

import { useSessionBootstrap } from '@/modules/session/useSessionBootstrap';
import { restoreSession, type RestoreResult } from '@/modules/session/restoreSession';
import { useAppStore } from '@/store/appStore';

jest.mock('@/modules/session/restoreSession', () => ({ restoreSession: jest.fn() }));
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
});
