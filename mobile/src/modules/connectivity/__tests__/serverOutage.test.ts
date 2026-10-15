/**
 * M5-F13: what counts as "the server is updating", how long to wait, and the banner state.
 */
import { ApiError } from '@/api/client';
import {
  afterOutageFailure,
  afterServerAnswered,
  HEALTHY,
  isNetworkFailure,
  isServerOutageError,
  outageBackoffMs,
  outageCauseForResponse,
  outageCauseOfError,
  outageRetryAfterMs,
  outageRetryDelayMs,
  OUTAGE_RETRY_MAX_MS,
  probeDelayMs,
} from '@/modules/connectivity/serverOutage';
import { act, renderHook } from '@testing-library/react-native';

import {
  isDeployError,
  OUTAGE_EXIT_AFTER_MS,
  outageLasted,
  useLongOutage,
  useServerStatusStore,
} from '@/store/serverStatusStore';

const maintenance = (retryAfter: number | null = 15) => new ApiError('Service Unavailable', 503, { message: 'Service Unavailable' }, retryAfter);

describe('outage classification', () => {
  it('maintenance 503 without a reason, gateway 502 / 504', () => {
    expect(outageCauseForResponse(503, { message: 'Service Unavailable' })).toBe('maintenance');
    expect(outageCauseForResponse(503, null)).toBe('maintenance');
    expect(outageCauseForResponse(502, null)).toBe('gateway');
    expect(outageCauseForResponse(504, null)).toBe('gateway');
  });

  it('a 503 with a machine-readable reason / code is a business answer, not a deploy', () => {
    expect(outageCauseForResponse(503, { message: 'x', reason: 'provider_unavailable' })).toBeNull();
    expect(outageCauseForResponse(503, { message: 'x', code: 'provider_disabled' })).toBeNull();
  });

  it('every other status is a normal answer (the server is up)', () => {
    for (const status of [200, 201, 204, 401, 403, 404, 409, 422, 423, 429, 500]) {
      expect(outageCauseForResponse(status, null)).toBeNull();
    }
  });

  it('network failures: TypeError yes, abort / other errors no', () => {
    expect(isNetworkFailure(new TypeError('Network request failed'))).toBe(true);
    const abort = new Error('Aborted');
    abort.name = 'AbortError';
    expect(isNetworkFailure(abort)).toBe(false);
    expect(isNetworkFailure(new Error('boom'))).toBe(false);
    expect(isNetworkFailure('Network request failed')).toBe(false);
  });

  it('errors thrown by the API client', () => {
    expect(outageCauseOfError(maintenance())).toBe('maintenance');
    expect(outageCauseOfError(new ApiError('x', 502))).toBe('gateway');
    expect(outageCauseOfError(new TypeError('Network request failed'))).toBe('network');
    expect(outageCauseOfError(new ApiError('x', 401))).toBeNull();
    expect(outageCauseOfError(new ApiError('x', 423, { reason: 'ill' }))).toBeNull();
    expect(isServerOutageError(new ApiError('x', 422))).toBe(false);
    expect(isServerOutageError(new ApiError('x', 503, { reason: 'provider_unavailable' }))).toBe(false);
  });

  it('isDeployError: network failures only while the banner already shows', () => {
    expect(isDeployError(maintenance())).toBe(true);
    expect(isDeployError(new TypeError('Network request failed'))).toBe(false);
    useServerStatusStore.getState().noteOutageFailure('maintenance', 15_000);
    expect(isDeployError(new TypeError('Network request failed'))).toBe(true);
    expect(isDeployError(new ApiError('x', 401))).toBe(false);
  });
});

describe('retry backoff', () => {
  it('honours Retry-After (clamped to 1 s … 30 s)', () => {
    expect(outageRetryAfterMs(maintenance(15))).toBe(15_000);
    expect(outageRetryAfterMs(maintenance(0))).toBe(1_000);
    expect(outageRetryAfterMs(maintenance(3_600))).toBe(OUTAGE_RETRY_MAX_MS);
    expect(outageRetryAfterMs(maintenance(null))).toBeNull();
    expect(outageRetryAfterMs(new TypeError('x'))).toBeNull();
  });

  it('exponential 2 s, 4 s, 8 s, 16 s, then capped at 30 s', () => {
    expect([0, 1, 2, 3, 4, 5, 10].map(outageBackoffMs)).toEqual([2_000, 4_000, 8_000, 16_000, 30_000, 30_000, 30_000]);
  });

  it('Retry-After wins over the backoff; without it the backoff', () => {
    expect(outageRetryDelayMs(4, maintenance(15))).toBe(15_000);
    expect(outageRetryDelayMs(1, new ApiError('x', 502))).toBe(4_000);
  });

  it('probe delays: Retry-After (≥ 3 s), else 3 s … 30 s', () => {
    expect(probeDelayMs(0, 15_000)).toBe(15_000);
    expect(probeDelayMs(0, 1_000)).toBe(3_000);
    expect([0, 1, 2, 3, 4].map((n) => probeDelayMs(n, null))).toEqual([3_000, 6_000, 12_000, 24_000, 30_000]);
  });
});

