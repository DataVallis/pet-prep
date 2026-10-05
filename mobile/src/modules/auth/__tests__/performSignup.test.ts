/**
 * M2-10a / PR #25: sign-up request — token stored like a login; a 422 on the device
 * timezone is retried ONCE without the timezone (server default Europe/Ljubljana).
 */
import * as SecureStore from 'expo-secure-store';

import { ApiError, api, type RegisterParentRequest } from '@/api/client';
import { isTimezoneRejection, performSignup } from '@/modules/auth/signup';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, register: jest.fn() } };
});

const register = api.register as jest.Mock;
const setItem = SecureStore.setItemAsync as jest.Mock;

const body: RegisterParentRequest = {
  name: 'Mama Ana',
  email: 'ana@example.com',
  password: 'Varno1Geslo',
  password_confirmation: 'Varno1Geslo',
  accept_terms: true,
  device_name: 'iPhone',
  timezone: 'Etc/Unknown',
};

const session = {
  token: 'tok',
  abilities: ['parent'],
  user: { id: 1, name: 'Mama Ana', email: 'ana@example.com', role: 'parent' },
  pet: null,
  awaiting_contract: null,
};

const timezone422 = () =>
  new ApiError('bad tz', 422, { message: 'bad tz', errors: { timezone: ['x'] }, codes: { timezone: 'timezone_invalid' } });

describe('performSignup', () => {
  beforeEach(() => jest.clearAllMocks());

  it('stores the token and returns the sign-in payload', async () => {
    register.mockResolvedValueOnce(session);
    await expect(performSignup(body)).resolves.toEqual({
      token: 'tok',
      user: session.user,
      pet: null,
      awaitingContract: null,
    });
    expect(register).toHaveBeenCalledTimes(1);
    expect(setItem).toHaveBeenCalledWith('petprep_auth_token', 'tok');
  });

  it('retries once without the timezone when the server rejects it', async () => {
    register.mockRejectedValueOnce(timezone422()).mockResolvedValueOnce(session);
    await expect(performSignup(body)).resolves.toMatchObject({ token: 'tok' });

    expect(register).toHaveBeenCalledTimes(2);
    expect(register.mock.calls[1][0]).not.toHaveProperty('timezone');
    expect(register.mock.calls[1][0]).toMatchObject({ email: 'ana@example.com', accept_terms: true });
    expect(body.timezone).toBe('Etc/Unknown'); // caller's body untouched
  });

  it('retries only once — a second rejection is thrown', async () => {
    register.mockRejectedValueOnce(timezone422()).mockRejectedValueOnce(timezone422());
    await expect(performSignup(body)).rejects.toBeInstanceOf(ApiError);
    expect(register).toHaveBeenCalledTimes(2);
    expect(setItem).not.toHaveBeenCalled();
  });

  it('does not retry other errors or a body without timezone', async () => {
    const taken = new ApiError('taken', 422, { message: 'taken', errors: { email: ['x'] }, codes: { email: 'email_taken' } });
    register.mockRejectedValueOnce(taken);
    await expect(performSignup(body)).rejects.toBe(taken);
    expect(register).toHaveBeenCalledTimes(1);

    register.mockRejectedValueOnce(timezone422());
    const noTz: RegisterParentRequest = { ...body };
    delete noTz.timezone;
    await expect(performSignup(noTz)).rejects.toBeInstanceOf(ApiError);
    expect(register).toHaveBeenCalledTimes(2);
  });

  it('isTimezoneRejection recognises codes and plain validation errors', () => {
    expect(isTimezoneRejection(timezone422())).toBe(true);
    expect(isTimezoneRejection(new ApiError('x', 422, { message: 'x', errors: { timezone: ['x'] } }))).toBe(true);
    expect(isTimezoneRejection(new ApiError('x', 429, null))).toBe(false);
    expect(isTimezoneRejection(new TypeError('offline'))).toBe(false);
  });
});
