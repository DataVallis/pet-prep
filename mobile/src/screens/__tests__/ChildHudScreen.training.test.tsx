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
  makeRunningSession,
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
  // A resumed session opens the (modal) overlay at once — the dock is then hidden from a11y.
  expect(screen.getByTestId('action-feed', { includeHiddenElements: true })).toBeTruthy();
  return utils;
}

const isDisabled = (testID: string) => screen.getByTestId(testID).props.accessibilityState?.disabled === true;

describe('ChildHudScreen — training (M5-R03)', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    // clearAllMocks keeps queued *Once values — a failed test must not leak them.
    startTraining.mockReset();
    finishTraining.mockReset();
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
    const session = makeRunningSession();
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

  it('app restarted mid-session: "Šola" opens by itself and the game resumes', async () => {
    finishTraining.mockResolvedValueOnce({ status: 'accepted', result: makeTrainingResult(), state: withTraining(makeEnabledTraining({ today_done: true })) });
    // Own session started 12:00:00 − 20 s by the server clock; device = server time 12:00:00.
    const own = makeRunningSession({
      mine: true,
      id: 'own-1',
      started_at: '2026-10-04T11:59:40+02:00',
      ends_at: '2026-10-04T12:00:30+02:00',
      expires_at: '2026-10-04T12:01:30+02:00',
    });
    await renderHud(withTraining(makeEnabledTraining({ can_start: false, session: own })));
    await flush();
    expect(screen.getByTestId('training-running')).toBeTruthy();
    expect(screen.getByTestId('training-resumed')).toBeTruthy();
    expect(screen.queryByTestId('training-close')).toBeNull();
    await flush(30_200);
    await flush();
    expect(finishTraining).toHaveBeenCalledWith('own-1', []);
    expect(screen.getByTestId('training-result')).toBeTruthy();
  });

  it('own session over but within the TTL: saved at once; closing never reopens it', async () => {
    getChildPet.mockResolvedValue(withTraining());
    finishTraining.mockResolvedValueOnce({ status: 'accepted', result: makeTrainingResult(), state: withTraining(makeEnabledTraining({ today_done: true })) });
    const own = makeRunningSession({ mine: true, id: 'own-2', started_at: '2026-10-04T11:58:50+02:00', ends_at: '2026-10-04T11:59:40+02:00', expires_at: '2026-10-04T12:00:40+02:00' });
    await renderHud(withTraining(makeEnabledTraining({ can_start: false, session: own })));
    await flush();
    expect(finishTraining).toHaveBeenCalledWith('own-2', []);
    fireEvent.press(screen.getByTestId('training-done'));
    expect(screen.queryByTestId('training-overlay')).toBeNull();
  });

  it('a repeated finish ("unchanged") shows the stored result neutrally', async () => {
    startTraining.mockResolvedValueOnce({ status: 'accepted', session: makeTrainingSessionPayload({ id: 'abcd-unchanged' }), state: withTraining() });
    finishTraining.mockResolvedValueOnce({ status: 'unchanged', result: makeTrainingResult(), state: withTraining(makeEnabledTraining({ today_done: true })) });
    await renderHud(withTraining());
    fireEvent.press(screen.getByTestId('hud-training-open'));
    fireEvent.press(screen.getByTestId('training-start-sit'));
    await flush();
    await flush(50_200);
    await flush();
    expect(screen.getByTestId('training-result-title').props.children).toBe(S.result.titleStored);
    expect(screen.getByText(S.result.stored)).toBeTruthy();
    expect(screen.queryByTestId('training-result-routine')).toBeNull();
  });

  it('day ending (23:59, can_start false): the start is off with a calm reason', async () => {
    jest.setSystemTime(new Date('2026-10-04T23:59:00+02:00'));
    await renderHud(makeLiveChildState({ server_time: '2026-10-04T23:59:00+02:00', training: makeEnabledTraining({ can_start: false }) }));
    fireEvent.press(screen.getByTestId('hud-training-open'));
    expect(isDisabled('training-start-sit')).toBe(true);
    expect(screen.getByTestId('training-blocked').props.children).toBe(S.blocked.day_ending);
  });

  describe('QA PR review', () => {
    it('the HUD under the game is hidden from TalkBack; the game itself is not', async () => {
      await renderHud(withTraining());
      expect(screen.getByTestId('hud-content').props.importantForAccessibility).toBe('auto');
      fireEvent.press(screen.getByTestId('hud-training-open'));
      const content = screen.getByTestId('hud-content', { includeHiddenElements: true });
      expect(content.props.importantForAccessibility).toBe('no-hide-descendants');
      expect(content.props.accessibilityElementsHidden).toBe(true);
      // The overlay is outside hud-content → still reachable.
      expect(screen.getByTestId('training-start-sit')).toBeTruthy();
    });

    it('while the session can still be saved only "Poskusi znova" is offered', async () => {
      startTraining.mockResolvedValueOnce({ status: 'accepted', session: makeTrainingSessionPayload({ id: 'retry-1' }), state: withTraining() });
      finishTraining.mockRejectedValueOnce(new TypeError('Network request failed'));
      await renderHud(withTraining());
      fireEvent.press(screen.getByTestId('hud-training-open'));
      fireEvent.press(screen.getByTestId('training-start-sit'));
      await flush();
      await flush(50_200);
      await flush();
      expect(screen.getByTestId('training-retry')).toBeTruthy();
      expect(screen.queryByTestId('training-back')).toBeNull();
    });

    it('a final failure offers "Nazaj v šolo"', async () => {
      startTraining.mockResolvedValueOnce({ status: 'accepted', session: makeTrainingSessionPayload({ id: 'final-1' }), state: withTraining() });
      finishTraining.mockRejectedValueOnce(new ApiError('x', 422, { reason: 'training_invalid_taps', state: withTraining() }));
      await renderHud(withTraining());
      fireEvent.press(screen.getByTestId('hud-training-open'));
      fireEvent.press(screen.getByTestId('training-start-sit'));
      await flush();
      await flush(50_200);
      await flush();
      expect(screen.queryByTestId('training-retry')).toBeNull();
      expect(screen.getByTestId('training-back')).toBeTruthy();
    });

    it('resume: cues that passed while the app was closed are neutral dots', async () => {
      // Resumed 21 s into the session: cues 0–2 are over (neutral), 3+ still to play.
      const own = makeRunningSession({ mine: true, id: 'own-away', started_at: '2026-10-04T11:59:39+02:00', ends_at: '2026-10-04T12:00:29+02:00', expires_at: '2026-10-04T12:01:29+02:00' });
      await renderHud(withTraining(makeEnabledTraining({ can_start: false, session: own })));
      await flush();
      expect(screen.getByTestId('training-dot-away-0')).toBeTruthy();
      expect(screen.getByTestId('training-dot-away-1')).toBeTruthy();
      expect(screen.getByTestId('training-dot-away-2')).toBeTruthy();
      expect(screen.getByTestId('training-dot-3')).toBeTruthy();
      expect(screen.queryByTestId('training-feedback')).toBeNull();
    });

    it('a long hold is one tap: its release never counts, whatever the duration', async () => {
      startTraining.mockResolvedValueOnce({ status: 'accepted', session: makeTrainingSessionPayload({ id: 'hold-1' }), state: withTraining() });
      finishTraining.mockResolvedValueOnce({ status: 'accepted', result: makeTrainingResult(), state: withTraining() });
      await renderHud(withTraining());
      fireEvent.press(screen.getByTestId('hud-training-open'));
      fireEvent.press(screen.getByTestId('training-start-sit'));
      await flush();
      const startedAt = Date.now();
      await flush(3_300);
      fireEvent(screen.getByTestId('training-praise'), 'pressIn', { nativeEvent: {} });
      // Held for 5 s, released in cue 1's slot.
      await flush(startedAt + 8_300 - Date.now());
      fireEvent.press(screen.getByTestId('training-praise'));
      await flush(startedAt + 50_200 - Date.now());
      await flush();
      expect(finishTraining).toHaveBeenCalledWith('hold-1', [3_300]);
    });
  });
});


