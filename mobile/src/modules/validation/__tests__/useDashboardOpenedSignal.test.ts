/** M5-08 H6 hook wiring: mount, AppState (incl. iOS background → inactive → active) and push taps. */
import { act, renderHook } from '@testing-library/react-native';
import { AppState, type AppStateStatus } from 'react-native';

import { api } from '@/api/client';
import { useAppStore } from '@/store/appStore';
import { DASHBOARD_OPEN_DEBOUNCE_MS, OPEN_GRACE_MS } from '../dashboardOpened';
import { resetDashboardOpenedForTests, useDashboardOpenedSignal } from '../useDashboardOpenedSignal';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, reportDashboardOpened: jest.fn(() => Promise.resolve(null)) } };
});

const report = api.reportDashboardOpened as jest.Mock;
let listeners: Array<(state: AppStateStatus) => void>;
const remove = jest.fn();

function emit(state: AppStateStatus) {
  act(() => {
    for (const l of listeners) l(state);
  });
}

function advance(ms: number) {
  act(() => {
    jest.advanceTimersByTime(ms);
  });
}

/** Past the launch report and its debounce, so the next open may be sent. */
function settleLaunch() {
  advance(OPEN_GRACE_MS);
  expect(report).toHaveBeenLastCalledWith('launch');
  advance(DASHBOARD_OPEN_DEBOUNCE_MS);
  report.mockClear();
}

beforeEach(() => {
  jest.useFakeTimers();
  jest.setSystemTime(new Date('2026-11-22T08:00:00Z'));
  jest.clearAllMocks();
  resetDashboardOpenedForTests();
  useAppStore.setState(useAppStore.getInitialState(), true);
  Object.defineProperty(AppState, 'currentState', { value: 'active', configurable: true, writable: true });
  listeners = [];
  jest.spyOn(AppState, 'addEventListener').mockImplementation((_type, handler) => {
    listeners.push(handler as (state: AppStateStatus) => void);
    return { remove } as unknown as ReturnType<typeof AppState.addEventListener>;
  });
});

afterEach(() => {
  jest.restoreAllMocks();
  jest.useRealTimers();
});

describe('useDashboardOpenedSignal', () => {
  it('no parent → nothing', () => {
    renderHook(() => useDashboardOpenedSignal(null));
    advance(OPEN_GRACE_MS * 2);
    expect(report).not.toHaveBeenCalled();
    expect(listeners).toHaveLength(0);
  });

  it('background → active reports `foreground`', () => {
    renderHook(() => useDashboardOpenedSignal(11));
    settleLaunch();
    emit('background');
    emit('active');
    advance(OPEN_GRACE_MS);
    expect(report).toHaveBeenCalledTimes(1);
    expect(report).toHaveBeenCalledWith('foreground');
  });

  it('iOS background → inactive → active still reports `foreground`', () => {
    renderHook(() => useDashboardOpenedSignal(11));
    settleLaunch();
    emit('background');
    emit('inactive');
    emit('active');
    advance(OPEN_GRACE_MS);
    expect(report).toHaveBeenCalledWith('foreground');
  });

  it('inactive → active alone (control centre) is no new open', () => {
    renderHook(() => useDashboardOpenedSignal(11));
    settleLaunch();
    emit('inactive');
    emit('active');
    advance(OPEN_GRACE_MS * 2);
    expect(report).not.toHaveBeenCalled();
  });

  it('the background flag is cleared at the next active', () => {
    renderHook(() => useDashboardOpenedSignal(11));
    settleLaunch();
    emit('background');
    emit('active');
    advance(OPEN_GRACE_MS);
    advance(DASHBOARD_OPEN_DEBOUNCE_MS);
    report.mockClear();
    emit('inactive');
    emit('active');
    advance(OPEN_GRACE_MS * 2);
    expect(report).not.toHaveBeenCalled();
  });

  it('a push tap after the foreground wins the label', () => {
    renderHook(() => useDashboardOpenedSignal(11));
    settleLaunch();
    emit('background');
    emit('active');
    act(() => useAppStore.getState().setPushTarget({ petId: 7, type: 'reminder' }));
    advance(OPEN_GRACE_MS * 2);
    expect(report).toHaveBeenCalledTimes(1);
    expect(report).toHaveBeenCalledWith('push');
  });

  it('a push already pending at mount reports `push`, not `launch`', () => {
    useAppStore.getState().setPushTarget({ petId: 7, type: 'reminder' });
    renderHook(() => useDashboardOpenedSignal(11));
    advance(OPEN_GRACE_MS * 2);
    expect(report).toHaveBeenCalledTimes(1);
    expect(report).toHaveBeenCalledWith('push');
  });

  it('unmount removes the listener and cancels a waiting report', () => {
    const { unmount } = renderHook(() => useDashboardOpenedSignal(11));
    unmount();
    advance(OPEN_GRACE_MS * 2);
    expect(report).not.toHaveBeenCalled();
    expect(remove).toHaveBeenCalled();
  });
});
