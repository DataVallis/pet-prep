/**
 * M1-12: launch-time session restore routes exactly like a fresh login.
 */
import { act, fireEvent, screen, waitFor } from '@testing-library/react-native';
import * as SecureStore from 'expo-secure-store';

import { ApiError, api } from '@/api/client';
import AppNavigator from '@/navigation/AppNavigator';
import { useAppStore } from '@/store/appStore';
import { OUTAGE_EXIT_AFTER_MS, useServerStatusStore } from '@/store/serverStatusStore';
import { makeChildState, makePet } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';
import { BOOT_WATCHDOG_MS } from '@/modules/session/useSessionBootstrap';
import { resetRestoreForTests } from '@/modules/session/restoreSession';
import { CONTRACT_STRINGS } from '@/screens/ContractScreen';
import { CHILD_PIN_STRINGS } from '@/screens/ChildPinLoginScreen';
import { START_STRINGS } from '@/screens/StartScreen';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, getUser: jest.fn(), logout: jest.fn(), logoutToken: jest.fn(), signContract: jest.fn() } };
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
const logoutToken = api.logoutToken as jest.Mock;

/** Answer the parental gate (M5-F12) correctly. */
function passGate() {
  const [a, b] = (String(screen.getByTestId('parental-gate-question').props.children).match(/\d+/g) ?? []).map(Number);
  fireEvent.changeText(screen.getByTestId('parental-gate-input'), String(a * b));
  fireEvent.press(screen.getByTestId('parental-gate-submit'));
}

