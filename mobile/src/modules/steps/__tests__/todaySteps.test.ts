/**
 * M3-06: one walk is seen by several sources (motion sensor, Apple Health, Health
 * Connect) — the day total is the MAXIMUM of them, never the sum.
 */
import { autoSyncDue, MIN_AUTO_SYNC_GAP_MS, mergeReadings, readTodaySteps } from '@/modules/steps/todaySteps';
import { makeFakeHealth } from '@/test-utils/fakeHealth';

const START = new Date('2026-10-03T22:00:00Z');
const AT = new Date('2026-10-04T10:00:00Z');

describe('mergeReadings (max, not sum)', () => {
  it('takes the larger reading of the same day, never the sum', () => {
    expect(
      mergeReadings([
        { steps: 3000, source: 'pedometer' },
        { steps: 4200, source: 'healthkit' },
      ]),
    ).toEqual({ steps: 4200, source: 'healthkit' });
    expect(
      mergeReadings([
        { steps: 5100, source: 'pedometer' },
        { steps: 4200, source: 'health_connect' },
      ]),
    ).toEqual({ steps: 5100, source: 'pedometer' });
  });

  it('prefers the health store on a tie (it covers phone + watch)', () => {
    expect(
      mergeReadings([
        { steps: 3000, source: 'pedometer' },
        { steps: 3000, source: 'healthkit' },
      ]),
    ).toEqual({ steps: 3000, source: 'healthkit' });
  });

  it('ignores broken readings and floors fractions; nothing readable → null', () => {
    expect(
      mergeReadings([
        { steps: Number.NaN, source: 'healthkit' },
        { steps: -5, source: 'pedometer' },
        { steps: 99.9, source: 'pedometer' },
      ]),
    ).toEqual({ steps: 99, source: 'pedometer' });
    expect(mergeReadings([])).toBeNull();
  });
});

describe('readTodaySteps', () => {
  it('reads health and the sensor for [family midnight, now] and keeps the max', async () => {
    const health = makeFakeHealth({ steps: 6100 });
    const pedometerHistory = jest.fn(async () => 5900);
    const reading = await readTodaySteps({ health, pedometerHistory, liveTotal: null }, START, AT);
    expect(reading).toEqual({ steps: 6100, source: 'healthkit' });
    expect(health.readSteps).toHaveBeenCalledWith(START, AT);
    expect(pedometerHistory).toHaveBeenCalledWith(START, AT);
  });

  it('a denied iOS Health read (no samples → 0) falls back to the sensor', async () => {
    const reading = await readTodaySteps(
      { health: makeFakeHealth({ steps: 0 }), pedometerHistory: async () => 2400, liveTotal: null },
      START,
      AT,
    );
    expect(reading).toEqual({ steps: 2400, source: 'pedometer' });
  });

  it('a throwing source is skipped (locked phone / revoked access)', async () => {
    const health = makeFakeHealth({ source: 'health_connect' });
    health.readSteps.mockRejectedValueOnce(new Error('SecurityException'));
    const reading = await readTodaySteps({ health, pedometerHistory: null, liveTotal: () => 800 }, START, AT);
    expect(reading).toEqual({ steps: 800, source: 'pedometer' });
  });

  it('no source at all → null (nothing is sent)', async () => {
    expect(await readTodaySteps({ health: null, pedometerHistory: null, liveTotal: null }, START, AT)).toBeNull();
  });
});

describe('autoSyncDue (throttle of automatic triggers)', () => {
  it('runs the first time, then at most once per minute', () => {
    expect(autoSyncDue(null, 1_000)).toBe(true);
    expect(autoSyncDue(1_000, 1_000 + MIN_AUTO_SYNC_GAP_MS - 1)).toBe(false);
    expect(autoSyncDue(1_000, 1_000 + MIN_AUTO_SYNC_GAP_MS)).toBe(true);
  });

  it('a clock that jumped backwards never blocks syncing', () => {
    expect(autoSyncDue(10_000, 5_000)).toBe(true);
  });
});
