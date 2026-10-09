/**
 * M5-R06-08a overlays: what the child sees and what each one sends — the wand game through
 * real drag callbacks and the accessible button, the stroke games, "Na praskalnik" with
 * the praise button, the scoop tap game (optimistic, one request), blocked reasons worded
 * with the family-local time, one game at a time, nothing for a dog.
 */
import { act, fireEvent, screen } from '@testing-library/react-native';
import { PanResponder, type GestureResponderEvent, type PanResponderCallbacks, type PanResponderGestureState } from 'react-native';

import { api, type ChildPetState } from '@/api/client';
import { childPetKey, writeChildState } from '@/hooks/queries/useChildPet';
import CatCareOverlay from '@/modules/catCare/CatCareOverlay';
import ChoreOverlay from '@/modules/catCare/ChoreOverlay';
import ScoopOverlay, { clumpCount } from '@/modules/catCare/ScoopOverlay';
import ScratchingOverlay from '@/modules/catCare/ScratchingOverlay';
import WandOverlay from '@/modules/catCare/WandOverlay';
import { normalizeChildState, type ChildPetView } from '@/modules/childPet/childPetView';
import { useAppStore } from '@/store/appStore';
import {
  makeChoreSession,
  makeGroomingState,
  makeMedia,
  makeLiveChildState,
  makeLitterState,
  makePet,
  makeScratchingSession,
  makeScratchingState,
  makeWandSession,
  makeWandState,
} from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: {
      ...actual.api,
      startWand: jest.fn(),
      finishWand: jest.fn(),
      startCareChore: jest.fn(),
      finishCareChore: jest.fn(),
      startScratching: jest.fn(),
      finishScratching: jest.fn(),
      scoopLitter: jest.fn(),
      getChildPet: jest.fn(),
    },
  };
});

const mocked = api as unknown as Record<string, jest.Mock>;
const T0 = Date.parse('2026-10-04T12:00:00+02:00');
const clock = { mono: () => Date.now(), wall: () => Date.now() };

function catState(o: { wand?: unknown; litter?: unknown; grooming?: unknown; scratching?: unknown } = {}): ChildPetState {
  return makeLiveChildState({
    pet: { breed_type: 'maine_coon', species: 'cat' } as Partial<ChildPetState['pet']>,
    wand: o.wand ?? makeWandState(),
    litter: o.litter ?? makeLitterState(),
    grooming: o.grooming ?? makeGroomingState(),
    scratching: o.scratching ?? makeScratchingState(),
  });
}

function viewOf(state: ChildPetState): ChildPetView {
  return normalizeChildState(state, 0, T0);
}

async function advance(ms: number) {
  await act(async () => {
    await jest.advanceTimersByTimeAsync(ms);
  });
}

beforeEach(() => {
  jest.clearAllMocks();
  jest.useFakeTimers({ doNotFake: ['nextTick', 'setImmediate'] });
  jest.setSystemTime(T0);
  mocked.getChildPet.mockResolvedValue(catState());
  useAppStore.setState(useAppStore.getInitialState(), true);
  useAppStore.getState().signIn({ token: 't', user: { id: 2, name: 'Maja', email: null, role: 'child' }, pet: makePet(), awaitingContract: false });
});

afterEach(() => {
  jest.useRealTimers();
  jest.restoreAllMocks();
});

describe('CatCareOverlay host', () => {
  it('renders nothing for a dog or while closed; one game at a time', async () => {
    const dog = viewOf(makeLiveChildState());
    useAppStore.getState().openCatGame('wand');
    const { unmount } = renderWithQuery(<CatCareOverlay view={dog} reduceMotion />);
    await advance(0);
    expect(screen.queryByTestId('cat-wand')).toBeNull();
    // QA m3: a dog can't play it → closed again.
    expect(useAppStore.getState().catOverlay).toBeNull();

    unmount();
    // One game at a time.
    useAppStore.getState().openCatGame('wand');
    expect(useAppStore.getState().openCatGame('grooming')).toBe(false);
    expect(useAppStore.getState().catOverlay).toBe('wand');
    useAppStore.getState().closeCatGame();
    expect(useAppStore.getState().openCatGame('grooming')).toBe(true);
  });

  it('opens the game the store names, closes through the store', async () => {
    useAppStore.getState().openCatGame('scratching');
    renderWithQuery(<CatCareOverlay view={viewOf(catState())} reduceMotion clock={clock} />);
    await advance(0);
    expect(screen.getByTestId('cat-scratching')).toBeTruthy();
    fireEvent.press(screen.getByTestId('cat-scratching-close'));
    expect(useAppStore.getState().catOverlay).toBeNull();
  });

  it('a domestic cat (no grooming block) can\'t open grooming', async () => {
    useAppStore.getState().openCatGame('grooming');
    renderWithQuery(<CatCareOverlay view={viewOf(makeLiveChildState({ wand: makeWandState(), grooming: null }))} reduceMotion />);
    await advance(0);
    expect(screen.queryByTestId('cat-grooming')).toBeNull();
    // QA m3: the store doesn't keep an unplayable game "open".
    expect(useAppStore.getState().catOverlay).toBeNull();
  });
});

