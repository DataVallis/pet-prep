/**
 * How the parent signed in on this device (M2-10c): `password` (e-mail sign-in / sign-up)
 * or the social provider (`google` | `apple`).
 *
 * Why the app keeps this itself: `GET /api/user` does not say whether the account has a
 * password, but irreversible deletions need it — a parent without a password must confirm
 * with a fresh provider token instead (`ConfirmDeletionRequest`). The method used to sign
 * in is exact for that question:
 * - a social sign-in only succeeds for an account WITHOUT a password (an e-mail that has a
 *   password answers 409 `email_in_use`, never linked automatically), and every provider
 *   it links is one the parent can re-authenticate with;
 * - an e-mail sign-in only succeeds for an account WITH a password.
 *
 * Stored next to the token in SecureStore (written at sign-in, read on session restore,
 * deleted on logout). Missing / unreadable → `password` (sessions from before M2-10c were
 * all e-mail sign-ins).
 */

import * as SecureStore from 'expo-secure-store';

export type SocialProvider = 'google' | 'apple';
export type SignInMethod = 'password' | SocialProvider;

export const SIGN_IN_METHOD_KEY = 'petprep_sign_in_method';

type Store = Pick<typeof SecureStore, 'getItemAsync' | 'setItemAsync' | 'deleteItemAsync'>;

export function isSocialProvider(value: unknown): value is SocialProvider {
  return value === 'google' || value === 'apple';
}

/** Remember the method after a successful sign-in (password = no entry). Never throws. */
export async function saveSignInMethod(method: SignInMethod, store: Store = SecureStore): Promise<void> {
  try {
    if (method === 'password') await store.deleteItemAsync(SIGN_IN_METHOD_KEY);
    else await store.setItemAsync(SIGN_IN_METHOD_KEY, method);
  } catch {
    // Keychain unavailable: the deletion dialog then asks for a password, the server refuses
    // with `invalid_password`; signing in again fixes it. Not worth failing the sign-in.
  }
}

/** The stored method; anything unknown → `password`. Never throws. */
export async function loadSignInMethod(store: Store = SecureStore): Promise<SignInMethod> {
  try {
    const value = await store.getItemAsync(SIGN_IN_METHOD_KEY);
    return isSocialProvider(value) ? value : 'password';
  } catch {
    return 'password';
  }
}

/** Logout: forget it. Never throws. */
export async function forgetSignInMethod(store: Store = SecureStore): Promise<void> {
  try {
    await store.deleteItemAsync(SIGN_IN_METHOD_KEY);
  } catch {
    // Nothing more to do.
  }
}
