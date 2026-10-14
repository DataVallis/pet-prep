/**
 * Regression (David's iPhone, 2026-10-14): a parent signed in with Apple could not delete a
 * child — »Vaš račun se prijavlja na način, ki ga ta telefon ne podpira«. Root cause:
 * `linkedProviders.filter(canOffer)` passed Array.filter's (value, INDEX, array) into
 * `canOfferProvider(provider, platform = Platform.OS, env = ENV)`, so `platform` became 0
 * (never 'ios' → Apple refused) and `env` the array (Google → TypeError). It only showed once
 * the server sent `linked_providers`; without them the fallback called `canOffer(p)` correctly.
 */
import { canOfferForDeletion, canOfferProvider, setSocialProbesForTests } from '@/modules/auth/socialSdk';
import { deletionIdentity } from '@/modules/session/authMethods';

jest.mock('expo-apple-authentication', () => ({ isAvailableAsync: jest.fn(async () => true), AppleAuthenticationScope: {} }));
jest.mock('expo-crypto', () => ({ getRandomBytes: jest.fn(), digestStringAsync: jest.fn(), CryptoDigestAlgorithm: {}, CryptoEncoding: {} }));

describe('deletionIdentity with the real provider check (iOS, Apple-only account)', () => {
  beforeEach(() => setSocialProbesForTests({ apple: () => true, crypto: () => true, google: () => false }));
  afterAll(() => setSocialProbesForTests(null));

  it('offers Apple re-auth for an Apple-only account on iOS (was: "unavailable")', () => {
    expect(deletionIdentity({ hasPassword: false, linkedProviders: ['apple'] }, 'password', canOfferProvider)).toEqual({
      kind: 'providers',
      providers: ['apple'],
    });
  });

  it('Google + Apple linked, Google not configured on this build: Apple offered, no TypeError', () => {
    expect(deletionIdentity({ hasPassword: false, linkedProviders: ['google', 'apple'] }, 'password', canOfferProvider)).toEqual({
      kind: 'providers',
      providers: ['apple'],
    });
  });

  it('canOfferForDeletion: Apple on iOS whenever the server says it is linked (the probe is not a reason to refuse)', () => {
    setSocialProbesForTests({ apple: () => false, crypto: () => false });
    expect(canOfferForDeletion('apple', 'ios')).toBe(true);
    expect(canOfferForDeletion('apple', 'android')).toBe(false);
  });
});
