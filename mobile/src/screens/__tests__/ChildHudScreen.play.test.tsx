/**
 * M5-R05 in the child HUD: "Igra" only for a pet with play (disabled with its reason while
 * the server says no), the invitation card (accept → the game, "Mogoče kasneje" → the chip),
 * a whole ball game reported once, the optimistic happy dog then the server's state,
 * "Kuža je vesel" + hearts for 30 minutes (timer), live invitations via the Echo handler,
 * a 422 closing the layer with a kind text, and a lock closing it.
 */
import { act, fireEvent, screen } from '@testing-library/react-native';

import { ApiError, api } from '@/api/client';
import ChildHudScreen from '@/screens/ChildHudScreen';
import { FETCH_MS } from '@/modules/play/play';
import { useAppStore } from '@/store/appStore';
import { makeBroadcast, makeLiveChildState, makePet, makePlayState } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';
import type { PetUpdatedBroadcast } from '@/types';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: {
      ...actual.api,
      getChildPet: jest.fn(),
      playWithPet: jest.fn(),
      syncSteps: jest.fn(),
      logout: jest.fn(),
    },
  };
});

const mockSocket: { handler: ((event: PetUpdatedBroadcast) => void) | null } = { handler: null };
jest.mock('@/hooks/usePetWebSocket', () => ({
  usePetWebSocket: jest.fn((_petId: number | null, handler?: (event: PetUpdatedBroadcast) => void) => {
    mockSocket.handler = handler ?? null;
  }),
}));
jest.mock('@/modules/session/useSessionBootstrap', () => ({ useSessionBootstrap: () => ({ retry: jest.fn() }) }));
jest.mock('@/modules/push/pushPrompt', () => ({ maybeAskForPush: jest.fn(() => Promise.resolve('skipped')) }));

jest.setTimeout(30_000);

const getChildPet = api.getChildPet as jest.Mock;
const playWithPet = api.playWithPet as jest.Mock;

const HAPPY_UNTIL = '2026-10-04T12:30:00+02:00';
const INVITE = { id: 12, kind: 'play' as const, expires_at: '2026-10-04T14:00:00+02:00' };

async function flush(ms = 0) {
  await act(async () => {
    await jest.advanceTimersByTimeAsync(ms);
  });
}

async function renderHud(state: ReturnType<typeof makeLiveChildState>) {
  getChildPet.mockResolvedValue(state);
  const utils = renderWithQuery(<ChildHudScreen />);
  await flush();
  await flush();
  expect(screen.getByTestId('action-feed', { includeHiddenElements: true })).toBeTruthy();
  return utils;
}

const isDisabled = (testID: string) => screen.getByTestId(testID).props.accessibilityState?.disabled === true;

