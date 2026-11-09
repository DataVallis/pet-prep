/**
 * The child's PET zone, remembered on the device for the lock overlay (hotfix 2026-10-06;
 * since David 2026-11-09 `state.timezone` is the pet's effective zone — its own, else the
 * family's — so this is no longer "the family zone"). After a relaunch the lock overlay
 * ("Kuža je pri veterinarju do 10:41") is built from `/api/user` before `GET /api/child/pet`
 * answers — and that pet only has a UTC `illness_until`. With the remembered zone the end time
 * is on the pet's clock even when the child state can't load (429 / offline).
 *
 * Keyed PER CHILD (`petprep.family_timezone.<childId>`, QA #891 m6): on a shared phone (M2-11)
 * two children's pets can run on different clocks. The un-keyed legacy value
 * (`petprep.family_timezone`) is only a read fallback and is deleted on logout. Not personal
 * data: a zone name only.
 */

import * as SecureStore from 'expo-secure-store';

/** The legacy (pre-2026-11-09) key, and the prefix of the per-child keys. */
export const FAMILY_TIMEZONE_KEY = 'petprep.family_timezone';

export interface TimezoneStore {
  getItemAsync(key: string): Promise<string | null>;
  setItemAsync(key: string, value: string): Promise<void>;
  deleteItemAsync(key: string): Promise<void>;
}

const IANA_ZONE = /^[A-Za-z][A-Za-z0-9_+-]*(\/[A-Za-z0-9_+-]+){0,2}$/;

export function isZoneName(value: unknown): value is string {
  return typeof value === 'string' && value.length <= 64 && IANA_ZONE.test(value);
}

/** The SecureStore key of one child's remembered pet zone (null = the legacy key). */
export function petZoneKey(childId: number | null | undefined): string {
  return typeof childId === 'number' && Number.isInteger(childId) && childId > 0 ? `${FAMILY_TIMEZONE_KEY}.${childId}` : FAMILY_TIMEZONE_KEY;
}

/** Last value written (or read) per key in this app run — skips redundant Keychain writes. */
const known = new Map<string, string | null>();

async function read(key: string, store: TimezoneStore): Promise<string | null> {
  const value = await store.getItemAsync(key);
  return isZoneName(value) ? value : null;
}

export async function loadFamilyTimezone(store: TimezoneStore = SecureStore, childId?: number | null): Promise<string | null> {
  const key = petZoneKey(childId);
  try {
    let value = await read(key, store);
    if (value === null && key !== FAMILY_TIMEZONE_KEY) value = await read(FAMILY_TIMEZONE_KEY, store);
    known.set(key, value);
    return value;
  } catch {
    return null;
  }
}

/** Remember the zone from a child state (fire and forget, never throws). */
export function rememberFamilyTimezone(zone: string, store: TimezoneStore = SecureStore, childId?: number | null): void {
  const key = petZoneKey(childId);
  if (!isZoneName(zone) || known.get(key) === zone) return;
  known.set(key, zone);
  const forget = () => {
    known.delete(key);
  };
  try {
    // Promise.resolve: a store that returns nothing must not throw inside a render effect.
    Promise.resolve(store.setItemAsync(key, zone)).catch(forget);
  } catch {
    forget();
  }
}

/** On logout: this child's value and the legacy one. */
export async function forgetFamilyTimezone(store: TimezoneStore = SecureStore, childId?: number | null): Promise<void> {
  const keys = [...new Set([petZoneKey(childId), FAMILY_TIMEZONE_KEY])];
  for (const key of keys) {
    known.delete(key);
    try {
      await store.deleteItemAsync(key);
    } catch {
      // Best effort: the next child state overwrites it anyway.
    }
  }
}

/** Test hook. */
export function resetFamilyTimezoneCacheForTests(): void {
  known.clear();
}
