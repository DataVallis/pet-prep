/**
 * M2-11 (David 2026-10-14): on a shared phone a child is credited only the steps taken while
 * their profile was ACTIVE — windows open on »Igraj kot«, close when another profile becomes
 * active; background time inside a window counts; across midnight only today's part; the
 * child's own phone keeps counting from midnight.
 */
import * as SecureStore from 'expo-secure-store';

import type { ChildPetState } from '@/api/client';
import {
  STEP_WINDOWS_KEY,
  closeStepWindows,
  loadStepWindows,
  mergeWindows,
  openStepWindow,
  pruneStepWindows,
  resetStepWindowsForTests,
  windowsInRange,
} from '@/modules/steps/activeWindows';
import { runBackgroundStepSync, type BackgroundSyncDeps } from '@/modules/steps/backgroundSteps';
import { readStepsInRanges } from '@/modules/steps/todaySteps';
import { makeFakeHealth } from '@/test-utils/fakeHealth';
import { makeLiveChildState } from '@/test-utils/fixtures';

let vault: Map<string, string>;
let failWrites = false;

beforeEach(() => {
  jest.clearAllMocks();
  vault = new Map();
  failWrites = false;
  resetStepWindowsForTests();
  jest.mocked(SecureStore.getItemAsync).mockImplementation(async (key: string) => vault.get(key) ?? null);
  jest.mocked(SecureStore.setItemAsync).mockImplementation(async (key: string, value: string) => {
    if (failWrites) throw new Error('keychain');
    vault.set(key, value);
  });
  jest.mocked(SecureStore.deleteItemAsync).mockImplementation(async (key: string) => {
    vault.delete(key);
  });
});

const t = (iso: string) => Date.parse(iso);

describe('step windows', () => {
  it('two children: opening one closes the other; closing ends the open window', async () => {
    await openStepWindow(2, t('2026-10-14T08:00:00Z'));
    await openStepWindow(5, t('2026-10-14T09:00:00Z'));
    await openStepWindow(2, t('2026-10-14T10:00:00Z'));
    await closeStepWindows(t('2026-10-14T11:30:00Z'));

    expect(await loadStepWindows()).toEqual({
      '2': [
        { start: t('2026-10-14T08:00:00Z'), end: t('2026-10-14T09:00:00Z') },
        { start: t('2026-10-14T10:00:00Z'), end: t('2026-10-14T11:30:00Z') },
      ],
      '5': [{ start: t('2026-10-14T09:00:00Z'), end: t('2026-10-14T10:00:00Z') }],
    });
  });

  it('clips to today: a window over midnight counts only after midnight; an open window ends now', () => {
    const dayStart = new Date('2026-10-14T00:00:00Z');
    const at = new Date('2026-10-14T12:00:00Z');
    const ranges = windowsInRange(
      [
        { start: t('2026-10-13T20:00:00Z'), end: t('2026-10-14T01:00:00Z') }, // across midnight
        { start: t('2026-10-13T10:00:00Z'), end: t('2026-10-13T11:00:00Z') }, // yesterday only
        { start: t('2026-10-14T11:00:00Z'), end: null }, // still active
      ],
      dayStart,
      at,
    );
    expect(ranges.map(([s, e]) => [s.toISOString(), e.toISOString()])).toEqual([
      ['2026-10-14T00:00:00.000Z', '2026-10-14T01:00:00.000Z'],
      ['2026-10-14T11:00:00.000Z', '2026-10-14T12:00:00.000Z'],
    ]);
  });

  it('sums each source over the windows, then the usual max merge', async () => {
    const health = makeFakeHealth({ steps: 0 });
    health.readSteps.mockImplementation(async (start: Date) => (start.getUTCHours() === 0 ? 1000 : 1500));
    const pedometer = jest.fn(async (start: Date) => (start.getUTCHours() === 0 ? 900 : 1400));
    const ranges: Array<[Date, Date]> = [
      [new Date('2026-10-14T00:00:00Z'), new Date('2026-10-14T01:00:00Z')],
      [new Date('2026-10-14T11:00:00Z'), new Date('2026-10-14T12:00:00Z')],
    ];

    await expect(readStepsInRanges({ health, pedometerHistory: pedometer, liveTotal: null }, ranges)).resolves.toEqual({ steps: 2500, source: 'healthkit' });
    expect(pedometer).toHaveBeenCalledTimes(2);
    // No window today → nothing to credit.
    await expect(readStepsInRanges({ health, pedometerHistory: pedometer, liveTotal: null }, [])).resolves.toEqual({ steps: 0, source: 'healthkit' });
  });
});

