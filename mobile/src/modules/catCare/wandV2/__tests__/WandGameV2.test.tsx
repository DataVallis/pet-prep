/**
 * GAMES_SPEC §5 — the simple wand hunt (David 2026-11-08) through the cat game host
 * (`CatCareOverlay`): the capability fallback (no layer → the v1 minute, which never sends
 * `rules`), the deck gating the start, the `rules: 3` start, the server's numbers driving the 👀
 * cues, letting go (a resting finger lifted / a drag ended, via the recorded gesture worklets)
 * during a cue = a catch with ★, three catches = the finish (one call) and the result screen with
 * the learning line. Too early / never = nothing. Hold mode: »Pusti!« is the let-go.
 */
import { act, fireEvent, screen } from '@testing-library/react-native';

import { api, ApiError, type ChildPetState } from '@/api/client';
import { i18n } from '@/i18n';
import CatCareOverlay from '@/modules/catCare/CatCareOverlay';
import { loadWandGameV2, resetWandGameV2ForTests } from '@/modules/catCare/wandV2/loadWandGameV2';
import { readWandHuntSession, scoreWandHunt, type WandHuntSession } from '@/modules/catCare/wandV2/wandRules';
import { normalizeChildState, type ChildPetView } from '@/modules/childPet/childPetView';
import { setGameLayerAvailableForTests } from '@/modules/gameLayer/capability';
import { normalizePetMedia } from '@/modules/petMedia/petMedia';
import { useAppStore } from '@/store/appStore';
import { makeLiveChildState, makeMedia, makeWandState, withEasyGestures } from '@/test-utils/fixtures';
import { captureAndroidBack, expectHudAboveStage, fireGamePan, fireGameRelease } from '@/test-utils/gameLayer';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, startWand: jest.fn(), finishWand: jest.fn(), startWandHunt: jest.fn(), finishWandHunt: jest.fn(), getChildPet: jest.fn() } };
});

const mocked = api as unknown as Record<string, jest.Mock>;
const T0 = Date.parse('2026-10-31T12:00:00+01:00');
const clock = { mono: () => Date.now(), wall: () => Date.now() };
const ID = 'cat-wand-v2';
const SIZE = { width: 400, height: 800 };

const URLS = {
  ref: 'https://api.petprep.si/m/ref.png',
  crouch: 'https://api.petprep.si/m/crouch.png',
  catch: 'https://api.petprep.si/m/catch.png',
  stalking: 'https://api.petprep.si/m/wand_stalking.mp4',
  loop: 'https://api.petprep.si/m/wand_crouch_loop.mp4',
  pouncing: 'https://api.petprep.si/m/wand_pouncing.mp4',
  catching: 'https://api.petprep.si/m/wand_catching.mp4',
};

const SESSION = {
  id: '9b1f0c1e-4c1a-4b8e-9d1e-0f2a3b4c5d6e',
  started_at: '2026-10-31T11:00:00Z',
  ends_at: '2026-10-31T11:00:50Z',
  expires_at: '2026-10-31T11:01:50Z',
  rules_version: 3,
  duration_ms: 50500,
  stalk_ms: 3000,
  first_cue_ms: 4500,
  cue_window_ms: 2000,
  after_catch_ms: 6000,
  after_miss_ms: 2500,
  max_cues: 6,
  catches_needed: 3,
  catch_clip_ms: 3500,
  // v1 keys, neutral in the simple hunt
  catch_at_ms: 50500,
  pounces_ms: [],
  min_away_moves: 0,
  segments: 0,
  min_move_interval_ms: 0,
  pounce_window_ms: 0,
};

const PARSED = readWandHuntSession(SESSION) as WandHuntSession;

function catState(): ChildPetState {
  return makeLiveChildState({ pet: { breed_type: 'maine_coon', species: 'cat' } as Partial<ChildPetState['pet']>, wand: makeWandState() });
}

function catView(easy = false): ChildPetView {
  const raw = catState();
  const base = normalizeChildState(easy ? withEasyGestures(raw) : raw, 0, T0);
  const media = normalizePetMedia(
    makeMedia({
      status: 'ready',
      reference_image_url: URLS.ref,
      poses: { crouch: URLS.crouch, catch: URLS.catch },
      phases: { wand_stalking: URLS.stalking, wand_crouch_loop: URLS.loop, wand_pouncing: URLS.pouncing, wand_catching: URLS.catching },
      phase_groups: ['wand'],
      phase_seconds: { wand_stalking: 3, wand_crouch_loop: 2, wand_pouncing: 3, wand_catching: 3 },
    }),
  );
  return { ...base, pet: { ...base.pet, media } };
}

