/**
 * M2-10c (QA m1): the server's `has_password` / `linked_providers` decide what a deletion
 * asks for; the remembered sign-in method only fills in for payloads without them.
 */
import { ApiError, api } from '@/api/client';
import { deletionIdentity, readAuthMethods } from '@/modules/session/authMethods';
import { refreshSessionPet } from '@/modules/session/logout';
import { useAppStore } from '@/store/appStore';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, getUser: jest.fn() } };
});

const getUser = api.getUser as jest.Mock;
const all = () => true;
const androidOnlyGoogle = (p: string) => p === 'google';

describe('readAuthMethods', () => {
  it('reads the server fields (known providers only, Google first, no duplicates)', () => {
    expect(readAuthMethods({ has_password: false, linked_providers: ['apple', 'google', 'apple', 'facebook'] })).toEqual({
      hasPassword: false,
      linkedProviders: ['google', 'apple'],
    });
    expect(readAuthMethods({ has_password: true, linked_providers: [] })).toEqual({ hasPassword: true, linkedProviders: [] });
  });

  it('missing or malformed → null (older server, cached state)', () => {
    expect(readAuthMethods({})).toBeNull();
    expect(readAuthMethods({ has_password: true })).toBeNull();
    expect(readAuthMethods({ has_password: 'yes', linked_providers: [] })).toBeNull();
    expect(readAuthMethods(null)).toBeNull();
  });
});

describe('deletionIdentity', () => {
  it('a password account → the password, whatever was remembered', () => {
    expect(deletionIdentity({ hasPassword: true, linkedProviders: [] }, 'google', all)).toEqual({ kind: 'password' });
  });

  it('no password → the linked providers this device can run', () => {
    expect(deletionIdentity({ hasPassword: false, linkedProviders: ['google', 'apple'] }, 'password', all)).toEqual({
      kind: 'providers',
      providers: ['google', 'apple'],
    });
    expect(deletionIdentity({ hasPassword: false, linkedProviders: ['google', 'apple'] }, 'apple', androidOnlyGoogle)).toEqual({
      kind: 'providers',
      providers: ['google'],
    });
  });

  it('Apple-only account on Android → unavailable (no crash, no password field)', () => {
    expect(deletionIdentity({ hasPassword: false, linkedProviders: ['apple'] }, 'apple', androidOnlyGoogle)).toEqual({ kind: 'unavailable' });
  });

  it('unknown server state → the remembered method', () => {
    expect(deletionIdentity(null, 'password', all)).toEqual({ kind: 'password' });
    expect(deletionIdentity(null, 'apple', all)).toEqual({ kind: 'providers', providers: ['apple'] });
    expect(deletionIdentity(null, 'apple', androidOnlyGoogle)).toEqual({ kind: 'unavailable' });
  });
});

describe('the store follows the server', () => {
  beforeEach(() => useAppStore.getState().reset());

  it('signIn() reads has_password / linked_providers from the user (login, register, social, restore)', () => {
    useAppStore.getState().signIn({
      token: 't',
      user: { id: 1, name: 'A', email: 'a@b.si', role: 'parent', has_password: false, linked_providers: ['apple'] },
      pet: null,
      signInMethod: 'apple',
    });
    expect(useAppStore.getState().authMethods).toEqual({ hasPassword: false, linkedProviders: ['apple'] });
    // The store's user stays the clean session user.
    expect(useAppStore.getState().user).toEqual({ id: 1, name: 'A', email: 'a@b.si', role: 'parent' });

    useAppStore.getState().reset();
    expect(useAppStore.getState().authMethods).toBeNull();
  });

  it('refreshSessionPet() (GET /api/user) refreshes them; a payload without them keeps the last known', async () => {
    useAppStore.getState().signIn({ token: 't', user: { id: 1, name: 'A', email: null, role: 'parent' }, pet: null });
    expect(useAppStore.getState().authMethods).toBeNull();

    getUser.mockResolvedValueOnce({ id: 1, name: 'A', email: null, role: 'parent', has_password: false, linked_providers: ['google', 'apple'], pet: null });
    await refreshSessionPet();
    expect(useAppStore.getState().authMethods).toEqual({ hasPassword: false, linkedProviders: ['google', 'apple'] });

    getUser.mockResolvedValueOnce({ id: 1, name: 'A', email: null, role: 'parent', pet: null });
    await refreshSessionPet();
    expect(useAppStore.getState().authMethods).toEqual({ hasPassword: false, linkedProviders: ['google', 'apple'] });

    getUser.mockRejectedValueOnce(new ApiError('x', 500, null));
    await expect(refreshSessionPet()).rejects.toBeInstanceOf(ApiError);
  });
});
