/**
 * M1-14 / M1-16: the child HUD runs on the server state — disabled buttons with hints
 * in the family timezone, API actions with friendly messages, cleaning, live locks
 * (hard stop) and the contract route.
 */
import { act, fireEvent, screen, waitFor } from '@testing-library/react-native';
import { Dimensions, StyleSheet, type ViewStyle } from 'react-native';
import { SafeAreaInsetsContext } from 'react-native-safe-area-context';

import { ApiError, api } from '@/api/client';
import { childPetKey } from '@/hooks/queries/useChildPet';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import { computeHudLayout, METRIC_BAR_WIDTH, METRICS_RESERVED_RIGHT, METRICS_RIGHT } from '@/modules/hud/hudLayout';
import AppNavigator from '@/navigation/AppNavigator';
import ChildHudScreen, { formatAgeMonths, HUD_STRINGS } from '@/screens/ChildHudScreen';
import { ALBUM_STRINGS } from '@/modules/petMedia/album';
import { LOCKED_STRINGS } from '@/screens/LockedScreen';
import { CONTRACT_STRINGS } from '@/screens/ContractScreen';
import { isAwaitingContract, useAppStore } from '@/store/appStore';
import { makeBroadcast, makeLegacyPetProfile, makeLiveChildState, makeMedia, makePet } from '@/test-utils/fixtures';
import { liveVideoPlayers, mockVideoPlayers, playerUris, resetMockVideoPlayers } from '@/test-utils/videoPlayers';
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
// M3-02 / PR #35: the HUD asks about notifications on its first view — no real Alert here.
jest.mock('@/modules/push/pushPrompt', () => ({ maybeAskForPush: jest.fn(() => Promise.resolve('skipped')) }));

