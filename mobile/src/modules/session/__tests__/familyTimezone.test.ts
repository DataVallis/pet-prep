/** Hotfix 2026-10-06: the family zone remembered for the lock overlay after a relaunch. */
import {
  FAMILY_TIMEZONE_KEY,
  forgetFamilyTimezone,
  isZoneName,
  loadFamilyTimezone,
  rememberFamilyTimezone,
  resetFamilyTimezoneCacheForTests,
  type TimezoneStore,
} from '@/modules/session/familyTimezone';

function memoryStore(): TimezoneStore & { data: Map<string, string>; setItemAsync: jest.Mock } {
  const data = new Map<string, string>();
  return {
    data,
    getItemAsync: jest.fn(async (key: string) => data.get(key) ?? null),
    setItemAsync: jest.fn(async (key: string, value: string) => {
      data.set(key, value);
    }),
    deleteItemAsync: jest.fn(async (key: string) => {
      data.delete(key);
    }),
  };
}

describe('familyTimezone', () => {
  beforeEach(() => resetFamilyTimezoneCacheForTests());

  it('remembers a zone once per change, loads it back, forgets it on logout', async () => {
    const store = memoryStore();
    rememberFamilyTimezone('Europe/Ljubljana', store);
    rememberFamilyTimezone('Europe/Ljubljana', store);
    await Promise.resolve();
    expect(store.setItemAsync).toHaveBeenCalledTimes(1);
    expect(store.data.get(FAMILY_TIMEZONE_KEY)).toBe('Europe/Ljubljana');
    await expect(loadFamilyTimezone(store)).resolves.toBe('Europe/Ljubljana');

    await forgetFamilyTimezone(store);
    await expect(loadFamilyTimezone(store)).resolves.toBeNull();
  });

  it('never throws from a render effect: a store returning nothing or throwing is ignored', () => {
    const broken: TimezoneStore = {
      getItemAsync: jest.fn(),
      setItemAsync: jest.fn(() => undefined as unknown as Promise<void>),
      deleteItemAsync: jest.fn(),
    };
    expect(() => rememberFamilyTimezone('Europe/Ljubljana', broken)).not.toThrow();
    const throwing: TimezoneStore = {
      getItemAsync: jest.fn(),
      setItemAsync: jest.fn(() => {
        throw new Error('keychain');
      }),
      deleteItemAsync: jest.fn(),
    };
    expect(() => rememberFamilyTimezone('America/New_York', throwing)).not.toThrow();
  });

  it('accepts IANA names only', () => {
    expect(isZoneName('Europe/Ljubljana')).toBe(true);
    expect(isZoneName('America/Argentina/Buenos_Aires')).toBe(true);
    expect(isZoneName('UTC')).toBe(true);
    expect(isZoneName('../etc/passwd')).toBe(false);
    expect(isZoneName('')).toBe(false);
    expect(isZoneName(42)).toBe(false);
  });
});
