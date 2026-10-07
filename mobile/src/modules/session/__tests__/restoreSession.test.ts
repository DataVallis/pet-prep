import * as SecureStore from 'expo-secure-store';

import { ApiError, api } from '@/api/client';
import { restoreSession } from '@/modules/session/restoreSession';
import { unregisterStepSyncTask } from '@/modules/steps/backgroundSteps';
import { makePet } from '@/test-utils/fixtures';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, getUser: jest.fn(), logout: jest.fn() } };
});

const getItem = SecureStore.getItemAsync as jest.Mock;
const deleteItem = SecureStore.deleteItemAsync as jest.Mock;
const getUser = api.getUser as jest.Mock;

jest.mock('@/modules/steps/backgroundSteps', () => ({ unregisterStepSyncTask: jest.fn(async () => undefined) }));

describe('restoreSession', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it('is anonymous without a saved token and never calls the API', async () => {
    getItem.mockResolvedValueOnce(null);
    await expect(restoreSession()).resolves.toEqual({ status: 'anonymous' });
    expect(getUser).not.toHaveBeenCalled();
  });

  it('is anonymous when SecureStore itself fails', async () => {
    getItem.mockRejectedValueOnce(new Error('keychain locked'));
    await expect(restoreSession()).resolves.toEqual({ status: 'anonymous' });
  });

  it('returns the flat /api/user payload as a sign-in session', async () => {
    const pet = makePet();
    getItem.mockResolvedValueOnce('tok-123');
    getUser.mockResolvedValueOnce({ id: 2, name: 'Otrok', email: 'c@x.si', role: 'child', pet });

    await expect(restoreSession()).resolves.toEqual({
      status: 'authenticated',
      session: { token: 'tok-123', user: { id: 2, name: 'Otrok', email: 'c@x.si', role: 'child' }, pet, familyTimezone: null },
    });
  });

  it('passes the per-child awaiting_contract on as awaitingContract, never on the user (M2-02)', async () => {
    const pet = makePet();
    getItem.mockResolvedValueOnce('tok-9');
    getUser.mockResolvedValueOnce({ id: 3, name: 'Bor', email: null, role: 'child', pet, awaiting_contract: true });

    const result = await restoreSession();
    expect(result).toEqual({
      status: 'authenticated',
      session: { token: 'tok-9', user: { id: 3, name: 'Bor', email: null, role: 'child' }, pet, awaitingContract: true, familyTimezone: null },
    });
    if (result.status === 'authenticated') expect(result.session.user).not.toHaveProperty('awaiting_contract');
  });

  it('hotfix 2026-10-06: a child session carries the remembered family timezone (lock overlay before the state loads)', async () => {
    getItem.mockImplementation((key: string) =>
      Promise.resolve(key === 'petprep.family_timezone' ? 'Europe/Ljubljana' : 'tok-5'),
    );
    getUser.mockResolvedValueOnce({ id: 4, name: 'Ana', email: null, role: 'child', pet: makePet() });
    const result = await restoreSession();
    expect(result.status === 'authenticated' && result.session.familyTimezone).toBe('Europe/Ljubljana');

    // A garbage value is ignored; a parent never reads it.
    getItem.mockImplementation((key: string) => Promise.resolve(key === 'petprep.family_timezone' ? '../etc' : 'tok-5'));
    getUser.mockResolvedValueOnce({ id: 4, name: 'Ana', email: null, role: 'child', pet: makePet() });
    const garbage = await restoreSession();
    expect(garbage.status === 'authenticated' && garbage.session.familyTimezone).toBeNull();
    getItem.mockReset();
    getItem.mockResolvedValue(null);
  });

  it('clears the token on 401', async () => {
    getItem.mockResolvedValueOnce('revoked');
    getUser.mockRejectedValueOnce(new ApiError('Unauthenticated.', 401));

    await expect(restoreSession()).resolves.toEqual({ status: 'anonymous' });
    expect(deleteItem).toHaveBeenCalledWith('petprep_auth_token');
    expect(unregisterStepSyncTask).toHaveBeenCalledTimes(1); // M3-06: no background sync with a revoked token
  });

  it('keeps the token when the server is unreachable (offline)', async () => {
    getItem.mockResolvedValueOnce('tok-123');
    getUser.mockRejectedValueOnce(new TypeError('Network request failed'));

    const result = await restoreSession();
    expect(result.status).toBe('offline');
    expect(deleteItem).not.toHaveBeenCalled();
    expect(unregisterStepSyncTask).not.toHaveBeenCalled();
  });

  it('keeps the token on a 5xx', async () => {
    getItem.mockResolvedValueOnce('tok-123');
    getUser.mockRejectedValueOnce(new ApiError('Server Error', 503));

    expect((await restoreSession()).status).toBe('offline');
    expect(deleteItem).not.toHaveBeenCalled();
  });
});
