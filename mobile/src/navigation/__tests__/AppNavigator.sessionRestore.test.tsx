/**
 * M1-12: launch-time session restore routes exactly like a fresh login.
 */
import { fireEvent, screen, waitFor } from '@testing-library/react-native';
import * as SecureStore from 'expo-secure-store';

import { ApiError, api } from '@/api/client';
import AppNavigator from '@/navigation/AppNavigator';
import { useAppStore } from '@/store/appStore';
import { makeChildState, makePet } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';
import { CONTRACT_STRINGS } from '@/screens/ContractScreen';
import { CHILD_PIN_STRINGS } from '@/screens/ChildPinLoginScreen';
import { START_STRINGS } from '@/screens/StartScreen';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, getUser: jest.fn(), logout: jest.fn(), signContract: jest.fn() } };
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
const signContract = api.signContract as jest.Mock;

describe('AppNavigator session restore', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    useAppStore.setState(useAppStore.getInitialState(), true);
  });

  it('shows the splash while restoring', async () => {
    getItem.mockReturnValueOnce(new Promise(() => undefined)); // never resolves
    renderWithQuery(<AppNavigator />);
    expect(screen.getByTestId('splash-restoring')).toBeTruthy();
  });

  it('no token → start screen with the parent and child paths', async () => {
    getItem.mockResolvedValueOnce(null);
    renderWithQuery(<AppNavigator />);

    expect(await screen.findByText(START_STRINGS.subtitle)).toBeTruthy();
    expect(screen.getByText(START_STRINGS.parent)).toBeTruthy();
    expect(screen.getByText(START_STRINGS.child)).toBeTruthy();
    expect(getUser).not.toHaveBeenCalled();
  });

  it('valid parent token → parent dashboard', async () => {
    getItem.mockResolvedValueOnce('parent-token');
    getUser.mockResolvedValueOnce({ id: 1, name: 'Starš', email: 'p@x.si', role: 'parent', pet: null });
    renderWithQuery(<AppNavigator />);

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
    renderWithQuery(<AppNavigator />);

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
    renderWithQuery(<AppNavigator />);

    expect(await screen.findByText('LOCKED')).toBeTruthy();
    expect(useAppStore.getState().lockState).toBe('game_over');
  });

  it('legacy child token without a pet → PIN entry (with "Odjava" as the way back)', async () => {
    getItem.mockResolvedValueOnce('child-token');
    getUser.mockResolvedValueOnce({ id: 2, name: 'Otrok', email: 'c@x.si', role: 'child', pet: null });
    renderWithQuery(<AppNavigator />);

    expect(await screen.findByText(CHILD_PIN_STRINGS.title)).toBeTruthy();
    expect(screen.getByLabelText(CHILD_PIN_STRINGS.logout)).toBeTruthy();
  });

  it('PIN-only child token (no e-mail) restores with GET /api/user → HUD (M2-02)', async () => {
    const pet = makePet();
    getItem.mockResolvedValueOnce('pin-child-token');
    getUser.mockResolvedValueOnce({ id: 9, name: 'Maja', email: null, role: 'child', pet });
    renderWithQuery(<AppNavigator />);

    expect(await screen.findByText('CHILD_HUD')).toBeTruthy();
    expect(useAppStore.getState().user).toEqual({ id: 9, name: 'Maja', email: null, role: 'child' });
  });

  it('revoked child token (parent signed all devices out) → 401 → back to the start screen', async () => {
    getItem.mockResolvedValueOnce('revoked-child-token');
    getUser.mockRejectedValueOnce(new ApiError('Unauthenticated.', 401));
    renderWithQuery(<AppNavigator />);

    expect(await screen.findByText(START_STRINGS.subtitle)).toBeTruthy();
    expect(deleteItem).toHaveBeenCalledWith('petprep_auth_token');
  });

  it('child with an unborn pet (paired, contract not signed) → contract step, not the HUD (M1-07b)', async () => {
    getItem.mockResolvedValueOnce('child-token');
    // /api/user returns the raw pet: born_at null, no awaiting_contract key.
    getUser.mockResolvedValueOnce({ id: 2, name: 'Otrok', email: 'c@x.si', role: 'child', pet: makePet({ born_at: null }) });
    renderWithQuery(<AppNavigator />);

    expect(await screen.findByText(CONTRACT_STRINGS.padHint)).toBeTruthy();
    expect(screen.queryByText('CHILD_HUD')).toBeNull();
    expect(useAppStore.getState().lockState).toBe('none');
  });

  it('child who joined a born shared pet and has not signed → contract step after a restart (M2-02)', async () => {
    getItem.mockResolvedValueOnce('child-token');
    // The pet itself is born; only the per-child flag says this child must sign.
    const pet = makePet({ born_at: '2026-10-01T08:00:00Z' });
    getUser.mockResolvedValueOnce({ id: 3, name: 'Bor', email: null, role: 'child', pet, awaiting_contract: true });
    renderWithQuery(<AppNavigator />);

    expect(await screen.findByText(CONTRACT_STRINGS.padHint)).toBeTruthy();
    expect(screen.queryByText('CHILD_HUD')).toBeNull();
    const state = useAppStore.getState();
    expect(state.pet?.awaiting_contract).toBe(true);
    expect(state.user).toEqual({ id: 3, name: 'Bor', email: null, role: 'child' });
  });

  it('per-child flag false wins over the pet: a signed child goes to the HUD (M2-02)', async () => {
    getItem.mockResolvedValueOnce('child-token');
    const pet = { ...makePet({ born_at: '2026-10-01T08:00:00Z' }), awaiting_contract: true };
    getUser.mockResolvedValueOnce({ id: 2, name: 'Ana', email: null, role: 'child', pet, awaiting_contract: false });
    renderWithQuery(<AppNavigator />);

    expect(await screen.findByText('CHILD_HUD')).toBeTruthy();
    expect(useAppStore.getState().pet?.awaiting_contract).toBe(false);
  });

  it('restored unborn pet → signing the contract opens the HUD with the server pet (M1-07b)', async () => {
    getItem.mockResolvedValueOnce('child-token');
    getUser.mockResolvedValueOnce({ id: 2, name: 'Otrok', email: 'c@x.si', role: 'child', pet: makePet({ born_at: null }) });
    signContract.mockResolvedValueOnce({ status: 'accepted', state: makeChildState() });
    renderWithQuery(<AppNavigator />);

    const pad = await screen.findByTestId('signature-pad');
    fireEvent(pad, 'responderGrant', { nativeEvent: { locationX: 5, locationY: 5 } });
    fireEvent(pad, 'responderMove', { nativeEvent: { locationX: 60, locationY: 30 } });
    fireEvent.press(screen.getByText(CONTRACT_STRINGS.accept));

    expect(await screen.findByText('CHILD_HUD')).toBeTruthy();
    expect(signContract).toHaveBeenCalledWith({ signature_format: 'svg_path', signature: 'M5 5 L60 30' });
    const { pet } = useAppStore.getState();
    expect(pet?.born_at).toBe('2026-10-04T09:00:00+00:00');
    expect(pet?.awaiting_contract).toBe(false);
  });

  it('401 → token deleted, login shown', async () => {
    getItem.mockResolvedValueOnce('revoked-token');
    getUser.mockRejectedValueOnce(new ApiError('Unauthenticated.', 401));
    renderWithQuery(<AppNavigator />);

    expect(await screen.findByText(START_STRINGS.subtitle)).toBeTruthy();
    expect(deleteItem).toHaveBeenCalledWith('petprep_auth_token');
    expect(useAppStore.getState().authToken).toBeNull();
  });

  it('offline → retry screen keeps the token; retry restores the session', async () => {
    getItem.mockResolvedValue('parent-token');
    getUser
      .mockRejectedValueOnce(new TypeError('Network request failed'))
      .mockResolvedValueOnce({ id: 1, name: 'Starš', email: 'p@x.si', role: 'parent', pet: null });
    renderWithQuery(<AppNavigator />);

    expect(await screen.findByTestId('splash-offline')).toBeTruthy();
    expect(deleteItem).not.toHaveBeenCalled();

    fireEvent.press(screen.getByText('Poskusi znova'));
    expect(await screen.findByText('PARENT_DASHBOARD')).toBeTruthy();
  });

  it('offline → "Odjava" revokes, clears the token and shows login', async () => {
    getItem.mockResolvedValue('parent-token');
    getUser.mockRejectedValueOnce(new TypeError('Network request failed'));
    apiLogout.mockRejectedValueOnce(new TypeError('Network request failed'));
    renderWithQuery(<AppNavigator />);

    fireEvent.press(await screen.findByText('Odjava'));
    await waitFor(() => expect(screen.getByText(START_STRINGS.subtitle)).toBeTruthy());
    expect(apiLogout).toHaveBeenCalled();
    expect(deleteItem).toHaveBeenCalledWith('petprep_auth_token');
  });
});
