/**
 * M2-10c: Google / Apple sign-in flow — the session is stored like a login (token +
 * sign-in method), `terms_required` keeps the credential and retries with the SAME token
 * and `terms_accepted: true`, refusals map to friendly kinds, a cancelled provider UI is
 * silent.
 */
import * as SecureStore from 'expo-secure-store';

import { ApiError, api, type SocialSignInRequest, type SocialSignInResponse } from '@/api/client';
import {
  FALLBACK_TIMEZONE,
  completeSocialSignUp,
  mapSocialError,
  socialSignInBody,
  startSocialSignIn,
  type SocialSignInDeps,
} from '@/modules/auth/socialSignIn';
import { SocialProviderError, obtainProviderCredential, type ProviderCredential } from '@/modules/auth/socialSdk';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, socialSignIn: jest.fn(), logout: jest.fn() } };
});

jest.mock('@/modules/auth/socialSdk', () => ({
  ...jest.requireActual<typeof import('@/modules/auth/socialSdk')>('@/modules/auth/socialSdk'),
  obtainProviderCredential: jest.fn(),
}));

const socialSignIn = api.socialSignIn as jest.Mock;
const obtain = obtainProviderCredential as jest.Mock;
const setItem = SecureStore.setItemAsync as jest.Mock;

const RESPONSE: SocialSignInResponse = {
  token: 'tok-social',
  user: { id: 9, name: 'Ana Novak', email: 'ana@example.com', role: 'parent' },
  pet: null,
  awaiting_contract: null,
  created: false,
};

const GOOGLE: ProviderCredential = { provider: 'google', idToken: 'google.jwt', name: 'Ana Novak' };
const APPLE: ProviderCredential = { provider: 'apple', idToken: 'apple.jwt', nonce: 'raw-nonce-hex' };

const refusal = (status: number, code: string) => new ApiError(code, status, { message: code, code });

function deps(overrides: Partial<SocialSignInDeps> = {}): SocialSignInDeps {
  return {
    obtain: jest.fn(async () => ({ kind: 'credential' as const, credential: GOOGLE })),
    request: jest.fn(async () => RESPONSE),
    saveToken: jest.fn(async () => undefined),
    saveMethod: jest.fn(async () => undefined),
    signOut: jest.fn(async () => undefined),
    timezone: () => 'Europe/Ljubljana',
    device: () => 'iPhone',
    ...overrides,
  };
}

describe('socialSignInBody', () => {
  it('Apple: the RAW nonce goes to the server; no terms flag until accepted', () => {
    expect(socialSignInBody(APPLE, { termsAccepted: false, timezone: 'Europe/Vienna', deviceName: 'iPhone' })).toEqual({
      provider: 'apple',
      id_token: 'apple.jwt',
      nonce: 'raw-nonce-hex',
      timezone: 'Europe/Vienna',
      device_name: 'iPhone',
    });
  });

  it('Google: name sent when known, terms_accepted when accepted, server default zone without one', () => {
    expect(socialSignInBody(GOOGLE, { termsAccepted: true, timezone: undefined, deviceName: 'Pixel 8' })).toEqual({
      provider: 'google',
      id_token: 'google.jwt',
      name: 'Ana Novak',
      timezone: FALLBACK_TIMEZONE,
      terms_accepted: true,
      device_name: 'Pixel 8',
    });
  });
});

describe('startSocialSignIn — real storage path', () => {
  beforeEach(() => jest.clearAllMocks());

  it('success stores the token and the provider, and returns the session', async () => {
    obtain.mockResolvedValueOnce({ kind: 'credential', credential: APPLE });
    socialSignIn.mockResolvedValueOnce({ ...RESPONSE, created: true });

    const outcome = await startSocialSignIn('apple');

    expect(outcome).toEqual({
      kind: 'signed_in',
      created: true,
      session: { token: 'tok-social', user: RESPONSE.user, pet: null, awaitingContract: null, signInMethod: 'apple' },
    });
    expect(setItem).toHaveBeenCalledWith('petprep_auth_token', 'tok-social');
    expect(setItem).toHaveBeenCalledWith('petprep_sign_in_method', 'apple');
    expect(socialSignIn).toHaveBeenCalledWith(expect.objectContaining({ provider: 'apple', id_token: 'apple.jwt', nonce: 'raw-nonce-hex' }));
  });
});

