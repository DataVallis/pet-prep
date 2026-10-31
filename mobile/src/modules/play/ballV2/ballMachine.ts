/**
 * Ball game v2 — the round as a pure state machine (GAMES_SPEC §4.3, David 2026-10-30:
 * 3 throws per round, V10). No native import, no timers: `BallGameV2` feeds it events
 * (gestures, clip ends from ClipQueue, the one timer `ballTimer` asks for) and plays the
 * cues `ballCues` returns.
 *
 * One throw = three acts: **throw** (pull and let go) → the dog runs out of frame
 * (`ball_running`) → **call** (»Pokliči!«, optional — after `CALL_WAIT_MS` the dog comes back
 * by itself) → the dog returns (`ball_fetching`) → the ball rolls to the camera → **catch**
 * (tap; a miss just stops the ball at the bottom — no ✗). After the 3rd throw the round is
 * `done` (»Bravo! ★★★«, »Še en krog« / »Končano«) and the game reports ONE finished play.
 *
 * Mood only (M5-R05 unchanged): stars are display only, nothing goes to the server but the
 * one `POST /api/child/pet/play {kind:"play"}` per round.
 */

import { BALL_THROWS } from '@/modules/play/play';

export type BallPhase = 'aim' | 'flying' | 'call' | 'returning' | 'rolling' | 'done';
/** The clip role the stage shows for a phase (`ballDeck`). */
export type BallRole = 'waiting' | 'running' | 'fetching';

export interface BallGameState {
  phase: BallPhase;
  /** Throws started this round (1…3 once the first ball flies). */
  throws: number;
  /** Balls caught this round (display only). */
  stars: number;
  /** 1, 2, … — »Še en krog« starts the next one. */
  round: number;
  /** Power (0…1) of the current throw — only the picture (arc height / distance). */
  power: number;
  /** The child called the dog during this throw. */
  called: boolean;
}

export type BallEvent =
  | { type: 'throw'; power: number }
  | { type: 'runEnded' }
  | { type: 'call' }
  | { type: 'callTimeout' }
  | { type: 'fetchEnded' }
  | { type: 'catch' }
  | { type: 'catchTimeout' }
  | { type: 'again' };

/** How long the dog waits out of frame for »Pokliči!» before it comes back by itself. */
export const CALL_WAIT_MS = 2_000;
/** The returned ball rolls to the camera this long. */
export const ROLL_MS = 900;
/** Catch window from the start of the roll (spec: T+6 … T+7.5 s). */
export const CATCH_WINDOW_MS = 1_500;
/** »Lažje kretnje« / screen reader: a wider window (§4.3 Dostopnost). */
export const CATCH_WINDOW_EASY_MS = 2_500;

export const BALL_V2_START: BallGameState = { phase: 'aim', throws: 0, stars: 0, round: 1, power: 1, called: false };

function clampPower(power: number): number {
  return Number.isFinite(power) ? Math.min(1, Math.max(0, power)) : 1;
}

/** One step. An event that doesn't fit the phase is ignored (the same object comes back). */
export function ballStep(state: BallGameState, event: BallEvent): BallGameState {
  switch (event.type) {
    case 'throw':
      // A throw only from the hand while the dog waits (anti-spam: never while it is out).
      if (state.phase !== 'aim' || state.throws >= BALL_THROWS) return state;
      return { ...state, phase: 'flying', throws: state.throws + 1, power: clampPower(event.power), called: false };
    case 'runEnded':
      return state.phase === 'flying' ? { ...state, phase: 'call' } : state;
    case 'call':
      return state.phase === 'call' ? { ...state, phase: 'returning', called: true } : state;
    case 'callTimeout':
      return state.phase === 'call' ? { ...state, phase: 'returning' } : state;
    case 'fetchEnded':
      return state.phase === 'returning' ? { ...state, phase: 'rolling' } : state;
    case 'catch':
      if (state.phase !== 'rolling') return state;
      return { ...state, stars: state.stars + 1, phase: state.throws >= BALL_THROWS ? 'done' : 'aim' };
    case 'catchTimeout':
      if (state.phase !== 'rolling') return state;
      return { ...state, phase: state.throws >= BALL_THROWS ? 'done' : 'aim' };
    case 'again':
      return state.phase === 'done' ? { ...BALL_V2_START, round: state.round + 1 } : state;
    default:
      return state;
  }
}

