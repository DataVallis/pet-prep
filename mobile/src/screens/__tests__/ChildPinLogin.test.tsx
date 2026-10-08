/**
 * M2-02: start screen ("Sem starš" / "Sem otrok") and the PIN-only child login,
 * end to end through AppNavigator: PIN → token in SecureStore → contract or HUD.
 */
import { act, fireEvent, screen } from '@testing-library/react-native';
import * as SecureStore from 'expo-secure-store';

import { ApiError, api, type PinLoginResponse } from '@/api/client';
import AppNavigator from '@/navigation/AppNavigator';
import { CHILD_PIN_STRINGS as S } from '@/screens/ChildPinLoginScreen';
import { CONTRACT_STRINGS } from '@/screens/ContractScreen';
import { PARENT_LOGIN_STRINGS } from '@/screens/ParentLoginScreen';
import { START_STRINGS } from '@/screens/StartScreen';
import { useAppStore } from '@/store/appStore';
import { makeMedia, makePetProfile, makePet } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, pinLogin: jest.fn(), getUser: jest.fn(), logout: jest.fn() } };
});
jest.mock('@/screens/ChildHudScreen', () => {
  const { Text } = jest.requireActual<typeof import('react-native')>('react-native');
  return { __esModule: true, default: () => <Text>CHILD_HUD</Text> };
});
jest.mock('@/screens/parent/ParentDashboardScreen', () => {
  const { Text } = jest.requireActual<typeof import('react-native')>('react-native');
  return { __esModule: true, default: () => <Text>PARENT_DASHBOARD</Text> };
});
jest.mock('@/modules/pairing/deviceName', () => ({ deviceName: () => 'samsung SM-A515F' }));

const pinLogin = api.pinLogin as jest.Mock;
const getUser = api.getUser as jest.Mock;
const setItem = SecureStore.setItemAsync as jest.Mock;
const getItem = SecureStore.getItemAsync as jest.Mock;

const NOW = new Date('2026-10-04T10:00:00Z');

function pinLoginResponse(overrides: Partial<PinLoginResponse> = {}): PinLoginResponse {
  return {
    token: 'child-token',
    abilities: ['child'],
    user: { id: 9, name: 'Maja', role: 'child' },
    mode: 'new_pet',
    joined_existing: false,
    family_id: 1,
    pet: {
      id: 7,
      breed_type: 'mutt',
      species: 'dog',
      hunger_level: 100,
      thirst_level: 100,
      energy_level: 100,
      hygiene_level: 100,
      born_at: null,
      awaiting_contract: true,
      is_active: true,
      is_game_over: false,
      pet_dna: { seed: null, prompt_anchor: null, visual_traits: null, reference_image_url: null },
      current_video_url: null,
      media_status: 'disabled',
      media: makeMedia(),
      profile: makePetProfile(),
    },
    awaiting_contract: true,
    ...overrides,
  };
}

async function flush() {
  await act(async () => {
    await jest.advanceTimersByTimeAsync(0);
  });
}

async function openChildPath() {
  renderWithQuery(<AppNavigator />);
  await flush();
  fireEvent.press(screen.getByLabelText(START_STRINGS.child));
  expect(screen.getByText(S.title)).toBeTruthy();
}

function typePin(pin: string) {
  for (const digit of pin) fireEvent.press(screen.getByTestId(`pin-key-${digit}`));
}

