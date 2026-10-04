/**
 * Parent e-mail login: M0-10 (seeded demo logins never outside development builds)
 * and M2-02 (children no longer log in with e-mail).
 */
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import * as SecureStore from 'expo-secure-store';

import { ApiError, api } from '@/api/client';
import ParentLoginScreen, { PARENT_LOGIN_STRINGS as S } from '@/screens/ParentLoginScreen';
import { useAppStore } from '@/store/appStore';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, login: jest.fn(), logout: jest.fn(() => Promise.resolve({ message: 'ok' })) } };
});

type DevGlobal = typeof globalThis & { __DEV__: boolean };

const login = api.login as jest.Mock;
const apiLogout = api.logout as jest.Mock;
const deleteItem = SecureStore.deleteItemAsync as jest.Mock;

function fillAndSubmit(email: string, password: string) {
  fireEvent.changeText(screen.getByPlaceholderText(S.email), email);
  fireEvent.changeText(screen.getByPlaceholderText(S.password), password);
  fireEvent.press(screen.getByText(S.submit));
}

describe('ParentLoginScreen', () => {
  const g = globalThis as DevGlobal;
  const original = g.__DEV__;

  beforeEach(() => {
    jest.clearAllMocks();
    useAppStore.setState(useAppStore.getInitialState(), true);
  });

  afterEach(() => {
    g.__DEV__ = original;
  });

  it('shows the parent 1-tap demo login in development builds — and no child one', () => {
    g.__DEV__ = true;
    render(<ParentLoginScreen onBack={jest.fn()} />);
    expect(screen.queryByText(S.devTitle)).not.toBeNull();
    expect(screen.queryByText(S.devParent)).not.toBeNull();
    expect(screen.queryByText('Otrok (HUD)')).toBeNull();
  });

  it('hides the demo logins and test emails in release builds', () => {
    g.__DEV__ = false;
    render(<ParentLoginScreen onBack={jest.fn()} />);
    expect(screen.queryByText(S.devTitle)).toBeNull();
    expect(screen.queryByPlaceholderText(/test\.com/)).toBeNull();
  });

  it('a parent signs in → session store holds the parent', async () => {
    login.mockResolvedValueOnce({
      token: 'parent-token',
      user: { id: 1, name: 'Starš', email: 'p@x.si', role: 'parent' },
      pet: null,
    });
    render(<ParentLoginScreen onBack={jest.fn()} />);
    fillAndSubmit('p@x.si', 'secret');

    await waitFor(() => expect(useAppStore.getState().authToken).toBe('parent-token'));
    expect(useAppStore.getState().user?.role).toBe('parent');
  });

  it('a legacy child e-mail account is refused: token revoked + deleted, PIN path explained', async () => {
    login.mockResolvedValueOnce({
      token: 'child-token',
      user: { id: 2, name: 'Otrok', email: 'c@x.si', role: 'child' },
      pet: null,
    });
    render(<ParentLoginScreen onBack={jest.fn()} />);
    fillAndSubmit('c@x.si', 'secret');

    expect(await screen.findByText(S.childAccount)).toBeTruthy();
    expect(apiLogout).toHaveBeenCalled();
    expect(deleteItem).toHaveBeenCalledWith('petprep_auth_token');
    expect(useAppStore.getState().authToken).toBeNull();
  });

  it.each([
    [new ApiError('Invalid credentials.', 401), S.wrongCredentials],
    [new TypeError('Network request failed'), S.offline],
    [new ApiError('Server Error', 500), S.failed],
  ])('maps %p to a parent-friendly message', async (error, message) => {
    login.mockRejectedValueOnce(error);
    render(<ParentLoginScreen onBack={jest.fn()} />);
    fillAndSubmit('p@x.si', 'x');

    expect(await screen.findByText(message)).toBeTruthy();
  });
});
