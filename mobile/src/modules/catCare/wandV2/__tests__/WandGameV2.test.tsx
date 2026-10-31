/**
 * GAMES_SPEC §5 — the wand v2 hunt through the cat game host (`CatCareOverlay`): the capability
 * fallback (no layer → the v1 minute, which never sends `rules`), the deck gating the start, the
 * `rules: 2` start, the server's schedule driving the cues, real strokes through the recorded
 * gesture worklets carrying `in_zone` / `speed_ok`, the ★ for a dodge, and the result screen with
 * the server's stars and hint. Hold mode: »Umakni!« is one clean away move.
 */
import { act, fireEvent, screen } from '@testing-library/react-native';

import { api, type ChildPetState } from '@/api/client';
import { i18n } from '@/i18n';
import CatCareOverlay from '@/modules/catCare/CatCareOverlay';
import { loadWandGameV2, resetWandGameV2ForTests } from '@/modules/catCare/wandV2/loadWandGameV2';
import { readWandV2Session, scoreWandV2, type WandV2Move, type WandV2Session } from '@/modules/catCare/wandV2/wandRules';
import { normalizeChildState, type ChildPetView } from '@/modules/childPet/childPetView';
import { setGameLayerAvailableForTests } from '@/modules/gameLayer/capability';
import { normalizePetMedia } from '@/modules/petMedia/petMedia';
import { useAppStore } from '@/store/appStore';
import { makeLiveChildState, makeMedia, makeWandState, withEasyGestures } from '@/test-utils/fixtures';
import { fireGamePan } from '@/test-utils/gameLayer';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return { ...actual, api: { ...actual.api, startWand: jest.fn(), finishWand: jest.fn(), startWandV2: jest.fn(), finishWandV2: jest.fn(), getChildPet: jest.fn() } };
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
  ends_at: '2026-10-31T11:00:26Z',
  expires_at: '2026-10-31T11:01:26Z',
  rules_version: 2,
  duration_ms: 26000,
  stalk_ms: 3000,
  pounces_ms: [7000, 13000, 19000],
  crouch_cues_ms: [5500, 11500, 17500],
  catch_at_ms: 22500,
  catch_window_ms: 1500,
  calm_window_ms: 1000,
  calm_max_moves: 0,
  dodge_window_ms: [150, 1500],
  min_move_interval_ms: 300,
  min_stars: 2,
  max_stars: 4,
  max_speed_pt_s: 4000,
  min_dodge_pt: 40,
  // v1 keys, neutral in v2
  min_away_moves: 0,
  segments: 0,
  pounce_window_ms: 0,
};

const PARSED = readWandV2Session(SESSION) as WandV2Session;

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
  for (let i = 0; i < 4; i += 1) fireEvent(screen.getByTestId(`${ID}-deck-player-${i}`), 'firstFrameRender');
}

function layoutField() {
  fireEvent(screen.getByTestId(`${ID}-field`, { includeHiddenElements: true }), 'layout', { nativeEvent: { layout: { x: 0, y: 0, ...SIZE } } });
}

/** A calm pull away inside the zone: finger from y 520 to 380 (feather 464 → 324). */
function pullAway() {
  const samples = Array.from({ length: 11 }, (_, i) => ({ x: 200, y: 520 - i * 14, velocityX: 0, velocityY: -900 }));
  fireGamePan(`${ID}-field`, samples);
}

beforeEach(async () => {
  jest.useFakeTimers({ now: T0 });
  await i18n.changeLanguage('sl');
  setGameLayerAvailableForTests(true);
  resetWandGameV2ForTests();
  useAppStore.setState({ catOverlay: null });
  for (const k of ['startWand', 'finishWand', 'startWandV2', 'finishWandV2', 'getChildPet']) mocked[k].mockReset();
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
  it('starts with rules: 2, plays the server schedule, reports in_zone / speed_ok moves, shows the server result', async () => {
    mocked.startWandV2.mockResolvedValue({ status: 'accepted', session: SESSION, state: catState() });
    open(catView());
    deckReady();
    layoutField();
    await advance(0);
    fireEvent.press(screen.getByTestId(`${ID}-start`));
    await advance(0);
    expect(mocked.startWandV2).toHaveBeenCalledTimes(1);
    expect(mocked.startWand).not.toHaveBeenCalled();

    // Calm until the pounce at 7000, then a clean pull-away at ~7400 → ★ »Ušlo!« on the top line.
    await advance(7400);
    pullAway();
    await advance(2100);
    expect(screen.getByTestId(`${ID}-top-line`).props.children).toBe('Ušlo!');
    expect(screen.getByTestId(`${ID}-top-stars`)).toBeTruthy();

    let sent: WandV2Move[] = [];
    mocked.finishWandV2.mockImplementation(async (_id: string, moves: WandV2Move[]) => {
      sent = moves;
      return { status: 'accepted', result: { session_id: SESSION.id, rules_version: 2, ...scoreWandV2(moves, PARSED) }, state: catState() };
    });
    await advance(26000 - 9500 + 200);
    expect(mocked.finishWandV2).toHaveBeenCalledTimes(1);
    expect(sent).toHaveLength(1);
    expect(sent[0]).toMatchObject({ away: true, in_zone: true, speed_ok: true });
    expect(sent[0].t).toBeGreaterThanOrEqual(7150);
    expect(sent[0].t).toBeLessThanOrEqual(8500);
    await advance(0);
    expect(screen.getByTestId(`${ID}-result-title`).props.children).toBe('Kakšen lov!');
    expect(screen.getByTestId(`${ID}-result-hint`).props.children).toBe('Ko skoči, pero hitro stran.');
  });

  it('»Lažje kretnje«: »Umakni!« is one clean away move; nothing else is reported', async () => {
    mocked.startWandV2.mockResolvedValue({ status: 'accepted', session: SESSION, state: catState() });
    mocked.finishWandV2.mockImplementation(async (_id: string, moves: WandV2Move[]) => ({
      status: 'accepted',
      result: { session_id: SESSION.id, rules_version: 2, ...scoreWandV2(moves, PARSED) },
      state: catState(),
    }));
    open(catView(true));
    deckReady();
    layoutField();
    await advance(0);
    fireEvent.press(screen.getByTestId(`${ID}-start`));
    await advance(7500);
    fireEvent.press(screen.getByTestId(`${ID}-dodge`));
    await advance(19000);
    const [, moves] = mocked.finishWandV2.mock.calls[0] as [string, WandV2Move[]];
    expect(moves).toEqual([{ t: expect.any(Number), away: true, in_zone: true, speed_ok: true }]);
  });
});
