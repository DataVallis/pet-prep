/**
 * M2-10c: native Google / Apple adapters — availability (client IDs, platform, native
 * module present), the Apple nonce (SHA-256 of the raw nonce to Apple, the raw one to the
 * API), cancellation and error mapping.
 */
import { createHash } from 'node:crypto';

import {
  SocialProviderError,
  createAppleNonce,
  googleClientConfig,
  isAppleSignInAvailable,
  isGoogleSignInAvailable,
  canOfferProvider,
  setSocialProbesForTests,
  signInWithApple,
  signInWithGoogle,
} from '@/modules/auth/socialSdk';

jest.mock('@react-native-google-signin/google-signin', () => ({
  GoogleSignin: {
    configure: jest.fn(),
    hasPlayServices: jest.fn(async () => true),
    signOut: jest.fn(async () => null),
    signIn: jest.fn(),
  },
  statusCodes: {
    SIGN_IN_CANCELLED: 'SIGN_IN_CANCELLED',
    IN_PROGRESS: 'IN_PROGRESS',
    PLAY_SERVICES_NOT_AVAILABLE: 'PLAY_SERVICES_NOT_AVAILABLE',
    SIGN_IN_REQUIRED: 'SIGN_IN_REQUIRED',
  },
  isErrorWithCode: (e: unknown) => typeof e === 'object' && e !== null && 'code' in e,
}));

jest.mock('expo-apple-authentication', () => ({
  isAvailableAsync: jest.fn(async () => true),
  signInAsync: jest.fn(),
  AppleAuthenticationScope: { FULL_NAME: 0, EMAIL: 1 },
  AppleAuthenticationButton: () => null,
  AppleAuthenticationButtonType: { SIGN_IN: 0, CONTINUE: 1, SIGN_UP: 2 },
  AppleAuthenticationButtonStyle: { WHITE: 0, WHITE_OUTLINE: 1, BLACK: 2 },
}));

// Real SHA-256 so the test proves the hash, not just a call.
jest.mock('expo-crypto', () => ({
  CryptoDigestAlgorithm: { SHA256: 'SHA-256' },
  CryptoEncoding: { HEX: 'hex', BASE64: 'base64' },
  getRandomBytes: jest.fn((n: number) => Uint8Array.from({ length: n }, (_, i) => i)),
  digestStringAsync: jest.fn(async (_alg: string, data: string) =>
    jest.requireActual<typeof import('node:crypto')>('node:crypto').createHash('sha256').update(data).digest('hex'),
  ),
}));

type GoogleMock = {
  GoogleSignin: { configure: jest.Mock; hasPlayServices: jest.Mock; signOut: jest.Mock; signIn: jest.Mock };
};
type AppleMock = { isAvailableAsync: jest.Mock; signInAsync: jest.Mock };

const google = jest.requireMock<GoogleMock>('@react-native-google-signin/google-signin').GoogleSignin;
const apple = jest.requireMock<AppleMock>('expo-apple-authentication');

const ENV_FULL = {
  GOOGLE_WEB_CLIENT_ID: 'web.apps.googleusercontent.com',
  GOOGLE_IOS_CLIENT_ID: 'ios.apps.googleusercontent.com',
  GOOGLE_IOS_URL_SCHEME: 'com.googleusercontent.apps.ios',
};
const ENV_NONE = { GOOGLE_WEB_CLIENT_ID: '', GOOGLE_IOS_CLIENT_ID: '', GOOGLE_IOS_URL_SCHEME: '' };

const RAW_NONCE = Array.from({ length: 32 }, (_, i) => i.toString(16).padStart(2, '0')).join('');

function present() {
  setSocialProbesForTests({ google: () => true, apple: () => true, crypto: () => true });
}

describe('Google availability', () => {
  afterEach(() => setSocialProbesForTests(null));

  it('Android needs the web client ID only', () => {
    expect(googleClientConfig('android', { ...ENV_NONE, GOOGLE_WEB_CLIENT_ID: 'web' })).toEqual({ webClientId: 'web' });
    expect(googleClientConfig('android', ENV_NONE)).toBeNull();
  });

  it('iOS needs the web ID, the iOS ID and the URL scheme', () => {
    expect(googleClientConfig('ios', ENV_FULL)).toEqual({ webClientId: ENV_FULL.GOOGLE_WEB_CLIENT_ID, iosClientId: ENV_FULL.GOOGLE_IOS_CLIENT_ID });
    expect(googleClientConfig('ios', { ...ENV_FULL, GOOGLE_IOS_CLIENT_ID: '' })).toBeNull();
    expect(googleClientConfig('ios', { ...ENV_FULL, GOOGLE_IOS_URL_SCHEME: ' ' })).toBeNull();
    expect(googleClientConfig('ios', { ...ENV_FULL, GOOGLE_WEB_CLIENT_ID: '' })).toBeNull();
    expect(googleClientConfig('web', ENV_FULL)).toBeNull();
  });

  it('hidden without the native module (Expo Go / an older binary), even when configured', () => {
    setSocialProbesForTests({ google: () => false });
    expect(isGoogleSignInAvailable('ios', ENV_FULL)).toBe(false);
    present();
    expect(isGoogleSignInAvailable('ios', ENV_FULL)).toBe(true);
    expect(isGoogleSignInAvailable('android', ENV_NONE)).toBe(false);
  });
});

