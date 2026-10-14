/**
 * M2-11 end to end in the navigator (David 2026-10-14): »Igraj kot Maja« → the child app
 * (the parent is signed out) → »Starš?« opens the PARENT LOGIN (no maths gate) → a wrong
 * password keeps the child mode → »Nazaj« returns to the child → the right password →
 * parent dashboard; the child token stays stored for the next »Igraj kot«.
 */
import { act, fireEvent, screen, waitFor } from '@testing-library/react-native';
import * as SecureStore from 'expo-secure-store';
import { Text, View } from 'react-native';

import { ApiError, api } from '@/api/client';
import PlayAsChildButton, { PLAY_AS_STRINGS } from '@/components/parent/PlayAsChildButton';
import { loadChildSessions } from '@/modules/session/profiles';
import { resetProfileSwitchForTests } from '@/modules/session/profileSwitch';
import AppNavigator from '@/navigation/AppNavigator';
import { PARENT_LOGIN_STRINGS } from '@/screens/ParentLoginScreen';
import { useAppStore } from '@/store/appStore';
import { makePet } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: {
      ...actual.api,
      getUser: jest.fn(),
      login: jest.fn(),
      childDeviceSession: jest.fn(),
      getUserWithToken: jest.fn(),
      logout: jest.fn(),
      logoutToken: jest.fn(async () => ({ message: 'ok' })),
    },
  };
});

// The dashboard and the HUD themselves are covered elsewhere: here only what the switch needs.
jest.mock('@/screens/parent/ParentDashboardScreen', () => {
  const { default: Button } = jest.requireActual<typeof import('@/components/parent/PlayAsChildButton')>('@/components/parent/PlayAsChildButton');
  const { View: V, Text: T } = jest.requireActual<typeof import('react-native')>('react-native');
  return {
    __esModule: true,
    default: () => (
      <V testID="parent-dashboard">
        <T>Pregled</T>
        <Button child={{ id: 2, name: 'Maja', pet_id: 7 }} />
        <Button child={{ id: 5, name: 'Luka', pet_id: null }} />
      </V>
    ),
  };
});
jest.mock('@/screens/ChildHudScreen', () => {
  const { default: Exit } = jest.requireActual<typeof import('@/components/ParentExitLink')>('@/components/ParentExitLink');
  const { View: V } = jest.requireActual<typeof import('react-native')>('react-native');
  return { __esModule: true, default: () => <V testID="child-hud"><Exit testID="hud-exit" /></V> };
});
jest.mock('@/modules/session/useSessionBootstrap', () => ({ useSessionBootstrap: () => ({ retry: jest.fn() }) }));
jest.mock('@/modules/steps/backgroundSteps', () => ({ unregisterStepSyncTask: jest.fn(async () => undefined) }));

const PET = makePet({ id: 7, born_at: '2026-10-01T08:00:00Z' });
const PARENT_USER = { id: 1, name: 'Mama', email: 'm@x.si', role: 'parent' as const };
let vault: Map<string, string>;

async function settle() {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 20));
  });
}

beforeEach(() => {
  jest.clearAllMocks();
  resetProfileSwitchForTests();
  vault = new Map([['petprep_auth_token', 'parent-token']]);
  jest.mocked(SecureStore.getItemAsync).mockImplementation(async (key: string) => vault.get(key) ?? null);
  jest.mocked(SecureStore.setItemAsync).mockImplementation(async (key: string, value: string) => {
    vault.set(key, value);
  });
  jest.mocked(SecureStore.deleteItemAsync).mockImplementation(async (key: string) => {
    vault.delete(key);
  });
  (api.getUser as jest.Mock).mockImplementation(async () =>
    vault.get('petprep_auth_token') === 'child-token'
      ? { id: 2, name: 'Maja', email: null, role: 'child', pet: PET, awaiting_contract: false }
      : { ...PARENT_USER, pet: null, has_password: true, linked_providers: [] },
  );
  (api.childDeviceSession as jest.Mock).mockResolvedValue({
    token: 'child-token',
    abilities: ['child'],
    user: { id: 2, name: 'Maja', role: 'child' },
    mode: 'relogin',
    joined_existing: true,
    family_id: 1,
    pet: { id: 7, breed_type: 'mutt', species: 'dog', hunger_level: 60, thirst_level: 60, energy_level: 60, hygiene_level: 60, born_at: '2026-10-01T08:00:00Z', awaiting_contract: false, is_active: true, is_game_over: false, current_video_url: null },
    awaiting_contract: false,
  });
  useAppStore.setState(useAppStore.getInitialState(), true);
  useAppStore.getState().signIn({ token: 'parent-token', user: PARENT_USER, pet: null });
});

