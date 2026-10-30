/**
 * GAMES_SPEC §6.2 — the cleaning games of the native layer, end to end through their recorded
 * gesture worklets: the capability fallback (older binary → the old overlays), the bag (poop) and
 * the cloth (puddle) with ONE `onCleaned`, the first-clean tip, the slipper → box → toy with ONE
 * `onResolved`, and the litter scoop (stick → shake → bag) with ONE scoop request.
 */
import { act, fireEvent, screen } from '@testing-library/react-native';
import * as Haptics from 'expo-haptics';

import { api, type ChildPetState } from '@/api/client';
import CatCareOverlay from '@/modules/catCare/CatCareOverlay';
import { normalizeChildState, type ChildPetView } from '@/modules/childPet/childPetView';
import { CLEANING_TIPS_KEY, setTipStoreForTests, type TipStore } from '@/modules/cleaning/cleaningTips';
import { LIFT } from '@/modules/cleaning/MessParts';
import { loadMessGames, resetMessGamesForTests, type MessGames } from '@/modules/cleaning/loadMessGames';
import { piecePlacements, type MessStage } from '@/modules/cleaning/messPlan';
import { clumpInTray } from '@/modules/cleaning/scoop';
import { trayGeometry } from '@/modules/cleaning/ScoopGame';
import { DOG_ZONE, SLIPPER_ITEMS, SLIPPER_TOY } from '@/modules/cleaning/SlipperGame';
import { setGameLayerAvailableForTests } from '@/modules/gameLayer/capability';
import { useAppStore } from '@/store/appStore';
import { makeGroomingState, makeLitterState, makeLiveChildState, makePet, makeScratchingState, makeWandState, withEasyGestures } from '@/test-utils/fixtures';
import { fireGameGesture } from '@/test-utils/gameLayer';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, scoopLitter: jest.fn(), getChildPet: jest.fn() } };
});

const mocked = api as unknown as Record<string, jest.Mock>;
const T0 = Date.parse('2026-10-04T12:00:00+02:00');
const SIZE = { width: 400, height: 800 };

function dogView(o: { easy?: boolean; feedback?: boolean } = {}): ChildPetView {
  const view = normalizeChildState(makeLiveChildState({ settings: { game_feedback: o.feedback ?? true } }), 0, T0);
  return withEasyGestures(view, o.easy ?? false);
}

function catState(litter: unknown = makeLitterState()): ChildPetState {
  return makeLiveChildState({
    pet: { breed_type: 'maine_coon', species: 'cat' } as Partial<ChildPetState['pet']>,
    wand: makeWandState(),
    litter,
    grooming: makeGroomingState(),
    scratching: makeScratchingState(),
  });
}

function memoryTips(seen: string[] = []): TipStore {
  const data = new Map<string, string>([[CLEANING_TIPS_KEY, JSON.stringify(seen)]]);
  return { getItemAsync: async (k) => data.get(k) ?? null, setItemAsync: async (k, v) => void data.set(k, v) };
}

async function advance(ms: number) {
  await act(async () => {
    await jest.advanceTimersByTimeAsync(ms);
  });
}

function layout(testID: string) {
  fireEvent(screen.getByTestId(testID, { includeHiddenElements: true }), 'layout', { nativeEvent: { layout: { x: 0, y: 0, ...SIZE } } });
}

function games(): MessGames {
  const g = loadMessGames();
  if (g === null) throw new Error('game layer off');
  return g;
}

beforeEach(() => {
  jest.clearAllMocks();
  jest.useFakeTimers({ doNotFake: ['nextTick', 'setImmediate'] });
  jest.setSystemTime(T0);
  setGameLayerAvailableForTests(true);
  resetMessGamesForTests();
  setTipStoreForTests(memoryTips(['poop', 'puddle']));
  mocked.getChildPet.mockResolvedValue(catState());
  useAppStore.setState(useAppStore.getInitialState(), true);
  useAppStore.getState().signIn({ token: 't', user: { id: 2, name: 'Maja', email: null, role: 'child' }, pet: makePet(), awaitingContract: false });
});

afterEach(() => {
  setTipStoreForTests(null);
  resetMessGamesForTests();
  jest.useRealTimers();
});

describe('capability fallback', () => {
  it('an older binary gets no game (null) — the HUD keeps the old overlays', () => {
    setGameLayerAvailableForTests(false);
    resetMessGamesForTests();
    expect(loadMessGames()).toBeNull();
    setGameLayerAvailableForTests(true);
    const g = loadMessGames();
    expect(g).not.toBeNull();
    expect(typeof g?.MessGame).toBe('function');
    expect(loadMessGames()).toBe(g);
  });

  it('the cat scoop: game with the layer and clumps, the old overlay without the layer or with a clean tray', async () => {
    useAppStore.getState().openCatGame('scoop');
    const view = normalizeChildState(catState(), 0, T0);
    const { unmount } = renderWithQuery(<CatCareOverlay view={view} reduceMotion />);
    await advance(0);
    expect(screen.getByTestId('scoop-game')).toBeTruthy();
    unmount();

    const clean = normalizeChildState(catState(makeLitterState({ open_uses: [], can_scoop: false, next_due_at: null })), 0, T0);
    const second = renderWithQuery(<CatCareOverlay view={clean} reduceMotion />);
    await advance(0);
    expect(screen.getByTestId('cat-scoop-nothing')).toBeTruthy();
    second.unmount();

    setGameLayerAvailableForTests(false);
    renderWithQuery(<CatCareOverlay view={view} reduceMotion />);
    await advance(0);
    expect(screen.getByTestId('cat-scoop')).toBeTruthy();
    expect(screen.queryByTestId('scoop-game')).toBeNull();
  });
});

