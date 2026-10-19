/**
 * One-time Keychain migration (fix 2026-10-17): session secrets written by older builds
 * use the default `WHEN_UNLOCKED` access, so they can't be read while the phone is locked
 * (iOS background launches). Each is re-written with {@link SESSION_SECURE_STORE_OPTIONS}.
 *
 * iOS only updates the value of an existing Keychain item, never its access class, so an
 * item is deleted and written again. Runs right after a successful restore (the phone is
 * unlocked — the token was just read) and before the session is applied, so no request
 * can read the token in between. Never throws; an unfinished migration is retried at the
 * next start (the marker is written last).
 */

import * as SecureStore from 'expo-secure-store';

import { SESSION_SECURE_STORE_OPTIONS, TOKEN_KEY } from '@/api/client';
import { CHILD_SESSIONS_KEY } from './profiles';

export const KEYCHAIN_MIGRATION_KEY = 'petprep_keychain_after_first_unlock';

type Store = Pick<typeof SecureStore, 'getItemAsync' | 'setItemAsync' | 'deleteItemAsync'>;

/** Keys holding session tokens — the ones a locked-phone launch must be able to read. */
export const SESSION_SECRET_KEYS = [TOKEN_KEY, CHILD_SESSIONS_KEY] as const;

async function rewrite(key: string, store: Store): Promise<void> {
  const value = await store.getItemAsync(key);
  if (value === null) return;
  await store.deleteItemAsync(key);
  try {
    await store.setItemAsync(key, value, SESSION_SECURE_STORE_OPTIONS);
  } catch (error) {
    // Never lose the session: put it back the old way (the next start retries).
    await store.setItemAsync(key, value);
    throw error;
  }
}

let running: Promise<boolean> | null = null;

/**
 * The migration still running from an earlier call, or null (boot-hang QA M1, 2026-10-19).
 * While it runs a session key may be deleted and not yet re-added — a restore must wait for
 * it before it reads the token, or it would see "no token" and show the login screen.
 */
export function pendingKeychainMigration(): Promise<boolean> | null {
  return running;
}

/** Single-flight: a second call while one runs joins it (never two delete + re-add at once). */
export function migrateSessionKeychain(store: Store = SecureStore): Promise<boolean> {
  if (running) return running;
  const current = migrateOnce(store).finally(() => {
    if (running === current) running = null;
  });
  running = current;
  return current;
}

async function migrateOnce(store: Store): Promise<boolean> {
  try {
    if ((await store.getItemAsync(KEYCHAIN_MIGRATION_KEY)) === '1') return false;
    for (const key of SESSION_SECRET_KEYS) await rewrite(key, store);
    await store.setItemAsync(KEYCHAIN_MIGRATION_KEY, '1', SESSION_SECURE_STORE_OPTIONS);
    return true;
  } catch {
    return false;
  }
}

/** Test hook. */
export function resetKeychainMigrationForTests(): void {
  running = null;
}
