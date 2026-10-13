/**
 * M2-10c: the parent login and sign-up screens show Google / Apple only when this device can
 * use them — missing client IDs or a binary without the native module hide the buttons
 * (the e-mail form stays as before); Apple never on Android.
 */
import { render, screen, waitFor } from '@testing-library/react-native';

import { setSocialProbesForTests } from '@/modules/auth/socialSdk';
import { SOCIAL_STRINGS } from '@/modules/auth/socialSignIn';
import ParentLoginScreen from '@/screens/ParentLoginScreen';
import ParentSignupScreen from '@/screens/ParentSignupScreen';

jest.mock('expo-apple-authentication', () => ({
  isAvailableAsync: jest.fn(async () => true),
  signInAsync: jest.fn(),
  AppleAuthenticationScope: { FULL_NAME: 0, EMAIL: 1 },
  AppleAuthenticationButton: () => null,
  AppleAuthenticationButtonType: { SIGN_IN: 0, CONTINUE: 1, SIGN_UP: 2 },
  AppleAuthenticationButtonStyle: { WHITE: 0, WHITE_OUTLINE: 1, BLACK: 2 },
}));

jest.mock('@react-native-google-signin/google-signin', () => ({
  GoogleSignin: { configure: jest.fn(), signIn: jest.fn(), signOut: jest.fn(), hasPlayServices: jest.fn() },
  statusCodes: {},
  isErrorWithCode: () => false,
}));

jest.mock('expo-crypto', () => ({ getRandomBytes: jest.fn(), digestStringAsync: jest.fn(), CryptoDigestAlgorithm: {}, CryptoEncoding: {} }));

describe('parent auth screens — social buttons', () => {
  afterEach(() => setSocialProbesForTests(null));

  it('no Google client IDs in this build (Jest env has none) and no native modules → only the e-mail form', async () => {
    setSocialProbesForTests({ google: () => false, apple: () => false, crypto: () => false });
    render(<ParentLoginScreen onBack={jest.fn()} />);
    await waitFor(() => expect(screen.queryByTestId('parent-login-social')).toBeNull());
    expect(screen.queryByText(SOCIAL_STRINGS.google)).toBeNull();
  });

  it('native Google module present but client IDs missing → still no Google button', async () => {
    setSocialProbesForTests({ google: () => true, apple: () => false, crypto: () => false });
    render(<ParentSignupScreen onBack={jest.fn()} onLogin={jest.fn()} />);
    await waitFor(() => expect(screen.queryByTestId('signup-social')).toBeNull());
  });

  it('iOS with Apple available → the Apple block appears above the e-mail form (Google still hidden without IDs)', async () => {
    setSocialProbesForTests({ google: () => true, apple: () => true, crypto: () => true });
    render(<ParentLoginScreen onBack={jest.fn()} />);
    expect(await screen.findByTestId('parent-login-social-apple')).toBeTruthy();
    expect(screen.queryByTestId('parent-login-social-google')).toBeNull();
    expect(screen.getByText(SOCIAL_STRINGS.divider)).toBeTruthy();
  });
});