async function advance(ms: number) {
  await act(async () => {
    await jest.advanceTimersByTimeAsync(ms);
  });
}

function open(view: ChildPetView) {
  useAppStore.getState().openCatGame('wand');
  return renderWithQuery(<CatCareOverlay view={view} reduceMotion={false} clock={clock} />);
}

function deckReady() {
  for (let i = 0; i < 3; i += 1) fireEvent(screen.getByTestId(`${ID}-deck-player-${i}`), 'firstFrameRender');
}

function layoutField() {
  fireEvent(screen.getByTestId(`${ID}-field`, { includeHiddenElements: true }), 'layout', { nativeEvent: { layout: { x: 0, y: 0, ...SIZE } } });
}

/** A drag of the feather that ends with the finger lifted (the lift = a let-go). */
function dragAndLift() {
  const samples = Array.from({ length: 11 }, (_, i) => ({ x: 200, y: 520 - i * 14, velocityX: 0, velocityY: -900 }));
  act(() => fireGamePan(`${ID}-field`, samples));
}

/** A finger resting on the feather lifts without moving (no pan end, no tap — only the finalize). */
function liftResting() {
  act(() => fireGameRelease(`${ID}-field`, { x: 200, y: 400 }));
}

function answerFinish() {
  mocked.finishWandHunt.mockImplementation(async (_id: string, releases: number[]) => ({
    status: 'accepted',
    result: { session_id: SESSION.id, ...scoreWandHunt(releases, PARSED) },
    state: catState(),
  }));
}

beforeEach(async () => {
  jest.useFakeTimers({ now: T0 });
  await i18n.changeLanguage('sl');
  setGameLayerAvailableForTests(true);
  resetWandGameV2ForTests();
  useAppStore.setState({ catOverlay: null });
  for (const k of ['startWand', 'finishWand', 'startWandHunt', 'finishWandHunt', 'getChildPet']) mocked[k].mockReset();
});

afterEach(() => {
  setGameLayerAvailableForTests(false);
  resetWandGameV2ForTests();
  jest.useRealTimers();
});

describe('capability fallback', () => {
  it('an older binary (no game layer) keeps the v1 wand overlay', () => {
    setGameLayerAvailableForTests(false);
    resetWandGameV2ForTests();
    expect(loadWandGameV2()).toBeNull();
    open(catView());
    expect(screen.getByTestId('cat-wand')).toBeTruthy();
    expect(screen.queryByTestId(ID)).toBeNull();
  });

  it('a game-layer binary opens the v2 hunt; »Začni lov« waits for the deck', async () => {
    open(catView());
    expect(screen.getByTestId(ID)).toBeTruthy();
    expect(screen.getByTestId(`${ID}-start`).props.accessibilityState?.disabled).toBe(true);
    deckReady();
    await advance(0);
    expect(screen.getByTestId(`${ID}-start`).props.accessibilityState?.disabled).toBe(false);
  });
});