describe('WandOverlay', () => {
  const realCreate = PanResponder.create.bind(PanResponder);
  let configs: PanResponderCallbacks[] = [];
  beforeEach(() => {
    configs = [];
    jest.spyOn(PanResponder, 'create').mockImplementation((config) => {
      configs.push(config);
      return realCreate(config);
    });
  });

  const gesture = (g: Partial<PanResponderGestureState>): PanResponderGestureState => ({
    stateID: 1,
    moveX: 0,
    moveY: 0,
    x0: 0,
    y0: 0,
    dx: 0,
    dy: 0,
    vx: 0,
    vy: 0,
    numberActiveTouches: 1,
    _accountsForMovesUpTo: 0,
    ...g,
  });
  const at = (x: number, y: number) => ({ nativeEvent: { locationX: x, locationY: y } }) as unknown as GestureResponderEvent;

  it('intro: today\'s games and why it can\'t start now (family-local time), start off', () => {
    const view = viewOf(catState({ wand: makeWandState({ sessions_today: 1, can_start: false, blocked_reason: 'wand_too_soon', next_allowed_at: '2026-10-04T14:05:00+02:00' }) }));
    renderWithQuery(<WandOverlay view={view} onClose={jest.fn()} reduceMotion />);
    expect(screen.getByText('Igre danes: 1 od 2')).toBeTruthy();
    expect(screen.getByTestId('cat-wand-blocked').props.children).toBe('Muca po zadnji igri počiva. Igrata se lahko spet ob 14:05.');
    expect(screen.getByTestId('cat-wand-start').props.accessibilityState?.disabled).toBe(true);
  });

  it('play: dragging away from the cat records "away" moves, the button too; finishes with them at 60 s', async () => {
    mocked.startWand.mockResolvedValue({ status: 'accepted', session: makeWandSession(), state: catState() });
    mocked.finishWand.mockResolvedValue({
      status: 'accepted',
      result: { session_id: makeWandSession().id, success: true, reason: null, away_moves: 2, toward_moves: 0, segments_hit: 4, segments: 4, pounces_hit: 3, pounces: 3 },
      state: catState({ wand: makeWandState({ sessions_today: 1 }) }),
    });
    const onClose = jest.fn();
    renderWithQuery(<WandOverlay view={viewOf(catState())} onClose={onClose} reduceMotion clock={clock} />);
    fireEvent.press(screen.getByTestId('cat-wand-start'));
    await advance(0);
    expect(screen.getByTestId('cat-wand-running')).toBeTruthy();
    expect(screen.getByText('Muca opazuje pero …')).toBeTruthy();
    fireEvent(screen.getByTestId('cat-wand-stage-touch'), 'layout', { nativeEvent: { layout: { x: 0, y: 0, width: 300, height: 400 } } });

    await advance(3_000);
    // The cat sits at (150, 330): drag the feather from (150, 250) up to (150, 130) — away.
    const config = configs[configs.length - 1];
    act(() => {
      config.onPanResponderGrant?.(at(150, 250), gesture({}));
      for (let i = 1; i <= 6; i++) config.onPanResponderMove?.(at(150, 250), gesture({ dy: -20 * i }));
      config.onPanResponderRelease?.(at(150, 250), gesture({ dy: -120 }));
    });
    expect(screen.getByTestId('cat-wand-feedback').props.children).toBe('Super — pero je ušlo!');
    expect(screen.queryByTestId('cat-wand-pounce-cue', { includeHiddenElements: true })).toBeNull();

    await advance(12_200);
    expect(screen.getByText('Skok! Umakni pero!')).toBeTruthy();
    // QA m2: the pounce cue shows while an answer counts (free drawing here).
    expect(screen.getByTestId('cat-wand-pounce-cue', { includeHiddenElements: true })).toBeTruthy();
    fireEvent.press(screen.getByLabelText('Umakni pero stran od muce'));
    expect(screen.getByTestId('cat-wand-feedback').props.children).toBe('Odličen umik!');
    expect(screen.getByTestId('cat-wand-stop')).toBeTruthy();

    await advance(45_000);
    expect(mocked.finishWand).toHaveBeenCalledTimes(1);
    const [, moves] = mocked.finishWand.mock.calls[0] as [string, { t: number; away: boolean }[]];
    expect(moves).toEqual([
      { t: 3_000, away: true },
      { t: 15_200, away: true },
    ]);
    await advance(0);
    expect(screen.getByTestId('cat-wand-result-title').props.children).toBe('Kakšen lov!');
    fireEvent.press(screen.getByTestId('cat-wand-done'));
    expect(onClose).toHaveBeenCalled();
  });

  it('"Ustavi" mid-game: back to the intro, nothing sent', async () => {
    mocked.startWand.mockResolvedValue({ status: 'accepted', session: makeWandSession(), state: catState() });
    renderWithQuery(<WandOverlay view={viewOf(catState())} onClose={jest.fn()} reduceMotion clock={clock} />);
    fireEvent.press(screen.getByTestId('cat-wand-start'));
    await advance(0);
    expect(screen.getByLabelText('Ustavi igro. Ne bo štela, ampak nič hudega — lahko začneš znova kadarkoli.')).toBeTruthy();
    fireEvent.press(screen.getByTestId('cat-wand-stop'));
    expect(screen.getByTestId('cat-wand-intro')).toBeTruthy();
    await advance(120_000);
    expect(mocked.finishWand).not.toHaveBeenCalled();
  });
});

