/**
 * GAMES_SPEC phase 1 — the ball game v2 on the native game layer, end to end through
 * `PlayOverlay` (the HUD's entry): the deck preloads every clip and the clock waits for it, the
 * chain hub → run → [call] → return → hub follows the players' ends, the ball is thrown /
 * caught through the recorded gesture worklets, one report per round, »Še en krog«, hold mode
 * buttons, picture mode on stored lengths, the 12 s deck timeout, and the parent's sound switch.
 */
import { act, fireEvent, render, screen } from '@testing-library/react-native';
import * as Haptics from 'expo-haptics';

import { normalizeChildState, type ChildPetView } from '@/modules/childPet/childPetView';
import { setGameLayerAvailableForTests } from '@/modules/gameLayer/capability';
import { DECK_READY_TIMEOUT_MS } from '@/modules/gameLayer/deck';
import { GAME_SOUNDS } from '@/modules/gameLayer/gameFxCore';
import { GAME_LINE_MAX_CHARS } from '@/modules/gameLayer/gameLine';
import { i18n } from '@/i18n';
import { normalizePetMedia } from '@/modules/petMedia/petMedia';
import PlayOverlay, { resetBallGameV2ForTests } from '@/modules/play/PlayOverlay';
import { ballAtRest } from '@/modules/play/ballV2/ballFlight';
import { CALL_WAIT_MS, CATCH_WINDOW_MS } from '@/modules/play/ballV2/ballMachine';
import { BALL_V2_STRINGS } from '@/modules/play/play';
import { makeLiveChildState, makeMedia, makePlayState, withEasyGestures } from '@/test-utils/fixtures';
import { captureAndroidBack, expectHudAboveStage, fakeAudioPlayers, fireGameGesture, resetFakeAudioPlayers } from '@/test-utils/gameLayer';
import { mockVideoPlayers, resetMockVideoPlayers, sourceUri, type MockVideoPlayer } from '@/test-utils/videoPlayers';

const URLS = {
  ref: 'https://api.petprep.si/m/ref.png?v=1',
  empty: 'https://api.petprep.si/m/empty.png?v=1',
  loop: 'https://api.petprep.si/m/ball_waiting_loop.mp4?v=1',
  waiting: 'https://api.petprep.si/m/ball_waiting.mp4?v=1',
  running: 'https://api.petprep.si/m/ball_running.mp4?v=1',
  fetching: 'https://api.petprep.si/m/ball_fetching.mp4?v=1',
};

function ballView(settings: { media_display?: 'video' | 'picture'; game_feedback?: boolean } = {}): ChildPetView {
  const base = normalizeChildState(makeLiveChildState({ play: makePlayState(), settings }), 0, Date.parse('2026-10-04T12:00:00+02:00'));
  const media = normalizePetMedia(
    makeMedia({
      status: 'ready',
      reference_image_url: URLS.ref,
      poses: { empty: URLS.empty },
      phases: { ball_waiting_loop: URLS.loop, ball_waiting: URLS.waiting, ball_running: URLS.running, ball_fetching: URLS.fetching },
      phase_groups: ['ball'],
      phase_seconds: { ball_running: 3, ball_fetching: 3 },
      ...({ phase_meta: { ball_running: { exit_side: 'right' } } } as object),
    }),
  );
  return { ...base, pet: { ...base.pet, media } };
}

const SIZE = { width: 400, height: 800 };
const ID = 'play-ball-v2';

function renderGame(view: ChildPetView, reduceMotion = false) {
  const props = { onPick: jest.fn(), onFinished: jest.fn(), onClose: jest.fn() };
  const utils = render(<PlayOverlay view={view} mode="play" reduceMotion={reduceMotion} {...props} />);
  return { ...props, ...utils };
}

function player(url: string): MockVideoPlayer {
  const found = mockVideoPlayers.find((p) => sourceUri(p) === url && !p.released);
  if (found === undefined) throw new Error(`no player for ${url}`);
  return found;
}

async function advance(ms: number) {
  await act(async () => {
    await jest.advanceTimersByTimeAsync(ms);
  });
}

/** Every deck player renders its first frame → the deck is ready, the clock starts. */
function deckReady() {
  for (let i = 0; i < 3; i += 1) fireEvent(screen.getByTestId(`${ID}-deck-player-${i}`), 'firstFrameRender');
}

