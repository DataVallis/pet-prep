/**
 * M5-R02 countdown clock: ticks every 15 s while enabled, adds the server skew and
 * re-reads the device clock as soon as the app is active again (no stale value after
 * the background, where timers don't run).
 */
import { act, renderHook } from '@testing-library/react-native';
import { AppState, type AppStateStatus } from 'react-native';

import { SERVER_NOW_TICK_MS, useServerNow } from '@/hooks/useServerNow';

type Listener = (state: AppStateStatus) => void;

describe('useServerNow', () => {
  let listeners: Listener[] = [];

  beforeEach(() => {
    listeners = [];
    jest.useFakeTimers();
    jest.setSystemTime(new Date('2026-10-04T10:00:00Z'));
    jest.spyOn(AppState, 'addEventListener').mockImplementation((_type, listener) => {
      listeners.push(listener as Listener);
      return { remove: () => (listeners = listeners.filter((l) => l !== listener)) } as ReturnType<
        typeof AppState.addEventListener
      >;
    });
  });

  afterEach(() => {
    jest.useRealTimers();
    jest.restoreAllMocks();
  });

  it('adds the skew and ticks', () => {
    const { result } = renderHook(() => useServerNow(5_000, true));
    expect(result.current).toBe(Date.parse('2026-10-04T10:00:05Z'));
    act(() => {
      jest.advanceTimersByTime(SERVER_NOW_TICK_MS);
    });
    expect(result.current).toBe(Date.parse('2026-10-04T10:00:20Z'));
  });

  it('refreshes at once when the app becomes active', () => {
    const { result } = renderHook(() => useServerNow(0, true));
    // The phone slept for 40 minutes: the interval never fired.
    jest.setSystemTime(new Date('2026-10-04T10:40:00Z'));
    expect(result.current).toBe(Date.parse('2026-10-04T10:00:00Z'));
    act(() => listeners.forEach((l) => l('active')));
    expect(result.current).toBe(Date.parse('2026-10-04T10:40:00Z'));
  });

  it('no listener / timer while disabled; cleaned up on unmount', () => {
    const { unmount, rerender } = renderHook(({ on }: { on: boolean }) => useServerNow(0, on), {
      initialProps: { on: false },
    });
    expect(listeners).toHaveLength(0);
    rerender({ on: true });
    expect(listeners).toHaveLength(1);
    unmount();
    expect(listeners).toHaveLength(0);
  });
});
