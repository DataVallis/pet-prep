/**
 * »Zamenjaj ves pesek« with three gestures (David 2026-11-15) — the REAL component inside
 * `ChoreOverlay` on the game layer: each step is finished only by its own gesture (drag → rub →
 * hold), the drawing's progress, the same session across re-renders, the strokes payload the
 * server has always scored, hold mode, reduce motion, the exit and the line's length.
 */
import { QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, screen } from '@testing-library/react-native';
import * as Haptics from 'expo-haptics';

import { api, type ChildPetState } from '@/api/client';
import { i18n } from '@/i18n';
import ChoreOverlay from '@/modules/catCare/ChoreOverlay';
import { scoreStrokes } from '@/modules/catCare/catGames';
import { SPOTS, TRAY_ART, litterLayout } from '@/modules/catCare/litterChange/litterChange';
import { resetLitterChangeGameForTests } from '@/modules/catCare/litterChange/loadLitterChangeGame';
import { normalizeChildState, type ChildPetView } from '@/modules/childPet/childPetView';
import { setGameLayerAvailableForTests } from '@/modules/gameLayer/capability';
import { GAME_LINE_MAX_CHARS } from '@/modules/gameLayer/gameLine';
import { resetCareShellForTests } from '@/modules/gameLayer/loadCareShell';
import { useAppStore } from '@/store/appStore';
import { makeChoreSession, makeGroomingState, makeLitterState, makeLiveChildState, makePet, makeScratchingState, makeWandState, withEasyGestures } from '@/test-utils/fixtures';
import { captureAndroidBack, expectHudAboveStage, fireGamePan, fireGameTouch } from '@/test-utils/gameLayer';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, startCareChore: jest.fn(), finishCareChore: jest.fn(), getChildPet: jest.fn() } };
});

jest.setTimeout(20_000);

const mocked = api as unknown as Record<string, jest.Mock>;
const T0 = Date.parse('2026-10-04T12:00:00+02:00');
const clock = { mono: () => Date.now(), wall: () => Date.now() };
const ID = 'cat-litter_change';
const FIELD = `${ID}-field`;
const HIDDEN = { includeHiddenElements: true };
const W = 336;
const H = 516;
const L = litterLayout(W, H);

function catState(): ChildPetState {
  return makeLiveChildState({
    pet: { breed_type: 'maine_coon', species: 'cat' } as Partial<ChildPetState['pet']>,
    wand: makeWandState(),
    litter: makeLitterState(),
    grooming: makeGroomingState(),
    scratching: makeScratchingState(),
  });
}

/** Swipe mode (the usual child) / hold mode (»Lažje kretnje«). */
function viewOf(state: ChildPetState, easy = false): ChildPetView {
  const view = normalizeChildState(state, 0, T0);
  return easy ? withEasyGestures(view, true) : view;
}

async function advance(ms: number) {
  await act(async () => {
    await jest.advanceTimersByTimeAsync(ms);
  });
}

async function startGame(opts: { easy?: boolean; reduceMotion?: boolean; onClose?: () => void } = {}) {
  const session = makeChoreSession('litter_change');
  mocked.startCareChore.mockResolvedValue({ status: 'accepted', session, state: catState() });
  const ui = (view: ChildPetView) => <ChoreOverlay kind="litter_change" view={view} onClose={opts.onClose ?? jest.fn()} reduceMotion={opts.reduceMotion ?? true} clock={clock} />;
  const utils = renderWithQuery(ui(viewOf(catState(), opts.easy)));
  fireEvent.press(screen.getByTestId(`${ID}-start`));
  await advance(0);
  fireEvent(screen.getByTestId(FIELD, HIDDEN), 'layout', { nativeEvent: { layout: { x: 0, y: 0, width: W, height: H } } });
  await advance(0);
  return { session, ui, ...utils };
}

const line = () => screen.getByTestId(`${ID}-layer-top-line`).props.children as string;
const readout = () => screen.getByTestId(`${ID}-layer-top-progress`).props.accessibilityLabel as string;
const dotsDone = () => screen.queryAllByTestId(`${ID}-layer-top-dot-done`).length;

