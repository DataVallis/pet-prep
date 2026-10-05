/**
 * M2-10a: parent self-registration screen — validation, success (token stored like a
 * login, parent routed to the dashboard), duplicate e-mail, throttling, offline.
 */
import { fireEvent, screen, waitFor } from '@testing-library/react-native';
import * as SecureStore from 'expo-secure-store';
import { Linking } from 'react-native';

import { ApiError, api } from '@/api/client';
import { PRIVACY_URL, SIGNUP_STRINGS as S, TERMS_URL } from '@/modules/auth/signup';
import AppNavigator from '@/navigation/AppNavigator';
import { PARENT_LOGIN_STRINGS } from '@/screens/ParentLoginScreen';
import ParentSignupScreen from '@/screens/ParentSignupScreen';
import { START_STRINGS } from '@/screens/StartScreen';
import { useAppStore } from '@/store/appStore';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, register: jest.fn(), getUser: jest.fn(), logout: jest.fn() } };
});

jest.mock('@/screens/parent/ParentDashboardScreen', () => {
  const { Text } = jest.requireActual<typeof import('react-native')>('react-native');
  return { __esModule: true, default: () => <Text>PARENT_DASHBOARD</Text> };
});

const register = api.register as jest.Mock;
const setItem = SecureStore.setItemAsync as jest.Mock;
const getItem = SecureStore.getItemAsync as jest.Mock;

const parentSession = {
  token: 'new-parent-token',
  abilities: ['parent'],
  user: { id: 7, name: 'Mama Ana', email: 'ana@example.com', role: 'parent' },
  pet: null,
  awaiting_contract: null,
};

function fill(overrides: Partial<Record<'name' | 'email' | 'password' | 'repeat', string>> = {}, terms = true) {
  fireEvent.changeText(screen.getByTestId('signup-name'), overrides.name ?? 'Mama Ana');
  fireEvent.changeText(screen.getByTestId('signup-email'), overrides.email ?? ' Ana@Example.com ');
  fireEvent.changeText(screen.getByTestId('signup-password'), overrides.password ?? 'Varno1Geslo');
  fireEvent.changeText(screen.getByTestId('signup-password-repeat'), overrides.repeat ?? overrides.password ?? 'Varno1Geslo');
  if (terms) fireEvent.press(screen.getByTestId('signup-terms'));
}

function submit() {
  fireEvent.press(screen.getByTestId('signup-submit'));
}

