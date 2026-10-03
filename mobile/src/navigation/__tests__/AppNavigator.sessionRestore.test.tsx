/**
 * M1-12: launch-time session restore routes exactly like a fresh login.
 */
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import * as SecureStore from 'expo-secure-store';

import { ApiError, api } from '@/api/client';
import AppNavigator from '@/navigation/AppNavigator';
import { useAppStore } from '@/store/appStore';
import { makePet } from '@/test-utils/fixtures';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, getUser: jest.fn(), logout: jest.fn() } };
});

// Role screens are covered elsewhere; here we only care which one is routed to.
jest.mock('@/screens/parent/ParentDashboardScreen', () => {
  const { Text } = jest.requireActual<typeof import('react-native')>('react-native');
  return { __esModule: true, default: () => <Text>PARENT_DASHBOARD</Text> };
});
jest.mock('@/screens/ChildHudScreen', () => {
  const { Text } = jest.requireActual<typeof import('react-native')>('react-native');
  return { __esModule: true, default: () => <Text>CHILD_HUD</Text> };
});
jest.mock('@/screens/LockedScreen', () => {
  const { Text } = jest.requireActual<typeof import('react-native')>('react-native');
  return { __esModule: true, default: () => <Text>LOCKED</Text> };
});

const getItem = SecureStore.getItemAsync as jest.Mock;
const deleteItem = SecureStore.deleteItemAsync as jest.Mock;
const getUser = api.getUser as jest.Mock;
const apiLogout = api.logout as jest.Mock;

describe('AppNavigator session restore', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    useAppStore.setState(useAppStore.getInitialState(), true);
  });

  it('shows the splash while restoring', async () => {
    getItem.mockReturnValueOnce(new Promise(() => undefined)); // never resolves
    render(<AppNavigator />);
    expect(screen.getByTestId('splash-restoring')).toBeTruthy();
  });

  it('no token → login screen', async () => {
    getItem.mockResolvedValueOnce(null);
    render(<AppNavigator />);

    expect(await screen.findByText('Prijava v račun')).toBeTruthy();
    expect(getUser).not.toHaveBeenCalled();
  });

  it('valid parent token → parent dashboard', async () => {
    getItem.mockResolvedValueOnce('parent-token');
    getUser.mockResolvedValueOnce({ id: 1, name: 'Starš', email: 'p@x.si', role: 'parent', pet: null });
    render(<AppNavigator />);

    expect(await screen.findByText('PARENT_DASHBOARD')).toBeTruthy();
    const state = useAppStore.getState();
    expect(state.authToken).toBe('parent-token');
    expect(state.user?.role).toBe('parent');
    expect(state.pairingStatus).toBe('unpaired');
  });

  it('valid child token with a pet → HUD, pet and pairing status set', async () => {
    const pet = makePet();
    getItem.mockResolvedValueOnce('child-token');
    getUser.mockResolvedValueOnce({ id: 2, name: 'Otrok', email: 'c@x.si', role: 'child', pet });
    render(<AppNavigator />);

    expect(await screen.findByText('CHILD_HUD')).toBeTruthy();
    expect(screen.queryByText('LOCKED')).toBeNull();
    const state = useAppStore.getState();
    expect(state.pet).toEqual(pet);
    expect(state.pairingStatus).toBe('paired');
  });

  it('valid child token with a game-over pet → HUD under the lock screen', async () => {
    getItem.mockResolvedValueOnce('child-token');
    getUser.mockResolvedValueOnce({
      id: 2,
      name: 'Otrok',
      email: 'c@x.si',
      role: 'child',
      pet: makePet({ is_game_over: true, is_active: false }),
    });
    render(<AppNavigator />);

    expect(await screen.findByText('LOCKED')).toBeTruthy();
    expect(useAppStore.getState().lockState).toBe('game_over');
  });

  it('valid child token without a pet → PIN entry', async () => {
    getItem.mockResolvedValueOnce('child-token');
    getUser.mockResolvedValueOnce({ id: 2, name: 'Otrok', email: 'c@x.si', role: 'child', pet: null });
    render(<AppNavigator />);

    expect(await screen.findByText('Vnos 6-mestne kode za seznanitev (PIN)')).toBeTruthy();
  });

  it('401 → token deleted, login shown', async () => {
    getItem.mockResolvedValueOnce('revoked-token');
    getUser.mockRejectedValueOnce(new ApiError('Unauthenticated.', 401));
    render(<AppNavigator />);

    expect(await screen.findByText('Prijava v račun')).toBeTruthy();
    expect(deleteItem).toHaveBeenCalledWith('petprep_auth_token');
    expect(useAppStore.getState().authToken).toBeNull();
  });

  it('offline → retry screen keeps the token; retry restores the session', async () => {
    getItem.mockResolvedValue('parent-token');
    getUser
      .mockRejectedValueOnce(new TypeError('Network request failed'))
      .mockResolvedValueOnce({ id: 1, name: 'Starš', email: 'p@x.si', role: 'parent', pet: null });
    render(<AppNavigator />);

    expect(await screen.findByTestId('splash-offline')).toBeTruthy();
    expect(deleteItem).not.toHaveBeenCalled();

    fireEvent.press(screen.getByText('Poskusi znova'));
    expect(await screen.findByText('PARENT_DASHBOARD')).toBeTruthy();
  });

  it('offline → "Odjava" revokes, clears the token and shows login', async () => {
    getItem.mockResolvedValue('parent-token');
    getUser.mockRejectedValueOnce(new TypeError('Network request failed'));
    apiLogout.mockRejectedValueOnce(new TypeError('Network request failed'));
    render(<AppNavigator />);

    fireEvent.press(await screen.findByText('Odjava'));
    await waitFor(() => expect(screen.getByText('Prijava v račun')).toBeTruthy());
    expect(apiLogout).toHaveBeenCalled();
    expect(deleteItem).toHaveBeenCalledWith('petprep_auth_token');
  });
});