/** A drag `share` of the way from the tray's place to the bin (in three samples), then let go. */
function dragToBin(share = 1) {
  const to = (f: number) => ({ translationX: L.ux * L.pathLen * share * f, translationY: L.uy * L.pathLen * share * f });
  act(() => fireGamePan(FIELD, [to(0), to(0.5), to(1)]));
}

/** Field point of a cavity point (u, v) of the enlarged tray. */
function cavityXY(u: number, v: number): { x: number; y: number } {
  const k = (L.trayW * L.bigScale) / TRAY_ART.w;
  return {
    x: L.big.x - (TRAY_ART.w / 2 - TRAY_ART.cavity.x) * k + u * TRAY_ART.cavity.w * k,
    y: L.big.y - (TRAY_ART.h / 2 - TRAY_ART.cavity.y) * k + v * TRAY_ART.cavity.h * k,
  };
}

/** Rub back and forth along the cavity row `v` (two passes over every spot of that row). */
function rubRow(v: number) {
  const samples: { x: number; y: number }[] = [];
  for (const dir of [1, -1]) {
    for (let i = 0; i <= 20; i++) samples.push(cavityXY(dir === 1 ? i / 20 : 1 - i / 20, v));
  }
  act(() => fireGamePan(FIELD, samples));
}

/** A finger that stays down for `ms`, then lifts. */
async function hold(ms: number, at = { x: L.bag.x, y: L.bag.y }) {
  act(() => fireGameTouch(FIELD, 'begin', at));
  await advance(ms);
  act(() => fireGameTouch(FIELD, 'finalize', at));
}

