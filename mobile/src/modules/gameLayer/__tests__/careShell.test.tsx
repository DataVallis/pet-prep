/**
 * GAMES_SPEC §5.5 / §7.7 — the care games on the game layer: same strokes / praise / verdict as
 * the card screens (no rule change), now full screen with the step on the top line, a haptic per
 * counted stroke and a ✓ at the end of each step; an older binary keeps the card screens.
 */
import { QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, screen } from '@testing-library/react-native';
import * as Haptics from 'expo-haptics';

import { api, type ChildPetState } from '@/api/client';
import { i18n } from '@/i18n';
import CareTaskOverlay from '@/modules/careTasks/CareTaskOverlay';
import ChoreOverlay from '@/modules/catCare/ChoreOverlay';
import ScratchingOverlay from '@/modules/catCare/ScratchingOverlay';
import { normalizeChildState, type ChildPetView } from '@/modules/childPet/childPetView';
import { careCues, careFrame } from '@/modules/gameLayer/careFrame';
import { setGameLayerAvailableForTests } from '@/modules/gameLayer/capability';
import { GAME_BACK_HINT_MS, GAME_ESCAPE_MS } from '@/modules/gameLayer/gameExit';
import { GAME_LINE_MAX_CHARS } from '@/modules/gameLayer/gameLine';
import { resetCareShellForTests } from '@/modules/gameLayer/loadCareShell';
import { useAppStore } from '@/store/appStore';
import {
  makeCareTask,
  makeCareTaskSession,
  makeChoreSession,
  makeGroomingState,
  makeLiveChildState,
  makeLitterState,
  makeMedia,
  makePet,
  makeScratchingSession,
  makeScratchingState,
  makeWandState,
  withEasyGestures,
} from '@/test-utils/fixtures';
import { captureAndroidBack, expectHudAboveStage } from '@/test-utils/gameLayer';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, startCareChore: jest.fn(), finishCareChore: jest.fn(), startScratching: jest.fn(), finishScratching: jest.fn(), startCareTask: jest.fn(), finishCareTask: jest.fn(), getChildPet: jest.fn() } };
});

const mocked = api as unknown as Record<string, jest.Mock>;
const T0 = Date.parse('2026-10-04T12:00:00+02:00');
const clock = { mono: () => Date.now(), wall: () => Date.now() };

function catState(): ChildPetState {
  return makeLiveChildState({
    pet: { breed_type: 'maine_coon', species: 'cat' } as Partial<ChildPetState['pet']>,
    wand: makeWandState(),
    litter: makeLitterState(),
    grooming: makeGroomingState(),
    scratching: makeScratchingState(),
  });
}

function viewOf(state: ChildPetState): ChildPetView {
  return withEasyGestures(normalizeChildState(state, 0, T0), true);
}

async function advance(ms: number) {
  await act(async () => {
    await jest.advanceTimersByTimeAsync(ms);
  });
}

describe('careFrame (pure)', () => {
  const rules = { duration_ms: 30_000, segments: 3, min_strokes: 4 };

  it('a step is ✓ at its end when it has a counted stroke; the last one early once complete', () => {
    expect(careFrame(rules, 1, new Set([0]), 9_999).stepsDone).toEqual([false, false, false]);
    expect(careFrame(rules, 1, new Set([0]), 10_000).stepsDone).toEqual([true, false, false]);
    expect(careFrame(rules, 1, new Set([1]), 20_000).stepsDone).toEqual([false, true, false]);
    expect(careFrame(rules, 4, new Set([0, 1, 2]), 21_000).stepsDone).toEqual([true, true, true]);
    expect(careFrame(rules, 3, new Set([0, 1, 2]), 21_000).stepsDone[2]).toBe(false);
  });

  it('cues one haptic per counted stroke and one ✓ per newly finished step — nothing for a miss', () => {
    const a = careFrame(rules, 1, new Set([0]), 5_000);
    const b = careFrame(rules, 2, new Set([0]), 10_000);
    expect(careCues(a, b)).toEqual([{ kind: 'stroke' }, { kind: 'step', step: 0 }]);
    expect(careCues(b, careFrame(rules, 2, new Set([0]), 20_000))).toEqual([]);
    expect(careCues(null, a)).toEqual([]);
  });
});

