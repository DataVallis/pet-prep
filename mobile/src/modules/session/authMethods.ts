/**
 * How the signed-in parent's account can prove its identity (M2-10c, QA m1): the server
 * says it — `has_password` and `linked_providers` on the user of `/api/login`,
 * `/api/register`, `/api/auth/social` and `GET /api/user`. The irreversible-deletion forms
 * use it to choose between the password field and a provider re-sign-in, and WHICH
 * provider(s) to offer (only linked ones this device can run; Apple only on iOS).
 *
 * The device-local `signInMethod` (SecureStore) is only the fallback for a session whose
 * user payload lacks the fields (older server / cached state).
 */

import { isSocialProvider, type SignInMethod, type SocialProvider } from './signInMethod';

export interface AuthMethods {
  hasPassword: boolean;
  /** Linked providers, Google first, without duplicates. */
  linkedProviders: SocialProvider[];
}

const PROVIDER_ORDER: readonly SocialProvider[] = ['google', 'apple'];

/** The server's fields → `AuthMethods`; null when they are missing or malformed. */
export function readAuthMethods(user: { has_password?: unknown; linked_providers?: unknown } | null | undefined): AuthMethods | null {
  if (!user || typeof user.has_password !== 'boolean' || !Array.isArray(user.linked_providers)) return null;
  const linked = new Set(user.linked_providers.filter(isSocialProvider));
  return { hasPassword: user.has_password, linkedProviders: PROVIDER_ORDER.filter((p) => linked.has(p)) };
}

export type DeletionIdentity =
  | { kind: 'password' }
  /** Re-sign-in with one of these (at least one). */
  | { kind: 'providers'; providers: SocialProvider[] }
  /** No password and none of the linked providers runs on this device (e.g. Apple-only on Android). */
  | { kind: 'unavailable' };

/**
 * What the deletion form asks for. Server fields win; without them the remembered sign-in
 * method decides (password, or the provider used on this device).
 */
export function deletionIdentity(
  methods: AuthMethods | null,
  fallback: SignInMethod,
  canOffer: (provider: SocialProvider) => boolean,
): DeletionIdentity {
  if (methods) {
    if (methods.hasPassword) return { kind: 'password' };
    if (methods.linkedProviders.length > 0) {
      const providers = methods.linkedProviders.filter(canOffer);
      return providers.length > 0 ? { kind: 'providers', providers } : { kind: 'unavailable' };
    }
    // Neither a password nor a provider (should not exist): use what this device knows.
  }
  if (!isSocialProvider(fallback)) return { kind: 'password' };
  return canOffer(fallback) ? { kind: 'providers', providers: [fallback] } : { kind: 'unavailable' };
}
