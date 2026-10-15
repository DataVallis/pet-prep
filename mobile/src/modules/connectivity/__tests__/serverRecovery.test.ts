/**
 * M5-F13: while the banner shows, the server is probed (bounded); when it is back,
 * every active query refetches exactly once.
 */
import { QueryClient, QueryObserver } from '@tanstack/react-query';

import { refetchAfterOutage, startServerRecovery } from '@/modules/connectivity/serverRecovery';
import { useServerStatusStore } from '@/store/serverStatusStore';

const store = () => useServerStatusStore.getState();

describe('startServerRecovery', () => {
  beforeEach(() => jest.useFakeTimers());
  afterEach(() => jest.useRealTimers());

  it('probes after Retry-After while down, then refetches once when a probe succeeds', async () => {
    let up = false;
    const probe = jest.fn(async () => {
      if (up) store().noteServerAnswered();
      else store().noteOutageFailure('maintenance', 15_000);
      return up;
    });
    const onRecovered = jest.fn();
    const stop = startServerRecovery({ probe, onRecovered });

    store().noteOutageFailure('maintenance', 15_000);
    await jest.advanceTimersByTimeAsync(14_999);
    expect(probe).not.toHaveBeenCalled();
    await jest.advanceTimersByTimeAsync(1);
    expect(probe).toHaveBeenCalledTimes(1);
    expect(onRecovered).not.toHaveBeenCalled();

    up = true;
    await jest.advanceTimersByTimeAsync(15_000);
    expect(probe).toHaveBeenCalledTimes(2);
    expect(onRecovered).toHaveBeenCalledTimes(1);
    expect(store().outage).toBe(false);

    // Healthy → no more probes.
    await jest.advanceTimersByTimeAsync(120_000);
    expect(probe).toHaveBeenCalledTimes(2);
    stop();
  });

  it('any other successful request ends the outage too (no probe needed)', async () => {
    const probe = jest.fn(async () => false);
    const onRecovered = jest.fn();
    const stop = startServerRecovery({ probe, onRecovered });

    store().noteOutageFailure('maintenance', 15_000);
    store().noteServerAnswered();
    expect(onRecovered).toHaveBeenCalledTimes(1);
    await jest.advanceTimersByTimeAsync(60_000);
    expect(probe).not.toHaveBeenCalled();
    stop();
  });

  it('without Retry-After the probe backs off 3 s, 6 s, 12 s … ≤ 30 s', async () => {
    const times: number[] = [];
    const start = Date.now();
    const probe = jest.fn(async () => {
      times.push(Date.now() - start);
      store().noteOutageFailure('gateway', null);
      return false;
    });
    const stop = startServerRecovery({ probe, onRecovered: jest.fn() });
    store().noteOutageFailure('gateway', null);
    await jest.advanceTimersByTimeAsync(3_000 + 6_000 + 12_000 + 24_000 + 30_000);
    expect(times).toEqual([3_000, 9_000, 21_000, 45_000, 75_000]);
    stop();
  });

  it('no probes in the background; resumes in the foreground', async () => {
    let foreground = false;
    const probe = jest.fn(async () => false);
    const stop = startServerRecovery({ probe, onRecovered: jest.fn(), isForeground: () => foreground });
    store().noteOutageFailure('maintenance', 15_000);
    await jest.advanceTimersByTimeAsync(60_000);
    expect(probe).not.toHaveBeenCalled();
    foreground = true;
    await jest.advanceTimersByTimeAsync(15_000);
    expect(probe).toHaveBeenCalledTimes(1);
    stop();
  });

  it('stop() cancels the pending probe and ignores later changes', async () => {
    const probe = jest.fn(async () => false);
    const onRecovered = jest.fn();
    const stop = startServerRecovery({ probe, onRecovered });
    store().noteOutageFailure('maintenance', 15_000);
    stop();
    await jest.advanceTimersByTimeAsync(60_000);
    store().noteServerAnswered();
    expect(probe).not.toHaveBeenCalled();
    expect(onRecovered).not.toHaveBeenCalled();
  });

  it('QA n1: back in the foreground during an outage → probes at once', async () => {
    let foregroundListener: (() => void) | null = null;
    const remove = jest.fn();
    const probe = jest.fn(async () => false);
    const stop = startServerRecovery({
      probe,
      onRecovered: jest.fn(),
      onForeground: (listener) => {
        foregroundListener = listener;
        return remove;
      },
    });
    store().noteOutageFailure('maintenance', 15_000);
    await jest.advanceTimersByTimeAsync(1_000);
    expect(probe).not.toHaveBeenCalled();
    await (async () => (foregroundListener as (() => void) | null)?.())();
    await jest.advanceTimersByTimeAsync(0);
    expect(probe).toHaveBeenCalledTimes(1);
    stop();
    expect(remove).toHaveBeenCalled();
  });

  it('QA n1: a foreground event while healthy does nothing', async () => {
    let foregroundListener: (() => void) | null = null;
    const probe = jest.fn(async () => true);
    const stop = startServerRecovery({
      probe,
      onRecovered: jest.fn(),
      onForeground: (listener) => {
        foregroundListener = listener;
        return () => undefined;
      },
    });
    (foregroundListener as (() => void) | null)?.();
    await jest.advanceTimersByTimeAsync(0);
    expect(probe).not.toHaveBeenCalled();
    stop();
  });

  it('starts probing at once when mounted during an outage', async () => {
    store().noteOutageFailure('maintenance', 15_000);
    const probe = jest.fn(async () => false);
    const stop = startServerRecovery({ probe, onRecovered: jest.fn() });
    await jest.advanceTimersByTimeAsync(15_000);
    expect(probe).toHaveBeenCalledTimes(1);
    stop();
  });
});

describe('refetchAfterOutage', () => {
  it('refetches active queries only', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { gcTime: Infinity, retry: false } } });
    const active = jest.fn(async () => 'a');
    const inactive = jest.fn(async () => 'b');
    await client.fetchQuery({ queryKey: ['child', 'pet'], queryFn: active });
    await client.fetchQuery({ queryKey: ['parent', 'billing'], queryFn: inactive });
    // ['child','pet'] is on screen (one observer), ['parent','billing'] is not.
    const observer = new QueryObserver(client, { queryKey: ['child', 'pet'], queryFn: active, staleTime: Infinity });
    const unsubscribe = observer.subscribe(() => undefined);
    active.mockClear();
    inactive.mockClear();

    refetchAfterOutage(client);
    await Promise.resolve();
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(active).toHaveBeenCalledTimes(1);
    expect(inactive).not.toHaveBeenCalled();
    expect(client.getQueryState(['parent', 'billing'])?.isInvalidated).toBe(true);
    unsubscribe();
    client.clear();
  });
});