describe('AppNavigator session restore', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    getUser.mockReset();
    resetRestoreForTests();
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

  it('legacy child token without a pet → signed out (server revoke) and back to the start screen', async () => {
    getItem.mockResolvedValueOnce('child-token');
    getUser.mockResolvedValueOnce({ id: 2, name: 'Otrok', email: 'c@x.si', role: 'child', pet: null });
    apiLogout.mockResolvedValueOnce({ message: 'ok' });
    renderWithQuery(<AppNavigator />);

    expect(await screen.findByText(START_STRINGS.subtitle)).toBeTruthy();
    await waitFor(() => expect(useAppStore.getState().authToken).toBeNull());
    expect(apiLogout).toHaveBeenCalledTimes(1);
    expect(deleteItem).toHaveBeenCalledWith('petprep_auth_token');
    expect(useAppStore.getState().user).toBeNull();
    expect(screen.queryByText(CHILD_PIN_STRINGS.title)).toBeNull();
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

  it('M5-F13: launch during a deploy → calm "updating" splash (token kept), restores by itself when the server is back', async () => {
    getItem.mockResolvedValue('parent-token');
    getUser
      .mockImplementationOnce(async () => {
        useServerStatusStore.getState().noteOutageFailure('maintenance', 15_000);
        throw new ApiError('Service Unavailable', 503, { message: 'Service Unavailable' }, 15);
      })
      .mockResolvedValueOnce({ id: 1, name: 'Starš', email: 'p@x.si', role: 'parent', pet: null });
    renderWithQuery(<AppNavigator />);

    expect(await screen.findByTestId('splash-updating')).toBeTruthy();
    expect(screen.getByText('Posodabljamo PetPrep — takoj smo nazaj.')).toBeTruthy();
    expect(screen.queryByText('Ni povezave')).toBeNull();
    expect(deleteItem).not.toHaveBeenCalled();

    // Any request (the /up probe in the app) reports the server back → restore again, no tap.
    act(() => useServerStatusStore.getState().noteServerAnswered());
    expect(await screen.findByText('PARENT_DASHBOARD')).toBeTruthy();
    expect(getUser).toHaveBeenCalledTimes(2);
  });

  it('QA m4: a launch stuck in the updating state > 2.5 min offers "Poskusi znova" and "Odjava"', async () => {
    jest.useFakeTimers({ doNotFake: ['nextTick', 'setImmediate'] });
    try {
      getItem.mockResolvedValue('parent-token');
      getUser.mockImplementation(async () => {
        useServerStatusStore.getState().noteOutageFailure('maintenance', 15_000);
        throw new ApiError('Service Unavailable', 503, { message: 'Service Unavailable' }, 15);
      });
      renderWithQuery(<AppNavigator />);
      await act(async () => {
        await jest.advanceTimersByTimeAsync(0);
      });
      expect(screen.getByTestId('splash-updating')).toBeTruthy();
      expect(screen.queryByTestId('splash-updating-exit')).toBeNull();
      await act(async () => {
        await jest.advanceTimersByTimeAsync(OUTAGE_EXIT_AFTER_MS);
      });
      expect(screen.getByTestId('splash-updating-exit')).toBeTruthy();
      expect(screen.getByText('Poskusi znova')).toBeTruthy();
      expect(screen.getByText('Odjava')).toBeTruthy();
    } finally {
      jest.useRealTimers();
    }
  });

  it('boot-hang fix 2026-10-19: a restore stuck past the watchdog → "Poskusi znova" / "Odjava", token kept; a late timeout then retry restores', async () => {
    jest.useFakeTimers({ doNotFake: ['nextTick', 'setImmediate'] });
    try {
      getItem.mockImplementation(async (key: string) => (key === 'petprep_auth_token' ? 'child-token' : key === 'petprep_keychain_after_first_unlock' ? '1' : null));
      let fail: (error: Error) => void = () => undefined;
      getUser
        .mockReturnValueOnce(new Promise((_, reject) => (fail = reject)))
        .mockResolvedValueOnce({ id: 2, name: 'Otrok', email: null, role: 'child', pet: makePet() });
      renderWithQuery(<AppNavigator />);
      await act(async () => {
        await jest.advanceTimersByTimeAsync(BOOT_WATCHDOG_MS - 1);
      });
      expect(screen.getByTestId('splash-restoring')).toBeTruthy();
      await act(async () => {
        await jest.advanceTimersByTimeAsync(1);
      });
      expect(screen.getByTestId('splash-offline')).toBeTruthy();
      expect(screen.getByText('Odjava')).toBeTruthy();
      expect(deleteItem).not.toHaveBeenCalled();

      // The stuck request finally gives up (its own timeout); "Poskusi znova" restores.
      await act(async () => {
        fail(new TypeError('Network request failed'));
        await jest.advanceTimersByTimeAsync(0);
      });
      fireEvent.press(screen.getByText('Poskusi znova'));
      await act(async () => {
        await jest.advanceTimersByTimeAsync(0);
      });
      expect(screen.getByText('CHILD_HUD')).toBeTruthy();
    } finally {
      getItem.mockReset();
      getItem.mockResolvedValue(null);
      jest.useRealTimers();
    }
  });

  it('offline → "Odjava" revokes, clears the token and shows login', async () => {
    getItem.mockResolvedValue('parent-token');
    getUser.mockRejectedValueOnce(new TypeError('Network request failed'));
    apiLogout.mockRejectedValueOnce(new TypeError('Network request failed'));
    renderWithQuery(<AppNavigator />);

    fireEvent.press(await screen.findByText('Odjava'));
    // QA M2: the role isn't known on the splash — a child may hold the phone.
    expect(screen.getByTestId('parental-gate')).toBeTruthy();
    expect(apiLogout).not.toHaveBeenCalled();
    passGate();
    await waitFor(() => expect(screen.getByText(START_STRINGS.subtitle)).toBeTruthy());
    expect(apiLogout).toHaveBeenCalled();
    expect(deleteItem).toHaveBeenCalledWith('petprep_auth_token');
  });

  it('QA M2: splash "Odjava" cancelled at the gate does nothing', async () => {
    getItem.mockResolvedValue('parent-token');
    getUser.mockRejectedValueOnce(new TypeError('Network request failed'));
    renderWithQuery(<AppNavigator />);
    fireEvent.press(await screen.findByText('Odjava'));
    fireEvent.press(screen.getByTestId('parental-gate-cancel'));
    expect(apiLogout).not.toHaveBeenCalled();
    expect(deleteItem).not.toHaveBeenCalled();
    expect(screen.getByTestId('splash-offline')).toBeTruthy();
  });

  it('QA M2: splash "Odjava" with a shared child token ends only that child — the other children keep their sessions', async () => {
    const vault = new Map<string, string>([
      ['petprep_auth_token', 'child-2-a'],
      ['petprep_child_sessions', JSON.stringify({ '2': { token: 'child-2-a', parentUserId: 1 }, '5': { token: 'child-5-b', parentUserId: 1 } })],
    ]);
    getItem.mockImplementation(async (key: string) => vault.get(key) ?? null);
    deleteItem.mockImplementation(async (key: string) => {
      vault.delete(key);
    });
    const setItem = SecureStore.setItemAsync as jest.Mock;
    setItem.mockImplementation(async (key: string, value: string) => {
      vault.set(key, value);
    });
    try {
      getUser.mockRejectedValueOnce(new TypeError('Network request failed'));
      apiLogout.mockResolvedValueOnce(undefined);
      renderWithQuery(<AppNavigator />);
      fireEvent.press(await screen.findByText('Odjava'));
      passGate();
      await waitFor(() => expect(screen.getByText(START_STRINGS.subtitle)).toBeTruthy());

      expect(logoutToken).not.toHaveBeenCalled(); // child 5's session is not revoked
      expect(JSON.parse(vault.get('petprep_child_sessions') ?? '{}')).toEqual({ '5': { token: 'child-5-b', parentUserId: 1 } });
      expect(vault.has('petprep_auth_token')).toBe(false);
    } finally {
      getItem.mockReset();
      getItem.mockResolvedValue(null);
      deleteItem.mockReset();
      setItem.mockReset();
    }
  });

  it('QA M1: "Poskusi znova" while /api/user is still in flight joins it — one request, the session restores', async () => {
    jest.useFakeTimers({ doNotFake: ['nextTick', 'setImmediate'] });
    try {
      getItem.mockImplementation(async (key: string) => (key === 'petprep_auth_token' ? 'parent-token' : key === 'petprep_keychain_after_first_unlock' ? '1' : null));
      let answer: (value: unknown) => void = () => undefined;
      getUser.mockReturnValueOnce(new Promise((resolve) => (answer = resolve)));
      renderWithQuery(<AppNavigator />);
      await act(async () => {
        await jest.advanceTimersByTimeAsync(BOOT_WATCHDOG_MS);
      });
      expect(screen.getByTestId('splash-offline')).toBeTruthy();

      fireEvent.press(screen.getByText('Poskusi znova'));
      await act(async () => {
        answer({ id: 1, name: 'Starš', email: 'p@x.si', role: 'parent', pet: null });
        await jest.advanceTimersByTimeAsync(0);
      });
      expect(screen.getByText('PARENT_DASHBOARD')).toBeTruthy();
      expect(getUser).toHaveBeenCalledTimes(1);
      expect(deleteItem).not.toHaveBeenCalled();
    } finally {
      getItem.mockReset();
      getItem.mockResolvedValue(null);
      jest.useRealTimers();
    }
  });
});