describe('the hunt', () => {
  async function started(view = catView()) {
    mocked.startWandHunt.mockResolvedValue({ status: 'accepted', session: SESSION, state: catState() });
    answerFinish();
    open(view);
    deckReady();
    layoutField();
    await advance(0);
    fireEvent.press(screen.getByTestId(`${ID}-start`));
    await advance(0);
  }

  it('starts with rules: 3; letting go during the 👀 cue = a catch (★ »Ujela ga je!«); three catches = one finish and the result', async () => {
    await started();
    expect(mocked.startWandHunt).toHaveBeenCalledTimes(1);
    expect(mocked.startWand).not.toHaveBeenCalled();

    // The cue at 4500: the top line says what to do.
    await advance(4_600);
    expect(screen.getByTestId(`${ID}-top-line`).props.children).toBe('Spusti pero!');
    // A finger that rested on the feather lifts at ~5300 → the cat catches it; ★ when the clip played.
    await advance(700);
    liftResting();
    await advance(3_700);
    expect(screen.getByTestId(`${ID}-top-line`).props.children).toBe('Ujela ga je!');
    expect(screen.getByTestId(`${ID}-top-stars`)).toBeTruthy();

    // Cue 2 at ~11300: a drag that ends (the finger lifts) inside the window.
    await advance(11_400 - 9_000);
    dragAndLift();
    // Cue 3 at ~17400.
    await advance(17_500 - 11_400);
    liftResting();
    expect(mocked.finishWandHunt).not.toHaveBeenCalled();
    await advance(3_700);
    expect(mocked.finishWandHunt).toHaveBeenCalledTimes(1);
    const [, sent] = mocked.finishWandHunt.mock.calls[0] as [string, number[]];
    expect(sent).toHaveLength(3);
    expect(scoreWandHunt(sent, PARSED)).toMatchObject({ success: true, stars: 3 });
    await advance(0);
    expect(screen.getByTestId(`${ID}-result-title`).props.children).toBe('Kakšen lov!');
    expect(screen.getByTestId(`${ID}-result-learn`).props.children).toBe('Muca mora plen na koncu ujeti – tako je lov zanjo srečen.');
    expect(screen.queryByTestId(`${ID}-result-hint`)).toBeNull();
  });

  it('too early or never: no ★, no fault — the next cue comes; the hunt ends after the last cue with the hint', async () => {
    await started();
    // Let go before the first cue: nothing happens.
    await advance(2_000);
    liftResting();
    await advance(5_000);
    expect(screen.queryByTestId(`${ID}-top-stars`)).toBeNull();
    // Never again: six cues pass (the last window ends at 29000), then one finish.
    await advance(29_000 - 7_000 + 200);
    expect(mocked.finishWandHunt).toHaveBeenCalledTimes(1);
    const [, sent] = mocked.finishWandHunt.mock.calls[0] as [string, number[]];
    expect(scoreWandHunt(sent, PARSED)).toMatchObject({ success: false, stars: 0 });
    await advance(0);
    expect(screen.getByTestId(`${ID}-result-title`).props.children).toBe('Še en lov?');
    expect(screen.getByTestId(`${ID}-result-hint`).props.children).toBe('Ko se pritaji 👀, spusti pero.');
  });

  it('»Lažje kretnje«: »Pusti!« is the let-go', async () => {
    await started(catView(true));
    await advance(5_000);
    fireEvent.press(screen.getByTestId(`${ID}-let-go`));
    await advance(6_600);
    fireEvent.press(screen.getByTestId(`${ID}-let-go`));
    await advance(6_600);
    fireEvent.press(screen.getByTestId(`${ID}-let-go`));
    await advance(3_700);
    expect(mocked.finishWandHunt).toHaveBeenCalledTimes(1);
    const [, sent] = mocked.finishWandHunt.mock.calls[0] as [string, number[]];
    expect(scoreWandHunt(sent, PARSED)).toMatchObject({ success: true, stars: 3 });
  });
});

/**
 * Device crash (David 2026-11-02, iPhone, store 3.1.10 (8)): the whole app closed ~1 s after the
 * wand card opened (»Muca se pripravlja …«). Cause: the play-zone ring's `useDerivedValue` called
 * `alpha()` — not a worklet — on the UI runtime; react-native-worklets reports that as a FATAL
 * error (no error boundary sees it). The reanimated mock now fails any UI worklet that captures a
 * non-worklet function (`assertUiWorklet`), so these renders reproduce the crash in Jest.
 */
