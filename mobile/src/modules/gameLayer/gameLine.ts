/**
 * GameLine — the ONE line of text a game shows (GAMES_SPEC §3.1 / §7.1), pure.
 *
 * - At most `GAME_LINE_MAX_CHARS` (32) characters — every text the games put here is checked
 *   by a test; a longer one is cut with "…" so it can never wrap under the top bar.
 * - A line stays at least `GAME_LINE_MIN_MS` (2 s): a newer line (or "clear") waits in the
 *   queue until the shown one has had its time; then the NEWEST waiting one wins — the
 *   child never reads a stale queue of old messages.
 * - The same text again (shown or waiting) is ignored.
 *
 * The component (`GameLine.tsx`) renders `shown`, announces it (live region + iOS
 * announcement) and runs one timer at `nextChangeAt`.
 */

export const GAME_LINE_MAX_CHARS = 32;
export const GAME_LINE_MIN_MS = 2_000;

export interface GameLineState {
  /** The text on screen (null = nothing). */
  shown: string | null;
  /** When `shown` appeared (ms of the caller's clock). */
  since: number;
  /** The newest line waiting for the shown one's 2 s (undefined = nothing waits; null = "clear"). */
  waiting: string | null | undefined;
}

export const EMPTY_GAME_LINE: GameLineState = { shown: null, since: Number.NEGATIVE_INFINITY, waiting: undefined };

/** A line as it may be shown: trimmed, ≤ 32 characters (cut with "…"). */
export function clampGameLine(text: string): string {
  const trimmed = text.trim().replace(/\s+/g, ' ');
  const chars = Array.from(trimmed);
  if (chars.length <= GAME_LINE_MAX_CHARS) return trimmed;
  return `${chars.slice(0, GAME_LINE_MAX_CHARS - 1).join('').trimEnd()}…`;
}

/** Ask for a line (`null` = clear it). */
export function sayLine(state: GameLineState, text: string | null, now: number): GameLineState {
  const next = text === null || text.trim() === '' ? null : clampGameLine(text);
  if (state.waiting === undefined && next === state.shown) return state;
  if (state.waiting !== undefined && next === state.waiting) return state;
  if (now - state.since >= GAME_LINE_MIN_MS) {
    // A "clear" that ends nothing visible changes nothing.
    if (next === state.shown) return { ...state, waiting: undefined };
    return { shown: next, since: now, waiting: undefined };
  }
  // The shown line hasn't had its 2 s: the newest request waits (replaces an older waiting one).
  return { ...state, waiting: next === state.shown ? undefined : next };
}

/** Let time pass: a waiting line takes over once the shown one had its 2 s. */
export function tickLine(state: GameLineState, now: number): GameLineState {
  if (state.waiting === undefined || now - state.since < GAME_LINE_MIN_MS) return state;
  return { shown: state.waiting, since: now, waiting: undefined };
}

/** When the line may change by itself (for one timer), or null. */
export function nextLineChangeAt(state: GameLineState): number | null {
  return state.waiting === undefined ? null : state.since + GAME_LINE_MIN_MS;
}
