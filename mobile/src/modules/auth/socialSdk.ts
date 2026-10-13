/**
 * Native Google / Apple sign-in (M2-10c) — the only place that touches
 * `@react-native-google-signin/google-signin`, `expo-apple-authentication` and `expo-crypto`.
 *
 * Every module is loaded lazily behind a native probe, so Expo Go and binaries built before
 * M2-10c (no native module) never crash: the provider is simply unavailable and its button
 * hidden. Availability:
 * - Google: native module present AND client IDs configured — Android needs the web client
 *   ID (the ID token's audience), iOS additionally the iOS client ID and its URL scheme
 *   (registered by the config plugin only when that env var is set, see `app.config.ts`).
 * - Apple: iOS only, native module + `expo-crypto` present and `isAvailableAsync()` true.
 *
 * A provider flow answers `{kind: 'credential'}` with the ID token (+ the RAW nonce for
 * Apple, + the name when the provider gave one) or `{kind: 'cancelled'}` when the parent
 * closed the provider UI (the caller then does nothing). Anything else throws
 * `SocialProviderError`.
 *
 * Kids-app rule: these are authentication SDKs only, used on the parent side; no other
 * Google / Firebase SDK is added.
 */

import { Platform, TurboModuleRegistry } from 'react-native';
import { requireOptionalNativeModule } from 'expo';
import type * as GoogleSignInModule from '@react-native-google-signin/google-signin';
import type * as AppleAuthModule from 'expo-apple-authentication';
import type * as CryptoModule from 'expo-crypto';

import { ENV } from '@/config/env';
import type { SocialProvider } from '@/modules/session/signInMethod';

export type { SocialProvider };

/** What a provider sign-in hands to the API (`POST /api/auth/social`, deletion re-auth). */
export interface ProviderCredential {
  provider: SocialProvider;
  idToken: string;
  /** Apple: the RAW nonce (its SHA-256 went to Apple). */
  nonce?: string;
  /** Display name, when the provider returned one (Apple only on the first authorization). */
  name?: string;
  /** Apple: the one-time authorization code (deletion re-auth sends it; never log it). */
  authorizationCode?: string;
}

export type ProviderResult = { kind: 'credential'; credential: ProviderCredential } | { kind: 'cancelled' };

export type SocialProviderErrorCode =
  /** The provider can't run here (module missing, not configured, not iOS…). */
  | 'unavailable'
  /** Android: Google Play services missing or outdated. */
  | 'play_services'
  /** The provider answered without an ID token. */
  | 'no_token'
  /** Any other native failure (network, misconfiguration). */
  | 'failed';

export class SocialProviderError extends Error {
  constructor(
    public readonly code: SocialProviderErrorCode,
    message: string = code,
  ) {
    super(message);
    this.name = 'SocialProviderError';
  }
}

// ──────────────────────────────────────────────────────────────
//  Lazy native modules
// ──────────────────────────────────────────────────────────────

type GoogleSdk = Pick<typeof GoogleSignInModule, 'GoogleSignin' | 'statusCodes' | 'isErrorWithCode'>;
type AppleSdk = Pick<
  typeof AppleAuthModule,
  'isAvailableAsync' | 'signInAsync' | 'AppleAuthenticationScope' | 'AppleAuthenticationButton' | 'AppleAuthenticationButtonType' | 'AppleAuthenticationButtonStyle'
>;
type CryptoSdk = Pick<typeof CryptoModule, 'getRandomBytes' | 'digestStringAsync' | 'CryptoDigestAlgorithm' | 'CryptoEncoding'>;

export interface NativeProbes {
  google: () => boolean;
  apple: () => boolean;
  crypto: () => boolean;
}

const defaultProbes: NativeProbes = {
  google: () => TurboModuleRegistry.get('RNGoogleSignin') != null,
  apple: () => requireOptionalNativeModule('ExpoAppleAuthentication') != null,
  crypto: () => requireOptionalNativeModule('ExpoCrypto') != null,
};

let probes: NativeProbes = defaultProbes;
let googleSdk: GoogleSdk | null | undefined;
let appleSdk: AppleSdk | null | undefined;
let cryptoSdk: CryptoSdk | null | undefined;
let googleConfiguredWith: string | null = null;

