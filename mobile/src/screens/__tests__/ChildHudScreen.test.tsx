/**
 * M1-14 / M1-16: the child HUD runs on the server state — disabled buttons with hints
 * in the family timezone, API actions with friendly messages, cleaning, live locks
 * (hard stop) and the contract route.
 */
import { act, fireEvent, screen, waitFor } from '@testing-library/react-native';

import { ApiError, api } from '@/api/client';
import { childPetKey } from '@/hooks/queries/useChildPet';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import AppNavigator from '@/navigation/AppNavigator';
import ChildHudScreen, { formatAgeMonths, HUD_STRINGS } from '@/screens/ChildHudScreen';
import { LOCKED_STRINGS } from '@/screens/LockedScreen';
import { CONTRACT_STRINGS } from '@/screens/ContractScreen';
import { isAwaitingContract, useAppStore } from '@/store/appStore';
import { makeBroadcast, makeLiveChildState, makePet } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';
import type { PetUpdatedBroadcast } from '@/types';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: {
      ...actual.api,
      getChildPet: jest.fn(),
      feedPet: jest.fn(),
      waterPet: jest.fn(),
      cleanPet: jest.fn(),
      syncSteps: jest.fn(),
      logout: jest.fn(),
    },
  };
});

// Capture the HUD's broadcast handler instead of opening a socket.
const mockSocket: { handler: ((event: PetUpdatedBroadcast) => void) | null } = { handler: null };
jest.mock('@/hooks/usePetWebSocket', () => ({
  usePetWebSocket: jest.fn((_petId: number | null, handler?: (event: PetUpdatedBroadcast) => void) => {
    mockSocket.handler = handler ?? null;
  }),
}));

// The session is set up by the test (signInChild), not restored from SecureStore.
jest.mock('@/modules/session/useSessionBootstrap', () => ({ useSessionBootstrap: () => ({ retry: jest.fn() }) }));

const getChildPet = api.getChildPet as jest.Mock;
const feedPet = api.feedPet as jest.Mock;
const waterPet = api.waterPet as jest.Mock;
const cleanPet = api.cleanPet as jest.Mock;
const syncSteps = api.syncSteps as jest.Mock;

function signInChild() {
  useAppStore.getState().signIn({
    token: 'child-token',
    user: { id: 2, name: 'Maja', email: null, role: 'child' },
    pet: makePet({ id: 7, born_at: '2026-10-01T08:00:00Z' }),
    awaitingContract: false,
  });
  useAppStore.getState().setWsStatus('connected');
}

async function renderHud(state = makeLiveChildState()) {
  getChildPet.mockResolvedValue(state);
  const utils = renderWithQuery(<ChildHudScreen />);
  await screen.findByTestId('action-feed');
  return utils;
}

function isDisabled(testID: string): boolean {
  return screen.getByTestId(testID).props.accessibilityState?.disabled === true;
}