describe('ChoreOverlay', () => {
  it('grooming intro: week progress and the matted note; strokes by button reach the server', async () => {
    const session = makeChoreSession('grooming');
    mocked.startCareChore.mockResolvedValue({ status: 'accepted', session, state: catState() });
    mocked.finishCareChore.mockResolvedValue({
      status: 'rejected',
      result: { session_id: session.id, success: false, reason: 'too_few_strokes', strokes: 3, min_strokes: 10, segments_hit: 2, segments: 3, matted: false },
      state: catState(),
    });
    const view = viewOf(catState({ grooming: makeGroomingState({ matted: true, session_seconds: 60 }) }));
    renderWithQuery(<ChoreOverlay kind="grooming" view={view} onClose={jest.fn()} reduceMotion clock={clock} />);
    expect(screen.getByText('Česanje ta teden: 1 od 3')).toBeTruthy();
    expect(screen.getByText('V dlaki ima vozel. To česanje traja malo dlje (60 s) in vozel razreši.')).toBeTruthy();

    fireEvent.press(screen.getByTestId('cat-grooming-start'));
    await advance(0);
    expect(screen.getByText('Glava in vrat')).toBeTruthy();
    for (const wait of [1_000, 9_500, 10_400]) {
      await advance(wait);
      fireEvent.press(screen.getByLabelText('Enkrat počeši muco'));
    }
    expect(screen.getByText('Boki in rep')).toBeTruthy();
    expect(screen.getByTestId('cat-grooming-progress').props.children).toBe('Poteze: 3 od 10');
    await advance(10_000);
    expect(mocked.finishCareChore).toHaveBeenCalledWith('grooming', session.id, [1_000, 10_500, 20_900]);
    await advance(0);
    expect(screen.getByTestId('cat-grooming-result-title').props.children).toBe('To česanje ni štelo');
    expect(screen.getByText('Naslednjič še nekaj potez — nadaljuj, dokler se čas ne izteče.')).toBeTruthy();
    expect(screen.getByText('Nič hudega — nič slabega se ne zgodi. Lahko takoj poskusiš znova.')).toBeTruthy();
  });

  it('weekly litter change: overdue note; done this week → blocked with the next week in words', () => {
    const overdue = viewOf(catState({ litter: makeLitterState({}, { overdue: true }) }));
    const { unmount } = renderWithQuery(<ChoreOverlay kind="litter_change" view={overdue} onClose={jest.fn()} reduceMotion />);
    expect(screen.getByText('Pesek zamenjaj najkasneje v četrtek ob 09:30.')).toBeTruthy();
    expect(screen.getByText(/zato pesek smrdi/)).toBeTruthy();
    unmount();

    const done = viewOf(catState({ litter: makeLitterState({}, { done: true, can_start: false, blocked_reason: 'litter_change_done' }) }));
    renderWithQuery(<ChoreOverlay kind="litter_change" view={done} onClose={jest.fn()} reduceMotion />);
    expect(screen.getByText('Menjava peska ta teden je opravljena ✓')).toBeTruthy();
    expect(screen.getByTestId('cat-litter_change-blocked').props.children).toBe('Pesek je ta teden že zamenjan. Naslednja menjava je v četrtek ob 09:30.');
    expect(screen.getByTestId('cat-litter_change-start').props.accessibilityState?.disabled).toBe(true);
  });

  it('QA m1: another child changing the litter — no week-end time in that text', () => {
    const busy = viewOf(catState({ litter: makeLitterState({}, { can_start: false, blocked_reason: 'litter_change_session_active', session_running: true }) }));
    renderWithQuery(<ChoreOverlay kind="litter_change" view={busy} onClose={jest.fn()} reduceMotion />);
    expect(screen.getByTestId('cat-litter_change-blocked').props.children).toBe('Pesek zdaj menja nekdo drug. Poskusi čez minuto.');
  });
});

