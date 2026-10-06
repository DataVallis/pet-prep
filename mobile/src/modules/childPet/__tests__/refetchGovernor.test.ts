/**
 * Hotfix 2026-10-06 (TestFlight 1.24.4, 429 from throttle:api): the pure limits on
 * how often the app may ask for the child state.
 */
import { ApiError } from '@/api/client';
import { normalizeChildState } from '@/modules/childPet/childPetView';
import {
  BOUNDARY_MAX_GAP_MS,
  BOUNDARY_MIN_GAP_MS,
  DEFAULT_RETRY_AFTER_MS,
  FETCH_BURST,
  FETCH_REFILL_MS,
  FetchAbortedError,
  NO_BOUNDARY_BACKOFF,
  boundaryDelayMs,
  boundaryGapMs,
  createFetchGate,
  errorRetryDelay,
  gatedCall,
  noteBoundaryFire,
  noteViewForBackoff,
  retryAfterMs,
  retryDelayFor,
  viewSignature,
} from '@/modules/childPet/refetchGovernor';
import { makeLiveChildState, makeMedia } from '@/test-utils/fixtures';

const throttled = (seconds: number | null) => new ApiError('Too Many Attempts.', 429, undefined, seconds);

describe('createFetchGate', () => {
  it('lets a burst through, then one call per refill interval (≤ 10 per minute sustained)', () => {
    const gate = createFetchGate();
    let now = 1_000_000;
    let calls = 0;
    // Someone asks every 100 ms for 3 minutes.
    const perMinute: number[] = [0, 0, 0];
    for (let t = 0; t < 180_000; t += 100) {
      now = 1_000_000 + t;
      if (gate.waitMs(now) === 0) {
        gate.take(now);
        calls += 1;
        perMinute[Math.floor(t / 60_000)] += 1;
      }
    }
    expect(perMinute[0]).toBeLessThanOrEqual(FETCH_BURST + 60_000 / FETCH_REFILL_MS);
    expect(perMinute[1]).toBeLessThanOrEqual(10);
    expect(perMinute[2]).toBeLessThanOrEqual(10);
    expect(calls).toBeGreaterThan(20); // still alive, just slower
  });

  it('a burst is immediate; the next call waits for the refill', () => {
    const gate = createFetchGate(3, 6_000);
    const t0 = 5_000;
    for (let i = 0; i < 3; i++) {
      expect(gate.waitMs(t0)).toBe(0);
      gate.take(t0);
    }
    expect(gate.waitMs(t0)).toBe(6_000);
    expect(gate.waitMs(t0 + 6_000)).toBe(0);
  });

  it('a 429 closes the gate for Retry-After, even with tokens left', () => {
    const gate = createFetchGate();
    gate.block(10_000, 20_000);
    expect(gate.waitMs(10_000)).toBe(20_000);
    expect(gate.waitMs(25_000)).toBe(5_000);
    expect(gate.waitMs(30_000)).toBe(0);
    gate.reset();
    gate.block(0, 0);
    expect(gate.waitMs(0)).toBe(0);
  });
});

describe('gatedCall', () => {
  beforeEach(() => jest.useFakeTimers());
  afterEach(() => jest.useRealTimers());

  it('waits for a token instead of calling, and blocks after a 429', async () => {
    const gate = createFetchGate(1, 6_000);
    const call = jest.fn().mockResolvedValue('ok');
    await expect(gatedCall(gate, call)).resolves.toBe('ok');
    const second = gatedCall(gate, call);
    await jest.advanceTimersByTimeAsync(5_999);
    expect(call).toHaveBeenCalledTimes(1);
    await jest.advanceTimersByTimeAsync(1);
    await expect(second).resolves.toBe('ok');
    expect(call).toHaveBeenCalledTimes(2);

    const failing = jest.fn().mockRejectedValue(throttled(20));
    const third = gatedCall(createFetchGate(), failing);
    await expect(third).rejects.toBeInstanceOf(ApiError);
  });

  it('a 429 makes the next call wait for Retry-After', async () => {
    const gate = createFetchGate();
    const call = jest.fn().mockRejectedValueOnce(throttled(20)).mockResolvedValue('ok');
    await expect(gatedCall(gate, call)).rejects.toBeInstanceOf(ApiError);
    const next = gatedCall(gate, call);
    await jest.advanceTimersByTimeAsync(19_000);
    expect(call).toHaveBeenCalledTimes(1);
    await jest.advanceTimersByTimeAsync(1_000);
    await expect(next).resolves.toBe('ok');
  });

  it('an abort while waiting rejects without calling', async () => {
    const gate = createFetchGate(1, 6_000);
    gate.take(Date.now());
    const controller = new AbortController();
    const call = jest.fn().mockResolvedValue('ok');
    const pending = gatedCall(gate, call, { signal: controller.signal });
    controller.abort();
    await expect(pending).rejects.toBeInstanceOf(FetchAbortedError);
    expect(call).not.toHaveBeenCalled();
  });
});

