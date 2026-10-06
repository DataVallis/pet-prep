/**
 * The reward-timing mini-game (M5-R03) — pure logic, unit-tested with fake timers.
 *
 * The server owns the schedule (8 cues, the dog obeys on some of them after a short
 * random delay, a 1.5 s praise window) and the scoring. The app plays the schedule
 * against its own clock, started the moment the start response arrived, and sends the
 * child's "Pohvali" taps as whole ms since that local start. Because both the schedule
 * and the taps are relative to the same local start, network latency doesn't shift a
 * tap against its cue.
 *
 * Local feedback mirrors the server's `TrainingService::score` (taps belong to the cue
 * whose slot [cue, next cue) holds them, taps before the first cue are ignored, the
 * FIRST tap of a slot decides) so the child sees "prezgodaj / prepozno / bravo" at once;
 * the server's result replaces it on finish.
 */

import type { TrainingSession, TrainingTrial, TrialOutcome } from '@/modules/training/training';

/** Upper bound of taps per finish (backend `TrainingService::MAX_TAPS`). */
export const MAX_TAPS = 64;

/** Index into `trials` of the slot that holds `ms`, or null (lead-in / out of range). */
export function slotIndex(trials: readonly TrainingTrial[], ms: number): number | null {
  if (trials.length === 0 || ms < trials[0].cue_at_ms) return null;
  for (let i = trials.length - 1; i >= 0; i--) {
    if (ms >= trials[i].cue_at_ms) return i;
  }
  return null;
}

/** End of slot `i` (next cue, or the session's end for the last one). */
export function slotEnd(trials: readonly TrainingTrial[], i: number, durationMs: number): number {
  return i + 1 < trials.length ? trials[i + 1].cue_at_ms : durationMs;
}

/** Outcome of a trial for its first tap (null = no tap). Same rules as the server. */
export function trialOutcome(trial: TrainingTrial, firstTapMs: number | null, windowMs: number): TrialOutcome {
  if (!trial.obeys || trial.obey_at_ms === null) return firstTapMs === null ? 'waited' : 'praised_without_obeying';
  if (firstTapMs === null) return 'no_praise';
  if (firstTapMs < trial.obey_at_ms) return 'too_early';
  if (firstTapMs <= trial.obey_at_ms + windowMs) return 'in_time';
  return 'too_late';
}

/** First tap per slot index (taps sorted or not). */
export function firstTapsBySlot(trials: readonly TrainingTrial[], taps: readonly number[]): Map<number, number> {
  const out = new Map<number, number>();
  for (const t of [...taps].sort((a, b) => a - b)) {
    const i = slotIndex(trials, t);
    if (i !== null && !out.has(i)) out.set(i, t);
  }
  return out;
}

/**
 * Outcome of slot `i` as known at `elapsedMs`: decided by a tap, or — without a tap —
 * once the slot is over. null = still open (the child may yet praise).
 */
export function liveOutcome(session: TrainingSession, i: number, firstTapMs: number | null, elapsedMs: number): TrialOutcome | null {
  const trial = session.trials[i];
  if (trial === undefined) return null;
  if (firstTapMs !== null) return trialOutcome(trial, firstTapMs, session.praise_window_ms);
  if (elapsedMs >= slotEnd(session.trials, i, session.duration_ms)) return trialOutcome(trial, null, session.praise_window_ms);
  // A missed praise window is already final, even before the next cue.
  if (trial.obeys && trial.obey_at_ms !== null && elapsedMs > trial.obey_at_ms + session.praise_window_ms) {
    return 'no_praise';
  }
  return null;
}

/**
 * Record a "Pohvali" tap at `ms`: only the first tap of a slot matters to the server,
 * so later ones in the same slot (and lead-in taps, taps after the end) are not kept —
 * the list stays ≤ the number of cues, well under `MAX_TAPS`. Returns the same array
 * when nothing changed.
 */
export function addTap(session: TrainingSession, taps: readonly number[], ms: number): readonly number[] {
  const t = Math.round(ms);
  if (!Number.isFinite(t) || t < 0 || t > session.duration_ms || taps.length >= MAX_TAPS) return taps;
  const i = slotIndex(session.trials, t);
  if (i === null) return taps;
  if (taps.some((x) => slotIndex(session.trials, x) === i)) return taps;
  return [...taps, t].sort((a, b) => a - b);
}

/** How long the previous cue's verdict stays up after the next cue. */
export const FEEDBACK_CARRY_MS = 1_500;

/**
 * The verdict to show now: the current cue's once decided; otherwise, briefly, the
 * previous cue's (a "počakal(a) si" is only final when the next cue comes). After the
 * end: the last cue's.
 */
