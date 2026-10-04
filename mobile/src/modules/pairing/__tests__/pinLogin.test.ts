/**
 * M2-02: child PIN login helpers.
 */
import { ApiError, api, type PinLoginResponse } from '@/api/client';
import { deviceName, FALLBACK_DEVICE_NAME } from '@/modules/pairing/deviceName';
import {
  classifyPinLoginError,
  DEFAULT_LOCKOUT_SECONDS,
  petFromPairedPet,
  sessionFromPinLogin,
} from '@/modules/pairing/pinLogin';
import { makePet } from '@/test-utils/fixtures';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, getUser: jest.fn() } };
});

const getUser = api.getUser as jest.Mock;

const response: PinLoginResponse = {
  token: 'tok',
  abilities: ['child'],
  user: { id: 9, name: 'Maja', role: 'child' },
  mode: 'join_pet',
  joined_existing: true,
  family_id: 1,
  pet: {
    id: 7,
    breed_type: 'border_collie',
    hunger_level: 80,
    thirst_level: 70,
    energy_level: 60,
    hygiene_level: 50,
    born_at: '2026-10-01T08:00:00+00:00',
    awaiting_contract: true,
    is_active: true,
    is_game_over: false,
    pet_dna: { seed: null, prompt_anchor: null, visual_traits: null, reference_image_url: null },
    current_video_url: null,
    media_status: 'disabled',
  },
  awaiting_contract: true,
};

describe('classifyPinLoginError', () => {
  it.each([
    [new ApiError('x', 422, { reason: 'invalid_pin' }), 'invalid'],
    [new ApiError('x', 422, { reason: 'pin_not_usable' }), 'invalid'],
    [new ApiError('x', 422, { errors: { pin: ['x'] } }), 'invalid'],
    [new ApiError('x', 500), 'server'],
    [new ApiError('x', 404), 'server'],
    [new TypeError('Network request failed'), 'offline'],
  ])('%p → %s', (error, kind) => {
    expect(classifyPinLoginError(error).kind).toBe(kind);
  });

  it('429: Retry-After header first, then body retry_after, then the default', () => {
    expect(classifyPinLoginError(new ApiError('x', 429, { retry_after: 600 }, 45))).toEqual({ kind: 'rate_limited', retryAfterSeconds: 45 });
    expect(classifyPinLoginError(new ApiError('x', 429, { retry_after: 600 }))).toEqual({ kind: 'rate_limited', retryAfterSeconds: 600 });
    expect(classifyPinLoginError(new ApiError('x', 429, null))).toEqual({ kind: 'rate_limited', retryAfterSeconds: DEFAULT_LOCKOUT_SECONDS });
    expect(classifyPinLoginError(new ApiError('x', 429, null, 0)).retryAfterSeconds).toBe(1);
  });
});

describe('sessionFromPinLogin', () => {
  beforeEach(() => getUser.mockReset());

  it('uses the full pet from /api/user but keeps the per-child awaiting_contract', async () => {
    const pet = makePet({ id: 7, born_at: '2026-10-01T08:00:00Z' });
    getUser.mockResolvedValueOnce({ id: 9, name: 'Maja', email: null, role: 'child', pet });

    const session = await sessionFromPinLogin(response);
    expect(session.token).toBe('tok');
    expect(session.user).toEqual({ id: 9, name: 'Maja', email: null, role: 'child' });
    expect(session.pet).toEqual({ ...pet, awaiting_contract: true });
  });

  it('falls back to the pin-login pet when /api/user fails or returns another pet', async () => {
    getUser.mockRejectedValueOnce(new TypeError('offline'));
    const offline = await sessionFromPinLogin(response);
    expect(offline.pet).toEqual(petFromPairedPet(response.pet, 9));

    getUser.mockResolvedValueOnce({ id: 9, name: 'Maja', email: null, role: 'child', pet: makePet({ id: 99 }) });
    const other = await sessionFromPinLogin(response);
    expect(other.pet?.id).toBe(7);
  });

  it('petFromPairedPet maps metrics, breed and contract state', () => {
    const pet = petFromPairedPet(response.pet, 9);
    expect(pet).toMatchObject({
      id: 7,
      user_id: 9,
      breed_type: 'border_collie',
      hunger_level: 80,
      thirst_level: 70,
      energy_level: 60,
      hygiene_level: 50,
      born_at: '2026-10-01T08:00:00+00:00',
      awaiting_contract: true,
      is_game_over: false,
      escalation_level: 0,
      illness_until: null,
    });
    expect(petFromPairedPet({ ...response.pet, breed_type: 'poodle' }, 9).breed_type).toBe('mutt');
  });
});

describe('deviceName', () => {
  it('Android: brand + model, without repeating the brand', () => {
    expect(deviceName({ OS: 'android', constants: { Brand: 'samsung', Model: 'SM-A515F' } })).toBe('samsung SM-A515F');
    expect(deviceName({ OS: 'android', constants: { Brand: 'Google', Model: 'Google Pixel 8' } })).toBe('Google Pixel 8');
  });

  it('iOS: only the device class (the user-chosen name may hold the child\'s name)', () => {
    expect(deviceName({ OS: 'ios', constants: { interfaceIdiom: 'phone' } })).toBe('iPhone');
    expect(deviceName({ OS: 'ios', constants: { interfaceIdiom: 'pad' } })).toBe('iPad');
  });

  it('falls back to "Telefon" and never exceeds 100 characters', () => {
    expect(deviceName({ OS: 'web', constants: {} })).toBe(FALLBACK_DEVICE_NAME);
    expect(deviceName({ OS: 'android', constants: {} })).toBe(FALLBACK_DEVICE_NAME);
    expect(deviceName({ OS: 'android', constants: { Brand: 'x', Model: 'y'.repeat(200) } }).length).toBe(100);
  });
});
