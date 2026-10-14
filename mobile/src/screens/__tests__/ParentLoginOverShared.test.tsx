/**
 * M2-11 QA B1: the parent login over a SHARED child session drives the real `apiRequest`. A
 * wrong password (401 from the public `/api/login`) must not look like a rejected session
 * token: no Bearer is sent, the unauthorized handler is not called, the child stays signed in
 * and the login shows its error. Android back closes the login and returns to the child.
 */
import { act, fireEvent, render, screen } from '@testing-library/react-native';
import * as SecureStore from 'expo-secure-store';
import { BackHandler } from 'react-native';

import { setUnauthorizedHandler } from '@/api/client';
import AppNavigator from '@/navigation/AppNavigator';
import ParentLoginScreen, { PARENT_LOGIN_STRINGS as S } from '@/screens/ParentLoginScreen';
import { useAppStore } from '@/store/appStore';
import { makePet } from '@/test-utils/fixtures';

jest.mock('@/screens/ChildHudScreen', () => {
  const { View: V } = jest.requireActual<typeof import('react-native')>('react-native');
  return { __esModule: true, default: () => <V testID="child-hud" /> };
});
jest.mock('@/modules/session/useSessionBootstrap', () => ({ useSessionBootstrap: () => ({ retry: jest.fn() }) }));

const fetchMock = jest.fn();

beforeEach(() => {
  jest.clearAllMocks();
  global.fetch = fetchMock as unknown as typeof fetch;
  jest.mocked(SecureStore.getItemAsync).mockImplementation(async (key: string) => (key === 'petprep_auth_token' ? 'child-token' : null));
  useAppStore.setState(useAppStore.getInitialState(), true);
  useAppStore.getState().signIn({
    token: 'child-token',
    user: { id: 2, name: 'Maja', email: null, role: 'child' },
    pet: makePet({ id: 7, born_at: '2026-10-01T08:00:00Z' }),
    awaitingContract: false,
    sharedSession: true,
  });
});

afterEach(() => setUnauthorizedHandler(null));

it('a wrong password over a shared child session: no Bearer, no unauthorized handler, child stays, error shown', async () => {
  const onUnauthorized = jest.fn();
  setUnauthorizedHandler(onUnauthorized);
  fetchMock.mockResolvedValueOnce({
    ok: false,
    status: 401,
    json: async () => ({ message: 'Invalid credentials.' }),
    headers: { get: () => null },
  });
  render(<ParentLoginScreen onBack={jest.fn()} />);

  fireEvent.changeText(screen.getByPlaceholderText(S.email), 'mama@example.com');
  fireEvent.changeText(screen.getByPlaceholderText(S.password), 'narobe');
  await act(async () => {
    fireEvent.press(screen.getByText(S.submit));
  });

  const [url, init] = fetchMock.mock.calls[0] as [string, { headers: Record<string, string> }];
  expect(url).toMatch(/\/api\/login$/);
  expect(init.headers.Authorization).toBeUndefined();
  expect(onUnauthorized).not.toHaveBeenCalled();
  expect(screen.getByTestId('parent-login-error')).toHaveTextContent(S.wrongCredentials);
  expect(useAppStore.getState().user?.role).toBe('child');
});

it('Android back on the login overlay returns to the child and resets parentLoginOpen', () => {
  const handlers: Array<Parameters<typeof BackHandler.addEventListener>[1]> = [];
  jest.spyOn(BackHandler, 'addEventListener').mockImplementation((_event, handler) => {
    handlers.push(handler);
    return { remove: jest.fn() };
  });
  render(<AppNavigator />);
  expect(screen.getByTestId('child-hud')).toBeTruthy();

  act(() => useAppStore.getState().setParentLoginOpen(true));
  expect(screen.getByText(S.title)).toBeTruthy();

  let handled: boolean | null | undefined;
  act(() => {
    handled = handlers[handlers.length - 1]({} as Parameters<Parameters<typeof BackHandler.addEventListener>[1]>[0]);
  });
  expect(handled).toBe(true);
  expect(useAppStore.getState().parentLoginOpen).toBe(false);
  expect(screen.getByTestId('child-hud')).toBeTruthy();
});