export function feedbackAt(
  session: TrainingSession,
  taps: readonly number[],
  elapsedMs: number,
): { slot: number; outcome: TrialOutcome } | null {
  const firsts = firstTapsBySlot(session.trials, taps);
  const outcomeOf = (i: number) => liveOutcome(session, i, firsts.get(i) ?? null, elapsedMs);
  const last = session.trials.length - 1;
  if (elapsedMs >= session.duration_ms) {
    const outcome = outcomeOf(last);
    return outcome === null ? null : { slot: last, outcome };
  }
  const i = slotIndex(session.trials, elapsedMs);
  if (i === null) return null;
  const own = outcomeOf(i);
  if (own !== null) return { slot: i, outcome: own };
  if (i > 0 && elapsedMs - session.trials[i].cue_at_ms < FEEDBACK_CARRY_MS) {
    const previous = outcomeOf(i - 1);
    if (previous !== null) return { slot: i - 1, outcome: previous };
  }
  return null;
}

/** What the dog does right now. */
export type DogAction = 'waiting' | 'listening' | 'obeying' | 'ignoring';

/** How long the cue bubble stays up after a cue. */
export const CUE_VISIBLE_MS = 1_500;
/** A dog that doesn't obey shows it this long after the cue (≈ the earliest obey delay). */
export const IGNORE_AFTER_MS = 800;
/** The dog keeps "doing it" this long after its praise window. */
export const OBEY_HOLD_MS = 1_000;

export interface GameFrame {
  /** Index into `trials` of the current slot (null during the lead-in / after the end). */
  slot: number | null;
  /** Show the cue bubble ("Sedi!"). */
  cueVisible: boolean;
  dog: DogAction;
  /** The praise window is open now (for tests / a11y; never shown as a hint). */
  windowOpen: boolean;
  /** Whole seconds left (rounded up). */
  secondsLeft: number;
  /** 0–1 of the session. */
  progress: number;
  over: boolean;
}

/** The game at `elapsedMs` (local clock). */
export function frameAt(session: TrainingSession, elapsedMs: number): GameFrame {
  const e = Math.max(0, elapsedMs);
  const over = e >= session.duration_ms;
  const slot = over ? null : slotIndex(session.trials, e);
  const base = {
    secondsLeft: Math.max(0, Math.ceil((session.duration_ms - e) / 1000)),
    progress: Math.min(1, e / session.duration_ms),
    over,
  };
  if (slot === null) return { ...base, slot: null, cueVisible: false, dog: 'waiting', windowOpen: false };
  const trial = session.trials[slot];
  const sinceCue = e - trial.cue_at_ms;
  let dog: DogAction = 'listening';
  let windowOpen = false;
  if (trial.obeys && trial.obey_at_ms !== null) {
    const windowEnd = trial.obey_at_ms + session.praise_window_ms;
    if (e >= trial.obey_at_ms && e <= windowEnd + OBEY_HOLD_MS) dog = 'obeying';
    windowOpen = e >= trial.obey_at_ms && e <= windowEnd;
  } else if (sinceCue >= IGNORE_AFTER_MS) {
    dog = 'ignoring';
  }
  return { ...base, slot, cueVisible: sinceCue < CUE_VISIBLE_MS, dog, windowOpen };
}

// ── Clock ─────────────────────────────────────────────────────

export interface ClockSources {
  /** Monotonic ms (performance.now) — never jumps with a clock change. */
  mono: () => number;
  /** Wall ms (Date.now) — keeps running while the device sleeps. */
  wall: () => number;
}

function defaultMono(): number {
  const perf = (globalThis as { performance?: { now?: () => number } }).performance;
  return typeof perf?.now === 'function' ? perf.now() : Date.now();
}

export const DEFAULT_CLOCK_SOURCES: ClockSources = { mono: defaultMono, wall: () => Date.now() };

/** Drift between the clocks (ms) above which the session clock follows the wall clock. */
export const RESYNC_THRESHOLD_MS = 250;

/**
 * The session's local clock: monotonic while the app is in the foreground (taps are
 * measured with it), re-anchored to the wall clock after the app comes back — a
 * monotonic clock may stop while the phone sleeps, the server's clock does not.
 */
export class SessionClock {
  private monoOrigin: number;
  private readonly wallOrigin: number;

  constructor(private readonly sources: ClockSources = DEFAULT_CLOCK_SOURCES) {
    this.monoOrigin = sources.mono();
    this.wallOrigin = sources.wall();
  }

  /** ms since the local start (monotonic). */
  elapsed(): number {
    return Math.max(0, Math.round(this.sources.mono() - this.monoOrigin));
  }

  /** ms since the local start by the wall clock. */
  wallElapsed(): number {
    return Math.max(0, this.sources.wall() - this.wallOrigin);
  }

  /** Wall ms of the local start. */
  startedAtWall(): number {
    return this.wallOrigin;
  }

  /** Follow the wall clock when the monotonic one fell behind (device slept in the background). */
  resync(): void {
    const drift = this.wallElapsed() - this.elapsed();
    if (Math.abs(drift) > RESYNC_THRESHOLD_MS) this.monoOrigin -= drift;
  }
}

/**
 * Whether a finish can still be accepted at `serverNowMs` (TTL `expires_at`, with a
 * small safety margin for the request's own travel time).
 */
export const FINISH_MARGIN_MS = 3_000;

export function canStillFinish(session: Pick<TrainingSession, 'expires_at'>, serverNowMs: number): boolean {
  const expires = Date.parse(session.expires_at);
  return Number.isNaN(expires) ? true : serverNowMs < expires - FINISH_MARGIN_MS;
}
