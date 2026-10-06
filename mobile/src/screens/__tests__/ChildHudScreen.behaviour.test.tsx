/**
 * M5-R02 in the child HUD: "Pelji ven" for a puppy (countdown ticks with fake timers,
 * toasts, disabled while locked), legacy pets unchanged, accident → cleaning game with
 * puddles, chewed slipper → its own button (never the scrub overlay), free-tier graphic
 * vs premium scene video, realtime behaviour from PetUpdated.
 */
import { act, fireEvent, screen, waitFor } from '@testing-library/react-native';

import { ApiError, api } from '@/api/client';
import ChildHudScreen, { HUD_STRINGS } from '@/screens/ChildHudScreen';
import { CLEANING_STRINGS } from '@/components/CleaningOverlay';
import { BEHAVIOUR_STRINGS } from '@/modules/behaviour/behaviour';
import { useAppStore } from '@/store/appStore';
import {
  makeBehaviourEvent,
  makeBroadcast,
  makeLegacyPetProfile,
  makeLiveChildState,
  makeMedia,
  makePet,
  makeTakeOut,
} from '@/test-utils/fixtures';
import { playerUris, resetMockVideoPlayers } from '@/test-utils/videoPlayers';
import { renderWithQuery } from '@/test-utils/renderWithQuery';
import type { PetUpdatedBroadcast } from '@/types';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: {
      ...actual.api,
      getChildPet: jest.fn(),
      cleanPet: jest.fn(),
      takeOutPet: jest.fn(),
      resolveChewing: jest.fn(),
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

jest.setTimeout(20_000);

const getChildPet = api.getChildPet as jest.Mock;
const cleanPet = api.cleanPet as jest.Mock;
const takeOutPet = api.takeOutPet as jest.Mock;
const resolveChewing = api.resolveChewing as jest.Mock;

const puppy = (behaviour: Parameters<typeof makeLiveChildState>[0] = {}) =>
  makeLiveChildState({ behaviour: { take_out: makeTakeOut(), can_take_out: true }, ...behaviour });

const chewingOnly = (media = makeMedia()) =>
  makeLiveChildState({
    pet: { hygiene_level: 0, needs_cleaning: true, pet_state: 'sick', media },
    behaviour: { active_events: [makeBehaviourEvent('chewing')], scene: 'chewing', can_resolve_chewing: true },
  });

async function renderHud(state: ReturnType<typeof makeLiveChildState>) {
  getChildPet.mockResolvedValue(state);
  const utils = renderWithQuery(<ChildHudScreen />);
  await screen.findByTestId('action-feed');
  return utils;
}

function isDisabled(testID: string): boolean {
  return screen.getByTestId(testID).props.accessibilityState?.disabled === true;
}

describe('ChildHudScreen — behaviour events (M5-R02)', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    resetMockVideoPlayers();
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

  it('legacy pet / older server: no "Pelji ven", no panel', async () => {
    await renderHud(makeLiveChildState({ pet: { profile: makeLegacyPetProfile() }, behaviour: null }));
    expect(screen.queryByTestId('action-take-out')).toBeNull();
    expect(screen.queryByTestId('hud-behaviour')).toBeNull();
    expect(screen.getByTestId('action-clean')).toBeTruthy();
  });

  it('puppy: "Pelji ven" with a calm countdown that ticks down (fake timers)', async () => {
    jest.useFakeTimers({ doNotFake: ['nextTick', 'setImmediate'] });
    // Device = server clock at 11:40 Ljubljana; accident due 13:00 → 1 h 20 min.
    jest.setSystemTime(new Date('2026-10-04T11:40:00+02:00'));
    getChildPet.mockResolvedValue(puppy({ server_time: '2026-10-04T11:40:00+02:00' }));
    renderWithQuery(<ChildHudScreen />);
    await act(async () => {
      await jest.advanceTimersByTimeAsync(0);
    });
    await act(async () => {
      await jest.advanceTimersByTimeAsync(0);
    });

    expect(screen.getByTestId('action-take-out')).toBeTruthy();
    expect(isDisabled('action-take-out')).toBe(false);
    expect(screen.getByText('Kuža bo moral ven čez ~1 h 20 min')).toBeTruthy();
    expect(screen.getByText('~1 h 20 min')).toBeTruthy();

    // 25 minutes later (ticks every 15 s): ~55 min.
    await act(async () => {
      await jest.advanceTimersByTimeAsync(25 * 60_000);
    });
    expect(screen.getByText('Kuža bo moral ven čez ~55 min')).toBeTruthy();

    // Past the due time: calm "wants to go out", hint "zdaj".
    getChildPet.mockResolvedValue(puppy({ server_time: '2026-10-04T13:00:30+02:00' }));
    await act(async () => {
      await jest.advanceTimersByTimeAsync(56 * 60_000);
    });
    expect(screen.getByText(BEHAVIOUR_STRINGS.takeOutNow)).toBeTruthy();
    expect(screen.getByText(BEHAVIOUR_STRINGS.hintNow)).toBeTruthy();
  });

  it('take-out success and double tap → friendly toasts', async () => {
    await renderHud(puppy());
    takeOutPet.mockResolvedValueOnce({ status: 'accepted', state: puppy() });
    fireEvent.press(screen.getByTestId('action-take-out'));
    expect(await screen.findByText(BEHAVIOUR_STRINGS.success.take_out)).toBeTruthy();

    takeOutPet.mockResolvedValueOnce({ status: 'unchanged', state: puppy() });
    fireEvent.press(screen.getByTestId('action-take-out'));
    expect(await screen.findByText(BEHAVIOUR_STRINGS.unchanged.take_out)).toBeTruthy();
  });

  it('take-out 422 take_out_not_needed: toast, button disappears with the server state', async () => {
    await renderHud(puppy());
    takeOutPet.mockRejectedValueOnce(
      new ApiError('x', 422, { reason: 'take_out_not_needed', state: makeLiveChildState({ behaviour: { take_out: null } }) }),
    );
    fireEvent.press(screen.getByTestId('action-take-out'));
    expect(await screen.findByText(BEHAVIOUR_STRINGS.takeOutNotNeeded)).toBeTruthy();
    await waitFor(() => expect(screen.queryByTestId('action-take-out')).toBeNull());
  });

  it('disabled when the server says no (can_take_out false)', async () => {
    await renderHud(makeLiveChildState({ behaviour: { take_out: makeTakeOut(), can_take_out: false } }));
    expect(isDisabled('action-take-out')).toBe(true);
  });

  it('accident: the cleaning game with puddles, which calls clean', async () => {
    const accident = makeLiveChildState({
      pet: { hygiene_level: 0, needs_cleaning: true, pet_state: 'sick' },
      behaviour: { take_out: makeTakeOut(), can_take_out: true, active_events: [makeBehaviourEvent('accident')], scene: 'accident' },
    });
    await renderHud(accident);
    expect(screen.getByTestId('cleaning-overlay')).toBeTruthy();
    expect(screen.getByText(CLEANING_STRINGS.accident.title)).toBeTruthy();
    // Free tier: the puddle graphic is drawn in-app near the dog.
    expect(screen.getByTestId('scene-graphic-accident')).toBeTruthy();
    expect(screen.queryByTestId('action-resolve-chewing')).toBeNull();

    cleanPet.mockResolvedValueOnce({ status: 'accepted', state: puppy() });
    for (let i = 0; i < 5; i++) fireEvent.press(screen.getByTestId(`dirt-spot-${i}`));
    await waitFor(() => expect(cleanPet).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(screen.queryByTestId('cleaning-overlay')).toBeNull());
  });

  it('chewed slipper: no scrub overlay, its own button resolves it', async () => {
    await renderHud(chewingOnly());
    expect(screen.queryByTestId('cleaning-overlay')).toBeNull();
    expect(screen.getByTestId('scene-graphic-chewing')).toBeTruthy();
    expect(screen.getByText(BEHAVIOUR_STRINGS.scene.chewing)).toBeTruthy();
    // "Očisti" can't scrub a slipper.
    expect(isDisabled('action-clean')).toBe(true);
    expect(screen.getByText(BEHAVIOUR_STRINGS.cleanChewing)).toBeTruthy();

    resolveChewing.mockResolvedValueOnce({ status: 'accepted', state: makeLiveChildState({ pet: { hygiene_level: 100 } }) });
    fireEvent.press(screen.getByTestId('action-resolve-chewing'));
    expect(await screen.findByText(BEHAVIOUR_STRINGS.success.resolve_chewing)).toBeTruthy();
    await waitFor(() => expect(screen.queryByTestId('hud-scene-chewing')).toBeNull());
    expect(resolveChewing).toHaveBeenCalledTimes(1);
  });

  it('premium: the scene video plays and replaces the graphic', async () => {
    const url = 'https://api.petprep.si/api/media/9?expires=1&v=chew&signature=s';
    await renderHud(
      chewingOnly(
        makeMedia({
          status: 'ready',
          videos: { idle: 'https://api.petprep.si/api/media/1?expires=1&v=idle&signature=s', chewing: url },
          states: ['idle', 'sleeping', 'chewing'],
        }),
      ),
    );
    await waitFor(() => expect(playerUris()).toContain(url));
    await waitFor(() => expect(useAppStore.getState().hudVideoState).toBe('chewing'));
    expect(screen.queryByTestId('scene-graphic-chewing')).toBeNull();
    // The button stays — the video only shows the scene.
    expect(screen.getByTestId('action-resolve-chewing')).toBeTruthy();
  });

  it('realtime: a chewing event arriving by PetUpdated shows the button', async () => {
    await renderHud(puppy());
    expect(screen.queryByTestId('action-resolve-chewing')).toBeNull();
    getChildPet.mockResolvedValue(chewingOnly());
    act(() => {
      mockSocket.handler?.(
        makeBroadcast({
          hygiene_level: 0,
          pet_state: 'sick',
          emitted_at: '2026-10-04T10:00:05.250Z',
          behaviour: { take_out: null, active_events: [makeBehaviourEvent('chewing')], scene: 'chewing' },
        }),
      );
    });
    expect(await screen.findByTestId('action-resolve-chewing')).toBeTruthy();
    expect(screen.queryByTestId('cleaning-overlay')).toBeNull();
  });

  it('labels the new dock button', async () => {
    await renderHud(puppy());
    expect(screen.getByText(HUD_STRINGS.takeOut)).toBeTruthy();
  });
});