describe('ScratchingOverlay', () => {
  it('intro (never punish), carry, praise right after the landing → resolved', async () => {
    const session = makeScratchingSession();
    mocked.startScratching.mockResolvedValue({ status: 'accepted', session, state: catState() });
    mocked.finishScratching.mockResolvedValue({
      status: 'accepted',
      result: { session_id: session.id, success: true, reason: null, praise_ms: 1_700, land_at_ms: 1_200, delay_ms: 500, praise_window_ms: 3_000 },
      state: catState({ scratching: makeScratchingState({ active: null, can_start: false, blocked_reason: 'scratching_not_needed' }) }),
    });
    renderWithQuery(<ScratchingOverlay view={viewOf(catState())} onClose={jest.fn()} reduceMotion clock={clock} />);
    expect(screen.getByText('Nikoli ne vpij in je ne kaznuj — samo pokaži ji pravo mesto in jo pohvali.')).toBeTruthy();
    expect(screen.getByTestId('cat-scratching-due').props.children).toBe('Na praskalnik jo odnesi najkasneje ob 14:00.');
    fireEvent.press(screen.getByTestId('cat-scratching-start'));
    await advance(0);
    expect(screen.getByText('Neseš jo na praskalnik …')).toBeTruthy();
    // No "stop" while carrying.
    expect(screen.queryByTestId('cat-scratching-close')).toBeNull();
    await advance(1_300);
    expect(screen.getByText('Praska praskalnik — zdaj jo pohvali!')).toBeTruthy();
    await advance(400);
    fireEvent.press(screen.getByLabelText('Pohvali muco'));
    await advance(0);
    expect(mocked.finishScratching).toHaveBeenCalledWith(session.id, 1_700);
    expect(screen.getByTestId('cat-scratching-result-title').props.children).toBe('Bravo!');
  });

  it('premium: the cat\'s own `scratching` video in the intro; without it the scratched-sofa graphic', () => {
    const withVideo = catState();
    (withVideo.pet as { media: unknown }).media = makeMedia({ status: 'ready', videos: { idle: 'https://x/i.mp4', scratching: 'https://x/s.mp4' }, states: ['idle', 'scratching'] });
    const { unmount } = renderWithQuery(<ScratchingOverlay view={viewOf(withVideo)} onClose={jest.fn()} reduceMotion />);
    expect(screen.getByTestId('cat-scratching-scene-video')).toBeTruthy();
    unmount();
    const idleOnly = catState();
    (idleOnly.pet as { media: unknown }).media = makeMedia({ status: 'ready', videos: { idle: 'https://x/i.mp4' }, states: ['idle'] });
    renderWithQuery(<ScratchingOverlay view={viewOf(idleOnly)} onClose={jest.fn()} reduceMotion />);
    expect(screen.queryByTestId('cat-scratching-scene-video')).toBeNull();
    expect(screen.getByTestId('scene-graphic-scratching')).toBeTruthy();
  });

  it('nothing scratched: a calm line and "Končano"', () => {
    renderWithQuery(<ScratchingOverlay view={viewOf(catState({ scratching: makeScratchingState({ active: null, can_start: false }) }))} onClose={jest.fn()} reduceMotion />);
    expect(screen.getByTestId('cat-scratching-nothing').props.children).toBe('Muca ni ničesar opraskala. Vse je v redu!');
    expect(screen.queryByTestId('cat-scratching-start')).toBeNull();
  });
});

