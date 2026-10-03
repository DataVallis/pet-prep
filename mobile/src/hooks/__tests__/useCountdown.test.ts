import { act, renderHook } from '@testing-library/react-native';

import { useCountdown } from '@/hooks/useCountdown';

describe('useCountdown', () => {
  beforeEach(() => {
    jest.useFakeTimers();
    jest.setSystemTime(new Date('2026-10-03T10:00:00Z'));
  });

  afterEach(() => {
    jest.useRealTimers();
  });

  it('counts down once per second from the wall clock', () => {
    const { result } = renderHook(() => useCountdown('2026-10-03T10:15:00Z'));
    expect(result.current).toBe(900);

    act(() => {
      jest.advanceTimersByTime(1000);
    });
    expect(result.current).toBe(899);

    act(() => {
      jest.advanceTimersByTime(60_000);
    });
    expect(result.current).toBe(839);
  });

  it('stops at 0 when the PIN expires', () => {
    const { result } = renderHook(() => useCountdown('2026-10-03T10:00:03Z'));
    act(() => {
      jest.advanceTimersByTime(10_000);
    });
    expect(result.current).toBe(0);
    expect(jest.getTimerCount()).toBe(0);
  });

  it('restarts from the new deadline when a new PIN arrives', () => {
    const { result, rerender } = renderHook(({ deadline }: { deadline: string | null }) => useCountdown(deadline), {
      initialProps: { deadline: '2026-10-03T10:00:30Z' as string | null },
    });
    act(() => {
      jest.advanceTimersByTime(20_000);
    });
    expect(result.current).toBe(10);

    rerender({ deadline: '2026-10-03T10:15:20Z' });
    expect(result.current).toBe(900);
  });

  it('returns 0 without a deadline', () => {
    const { result } = renderHook(() => useCountdown(null));
    expect(result.current).toBe(0);
  });
});