describe('banner state (pure)', () => {
  it('maintenance / gateway show the banner at once', () => {
    expect(afterOutageFailure(HEALTHY, 'maintenance', 15_000, 1_000)).toEqual({
      outage: true,
      cause: 'maintenance',
      failures: 1,
      since: 1_000,
      retryAfterMs: 15_000,
    });
    expect(afterOutageFailure(HEALTHY, 'gateway', null, 1_000).outage).toBe(true);
  });

  it('a network failure alone never starts it (phone offline ≠ PetPrep updating), but keeps it up', () => {
    let state = afterOutageFailure(HEALTHY, 'network', null, 1_000);
    state = afterOutageFailure(state, 'network', null, 2_000);
    state = afterOutageFailure(state, 'network', null, 3_000);
    expect(state.outage).toBe(false);
    expect(state.since).toBeNull();

    const shown = afterOutageFailure(HEALTHY, 'maintenance', 15_000, 1_000);
    const kept = afterOutageFailure(shown, 'network', null, 9_000);
    expect(kept.outage).toBe(true);
    expect(kept.since).toBe(1_000);
    expect(kept.failures).toBe(2);
  });

  it('any normal answer clears it (same object when already healthy)', () => {
    const shown = afterOutageFailure(HEALTHY, 'maintenance', 15_000, 1_000);
    expect(afterServerAnswered(shown)).toEqual(HEALTHY);
    expect(afterServerAnswered(HEALTHY)).toBe(HEALTHY);
  });
});

describe('long outage (QA m4)', () => {
  it('outageLasted: only after OUTAGE_EXIT_AFTER_MS of an ongoing outage', () => {
    expect(outageLasted(null, 1_000_000)).toBe(false);
    expect(outageLasted(0, OUTAGE_EXIT_AFTER_MS - 1)).toBe(false);
    expect(outageLasted(0, OUTAGE_EXIT_AFTER_MS)).toBe(true);
  });

  it('useLongOutage flips after the threshold and resets when the server is back', async () => {
    jest.useFakeTimers();
    try {
      const { result } = renderHook(() => useLongOutage());
      expect(result.current).toBe(false);
      act(() => useServerStatusStore.getState().noteOutageFailure('maintenance', 15_000, Date.now()));
      await act(async () => {
        await jest.advanceTimersByTimeAsync(OUTAGE_EXIT_AFTER_MS - 1);
      });
      expect(result.current).toBe(false);
      await act(async () => {
        await jest.advanceTimersByTimeAsync(1);
      });
      expect(result.current).toBe(true);
      act(() => useServerStatusStore.getState().noteServerAnswered());
      expect(result.current).toBe(false);
    } finally {
      jest.useRealTimers();
    }
  });
});

describe('D19 (PR #251): the few-seconds Caddy 502 during the app container swap', () => {
  it('a 502 starts the banner; connection refused during it keeps it; the next answer ends it', () => {
    const store = useServerStatusStore.getState();
    store.noteOutageFailure(outageCauseForResponse(502, null) ?? 'network', null);
    expect(useServerStatusStore.getState().outage).toBe(true);
    // Connection refused while the container is gone.
    store.noteOutageFailure(outageCauseOfError(new TypeError('Network request failed')) ?? 'gateway', null);
    expect(useServerStatusStore.getState()).toMatchObject({ outage: true, cause: 'network', failures: 2 });
    store.noteServerAnswered();
    expect(useServerStatusStore.getState().outage).toBe(false);
  });

  it('connection refused alone (no 502 / 503 first) never starts the banner', () => {
    for (let i = 0; i < 5; i += 1) useServerStatusStore.getState().noteOutageFailure('network', null);
    expect(useServerStatusStore.getState().outage).toBe(false);
  });
});

describe('serverStatusStore', () => {
  it('shows on a maintenance failure and hides on the next answer', () => {
    const store = useServerStatusStore.getState();
    store.noteOutageFailure('maintenance', 15_000, 5_000);
    expect(useServerStatusStore.getState()).toMatchObject({ outage: true, cause: 'maintenance', since: 5_000, retryAfterMs: 15_000 });
    useServerStatusStore.getState().noteServerAnswered();
    expect(useServerStatusStore.getState()).toMatchObject(HEALTHY);
  });

  it('does not notify subscribers for answers while healthy (no re-render storm)', () => {
    const listener = jest.fn();
    const unsubscribe = useServerStatusStore.subscribe(listener);
    useServerStatusStore.getState().noteServerAnswered();
    useServerStatusStore.getState().noteServerAnswered();
    expect(listener).not.toHaveBeenCalled();
    unsubscribe();
  });
});
