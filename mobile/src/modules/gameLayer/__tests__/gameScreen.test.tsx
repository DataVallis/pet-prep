/**
 * GameScreen z-order + the mandatory exit (ball v2 device bug, David 2026-10-31): only the dog
 * video was visible — the front `ClipDeck` player (`zIndex: 2`) was hoisted by Fabric over the
 * top bar, the ball, the buttons and the ×, and there was no other way out. These tests run the
 * native paint rules (`fabricPaintOrder`) and every exit path: the × (own action or the screen's
 * exit), a locked × coming back after `GAME_ESCAPE_MS`, Android back (a running game only gets
 * the »tap ×« hint — David 2026-11-15, every game), the watchdog card for a
 * stage that never gets ready, and a layer that throws while rendering.
 */
import { act, fireEvent, render, screen } from '@testing-library/react-native';
import { useContext, useEffect } from 'react';
import { StyleSheet, View } from 'react-native';

import ClipDeck from '@/modules/gameLayer/ClipDeck';
import { DECK_READY_TIMEOUT_MS } from '@/modules/gameLayer/deck';
import {
  GAME_BACK_HINT_MS,
  GAME_ESCAPE_MS,
  GAME_STAGE_WATCHDOG_MS,
  GameExitProvider,
  GameScreenContext,
  closeActionOf,
  gameBack,
  gameEscape,
} from '@/modules/gameLayer/gameExit';
import GameScreen from '@/modules/gameLayer/GameScreen';
import GameTopBar from '@/modules/gameLayer/GameTopBar';
import { fabricPaintedTestIDs } from '@/test-utils/fabricPaintOrder';
import { captureAndroidBack, expectHudAboveStage } from '@/test-utils/gameLayer';
import { resetMockVideoPlayers } from '@/test-utils/videoPlayers';

const CLIPS = [
  { role: 'hub', url: 'https://api.petprep.si/m/a.mp4?v=1', loop: true, startStill: null, picture: null },
  { role: 'run', url: 'https://api.petprep.si/m/b.mp4?v=1', loop: false, startStill: null, picture: null },
];

/** A video stage that registers with the screen and then hangs (never reports ready). */
function HungStage() {
  const { stageLoading } = useContext(GameScreenContext);
  useEffect(() => stageLoading(), [stageLoading]);
  return <View testID="g-deck" />;
}

function Game({
  onExit,
  onClose,
  closeLocked = false,
  current = { role: 'hub', instance: 1, lastSwitch: null },
  dock = true,
  hung = false,
  card = false,
  line = null,
  Broken,
}: {
  onExit?: () => void;
  onClose?: () => void;
  closeLocked?: boolean;
  current?: { role: string; instance: number; lastSwitch: null } | null;
  dock?: boolean;
  hung?: boolean;
  /** A card over the game (intro / result): the game is not running. */
  card?: boolean;
  line?: string | null;
  Broken?: () => never;
}) {
  return (
    <GameScreen
      testID="g"
      onExit={onExit}
      stage={hung ? <HungStage /> : <ClipDeck clips={CLIPS} current={current} mode="video" onReady={jest.fn()} testID="g-deck" />}
      field={<View style={StyleSheet.absoluteFill} testID="g-field">{Broken !== undefined && <Broken />}</View>}
      topBar={<GameTopBar dots={[false]} stars={0} line={line} closeLabel="Zapri" progressLabel="" onClose={onClose} closeLocked={closeLocked} testID="g-top" />}
      dock={dock ? <View testID="g-button" /> : null}
      overlay={card ? <View testID="g-card" /> : null}
    />
  );
}

function deckReady() {
  for (let i = 0; i < CLIPS.length; i += 1) fireEvent(screen.getByTestId(`g-deck-player-${i}`), 'firstFrameRender');
}

async function advance(ms: number) {
  await act(async () => {
    await jest.advanceTimersByTimeAsync(ms);
  });
}

