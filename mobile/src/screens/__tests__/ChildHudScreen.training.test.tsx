/**
 * M5-R03 in the child HUD: the "Šola" chip only for a pet with training (badge while
 * today's session is open), the command list with progress / "naučeno", a full 50 s game
 * under fake timers (cue, dog reaction from the schedule, "Pohvali", immediate verdict,
 * automatic finish with the right offsets, server result), blocked start, locks, and
 * nothing new for legacy pets / older servers.
 */
import { act, fireEvent, screen, within } from '@testing-library/react-native';

import { ApiError, api } from '@/api/client';
import ChildHudScreen from '@/screens/ChildHudScreen';
import { TRAINING_STRINGS } from '@/modules/training/training';
import { useAppStore } from '@/store/appStore';
import {
  makeBroadcast,
  makeEnabledTraining,
  makeLiveChildState,
  makeMedia,
  makePet,
  makeTrainingResult,
  makeTrainingSessionPayload,
} from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';
import type { PetUpdatedBroadcast } from '@/types';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: {
      ...actual.api,
      getChildPet: jest.fn(),
      startTraining: jest.fn(),
      finishTraining: jest.fn(),
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
const startTraining = api.startTraining as jest.Mock;
const finishTraining = api.finishTraining as jest.Mock;

const S = TRAINING_STRINGS;
const withTraining = (training = makeEnabledTraining()) => makeLiveChildState({ training });

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
  expect(screen.getByTestId('action-feed')).toBeTruthy();
  return utils;
}

const isDisabled = (testID: string) => screen.getByTestId(testID).props.accessibilityState?.disabled === true;