describe('MessGame — bag and cloth', () => {
  const stages: MessStage[] = [
    { kind: 'poop', pieces: 2, seed: 5 },
    { kind: 'puddle', pieces: 1, seed: 6 },
  ];

  it('bag every swirl, wipe the puddle → ONE onCleaned; haptics only for good moments', async () => {
    const onCleaned = jest.fn();
    const { MessGame } = games();
    renderWithQuery(<MessGame view={dogView({ easy: true })} stages={stages} reduceMotion={false} onCleaned={onCleaned} />);
    layout('mess-game-layout');
    await advance(0);
    // No × while a real mess must be cleaned.
    expect(screen.queryByTestId('mess-game-top-close')).toBeNull();
    expect(screen.getByTestId('mess-game-top-line').props.children).toBe('Tapni kakec ali gumb.');

    // Tap the first swirl (the bag jumps there), the button bags the second.
    const place = piecePlacements(2, 5);
    fireGameGesture('mess-game-field-poop', 'tap', { x: place[0].x * SIZE.width, y: place[0].y * SIZE.height });
    expect(Haptics.impactAsync).toHaveBeenCalledTimes(1);
    fireEvent.press(screen.getByTestId('mess-game-bag-button'));
    await advance(1_000);
    expect(Haptics.notificationAsync).toHaveBeenCalledTimes(1);
    expect(screen.getByTestId('mess-game-feedback-ok')).toBeTruthy();
    await advance(1_000);

    // Puddle: three screen-reader activations wipe it dry.
    expect(screen.getByTestId('mess-game-top-line').props.children).toBe('Drži gumb in briši.');
    expect(onCleaned).not.toHaveBeenCalled();
    for (let i = 0; i < 3; i++) fireEvent.press(screen.getByTestId('mess-game-wipe-button'));
    await advance(2_000);
    expect(onCleaned).toHaveBeenCalledTimes(1);
    await advance(5_000);
    expect(onCleaned).toHaveBeenCalledTimes(1);
  });

  it('a swipe through the swirls bags them (UI-thread hit-test); × only when the child opened it', async () => {
    const onCleaned = jest.fn();
    const onClose = jest.fn();
    const { MessGame } = games();
    renderWithQuery(<MessGame view={dogView()} stages={[{ kind: 'poop', pieces: 1, seed: 9 }]} reduceMotion onCleaned={onCleaned} onClose={onClose} />);
    layout('mess-game-layout');
    await advance(0);
    const [p] = piecePlacements(1, 9);
    // The bag rides LIFT pt above the finger.
    fireGameGesture('mess-game-field-poop', 'pan', { x: p.x * SIZE.width, y: p.y * SIZE.height + LIFT });
    await advance(2_500);
    expect(onCleaned).toHaveBeenCalledTimes(1);
    fireEvent.press(screen.getByTestId('mess-game-top-close'));
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('the parent switched sounds and vibration off → no haptics at all', async () => {
    const { MessGame } = games();
    renderWithQuery(<MessGame view={dogView({ easy: true, feedback: false })} stages={[{ kind: 'poop', pieces: 1, seed: 1 }]} reduceMotion onCleaned={jest.fn()} />);
    layout('mess-game-layout');
    fireEvent.press(screen.getByTestId('mess-game-bag-button'));
    await advance(2_000);
    expect(Haptics.impactAsync).not.toHaveBeenCalled();
    expect(Haptics.notificationAsync).not.toHaveBeenCalled();
  });

  it('the first clean of a kind shows its tip once, ≥ 2.5 s, at the top', async () => {
    setTipStoreForTests(memoryTips());
    const { MessGame } = games();
    const first = renderWithQuery(<MessGame view={dogView()} stages={[{ kind: 'poop', pieces: 1, seed: 1 }]} reduceMotion onCleaned={jest.fn()} />);
    await advance(0);
    expect(screen.getByTestId('mess-tip').props.accessibilityLabel).toBe('Na sprehodu vedno pobereš z vrečko.');
    await advance(2_500);
    expect(screen.getByTestId('mess-tip')).toBeTruthy();
    await advance(2_500);
    expect(screen.queryByTestId('mess-tip')).toBeNull();
    first.unmount();
    renderWithQuery(<MessGame view={dogView()} stages={[{ kind: 'poop', pieces: 1, seed: 1 }]} reduceMotion onCleaned={jest.fn()} />);
    await advance(0);
    expect(screen.queryByTestId('mess-tip')).toBeNull();
  });
});

describe('SlipperGame — box, then the toy', () => {
  it('every bit into the box, the toy to the dog → ONE onResolved', async () => {
    const onResolved = jest.fn();
    const { SlipperGame } = games();
    renderWithQuery(<SlipperGame view={dogView()} reduceMotion={false} onResolved={onResolved} onClose={jest.fn()} />);
    layout('slipper-game-field');
    await advance(0);
    expect(screen.getByTestId('slipper-game-top-line').props.children).toBe('Pospravi kose v škatlo.');
    // The slipper is dragged into the box; the flakes are tapped.
    const s = SLIPPER_ITEMS[0];
    fireGameGesture('slipper-game-field', 'pan', {
      x: s.x * SIZE.width,
      y: s.y * SIZE.height,
      translationX: (0.2 - s.x) * SIZE.width,
      translationY: (0.86 - s.y) * SIZE.height,
    });
    for (const f of SLIPPER_ITEMS.slice(1)) fireGameGesture('slipper-game-field', 'tap', { x: f.x * SIZE.width, y: f.y * SIZE.height });
    await advance(0);
    expect(screen.getByTestId('slipper-game-box-closed', { includeHiddenElements: true })).toBeTruthy();
    // The box closes (✓ 1 s), then the toy line (GameLine keeps a line ≥ 2 s).
    await advance(2_000);
    expect(screen.getByTestId('slipper-game-top-line').props.children).toBe('Daj mu igračo!');
    // A toy dropped next to the box springs back; dropped on the dog it's given.
    fireGameGesture('slipper-game-field', 'pan', { x: SLIPPER_TOY.x * SIZE.width, y: SLIPPER_TOY.y * SIZE.height, translationX: -10, translationY: 0 });
    expect(onResolved).not.toHaveBeenCalled();
    fireGameGesture('slipper-game-field', 'pan', {
      x: SLIPPER_TOY.x * SIZE.width,
      y: SLIPPER_TOY.y * SIZE.height,
      translationX: (DOG_ZONE.x - SLIPPER_TOY.x) * SIZE.width,
      translationY: (DOG_ZONE.y - SLIPPER_TOY.y) * SIZE.height,
    });
    await advance(2_000);
    expect(screen.getByTestId('slipper-game-heart', { includeHiddenElements: true })).toBeTruthy();
    expect(onResolved).toHaveBeenCalledTimes(1);
  });

  it('hold mode: »Pospravi kos« ×4, »Daj igračo«', async () => {
    const onResolved = jest.fn();
    const { SlipperGame } = games();
    renderWithQuery(<SlipperGame view={dogView({ easy: true })} reduceMotion onResolved={onResolved} onClose={jest.fn()} />);
    layout('slipper-game-field');
    for (let i = 0; i < 4; i++) fireEvent.press(screen.getByTestId('slipper-game-box-button'));
    await advance(1_000);
    fireEvent.press(screen.getByTestId('slipper-game-toy-button'));
    await advance(2_000);
    expect(onResolved).toHaveBeenCalledTimes(1);
  });
});

describe('ScoopGame — stick, shake, bag', () => {
  it('scoop + shake every clump → the sand smooths, ONE scoop request, the server result', async () => {
    let resolve: (v: unknown) => void = () => undefined;
    mocked.scoopLitter.mockReturnValue(new Promise((r) => (resolve = r)));
    const { ScoopGame } = games();
    const view = normalizeChildState(catState(), 0, T0);
    renderWithQuery(<ScoopGame view={view} reduceMotion={false} onClose={jest.fn()} />);
    layout('scoop-game-field');
    await advance(0);
    expect(screen.getByTestId('scoop-game-top-line').props.children).toBe('Povleci lopatko skozi pesek.');
    const tray = trayGeometry(SIZE.width, SIZE.height);
    const at = (i: number) => ({ x: tray.x + clumpInTray(i).x * tray.w, y: tray.y + clumpInTray(i).y * tray.h + LIFT });
    // A slow drag over a clump: it sticks to the scoop, nothing in the bag yet.
    fireGameGesture('scoop-game-field', 'pan', { ...at(0), velocityY: 0 });
    // GameLine keeps the rule line ≥ 2 s before the shake hint replaces it.
    await advance(2_000);
    expect(screen.getByTestId('scoop-game-top-line').props.children).toBe('Stresi navzgor v vrečko!');
    expect(mocked.scoopLitter).not.toHaveBeenCalled();
    // A quick flick up over the second clump picks it and shakes both into the bag.
    fireGameGesture('scoop-game-field', 'pan', { ...at(1), velocityY: -1200, translationY: -60 });
    await advance(1_000);
    expect(mocked.scoopLitter).toHaveBeenCalledTimes(1);
    expect(screen.getByTestId('scoop-game-saving')).toBeTruthy();
    await act(async () => {
      resolve({ status: 'accepted', scooped: 1, state: catState(makeLitterState({ open_uses: [], can_scoop: false, next_due_at: null })) });
      await jest.advanceTimersByTimeAsync(0);
    });
    expect(screen.getByTestId('scoop-game-result').props.children).toBe('Bravo! Pesek je čist.');
    expect(mocked.scoopLitter).toHaveBeenCalledTimes(1);
  });
});