describe('gameExit (pure)', () => {
  const base = { stage: 'ready' as const, stalled: false, crashed: false, closeLocked: false, lockExpired: false };
  it('the card only for a stalled loading stage or a crash; a locked × opens after its timeout', () => {
    expect(gameEscape(base)).toEqual({ card: false, exitOpen: true });
    expect(gameEscape({ ...base, closeLocked: true })).toEqual({ card: false, exitOpen: false });
    expect(gameEscape({ ...base, closeLocked: true, lockExpired: true })).toEqual({ card: false, exitOpen: true });
    expect(gameEscape({ ...base, stage: 'loading', stalled: true, closeLocked: true })).toEqual({ card: true, exitOpen: true });
    expect(gameEscape({ ...base, stage: 'ready', stalled: true })).toEqual({ card: false, exitOpen: true });
    expect(gameEscape({ ...base, crashed: true, closeLocked: true })).toEqual({ card: true, exitOpen: true });
  });
  it('the × does its own action, else the screen exit', () => {
    const own = jest.fn();
    const exit = jest.fn();
    expect(closeActionOf(own, exit)).toBe(own);
    expect(closeActionOf(undefined, exit)).toBe(exit);
    expect(closeActionOf(undefined, null)).toBeNull();
  });
});

describe('gameBack (pure)', () => {
  const running = { card: false, exitOpen: true, hasBar: true, closeLocked: false, covered: false };
  it('a running game only gets the hint; a card, the escape card or a bar-less screen exit; a locked × swallows', () => {
    expect(gameBack(running)).toBe('hint');
    expect(gameBack({ ...running, covered: true })).toBe('exit');
    expect(gameBack({ ...running, card: true })).toBe('exit');
    expect(gameBack({ ...running, hasBar: false })).toBe('exit');
    expect(gameBack({ ...running, closeLocked: true, exitOpen: false })).toBe('none');
    expect(gameBack({ ...running, closeLocked: true, covered: true, exitOpen: false })).toBe('none');
    // The × came back after GAME_ESCAPE_MS: back leads out like the ×.
    expect(gameBack({ ...running, closeLocked: true, exitOpen: true })).toBe('exit');
    expect(gameBack({ ...running, closeLocked: true, card: true })).toBe('exit');
  });
});