describe('ChildHudScreen — training (M5-R03)', () => {
  beforeEach(() => {
    jest.clearAllMocks();
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

  it('legacy pet (training off) and older server (no key): no "Šola", no panel', async () => {
    const { unmount } = await renderHud(makeLiveChildState());
    expect(screen.queryByTestId('hud-training-open')).toBeNull();
    expect(screen.queryByTestId('hud-behaviour')).toBeNull();
    unmount();
    await renderHud(makeLiveChildState({ training: null }));
    expect(screen.queryByTestId('hud-training-open')).toBeNull();
  });

  it('chip with a badge while today is open; ✓ once done', async () => {
    const { unmount } = await renderHud(withTraining());
    expect(screen.getByTestId('hud-training-open')).toBeTruthy();
    expect(screen.getByTestId('hud-training-badge')).toBeTruthy();
    expect(screen.getByLabelText(S.entryA11y(false))).toBeTruthy();
    unmount();
    await renderHud(withTraining(makeEnabledTraining({ today_done: true })));
    expect(screen.queryByTestId('hud-training-badge')).toBeNull();
    expect(screen.getByTestId('hud-training-done')).toBeTruthy();
  });

  it('opens the command list: progress, "naučeno", today, sessions left; closes', async () => {
    await renderHud(withTraining());
    fireEvent.press(screen.getByTestId('hud-training-open'));
    expect(screen.getByTestId('training-overlay')).toBeTruthy();
    expect(screen.getByTestId('training-command-sit')).toBeTruthy();
    expect(screen.getByText('40 %')).toBeTruthy();
    expect(screen.getByTestId('training-learned-come')).toBeTruthy();
    expect(screen.getByTestId('training-today').props.children).toBe(S.todayOpen);
    expect(screen.getByTestId('training-left').props.children.join('')).toBe('Danes še 6 vaj. Vaja traja 50 s.');
    expect(isDisabled('training-start-sit')).toBe(false);
    fireEvent.press(screen.getByTestId('training-close'));
    expect(screen.queryByTestId('training-overlay')).toBeNull();
  });

  it('a sibling is training: start disabled with the reason', async () => {
    const session = { id: 's1', command: 'come' as const, started_at: '2026-10-04T11:59:40+02:00', ends_at: '2026-10-04T12:00:30+02:00', expires_at: '2026-10-04T12:01:30+02:00', mine: false };
    await renderHud(withTraining(makeEnabledTraining({ can_start: false, session })));
    fireEvent.press(screen.getByTestId('hud-training-open'));
    expect(isDisabled('training-start-sit')).toBe(true);
    expect(screen.getByTestId('training-blocked').props.children).toBe(S.blocked.sibling_training);
  });

  it('plays a full session: cue, dog obeys, in-time praise, auto finish with offsets, result', async () => {
    startTraining.mockResolvedValueOnce({
      status: 'accepted',
      session: makeTrainingSessionPayload(),
      state: withTraining(makeEnabledTraining({ can_start: false })),
    });
    finishTraining.mockResolvedValueOnce({
      status: 'accepted',
      result: makeTrainingResult(),
      state: withTraining(makeEnabledTraining({ today_done: true, daily_budget_left_seconds: 250 })),
    });
    await renderHud(withTraining());
    fireEvent.press(screen.getByTestId('hud-training-open'));
    fireEvent.press(screen.getByTestId('training-start-sit'));
    expect(screen.getByTestId('training-starting')).toBeTruthy();
    await flush();
    await flush();
    const startedAt = Date.now();
    expect(startTraining).toHaveBeenCalledWith('sit');
    expect(screen.getByTestId('training-running')).toBeTruthy();
    // No closing mid-session.
    expect(screen.queryByTestId('training-close')).toBeNull();
    expect(screen.getByTestId('training-dog-text').props.children).toBe(S.getReady);

    await flush(2_100);
    expect(within(screen.getByTestId('training-cue')).getByText('Sedi!')).toBeTruthy();
    expect(screen.getByTestId('training-dog-text').props.children).toBe(S.listening);

    await flush(startedAt + 3_200 - Date.now());
    expect(screen.getByTestId('training-dog-text').props.children).toBe(S.commands.sit.obeys);
    fireEvent(screen.getByTestId('training-praise'), 'pressIn');
    expect(screen.getByTestId('training-feedback').props.children).toBe(S.feedback.in_time);

    // Cue 1: the dog doesn't obey — praising anyway gets a kind hint.
    await flush(startedAt + 9_100 - Date.now());
    expect(screen.getByTestId('training-dog-text').props.children).toBe(S.commands.sit.ignores);
    fireEvent(screen.getByTestId('training-praise'), 'pressIn');
    expect(screen.getByTestId('training-feedback').props.children).toBe(S.feedback.praised_without_obeying);

    await flush(startedAt + 50_200 - Date.now());
    await flush();
    expect(finishTraining).toHaveBeenCalledTimes(1);
    expect(finishTraining).toHaveBeenCalledWith('7b0d7a8e-3c1f-4f7e-9d65-0a6a9c0b1e11', [3_200, 9_100]);
    expect(screen.getByTestId('training-result')).toBeTruthy();
    expect(screen.getByTestId('training-result-gain').props.children).toBe('Sedi: 40 % → 43 %');
    expect(screen.getByTestId('training-result-successes').props.children).toBe('Pravočasne pohvale: 3 od 5.');
    expect(screen.getByText(S.result.titleGood)).toBeTruthy();
    // The HUD chip (hidden from a11y under the modal overlay) now shows today as done.
    expect(screen.getByTestId('hud-training-done', { includeHiddenElements: true })).toBeTruthy();
    fireEvent.press(screen.getByTestId('training-done'));
    expect(screen.queryByTestId('training-overlay')).toBeNull();
  });

  it('screen reader (press only) praises; a touch release never counts as a second tap', async () => {
    startTraining.mockResolvedValueOnce({ status: 'accepted', session: makeTrainingSessionPayload(), state: withTraining() });
    finishTraining.mockResolvedValueOnce({ status: 'accepted', result: makeTrainingResult(), state: withTraining() });
    await renderHud(withTraining());
    fireEvent.press(screen.getByTestId('hud-training-open'));
    fireEvent.press(screen.getByTestId('training-start-sit'));
    await flush();
    const startedAt = Date.now();
    await flush(3_300);
    fireEvent.press(screen.getByTestId('training-praise'));
    expect(screen.getByTestId('training-feedback').props.children).toBe(S.feedback.in_time);
    // Touch held across the next cue: down at 7.9 s (cue 0), released at 8.2 s (cue 1).
    await flush(startedAt + 7_900 - Date.now());
    fireEvent(screen.getByTestId('training-praise'), 'pressIn');
    await flush(300);
    fireEvent.press(screen.getByTestId('training-praise'));
    await flush(startedAt + 50_200 - Date.now());
    await flush();
    expect(finishTraining).toHaveBeenCalledWith('7b0d7a8e-3c1f-4f7e-9d65-0a6a9c0b1e11', [3_300]);
  });

  it('premium: the pet\'s own video plays in the game (no new media); free: the illustration', async () => {
    const premium = makeLiveChildState({
      training: makeEnabledTraining(),
      pet: { media: makeMedia({ status: 'ready', videos: { idle: 'https://media.test/idle.mp4', playing: 'https://media.test/playing.mp4' } }) },
    });
    startTraining.mockResolvedValueOnce({ status: 'accepted', session: makeTrainingSessionPayload(), state: premium });
    const { unmount } = await renderHud(premium);
    fireEvent.press(screen.getByTestId('hud-training-open'));
    fireEvent.press(screen.getByTestId('training-start-sit'));
    await flush();
    expect(screen.getByTestId('training-dog-video')).toBeTruthy();
    expect(screen.queryByTestId('training-dog-illustration')).toBeNull();
    unmount();
    useAppStore.getState().setTrainingVisible(false);

    startTraining.mockResolvedValueOnce({ status: 'accepted', session: makeTrainingSessionPayload({ id: 'f1e2d3c4-0000-4000-8000-000000000001' }), state: withTraining() });
    await renderHud(withTraining());
    fireEvent.press(screen.getByTestId('hud-training-open'));
    fireEvent.press(screen.getByTestId('training-start-sit'));
    await flush();
    expect(screen.getByTestId('training-dog-illustration')).toBeTruthy();
  });

  it('no progress: encouraging, never shaming; learned command celebrated', async () => {
    startTraining.mockResolvedValue({ status: 'accepted', session: makeTrainingSessionPayload(), state: withTraining() });
    finishTraining.mockResolvedValueOnce({
      status: 'accepted',
      result: makeTrainingResult({ successes: 0, progress_before: 40, progress_after: 40, progress_gain: 0 }),
      state: withTraining(makeEnabledTraining({ today_done: true })),
    });
    await renderHud(withTraining());
    fireEvent.press(screen.getByTestId('hud-training-open'));
    fireEvent.press(screen.getByTestId('training-start-sit'));
    await flush();
    await flush(50_200);
    await flush();
    expect(screen.getByText(S.result.titleLearning)).toBeTruthy();
    expect(screen.getByTestId('training-result-gain').props.children).toBe(S.result.noGain);
    expect(screen.queryByTestId('training-result-learned')).toBeNull();

    // Again → back to the list → a session that teaches the command fully.
    fireEvent.press(screen.getByTestId('training-again'));
    startTraining.mockResolvedValueOnce({
      status: 'accepted',
      session: makeTrainingSessionPayload({ id: '0c9a7c55-4a8e-4b1e-8f0e-5d2c1b0a9f22' }),
      state: withTraining(),
    });
    finishTraining.mockResolvedValueOnce({
      status: 'accepted',
      result: makeTrainingResult({ successes: 5, progress_before: 98, progress_after: 100, progress_gain: 2 }),
      state: withTraining(makeEnabledTraining({ today_done: true })),
    });
    fireEvent.press(screen.getByTestId('training-start-sit'));
    await flush();
    await flush(50_200);
    await flush();
    expect(screen.getByTestId('training-result-learned').props.children).toBe('Kuža zna ukaz »Sedi«!');
  });

  it('start refused (budget used): message in the overlay, back to the list', async () => {
    startTraining.mockRejectedValueOnce(
      new ApiError('x', 422, { reason: 'training_daily_budget_used', state: withTraining(makeEnabledTraining({ can_start: false, daily_budget_left_seconds: 0 })) }),
    );
    await renderHud(withTraining());
    fireEvent.press(screen.getByTestId('hud-training-open'));
    fireEvent.press(screen.getByTestId('training-start-place'));
    await flush();
    await flush();
    expect(screen.getByText(S.errors.training_daily_budget_used)).toBeTruthy();
    fireEvent.press(screen.getByTestId('training-back'));
    expect(screen.getByTestId('training-blocked').props.children).toBe(S.blocked.budget_used);
  });

  it('a lock (hard stop broadcast) closes the overlay and hides the chip', async () => {
    await renderHud(withTraining());
    fireEvent.press(screen.getByTestId('hud-training-open'));
    expect(screen.getByTestId('training-overlay')).toBeTruthy();
    getChildPet.mockResolvedValue(makeLiveChildState({ training: makeEnabledTraining({ can_start: false }), lock: { is_locked: true, reason: 'hard_stopped' }, pet: { is_hard_stopped: true } }));
    await act(async () => {
      mockSocket.handler?.(makeBroadcast({ pet_id: 7, is_hard_stopped: true, event_type: 'hard_stop_changed', emitted_at: '2026-10-04T10:00:05.000Z' }));
      await jest.advanceTimersByTimeAsync(0);
    });
    await flush();
    expect(screen.queryByTestId('training-overlay')).toBeNull();
    expect(useAppStore.getState().isTrainingVisible).toBe(false);
  });

  it('broadcast training summary updates the chip (today done) without a crash', async () => {
    await renderHud(withTraining());
    getChildPet.mockResolvedValue(withTraining(makeEnabledTraining({ today_done: true })));
    await act(async () => {
      mockSocket.handler?.(
        makeBroadcast({
          pet_id: 7,
          event_type: 'trained_pet',
          emitted_at: '2026-10-04T10:00:05.000Z',
          training: { enabled: true, commands: makeEnabledTraining().commands, today_done: true, session_active: false },
        }),
      );
      await jest.advanceTimersByTimeAsync(0);
    });
    expect(screen.getByTestId('hud-training-done')).toBeTruthy();
  });
});