/** Which clip the stage shows: the hub loop, the run (held on its last frame while waiting for the call), the return. */
export function ballRole(state: BallGameState): BallRole {
  switch (state.phase) {
    case 'flying':
    case 'call':
      return 'running';
    case 'returning':
      return 'fetching';
    default:
      return 'waiting';
  }
}

/** The one timer of a phase (`easy` = »Lažje kretnje« / screen reader), or null. */
export function ballTimer(state: BallGameState, easy: boolean): { event: BallEvent; ms: number } | null {
  if (state.phase === 'call') return { event: { type: 'callTimeout' }, ms: CALL_WAIT_MS };
  if (state.phase === 'rolling') return { event: { type: 'catchTimeout' }, ms: easy ? CATCH_WINDOW_EASY_MS : CATCH_WINDOW_MS };
  return null;
}

export type BallSound = 'whoosh' | 'bounce' | 'whistle' | 'chime' | 'pop';
/** Positive haptics only — a miss never vibrates (§3.1, §7.1). */
export type BallHaptic = 'tap' | 'success';
export type BallFeedbackLabel = 'throw' | 'called' | 'caught';

export type BallCue =
  | { kind: 'feedback'; feedback: 'ok' | 'star'; label: BallFeedbackLabel }
  | { kind: 'sound'; sound: BallSound; delayMs: number }
  | { kind: 'haptic'; haptic: BallHaptic }
  /** The round is over: report one finished play (once per round). */
  | { kind: 'roundDone'; round: number; stars: number };

/** When the thrown ball first touches the ground (ms after the throw) — the bounce sound. */
export const BALL_LAND_MS = 550;

/** What the child sees / hears / feels for the step `prev` → `next` (nothing for a miss). */
export function ballCues(prev: BallGameState, next: BallGameState): BallCue[] {
  if (prev === next) return [];
  const cues: BallCue[] = [];
  if (prev.phase === 'aim' && next.phase === 'flying') {
    cues.push({ kind: 'feedback', feedback: 'ok', label: 'throw' });
    cues.push({ kind: 'sound', sound: 'whoosh', delayMs: 0 });
    cues.push({ kind: 'sound', sound: 'bounce', delayMs: BALL_LAND_MS });
    cues.push({ kind: 'haptic', haptic: 'tap' });
  }
  if (prev.phase === 'call' && next.phase === 'returning' && next.called) {
    cues.push({ kind: 'feedback', feedback: 'ok', label: 'called' });
    cues.push({ kind: 'sound', sound: 'whistle', delayMs: 0 });
    cues.push({ kind: 'haptic', haptic: 'tap' });
  }
  if (prev.phase === 'returning' && next.phase === 'rolling') {
    cues.push({ kind: 'sound', sound: 'pop', delayMs: 0 });
  }
  if (prev.phase === 'rolling' && next.stars > prev.stars) {
    cues.push({ kind: 'feedback', feedback: 'star', label: 'caught' });
    cues.push({ kind: 'sound', sound: 'chime', delayMs: 0 });
    cues.push({ kind: 'haptic', haptic: 'success' });
  }
  if (prev.phase !== 'done' && next.phase === 'done') {
    cues.push({ kind: 'roundDone', round: next.round, stars: next.stars });
  }
  return cues;
}

/** The game line (§4.3 texts): the rule only before the very first throw of the game. */
export type BallLine = 'hint' | null;

export function ballLine(state: BallGameState): BallLine {
  return state.phase === 'aim' && state.throws === 0 && state.round === 1 ? 'hint' : null;
}

/** Progress dots: ● for each throw started, ○ for the rest. */
export function ballDots(state: BallGameState): boolean[] {
  return Array.from({ length: BALL_THROWS }, (_, i) => i < state.throws);
}
