/**
 * Parent sign-in / sign-up with Google or Apple (M2-10c): provider credential → `POST
 * /api/auth/social` → the same session a password login produces (token in SecureStore,
 * `appStore.signIn()` payload), plus the sign-in method remembered for deletions
 * (`modules/session/signInMethod.ts`).
 *
 * `terms_required` (422): no PetPrep account exists for this identity yet. The flow returns
 * the credential so the screen can show the terms + privacy consent and call
 * `completeSocialSignUp` with the SAME ID token and `terms_accepted: true` — the provider UI
 * is not run again. Cancelling the provider UI is silent (`cancelled`).
 */

import { ApiError, api, saveAuthToken, type SocialSignInErrorCode, type SocialSignInRequest, type SocialSignInResponse } from '@/api/client';
import { deviceName } from '@/modules/pairing/deviceName';
import { logout } from '@/modules/session/logout';
import { saveSignInMethod } from '@/modules/session/signInMethod';
import type { SignInPayload } from '@/store/appStore';
import { strings } from '@/i18n/strings';
import { deviceTimezone } from './signup';
import { SocialProviderError, obtainProviderCredential, type ProviderCredential, type ProviderResult, type SocialProvider } from './socialSdk';

/** All user-visible strings of social sign-in (`auth:social`). */
export const SOCIAL_STRINGS = strings('auth', 'social');

/** The server's default family zone (as at `POST /api/register` without a zone). */
export const FALLBACK_TIMEZONE = 'Europe/Ljubljana';

export type SocialErrorKind =
  | 'email_in_use'
  | 'email_link_unavailable'
  | 'not_a_parent'
  | 'protected'
  | 'invalid_token'
  | 'provider_disabled'
  | 'provider_unavailable'
  | 'throttled'
  | 'play_services'
  | 'offline'
  | 'failed';

export type SocialSignInOutcome =
  | { kind: 'signed_in'; session: SignInPayload; created: boolean }
  /** No account yet: show the terms, then `completeSocialSignUp(credential)`. */
  | { kind: 'terms_required'; credential: ProviderCredential }
  | { kind: 'cancelled' }
  | { kind: 'error'; error: SocialErrorKind };

export interface SocialSignInDeps {
  obtain: (provider: SocialProvider) => Promise<ProviderResult>;
  request: (body: SocialSignInRequest) => Promise<SocialSignInResponse>;
  saveToken: (token: string) => Promise<void>;
  saveMethod: (provider: SocialProvider) => Promise<void>;
  signOut: () => Promise<void>;
  timezone: () => string | undefined;
  device: () => string;
}

const defaultDeps: SocialSignInDeps = {
  obtain: obtainProviderCredential,
  request: (body) => api.socialSignIn(body),
  saveToken: saveAuthToken,
  saveMethod: saveSignInMethod,
  signOut: () => logout(),
  timezone: deviceTimezone,
  device: deviceName,
};

/** `POST /api/auth/social` body for a credential. */
export function socialSignInBody(
  credential: ProviderCredential,
  options: { termsAccepted: boolean; timezone: string | undefined; deviceName: string },
): SocialSignInRequest {
  return {
    provider: credential.provider,
    id_token: credential.idToken,
    ...(credential.nonce ? { nonce: credential.nonce } : {}),
    ...(credential.name ? { name: credential.name } : {}),
    timezone: options.timezone ?? FALLBACK_TIMEZONE,
    ...(options.termsAccepted ? { terms_accepted: true } : {}),
    device_name: options.deviceName,
  };
}

function codeOf(err: ApiError): SocialSignInErrorCode | null {
  const data = err.data;
  if (typeof data === 'object' && data !== null && 'code' in data) {
    const { code } = data as { code: unknown };
    if (typeof code === 'string') return code as SocialSignInErrorCode;
  }
  return null;
}