describe('Apple availability', () => {
  afterEach(() => setSocialProbesForTests(null));

  it('never on Android', async () => {
    present();
    await expect(isAppleSignInAvailable('android')).resolves.toBe(false);
    expect(apple.isAvailableAsync).not.toHaveBeenCalled();
  });

  it('on iOS when the module exists and the OS supports it', async () => {
    present();
    apple.isAvailableAsync.mockResolvedValueOnce(true);
    await expect(isAppleSignInAvailable('ios')).resolves.toBe(true);
    apple.isAvailableAsync.mockResolvedValueOnce(false);
    await expect(isAppleSignInAvailable('ios')).resolves.toBe(false);
    apple.isAvailableAsync.mockRejectedValueOnce(new Error('boom'));
    await expect(isAppleSignInAvailable('ios')).resolves.toBe(false);
  });

  it('hidden without the native module or without expo-crypto', async () => {
    setSocialProbesForTests({ apple: () => false, crypto: () => true });
    await expect(isAppleSignInAvailable('ios')).resolves.toBe(false);
    setSocialProbesForTests({ apple: () => true, crypto: () => false });
    await expect(isAppleSignInAvailable('ios')).resolves.toBe(false);
  });
});

describe('signInWithApple', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    present();
  });
  afterEach(() => setSocialProbesForTests(null));

  it('gives Apple sha256(raw nonce), the API the raw nonce, and asks for name + e-mail', async () => {
    apple.signInAsync.mockResolvedValueOnce({
      identityToken: 'apple.jwt',
      authorizationCode: 'apple-code',
      fullName: { givenName: ' Ana ', familyName: 'Novak' },
    });

    const result = await signInWithApple('ios');

    const expectedHash = createHash('sha256').update(RAW_NONCE).digest('hex');
    expect(apple.signInAsync).toHaveBeenCalledWith({ requestedScopes: [0, 1], nonce: expectedHash });
    expect(result).toEqual({
      kind: 'credential',
      credential: { provider: 'apple', idToken: 'apple.jwt', nonce: RAW_NONCE, name: 'Ana Novak', authorizationCode: 'apple-code' },
    });
  });

  it('a new nonce for every sign-in (raw is 32 random bytes as hex)', async () => {
    const crypto = jest.requireMock<Parameters<typeof createAppleNonce>[0]>('expo-crypto');
    const nonce = await createAppleNonce(crypto);
    expect(nonce.raw).toMatch(/^[0-9a-f]{64}$/);
    expect(nonce.hashed).toBe(createHash('sha256').update(nonce.raw).digest('hex'));
    expect(crypto.getRandomBytes).toHaveBeenCalledWith(32);
  });

  it('no name when Apple sends none (not the first authorization)', async () => {
    apple.signInAsync.mockResolvedValueOnce({ identityToken: 'apple.jwt', fullName: { givenName: null, familyName: null } });
    const result = await signInWithApple('ios');
    expect(result.kind === 'credential' && result.credential).toEqual({ provider: 'apple', idToken: 'apple.jwt', nonce: RAW_NONCE });
  });

  it('closing the Apple sheet is a silent cancel', async () => {
    apple.signInAsync.mockRejectedValueOnce(Object.assign(new Error('canceled'), { code: 'ERR_REQUEST_CANCELED' }));
    await expect(signInWithApple('ios')).resolves.toEqual({ kind: 'cancelled' });
  });

  it('other failures throw SocialProviderError; no token → no_token; Android → unavailable', async () => {
    apple.signInAsync.mockRejectedValueOnce(Object.assign(new Error('x'), { code: 'ERR_REQUEST_FAILED' }));
    await expect(signInWithApple('ios')).rejects.toMatchObject({ code: 'failed' });
    apple.signInAsync.mockResolvedValueOnce({ identityToken: null, fullName: null });
    await expect(signInWithApple('ios')).rejects.toMatchObject({ code: 'no_token' });
    await expect(signInWithApple('android')).rejects.toBeInstanceOf(SocialProviderError);
  });
});