describe('Retry-After and retry delays', () => {
  it('reads Retry-After from a 429 only, clamped', () => {
    expect(retryAfterMs(throttled(17))).toBe(17_000);
    expect(retryAfterMs(throttled(null))).toBe(DEFAULT_RETRY_AFTER_MS);
    expect(retryAfterMs(throttled(0))).toBe(1_000);
    expect(retryAfterMs(throttled(100_000))).toBe(5 * 60_000);
    expect(retryAfterMs(new ApiError('x', 500))).toBeNull();
    expect(retryAfterMs(new TypeError('Network request failed'))).toBeNull();
  });

  it('TanStack retry delay: Retry-After for 429, else exponential', () => {
    expect(retryDelayFor(0, throttled(12))).toBe(12_000);
    expect(retryDelayFor(0, new TypeError('x'))).toBe(2_000);
    expect(retryDelayFor(1, new TypeError('x'))).toBe(4_000);
    expect(retryDelayFor(10, new TypeError('x'))).toBe(30_000);
  });

  it('error-screen retries: 15 s, 30 s, 60 s, 120 s cap; 429 never sooner than Retry-After', () => {
    expect([1, 2, 3, 4, 5].map((n) => errorRetryDelay(n, new TypeError('x')))).toEqual([15_000, 30_000, 60_000, 120_000, 120_000]);
    expect(errorRetryDelay(1, throttled(45))).toBe(45_000);
    expect(errorRetryDelay(1, throttled(2))).toBe(15_000);
  });
});

describe('boundary backoff', () => {
  it('gap doubles per unchanged boundary refetch, 30 s → 5 min', () => {
    expect([0, 1, 2, 3, 4, 10].map(boundaryGapMs)).toEqual([30_000, 60_000, 120_000, 240_000, BOUNDARY_MAX_GAP_MS, BOUNDARY_MAX_GAP_MS]);
  });

  it('a boundary that says "now" again and again fires at most once per gap, backing off while nothing changes', () => {
    let backoff = NO_BOUNDARY_BACKOFF;
    let now = 1_000_000;
    const fires: number[] = [];
    // The worst case: the boundary is always already past (raw delay 0) and the server
    // keeps answering the same state.
    for (let i = 0; i < 8; i++) {
      backoff = noteViewForBackoff(backoff, 'same');
      now += boundaryDelayMs(0, backoff, now);
      fires.push(now);
      backoff = noteBoundaryFire(backoff, 'same', now);
    }
    const gaps = fires.slice(1).map((t, i) => t - fires[i]);
    expect(gaps).toEqual([30_000, 60_000, 120_000, 240_000, 300_000, 300_000, 300_000]);
  });

  it('a changed state ends the streak (back to 30 s); a far boundary keeps its own delay', () => {
    let backoff = noteBoundaryFire(NO_BOUNDARY_BACKOFF, 'a', 1_000_000);
    backoff = noteBoundaryFire(backoff, 'a', 1_030_000);
    expect(backoff.unchangedStreak).toBe(1);
    backoff = noteViewForBackoff(backoff, 'b');
    expect(backoff.unchangedStreak).toBe(0);
    expect(boundaryDelayMs(0, backoff, 1_030_000)).toBe(BOUNDARY_MIN_GAP_MS);
    expect(boundaryDelayMs(3_600_000, backoff, 1_030_000)).toBe(3_600_000);
  });

  it('viewSignature ignores clocks and re-signed media URLs, not what the child sees', () => {
    const a = normalizeChildState(makeLiveChildState({ server_time: '2026-10-06T10:40:00+02:00' }), 0, 0);
    const b = normalizeChildState(
      makeLiveChildState({
        server_time: '2026-10-06T10:40:30+02:00',
        pet: { media: makeMedia({ reference_image_url: 'https://x/api/media/2?v=1&expires=2&signature=new' }) },
      }),
      123,
      999,
    );
    expect(viewSignature(b)).toBe(viewSignature(a));
    const c = normalizeChildState(makeLiveChildState({ pet: { hunger_level: 10 } }), 0, 0);
    expect(viewSignature(c)).not.toBe(viewSignature(a));
  });
});