describe('ChildHudScreen — play & cuddle (M5-R05)', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    playWithPet.mockReset();
    jest.useFakeTimers({ doNotFake: ['nextTick', 'setImmediate'] });
    jest.setSystemTime(new Date('2026-10-04T12:00:00+02:00'));
    mockSocket.handler = null;
    useAppStore.setState(useAppStore.getInitialState(), true);
    useAppStore.getState().signIn({
      token: 'child-token',
      user: { id: 2, name: 'Maja', email: null, role: 'child' },
      pet: makePet({ id: 7, born_at: '2026-10-01T08:00:00Z' }),
      awaitingContract: false,
    });
    useAppStore.getState().setWsStatus('connected');
  });

  afterEach(() => {
    jest.useRealTimers();
  });

  it('no play (free mutt / legacy / older server): no "Igra"', async () => {
    const { unmount } = await renderHud(makeLiveChildState());
    expect(screen.queryByTestId('hud-play-open')).toBeNull();
    unmount();
    await renderHud(makeLiveChildState({ play: 'absent' }));
    expect(screen.queryByTestId('hud-play-open')).toBeNull();
  });

  it('"Igra" disabled with its reason while the server says no', async () => {
    await renderHud(makeLiveChildState({ play: makePlayState({ can_play: false }), pet: { pet_state: 'sleeping' } }));
    expect(isDisabled('hud-play-open')).toBe(true);
    expect(screen.getByTestId('hud-play-blocked').props.children).toBe('Kuža spi. Igrata se, ko se zbudi.');
  });

  it('a whole ball game: pick, 3 throws, one request, optimistic happy dog, then the server state', async () => {
    let resolve: (value: unknown) => void = () => undefined;
    playWithPet.mockReturnValueOnce(new Promise((res) => (resolve = res)));
    await renderHud(makeLiveChildState({ play: makePlayState() }));
    expect(screen.queryByTestId('hud-happy')).toBeNull();

    fireEvent.press(screen.getByTestId('hud-play-open'));
    expect(screen.getByTestId('play-pick')).toBeTruthy();
    fireEvent.press(screen.getByTestId('play-pick-ball'));
    for (let i = 0; i < 3; i += 1) {
      fireEvent.press(screen.getByTestId('play-throw'));
      await flush(FETCH_MS);
    }
    await flush();
    expect(playWithPet).toHaveBeenCalledTimes(1);
    expect(playWithPet).toHaveBeenCalledWith('play');
    expect(screen.getByText('Hvala za igro! Kuža je ves vesel.')).toBeTruthy();
    // Optimistic: happy already (the badge sits under the modal layer).
    expect(screen.getByTestId('hud-happy', { includeHiddenElements: true })).toBeTruthy();

    await act(async () =>
      resolve({
        status: 'accepted',
        play: { kind: 'play', source: 'free' },
        state: makeLiveChildState({ play: makePlayState({ mood: { happy_until: HAPPY_UNTIL, scene: 'playing' } }) }),
      }),
    );
    await flush();
    fireEvent.press(screen.getByTestId('play-done-close'));
    expect(screen.queryByTestId('play-overlay')).toBeNull();
    expect(screen.getByTestId('hud-happy')).toBeTruthy();
    expect(screen.getByText('Kuža je vesel')).toBeTruthy();
    // No `playing` video (media disabled): hearts are drawn in the app.
    expect(screen.getByTestId('hud-hearts', { includeHiddenElements: true })).toBeTruthy();
  });

  it('happy mood ends by the clock (30 min)', async () => {
    await renderHud(makeLiveChildState({ play: makePlayState({ mood: { happy_until: HAPPY_UNTIL, scene: 'playing' } }) }));
    expect(screen.getByTestId('hud-happy')).toBeTruthy();
    await flush(29 * 60_000);
    expect(screen.getByTestId('hud-happy')).toBeTruthy();
    await flush(61_000);
    expect(screen.queryByTestId('hud-happy')).toBeNull();
    expect(screen.queryByTestId('hud-hearts', { includeHiddenElements: true })).toBeNull();
  });

  it('a need wins over the happy mood (hungry dog: no badge)', async () => {
    await renderHud(
      makeLiveChildState({ play: makePlayState({ mood: { happy_until: HAPPY_UNTIL, scene: 'playing' } }), pet: { pet_state: 'hungry' } }),
    );
    expect(screen.queryByTestId('hud-happy')).toBeNull();
  });

  it('invitation: card instead of the chip; accept opens the ball game; no countdown', async () => {
    await renderHud(makeLiveChildState({ play: makePlayState({ invitation: INVITE }) }));
    expect(screen.getByTestId('hud-play-invitation-play')).toBeTruthy();
    expect(screen.getByText('Kuža ti prinaša žogo. Se igrava?')).toBeTruthy();
    expect(screen.queryByTestId('hud-play-open')).toBeNull();
    fireEvent.press(screen.getByTestId('hud-play-invitation-accept'));
    expect(screen.getByTestId('play-ball')).toBeTruthy();
  });

  it('"Mogoče kasneje" hides the invitation (the chip comes back); expiry hides it quietly', async () => {
    const { unmount } = await renderHud(makeLiveChildState({ play: makePlayState({ invitation: { ...INVITE, kind: 'cuddle' } }) }));
    expect(screen.getByText('Kuža se stisne k tebi. Ga pobožaš?')).toBeTruthy();
    fireEvent.press(screen.getByTestId('hud-play-invitation-dismiss'));
    expect(screen.queryByTestId('hud-play-invitation-cuddle')).toBeNull();
    expect(screen.getByTestId('hud-play-open')).toBeTruthy();
    unmount();

    // The client hides it by its own clock (even if a refetch still carried it).
    const expiring = makePlayState({ invitation: { ...INVITE, expires_at: '2026-10-04T12:10:00+02:00' } });
    getChildPet.mockImplementation(() =>
      Promise.resolve(makeLiveChildState({ play: expiring, server_time: new Date().toISOString() })),
    );
    const utils = renderWithQuery(<ChildHudScreen />);
    await flush();
    await flush();
    expect(utils.getByTestId('hud-play-invitation-play')).toBeTruthy();
    await flush(10 * 60_000 + 1_000);
    expect(screen.queryByTestId('hud-play-invitation-play')).toBeNull();
  });

  it('live: an invitation arrives with a broadcast', async () => {
    await renderHud(makeLiveChildState({ play: makePlayState() }));
    expect(screen.queryByTestId('hud-play-invitation-cuddle')).toBeNull();
    getChildPet.mockResolvedValue(makeLiveChildState({ play: makePlayState({ invitation: { ...INVITE, kind: 'cuddle' } }) }));
    act(() => {
      mockSocket.handler?.(
        makeBroadcast({
          event_type: 'play',
          emitted_at: '2026-10-04T10:00:30.000Z',
          play: makePlayState({ invitation: { ...INVITE, kind: 'cuddle' } }),
        }),
      );
    });
    await flush();
    expect(screen.getByTestId('hud-play-invitation-cuddle')).toBeTruthy();
  });

  it('422 play_not_available: the layer closes with a kind text', async () => {
    playWithPet.mockRejectedValueOnce(
      new ApiError('refused', 422, {
        status: 'refused',
        reason: 'play_not_available',
        next_allowed_at: '2026-10-04T20:00:00+02:00',
        state: makeLiveChildState({ play: makePlayState({ can_play: false }), pet: { pet_state: 'sleeping' } }),
      }),
    );
    await renderHud(makeLiveChildState({ play: makePlayState() }));
    fireEvent.press(screen.getByTestId('hud-play-open'));
    fireEvent.press(screen.getByTestId('play-pick-cuddle'));
    fireEvent(screen.getByTestId('play-hold'), 'accessibilityAction', { nativeEvent: { actionName: 'activate' } });
    await flush();
    await flush();
    expect(playWithPet).toHaveBeenCalledWith('cuddle');
    expect(screen.queryByTestId('play-overlay')).toBeNull();
    expect(screen.getByTestId('hud-toast').props.children.props.children).toBe('Kuža spi. Igrata se lahko ob 20:00.');
    expect(isDisabled('hud-play-open')).toBe(true);
  });

  it('a lock closes the layer', async () => {
    await renderHud(makeLiveChildState({ play: makePlayState() }));
    fireEvent.press(screen.getByTestId('hud-play-open'));
    expect(screen.getByTestId('play-overlay')).toBeTruthy();
    act(() => {
      mockSocket.handler?.(makeBroadcast({ is_hard_stopped: true, event_type: 'hard_stop', emitted_at: '2026-10-04T10:00:30.000Z', play: makePlayState() }));
    });
    await flush();
    expect(screen.queryByTestId('play-overlay')).toBeNull();
    expect(useAppStore.getState().playOverlay).toBeNull();
  });
});
