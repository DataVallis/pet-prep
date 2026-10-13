/**
 * M2-10c: the Google / Apple block on the parent auth screens — visibility per provider,
 * sign-in into the store, the terms sheet on `terms_required` (same token retried with
 * `terms_accepted: true`), friendly errors, silent cancel.
 */
import { act, fireEvent, render, screen } from '@testing-library/react-native';

import { ApiError, api } from '@/api/client';
import SocialSignInSection from '@/components/auth/SocialSignInSection';
import { SOCIAL_STRINGS } from '@/modules/auth/socialSignIn';
import { obtainProviderCredential } from '@/modules/auth/socialSdk';
import { useAppStore } from '@/store/appStore';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, socialSignIn: jest.fn(), logout: jest.fn() } };
});

jest.mock('@/modules/auth/socialSdk', () => {
  const actual = jest.requireActual<typeof import('@/modules/auth/socialSdk')>('@/modules/auth/socialSdk');
  const { Pressable: P } = jest.requireActual<typeof import('react-native')>('react-native');
  // Stand-in for the native ASAuthorizationAppleIDButton.
  const AppleAuthenticationButton = ({ onPress }: { onPress: () => void }) => <P testID="apple-native-button" onPress={onPress} />;
  return {
    ...actual,
    obtainProviderCredential: jest.fn(),
    isGoogleSignInAvailable: jest.fn(() => false),
    isAppleSignInAvailable: jest.fn(async () => false),
    appleButtonSdk: jest.fn(() => ({
      AppleAuthenticationButton,
      AppleAuthenticationButtonType: { SIGN_IN: 0, CONTINUE: 1, SIGN_UP: 2 },
      AppleAuthenticationButtonStyle: { WHITE: 0, WHITE_OUTLINE: 1, BLACK: 2 },
    })),
  };
});

const socialSignIn = api.socialSignIn as jest.Mock;
const obtain = obtainProviderCredential as jest.Mock;

const RESPONSE = {
  token: 'tok-social',
  user: { id: 9, name: 'Ana', email: 'ana@example.com', role: 'parent', has_password: false, linked_providers: ['google'] },
  pet: null,
  awaiting_contract: null,
  created: false,
};

async function flush() {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 10));
  });
}

