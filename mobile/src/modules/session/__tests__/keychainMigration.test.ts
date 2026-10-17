import * as SecureStore from 'expo-secure-store';

import { SESSION_SECURE_STORE_OPTIONS, TOKEN_KEY, saveAuthToken } from '@/api/client';
import { KEYCHAIN_MIGRATION_KEY, migrateSessionKeychain } from '@/modules/session/keychainMigration';
import { CHILD_SESSIONS_KEY } from '@/modules/session/profiles';

/** In-memory Keychain that records the access class of every write. */
function fakeStore(initial: Record<string, string>) {
  const items = new Map(Object.entries(initial).map(([k, v]) => [k, { value: v, access: 'WHEN_UNLOCKED' as string }]));
  const store = {
    items,
    getItemAsync: jest.fn(async (key: string) => items.get(key)?.value ?? null),
    deleteItemAsync: jest.fn(async (key: string) => {
      items.delete(key);
    }),
    setItemAsync: jest.fn(async (key: string, value: string, options?: SecureStore.SecureStoreOptions) => {
      const existing = items.get(key);
      // iOS: an existing item keeps its access class; only the value changes.
      if (existing) existing.value = value;
      else items.set(key, { value, access: options?.keychainAccessible === SecureStore.AFTER_FIRST_UNLOCK ? 'AFTER_FIRST_UNLOCK' : 'WHEN_UNLOCKED' });
    }),
  };
  return store;
}

describe('migrateSessionKeychain (fix 2026-10-17: child signed out after a locked-phone launch)', () => {
  it('re-creates the token and child sessions as AFTER_FIRST_UNLOCK, keeping the values', async () => {
    const store = fakeStore({ [TOKEN_KEY]: 'tok', [CHILD_SESSIONS_KEY]: '{"2":{"token":"c","parentUserId":1}}' });

    await expect(migrateSessionKeychain(store)).resolves.toBe(true);

    expect(store.items.get(TOKEN_KEY)).toEqual({ value: 'tok', access: 'AFTER_FIRST_UNLOCK' });
    expect(store.items.get(CHILD_SESSIONS_KEY)).toEqual({ value: '{"2":{"token":"c","parentUserId":1}}', access: 'AFTER_FIRST_UNLOCK' });
    expect(store.items.get(KEYCHAIN_MIGRATION_KEY)?.value).toBe('1');
  });

  it('runs once: a set marker skips everything', async () => {
    const store = fakeStore({ [TOKEN_KEY]: 'tok', [KEYCHAIN_MIGRATION_KEY]: '1' });
    await expect(migrateSessionKeychain(store)).resolves.toBe(false);
    expect(store.deleteItemAsync).not.toHaveBeenCalled();
  });

  it('skips keys that are not stored', async () => {
    const store = fakeStore({ [TOKEN_KEY]: 'tok' });
    await migrateSessionKeychain(store);
    expect(store.items.has(CHILD_SESSIONS_KEY)).toBe(false);
  });

  it('never loses the session: a failed write puts the value back and leaves the marker unset', async () => {
    const store = fakeStore({ [TOKEN_KEY]: 'tok' });
    store.setItemAsync.mockRejectedValueOnce(new Error('keychain busy'));

    await expect(migrateSessionKeychain(store)).resolves.toBe(false);

    expect(store.items.get(TOKEN_KEY)?.value).toBe('tok');
    expect(store.items.has(KEYCHAIN_MIGRATION_KEY)).toBe(false);
  });

  it('never throws when the Keychain is unreadable', async () => {
    const store = fakeStore({ [TOKEN_KEY]: 'tok' });
    store.getItemAsync.mockRejectedValue(new Error('locked'));
    await expect(migrateSessionKeychain(store)).resolves.toBe(false);
    expect(store.items.get(TOKEN_KEY)?.value).toBe('tok');
  });
});

describe('saveAuthToken', () => {
  it('writes the token with AFTER_FIRST_UNLOCK', async () => {
    const set = SecureStore.setItemAsync as jest.Mock;
    set.mockClear();
    await saveAuthToken('new');
    expect(set).toHaveBeenCalledWith(TOKEN_KEY, 'new', SESSION_SECURE_STORE_OPTIONS);
  });
});