describe('Start screen + child PIN login', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    [pinLogin, getUser].forEach((m) => m.mockReset());
    getItem.mockResolvedValue(null);
    jest.useFakeTimers();
    jest.setSystemTime(NOW);
    useAppStore.setState(useAppStore.getInitialState(), true);
  });

  afterEach(() => {
    jest.useRealTimers();
  });

  it('start screen offers exactly two paths; the child path has no e-mail or password field', async () => {
    renderWithQuery(<AppNavigator />);
    await flush();
    expect(screen.getByText(START_STRINGS.parent)).toBeTruthy();
    expect(screen.getByText(START_STRINGS.child)).toBeTruthy();

    fireEvent.press(screen.getByLabelText(START_STRINGS.child));
    expect(screen.queryByPlaceholderText(PARENT_LOGIN_STRINGS.email)).toBeNull();
    expect(screen.queryByPlaceholderText(PARENT_LOGIN_STRINGS.password)).toBeNull();

    fireEvent.press(screen.getByLabelText(S.back));
    fireEvent.press(screen.getByLabelText(START_STRINGS.parent));
    expect(screen.getByText(PARENT_LOGIN_STRINGS.title)).toBeTruthy();
    expect(screen.getByPlaceholderText(PARENT_LOGIN_STRINGS.email)).toBeTruthy();
  });

  it('new pet: the 6th digit sends the PIN → token saved → contract step', async () => {
    pinLogin.mockResolvedValueOnce(pinLoginResponse());
    getUser.mockResolvedValueOnce({ id: 9, name: 'Maja', email: null, role: 'child', pet: makePet({ id: 7, born_at: null, user_id: 9 }) });
    await openChildPath();

    typePin('73491');
    expect(pinLogin).not.toHaveBeenCalled();
    typePin('2');
    expect(screen.getByTestId('pin-login-loading')).toBeTruthy();
    await flush();

    expect(pinLogin).toHaveBeenCalledWith('734912', 'samsung SM-A515F');
    expect(setItem).toHaveBeenCalledWith('petprep_auth_token', 'child-token');
    expect(screen.getByText(CONTRACT_STRINGS.padHint)).toBeTruthy();
    const { user, authToken, pet } = useAppStore.getState();
    expect(authToken).toBe('child-token');
    expect(user).toEqual({ id: 9, name: 'Maja', email: null, role: 'child' });
    expect(pet?.awaiting_contract).toBe(true);
  });

  it('joined a born shared pet: still the contract step (awaiting_contract from pin-login wins)', async () => {
    pinLogin.mockResolvedValueOnce(
      pinLoginResponse({ mode: 'join_pet', joined_existing: true, pet: { ...pinLoginResponse().pet, born_at: '2026-10-01T08:00:00+00:00' } }),
    );
    // /api/user's raw pet is born and has no per-child flag.
    getUser.mockResolvedValueOnce({ id: 9, name: 'Maja', email: null, role: 'child', pet: makePet({ id: 7 }) });
    await openChildPath();
    typePin('734912');
    await flush();

    expect(screen.getByText(CONTRACT_STRINGS.padHint)).toBeTruthy();
    expect(screen.queryByText('CHILD_HUD')).toBeNull();
  });

  it('relogin on a new device (contract already signed) → straight to the HUD', async () => {
    const pet = makePet({ id: 7, user_id: 9 });
    pinLogin.mockResolvedValueOnce(
      pinLoginResponse({ mode: 'relogin', awaiting_contract: false, pet: { ...pinLoginResponse().pet, born_at: pet.born_at, awaiting_contract: false } }),
    );
    getUser.mockResolvedValueOnce({ id: 9, name: 'Maja', email: null, role: 'child', pet });
    await openChildPath();
    typePin('734912');
    await flush();

    expect(screen.getByText('CHILD_HUD')).toBeTruthy();
    expect(useAppStore.getState().pet).toEqual({ ...pet, awaiting_contract: false });
  });

  it('invalid PIN: friendly message without saying what was wrong, digits cleared, no session', async () => {
    pinLogin.mockRejectedValueOnce(new ApiError('This code is not valid.', 422, { reason: 'invalid_pin' }));
    await openChildPath();
    typePin('111111');
    await flush();

    expect(screen.getByTestId('pin-login-error')).toHaveTextContent(S.invalid);
    expect(screen.queryAllByTestId('pin-slot-filled')).toHaveLength(0);
    expect(setItem).not.toHaveBeenCalled();
    expect(useAppStore.getState().authToken).toBeNull();

    typePin('2'); // typing again clears the message
    expect(screen.queryByTestId('pin-login-error')).toBeNull();
  });

  it('pin_not_usable gets the same answer as a wrong PIN', async () => {
    pinLogin.mockRejectedValueOnce(new ApiError('x', 422, { reason: 'pin_not_usable' }));
    await openChildPath();
    typePin('111111');
    await flush();
    expect(screen.getByTestId('pin-login-error')).toHaveTextContent(S.invalid);
  });

  it('M5-R06-01 app_update_required (a cat on a build without species_cat): asks to update the app, no session', async () => {
    pinLogin.mockRejectedValueOnce(new ApiError('x', 422, { reason: 'app_update_required' }));
    await openChildPath();
    typePin('111111');
    await flush();
    expect(screen.getByTestId('pin-login-error')).toHaveTextContent(S.updateRequired);
    expect(S.updateRequired).toBe('Posodobi aplikacijo, da lahko skrbiš za tega ljubljenčka.');
    expect(screen.queryAllByTestId('pin-slot-filled')).toHaveLength(0);
    expect(useAppStore.getState().authToken).toBeNull();
  });

  it('429: counts down from Retry-After with the keypad locked, then lets the child try again', async () => {
    pinLogin.mockRejectedValueOnce(new ApiError('Too many attempts.', 429, { reason: 'too_many_attempts', retry_after: 90 }, 90));
    await openChildPath();
    typePin('111111');
    await flush();

    expect(screen.getByTestId('pin-login-error')).toHaveTextContent(S.rateLimited('1:30'));
    typePin('2');
    expect(screen.queryAllByTestId('pin-slot-filled')).toHaveLength(0); // keypad locked

    act(() => {
      jest.advanceTimersByTime(30_000);
    });
    expect(screen.getByTestId('pin-login-error')).toHaveTextContent(S.rateLimited('1:00'));

    act(() => {
      jest.advanceTimersByTime(60_000);
    });
    expect(screen.queryByTestId('pin-login-error')).toBeNull();
    typePin('2');
    expect(screen.queryAllByTestId('pin-slot-filled')).toHaveLength(1);
  });

  it('429 without a Retry-After header uses retry_after from the body', async () => {
    pinLogin.mockRejectedValueOnce(new ApiError('Too many attempts.', 429, { reason: 'too_many_attempts', retry_after: 600 }));
    await openChildPath();
    typePin('111111');
    await flush();
    expect(screen.getByTestId('pin-login-error')).toHaveTextContent(S.rateLimited('10:00'));
  });

  it('offline: keeps the digits and "Poskusi znova" re-sends the same PIN', async () => {
    pinLogin
      .mockRejectedValueOnce(new TypeError('Network request failed'))
      .mockResolvedValueOnce(pinLoginResponse({ awaiting_contract: false, pet: { ...pinLoginResponse().pet, born_at: '2026-10-01T08:00:00+00:00', awaiting_contract: false } }));
    getUser.mockRejectedValueOnce(new TypeError('Network request failed')); // falls back to the pin-login pet
    await openChildPath();
    typePin('734912');
    await flush();

    expect(screen.getByTestId('pin-login-error')).toHaveTextContent(S.offline);
    expect(screen.queryAllByTestId('pin-slot-filled')).toHaveLength(6);

    fireEvent.press(screen.getByText(S.retry));
    await flush();
    expect(pinLogin).toHaveBeenCalledTimes(2);
    expect(pinLogin.mock.calls[1]).toEqual(pinLogin.mock.calls[0]);
    expect(screen.getByText('CHILD_HUD')).toBeTruthy();
    expect(useAppStore.getState().pet?.id).toBe(7);
  });

  it('server error is retryable too', async () => {
    pinLogin.mockRejectedValueOnce(new ApiError('Server Error', 500));
    await openChildPath();
    typePin('734912');
    await flush();
    expect(screen.getByTestId('pin-login-error')).toHaveTextContent(S.server);
    expect(screen.getByText(S.retry)).toBeTruthy();
  });

  it('the delete key removes the last digit', async () => {
    await openChildPath();
    typePin('123');
    fireEvent.press(screen.getByTestId('pin-key-delete'));
    expect(screen.queryAllByTestId('pin-slot-filled')).toHaveLength(2);
    expect(screen.getByLabelText(S.digitsEntered(2))).toBeTruthy();
  });
});