describe('care games on the game layer', () => {
  beforeEach(async () => {
    jest.clearAllMocks();
    jest.useFakeTimers({ doNotFake: ['nextTick', 'setImmediate'] });
    jest.setSystemTime(T0);
    await i18n.changeLanguage('sl');
    setGameLayerAvailableForTests(true);
    resetCareShellForTests();
    mocked.getChildPet.mockResolvedValue(catState());
    useAppStore.setState(useAppStore.getInitialState(), true);
    useAppStore.getState().signIn({ token: 't', user: { id: 2, name: 'Maja', email: null, role: 'child' }, pet: makePet(), awaitingContract: false });
  });

  afterEach(() => {
    setGameLayerAvailableForTests(false);
    resetCareShellForTests();
    jest.useRealTimers();
  });

  it('grooming: full screen, the step on the top line, a haptic per stroke, ✓ per step, the same strokes sent', async () => {
    const session = makeChoreSession('grooming');
    mocked.startCareChore.mockResolvedValue({ status: 'accepted', session, state: catState() });
    mocked.finishCareChore.mockResolvedValue({
      status: 'rejected',
      result: { session_id: session.id, success: false, reason: 'too_few_strokes', strokes: 3, min_strokes: 10, segments_hit: 3, segments: 3, matted: false },
      state: catState(),
    });
    renderWithQuery(<ChoreOverlay kind="grooming" view={viewOf(catState())} onClose={jest.fn()} reduceMotion clock={clock} />);
    fireEvent.press(screen.getByTestId('cat-grooming-start'));
    await advance(0);
    expect(screen.getByTestId('cat-grooming-layer')).toBeTruthy();
    expect(screen.queryByTestId('cat-grooming-running')).toBeNull();
    expect(screen.getByTestId('cat-grooming-layer-top-line').props.children).toBe('Glava in vrat');
    expect(screen.queryByTestId('cat-grooming-brush-hint')).toBeNull();

    for (const wait of [1_000, 9_500, 10_400]) {
      await advance(wait);
      fireEvent.press(screen.getByLabelText('Enkrat počeši muco'));
    }
    expect(Haptics.impactAsync).toHaveBeenCalledTimes(3);
    // Steps 1 and 2 ended with a stroke each → two ✓ so far.
    expect(Haptics.notificationAsync).toHaveBeenCalledTimes(2);
    await advance(10_000);
    expect(mocked.finishCareChore).toHaveBeenCalledWith('grooming', session.id, [1_000, 10_500, 20_900]);
    await advance(0);
    // The result stays the card screen.
    expect(screen.getByTestId('cat-grooming-result-title').props.children).toBe('To česanje ni štelo');
  });

  it('scratching: praise too early → a soft ✗ (no haptic); the same praise is sent', async () => {
    const session = makeScratchingSession();
    mocked.startScratching.mockResolvedValue({ status: 'accepted', session, state: catState() });
    mocked.finishScratching.mockResolvedValue({
      status: 'rejected',
      result: { session_id: session.id, success: false, reason: 'too_early', praise_ms: 500, land_at_ms: 1_200, delay_ms: -700, praise_window_ms: 3_000 },
      state: catState(),
    });
    renderWithQuery(<ScratchingOverlay view={viewOf(catState())} onClose={jest.fn()} reduceMotion clock={clock} />);
    fireEvent.press(screen.getByTestId('cat-scratching-start'));
    await advance(0);
    expect(screen.getByTestId('cat-scratching-layer')).toBeTruthy();
    expect(screen.getByTestId('cat-scratching-layer-top-line').props.children).toBe('Neseš jo na praskalnik …');
    await advance(500);
    fireEvent.press(screen.getByLabelText('Pohvali muco'));
    await advance(0);
    expect(screen.getByTestId('cat-scratching-layer-feedback-miss', { includeHiddenElements: true })).toBeTruthy();
    expect(Haptics.notificationAsync).not.toHaveBeenCalled();
    await advance(1_000);
    expect(mocked.finishScratching).toHaveBeenCalledWith(session.id, 500);
  });

  it('nail trim (one step): a haptic per claw, ✓ once every claw is trimmed, the same taps sent', async () => {
    const nails = makeCareTaskSession({ task: 'nail_trim', duration_ms: 12000, min_strokes: 8, segments: 1, ends_at: '2026-10-04T12:00:12+02:00', expires_at: '2026-10-04T12:01:12+02:00' });
    const dog = makeLiveChildState({ pet: { breed_type: 'papillon' } as Partial<ChildPetState['pet']>, care_tasks: [makeCareTask({ task: 'nail_trim', times: 1, period_days: 28 })] });
    mocked.startCareTask.mockResolvedValue({ status: 'accepted', session: nails, state: dog });
    mocked.finishCareTask.mockReturnValue(new Promise(() => undefined));
    renderWithQuery(<CareTaskOverlay task="nail_trim" view={normalizeChildState(dog, 0, T0)} onClose={jest.fn()} reduceMotion clock={clock} />);
    fireEvent.press(screen.getByTestId('care-nail_trim-start'));
    await advance(0);
    expect(screen.getByTestId('care-nail_trim-layer')).toBeTruthy();
    expect(screen.getByTestId('care-nail_trim-layer-top-line').props.children).toBe('Tapni vsak kremplj enkrat.');
    for (let i = 0; i < 8; i++) {
      await advance(300);
      fireEvent.press(screen.getByTestId(`care-nail_trim-claw-${i}`));
    }
    await advance(0);
    expect(Haptics.impactAsync).toHaveBeenCalledTimes(8);
    expect(Haptics.notificationAsync).toHaveBeenCalledTimes(1);
  });

  it('an older binary keeps the card screen', async () => {
    setGameLayerAvailableForTests(false);
    resetCareShellForTests();
    mocked.startCareChore.mockResolvedValue({ status: 'accepted', session: makeChoreSession('grooming'), state: catState() });
    renderWithQuery(<ChoreOverlay kind="grooming" view={viewOf(catState())} onClose={jest.fn()} reduceMotion clock={clock} />);
    fireEvent.press(screen.getByTestId('cat-grooming-start'));
    await advance(0);
    expect(screen.getByTestId('cat-grooming-running')).toBeTruthy();
    expect(screen.queryByTestId('cat-grooming-layer')).toBeNull();
  });

  it.each(['sl', 'en'])('the new layer lines fit the game line (%s)', async (lang) => {
    await i18n.changeLanguage(lang);
    for (const key of ['cat:scratching.layer.carrying', 'cat:scratching.layer.landed', 'cat:scratching.layer.early', 'careTasks:common.nails.layerHint', 'careTasks:common.nails.layerDone', 'cat:common.finishing'] as const) {
      expect(Array.from(i18n.t(key)).length).toBeLessThanOrEqual(GAME_LINE_MAX_CHARS);
    }
  });

  // Ball v2 device bug (David 2026-10-31): the HUD above the stage and always a way out.
  it('grooming: the × stops the running chore; the HUD is above the stage', async () => {
    mocked.startCareChore.mockResolvedValue({ status: 'accepted', session: makeChoreSession('grooming'), state: catState() });
    renderWithQuery(<ChoreOverlay kind="grooming" view={viewOf(catState())} onClose={jest.fn()} reduceMotion clock={clock} />);
    fireEvent.press(screen.getByTestId('cat-grooming-start'));
    await advance(0);
    expectHudAboveStage('cat-grooming-layer', ['cat-grooming-layer-top-close', 'cat-grooming-layer-area']);
    fireEvent.press(screen.getByTestId('cat-grooming-layer-top-close'));
    await advance(0);
    expect(screen.queryByTestId('cat-grooming-layer')).toBeNull();
    expect(screen.getByTestId('cat-grooming-start')).toBeTruthy();
  });

  // David 2026-11-15 (production, Android): rubbing across the screen edge is Android's back
  // GESTURE; back used to be the × = »stop«, so the litter change fell back to »Začni menjavo«
  // right after step 1 and the session was thrown away.
  it('litter change: an Android back mid-game (edge swipe) and a refetch between the steps never reset it — a hint, the same session, every stroke sent', async () => {
    const back = captureAndroidBack();
    const session = makeChoreSession('litter_change');
    mocked.startCareChore.mockResolvedValue({ status: 'accepted', session, state: catState() });
    mocked.finishCareChore.mockResolvedValue({
      status: 'accepted',
      result: { session_id: session.id, success: true, reason: null, strokes: 3, min_strokes: 10, segments_hit: 3, segments: 3, matted: false },
      state: catState(),
    });
    const onClose = jest.fn();
    const ui = (view: ChildPetView) => <ChoreOverlay kind="litter_change" view={view} onClose={onClose} reduceMotion clock={clock} />;
    const { rerender, client } = renderWithQuery(ui(viewOf(catState())));
    fireEvent.press(screen.getByTestId('cat-litter_change-start'));
    await advance(0);
    expect(screen.getByTestId('cat-litter_change-layer-top-line').props.children).toBe('Izprazni stranišče');
    await advance(1_000);
    fireEvent.press(screen.getByLabelText('Enkrat podrgni stranišče'));
    // Step 1 ends (✓, »Umij ga«) …
    await advance(9_500);
    expect(screen.getByTestId('cat-litter_change-layer-top-line').props.children).toBe('Umij ga');
    // … a broadcast / refetch lands (a new view object; the child's own session is in it) …
    const running = makeLitterState({}, { session_running: true, can_start: false, blocked_reason: 'litter_change_session_active', session });
    rerender(<QueryClientProvider client={client}>{ui(viewOf({ ...catState(), litter: running } as ChildPetState))}</QueryClientProvider>);
    await advance(1_600);
    // … and the next rub starts at the screen edge: Android sends »back«.
    expect(back.pressBack()).toBe(true);
    await advance(0);
    expect(screen.getByTestId('cat-litter_change-layer')).toBeTruthy();
    expect(screen.queryByTestId('cat-litter_change-start')).toBeNull();
    expect(onClose).not.toHaveBeenCalled();
    // The hint replaces the step line at once (GameScreen's rule for every game).
    expect(screen.getByTestId('cat-litter_change-layer-top-line').props.children).toBe('Za konec igre tapni ×.');
    // The game goes on with the same session: the hint leaves, the strokes before and after count.
    fireEvent.press(screen.getByLabelText('Enkrat podrgni stranišče'));
    await advance(GAME_BACK_HINT_MS);
    expect(screen.getByTestId('cat-litter_change-layer-top-line').props.children).toBe('Umij ga');
    await advance(8_800 - GAME_BACK_HINT_MS);
    fireEvent.press(screen.getByLabelText('Enkrat podrgni stranišče'));
    expect(back.pressBack()).toBe(true);
    await advance(10_000);
    expect(mocked.startCareChore).toHaveBeenCalledTimes(1);
    expect(mocked.finishCareChore).toHaveBeenCalledWith('litter_change', session.id, [1_000, 12_100, 20_900]);
    await advance(0);
    expect(screen.getByTestId('cat-litter_change-result-title').props.children).toBe('Sveže in čisto!');
    jest.restoreAllMocks();
  });

  it('litter change: the × still stops it (nothing sent); back while saving does nothing', async () => {
    const back = captureAndroidBack();
    const session = makeChoreSession('litter_change');
    mocked.startCareChore.mockResolvedValue({ status: 'accepted', session, state: catState() });
    mocked.finishCareChore.mockReturnValue(new Promise(() => undefined));
    const onClose = jest.fn();
    renderWithQuery(<ChoreOverlay kind="litter_change" view={viewOf(catState())} onClose={onClose} reduceMotion clock={clock} />);
    fireEvent.press(screen.getByTestId('cat-litter_change-start'));
    await advance(0);
    fireEvent.press(screen.getByTestId('cat-litter_change-layer-top-close'));
    await advance(0);
    expect(screen.getByTestId('cat-litter_change-start')).toBeTruthy();
    expect(mocked.finishCareChore).not.toHaveBeenCalled();
    // A new game, played to the end: while it is being saved the × is locked and back is swallowed.
    fireEvent.press(screen.getByTestId('cat-litter_change-start'));
    await advance(30_000);
    expect(mocked.finishCareChore).toHaveBeenCalledTimes(1);
    expect(back.pressBack()).toBe(true);
    await advance(0);
    expect(screen.getByTestId('cat-litter_change-layer')).toBeTruthy();
    expect(screen.getByTestId('cat-litter_change-layer-top-line').props.children).not.toBe('Za konec igre tapni ×.');
    expect(onClose).not.toHaveBeenCalled();
    jest.restoreAllMocks();
  });

  // David 2026-11-15 (production): the child rubbed the cat's resting video — no litter box at all.
  it.each([true, false])('litter change: the stage is the litter box drawing per step, never the cat video (game layer %s)', async (layer) => {
    setGameLayerAvailableForTests(layer);
    resetCareShellForTests();
    const state = catState();
    (state.pet as { media: unknown }).media = makeMedia({ status: 'ready', videos: { idle: 'https://x/i.mp4' }, states: ['idle'] });
    mocked.startCareChore.mockImplementation((kind: 'grooming' | 'litter_change') => Promise.resolve({ status: 'accepted', session: makeChoreSession(kind), state }));
    // Swipe mode (David's phone) and hold mode alike.
    for (const view of [normalizeChildState(state, 0, T0), viewOf(state)]) {
      const { unmount } = renderWithQuery(<ChoreOverlay kind="litter_change" view={view} onClose={jest.fn()} reduceMotion clock={clock} />);
      fireEvent.press(screen.getByTestId('cat-litter_change-start'));
      await advance(0);
      expect(screen.queryByTestId('cat-litter_change-stage-video')).toBeNull();
      expect(screen.getByTestId('cat-tray', { includeHiddenElements: true })).toBeTruthy();
      unmount();
    }
    // Grooming is about the cat: it keeps the cat's own video.
    renderWithQuery(<ChoreOverlay kind="grooming" view={viewOf(state)} onClose={jest.fn()} reduceMotion clock={clock} />);
    fireEvent.press(screen.getByTestId('cat-grooming-start'));
    await advance(0);
    expect(screen.getByTestId('cat-grooming-stage-video', { includeHiddenElements: true })).toBeTruthy();
  });

  it.each(['sl', 'en'])('the back hint fits the game line (%s)', async (lang) => {
    await i18n.changeLanguage(lang);
    expect(Array.from(i18n.t('common:game.backHint')).length).toBeLessThanOrEqual(GAME_LINE_MAX_CHARS);
  });

  it('scratching: no × during the short carry; a finish that hangs gets the × back after GAME_ESCAPE_MS', async () => {
    mocked.startScratching.mockResolvedValue({ status: 'accepted', session: makeScratchingSession(), state: catState() });
    mocked.finishScratching.mockReturnValue(new Promise(() => undefined));
    const onClose = jest.fn();
    renderWithQuery(<ScratchingOverlay view={viewOf(catState())} onClose={onClose} reduceMotion clock={clock} />);
    fireEvent.press(screen.getByTestId('cat-scratching-start'));
    await advance(0);
    expect(screen.queryByTestId('cat-scratching-layer-top-close')).toBeNull();
    fireEvent.press(screen.getByLabelText('Pohvali muco'));
    await advance(GAME_ESCAPE_MS);
    expect(screen.getByTestId('cat-scratching-layer')).toBeTruthy();
    fireEvent.press(screen.getByTestId('cat-scratching-layer-top-close'));
    expect(onClose).toHaveBeenCalledTimes(1);
  });
});
