/**
 * M2-02 (PR #16 review): a PIN login never leaves an older session token alive.
 */
import * as SecureStore from 'expo-secure-store';

import { ApiError, api, type PinLoginResponse } from '@/api/client';
import { performPinLogin } from '@/modules/pairing/pinLogin';
import { useAppStore } from '@/store/appStore';
import { makeMedia, makePetProfile, makePet } from '@/test-utils/fixtures';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, pinLogin: jest.fn(), getUser: jest.fn(), logout: jest.fn(), unregisterDevice: jest.fn() } };
});

const getItem = SecureStore.getItemAsync as jest.Mock;
const setItem = SecureStore.setItemAsync as jest.Mock;
const deleteItem = SecureStore.deleteItemAsync as jest.Mock;
const pinLogin = api.pinLogin as jest.Mock;
const getUser = api.getUser as jest.Mock;
const apiLogout = api.logout as jest.Mock;

const response: PinLoginResponse = {
  token: 'new-child-token',
  abilities: ['child'],
  user: { id: 9, name: 'Maja', role: 'child' },
  mode: 'relogin',
  joined_existing: false,
  family_id: 1,
  pet: {
    id: 7,
    breed_type: 'mutt',
    species: 'dog',
    hunger_level: 80,
    thirst_level: 70,
    energy_level: 60,
    hygiene_level: 50,
    born_at: '2026-10-01T08:00:00+00:00',
    awaiting_contract: false,
    is_active: true,
    is_game_over: false,
    pet_dna: { seed: null, prompt_anchor: null, visual_traits: null, reference_image_url: null },
    current_video_url: null,
    media_status: 'disabled',
    media: makeMedia(),
    profile: makePetProfile(),
  },
  awaiting_contract: false,
};

describe('performPinLogin', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    useAppStore.setState(useAppStore.getInitialState(), true);
    getUser.mockResolvedValue({ id: 9, name: 'Maja', email: null, role: 'child', pet: makePet({ id: 7 }) });
    apiLogout.mockResolvedValue({ message: 'ok' });
  });

  it('without a stored token: no revoke, saves the new token', async () => {
    getItem.mockResolvedValue(null);
    pinLogin.mockResolvedValueOnce(response);

    const session = await performPinLogin('123456', 'iPhone');

    expect(apiLogout).not.toHaveBeenCalled();
    expect(setItem).toHaveBeenCalledWith('petprep_auth_token', 'new-child-token');
    expect(session.token).toBe('new-child-token');
  });

  it('revokes and clears an older stored token before saving the new one', async () => {
    const order: string[] = [];
    getItem.mockResolvedValue('old-legacy-token');
    pinLogin.mockResolvedValueOnce(response);
    apiLogout.mockImplementationOnce(async () => {
      order.push('revoke');
      return { message: 'ok' };
    });
    // logout() also forgets the push registration key (M3-02) — only the session token counts here.
    deleteItem.mockImplementation(async (key: string) => {
      if (key === 'petprep_auth_token') order.push('clear');
    });
    setItem.mockImplementationOnce(async () => {
      order.push('save');
    });

    await performPinLogin('123456', 'iPhone');

    expect(order).toEqual(['revoke', 'clear', 'save']);
  });

  it('a wrong PIN keeps the existing session untouched', async () => {
    getItem.mockResolvedValue('old-token');
    pinLogin.mockRejectedValueOnce(new ApiError('invalid', 422, { reason: 'invalid_pin' }));

    await expect(performPinLogin('000000', 'iPhone')).rejects.toBeInstanceOf(ApiError);
    expect(apiLogout).not.toHaveBeenCalled();
    expect(deleteItem).not.toHaveBeenCalled();
    expect(setItem).not.toHaveBeenCalled();
  });
});