describe('QA n2: fail closed, merge, prune', () => {
  it('a window that cannot be closed (writes fail twice) is never credited again; the child starts afresh later', async () => {
    await openStepWindow(2, t('2026-10-14T08:00:00Z'));
    failWrites = true;
    await closeStepWindows(t('2026-10-14T09:00:00Z'));

    expect(SecureStore.setItemAsync).toHaveBeenCalledTimes(3); // open + 2 close attempts
    expect(await loadStepWindows()).toEqual({}); // ignored (and deleted)
    expect(vault.has(STEP_WINDOWS_KEY)).toBe(false);

    failWrites = false;
    await openStepWindow(2, t('2026-10-14T10:00:00Z'));
    expect(await loadStepWindows()).toEqual({ '2': [{ start: t('2026-10-14T10:00:00Z'), end: null }] });
  });

  it('a failed open means no window — nothing credited', async () => {
    failWrites = true;
    await openStepWindow(2, t('2026-10-14T08:00:00Z'));
    expect(await loadStepWindows()).toEqual({});
  });

  it('merges overlapping / touching windows; an open window swallows later ones', () => {
    expect(
      mergeWindows([
        { start: 30, end: 40 },
        { start: 10, end: 20 },
        { start: 20, end: 25 },
        { start: 24, end: 28 },
        { start: 50, end: null },
        { start: 60, end: 70 },
      ]),
    ).toEqual([
      { start: 10, end: 28 },
      { start: 30, end: 40 },
      { start: 50, end: null },
    ]);
  });

  it('prunes to the current and the previous family day', async () => {
    vault.set(
      STEP_WINDOWS_KEY,
      JSON.stringify({
        '2': [
          { start: t('2026-10-12T08:00:00Z'), end: t('2026-10-12T09:00:00Z') }, // two days ago
          { start: t('2026-10-13T08:00:00Z'), end: t('2026-10-13T09:00:00Z') }, // yesterday
          { start: t('2026-10-14T08:00:00Z'), end: null },
        ],
      }),
    );
    await pruneStepWindows(t('2026-10-13T00:00:00Z'));
    expect((await loadStepWindows())['2'].map((w) => w.start)).toEqual([t('2026-10-13T08:00:00Z'), t('2026-10-14T08:00:00Z')]);
  });
});

describe('background sync on a shared phone', () => {
  const NOW = new Date('2026-10-14T10:00:00Z'); // 12:00 in Ljubljana
  const state = (): ChildPetState =>
    makeLiveChildState({ server_time: '2026-10-14T11:55:00+02:00', timezone: 'Europe/Ljubljana', steps: { my_steps_today: 0 } });

  function deps(sharedRanges: BackgroundSyncDeps['sharedRanges']) {
    const getStepCountAsync = jest.fn(async (start: Date) => ({ steps: start.getUTCHours() === 7 ? 800 : 5000 }));
    return {
      platform: 'ios' as const,
      health: makeFakeHealth({ steps: 0 }),
      pedometer: {
        getPermissionsAsync: jest.fn(async () => ({ status: 'granted', granted: true, canAskAgain: true, expires: 'never' })),
        getStepCountAsync,
      } as unknown as BackgroundSyncDeps['pedometer'],
      getToken: jest.fn(async () => 'child-2-token'),
      getChildPet: jest.fn(async () => state()),
      syncSteps: jest.fn(async () => ({})),
      now: () => NOW,
      sharedRanges,
    };
  }

  it('a shared child session sends only the steps of its active windows', async () => {
    const d = deps(async () => [[new Date('2026-10-14T07:00:00Z'), new Date('2026-10-14T08:00:00Z')]]);
    expect(await runBackgroundStepSync(d)).toBe('synced');
    expect(d.syncSteps).toHaveBeenCalledWith(expect.objectContaining({ steps_today: 800 }));
  });

  it("the child's own phone (no stored shared session) still counts from the family midnight", async () => {
    const d = deps(async () => null);
    expect(await runBackgroundStepSync(d)).toBe('synced');
    expect(d.syncSteps).toHaveBeenCalledWith(expect.objectContaining({ steps_today: 5000 }));
    const [start] = (d.pedometer.getStepCountAsync as jest.Mock).mock.calls[0] as [Date];
    expect(start.toISOString()).toBe('2026-10-13T22:00:00.000Z');
  });

  it('the default lookup finds the shared child by its stored token', async () => {
    vault.set('petprep_child_sessions', JSON.stringify({ '2': { token: 'child-2-token', parentUserId: 1 } }));
    vault.set('petprep_step_windows', JSON.stringify({ '2': [{ start: Date.parse('2026-10-14T07:00:00Z'), end: Date.parse('2026-10-14T08:00:00Z') }] }));
    const d = deps(undefined as unknown as BackgroundSyncDeps['sharedRanges']);
    delete (d as Partial<typeof d>).sharedRanges;
    expect(await runBackgroundStepSync(d)).toBe('synced');
    expect(d.syncSteps).toHaveBeenCalledWith(expect.objectContaining({ steps_today: 800 }));
  });
});