// Heavy file (video players, timers): generous per-test budget on slow CI.
jest.setTimeout(20_000);

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
    // M5-R04: a profiled pet shows stage · age, origin and the next stage instead of the old age.
    expect(screen.getByTestId('hud-stage')).toHaveTextContent('Mladiček · 2 meseca');
    expect(screen.getByTestId('hud-profile-sub')).toHaveTextContent('Kupljen pri vzreditelju · 24. 11. 2026 postane mlad pes');
    expect(screen.queryByText(formatAgeMonths(0))).toBeNull();
  });

  it('legacy pet (profile.legacy, null fields): the old age line, nothing new, no crash', async () => {
    getChildPet.mockResolvedValueOnce(makeLiveChildState({ pet: { profile: makeLegacyPetProfile(), age_months: null, origin: null, life_stage: null } }));
    renderWithQuery(<ChildHudScreen />);
    expect(await screen.findByText(formatAgeMonths(0))).toBeTruthy();
    expect(screen.queryByTestId('hud-stage')).toBeNull();
    expect(screen.queryByTestId('hud-profile-sub')).toBeNull();
  });

  it('load error without any state → retry + logout', async () => {
    jest.useFakeTimers({ doNotFake: ['nextTick', 'setImmediate'] });
    try {
      // Hotfix 2026-10-06: a network error is retried twice (2 s, 4 s) before the error screen.
      getChildPet.mockRejectedValue(new TypeError('Network request failed'));
      renderWithQuery(<ChildHudScreen />);
      await act(async () => {
        await jest.advanceTimersByTimeAsync(7_000);
      });
      expect(getChildPet).toHaveBeenCalledTimes(3);
      expect(screen.getByText(HUD_STRINGS.loadFailed)).toBeTruthy();

      getChildPet.mockResolvedValue(makeLiveChildState());
      fireEvent.press(screen.getByText(HUD_STRINGS.retry));
      await act(async () => {
        await jest.advanceTimersByTimeAsync(6_000);
      });
      expect(screen.getByTestId('action-feed')).toBeTruthy();
    } finally {
      jest.useRealTimers();
    }
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
        makeBroadcast({ is_hard_stopped: true, event_type: 'hard_stop_activated', emitted_at: '2026-10-04T10:00:30.000+00:00' }),
      );
    });
    expect(await screen.findByTestId('locked-screen')).toBeTruthy();
    expect(screen.getByText(LOCKED_STRINGS.hard_stop.title)).toBeTruthy();
    expect(useAppStore.getState().lockState).toBe('hard_stop');

    getChildPet.mockResolvedValue(makeLiveChildState({ server_time: '2026-10-04T12:05:00+02:00' }));
    act(() => {
      mockSocket.handler?.(
        makeBroadcast({ is_hard_stopped: false, event_type: 'hard_stop_deactivated', emitted_at: '2026-10-04T10:04:00.000+00:00' }),
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

  it('PR #30 review: the toast sits left of the metric column, never over the bars', async () => {
    waterPet.mockRejectedValueOnce(new TypeError('Network request failed'));
    await renderHud();
    fireEvent.press(screen.getByTestId('action-water'));
    await screen.findByTestId('hud-toast');
    const slot = StyleSheet.flatten(screen.getByTestId('hud-toast-slot').props.style) as ViewStyle;
    expect(slot).toMatchObject({ position: 'absolute', left: 16, right: METRICS_RESERVED_RIGHT, alignItems: 'center' });
    // The slot ends left of the measured column: right edge + bar width.
    expect(StyleSheet.flatten(screen.getByTestId('metric-hunger').props.style).width).toBe(METRIC_BAR_WIDTH);
    expect(StyleSheet.flatten(screen.getByTestId('hud-metrics').props.style).right).toBe(METRICS_RIGHT);
    expect(METRICS_RESERVED_RIGHT).toBeGreaterThan(METRICS_RIGHT + METRIC_BAR_WIDTH);
  });

  describe('2026-10-05 TestFlight fixes', () => {
    const styleOf = (testID: string): ViewStyle => StyleSheet.flatten(screen.getByTestId(testID).props.style) as ViewStyle;
    const METRICS = ['metric-hunger', 'metric-thirst', 'metric-energy', 'metric-hygiene'] as const;

    it('realtime badge: green "V ŽIVO" when live, a calm grey icon (no "BREZ POVEZAVE") while polling', async () => {
      await renderHud();
      expect(screen.getByTestId('hud-ws-live')).toBeTruthy();
      expect(screen.getByText(HUD_STRINGS.ws.live)).toBeTruthy();

      act(() => useAppStore.getState().setWsStatus('disconnected'));
      expect(screen.getByTestId('hud-ws-polling')).toBeTruthy();
      expect(screen.getByLabelText(HUD_STRINGS.ws.polling)).toBeTruthy();
      expect(screen.queryByText('BREZ POVEZAVE')).toBeNull();

      act(() => useAppStore.getState().setWsStatus('connecting'));
      expect(screen.getByText(HUD_STRINGS.ws.connecting)).toBeTruthy();
    });

    it('renders all four metric bars with the computed track height', async () => {
      await renderHud();
      const expected = computeHudLayout({ screenHeight: Dimensions.get('window').height, insets: { top: 0, bottom: 0 } });

      for (const id of METRICS) {
        expect(styleOf(`${id}-track`).height).toBe(expected.metric.trackHeight);
      }
      expect(styleOf('hud-metrics').top).toBe(expected.metricsTop);
      expect(screen.getByLabelText('Čistoča 80%')).toBeTruthy();
    });

    // Jest's window is 1334 pt tall (bars capped at 100 pt) → a very tall measured dock forces a refit.
    it('places header and dock inside the safe area and refits the bars to the measured dock', async () => {
      getChildPet.mockResolvedValue(makeLiveChildState());
      renderWithQuery(
        <SafeAreaInsetsContext.Provider value={{ top: 59, bottom: 34, left: 0, right: 0 }}>
          <ChildHudScreen />
        </SafeAreaInsetsContext.Provider>,
      );
      await screen.findByTestId('action-feed');

      expect(styleOf('hud-header').top).toBe(67);
      expect(styleOf('hud-dock').bottom).toBe(38);
      const before = styleOf('metric-hygiene-track').height as number;

      fireEvent(screen.getByTestId('hud-dock'), 'layout', { nativeEvent: { layout: { x: 0, y: 0, width: 350, height: 500 } } });
      const after = styleOf('metric-hygiene-track').height as number;
      expect(after).toBeLessThan(before);

      const height = Dimensions.get('window').height;
      const layout = computeHudLayout({ screenHeight: height, insets: { top: 59, bottom: 34 }, dockHeight: 500 });
      expect(after).toBe(layout.metric.trackHeight);
      expect(layout.metricsTop + layout.metricsHeight).toBeLessThanOrEqual(height - 38 - 500);
    });
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

describe('ChildHudScreen — AI dog media (M4-03)', () => {
    const IDLE = 'https://api.petprep.si/api/media/2?expires=1&v=idle&signature=a';
    const HUNGRY = 'https://api.petprep.si/api/media/4?expires=1&v=hungry&signature=a';
    const IMG = 'https://api.petprep.si/api/media/1?expires=1&v=img&signature=a';
    const ready = makeMedia({
      status: 'ready',
      reference_image_url: IMG,
      videos: { idle: IDLE, hungry: HUNGRY },
      states: ['idle', 'hungry'],
    });

    beforeEach(() => {
      jest.clearAllMocks();
      mockSocket.handler = null;
      useAppStore.setState(useAppStore.getInitialState(), true);
      signInChild();
      resetMockVideoPlayers();
    });

    it('plays the video of the server pet_state as the main dog visual', async () => {
      await renderHud(makeLiveChildState({ pet: { pet_state: 'hungry', media: ready } }));
      expect(screen.getByTestId('hud-pet-media')).toBeTruthy();
      expect(playerUris()).toEqual([HUNGRY]);
      expect(liveVideoPlayers()[0].playing).toBe(true);
    });

    it('no media yet: the animated avatar placeholder with the mood, pending hint', async () => {
      await renderHud(makeLiveChildState({ pet: { pet_state: 'idle', media: makeMedia({ status: 'pending' }) } }));
      expect(mockVideoPlayers).toHaveLength(0);
      expect(screen.getByText(HUD_STRINGS.moods.idle)).toBeTruthy();
      expect(screen.getByTestId('hud-pet-media-pending')).toBeTruthy();
    });

    it('a broadcast with a new pet_state crossfades to that video', async () => {
      await renderHud(makeLiveChildState({ pet: { pet_state: 'idle', media: ready } }));
      expect(playerUris()).toEqual([IDLE]);
      await act(async () => {
        mockSocket.handler?.(makeBroadcast({ pet_state: 'hungry', hunger_level: 20, media: ready }));
      });
      // TanStack notifies observers on a 0 ms timer.
      await waitFor(() => expect(playerUris()).toEqual([IDLE, HUNGRY]));
    });

    it('M3: at the vet the sick video keeps playing under a translucent grey lock', async () => {
      const sick = makeMedia({ status: 'ready', reference_image_url: IMG, videos: { idle: IDLE, sick: HUNGRY }, states: ['idle', 'sick'] });
      getChildPet.mockResolvedValue(
        makeLiveChildState({
          pet: { pet_state: 'sick', is_ill: true, illness_until: '2026-10-04T16:30:00+00:00', media: sick },
          lock: { is_locked: true, reason: 'ill', until: '2026-10-04T18:30:00+02:00' },
        }),
      );
      renderWithQuery(<AppNavigator />);
      expect(await screen.findByTestId('locked-card-glass')).toBeTruthy();
      expect(screen.getByText(LOCKED_STRINGS.illness.title)).toBeTruthy();
      expect(screen.getByText(LOCKED_STRINGS.illness.body('18:30'))).toBeTruthy();
      const flat = [screen.getByTestId('locked-screen').props.style].flat(3) as Array<{ backgroundColor?: string } | undefined>;
      expect(flat.some((st) => st?.backgroundColor === 'rgba(71, 85, 105, 0.55)')).toBe(true);
      await waitFor(() => expect(playerUris()).toEqual([HUNGRY]));
      expect(liveVideoPlayers()[0].playing).toBe(true);
    });

    it('2026-10-06: a free mutt at the vet plays the sleeping video under the darker "ill" veil', async () => {
      const SLEEPING = 'https://api.petprep.si/api/media/3?expires=1&v=sleep&signature=a';
      const mutt = makeMedia({ status: 'ready', reference_image_url: IMG, videos: { idle: IDLE, sleeping: SLEEPING }, states: ['idle', 'sleeping'] });
      getChildPet.mockResolvedValue(
        makeLiveChildState({
          pet: { pet_state: 'idle', is_ill: true, illness_until: '2026-10-04T16:30:00+00:00', media: mutt },
          lock: { is_locked: true, reason: 'ill', until: '2026-10-04T18:30:00+02:00' },
        }),
      );
      renderWithQuery(<AppNavigator />);
      expect(await screen.findByTestId('locked-card-glass')).toBeTruthy();
      await waitFor(() => expect(playerUris()).toEqual([SLEEPING]));
      expect(useAppStore.getState().hudVideoState).toBe('sleeping');
      const flat = [screen.getByTestId('locked-screen').props.style].flat(3) as Array<{ backgroundColor?: string } | undefined>;
      expect(flat.some((st) => st?.backgroundColor === 'rgba(30, 41, 59, 0.8)')).toBe(true);
    });

    it('M3: game over → opaque lock screen, no video', async () => {
      getChildPet.mockResolvedValue(
        makeLiveChildState({
          pet: { is_game_over: true, media: ready },
          lock: { is_locked: true, reason: 'game_over', until: null },
        }),
      );
      renderWithQuery(<AppNavigator />);
      expect(await screen.findByTestId('locked-card')).toBeTruthy();
      await waitFor(() => expect(screen.getByTestId('hud-pet-media-image', { includeHiddenElements: true })).toBeTruthy());
      expect(liveVideoPlayers()).toHaveLength(0);
    });

    it('an expired URL refetches the child state once', async () => {
      await renderHud(makeLiveChildState({ pet: { pet_state: 'idle', media: ready } }));
      const calls = getChildPet.mock.calls.length;
      await act(async () => {
        mockVideoPlayers[0].emit('statusChange', { status: 'error' });
      });
      await waitFor(() => expect(getChildPet.mock.calls.length).toBe(calls + 1));
    });

    describe('"Moj kuža" album', () => {
      const SLEEP = 'https://api.petprep.si/api/media/3?expires=1&v=sleep&signature=a';
      const album = makeMedia({
        status: 'partial',
        reference_image_url: IMG,
        videos: { idle: IDLE, sleeping: SLEEP },
        states: ['idle', 'sleeping', 'hungry'],
      });

      it('opens from the header, lists the entitled media and pauses the HUD video', async () => {
        await renderHud(makeLiveChildState({ pet: { pet_state: 'idle', media: album } }));
        const hudPlayer = liveVideoPlayers()[0];
        expect(hudPlayer.playing).toBe(true);

        fireEvent.press(screen.getByLabelText(ALBUM_STRINGS.open));
        expect(screen.getByTestId('hud-album')).toBeTruthy();
        expect(useAppStore.getState().isAlbumVisible).toBe(true);
        expect(hudPlayer.playing).toBe(false);
        expect(screen.getByTestId('album-item-photo')).toBeTruthy();
        expect(screen.getByTestId('album-item-sleeping')).toBeTruthy();
        expect(screen.getByTestId('album-item-hungry')).toBeTruthy(); // greyed "Še ni posnetka"
        expect(screen.queryByTestId('album-item-playing')).toBeNull();
      });

      it('plays the selected video with one active player; closing resumes the HUD dog', async () => {
        await renderHud(makeLiveChildState({ pet: { pet_state: 'idle', media: album } }));
        const hudPlayer = liveVideoPlayers()[0];

        fireEvent.press(screen.getByTestId('hud-album-open'));
        fireEvent.press(screen.getByTestId('album-item-sleeping'));
        const playing = liveVideoPlayers().filter((p) => p.playing);
        expect(playing).toHaveLength(1);
        expect(playing[0].source).toEqual({ uri: SLEEP, useCaching: true });

        fireEvent.press(screen.getByTestId('album-close'));
        expect(screen.queryByTestId('hud-album')).toBeNull();
        expect(hudPlayer.playing).toBe(true);
        expect(liveVideoPlayers()).toEqual([hudPlayer]);
      });

      it('an expired album video refetches the child state', async () => {
        await renderHud(makeLiveChildState({ pet: { pet_state: 'idle', media: album } }));
        fireEvent.press(screen.getByTestId('hud-album-open'));
        fireEvent.press(screen.getByTestId('album-item-sleeping'));
        const calls = getChildPet.mock.calls.length;
        const albumPlayer = liveVideoPlayers().find((p) => p.playing);
        await act(async () => {
          albumPlayer?.emit('statusChange', { status: 'error' });
        });
        await waitFor(() => expect(getChildPet.mock.calls.length).toBe(calls + 1));
      });

      it('is not shown while the pet is locked', async () => {
        useAppStore.getState().setAlbumVisible(true);
        await renderHud(
          makeLiveChildState({
            pet: { is_hard_stopped: true, media: album },
            lock: { is_locked: true, reason: 'hard_stopped', until: null },
          }),
        );
        expect(screen.queryByTestId('hud-album')).toBeNull();
      });

      it('PR #31: a lock closes the album for good — lifting it does not reopen it', async () => {
        getChildPet.mockResolvedValue(makeLiveChildState({ pet: { media: album } }));
        renderWithQuery(<AppNavigator />);
        await screen.findByTestId('action-feed');
        fireEvent.press(screen.getByTestId('hud-album-open'));
        expect(screen.getByTestId('hud-album')).toBeTruthy();

        getChildPet.mockResolvedValue(
          makeLiveChildState({
            pet: { is_hard_stopped: true, media: album },
            lock: { is_locked: true, reason: 'hard_stopped' },
            server_time: '2026-10-04T12:01:00+02:00',
          }),
        );
        act(() => {
          mockSocket.handler?.(
            makeBroadcast({ is_hard_stopped: true, event_type: 'hard_stop_activated', emitted_at: '2026-10-04T10:00:30.000+00:00' }),
          );
        });
        expect(await screen.findByTestId('locked-screen')).toBeTruthy();
        expect(useAppStore.getState().isAlbumVisible).toBe(false);

        getChildPet.mockResolvedValue(makeLiveChildState({ pet: { media: album }, server_time: '2026-10-04T12:05:00+02:00' }));
        act(() => {
          mockSocket.handler?.(
            makeBroadcast({ is_hard_stopped: false, event_type: 'hard_stop_deactivated', emitted_at: '2026-10-04T10:04:00.000+00:00' }),
          );
        });
        await waitFor(() => expect(screen.queryByTestId('locked-screen')).toBeNull());
        expect(screen.queryByTestId('hud-album')).toBeNull();
        expect(screen.getByTestId('hud-album-open')).toBeTruthy();
      });

      it('PR #31: no album button while nothing is stored and generation is disabled / failed', async () => {
        for (const status of ['disabled', 'failed'] as const) {
          // Realistic backend payload: `states` present, nothing stored.
          const media = makeMedia({ status, states: ['idle', 'sleeping'] });
          const { unmount } = await renderHud(makeLiveChildState({ pet: { media } }));
          expect(screen.queryByTestId('hud-album-open')).toBeNull();
          unmount();
        }
        // Still generating → the album shows the greyed tiles.
        await renderHud(makeLiveChildState({ pet: { media: makeMedia({ status: 'pending', states: ['idle', 'sleeping'] }) } }));
        fireEvent.press(screen.getByTestId('hud-album-open'));
        expect(screen.getAllByText(ALBUM_STRINGS.missing)).toHaveLength(2);
      });

      it('PR #31: leaving the HUD clears the album flag', async () => {
        const { unmount } = await renderHud(makeLiveChildState({ pet: { media: album } }));
        fireEvent.press(screen.getByTestId('hud-album-open'));
        expect(useAppStore.getState().isAlbumVisible).toBe(true);
        unmount();
        expect(useAppStore.getState().isAlbumVisible).toBe(false);
      });

      it('PR #31: the HUD is hidden from TalkBack / VoiceOver while the album is open', async () => {
        await renderHud(makeLiveChildState({ pet: { media: album } }));
        expect(screen.getByTestId('hud-content').props.importantForAccessibility).toBe('auto');
        fireEvent.press(screen.getByTestId('hud-album-open'));
        const content = screen.getByTestId('hud-content', { includeHiddenElements: true });
        expect(content.props.importantForAccessibility).toBe('no-hide-descendants');
        expect(content.props.accessibilityElementsHidden).toBe(true);
      });
    });
  });