function login(password: string) {
  fireEvent.changeText(screen.getByPlaceholderText(PARENT_LOGIN_STRINGS.email), 'm@x.si');
  fireEvent.changeText(screen.getByPlaceholderText(PARENT_LOGIN_STRINGS.password), password);
  fireEvent.press(screen.getByText(PARENT_LOGIN_STRINGS.submit));
}

it('parent → »Igraj kot Maja« (parent signed out) → »Starš?« = parent login → wrong password stays → »Nazaj« → right password → dashboard', async () => {
  renderWithQuery(<AppNavigator />);
  expect(screen.getByTestId('parent-dashboard')).toBeTruthy();
  // A child without a pet has no button (first pairing needs the PIN); the steps hint is shown.
  expect(screen.queryByTestId('child-play-as-5')).toBeNull();
  expect(screen.getByText(PLAY_AS_STRINGS.playAsSteps('Maja'))).toBeTruthy();

  fireEvent.press(screen.getByText(PLAY_AS_STRINGS.playAs('Maja')));
  await waitFor(() => expect(screen.getByTestId('child-hud')).toBeTruthy());
  expect(api.logoutToken).toHaveBeenCalledWith('parent-token', expect.anything());
  expect(useAppStore.getState().sharedSession).toBe(true);

  // »Starš?« → the parent login directly (no maths gate).
  fireEvent.press(screen.getByTestId('hud-exit'));
  expect(screen.queryByTestId('parental-gate')).toBeNull();
  expect(screen.getByText(PARENT_LOGIN_STRINGS.title)).toBeTruthy();

  (api.login as jest.Mock).mockRejectedValueOnce(new ApiError('Invalid credentials.', 401, { message: 'Invalid credentials.' }));
  login('narobe');
  await settle();
  expect(screen.getByTestId('parent-login-error')).toHaveTextContent(PARENT_LOGIN_STRINGS.wrongCredentials);
  expect(useAppStore.getState().user?.role).toBe('child');
  expect(vault.get('petprep_auth_token')).toBe('child-token');

  fireEvent.press(screen.getByText(PARENT_LOGIN_STRINGS.back));
  expect(screen.getByTestId('child-hud')).toBeTruthy();

  fireEvent.press(screen.getByTestId('hud-exit'));
  (api.login as jest.Mock).mockResolvedValueOnce({ token: 'parent-token-2', user: PARENT_USER, pet: null, awaiting_contract: null });
  login('Pravilno1Geslo');
  await waitFor(() => expect(screen.getByTestId('parent-dashboard')).toBeTruthy());
  await settle();

  expect(useAppStore.getState().user?.role).toBe('parent');
  expect(vault.get('petprep_auth_token')).toBe('parent-token-2');
  // The child token stays stored for this parent (instant next time).
  expect(await loadChildSessions()).toEqual({ '2': { token: 'child-token', parentUserId: 1 } });
});

it('PlayAsChildButton: errors are shown on the card; no button without a pet', async () => {
  const onSwitch = jest.fn(async () => {
    const { ProfileSwitchError } = jest.requireActual<typeof import('@/modules/session/profileSwitch')>('@/modules/session/profileSwitch');
    throw new ProfileSwitchError('throttled');
  });
  const { unmount } = renderWithQuery(<PlayAsChildButton child={{ id: 2, name: 'Maja', pet_id: 7 }} onSwitch={onSwitch} />);
  fireEvent.press(screen.getByTestId('child-play-as-2'));
  await settle();
  expect(screen.getByTestId('child-play-as-error-2')).toHaveTextContent(PLAY_AS_STRINGS.playAsErrors.throttled);
  unmount();

  renderWithQuery(
    <View>
      <PlayAsChildButton child={{ id: 3, name: 'Ana', pet_id: null }} />
      <Text>x</Text>
    </View>,
  );
  expect(screen.queryByTestId('child-play-as-3')).toBeNull();
});