describe('ParentSignupScreen', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    useAppStore.setState(useAppStore.getInitialState(), true);
  });

  it('shows client-side errors and sends nothing while the form is invalid', () => {
    renderWithQuery(<ParentSignupScreen onBack={jest.fn()} onLogin={jest.fn()} />);
    fill({ name: ' ', email: 'ana@', password: 'kratko', repeat: 'drugo' }, false);
    submit();

    expect(screen.getByTestId('signup-name-error').props.children).toBe(S.errors.nameRequired);
    expect(screen.getByTestId('signup-email-error').props.children).toBe(S.errors.emailInvalid);
    expect(screen.getByTestId('signup-password-error').props.children).toBe(S.errors.passwordWeak);
    expect(screen.getByTestId('signup-terms-error').props.children).toBe(S.errors.termsRequired);
    expect(register).not.toHaveBeenCalled();
  });

  it('flags a password repeat that does not match', () => {
    renderWithQuery(<ParentSignupScreen onBack={jest.fn()} onLogin={jest.fn()} />);
    fill({ repeat: 'Varno1Gesl0' });
    submit();
    expect(screen.getByTestId('signup-password-repeat-error').props.children).toBe(S.errors.passwordMismatch);
    expect(register).not.toHaveBeenCalled();
  });

  it('toggles password visibility for both fields', () => {
    renderWithQuery(<ParentSignupScreen onBack={jest.fn()} onLogin={jest.fn()} />);
    expect(screen.getByTestId('signup-password').props.secureTextEntry).toBe(true);
    fireEvent.press(screen.getByTestId('signup-password-toggle'));
    expect(screen.getByTestId('signup-password').props.secureTextEntry).toBe(false);
    expect(screen.getByTestId('signup-password-repeat').props.secureTextEntry).toBe(false);
  });

  it('opens the terms and privacy links', () => {
    const open = jest.spyOn(Linking, 'openURL').mockResolvedValue(true);
    renderWithQuery(<ParentSignupScreen onBack={jest.fn()} onLogin={jest.fn()} />);
    fireEvent.press(screen.getByText(S.termsLink));
    fireEvent.press(screen.getByText(S.privacyLink));
    expect(open).toHaveBeenCalledWith(TERMS_URL);
    expect(open).toHaveBeenCalledWith(PRIVACY_URL);
    open.mockRestore();
  });

  it('success: sends the normalised payload with the device timezone, stores the token, signs the parent in', async () => {
    register.mockResolvedValueOnce(parentSession);
    renderWithQuery(<ParentSignupScreen onBack={jest.fn()} onLogin={jest.fn()} />);
    fill();
    submit();

    await waitFor(() => expect(useAppStore.getState().authToken).toBe('new-parent-token'));
    expect(register).toHaveBeenCalledWith({
      name: 'Mama Ana',
      email: 'ana@example.com',
      password: 'Varno1Geslo',
      password_confirmation: 'Varno1Geslo',
      accept_terms: true,
      device_name: expect.any(String),
      timezone: 'UTC', // Jest's device zone
    });
    expect(setItem).toHaveBeenCalledWith('petprep_auth_token', 'new-parent-token');
    expect(useAppStore.getState().user?.role).toBe('parent');
    expect(useAppStore.getState().pet).toBeNull();
  });

  it('duplicate e-mail (422) → message under the e-mail field, no session', async () => {
    register.mockRejectedValueOnce(
      new ApiError('The email has already been taken.', 422, {
        message: 'The email has already been taken.',
        errors: { email: ['The email has already been taken.'] },
      }),
    );
    renderWithQuery(<ParentSignupScreen onBack={jest.fn()} onLogin={jest.fn()} />);
    fill();
    submit();

    expect(await screen.findByText(S.errors.emailTaken)).toBeTruthy();
    expect(useAppStore.getState().authToken).toBeNull();
    expect(setItem).not.toHaveBeenCalled();
  });

  it('throttled (429) → wait message; offline → connection message', async () => {
    register.mockRejectedValueOnce(new ApiError('Too Many Attempts.', 429, null, 60));
    renderWithQuery(<ParentSignupScreen onBack={jest.fn()} onLogin={jest.fn()} />);
    fill();
    submit();
    expect(await screen.findByText(S.errors.tooManyAttemptsMinutes(1))).toBeTruthy();

    register.mockRejectedValueOnce(new TypeError('Network request failed'));
    submit();
    expect(await screen.findByText(S.errors.offline)).toBeTruthy();
    expect(useAppStore.getState().authToken).toBeNull();
  });

  it('routing: Start → Sem starš → Registracija → sign-up → parent dashboard', async () => {
    getItem.mockResolvedValueOnce(null); // no stored session on launch
    register.mockResolvedValueOnce(parentSession);
    renderWithQuery(<AppNavigator />);

    fireEvent.press(await screen.findByText(START_STRINGS.parent));
    fireEvent.press(screen.getByText(PARENT_LOGIN_STRINGS.noAccount));
    expect(screen.getByText(S.title)).toBeTruthy();

    fill();
    submit();
    expect(await screen.findByText('PARENT_DASHBOARD')).toBeTruthy();
  });

  it('"Že imate račun? Prijava" goes back to the login', () => {
    const onLogin = jest.fn();
    renderWithQuery(<ParentSignupScreen onBack={jest.fn()} onLogin={onLogin} />);
    fireEvent.press(screen.getByText(S.haveAccount));
    expect(onLogin).toHaveBeenCalled();
  });
});