/** Tests: pretend the native modules exist (or not). `null` restores the real probes. */
export function setSocialProbesForTests(next: Partial<NativeProbes> | null): void {
  probes = next ? { ...defaultProbes, ...next } : defaultProbes;
  googleSdk = undefined;
  appleSdk = undefined;
  cryptoSdk = undefined;
  googleConfiguredWith = null;
}

function load<T>(probe: () => boolean, requireIt: () => T): T | null {
  try {
    return probe() ? requireIt() : null;
  } catch {
    return null;
  }
}

function loadGoogle(): GoogleSdk | null {
  if (googleSdk === undefined) {
    googleSdk = load(probes.google, () => require('@react-native-google-signin/google-signin') as typeof GoogleSignInModule);
  }
  return googleSdk;
}

function loadApple(): AppleSdk | null {
  if (appleSdk === undefined) {
    appleSdk = load(probes.apple, () => require('expo-apple-authentication') as typeof AppleAuthModule);
  }
  return appleSdk;
}

function loadCrypto(): CryptoSdk | null {
  if (cryptoSdk === undefined) {
    cryptoSdk = load(probes.crypto, () => require('expo-crypto') as typeof CryptoModule);
  }
  return cryptoSdk;
}

/** The official Apple button component, or null without the native module (never render it then). */
export function appleButtonSdk(): Pick<AppleSdk, 'AppleAuthenticationButton' | 'AppleAuthenticationButtonType' | 'AppleAuthenticationButtonStyle'> | null {
  return loadApple();
}

// ──────────────────────────────────────────────────────────────
//  Availability
// ──────────────────────────────────────────────────────────────

type GoogleEnv = Pick<typeof ENV, 'GOOGLE_WEB_CLIENT_ID' | 'GOOGLE_IOS_CLIENT_ID' | 'GOOGLE_IOS_URL_SCHEME'>;

export interface GoogleClientConfig {
  webClientId: string;
  iosClientId?: string;
}

/**
 * Client IDs for `GoogleSignin.configure`, or null when this platform lacks one it needs
 * (→ no Google button). iOS without the URL scheme would crash inside the SDK, so it counts
 * as not configured.
 */
export function googleClientConfig(platform: string = Platform.OS, env: GoogleEnv = ENV): GoogleClientConfig | null {
  const web = env.GOOGLE_WEB_CLIENT_ID.trim();
  if (!web) return null;
  if (platform === 'android') return { webClientId: web };
  if (platform === 'ios') {
    const ios = env.GOOGLE_IOS_CLIENT_ID.trim();
    const scheme = env.GOOGLE_IOS_URL_SCHEME.trim();
    if (!ios || !scheme) return null;
    return { webClientId: web, iosClientId: ios };
  }
  return null;
}

/** Show "Continue with Google"? (configured for this platform + native module in this binary). */
export function isGoogleSignInAvailable(platform: string = Platform.OS, env: GoogleEnv = ENV): boolean {
  return googleClientConfig(platform, env) !== null && loadGoogle() !== null;
}

/**
 * Can this device run the provider's sign-in right now (sync — for choosing re-auth buttons)?
 * Google: configured + module; Apple: iOS + module + expo-crypto (the OS check is async and
 * true on every iOS version the app supports).
 */
export function canOfferProvider(provider: SocialProvider, platform: string = Platform.OS, env: GoogleEnv = ENV): boolean {
  if (provider === 'google') return isGoogleSignInAvailable(platform, env);
  return platform === 'ios' && loadApple() !== null && loadCrypto() !== null;
}

/** Show the Apple button? iOS only, module present, and the OS supports it. Never throws. */
export async function isAppleSignInAvailable(platform: string = Platform.OS): Promise<boolean> {
  if (platform !== 'ios') return false;
  const apple = loadApple();
  if (!apple || !loadCrypto()) return false;
  try {
    return await apple.isAvailableAsync();
  } catch {
    return false;
  }
}

// ──────────────────────────────────────────────────────────────
//  Flows
// ──────────────────────────────────────────────────────────────

function cleanName(...parts: (string | null | undefined)[]): string | undefined {
  const name = parts
    .filter((p): p is string => typeof p === 'string')
    .map((p) => p.trim())
    .filter((p) => p.length > 0)
    .join(' ');
  return name.length > 0 ? name : undefined;
}