describe('GameScreen', () => {
  beforeEach(() => {
    jest.useFakeTimers({ doNotFake: ['nextTick', 'setImmediate'] });
    resetMockVideoPlayers();
  });
  afterEach(() => {
    jest.useRealTimers();
    jest.restoreAllMocks();
  });

  it('paints the field, top bar, × and dock above the playing video (Fabric zIndex hoisting)', () => {
    render(<Game onExit={jest.fn()} />);
    deckReady();
    const order = fabricPaintedTestIDs(screen.getByTestId('g', { includeHiddenElements: true }));
    // The front player keeps its zIndex 2 — inside the deck's own stacking context.
    expect(order).toContain('g-deck-player-0-front');
    expect(order.indexOf('g-deck-player-0-front')).toBeLessThan(order.indexOf('g-top-close'));
    expectHudAboveStage('g', ['g-field', 'g-top-close', 'g-button', 'g-dock-layer', 'g-feedback-layer']);
  });

  it('the × without its own onClose uses the screen exit — from the prop or the host context', () => {
    const exit = jest.fn();
    const { unmount } = render(<Game onExit={exit} />);
    fireEvent.press(screen.getByTestId('g-top-close'));
    expect(exit).toHaveBeenCalledTimes(1);
    unmount();
    const host = jest.fn();
    render(
      <GameExitProvider value={host}>
        <Game />
      </GameExitProvider>,
    );
    fireEvent.press(screen.getByTestId('g-top-close'));
    expect(host).toHaveBeenCalledTimes(1);
  });

  // David 2026-11-15 (»1 ja«): an edge swipe mid-game is Android's back GESTURE — in EVERY game
  // of the layer it only shows the hint; the × stays the way out.
  it('Android back in a running game: only the »tap ×« hint for GAME_BACK_HINT_MS, the × still stops', async () => {
    const back = captureAndroidBack();
    const exit = jest.fn();
    const stop = jest.fn();
    render(<Game onExit={exit} onClose={stop} line="Vrzi žogo!" />);
    expect(back.pressBack()).toBe(true);
    expect(stop).not.toHaveBeenCalled();
    expect(exit).not.toHaveBeenCalled();
    expect(screen.getByTestId('g-top-line').props.children).toBe('Za konec igre tapni ×.');
    // A second back restarts the hint's time.
    await advance(GAME_BACK_HINT_MS - 1_000);
    expect(back.pressBack()).toBe(true);
    await advance(GAME_BACK_HINT_MS - 1);
    expect(screen.getByTestId('g-top-line').props.children).toBe('Za konec igre tapni ×.');
    await advance(1);
    expect(screen.getByTestId('g-top-line').props.children).toBe('Vrzi žogo!');
    expect(stop).not.toHaveBeenCalled();
    fireEvent.press(screen.getByTestId('g-top-close'));
    expect(stop).toHaveBeenCalledTimes(1);
    expect(exit).not.toHaveBeenCalled();
  });

  it('Android back with a card over the game (intro / result) = the ×; the hint never stays under a card', async () => {
    const back = captureAndroidBack();
    const exit = jest.fn();
    const { rerender } = render(<Game onExit={exit} line="Vrzi žogo!" />);
    expect(back.pressBack()).toBe(true);
    expect(screen.getByTestId('g-top-line').props.children).toBe('Za konec igre tapni ×.');
    rerender(<Game onExit={exit} line="Vrzi žogo!" card />);
    expect(screen.getByTestId('g-top-line').props.children).toBe('Vrzi žogo!');
    expect(exit).not.toHaveBeenCalled();
    expect(back.pressBack()).toBe(true);
    expect(exit).toHaveBeenCalledTimes(1);
    expect(screen.getByTestId('g-top-line').props.children).toBe('Vrzi žogo!');
  });

  it('Android back does nothing while the × is locked (no hint either) until GAME_ESCAPE_MS; then it is the ×', async () => {
    const back = captureAndroidBack();
    const exit = jest.fn();
    const stop = jest.fn();
    render(<Game onExit={exit} onClose={stop} closeLocked />);
    expect(screen.queryByTestId('g-top-close')).toBeNull();
    expect(back.pressBack()).toBe(true);
    expect(stop).not.toHaveBeenCalled();
    expect(screen.getByTestId('g-top-line').props.children).toBe('');
    await advance(GAME_ESCAPE_MS - 1);
    expect(screen.queryByTestId('g-top-close')).toBeNull();
    await advance(1);
    fireEvent.press(screen.getByTestId('g-top-close'));
    expect(stop).toHaveBeenCalledTimes(1);
    expect(back.pressBack()).toBe(true);
    expect(stop).toHaveBeenCalledTimes(2);
  });

  it('Android back on the escape card leads out', async () => {
    const back = captureAndroidBack();
    const exit = jest.fn();
    const stop = jest.fn();
    render(<Game onExit={exit} onClose={stop} hung />);
    await advance(GAME_STAGE_WATCHDOG_MS);
    expect(screen.getByTestId('g-escape')).toBeTruthy();
    expect(back.pressBack()).toBe(true);
    expect(exit).toHaveBeenCalledTimes(1);
    expect(stop).not.toHaveBeenCalled();
  });

  it('a video stage not ready after the watchdog opens the escape card (× + »Nazaj«) on top', async () => {
    const exit = jest.fn();
    render(<Game onExit={exit} onClose={jest.fn()} closeLocked hung />);
    await advance(GAME_STAGE_WATCHDOG_MS - 1);
    expect(screen.queryByTestId('g-escape')).toBeNull();
    await advance(1);
    expect(screen.getByText('Igra se ne odziva.')).toBeTruthy();
    const order = fabricPaintedTestIDs(screen.getByTestId('g', { includeHiddenElements: true }));
    expect(order[order.length - 1]).toBe('g-escape-back');
    fireEvent.press(screen.getByTestId('g-escape-back'));
    fireEvent.press(screen.getByTestId('g-escape-close'));
    expect(exit).toHaveBeenCalledTimes(2);
  });

  it('a ready stage never opens the card; a deck that times out reports picture mode (ready) first', async () => {
    const { unmount } = render(<Game onExit={jest.fn()} />);
    deckReady();
    await advance(GAME_STAGE_WATCHDOG_MS * 2);
    expect(screen.queryByTestId('g-escape')).toBeNull();
    unmount();
    expect(DECK_READY_TIMEOUT_MS).toBeLessThan(GAME_STAGE_WATCHDOG_MS);
    render(<Game onExit={jest.fn()} current={null} />);
    await advance(GAME_STAGE_WATCHDOG_MS * 2);
    expect(screen.queryByTestId('g-escape')).toBeNull();
  });

  it('a layer that throws while rendering keeps the screen and opens the escape card', () => {
    jest.spyOn(console, 'error').mockImplementation(() => undefined);
    const exit = jest.fn();
    const Broken = (): never => {
      throw new Error('boom');
    };
    render(<Game onExit={exit} Broken={Broken} />);
    expect(screen.getByTestId('g-deck')).toBeTruthy();
    expect(screen.getByTestId('g-top-close')).toBeTruthy();
    fireEvent.press(screen.getByTestId('g-escape-back'));
    expect(exit).toHaveBeenCalledTimes(1);
  });
});
