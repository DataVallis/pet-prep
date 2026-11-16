/**
 * The game layer's z-order and its mandatory way out (ball v2 device bug, David 2026-10-31).
 *
 * PURE (imports only React): safe to import from anything an older binary evaluates — the
 * overlay hosts (`TrainingOverlay`, `ChoreOverlay`, …) provide `GameExitContext` around a game
 * they loaded lazily.
 *
 * - `GAME_Z`: every `GameScreen` layer is its own stacking context with this zIndex. Fabric
 *   hoists the children of a view that is not a stacking context into the nearest one and sorts
 *   them by zIndex, so a zIndex inside the stage (the video players) must never be able to climb
 *   over the HUD (`test-utils/fabricPaintOrder.ts` mirrors the native rule in Jest).
 * - Exit: every game screen always has a way out — the × in `GameTopBar` (its own `onClose`, else
 *   the screen's exit), Android back, and the escape card. The top bar is painted ABOVE the
 *   `overlay` card (`GAME_Z.top` > `GAME_Z.overlay`), so the × works on every intro / result /
 *   refusal card too (iOS has no back — wand cooldown dead end, David 2026-11-16). A game may lock the × for a while
 *   (`closeLocked`: a mess that must be cleaned, a request in flight); after `GAME_ESCAPE_MS` the
 *   × comes back anyway. A video stage that is not ready after `GAME_STAGE_WATCHDOG_MS`, or a
 *   layer that crashed while rendering, opens the escape card (× + »Nazaj«).
 * - Android back never ends a RUNNING game (David 2026-11-15, every game of the layer): while the
 *   × is there and no card (`GameScreen overlay`: intro / result) is up, back only shows the
 *   »tap × to stop« hint on the game line for `GAME_BACK_HINT_MS` (`gameBack`).
 */

import { createContext } from 'react';

export type GameExit = () => void;

/** zIndex of the GameScreen layers (each layer is its own stacking context). */
export const GAME_Z = {
  stage: 0,
  shade: 1,
  field: 2,
  feedback: 3,
  dock: 10,
  overlay: 20,
  /**
   * ABOVE the overlay (David's iPhone 2026-11-16): an intro / result / refusal card's backdrop
   * covered the whole screen, the × under it got no touch, and with »Začni lov« disabled (the cat
   * resting) the child had to kill the app — iOS has no back. The bar is a thin strip at the top;
   * the cards are centred under it.
   */
  top: 30,
  escape: 100,
} as const;

/** The deck gives up at 12 s (`DECK_READY_TIMEOUT_MS` → picture mode); 3 s margin on top. */
export const GAME_STAGE_WATCHDOG_MS = 15_000;
/** A locked × (forced mess, saving, starting / finishing) comes back after this long. */
export const GAME_ESCAPE_MS = 20_000;

/**
 * How long the »tap × to stop« hint replaces the game line after an Android back in a running
 * game — twice the line's minimum (2 s), long enough to read while the hand keeps playing.
 */
export const GAME_BACK_HINT_MS = 4_000;

/** The host's exit for a game it mounted (the overlay's close). */
export const GameExitContext = createContext<GameExit | null>(null);
export const GameExitProvider = GameExitContext.Provider;

export type StageStatus = 'none' | 'loading' | 'ready';

/** What GameScreen shares with its top bar and stage. */
export interface GameScreenLink {
  /** The screen's exit (`GameScreen onExit` ?? `GameExitContext`), null when none. */
  exit: GameExit | null;
  /** A locked × is allowed again (escape timeout / watchdog / crash). */
  exitOpen: boolean;
  /** The »tap × to stop« hint to show INSTEAD of the game line (Android back in a running game), else null. */
  backHint: string | null;
  /** The top bar reports its × (action + lock) so Android back knows it. */
  reportClose: (action: GameExit | null, locked: boolean) => void;
  /** A video stage (ClipDeck) mounted / became ready (either mode). */
  stageLoading: () => void;
  stageReady: () => void;
}

const noop = () => undefined;
export const DETACHED_SCREEN: GameScreenLink = { exit: null, exitOpen: false, backHint: null, reportClose: noop, stageLoading: noop, stageReady: noop };
export const GameScreenContext = createContext<GameScreenLink>(DETACHED_SCREEN);

export interface EscapeInput {
  stage: StageStatus;
  /** The stage stayed `loading` for GAME_STAGE_WATCHDOG_MS. */
  stalled: boolean;
  /** A layer threw while rendering. */
  crashed: boolean;
  closeLocked: boolean;
  /** The × stayed locked for GAME_ESCAPE_MS. */
  lockExpired: boolean;
}

export interface EscapeState {
  /** Show the escape card (× + »Nazaj«) over everything. */
  card: boolean;
  /** The ×  / Android back may close now. */
  exitOpen: boolean;
}

export function gameEscape({ stage, stalled, crashed, closeLocked, lockExpired }: EscapeInput): EscapeState {
  const card = crashed || (stage === 'loading' && stalled);
  return { card, exitOpen: card || !closeLocked || lockExpired };
}

/** The ×'s action: the top bar's own `onClose`, else the screen's exit. */
export function closeActionOf(own: GameExit | undefined, screenExit: GameExit | null): GameExit | null {
  return own ?? screenExit;
}

export interface BackInput {
  /** The escape card is up. */
  card: boolean;
  /** `gameEscape().exitOpen`. */
  exitOpen: boolean;
  /** A top bar reported its × (the hint needs its line). */
  hasBar: boolean;
  closeLocked: boolean;
  /** A card covers the game (`GameScreen overlay`: intro, result, failed, saving). */
  covered: boolean;
}

/**
 * What Android back does on a game screen (GAMES_SPEC §7.1, David 2026-11-15 — an edge swipe
 * mid-game is Android's back GESTURE and must never throw a running game away):
 * - `hint`: a running game with its × on screen → only the »tap × to stop« hint;
 * - `exit`: the escape card, a card over the game (intro / result), a × that came back after
 *   `GAME_ESCAPE_MS`, or a screen without a top bar → the same as the × / the exit;
 * - `none`: the × is locked (saving, a forced mess, starting / finishing) → swallowed.
 */
export function gameBack({ card, exitOpen, hasBar, closeLocked, covered }: BackInput): 'hint' | 'exit' | 'none' {
  if (card) return 'exit';
  if (hasBar && !closeLocked && !covered) return 'hint';
  return exitOpen ? 'exit' : 'none';
}
