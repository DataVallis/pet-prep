/**
 * »Preveri posodobitve« (David 2026-10-29): check → download → offer a restart; never reloads by
 * itself, bounded in time, off where expo-updates can't work.
 */
import {
  canCheckForUpdates,
  checkAndFetchUpdate,
  restartIntoUpdate,
  setUpdatesApiForTests,
  type UpdatesApi,
} from '../updateCheck';

function fakeUpdates(overrides: Partial<UpdatesApi> = {}): jest.Mocked<UpdatesApi> {
  return {
    isEnabled: true,
    checkForUpdateAsync: jest.fn(async () => ({ isAvailable: false })),
    fetchUpdateAsync: jest.fn(async () => ({ isNew: false })),
    reloadAsync: jest.fn(async () => undefined),
    ...overrides,
  } as jest.Mocked<UpdatesApi>;
}

afterEach(() => {
  setUpdatesApiForTests('real');
  jest.useRealTimers();
});

describe('checkAndFetchUpdate', () => {
  it('is unavailable in __DEV__ / Expo Go / without expo-updates (the real lookup in Jest)', async () => {
    expect(canCheckForUpdates()).toBe(false);
    await expect(checkAndFetchUpdate()).resolves.toBe('unavailable');
    expect(await restartIntoUpdate()).toBe(false);
  });

  it('no update → up_to_date, nothing downloaded, no reload', async () => {
    const updates = fakeUpdates();
    setUpdatesApiForTests(updates);
    expect(canCheckForUpdates()).toBe(true);
    await expect(checkAndFetchUpdate()).resolves.toBe('up_to_date');
    expect(updates.fetchUpdateAsync).not.toHaveBeenCalled();
    expect(updates.reloadAsync).not.toHaveBeenCalled();
  });

  it('update available → downloaded → ready (restart only on request)', async () => {
    const updates = fakeUpdates({
      checkForUpdateAsync: jest.fn(async () => ({ isAvailable: true })),
      fetchUpdateAsync: jest.fn(async () => ({ isNew: true })),
    });
    setUpdatesApiForTests(updates);
    await expect(checkAndFetchUpdate()).resolves.toBe('ready');
    expect(updates.reloadAsync).not.toHaveBeenCalled();
    expect(await restartIntoUpdate()).toBe(true);
    expect(updates.reloadAsync).toHaveBeenCalledTimes(1);
  });

  it('a rollback to the embedded bundle also needs a restart', async () => {
    setUpdatesApiForTests(
      fakeUpdates({
        checkForUpdateAsync: jest.fn(async () => ({ isAvailable: false, isRollBackToEmbedded: true })),
        fetchUpdateAsync: jest.fn(async () => ({ isNew: false, isRollBackToEmbedded: true })),
      }),
    );
    await expect(checkAndFetchUpdate()).resolves.toBe('ready');
  });

  it('a fetch that finds nothing new → up_to_date', async () => {
    setUpdatesApiForTests(fakeUpdates({ checkForUpdateAsync: jest.fn(async () => ({ isAvailable: true })) }));
    await expect(checkAndFetchUpdate()).resolves.toBe('up_to_date');
  });

  it('errors → error (never throws); a failed reload → false', async () => {
    setUpdatesApiForTests(
      fakeUpdates({
        checkForUpdateAsync: jest.fn(async () => {
          throw new Error('ERR_UPDATES_CHECK');
        }),
        reloadAsync: jest.fn(async () => {
          throw new Error('ERR_UPDATES_RELOAD');
        }),
      }),
    );
    await expect(checkAndFetchUpdate()).resolves.toBe('error');
    expect(await restartIntoUpdate()).toBe(false);
  });

  it('a hung check or download → timeout', async () => {
    jest.useFakeTimers();
    const never = () => new Promise<never>(() => undefined);
    setUpdatesApiForTests(fakeUpdates({ checkForUpdateAsync: jest.fn(never) }));
    const hungCheck = checkAndFetchUpdate({ checkMs: 1000 });
    await jest.advanceTimersByTimeAsync(1000);
    await expect(hungCheck).resolves.toBe('timeout');

    setUpdatesApiForTests(fakeUpdates({ checkForUpdateAsync: jest.fn(async () => ({ isAvailable: true })), fetchUpdateAsync: jest.fn(never) }));
    const hungFetch = checkAndFetchUpdate({ fetchMs: 2000 });
    await jest.advanceTimersByTimeAsync(2000);
    await expect(hungFetch).resolves.toBe('timeout');
  });
});