describe('startSocialSignIn / completeSocialSignUp', () => {
  it('terms_required keeps the credential; accepting retries with the SAME token + terms_accepted, provider UI once', async () => {
    const request = jest
      .fn<Promise<SocialSignInResponse>, [SocialSignInRequest]>()
      .mockRejectedValueOnce(refusal(422, 'terms_required'))
      .mockResolvedValueOnce({ ...RESPONSE, created: true });
    const d = deps({ request });

    const first = await startSocialSignIn('google', d);
    expect(first).toEqual({ kind: 'terms_required', credential: GOOGLE });
    expect(d.saveToken).not.toHaveBeenCalled();

    const second = await completeSocialSignUp(GOOGLE, d);
    expect(second).toMatchObject({ kind: 'signed_in', created: true, session: { signInMethod: 'google' } });

    expect(d.obtain).toHaveBeenCalledTimes(1);
    expect(request).toHaveBeenCalledTimes(2);
    expect(request.mock.calls[0][0]).not.toHaveProperty('terms_accepted');
    expect(request.mock.calls[1][0]).toMatchObject({ id_token: 'google.jwt', terms_accepted: true });
    expect(d.saveToken).toHaveBeenCalledWith('tok-social');
    expect(d.saveMethod).toHaveBeenCalledWith('google');
  });

  it('cancelling the provider UI does nothing (no request)', async () => {
    const d = deps({ obtain: jest.fn(async () => ({ kind: 'cancelled' as const })) });
    await expect(startSocialSignIn('google', d)).resolves.toEqual({ kind: 'cancelled' });
    expect(d.request).not.toHaveBeenCalled();
  });

  it('email_in_use → its own message kind, nothing stored', async () => {
    const d = deps({ request: jest.fn(async () => Promise.reject(refusal(409, 'email_in_use'))) });
    await expect(startSocialSignIn('google', d)).resolves.toEqual({ kind: 'error', error: 'email_in_use' });
    expect(d.saveToken).not.toHaveBeenCalled();
    expect(d.saveMethod).not.toHaveBeenCalled();
  });

  it('a second terms_required after accepting is an error, not a loop', async () => {
    const d = deps({ request: jest.fn(async () => Promise.reject(refusal(422, 'terms_required'))) });
    await expect(completeSocialSignUp(GOOGLE, d)).resolves.toEqual({ kind: 'error', error: 'failed' });
  });

  it('provider failures map (Play services) and never throw', async () => {
    const d = deps({ obtain: jest.fn(async () => Promise.reject(new SocialProviderError('play_services'))) });
    await expect(startSocialSignIn('google', d)).resolves.toEqual({ kind: 'error', error: 'play_services' });
  });

  it('an unusable device zone is retried once with the server default', async () => {
    const zone422 = new ApiError('bad', 422, { message: 'bad', errors: { timezone: ['The timezone field must be a valid timezone.'] } });
    const request = jest.fn<Promise<SocialSignInResponse>, [SocialSignInRequest]>().mockRejectedValueOnce(zone422).mockResolvedValueOnce(RESPONSE);
    const d = deps({ request, timezone: () => 'Mars/Olympus' });
    await expect(startSocialSignIn('google', d)).resolves.toMatchObject({ kind: 'signed_in' });
    expect(request.mock.calls[1][0].timezone).toBe(FALLBACK_TIMEZONE);
  });

  it('never routes a non-parent: signs out and reports not_a_parent', async () => {
    const d = deps({ request: jest.fn(async () => ({ ...RESPONSE, user: { ...RESPONSE.user, role: 'child' as const } })) });
    await expect(startSocialSignIn('google', d)).resolves.toEqual({ kind: 'error', error: 'not_a_parent' });
    expect(d.signOut).toHaveBeenCalled();
    expect(d.saveMethod).not.toHaveBeenCalled();
  });
});

describe('mapSocialError', () => {
  it.each<[string, unknown, string]>([
    ['401 invalid_token', refusal(401, 'invalid_token'), 'invalid_token'],
    ['403 not_a_parent', refusal(403, 'not_a_parent'), 'not_a_parent'],
    ['403 superadmin_protected', refusal(403, 'superadmin_protected'), 'protected'],
    ['409 email_in_use', refusal(409, 'email_in_use'), 'email_in_use'],
    ['409 email_link_unavailable', refusal(409, 'email_link_unavailable'), 'email_link_unavailable'],
    ['503 provider_disabled', refusal(503, 'provider_disabled'), 'provider_disabled'],
    ['503 provider_unavailable', refusal(503, 'provider_unavailable'), 'provider_unavailable'],
    ['429 throttle', new ApiError('Too Many Attempts.', 429, { message: 'Too Many Attempts.' }), 'throttled'],
    ['500', new ApiError('Server Error', 500, null), 'failed'],
    ['no answer', new TypeError('Network request failed'), 'offline'],
    ['provider failure', new SocialProviderError('failed'), 'failed'],
  ])('%s', (_label, err, kind) => {
    expect(mapSocialError(err)).toBe(kind);
  });
});
