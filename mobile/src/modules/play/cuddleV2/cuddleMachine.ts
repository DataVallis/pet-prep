/**
 * Cuddles v2 — the 3-second rule (GAMES_SPEC §4.4, C1, David 2026-10-30), pure. App only: the
 * server still gets ONE `POST /api/child/pet/play {kind: "cuddle"}` per finished cuddle (it
 * never checked strokes). Dog and cat alike.
 *
 * Three hearts. A heart fills while the child strokes SLOWLY (≤ `CUDDLE_MAX_SPEED_PT_S`; only
 * finger movement fills — `move` samples, each worth at most `CUDDLE_MAX_SAMPLE_MS`) for
 * `CUDDLE_HEART_MS` (3 s). Too fast → no fill, 🐢 for ≥ 2 s. A full heart → ✋ »Počakaj …«: the
 * child lifts the finger; after a pause of `CUDDLE_PAUSE_MS` the pet »asks for more« (💗 »Še!«)
 * and the next heart may fill. Not lifting just waits — nothing is ever lost. The third heart
 * ends the cuddle at once (»Uživa!«).
 *
 * »Lažje kretnje« / screen reader: the »Pobožaj« button — holding it fills like slow stroking
 * (`hold` ticks), letting go is the pause; a screen reader's activate fills the current heart
 * (`complete`) and pauses.
 */

export const CUDDLE_HEARTS = 3;
export const CUDDLE_HEART_MS = 3_000;
export const CUDDLE_PAUSE_MS = 1_000;
export const CUDDLE_MAX_SPEED_PT_S = 900;
/** One move sample fills at most this long (a finger resting between samples doesn't fill). */
export const CUDDLE_MAX_SAMPLE_MS = 120;
export const CUDDLE_TOO_FAST_MS = 2_000;
/** Soft haptic every 0.5 s of filling, a purr every 1 s (§4.4). */
export const CUDDLE_HAPTIC_EVERY_MS = 500;
export const CUDDLE_PURR_EVERY_MS = 1_000;
/** The hold button's tick (it fills like slow stroking). */
export const CUDDLE_HOLD_TICK_MS = 100;

export type CuddlePhase = 'ready' | 'stroking' | 'full' | 'paused' | 'ask' | 'done';

export interface CuddleState {
  phase: CuddlePhase;
  /** Full hearts (0 … 3). */
  hearts: number;
  /** The current heart's fill (0 … CUDDLE_HEART_MS). */
  fillMs: number;
  touching: boolean;
  /** The last move sample (null = finger up). */
  lastAt: number | null;
  /** The finger lifted off a full heart at (null = not pausing). */
  pausedAt: number | null;
  /** 🐢 shows until (null = not shown). */
  tooFastUntil: number | null;
}

export const CUDDLE_START: CuddleState = { phase: 'ready', hearts: 0, fillMs: 0, touching: false, lastAt: null, pausedAt: null, tooFastUntil: null };

export type CuddleEvent =
  | { type: 'down'; at: number }
  | { type: 'move'; at: number; speed: number }
  | { type: 'up'; at: number }
  | { type: 'tick'; at: number }
  | { type: 'hold'; at: number; dtMs: number }
  | { type: 'complete'; at: number };

function fill(state: CuddleState, dtMs: number): CuddleState {
  const fillMs = state.fillMs + Math.max(0, Math.min(CUDDLE_MAX_SAMPLE_MS, dtMs));
  if (fillMs < CUDDLE_HEART_MS) return { ...state, fillMs };
  const hearts = state.hearts + 1;
  if (hearts >= CUDDLE_HEARTS) return { ...state, hearts: CUDDLE_HEARTS, fillMs: 0, phase: 'done' };
  return { ...state, hearts, fillMs: 0, phase: state.touching ? 'full' : 'paused', pausedAt: state.touching ? null : state.lastAt };
}