/**
 * Google: configure once, (Android) check Play services, drop any cached Google session so
 * the account picker shows and the ID token is freshly issued (the API needs ≤ 10 min for a
 * deletion re-auth), then sign in. The token's audience is the web client ID.
 */
export async function signInWithGoogle(platform: string = Platform.OS, env: GoogleEnv = ENV): Promise<ProviderResult> {
  const config = googleClientConfig(platform, env);
  const sdk = loadGoogle();
  if (!config || !sdk) throw new SocialProviderError('unavailable');
  const { GoogleSignin, statusCodes, isErrorWithCode } = sdk;

  const key = `${config.webClientId}|${config.iosClientId ?? ''}`;
  if (googleConfiguredWith !== key) {
    GoogleSignin.configure(config);
    googleConfiguredWith = key;
  }

  try {
    if (platform === 'android') await GoogleSignin.hasPlayServices({ showPlayServicesUpdateDialog: true });
    try {
      await GoogleSignin.signOut();
    } catch {
      // Nobody was signed in to Google in this app — fine.
    }
    const response = await GoogleSignin.signIn();
    if (response.type === 'cancelled') return { kind: 'cancelled' };
    const { idToken, user } = response.data;
    if (!idToken) throw new SocialProviderError('no_token');
    const name = user.name?.trim() ? user.name.trim() : cleanName(user.givenName, user.familyName);
    return { kind: 'credential', credential: { provider: 'google', idToken, ...(name ? { name } : {}) } };
  } catch (err) {
    if (err instanceof SocialProviderError) throw err;
    if (isErrorWithCode(err)) {
      // IN_PROGRESS = a second tap while the sheet is open: the first flow answers.
      if (err.code === statusCodes.SIGN_IN_CANCELLED || err.code === statusCodes.IN_PROGRESS) return { kind: 'cancelled' };
      if (err.code === statusCodes.PLAY_SERVICES_NOT_AVAILABLE) throw new SocialProviderError('play_services', err.message);
    }
    throw new SocialProviderError('failed', err instanceof Error ? err.message : String(err));
  }
}

function toHex(bytes: Uint8Array): string {
  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
}

/** A fresh raw nonce (32 random bytes, hex) and its SHA-256 (hex) — the hash goes to Apple. */
export async function createAppleNonce(crypto: CryptoSdk): Promise<{ raw: string; hashed: string }> {
  const raw = toHex(crypto.getRandomBytes(32));
  const hashed = await crypto.digestStringAsync(crypto.CryptoDigestAlgorithm.SHA256, raw, {
    encoding: crypto.CryptoEncoding.HEX,
  });
  return { raw, hashed };
}

/**
 * Apple: a new nonce per sign-in; Apple gets `sha256(raw)` and puts it into the token, the
 * API gets the RAW nonce and checks it. Asks for the name + e-mail (Apple gives the name
 * only on the first authorization — sent along when present).
 */
export async function signInWithApple(platform: string = Platform.OS): Promise<ProviderResult> {
  const apple = platform === 'ios' ? loadApple() : null;
  const crypto = loadCrypto();
  if (!apple || !crypto) throw new SocialProviderError('unavailable');

  try {
    const nonce = await createAppleNonce(crypto);
    const credential = await apple.signInAsync({
      requestedScopes: [apple.AppleAuthenticationScope.FULL_NAME, apple.AppleAuthenticationScope.EMAIL],
      nonce: nonce.hashed,
    });
    if (!credential.identityToken) throw new SocialProviderError('no_token');
    const name = cleanName(credential.fullName?.givenName, credential.fullName?.familyName);
    const code = credential.authorizationCode;
    return {
      kind: 'credential',
      credential: {
        provider: 'apple',
        idToken: credential.identityToken,
        nonce: nonce.raw,
        ...(name ? { name } : {}),
        ...(code ? { authorizationCode: code } : {}),
      },
    };
  } catch (err) {
    if (err instanceof SocialProviderError) throw err;
    const code = typeof err === 'object' && err !== null && 'code' in err ? (err as { code: unknown }).code : null;
    if (code === 'ERR_REQUEST_CANCELED') return { kind: 'cancelled' };
    throw new SocialProviderError('failed', err instanceof Error ? err.message : String(err));
  }
}

/** Run the provider's native sign-in. */
export function obtainProviderCredential(provider: SocialProvider): Promise<ProviderResult> {
  return provider === 'google' ? signInWithGoogle() : signInWithApple();
}
