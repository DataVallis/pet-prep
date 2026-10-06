/**
 * The family's IANA timezone, remembered on the device (hotfix 2026-10-06). After a
 * relaunch the lock overlay ("Kuža je pri veterinarju do 10:41") is built from
 * `/api/user` before `GET /api/child/pet` answers — and that pet only has a UTC
 * `illness_until`. With the remembered zone the end time is family-local even when the
 * child state can't load (429 / offline). Not personal data: a zone name only.
 */

import * as SecureStore from 'expo-secure-store';

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

/** Last value written (or read) in this app run — skips redundant Keychain writes. */
let known: string | null | undefined;

export async function loadFamilyTimezone(store: TimezoneStore = SecureStore): Promise<string | null> {
  try {
    const value = await store.getItemAsync(FAMILY_TIMEZONE_KEY);
    known = isZoneName(value) ? value : null;
    return known;
  } catch {
    return null;
  }
}

/** Remember the zone from a child state (fire and forget, never throws). */
export function rememberFamilyTimezone(zone: string, store: TimezoneStore = SecureStore): void {
  if (!isZoneName(zone) || zone === known) return;
  known = zone;
  const forget = () => {
    known = undefined;
  };
  try {
    // Promise.resolve: a store that returns nothing must not throw inside a render effect.
    Promise.resolve(store.setItemAsync(FAMILY_TIMEZONE_KEY, zone)).catch(forget);
  } catch {
    forget();
  }
}

export async function forgetFamilyTimezone(store: TimezoneStore = SecureStore): Promise<void> {
  known = undefined;
  try {
    await store.deleteItemAsync(FAMILY_TIMEZONE_KEY);
  } catch {
    // Best effort: the next child state overwrites it anyway.
  }
}

/** Test hook. */
export function resetFamilyTimezoneCacheForTests(): void {
  known = undefined;
}