describe('ChildHudScreen — training fair share & starting progress (M5-R03b)', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    startTraining.mockReset();
    finishTraining.mockReset();
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

  const shared = (overrides: Parameters<typeof makeEnabledTraining>[0] = {}) =>
    makeEnabledTraining({ children_sharing: 2, my_share_seconds: 150, my_seconds_left: 150, ...overrides });

  it('two children: 3 sessions of my share and the "deliš" hint', async () => {
    await renderHud(withTraining(shared()));
    fireEvent.press(screen.getByTestId('hud-training-open'));
    expect(screen.getByTestId('training-left').props.children.join('')).toBe('Danes še 3 vaje. Vaja traja 50 s.');
    expect(screen.getByTestId('training-shared').props.children).toBe('Čas za šolo si deliš z bratom ali sestro.');
  });

  it('one child: no "deliš" hint', async () => {
    await renderHud(withTraining());
    fireEvent.press(screen.getByTestId('hud-training-open'));
    expect(screen.queryByTestId('training-shared')).toBeNull();
  });

  it('my share used (sibling still has time): no dot, start off with a kind reason', async () => {
    await renderHud(withTraining(shared({ my_seconds_left: 0, daily_budget_left_seconds: 150, can_start: false })));
    expect(screen.getByTestId('hud-training-open')).toBeTruthy();
    expect(screen.queryByTestId('hud-training-badge')).toBeNull();
    expect(screen.queryByTestId('hud-training-done')).toBeNull();
    fireEvent.press(screen.getByTestId('hud-training-open'));
    expect(screen.getByTestId('training-left').props.children.join('')).toBe('Danes še 0 vaj. Vaja traja 50 s.');
    expect(screen.getByTestId('training-blocked').props.children).toBe(S.blocked.share_used);
    expect(isDisabled('training-start-sit')).toBe(true);
  });

  it('start refused (training_child_share_used): kind message, then the list explains it', async () => {
    startTraining.mockRejectedValueOnce(
      new ApiError('x', 422, {
        reason: 'training_child_share_used',
        next_allowed_at: '2026-10-05T00:00:00+02:00',
        state: withTraining(shared({ my_seconds_left: 0, daily_budget_left_seconds: 150, can_start: false })),
      }),
    );
    await renderHud(withTraining(shared()));
    fireEvent.press(screen.getByTestId('hud-training-open'));
    fireEvent.press(screen.getByTestId('training-start-sit'));
    await flush();
    await flush();
    expect(screen.getByText(S.errors.training_child_share_used)).toBeTruthy();
    fireEvent.press(screen.getByTestId('training-back'));
    expect(screen.getByTestId('training-blocked').props.children).toBe(S.blocked.share_used);
  });

  it('a dog that arrived older shows its starting progress (sit 50 %, potty 70 %), nothing learned', async () => {
    const commands = [
      { command: 'sit' as const, progress: 50, learned: false, last_practised_at: null },
      { command: 'come' as const, progress: 30, learned: false, last_practised_at: null },
      { command: 'place' as const, progress: 0, learned: false, last_practised_at: null },
      { command: 'potty' as const, progress: 70, learned: false, last_practised_at: null },
    ];
    await renderHud(withTraining(makeEnabledTraining({ commands })));
    expect(screen.getByTestId('hud-training-badge')).toBeTruthy();
    fireEvent.press(screen.getByTestId('hud-training-open'));
    expect(within(screen.getByTestId('training-command-sit')).getByText('50 %')).toBeTruthy();
    expect(within(screen.getByTestId('training-command-potty')).getByText('70 %')).toBeTruthy();
    expect(screen.getByTestId('training-progress-potty').props.accessibilityValue).toEqual({ min: 0, max: 100, now: 70 });
    expect(screen.queryByTestId('training-learned-sit')).toBeNull();
    expect(screen.queryByTestId('training-learned-potty')).toBeNull();
    expect(isDisabled('training-start-place')).toBe(false);
  });
});