describe('signInWithGoogle', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    present();
  });
  afterEach(() => setSocialProbesForTests(null));

  const success = (idToken: string | null, user: Partial<Record<'name' | 'givenName' | 'familyName', string | null>> = {}) => ({
    type: 'success',
    data: { idToken, user: { id: '1', email: 'a@b.si', photo: null, name: null, givenName: null, familyName: null, ...user }, scopes: [], serverAuthCode: null },
  });

  it('configures once with the client IDs, drops the cached Google session, returns the ID token', async () => {
    google.signIn.mockResolvedValue(success('google.jwt', { name: 'Ana Novak' }));

    await expect(signInWithGoogle('ios', ENV_FULL)).resolves.toEqual({
      kind: 'credential',
      credential: { provider: 'google', idToken: 'google.jwt', name: 'Ana Novak' },
    });
    await signInWithGoogle('ios', ENV_FULL);

    expect(google.configure).toHaveBeenCalledTimes(1);
    expect(google.configure).toHaveBeenCalledWith({ webClientId: ENV_FULL.GOOGLE_WEB_CLIENT_ID, iosClientId: ENV_FULL.GOOGLE_IOS_CLIENT_ID });
    // Fresh token every time (deletion re-auth needs ≤ 10 min): sign out of Google first.
    expect(google.signOut.mock.invocationCallOrder[0]).toBeLessThan(google.signIn.mock.invocationCallOrder[0]);
    expect(google.hasPlayServices).not.toHaveBeenCalled();
  });

  it('Android checks Play services and maps a missing one', async () => {
    google.signIn.mockResolvedValueOnce(success('g', { givenName: 'Ana', familyName: 'Novak' }));
    const env = { ...ENV_NONE, GOOGLE_WEB_CLIENT_ID: 'web' };
    await expect(signInWithGoogle('android', env)).resolves.toMatchObject({ credential: { idToken: 'g', name: 'Ana Novak' } });
    expect(google.hasPlayServices).toHaveBeenCalledWith({ showPlayServicesUpdateDialog: true });
    expect(google.configure).toHaveBeenCalledWith({ webClientId: 'web' });

    google.hasPlayServices.mockRejectedValueOnce(Object.assign(new Error('no gms'), { code: 'PLAY_SERVICES_NOT_AVAILABLE' }));
    await expect(signInWithGoogle('android', env)).rejects.toMatchObject({ code: 'play_services' });
  });

  it('cancel (response or error code) is silent', async () => {
    google.signIn.mockResolvedValueOnce({ type: 'cancelled', data: null });
    await expect(signInWithGoogle('ios', ENV_FULL)).resolves.toEqual({ kind: 'cancelled' });
    google.signIn.mockRejectedValueOnce(Object.assign(new Error('c'), { code: 'SIGN_IN_CANCELLED' }));
    await expect(signInWithGoogle('ios', ENV_FULL)).resolves.toEqual({ kind: 'cancelled' });
  });

  it('no ID token / not configured / other errors throw', async () => {
    google.signIn.mockResolvedValueOnce(success(null));
    await expect(signInWithGoogle('ios', ENV_FULL)).rejects.toMatchObject({ code: 'no_token' });
    google.signIn.mockRejectedValueOnce(Object.assign(new Error('DEVELOPER_ERROR'), { code: '10' }));
    await expect(signInWithGoogle('ios', ENV_FULL)).rejects.toMatchObject({ code: 'failed' });
    await expect(signInWithGoogle('ios', ENV_NONE)).rejects.toMatchObject({ code: 'unavailable' });
  });
});

describe('canOfferProvider (deletion re-auth buttons)', () => {
  afterEach(() => setSocialProbesForTests(null));

  it('Apple only on iOS with the modules; Google only when configured', () => {
    present();
    expect(canOfferProvider('apple', 'ios')).toBe(true);
    expect(canOfferProvider('apple', 'android')).toBe(false);
    expect(canOfferProvider('google', 'android', { ...ENV_NONE, GOOGLE_WEB_CLIENT_ID: 'web' })).toBe(true);
    expect(canOfferProvider('google', 'ios', ENV_NONE)).toBe(false);
    setSocialProbesForTests({ apple: () => false, crypto: () => true });
    expect(canOfferProvider('apple', 'ios')).toBe(false);
  });
});