describe('SocialSignInSection', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    useAppStore.getState().reset();
  });

  it('renders nothing when no provider is available (no client IDs, Expo Go, Android without Google)', () => {
    render(<SocialSignInSection providers={{ google: false, apple: false }} />);
    expect(screen.queryByTestId('social-signin')).toBeNull();
    expect(screen.queryByText(SOCIAL_STRINGS.divider)).toBeNull();
  });

  it('Google only (Android): the Google button and the divider, no Apple button', () => {
    render(<SocialSignInSection providers={{ google: true, apple: false }} />);
    expect(screen.getByTestId('social-signin-google')).toBeTruthy();
    expect(screen.getByLabelText(SOCIAL_STRINGS.google)).toBeTruthy();
    expect(screen.getByText(SOCIAL_STRINGS.divider)).toBeTruthy();
    expect(screen.queryByTestId('social-signin-apple')).toBeNull();
  });

  it('iOS with Apple: the official Apple button is shown', () => {
    render(<SocialSignInSection providers={{ google: true, apple: true }} />);
    expect(screen.getByTestId('social-signin-apple')).toBeTruthy();
    expect(screen.getByTestId('apple-native-button')).toBeTruthy();
  });

  it('Google sign-in puts the parent session into the store (method google)', async () => {
    obtain.mockResolvedValueOnce({ kind: 'credential', credential: { provider: 'google', idToken: 'g.jwt' } });
    socialSignIn.mockResolvedValueOnce(RESPONSE);
    const onBusy = jest.fn();
    render(<SocialSignInSection providers={{ google: true, apple: false }} onBusyChange={onBusy} />);

    fireEvent.press(screen.getByTestId('social-signin-google'));
    await flush();

    expect(obtain).toHaveBeenCalledWith('google');
    expect(useAppStore.getState().user).toEqual({ id: 9, name: 'Ana', email: 'ana@example.com', role: 'parent' });
    expect(useAppStore.getState().signInMethod).toBe('google');
    // QA m1: the server's sign-in methods drive the deletion forms.
    expect(useAppStore.getState().authMethods).toEqual({ hasPassword: false, linkedProviders: ['google'] });
    expect(onBusy.mock.calls).toEqual([[true], [false]]);
  });

  it('terms_required: sheet with the consent; only after ticking it retries the SAME token with terms_accepted', async () => {
    obtain.mockResolvedValueOnce({ kind: 'credential', credential: { provider: 'apple', idToken: 'a.jwt', nonce: 'raw', name: 'Ana Novak' } });
    socialSignIn
      .mockRejectedValueOnce(new ApiError('terms', 422, { message: 'terms', code: 'terms_required' }))
      .mockResolvedValueOnce({ ...RESPONSE, created: true });
    render(<SocialSignInSection providers={{ google: false, apple: true }} />);

    fireEvent.press(screen.getByTestId('apple-native-button'));
    await flush();

    expect(screen.getByTestId('social-signin-terms')).toBeTruthy();
    expect(screen.getByText(SOCIAL_STRINGS.termsBody)).toBeTruthy();
    expect(useAppStore.getState().user).toBeNull();

    // Not ticked yet → no request.
    fireEvent.press(screen.getByTestId('social-signin-terms-submit'));
    await flush();
    expect(socialSignIn).toHaveBeenCalledTimes(1);

    fireEvent.press(screen.getByTestId('social-signin-terms-checkbox'));
    fireEvent.press(screen.getByTestId('social-signin-terms-submit'));
    await flush();

    expect(obtain).toHaveBeenCalledTimes(1);
    expect(socialSignIn).toHaveBeenCalledTimes(2);
    expect(socialSignIn.mock.calls[1][0]).toMatchObject({ provider: 'apple', id_token: 'a.jwt', nonce: 'raw', name: 'Ana Novak', terms_accepted: true });
    expect(useAppStore.getState().signInMethod).toBe('apple');
    expect(useAppStore.getState().user?.id).toBe(9);
  });

  it('cancelling the terms sheet signs nobody in', async () => {
    obtain.mockResolvedValueOnce({ kind: 'credential', credential: { provider: 'google', idToken: 'g.jwt' } });
    socialSignIn.mockRejectedValueOnce(new ApiError('terms', 422, { message: 'terms', code: 'terms_required' }));
    render(<SocialSignInSection providers={{ google: true, apple: false }} />);

    fireEvent.press(screen.getByTestId('social-signin-google'));
    await flush();
    fireEvent.press(screen.getByTestId('social-signin-terms-cancel'));
    await flush();

    expect(screen.queryByTestId('social-signin-terms')).toBeNull();
    expect(socialSignIn).toHaveBeenCalledTimes(1);
    expect(useAppStore.getState().user).toBeNull();
  });

  it('email_in_use: tells the parent to sign in with e-mail and password', async () => {
    obtain.mockResolvedValueOnce({ kind: 'credential', credential: { provider: 'google', idToken: 'g.jwt' } });
    socialSignIn.mockRejectedValueOnce(new ApiError('in use', 409, { message: 'in use', code: 'email_in_use' }));
    render(<SocialSignInSection providers={{ google: true, apple: false }} />);

    fireEvent.press(screen.getByTestId('social-signin-google'));
    await flush();

    expect(screen.getByText(SOCIAL_STRINGS.errors.email_in_use)).toBeTruthy();
    expect(useAppStore.getState().user).toBeNull();
  });

  it('email_link_unavailable (QA m3): another sign-in method — not the e-mail + password text', async () => {
    obtain.mockResolvedValueOnce({ kind: 'credential', credential: { provider: 'apple', idToken: 'a.jwt', nonce: 'n' } });
    socialSignIn.mockRejectedValueOnce(new ApiError('link', 409, { message: 'link', code: 'email_link_unavailable' }));
    render(<SocialSignInSection providers={{ google: false, apple: true }} />);

    fireEvent.press(screen.getByTestId('apple-native-button'));
    await flush();

    expect(screen.getByText(SOCIAL_STRINGS.errors.email_link_unavailable)).toBeTruthy();
    expect(screen.queryByText(SOCIAL_STRINGS.errors.email_in_use)).toBeNull();
  });

  it('closing the Google sheet: no request, no error', async () => {
    obtain.mockResolvedValueOnce({ kind: 'cancelled' });
    render(<SocialSignInSection providers={{ google: true, apple: false }} />);

    fireEvent.press(screen.getByTestId('social-signin-google'));
    await flush();

    expect(socialSignIn).not.toHaveBeenCalled();
    expect(screen.queryByTestId('social-signin-error')).toBeNull();
  });

  it('disabled while the e-mail form sends', () => {
    render(<SocialSignInSection providers={{ google: true, apple: false }} disabled />);
    fireEvent.press(screen.getByTestId('social-signin-google'));
    expect(obtain).not.toHaveBeenCalled();
    expect(screen.getByTestId('social-signin-google').props.accessibilityState).toMatchObject({ disabled: true });
  });
});