function layoutField() {
  fireEvent(screen.getByTestId(`${ID}-field`, { includeHiddenElements: true }), 'layout', { nativeEvent: { layout: { x: 0, y: 0, ...SIZE } } });
}

function swipeThrow() {
  act(() => fireGameGesture(`${ID}-field`, 'pan', { y: SIZE.height * 0.8, translationY: -140, velocityY: -1_600 }));
}

function tapBall() {
  const rest = ballAtRest(SIZE);
  act(() => fireGameGesture(`${ID}-field`, 'tap', { x: rest.x, y: rest.y }));
}

/** A once clip reaches its end: the native player stops on its last frame, then reports it. */
function end(url: string) {
  const p = player(url);
  p.playing = false;
  act(() => p.emit('playToEnd', {}));
}

/** One whole throw in swipe mode: throw, the run ends, call, the return ends, catch. */
async function oneThrow({ call = true, caught = true } = {}) {
  swipeThrow();
  end(URLS.running);
  if (call) fireEvent.press(screen.getByTestId(`${ID}-call`));
  else await advance(CALL_WAIT_MS);
  end(URLS.fetching);
  if (caught) tapBall();
  else await advance(CATCH_WINDOW_MS);
}

describe('BallGameV2 (GAMES_SPEC §4.3)', () => {
  beforeEach(() => {
    jest.useFakeTimers({ doNotFake: ['nextTick', 'setImmediate'] });
    setGameLayerAvailableForTests(true);
    resetBallGameV2ForTests();
    resetMockVideoPlayers();
    resetFakeAudioPlayers();
    jest.clearAllMocks();
  });
  afterEach(() => {
    resetBallGameV2ForTests();
    jest.useRealTimers();
  });

  it('preloads every ball clip once (the derived waiting loop first) and starts only when they are ready', async () => {
    renderGame(ballView());
    expect(screen.getByTestId(ID)).toBeTruthy();
    expect(mockVideoPlayers.map(sourceUri)).toEqual([URLS.loop, URLS.running, URLS.fetching]);
    expect(mockVideoPlayers.every((p) => !p.playing && p.muted)).toBe(true);
    expect(screen.getByTestId(`${ID}-top-line`).props.children).toBe('Kuža se pripravlja …');
    // No throw before the deck is ready.
    swipeThrow();
    expect(screen.queryByText('Lep met!')).toBeNull();
    deckReady();
    expect(player(URLS.loop).playing).toBe(true);
    await advance(2_000);
    expect(screen.getByTestId(`${ID}-top-line`).props.children).toBe('Povleci in spusti žogo!');
  });

  it('a whole round: throw → run → call → return → catch, ×3, one report, »Še en krog«', async () => {
    const { onFinished } = renderGame(ballView());
    deckReady();
    layoutField();

    swipeThrow();
    expect(screen.getByText('Lep met!')).toBeTruthy();
    // Hub loop → run (crossfade): the run starts from frame 0.
    expect(player(URLS.running).playing).toBe(true);
    expect(player(URLS.running).currentTime).toBe(0);
    // Anti-spam: no second throw while the dog is out.
    swipeThrow();
    expect(screen.getByTestId(`${ID}-top-progress`).props.accessibilityLabel).toBe('Met 1 od 3, ujete žoge: 0');
    // The run ends on its last frame (the empty room) — »Pokliči!«.
    end(URLS.running);
    // Held on its last frame — never restarted while the child may call.
    await advance(500);
    expect(player(URLS.running).playing).toBe(false);
    expect(player(URLS.running).play).toHaveBeenCalledTimes(1);
    fireEvent.press(screen.getByTestId(`${ID}-call`));
    expect(screen.getByText('Pridi!')).toBeTruthy();
    expect(player(URLS.fetching).playing).toBe(true);
    end(URLS.fetching);
    // Back in the hub loop on the same frame; the ball rolls to the camera — catch it.
    expect(player(URLS.loop).playing).toBe(true);
    tapBall();
    expect(screen.getByText('Ujeta!')).toBeTruthy();
    expect(screen.getByTestId(`${ID}-top-stars`)).toBeTruthy();

    await oneThrow({ call: false });
    expect(onFinished).not.toHaveBeenCalled();
    await oneThrow({ caught: false });
    expect(onFinished).toHaveBeenCalledTimes(1);
    expect(onFinished).toHaveBeenCalledWith('play');
    expect(screen.getByTestId(`${ID}-done`)).toBeTruthy();
    expect(screen.getByText('Bravo! Kuža je ves vesel.')).toBeTruthy();
    expect(screen.getByLabelText('Ujete žoge: 2 od 3')).toBeTruthy();

    fireEvent.press(screen.getByTestId(`${ID}-again`));
    expect(screen.queryByTestId(`${ID}-done`)).toBeNull();
    expect(screen.getByTestId(`${ID}-top-progress`).props.accessibilityLabel).toBe('Met 0 od 3, ujete žoge: 0');
    await oneThrow();
    await oneThrow();
    await oneThrow();
    expect(onFinished).toHaveBeenCalledTimes(2);
  });

  it('a short or slow pull is no throw; a tap beside the rolling ball is no catch (no ✗ either)', async () => {
    renderGame(ballView());
    deckReady();
    layoutField();
    act(() => fireGameGesture(`${ID}-field`, 'pan', { y: 700, translationY: -20, velocityY: -2_000 }));
    act(() => fireGameGesture(`${ID}-field`, 'pan', { y: 700, translationY: -200, velocityY: -100 }));
    // A pull that starts in the top of the screen (not the hand's zone) neither.
    act(() => fireGameGesture(`${ID}-field`, 'pan', { y: 100, translationY: -200, velocityY: -2_000 }));
    expect(screen.queryByText('Lep met!')).toBeNull();
    swipeThrow();
    end(URLS.running);
    await advance(CALL_WAIT_MS);
    end(URLS.fetching);
    act(() => fireGameGesture(`${ID}-field`, 'tap', { x: 20, y: 20 }));
    expect(screen.queryByText('Ujeta!')).toBeNull();
    await advance(CATCH_WINDOW_MS);
    expect(screen.queryByTestId(`${ID}-feedback-miss`)).toBeNull();
  });

  it('sounds + positive haptics only — and none when the parent turned them off', async () => {
    renderGame(ballView());
    deckReady();
    layoutField();
    // One preloaded player per game sound (school cue / crunch added 2026-10-31).
    expect(fakeAudioPlayers()).toHaveLength(GAME_SOUNDS.length);
    swipeThrow();
    expect(Haptics.impactAsync).toHaveBeenCalledTimes(1);
    end(URLS.running);
    await advance(CALL_WAIT_MS);
    end(URLS.fetching);
    await advance(CATCH_WINDOW_MS);
    // The missed catch: no haptic, no error notification.
    expect(Haptics.notificationAsync).not.toHaveBeenCalled();
    expect(fakeAudioPlayers().some((p) => p.play.mock.calls.length > 0)).toBe(true);
  });

  it('game_feedback off: no sound player, no haptic', async () => {
    renderGame(ballView({ game_feedback: false }));
    deckReady();
    layoutField();
    await oneThrow();
    expect(fakeAudioPlayers()).toHaveLength(0);
    expect(Haptics.impactAsync).not.toHaveBeenCalled();
    expect(Haptics.notificationAsync).not.toHaveBeenCalled();
  });

  it('»Lažje kretnje«: »Vrzi žogo« / »Ujemi žogo« buttons, a wider catch window', async () => {
    renderGame(withEasyGestures(ballView()), true);
    deckReady();
    await advance(2_000);
    expect(screen.getByTestId(`${ID}-top-line`).props.children).toBe('Pridrži žogo in spusti!');
    // A screen reader's activate (no press-in) throws at once.
    fireEvent.press(screen.getByLabelText('Vrzi žogo'));
    expect(screen.getByText('Lep met!')).toBeTruthy();
    end(URLS.running);
    fireEvent.press(screen.getByLabelText('Pokliči kužka'));
    end(URLS.fetching);
    // Still open after the normal 1.5 s window (2.5 s in easy mode).
    await advance(CATCH_WINDOW_MS + 200);
    fireEvent.press(screen.getByLabelText('Ujemi žogo'));
    expect(screen.getByText('Ujeta!')).toBeTruthy();
  });

  it('hold to charge: press in, wait, let go = one throw', async () => {
    renderGame(withEasyGestures(ballView()));
    deckReady();
    const button = screen.getByTestId(`${ID}-throw`);
    fireEvent(button, 'pressIn');
    expect(screen.getByTestId(`${ID}-charge`)).toBeTruthy();
    await advance(600);
    fireEvent(button, 'pressOut');
    expect(screen.getByText('Lep met!')).toBeTruthy();
    expect(screen.queryByTestId(`${ID}-throw`)).toBeNull();
  });

  it('picture mode: no player at all; the game runs on the stored clip lengths', async () => {
    const { onFinished } = renderGame(ballView({ media_display: 'picture' }));
    expect(mockVideoPlayers).toHaveLength(0);
    layoutField();
    swipeThrow();
    expect(screen.queryByTestId(`${ID}-call`)).toBeNull();
    await advance(3_000);
    expect(screen.getByTestId(`${ID}-call`)).toBeTruthy();
    fireEvent.press(screen.getByTestId(`${ID}-call`));
    await advance(3_000);
    tapBall();
    expect(screen.getByText('Ujeta!')).toBeTruthy();
    expect(onFinished).not.toHaveBeenCalled();
  });

  it('a deck that is not ready in 12 s plays the game in »Slika« (players released)', async () => {
    renderGame(ballView());
    expect(mockVideoPlayers.filter((p) => !p.released)).toHaveLength(3);
    await advance(DECK_READY_TIMEOUT_MS);
    expect(mockVideoPlayers.filter((p) => !p.released)).toHaveLength(0);
    await advance(2_000);
    expect(screen.getByTestId(`${ID}-top-line`).props.children).toBe('Povleci in spusti žogo!');
  });

  // David's TestFlight build 2026-10-31: only the dog video was visible — no top bar, ball,
  // »Pokliči!«, feedback or ×. Fabric hoisted the deck's front player (zIndex 2) over them.
  it('paints the top bar, ×, ball field, feedback, »Pokliči!« and the result above the playing video', async () => {
    renderGame(ballView());
    deckReady();
    layoutField();
    expectHudAboveStage(ID, [`${ID}-top-close`, `${ID}-field`, `${ID}-feedback-layer`]);
    swipeThrow();
    end(URLS.running);
    expectHudAboveStage(ID, [`${ID}-call`, `${ID}-feedback`]);
    fireEvent.press(screen.getByTestId(`${ID}-call`));
    end(URLS.fetching);
    tapBall();
    await oneThrow();
    await oneThrow();
    expectHudAboveStage(ID, [`${ID}-done`, `${ID}-again`, `${ID}-finish`]);
  });

  it('the × is there before the deck is ready, and Android back closes the game', () => {
    const back = captureAndroidBack();
    const { onClose } = renderGame(ballView());
    expect(screen.getByTestId(`${ID}-top-close`)).toBeTruthy();
    expectHudAboveStage(ID, [`${ID}-top-close`]);
    expect(back.pressBack()).toBe(true);
    expect(onClose).toHaveBeenCalled();
    jest.restoreAllMocks();
  });

  it('closing mid-round reports nothing', () => {
    const { onFinished, onClose } = renderGame(ballView());
    deckReady();
    swipeThrow();
    fireEvent.press(screen.getByTestId(`${ID}-top-close`));
    expect(onClose).toHaveBeenCalledTimes(1);
    expect(onFinished).not.toHaveBeenCalled();
  });

  it('every game line and button text fits one line (≤ 32 characters) in sl and en', async () => {
    for (const lang of ['sl', 'en']) {
      await act(async () => {
        await i18n.changeLanguage(lang);
      });
      const S = BALL_V2_STRINGS;
      for (const text of [S.hint, S.holdHint, S.preparing, S.call, S.catch, S.again, S.done, S.fb.throw, S.fb.called, S.fb.caught]) {
        expect(Array.from(text).length).toBeLessThanOrEqual(GAME_LINE_MAX_CHARS);
      }
    }
    await act(async () => {
      await i18n.changeLanguage('sl');
    });
  });
});