describe('ScoopOverlay', () => {
  it('two clumps per use (2–6)', () => {
    expect([0, 1, 2, 3, 4].map(clumpCount)).toEqual([2, 2, 4, 6, 6]);
  });

  it('scoop every clump → ONE optimistic request, then the server state', async () => {
    let resolve: (v: unknown) => void = () => undefined;
    mocked.scoopLitter.mockReturnValue(new Promise((r) => (resolve = r)));
    const state = catState();
    const { client } = renderWithQuery(<ScoopOverlay view={viewOf(state)} onClose={jest.fn()} />);
    writeChildState(client, state);
    expect(screen.getByTestId('cat-scoop-due').props.children).toBe('Počisti ga najkasneje ob 15:00.');
    fireEvent.press(screen.getByTestId('cat-scoop-clump-0'));
    expect(screen.getByText('Pobrano: 1 od 2')).toBeTruthy();
    fireEvent.press(screen.getAllByLabelText('Poberi grudico')[0]);
    await advance(0);
    expect(mocked.scoopLitter).toHaveBeenCalledTimes(1);
    expect(client.getQueryData<ChildPetView>(childPetKey)?.cat.litter?.can_scoop).toBe(false);
    expect(screen.getByTestId('cat-scoop-saving')).toBeTruthy();
    await act(async () => {
      resolve({ status: 'accepted', scooped: 1, state: catState({ litter: makeLitterState({ open_uses: [], can_scoop: false, next_due_at: null, uses_per_day: 3 }) }) });
      await jest.advanceTimersByTimeAsync(0);
    });
    expect(screen.getByTestId('cat-scoop-result').props.children).toBe('Bravo! Pesek je čist.');
    expect(client.getQueryData<ChildPetView>(childPetKey)?.cat.litter?.uses_per_day).toBe(3);
  });

  it('offline: the tray comes back and the state is refetched; the text is calm', async () => {
    mocked.scoopLitter.mockRejectedValue(new TypeError('Network request failed'));
    const state = catState();
    const { client } = renderWithQuery(<ScoopOverlay view={viewOf(state)} onClose={jest.fn()} />);
    writeChildState(client, state);
    fireEvent.press(screen.getByTestId('cat-scoop-clump-0'));
    fireEvent.press(screen.getByTestId('cat-scoop-clump-1'));
    await advance(0);
    expect(screen.getByTestId('cat-scoop-result').props.children).toBe('Ni povezave. Preveri internet in poskusi znova.');
    expect(client.getQueryData<ChildPetView>(childPetKey)?.cat.litter?.can_scoop).toBe(true);
  });

  it('nothing to scoop; a mess next to the tray is pointed out', () => {
    const clean = viewOf(catState({ litter: makeLitterState({ open_uses: [], can_scoop: false, next_due_at: null }) }));
    const { unmount } = renderWithQuery(<ScoopOverlay view={clean} onClose={jest.fn()} />);
    expect(screen.getByTestId('cat-scoop-nothing')).toBeTruthy();
    unmount();
    const mess = viewOf(catState({ litter: makeLitterState({ open_uses: [{ id: 1, used_at: '2026-10-04T06:00:00+02:00', due_at: '2026-10-04T10:00:00+02:00', expired: true }] }) }));
    renderWithQuery(<ScoopOverlay view={mess} onClose={jest.fn()} />);
    expect(screen.getByTestId('cat-scoop-mess').props.children).toBe('Zraven peska je tudi nered. Počisti ga z gumbom za čiščenje.');
  });
});
