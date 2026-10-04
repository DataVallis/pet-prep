import {
  createDeltaTracker,
  formatSteps,
  isoWithOffset,
  clearLiveSteps,
  LiveStepCounter,
  liveStepsKey,
  type KeyValueStore,
} from '@/modules/steps/stepCounter';
import { familyCalendar } from '@/modules/childPet/familyTime';

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
  const KEY = liveStepsKey(2);
  // Family day key (Europe/Ljubljana) — independent of the device zone.
  const lj = familyCalendar('Europe/Ljubljana');
  const familyDay = (d: Date) => lj.dateOf(d.getTime());
  const morning = new Date('2026-10-04T07:00:00Z'); // 09:00 in Ljubljana
  const evening = new Date('2026-10-04T18:00:00Z'); // 20:00
  const nextDay = new Date('2026-10-04T22:05:00Z'); // 00:05 on 5 Oct in Ljubljana, still 4 Oct in UTC

  it('per-user storage key', () => {
    expect(liveStepsKey(2)).toBe('petprep_live_steps_today_2');
    expect(liveStepsKey(3)).not.toBe(liveStepsKey(2));
  });

  it('continues today’s saved total and adds live deltas', async () => {
    const store = memoryStore({ [KEY]: JSON.stringify({ date: '2026-10-04', steps: 1500 }) });
    const counter = new LiveStepCounter(store, KEY, familyDay, morning);
    expect(await counter.load(morning)).toBe(1500);
    counter.add(120, morning);
    counter.add(-5, morning);
    expect(counter.value(evening)).toBe(1620);

    await counter.persist();
    expect(JSON.parse(store.data[KEY])).toEqual({ date: '2026-10-04', steps: 1620 });
  });

  it('ignores a total saved on another day and restarts at the FAMILY midnight (device on UTC)', async () => {
    const store = memoryStore({ [KEY]: JSON.stringify({ date: '2026-10-03', steps: 9000 }) });
    const counter = new LiveStepCounter(store, KEY, familyDay, morning);
    expect(await counter.load(morning)).toBe(0);
    counter.add(300, evening);
    // 22:05Z is still 4 Oct for a UTC device, but 5 Oct for the family → new day.
    expect(counter.value(nextDay)).toBe(0);
    expect(counter.currentDay(nextDay)).toBe('2026-10-05');
    counter.add(40, nextDay);
    expect(counter.value(nextDay)).toBe(40);
  });

  it('raises to the server count only for the same family day', async () => {
    const counter = new LiveStepCounter(memoryStore(), KEY, familyDay, morning);
    await counter.load(morning);
    expect(counter.raiseTo(2200, '2026-10-04', morning)).toBe(2200);
    counter.add(10, morning);
    expect(counter.raiseTo(1000, '2026-10-04', morning)).toBe(2210);
    // After the family midnight the cached server value is yesterday's → ignored (B1).
    expect(counter.raiseTo(5000, '2026-10-04', nextDay)).toBe(0);
  });

  it('a broken or unreadable store starts at 0', async () => {
    const broken = memoryStore({ [KEY]: '{not json' });
    expect(await new LiveStepCounter(broken, KEY, familyDay, morning).load(morning)).toBe(0);
    const throwing: KeyValueStore = {
      getItemAsync: jest.fn(() => Promise.reject(new Error('keychain'))),
      setItemAsync: jest.fn(() => Promise.reject(new Error('keychain'))),
    };
    const counter = new LiveStepCounter(throwing, KEY, familyDay, morning);
    expect(await counter.load(morning)).toBe(0);
    await expect(counter.persist()).resolves.toBeUndefined();
  });

  it('clearLiveSteps deletes only that child’s total; null user is a no-op', async () => {
    const store = { deleteItemAsync: jest.fn(async (_key: string) => undefined) };
    await clearLiveSteps(2, store);
    expect(store.deleteItemAsync).toHaveBeenCalledWith('petprep_live_steps_today_2');
    await clearLiveSteps(null, store);
    expect(store.deleteItemAsync).toHaveBeenCalledTimes(1);
    await expect(
      clearLiveSteps(2, { deleteItemAsync: jest.fn(() => Promise.reject(new Error('x'))) }),
    ).resolves.toBeUndefined();
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