describe('device crash 2026-11-02: no UI-thread worklet calls a non-worklet function', () => {
  const { assertUiWorklet } = jest.requireMock<{ assertUiWorklet: (fn: unknown, hook: string) => void }>('react-native-reanimated');

  it('the guard rejects a UI worklet that captures a plain helper and accepts worklet helpers', () => {
    const plain = (n: number) => n * 2;
    const helper = (n: number) => {
      'worklet';
      return n + 1;
    };
    const bad = () => {
      'worklet';
      return plain(1);
    };
    const indirect = (n: number) => {
      'worklet';
      return plain(n);
    };
    const viaWorklet = () => {
      'worklet';
      return indirect(1);
    };
    const good = () => {
      'worklet';
      return helper(1);
    };
    expect(() => assertUiWorklet(bad, 'useDerivedValue')).toThrow(/non-worklet function "plain"/);
    expect(() => assertUiWorklet(viaWorklet, 'useDerivedValue')).toThrow(/non-worklet function "plain"/);
    expect(() => assertUiWorklet(good, 'useDerivedValue')).not.toThrow();
  });

  function mediaView(media: Parameters<typeof makeMedia>[0], settings?: Record<string, unknown>): ChildPetView {
    const raw = catState();
    const base = normalizeChildState(settings === undefined ? raw : { ...raw, settings: { ...(raw.settings ?? {}), ...settings } } as ChildPetState, 0, T0);
    return { ...base, pet: { ...base.pet, media: normalizePetMedia(makeMedia(media)) } };
  }

  const FULL = {
    status: 'ready' as const,
    reference_image_url: URLS.ref,
    poses: { crouch: URLS.crouch, catch: URLS.catch },
    phases: { wand_stalking: URLS.stalking, wand_crouch_loop: URLS.loop, wand_pouncing: URLS.pouncing, wand_catching: URLS.catching },
    phase_groups: ['wand' as const],
    phase_seconds: { wand_stalking: 3, wand_crouch_loop: 2, wand_pouncing: 3, wand_catching: 3 },
  };

  const CASES: [string, ChildPetView][] = [
    ['phase group present', mediaView(FULL)],
    // Simple mode (backend PR #547): the group is dropped → the state-loop deck.
    ['phase group absent (simple mode)', mediaView({ ...FULL, phase_groups: [] })],
    ['clips partly missing (no crouch loop, no pounce)', mediaView({ ...FULL, phases: { wand_stalking: URLS.stalking, wand_catching: URLS.catching } })],
    ['no clips and no poses at all', mediaView({ status: 'ready', reference_image_url: URLS.ref, phase_groups: ['wand'] })],
    ['picture display mode', mediaView(FULL, { media_display: 'picture' })],
  ];

  function readyEveryPlayer() {
    for (const node of screen.queryAllByTestId(new RegExp(`^${ID}-deck-player-\\d+$`))) fireEvent(node, 'firstFrameRender');
  }

  it.each(CASES)('%s: the card, the deck wait and a full hunt run without a crash', async (_name, view) => {
    mocked.startWandHunt.mockResolvedValue({ status: 'accepted', session: SESSION, state: catState() });
    answerFinish();
    open(view);
    layoutField();
    // »Muca se pripravlja …« — the moment the device crashed.
    await advance(1_000);
    expect(screen.queryByTestId(`${ID}-escape`)).toBeNull();
    readyEveryPlayer();
    await advance(12_000);
    expect(screen.getByTestId(`${ID}-start`).props.accessibilityState?.disabled).toBe(false);
    fireEvent.press(screen.getByTestId(`${ID}-start`));
    await advance(5_000);
    liftResting();
    await advance(30_000);
    expect(mocked.finishWandHunt).toHaveBeenCalledTimes(1);
    expect(screen.getByTestId(`${ID}-result-title`)).toBeTruthy();
    expect(screen.queryByTestId(`${ID}-escape`)).toBeNull();
  });

  it('a start refusal (422 media_not_ready) shows a friendly card, never a crash', async () => {
    mocked.startWandHunt.mockRejectedValue(new ApiError('refused', 422, { reason: 'media_not_ready' }));
    open(catView());
    layoutField();
    deckReady();
    await advance(0);
    fireEvent.press(screen.getByTestId(`${ID}-start`));
    await advance(0);
    expect(mocked.finishWandHunt).not.toHaveBeenCalled();
    expect(screen.queryByTestId(`${ID}-escape`)).toBeNull();
    expect(screen.queryByTestId(`${ID}-failed-text`) ?? screen.queryByTestId(`${ID}-intro`)).toBeTruthy();
  });
});

// Ball v2 device bug (David 2026-10-31): the HUD must sit above the cat video, with a way out.
describe('z-order and exit', () => {
  it('paints the top bar, ×, field, cue and »Začni lov« above the video; × and Android back close', async () => {
    const back = captureAndroidBack();
    useAppStore.getState().openCatGame('wand');
    renderWithQuery(<CatCareOverlay view={catView()} reduceMotion={false} clock={clock} />);
    deckReady();
    layoutField();
    await advance(0);
    expectHudAboveStage(ID, [`${ID}-top-close`, `${ID}-field-layer`, `${ID}-cue`, `${ID}-start`]);
    expect(back.pressBack()).toBe(true);
    expect(useAppStore.getState().catOverlay).toBeNull();
    jest.restoreAllMocks();
  });

  it('while running the × and Android back stop the hunt (no dead end)', async () => {
    const back = captureAndroidBack();
    mocked.startWandHunt.mockResolvedValue({ status: 'accepted', session: SESSION, state: catState() });
    open(catView());
    deckReady();
    layoutField();
    await advance(0);
    fireEvent.press(screen.getByTestId(`${ID}-start`));
    await advance(1_000);
    expect(screen.getByTestId(`${ID}-top-close`)).toBeTruthy();
    expectHudAboveStage(ID, [`${ID}-top-close`, `${ID}-field-layer`]);
    expect(screen.queryByTestId(`${ID}-start`)).toBeNull();
    // Back = the × while running = »stop«: the hunt goes back to its intro (nothing reported).
    expect(back.pressBack()).toBe(true);
    await advance(0);
    expect(screen.getByTestId(`${ID}-start`)).toBeTruthy();
    expect(mocked.finishWandHunt).not.toHaveBeenCalled();
    jest.restoreAllMocks();
  });
});