describe('ChildHudScreen', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockSocket.handler = null;
    useAppStore.setState(useAppStore.getInitialState(), true);
    signInChild();
  });

  it('shows a loading state, then the server metrics (no local fake values)', async () => {
    let resolve: (value: unknown) => void = () => undefined;
    getChildPet.mockReturnValueOnce(new Promise((r) => (resolve = r)));
    renderWithQuery(<ChildHudScreen />);
    expect(screen.getByText(HUD_STRINGS.loading)).toBeTruthy();

    await act(async () => resolve(makeLiveChildState()));
    expect(await screen.findByText('60%')).toBeTruthy(); // hunger from the server
    expect(screen.getByText('Mešanček')).toBeTruthy();
    expect(screen.getByText(formatAgeMonths(0))).toBeTruthy();
  });

  it('load error without any state → retry + logout', async () => {
    getChildPet.mockRejectedValueOnce(new TypeError('Network request failed'));
    renderWithQuery(<ChildHudScreen />);
    expect(await screen.findByText(HUD_STRINGS.loadFailed)).toBeTruthy();

    getChildPet.mockResolvedValueOnce(makeLiveChildState());
    fireEvent.press(screen.getByText(HUD_STRINGS.retry));
    expect(await screen.findByTestId('action-feed')).toBeTruthy();
  });

  it('outside the feed window: feed disabled with "ob 17:00" (Ljubljana), water enabled', async () => {
    await renderHud();
    expect(isDisabled('action-feed')).toBe(true);
    expect(screen.getByText('ob 17:00')).toBeTruthy();
    expect(isDisabled('action-water')).toBe(false);
    expect(screen.getByText('1.250/4.000')).toBeTruthy();
  });

  it('hints use the family timezone (New York family)', async () => {
    await renderHud(
      makeLiveChildState({
        timezone: 'America/New_York',
        server_time: '2026-10-04T21:30:00-04:00',
        feeding: { next_feed_window: { start: '2026-10-05T10:00:00Z', end: '2026-10-05T14:00:00Z' } },
        water: { can_water: false, next_allowed_at: '2026-10-04T23:15:00-04:00' },
      }),
    );
    expect(screen.getByText('jutri ob 06:00')).toBeTruthy();
    expect(screen.getByText('ob 23:15')).toBeTruthy();
  });

  it('a mess blocks feed + water ("Najprej pospravi") and opens the cleaning game', async () => {
    await renderHud(
      makeLiveChildState({
        pet: { hygiene_level: 0, needs_cleaning: true },
        feeding: { can_feed: false },
        water: { can_water: false },
      }),
    );
    expect(isDisabled('action-feed')).toBe(true);
    expect(isDisabled('action-water')).toBe(true);
    expect(screen.getAllByText('Najprej pospravi')).toHaveLength(2);
    expect(screen.getByTestId('cleaning-overlay')).toBeTruthy();
  });

  it('cleaning all spots → POST clean → overlay gone, "Bravo" toast', async () => {
    cleanPet.mockResolvedValueOnce({ status: 'accepted', state: makeLiveChildState({ pet: { hygiene_level: 100 } }) });
    const { client } = await renderHud(
      makeLiveChildState({ pet: { hygiene_level: 0, needs_cleaning: true }, water: { can_water: false } }),
    );
    for (let i = 0; i < 5; i++) fireEvent.press(screen.getByTestId(`dirt-spot-${i}`));

    await waitFor(() => expect(cleanPet).toHaveBeenCalledTimes(1));
    expect(await screen.findByText('Bravo, vse je čisto!')).toBeTruthy();
    expect(screen.queryByTestId('cleaning-overlay')).toBeNull();
    expect(client.getQueryData<ChildPetView>(childPetKey)?.pet.hygiene_level).toBe(100);
  });

  it('water press → API, optimistic 100 %, server state and a friendly toast', async () => {
    let resolve: (value: unknown) => void = () => undefined;
    waterPet.mockReturnValueOnce(new Promise((r) => (resolve = r)));
    await renderHud();
    fireEvent.press(screen.getByTestId('action-water'));

    await waitFor(() => expect(waterPet).toHaveBeenCalledTimes(1));
    expect(screen.getAllByText('100%').length).toBeGreaterThanOrEqual(1);

    await act(async () =>
      resolve({
        status: 'accepted',
        state: makeLiveChildState({
          pet: { thirst_level: 100 },
          water: { can_water: false, used_today: 2, remaining_today: 1, next_allowed_at: '2026-10-04T15:00:00+02:00' },
        }),
      }),
    );
    expect(await screen.findByText('Sveža voda! Kuža je odžejan.')).toBeTruthy();
    expect(isDisabled('action-water')).toBe(true);
    expect(screen.getByText('ob 15:00')).toBeTruthy();
  });

  it('feed 422 outside_feed_window → "Kuža bo lačen spet ob 17:00." and the value rolls back', async () => {
    const state = makeLiveChildState({ feeding: { can_feed: true } });
    feedPet.mockRejectedValueOnce(
      new ApiError('refused', 422, {
        status: 'refused',
        reason: 'outside_feed_window',
        next_allowed_at: '2026-10-04T17:00:00+02:00',
        state: makeLiveChildState(),
      }),
    );
    await renderHud(state);
    fireEvent.press(screen.getByTestId('action-feed'));

    expect(await screen.findByText('Kuža bo lačen spet ob 17:00.')).toBeTruthy();
    expect(screen.getByText('60%')).toBeTruthy();
    expect(isDisabled('action-feed')).toBe(true);
  });

  it('water 422 water_too_soon → names the time', async () => {
    waterPet.mockRejectedValueOnce(
      new ApiError('refused', 422, {
        status: 'refused',
        reason: 'water_too_soon',
        next_allowed_at: '2026-10-04T14:10:00+02:00',
        state: makeLiveChildState({ water: { can_water: false, next_allowed_at: '2026-10-04T14:10:00+02:00' } }),
      }),
    );
    await renderHud();
    fireEvent.press(screen.getByTestId('action-water'));
    expect(await screen.findByText('Posoda je še polna. Novo vodo lahko daš ob 14:10.')).toBeTruthy();
  });

  it('offline press → "Ni povezave" and the optimistic value is rolled back', async () => {
    waterPet.mockRejectedValueOnce(new TypeError('Network request failed'));
    await renderHud();
    fireEvent.press(screen.getByTestId('action-water'));
    expect(await screen.findByText('Ni povezave. Preveri internet in poskusi znova.')).toBeTruthy();
    expect(screen.getByText('50%')).toBeTruthy();
    expect(isDisabled('action-water')).toBe(false);
  });

  it('423 ill → lock overlay state with the vet end time in family time', async () => {
    waterPet.mockRejectedValueOnce(
      new ApiError('locked', 423, {
        status: 'locked',
        reason: 'ill',
        locked_until: '2026-10-04T18:30:00+02:00',
        state: makeLiveChildState({
          pet: { is_ill: true, illness_until: '2026-10-04T18:30:00+02:00' },
          lock: { is_locked: true, reason: 'ill', until: '2026-10-04T18:30:00+02:00' },
          water: { can_water: false },
        }),
      }),
    );
    await renderHud();
    fireEvent.press(screen.getByTestId('action-water'));

    await waitFor(() => expect(useAppStore.getState().lockState).toBe('illness'));
    expect(useAppStore.getState().lockDetails).toEqual({ until: '2026-10-04T18:30:00+02:00', timezone: 'Europe/Ljubljana' });
    expect(await screen.findByText('Kuža je pri veterinarju do 18:30.')).toBeTruthy();
  });

  it('423 contract_required → the session routes to the contract step', async () => {
    feedPet.mockRejectedValueOnce(
      new ApiError('locked', 423, {
        status: 'locked',
        reason: 'contract_required',
        locked_until: null,
        state: makeLiveChildState({
          pet: { awaiting_contract: true },
          lock: { is_locked: true, reason: 'contract_required' },
        }),
      }),
    );
    await renderHud(makeLiveChildState({ feeding: { can_feed: true } }));
    fireEvent.press(screen.getByTestId('action-feed'));

    await waitFor(() => expect(isAwaitingContract(useAppStore.getState().pet)).toBe(true));
    expect(useAppStore.getState().lockState).toBe('none');
  });

  it('hard stop from a broadcast locks live; lifting it unlocks (M1-16)', async () => {
    getChildPet.mockResolvedValue(makeLiveChildState());
    renderWithQuery(<AppNavigator />);
    await screen.findByTestId('action-feed');
    expect(screen.queryByTestId('locked-screen')).toBeNull();

    getChildPet.mockResolvedValue(
      makeLiveChildState({
        pet: { is_hard_stopped: true },
        lock: { is_locked: true, reason: 'hard_stopped' },
        feeding: { can_feed: false },
        water: { can_water: false },
        server_time: '2026-10-04T12:01:00+02:00',
      }),
    );
    act(() => {
      mockSocket.handler?.(
        makeBroadcast({ is_hard_stopped: true, event_type: 'hard_stop_activated', emitted_at: '2026-10-04T10:00:30.000Z' }),
      );
    });
    expect(await screen.findByTestId('locked-screen')).toBeTruthy();
    expect(screen.getByText(LOCKED_STRINGS.hard_stop.title)).toBeTruthy();
    expect(useAppStore.getState().lockState).toBe('hard_stop');

    getChildPet.mockResolvedValue(makeLiveChildState({ server_time: '2026-10-04T12:05:00+02:00' }));
    act(() => {
      mockSocket.handler?.(
        makeBroadcast({ is_hard_stopped: false, event_type: 'hard_stop_deactivated', emitted_at: '2026-10-04T10:04:00.000Z' }),
      );
    });
    await waitFor(() => expect(screen.queryByTestId('locked-screen')).toBeNull());
    expect(useAppStore.getState().lockState).toBe('none');
  });

  it('a session restored with a hard-stopped pet is locked before the HUD loads', () => {
    useAppStore.getState().signIn({
      token: 't',
      user: { id: 2, name: 'Maja', email: null, role: 'child' },
      pet: makePet({ is_hard_stopped: true }),
    });
    expect(useAppStore.getState().lockState).toBe('hard_stop');
  });

  it('contract_required in the polled state shows the contract screen instead of the HUD', async () => {
    getChildPet.mockResolvedValue(
      makeLiveChildState({ pet: { awaiting_contract: true }, lock: { is_locked: true, reason: 'contract_required' } }),
    );
    renderWithQuery(<AppNavigator />);
    expect(await screen.findByText(CONTRACT_STRINGS.padHint)).toBeTruthy();
  });

  it('walk opens the overlay with server steps / goal and energy', async () => {
    await renderHud();
    fireEvent.press(screen.getByTestId('action-walk'));
    expect(screen.getByTestId('walk-overlay')).toBeTruthy();
    expect(screen.getByText('1.250 / 4.000 korakov')).toBeTruthy();
    expect(screen.getByText('Energija 30 %')).toBeTruthy();
  });

  it('m4: closing the walk overlay sends the new steps right away', async () => {
    const { Pedometer } = jest.requireMock<typeof import('expo-sensors')>('expo-sensors');
    await renderHud();
    await waitFor(() => expect(Pedometer.getStepCountAsync).toHaveBeenCalledTimes(1)); // mount sync: 0 steps → nothing sent
    (Pedometer.getStepCountAsync as jest.Mock).mockResolvedValueOnce({ steps: 1900 });
    syncSteps.mockResolvedValueOnce({
      status: 'accepted',
      accepted_steps: 650,
      steps_today: 1900,
      energy_level: 48,
      state: makeLiveChildState({ steps: { steps_today: 1900, my_steps_today: 1900, energy_level: 48 } }),
    });

    fireEvent.press(screen.getByTestId('action-walk'));
    fireEvent.press(screen.getByLabelText('Zapri'));

    await waitFor(() => expect(syncSteps).toHaveBeenCalledWith(expect.objectContaining({ steps_today: 1900 })));
    expect(screen.queryByTestId('walk-overlay')).toBeNull();
    expect(await screen.findByText('1.900/4.000')).toBeTruthy();
  });
});

describe('formatAgeMonths', () => {
  it('Slovenian forms', () => {
    expect(formatAgeMonths(0)).toBe('STAROST: 0 MESECEV');
    expect(formatAgeMonths(1)).toBe('STAROST: 1 MESEC');
    expect(formatAgeMonths(2)).toBe('STAROST: 2 MESECA');
    expect(formatAgeMonths(3)).toBe('STAROST: 3 MESECI');
    expect(formatAgeMonths(11)).toBe('STAROST: 11 MESECEV');
  });
});