/** One step; an event that doesn't fit is ignored (the same object comes back). */
export function cuddleStep(state: CuddleState, event: CuddleEvent): CuddleState {
  if (state.phase === 'done') return state;
  switch (event.type) {
    case 'down': {
      const touching = { ...state, touching: true, lastAt: null };
      if (state.phase === 'ready' || state.phase === 'ask') return { ...touching, phase: 'stroking', pausedAt: null };
      // Touched again before the pause was long enough: wait again (nothing lost).
      if (state.phase === 'paused') return { ...touching, phase: 'full', pausedAt: null };
      return touching;
    }
    case 'move': {
      if (!state.touching) return state;
      const dt = state.lastAt === null ? 0 : event.at - state.lastAt;
      const moved = { ...state, lastAt: event.at };
      if (state.phase !== 'stroking') return moved;
      if (!Number.isFinite(event.speed) || event.speed > CUDDLE_MAX_SPEED_PT_S) return { ...moved, tooFastUntil: event.at + CUDDLE_TOO_FAST_MS };
      return fill(moved, dt);
    }
    case 'hold': {
      if (state.phase === 'ready' || state.phase === 'ask') return fill({ ...state, phase: 'stroking', touching: true, pausedAt: null, lastAt: event.at }, event.dtMs);
      if (state.phase === 'paused') return { ...state, phase: 'full', touching: true, pausedAt: null };
      if (state.phase !== 'stroking') return { ...state, touching: true };
      return fill({ ...state, touching: true, lastAt: event.at }, event.dtMs);
    }
    case 'up': {
      if (!state.touching) return state;
      const up = { ...state, touching: false, lastAt: null };
      return state.phase === 'full' ? { ...up, phase: 'paused', pausedAt: event.at } : up;
    }
    case 'tick': {
      let next = state;
      if (next.tooFastUntil !== null && event.at >= next.tooFastUntil) next = { ...next, tooFastUntil: null };
      if (next.phase === 'paused' && next.pausedAt !== null && event.at - next.pausedAt >= CUDDLE_PAUSE_MS) next = { ...next, phase: 'ask', pausedAt: null };
      return next;
    }
    case 'complete': {
      if (state.phase !== 'ready' && state.phase !== 'ask' && state.phase !== 'stroking') return state;
      const filled = fill({ ...state, phase: 'stroking', touching: false, lastAt: event.at, fillMs: CUDDLE_HEART_MS - 1 }, 1);
      return filled.phase === 'paused' ? { ...filled, pausedAt: event.at } : filled;
    }
    default:
      return state;
  }
}

/** When the machine should get its next `tick` (pause over, 🐢 over), or null. */
export function cuddleNextTick(state: CuddleState): number | null {
  const at: number[] = [];
  if (state.phase === 'paused' && state.pausedAt !== null) at.push(state.pausedAt + CUDDLE_PAUSE_MS);
  if (state.tooFastUntil !== null) at.push(state.tooFastUntil);
  return at.length === 0 ? null : Math.min(...at);
}

export type CuddleCue =
  | { kind: 'haptic' }
  | { kind: 'purr' }
  | { kind: 'heart'; hearts: number }
  | { kind: 'ask' }
  | { kind: 'tooFast' }
  | { kind: 'done' };

/** Total fill (ms) over the whole cuddle — the haptic / purr rhythm runs on it. */
function total(state: CuddleState): number {
  return state.hearts * CUDDLE_HEART_MS + state.fillMs;
}

/** What the child feels / hears / sees for `prev` → `next` (positive only; too fast = the 🐢 icon). */
export function cuddleCues(prev: CuddleState, next: CuddleState): CuddleCue[] {
  if (prev === next) return [];
  const cues: CuddleCue[] = [];
  const a = total(prev);
  const b = total(next);
  if (b > a) {
    if (Math.floor(b / CUDDLE_HAPTIC_EVERY_MS) > Math.floor(a / CUDDLE_HAPTIC_EVERY_MS) && next.hearts === prev.hearts) cues.push({ kind: 'haptic' });
    if (Math.floor(b / CUDDLE_PURR_EVERY_MS) > Math.floor(a / CUDDLE_PURR_EVERY_MS) && next.hearts === prev.hearts) cues.push({ kind: 'purr' });
  }
  if (next.hearts > prev.hearts && next.phase !== 'done') cues.push({ kind: 'heart', hearts: next.hearts });
  if (prev.phase !== 'ask' && next.phase === 'ask') cues.push({ kind: 'ask' });
  if (prev.tooFastUntil === null && next.tooFastUntil !== null) cues.push({ kind: 'tooFast' });
  if (prev.phase !== 'done' && next.phase === 'done') cues.push({ kind: 'done' });
  return cues;
}

export type CuddleLine = 'slow' | 'wait' | 'more' | 'done';

/** The one line: the rule at the start, »wait« on a full heart, »more« when the pet asks, »done«. */
export function cuddleLine(state: CuddleState): CuddleLine {
  switch (state.phase) {
    case 'full':
    case 'paused':
      return 'wait';
    case 'ask':
      return 'more';
    case 'done':
      return 'done';
    default:
      return 'slow';
  }
}

/** Fill of heart `i` (0 … 1) for the three hearts at the top. */
export function heartFill(state: CuddleState, i: number): number {
  if (i < state.hearts) return 1;
  if (i === state.hearts && state.phase !== 'done') return state.fillMs / CUDDLE_HEART_MS;
  return 0;
}
