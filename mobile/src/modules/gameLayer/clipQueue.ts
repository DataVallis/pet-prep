/**
 * ClipQueue — WHICH clip role the game stage shows and WHEN it may change (GAMES_SPEC §3.2,
 * §7.1), pure. `ClipDeck` only renders what this decides; `useClipQueue` runs it on the game
 * clock.
 *
 * Every clip has a start and an end POSE (`stand`, `empty`, `crouch`, …). The chain rules:
 * 1. **Never cut a clip mid-way.** A `once` clip (pose → pose) plays to its end; a request
 *    made meanwhile waits in the queue (the newest request wins) and runs at the end.
 * 2. **Crossfade only from a loop**, ≤ `CROSSFADE_MAX_MS` (200 ms), and only into a clip that
 *    starts in the loop's pose (every frame of a hub loop ≈ the hub pose).
 * 3. A finished `once` clip holds its last frame; the next clip that starts in that pose
 *    follows INSTANTLY (same frame — no fade, no jump).
 * 4. Anything else changes the pose = a **cut**, allowed only while the FeedbackLayer masks
 *    the stage (`setMasked(true)`); until then the request waits.
 *
 * End of a `once` clip = the player's `playToEnd` (`clipEnded`) or, as a safety, its length
 * + `endGraceMs` on the clock (`tick`) — a role without a player (picture mode, missing clip)
 * has grace 0, so the game moves on its stored length alone.
 */

export const CROSSFADE_MAX_MS = 200;
/** The crossfade from a hub loop into a clip of the same pose. */
export const CROSSFADE_MS = 180;
/** Safety after a clip's length when the player's end event never comes. */
export const CLIP_END_GRACE_MS = 600;

export interface ClipSpec {
  role: string;
  /** Chain rule: a loop can be left at any moment (crossfade); a `once` clip only at its end. */
  loop: boolean;
  startPose: string;
  endPose: string;
  /** Length of one pass (ms) — the server's `phase_seconds`, else the default. */
  durationMs: number;
  /** Extra wait after `durationMs` before the clock declares the end (0 without a player). */
  endGraceMs: number;
}

export type ClipSwitch = 'crossfade' | 'instant' | 'cut';

export interface ClipQueueState {
  clips: Readonly<Record<string, ClipSpec>>;
  /** The role on screen. */
  current: string;
  /** Changes on every switch (the deck restarts that role's player from frame 0). */
  instance: number;
  /** Clock ms when `current` started. */
  startedAt: number;
  /** A `once` clip reached its end (holds its last frame). Loops never "end". */
  ended: boolean;
  /** The newest role waiting for its turn, or null. */
  pending: string | null;
  /** The FeedbackLayer masks the stage (≥ 85 % opaque) — a cut may happen now. */
  masked: boolean;
  /** How the last switch happened (the deck animates a crossfade from `from`). */
  lastSwitch: { from: string; kind: ClipSwitch; ms: number } | null;
}

/** How the stage may go from `from` (ended or a loop) to `to`. */
export function switchKind(from: ClipSpec, to: ClipSpec): ClipSwitch {
  if (from.endPose !== to.startPose) return 'cut';
  return from.loop ? 'crossfade' : 'instant';
}

export function createClipQueue(clips: readonly ClipSpec[], initial: string, now: number): ClipQueueState {
  const map: Record<string, ClipSpec> = {};
  for (const clip of clips) map[clip.role] = clip;
  if (map[initial] === undefined) throw new Error(`ClipQueue: unknown initial role ${initial}`);
  return { clips: map, current: initial, instance: 0, startedAt: now, ended: false, pending: null, masked: false, lastSwitch: null };
}

/** The current clip may be left now (a loop, or a `once` clip that reached its end). */
export function canLeave(state: ClipQueueState): boolean {
  return state.clips[state.current].loop || state.ended;
}

function apply(state: ClipQueueState, now: number): ClipQueueState {
  const role = state.pending;
  if (role === null) return state;
  if (!canLeave(state)) return state;
  const from = state.clips[state.current];
  const to = state.clips[role];
  const kind = switchKind(from, to);
  if (kind === 'cut' && !state.masked) return state;
  return {
    ...state,
    current: role,
    instance: state.instance + 1,
    startedAt: now,
    ended: false,
    pending: null,
    lastSwitch: { from: state.current, kind, ms: kind === 'crossfade' ? Math.min(CROSSFADE_MS, CROSSFADE_MAX_MS) : 0 },
  };
}

/**
 * Ask for a role. It runs as soon as the chain rules allow (maybe at once). Asking for the loop
 * that already plays drops whatever waited (the stage simply stays).
 */
export function requestClip(state: ClipQueueState, role: string, now: number): ClipQueueState {
  if (state.clips[role] === undefined) return state;
  if (role === state.current && state.clips[role].loop) return state.pending === null ? state : { ...state, pending: null };
  return apply({ ...state, pending: role }, now);
}

/** The player of `role` / `instance` reached its end. */
export function clipEnded(state: ClipQueueState, role: string, instance: number, now: number): ClipQueueState {
  if (role !== state.current || instance !== state.instance || state.ended || state.clips[role].loop) return state;
  return apply({ ...state, ended: true }, now);
}

/** The FeedbackLayer's mask went up / down. */
export function setMasked(state: ClipQueueState, masked: boolean, now: number): ClipQueueState {
  if (state.masked === masked) return state;
  return apply({ ...state, masked }, now);
}

/** Clock safety: a `once` clip is over at its length + grace even without the player's event. */
export function tickClips(state: ClipQueueState, now: number): ClipQueueState {
  const clip = state.clips[state.current];
  if (clip.loop || state.ended) return state;
  if (now - state.startedAt < clip.durationMs + clip.endGraceMs) return state;
  return apply({ ...state, ended: true }, now);
}

/** When `tickClips` should next run (clock ms), or null. */
export function nextClipTickAt(state: ClipQueueState): number | null {
  const clip = state.clips[state.current];
  if (clip.loop || state.ended) return null;
  return state.startedAt + clip.durationMs + clip.endGraceMs;
}