function hasTimezoneError(err: unknown): boolean {
  if (!(err instanceof ApiError) || err.status !== 422) return false;
  const data = err.data;
  if (typeof data !== 'object' || data === null || !('errors' in data)) return false;
  const { errors } = data as { errors: unknown };
  return typeof errors === 'object' && errors !== null && 'timezone' in errors;
}

/** True when the server answered 422 `terms_required` (no account yet). */
export function isTermsRequired(err: unknown): boolean {
  return err instanceof ApiError && err.status === 422 && codeOf(err) === 'terms_required';
}

/** A failed provider or API step → what the screen shows (never for `terms_required`). */
export function mapSocialError(err: unknown): SocialErrorKind {
  if (err instanceof SocialProviderError) return err.code === 'play_services' ? 'play_services' : 'failed';
  if (!(err instanceof ApiError)) return 'offline';
  if (err.status === 429) return 'throttled';
  switch (codeOf(err)) {
    case 'email_in_use':
      return 'email_in_use';
    case 'email_link_unavailable':
      return 'email_link_unavailable';
    case 'not_a_parent':
      return 'not_a_parent';
    case 'superadmin_protected':
      return 'protected';
    case 'invalid_token':
      return 'invalid_token';
    case 'provider_disabled':
      return 'provider_disabled';
    case 'provider_unavailable':
      return 'provider_unavailable';
    default:
      return 'failed';
  }
}

/**
 * Send the credential and store the session like a login. Throws the API error (map it with
 * `mapSocialError`, or check `isTermsRequired`). An unusable device zone is retried once with
 * the server default (the family's zone can be changed in settings).
 */
export async function performSocialSignIn(
  credential: ProviderCredential,
  termsAccepted: boolean,
  deps: SocialSignInDeps = defaultDeps,
): Promise<{ session: SignInPayload; created: boolean }> {
  const body = socialSignInBody(credential, { termsAccepted, timezone: deps.timezone(), deviceName: deps.device() });
  let response: SocialSignInResponse;
  try {
    response = await deps.request(body);
  } catch (err) {
    if (body.timezone === FALLBACK_TIMEZONE || !hasTimezoneError(err)) throw err;
    response = await deps.request({ ...body, timezone: FALLBACK_TIMEZONE });
  }
  await deps.saveToken(response.token);
  if (response.user.role !== 'parent') {
    // Never happens by contract (children use the PIN) — but never route a child here.
    await deps.signOut();
    throw new ApiError('Not a parent account.', 403, { code: 'not_a_parent' });
  }
  await deps.saveMethod(credential.provider);
  return {
    session: {
      token: response.token,
      user: response.user,
      pet: response.pet,
      awaitingContract: response.awaiting_contract,
      signInMethod: credential.provider,
    },
    created: response.created === true,
  };
}

async function settle(credential: ProviderCredential, termsAccepted: boolean, deps: SocialSignInDeps): Promise<SocialSignInOutcome> {
  try {
    const { session, created } = await performSocialSignIn(credential, termsAccepted, deps);
    return { kind: 'signed_in', session, created };
  } catch (err) {
    if (!termsAccepted && isTermsRequired(err)) return { kind: 'terms_required', credential };
    return { kind: 'error', error: mapSocialError(err) };
  }
}

/** "Nadaljuj z Googlom" / the Apple button: provider UI, then the API. Never throws. */
export async function startSocialSignIn(provider: SocialProvider, deps: SocialSignInDeps = defaultDeps): Promise<SocialSignInOutcome> {
  let result: ProviderResult;
  try {
    result = await deps.obtain(provider);
  } catch (err) {
    return { kind: 'error', error: mapSocialError(err) };
  }
  if (result.kind === 'cancelled') return { kind: 'cancelled' };
  return settle(result.credential, false, deps);
}

/** After the parent accepted the terms: the same ID token again, with `terms_accepted: true`. Never throws. */
export function completeSocialSignUp(credential: ProviderCredential, deps: SocialSignInDeps = defaultDeps): Promise<SocialSignInOutcome> {
  return settle(credential, true, deps);
}
