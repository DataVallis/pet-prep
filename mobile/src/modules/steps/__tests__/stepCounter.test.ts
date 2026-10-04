import {
  createDeltaTracker,
  formatSteps,
  isoWithOffset,
  LiveStepCounter,
  LIVE_STEPS_KEY,
  localDateKey,
  type KeyValueStore,
} from '@/modules/steps/stepCounter';

/** The server's `recorded_at` rule (SyncStepsRequest): explicit Z or ±HH:MM offset. */
const SERVER_ISO = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/;

function memoryStore(initial: Record<string, string> = {}): KeyValueStore & { data: Record<string, string> } {
  const data = { ...initial };
  return {
    data,
    getItemAsync: jest.fn(async (key: string) => data[key] ?? null),
    setItemAsync: jest.fn(async (key: string, value: string) => {
      data[key] = value;
    }),
  };
}

describe('isoWithOffset', () => {
  const instant = new Date('2026-10-04T13:30:15.500Z');

  it('Ljubljana summer time (+120 min)', () => {
    expect(isoWithOffset(instant, 120)).toBe('2026-10-04T15:30:15+02:00');
  });

  it('New York (−240 min) — a different local day than UTC late in the evening', () => {
    expect(isoWithOffset(new Date('2026-10-05T02:10:00Z'), -240)).toBe('2026-10-04T22:10:00-04:00');
  });

  it('half-hour zones and UTC', () => {
    expect(isoWithOffset(instant, 330)).toBe('2026-10-04T19:00:15+05:30');
    expect(isoWithOffset(instant, 0)).toBe('2026-10-04T13:30:15+00:00');
  });

  it('always matches the server format and the same instant (device offset)', () => {
    const iso = isoWithOffset(instant);
    expect(iso).toMatch(SERVER_ISO);
    expect(Date.parse(iso)).toBe(Date.parse('2026-10-04T13:30:15Z'));
  });
});

describe('LiveStepCounter (Android, no history)', () => {
  const morning = new Date(2026, 9, 4, 9, 0);
  const evening = new Date(2026, 9, 4, 20, 0);
  const nextDay = new Date(2026, 9, 5, 0, 5);

  it('continues today’s saved total and adds live deltas', async () => {
    const store = memoryStore({ [LIVE_STEPS_KEY]: JSON.stringify({ date: localDateKey(morning), steps: 1500 }) });
    const counter = new LiveStepCounter(store, morning);
    expect(await counter.load(morning)).toBe(1500);
    counter.add(120, morning);
    counter.add(-5, morning);
    expect(counter.value(evening)).toBe(1620);

    await counter.persist();
    expect(JSON.parse(store.data[LIVE_STEPS_KEY])).toEqual({ date: '2026-10-04', steps: 1620 });
  });

  it('ignores a total saved on another day and restarts at local midnight', async () => {
    const store = memoryStore({ [LIVE_STEPS_KEY]: JSON.stringify({ date: '2026-10-03', steps: 9000 }) });
    const counter = new LiveStepCounter(store, morning);
    expect(await counter.load(morning)).toBe(0);
    counter.add(300, evening);
    expect(counter.value(nextDay)).toBe(0);
    counter.add(40, nextDay);
    expect(counter.value(nextDay)).toBe(40);
  });

  it('never reports less than the server has for this child (storage wiped)', async () => {
    const counter = new LiveStepCounter(memoryStore(), morning);
    await counter.load(morning);
    expect(counter.raiseTo(2200, morning)).toBe(2200);
    counter.add(10, morning);
    expect(counter.raiseTo(1000, morning)).toBe(2210);
  });

  it('a broken or unreadable store starts at 0', async () => {
    const broken = memoryStore({ [LIVE_STEPS_KEY]: '{not json' });
    expect(await new LiveStepCounter(broken, morning).load(morning)).toBe(0);
    const throwing: KeyValueStore = {
      getItemAsync: jest.fn(() => Promise.reject(new Error('keychain'))),
      setItemAsync: jest.fn(() => Promise.reject(new Error('keychain'))),
    };
    const counter = new LiveStepCounter(throwing, morning);
    expect(await counter.load(morning)).toBe(0);
    await expect(counter.persist()).resolves.toBeUndefined();
  });
});

describe('createDeltaTracker', () => {
  it('turns cumulative-since-subscribe readings into deltas; a restart counts from 0', () => {
    const delta = createDeltaTracker();
    expect(delta(10)).toBe(10);
    expect(delta(25)).toBe(15);
    expect(delta(25)).toBe(0);
    expect(delta(4)).toBe(4); // subscription restarted
    expect(delta(Number.NaN)).toBe(0);
  });
});

describe('formatSteps', () => {
  it('uses a dot as thousands separator', () => {
    expect(formatSteps(4000)).toBe('4.000');
    expect(formatSteps(1250)).toBe('1.250');
    expect(formatSteps(10000)).toBe('10.000');
    expect(formatSteps(999)).toBe('999');
  });
});