describe('the weekly litter change — three gestures', () => {
  beforeEach(async () => {
    jest.clearAllMocks();
    jest.useFakeTimers({ doNotFake: ['nextTick', 'setImmediate'] });
    jest.setSystemTime(T0);
    await i18n.changeLanguage('sl');
    setGameLayerAvailableForTests(true);
    resetCareShellForTests();
    resetLitterChangeGameForTests();
    mocked.getChildPet.mockResolvedValue(catState());
    useAppStore.setState(useAppStore.getInitialState(), true);
    useAppStore.getState().signIn({ token: 't', user: { id: 2, name: 'Maja', email: null, role: 'child' }, pet: makePet(), awaitingContract: false });
  });

  afterEach(() => {
    setGameLayerAvailableForTests(false);
    resetCareShellForTests();
    resetLitterChangeGameForTests();
    jest.restoreAllMocks();
    jest.useRealTimers();
  });

  it('the intro tells the three moves; the stage is the drawn tray with the bin (no video, no bag yet)', async () => {
    renderWithQuery(<ChoreOverlay kind="litter_change" view={viewOf(catState())} onClose={jest.fn()} reduceMotion clock={clock} />);
    expect(screen.getByTestId(`${ID}-intro-text`).props.children).toContain('vsak ima svojo kretnjo');
    const session = makeChoreSession('litter_change');
    mocked.startCareChore.mockResolvedValue({ status: 'accepted', session, state: catState() });
    fireEvent.press(screen.getByTestId(`${ID}-start`));
    await advance(0);
    fireEvent(screen.getByTestId(FIELD, HIDDEN), 'layout', { nativeEvent: { layout: { x: 0, y: 0, width: W, height: H } } });
    await advance(0);
    expect(line()).toBe('Povleci stranišče do koša');
    expect(readout()).toBe('1. korak od 3. Stari pesek: še 100 %.');
    expect(screen.getByTestId('cat-tray', HIDDEN)).toBeTruthy();
    expect(screen.getByTestId(`${ID}-bin`, HIDDEN)).toBeTruthy();
    expect(screen.queryByTestId(`${ID}-bag`, HIDDEN)).toBeNull();
    expect(screen.queryByTestId(`${ID}-stage-video`, HIDDEN)).toBeNull();
    // Swipe mode: no button.
    expect(screen.queryByTestId(`${ID}-hold`)).toBeNull();
  });

  it('step 1 — only DRAGGING the tray to the bin empties it: a rub or a hold does not; 40 % per drag, empty after the third (✓)', async () => {
    await startGame();
    await advance(500);
    // A rub on the tray (back and forth, never as far as the bin).
    act(() =>
      fireGamePan(
        FIELD,
        [0, 50, 0, -50, 0, 50, 0].map((dx) => ({ x: L.home.x + dx, y: L.home.y, translationX: dx, translationY: 0 })),
      ),
    );
    // A press and hold.
    await hold(1_500, { x: L.home.x, y: L.home.y });
    // A drag only half way: the tray tilts but nothing pours yet.
    dragToBin(0.5);
    await advance(0);
    expect(readout()).toBe('1. korak od 3. Stari pesek: še 100 %.');
    expect(dotsDone()).toBe(0);
    expect(Haptics.impactAsync).not.toHaveBeenCalled();

    dragToBin();
    await advance(0);
    expect(readout()).toBe('1. korak od 3. Stari pesek: še 60 %.');
    await advance(600);
    dragToBin();
    await advance(0);
    expect(readout()).toBe('1. korak od 3. Stari pesek: še 20 %.');
    expect(dotsDone()).toBe(0);
    await advance(600);
    dragToBin();
    await advance(0);
    expect(readout()).toBe('1. korak od 3. Stari pesek: še 0 %.');
    // ✓ the moment the child emptied it (not at the end of the step's time) …
    expect(dotsDone()).toBe(1);
    expect(Haptics.notificationAsync).toHaveBeenCalledTimes(1);
    expect(screen.getByTestId(`${ID}-layer-feedback-ok`, HIDDEN)).toBeTruthy();
    // … and the line says so once the instruction had its 2 s.
    await advance(0);
    expect(line()).toBe('Prazno! Bravo!');
    // More drags change nothing (and send no more strokes).
    const before = (Haptics.impactAsync as jest.Mock).mock.calls.length;
    dragToBin();
    await advance(0);
    expect((Haptics.impactAsync as jest.Mock).mock.calls.length).toBe(before);
  });

  it('step 2 — only RUBBING washes: a hold or a drag to the bin does not; spots fade where rubbed, clean at 7 of 8 (✓)', async () => {
    await startGame();
    await advance(10_000);
    expect(line()).toBe('Drgni po madežih, da izginejo');
    // The time of step 1 ran out: the tray is shown emptied, but WITHOUT a ✓.
    expect(dotsDone()).toBe(0);
    expect(screen.queryByTestId(`${ID}-bin`, HIDDEN)).toBeNull();
    expect(readout()).toBe('2. korak od 3. Preostali madeži: 8.');

    // Hold on a spot: nothing.
    await hold(1_500, cavityXY(SPOTS[0].u, SPOTS[0].v));
    expect(readout()).toBe('2. korak od 3. Preostali madeži: 8.');
    // One pass over the upper row: the spots are lighter, none gone yet.
    act(() => fireGamePan(FIELD, [cavityXY(0, 0.52), cavityXY(0.5, 0.52), cavityXY(1, 0.52)]));
    await advance(0);
    expect(readout()).toBe('2. korak od 3. Preostali madeži: 8.');
    expect(screen.getByTestId(`${ID}-spot-0`, HIDDEN).props.style).toEqual(expect.arrayContaining([expect.objectContaining({ opacity: expect.any(Number) })]));
    // Rubbing the upper row: its four spots go.
    rubRow(0.52);
    await advance(0);
    expect(readout()).toBe('2. korak od 3. Preostali madeži: 4.');
    expect(dotsDone()).toBe(0);
    // The lower row: most spots are gone → clean (the last one goes with the sparkle).
    await advance(400);
    rubRow(0.8);
    await advance(0);
    expect(readout()).toBe('2. korak od 3. Preostali madeži: 0.');
    expect(dotsDone()).toBe(1);
    await advance(2_000);
    expect(line()).toBe('Čisto! Bravo!');
  });

  it('step 3 — only PRESS AND HOLD fills: the level rises while held, pauses on release, stops at the line (✓) — never over it', async () => {
    await startGame();
    await advance(20_000);
    expect(line()).toBe('Pritisni in drži vrečo s peskom');
    expect(screen.getByTestId(`${ID}-bag`, HIDDEN)).toBeTruthy();
    expect(screen.getByTestId(`${ID}-fill-line`, HIDDEN)).toBeTruthy();
    expect(readout()).toBe('3. korak od 3. Svež pesek: 0 % do črte.');
    // A rub (the finger moves, then lifts at once) pours next to nothing.
    rubRow(0.6);
    await advance(0);
    expect(readout()).toBe('3. korak od 3. Svež pesek: 0 % do črte.');

    await hold(2_500);
    expect(readout()).toBe('3. korak od 3. Svež pesek: 50 % do črte.');
    // Released: paused.
    await advance(1_000);
    expect(readout()).toBe('3. korak od 3. Svež pesek: 50 % do črte.');
    expect(dotsDone()).toBe(0);
    // Holding on the tray works too — and holding on past the line changes nothing.
    act(() => fireGameTouch(FIELD, 'begin', { x: L.big.x, y: L.big.y }));
    await advance(2_500);
    expect(readout()).toBe('3. korak od 3. Svež pesek: 100 % do črte.');
    expect(dotsDone()).toBe(1);
    const strokes = (Haptics.impactAsync as jest.Mock).mock.calls.length;
    await advance(1_500);
    act(() => fireGameTouch(FIELD, 'finalize'));
    await hold(1_000);
    expect(readout()).toBe('3. korak od 3. Svež pesek: 100 % do črte.');
    expect((Haptics.impactAsync as jest.Mock).mock.calls.length).toBe(strokes);
    expect(line()).toBe('Polno do črte! Bravo!');
  });

  it('short presses add up to the same pour beats as one long hold', async () => {
    const { session } = await startGame();
    mocked.finishCareChore.mockReturnValue(new Promise(() => undefined));
    await advance(20_000);
    for (let i = 0; i < 25; i++) {
      await hold(200);
      await advance(100);
    }
    expect(readout()).toBe('3. korak od 3. Svež pesek: 100 % do črte.');
    await advance(10_000);
    const sent = mocked.finishCareChore.mock.calls[0][2] as number[];
    expect(mocked.finishCareChore.mock.calls[0][1]).toBe(session.id);
    expect(sent).toHaveLength(7);
  });

  it('a whole change: the same session, a re-render mid-step keeps the progress, and the strokes the server has always scored are sent', async () => {
    const { session, ui, rerender, client } = await startGame();
    mocked.finishCareChore.mockResolvedValue({
      status: 'accepted',
      result: { session_id: session.id, success: true, reason: null, strokes: 12, min_strokes: 10, segments_hit: 3, segments: 3, matted: false },
      state: catState(),
    });
    // Step 1: three drags.
    for (const wait of [1_000, 1_000, 1_000]) {
      await advance(wait);
      dragToBin();
    }
    await advance(0);
    expect(dotsDone()).toBe(1);

    // Step 2 — half washed, then a broadcast / refetch lands (a new view object with the child's own running session).
    await advance(8_000);
    rubRow(0.52);
    await advance(0);
    expect(readout()).toBe('2. korak od 3. Preostali madeži: 4.');
    const running = makeLitterState({}, { session_running: true, can_start: false, blocked_reason: 'litter_change_session_active', session });
    rerender(<QueryClientProvider client={client}>{ui(viewOf({ ...catState(), litter: running } as ChildPetState))}</QueryClientProvider>);
    await advance(1_000);
    expect(screen.queryByTestId(`${ID}-start`)).toBeNull();
    expect(readout()).toBe('2. korak od 3. Preostali madeži: 4.');
    expect(dotsDone()).toBe(1);
    rubRow(0.8);
    await advance(0);
    expect(dotsDone()).toBe(2);

    // Step 3: one hold to the line.
    await advance(9_000);
    expect(line()).toBe('Pritisni in drži vrečo s peskom');
    await hold(5_000);
    expect(dotsDone()).toBe(3);
    await advance(4_000);

    expect(mocked.startCareChore).toHaveBeenCalledTimes(1);
    expect(mocked.finishCareChore).toHaveBeenCalledTimes(1);
    const [kind, id, sent] = mocked.finishCareChore.mock.calls[0] as [string, string, number[]];
    expect([kind, id]).toEqual(['litter_change', session.id]);
    // A stroke each time the pour passes another fifth, the rub's turns / lifts and the spots
    // rubbed away, then a pour beat per stretch of holding — every one at its real time.
    expect(sent.filter((t) => t < 10_000)).toEqual([1_000, 2_000, 3_000]);
    expect([...new Set(sent.filter((t) => t >= 10_000 && t < 20_000))]).toEqual([11_000, 12_000]);
    expect(sent.filter((t) => t >= 20_000)).toEqual([21_300, 22_080, 22_780, 23_640, 24_250, 24_990, 25_810]);
    // … which is what `CatChoreService::score` accepts (its mirror): enough, spread, not machine-regular.
    expect(scoreStrokes(sent, session)).toMatchObject({ success: true, reason: null, segments_hit: 3 });
    await advance(0);
    expect(screen.getByTestId(`${ID}-result-title`).props.children).toBe('Sveže in čisto!');
  });

  it('hold mode (»Lažje kretnje« / screen reader): one labelled button per step does the same work; a tap = one beat', async () => {
    const { session } = await startGame({ easy: true });
    mocked.finishCareChore.mockReturnValue(new Promise(() => undefined));
    expect(line()).toBe('Drži gumb »Izprazni«');
    const button = () => screen.getByTestId(`${ID}-hold`);
    expect(button().props.accessibilityLabel).toBe('Izprazni stranišče. Pridrži, da pesek steče v koš.');
    // A tap (also the screen reader's activation): one fifth.
    await advance(1_000);
    fireEvent.press(button());
    expect(readout()).toBe('1. korak od 3. Stari pesek: še 80 %.');
    expect(button().props.accessibilityValue).toEqual({ text: 'Stari pesek: še 80 %.' });
    // Holding: the rest, beat by beat.
    fireEvent(button(), 'pressIn');
    await advance(4_000);
    fireEvent(button(), 'pressOut');
    expect(readout()).toBe('1. korak od 3. Stari pesek: še 0 %.');
    expect(dotsDone()).toBe(1);
    expect(button().props.accessibilityState).toMatchObject({ disabled: true });

    await advance(5_000);
    expect(line()).toBe('Drži gumb »Umij«');
    expect(button().props.accessibilityLabel).toBe('Umij stranišče. Pridrži, da madeži izginejo.');
    fireEvent(button(), 'pressIn');
    await advance(6_000);
    fireEvent(button(), 'pressOut');
    expect(dotsDone()).toBe(2);

    await advance(4_000);
    expect(line()).toBe('Drži gumb »Napolni«');
    fireEvent(button(), 'pressIn');
    await advance(5_000);
    fireEvent(button(), 'pressOut');
    expect(readout()).toBe('3. korak od 3. Svež pesek: 100 % do črte.');
    expect(dotsDone()).toBe(3);
    await advance(5_000);
    const sent = mocked.finishCareChore.mock.calls[0][2] as number[];
    expect(scoreStrokes(sent, session)).toMatchObject({ success: true, segments_hit: 3 });
    expect(sent).toHaveLength(5 + 7 + 6);
  });

  it('with motion: the same gestures finish every step, with a sparkle; reduce motion has none', async () => {
    await startGame({ reduceMotion: false });
    for (let i = 0; i < 3; i++) {
      await advance(500);
      dragToBin();
    }
    await advance(0);
    expect(dotsDone()).toBe(1);
    expect(screen.getByTestId('mess-sparkles', HIDDEN)).toBeTruthy();
    await advance(8_500);
    rubRow(0.52);
    rubRow(0.8);
    await advance(0);
    expect(dotsDone()).toBe(2);
    await advance(10_000);
    await hold(5_000);
    expect(dotsDone()).toBe(3);
  });

  it('reduce motion: no sparkle', async () => {
    await startGame({ reduceMotion: true });
    for (let i = 0; i < 3; i++) {
      await advance(500);
      dragToBin();
    }
    await advance(0);
    expect(dotsDone()).toBe(1);
    expect(screen.queryByTestId('mess-sparkles', HIDDEN)).toBeNull();
  });

  it('the HUD is above the stage; × stops it; Android back mid-drag is only a hint; nothing moves while it is saved', async () => {
    const back = captureAndroidBack();
    const onClose = jest.fn();
    await startGame({ onClose });
    mocked.finishCareChore.mockReturnValue(new Promise(() => undefined));
    expectHudAboveStage(`${ID}-layer`, [`${ID}-layer-top-close`, `${ID}-layer-area`]);
    await advance(2_100);
    expect(back.pressBack()).toBe(true);
    await advance(0);
    expect(line()).toBe('Za konec igre tapni ×.');
    expect(screen.getByTestId(`${ID}-layer`)).toBeTruthy();
    expect(onClose).not.toHaveBeenCalled();
    // While the result is being saved the gestures are off.
    await advance(28_000);
    expect(mocked.finishCareChore).toHaveBeenCalledTimes(1);
    await hold(3_000);
    expect(readout()).toBe('3. korak od 3. Svež pesek: 0 % do črte.');
    expect(line()).not.toBe('Pritisni in drži vrečo s peskom');
  });

  it('× stops the running change: nothing is sent, the start card is back', async () => {
    await startGame();
    dragToBin();
    fireEvent.press(screen.getByTestId(`${ID}-layer-top-close`));
    await advance(0);
    expect(screen.getByTestId(`${ID}-start`)).toBeTruthy();
    expect(mocked.finishCareChore).not.toHaveBeenCalled();
  });

  it('a binary without the game layer keeps the card screen with the rub (and its old intro)', async () => {
    setGameLayerAvailableForTests(false);
    resetCareShellForTests();
    resetLitterChangeGameForTests();
    mocked.startCareChore.mockResolvedValue({ status: 'accepted', session: makeChoreSession('litter_change'), state: catState() });
    renderWithQuery(<ChoreOverlay kind="litter_change" view={viewOf(catState())} onClose={jest.fn()} reduceMotion clock={clock} />);
    expect(screen.getByTestId(`${ID}-intro-text`).props.children).toContain('Drgni s prstom.');
    fireEvent.press(screen.getByTestId(`${ID}-start`));
    await advance(0);
    expect(screen.getByTestId(`${ID}-running`)).toBeTruthy();
    expect(screen.getByTestId(`${ID}-stage-touch`)).toBeTruthy();
    expect(screen.queryByTestId(FIELD, HIDDEN)).toBeNull();
  });

  it.each(['sl', 'en'])('every line of the three steps fits the game line (%s)', async (lang) => {
    await i18n.changeLanguage(lang);
    for (const group of ['play', 'playDone', 'playHold'] as const) {
      for (const n of ['1', '2', '3'] as const) {
        const text = i18n.t(`cat:chore.litter_change.${group}.${n}`);
        expect(text.length).toBeGreaterThan(0);
        expect(Array.from(text).length).toBeLessThanOrEqual(GAME_LINE_MAX_CHARS);
      }
    }
    for (const n of ['1', '2', '3'] as const) {
      expect(i18n.t(`cat:chore.litter_change.playButton.${n}`).length).toBeLessThanOrEqual(10);
    }
  });
});
