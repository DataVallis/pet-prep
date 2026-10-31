/**
 * School v2 on the game layer (GAMES_SPEC §4.1 / §4.2, rules v2 — David 2026-10-30, Š1–Š3), pure.
 *
 * The server's schedule (`rules: 2`) is played as it comes — the app never re-times it:
 *
 *   whole session                          → the rule strip »Ko kuža uboga → pritisni ♥ …« (SchoolRuleStrip, 2026-10-31)
 *   lead-in 0 … cue₀ (round 1 only)        → attention loop, no cue yet
 *   per cue the dog obeys:
 *     cue … perform_at                     → `listening`: big command chip + 👂, attention loop
 *     perform_at … obey_at                 → `performing`: chip + 👂, the performing clip from frame 0, NO text
 *     obey_at … window_end                 → `window`: chip gone (= »now«), success loop, a11y »Kuža se usede.«;
 *                                            the praise hint (glow + »Zdaj!«) in every session
 *     window_end (= release_at) … + mask   → `mask`: the verdict under the FeedbackLayer mask; the cut
 *                                            success loop → attention loop happens under it (Š2 — the
 *                                            reversed release clip is never played)
 *     … slot end                           → `calm` (700 ms standing)
 *   per cue the dog ignores (slot 4.5 s):
 *     cue … cue + 1.6 s                    → `ignoredCue`: chip for 1.5 s, attention loop
 *     cue + 1.6 s … slot end               → `ignoring`: chip »Ne uboga — ne pohvali, počakaj.« + what the dog
 *                                            does, the token dimmed (»Počakaj«, still tappable) (≥ 2.9 s)
 *   after round 1                          → `break` (2.5 s): »1. krog ✓«, the round's stars, »Nadaljuj (3)«
 *                                            counting down to round 2's start, small »Končaj«
 *   duration                               → `over`
 *
 * Scoring stays the server's (`TrainingService::score`); the instant feedback mirrors it with
 * `game.ts` (`trialOutcome` / `liveOutcome`; the FIRST tap of a slot decides).
 */

import { firstTapsBySlot, liveOutcome, missedWhileAway, slotEnd, trialOutcome } from '@/modules/training/game';
import {
  isSchoolV2Session,
  trainingResultBucket,
  type ChildTraining,
  type TrainingResult,
  type TrainingSession,
  type TrialOutcome,
  type TrialResult,
} from '@/modules/training/training';

/** Cues per round (server `TrainingService::TRIALS_PER_ROUND`). */
export const SCHOOL_TRIALS_PER_ROUND = 3;
/** The command chip of a cue the dog ignores stays this long (§4.1). */
export const IGNORED_CHIP_MS = 1_500;
/** From then on the dog visibly ignores it (after the longest »think«, 1.5 s — §4.1). */
export const IGNORE_SHOWN_AFTER_MS = 1_600;
/** Server `TrainingService::FINISH_EARLY_TOLERANCE_MS`: an early end is accepted until round 2's start + this. */
export const EARLY_END_TOLERANCE_MS = 2_000;
/** Kept free of the server's limit (request handling). */
export const EARLY_END_SAFETY_MS = 300;
/** How far the server may be ahead of a RESUMED session's clock (whole-second `started_at` + skew). */
export const EARLY_END_RESUME_AHEAD_MS = 1_500;
/** The treat token is grey this long after a tap (visual only; the first tap decides — §4.1). */
export const TOKEN_COOLDOWN_MS = 600;

export type SchoolStepKind =
  | 'lead'
  | 'listening'
  | 'performing'
  | 'window'
  | 'mask'
  | 'calm'
  | 'ignoredCue'
  | 'ignoring'
  | 'break'
  | 'over';

/** The clip role the stage should show (ClipQueue decides WHEN it may switch). */
export type SchoolRole = 'attention' | 'performing' | 'success';

export interface SchoolFrame {
  kind: SchoolStepKind;
  /** Index into `trials` (null in the lead-in, a break and after the end). */
  trial: number | null;
  /** 0-based round of the trial / break. */
  round: number;
  role: SchoolRole;
  /** The big command chip (»Sedi!«) is up. */
  chip: boolean;
  /** Status icon by the chip: 👂 listening, or the dog's distraction while it ignores. */
  icon: 'ear' | 'ignore' | null;
  /** Which text step this is (the rule strip / the »don't praise, wait« chip read it via `kind`; kept for the timeline tests). */
  line: 'lead' | 'ignores' | null;
  /** When this step began / ends (ms). */
  fromMs: number;
  toMs: number;
}

type Session = Pick<TrainingSession, 'trials' | 'duration_ms' | 'praise_window_ms' | 'min_reaction_ms' | 'mask_ms' | 'rounds' | 'rules_version'>;

function maskMs(session: Session): number {
  return session.mask_ms ?? 0;
}

/** 0-based round of trial `i` (by the server's rounds; past the last → the last). */
export function roundOfTrial(session: Pick<TrainingSession, 'rounds'>, i: number): number {
  const rounds = session.rounds ?? [];
  const found = rounds.findIndex((r) => i >= r.first_index && i <= r.last_index);
  return found >= 0 ? found : Math.max(0, Math.floor(i / SCHOOL_TRIALS_PER_ROUND));
}

/** Where trial `i`'s own time ends: its round's end for the last cue of a round, else the next cue. */
export function trialEndMs(session: Session, i: number): number {
  const round = (session.rounds ?? []).find((r) => r.last_index === i);
  return round !== undefined ? round.end_ms : slotEnd(session.trials, i, session.duration_ms);
}

/** The step of trial `i` at `e` (the caller made sure `e` lies in the trial's time). */
function trialFrame(session: Session, i: number, e: number): SchoolFrame {
  const t = session.trials[i];
  const round = roundOfTrial(session, i);
  const end = trialEndMs(session, i);
  const base = { trial: i, round };
  if (t.obeys && t.obey_at_ms !== null) {
    const perform = typeof t.perform_at_ms === 'number' ? t.perform_at_ms : t.cue_at_ms;
    const windowEnd = t.window_end_ms ?? t.obey_at_ms + session.praise_window_ms;
    const release = typeof t.release_at_ms === 'number' ? t.release_at_ms : windowEnd;
    const maskEnd = Math.min(end, release + maskMs(session));
    if (e < perform) return { ...base, kind: 'listening', role: 'attention', chip: true, icon: 'ear', line: null, fromMs: t.cue_at_ms, toMs: perform };
    if (e < t.obey_at_ms) return { ...base, kind: 'performing', role: 'performing', chip: true, icon: 'ear', line: null, fromMs: perform, toMs: t.obey_at_ms };
    if (e < release) return { ...base, kind: 'window', role: 'success', chip: false, icon: null, line: null, fromMs: t.obey_at_ms, toMs: release };
    if (e < maskEnd) return { ...base, kind: 'mask', role: 'attention', chip: false, icon: null, line: null, fromMs: release, toMs: maskEnd };
    return { ...base, kind: 'calm', role: 'attention', chip: false, icon: null, line: null, fromMs: maskEnd, toMs: end };
  }
  const shown = Math.min(end, t.cue_at_ms + IGNORE_SHOWN_AFTER_MS);
  if (e < shown) {
    return { ...base, kind: 'ignoredCue', role: 'attention', chip: e < t.cue_at_ms + IGNORED_CHIP_MS, icon: null, line: null, fromMs: t.cue_at_ms, toMs: shown };
  }
  return { ...base, kind: 'ignoring', role: 'attention', chip: false, icon: 'ignore', line: 'ignores', fromMs: shown, toMs: end };
}

/** The game at `elapsedMs` (local session clock). */
export function schoolFrameAt(session: Session, elapsedMs: number): SchoolFrame {
  const e = Math.max(0, elapsedMs);
  const rounds = session.rounds ?? [];
  const lastRound = Math.max(0, rounds.length - 1);
  const idle = { trial: null, role: 'attention' as const, chip: false, icon: null, line: null };
  if (e >= session.duration_ms) return { ...idle, kind: 'over', round: lastRound, fromMs: session.duration_ms, toMs: Number.POSITIVE_INFINITY };
  const first = session.trials[0];
  if (first === undefined || e < first.cue_at_ms) {
    return { ...idle, kind: 'lead', round: 0, line: 'lead', fromMs: 0, toMs: first?.cue_at_ms ?? session.duration_ms };
  }
  // A break between two rounds.
  for (let r = 0; r + 1 < rounds.length; r += 1) {
    if (e >= rounds[r].end_ms && e < rounds[r + 1].start_ms) {
      return { ...idle, kind: 'break', round: r, fromMs: rounds[r].end_ms, toMs: rounds[r + 1].start_ms };
    }
  }
  let i = 0;
  for (let k = session.trials.length - 1; k >= 0; k -= 1) {
    if (e >= session.trials[k].cue_at_ms) {
      i = k;
      break;
    }
  }
  return trialFrame(session, i, e);
}

/** Every instant the frame (or its chip) changes, sorted — one timer per step, never a frame late. */
export function schoolBoundaries(session: Session): number[] {
  const out = new Set<number>([0, session.duration_ms]);
  for (const round of session.rounds ?? []) {
    out.add(round.start_ms);
    out.add(round.end_ms);
  }
  session.trials.forEach((t) => {
    out.add(t.cue_at_ms);
    if (t.obeys && t.obey_at_ms !== null) {
      if (typeof t.perform_at_ms === 'number') out.add(t.perform_at_ms);
      out.add(t.obey_at_ms);
      const release = typeof t.release_at_ms === 'number' ? t.release_at_ms : (t.window_end_ms ?? t.obey_at_ms + session.praise_window_ms);
      out.add(release);
      out.add(release + maskMs(session));
    } else {
      out.add(t.cue_at_ms + IGNORED_CHIP_MS);
      out.add(t.cue_at_ms + IGNORE_SHOWN_AFTER_MS);
    }
  });
  return [...out].filter((n) => Number.isFinite(n) && n >= 0 && n <= session.duration_ms).sort((a, b) => a - b);
}

/** The first boundary after `elapsedMs`, or null after the end. */
export function nextBoundaryAfter(boundaries: readonly number[], elapsedMs: number): number | null {
  for (const b of boundaries) if (b > elapsedMs) return b;
  return null;
}

// ── Feedback ──────────────────────────────────────────────────

/** The FeedbackLayer labels (`training:school.fb.*`). */
export type SchoolFeedbackLabel = 'inTime' | 'early' | 'late' | 'missed' | 'waited' | 'notObeyed';

export interface SchoolFeedback {
  kind: 'ok' | 'miss';
  label: SchoolFeedbackLabel;
  /** Dim the stage ≥ 85 % — the cut back to the attention loop happens under it. */
  mask: boolean;
  totalMs: number;
}

/** Glyph times on screen (§4.1): ✓ 1.0 s, ✗ 0.9 s. */
export const OK_MS = 1_000;
export const MISS_MS = 0.9 * 1_000;
/** FeedbackLayer's shortest show (enter 150 + exit 250). */
const MIN_SHOW_MS = 400;

/** The feedback of the FIRST tap of a slot (later taps of the same slot never count). */
export function tapFeedback(session: Session, trialIndex: number, tapMs: number, frame: SchoolFrame): SchoolFeedback {
  const trial = session.trials[trialIndex];
  const outcome = trialOutcome(trial, tapMs, session.praise_window_ms, session.min_reaction_ms);
  switch (outcome) {
    case 'in_time':
      return { kind: 'ok', label: 'inTime', mask: false, totalMs: OK_MS };
    case 'too_early':
      return { kind: 'miss', label: 'early', mask: false, totalMs: MISS_MS };
    case 'praised_without_obeying':
      return { kind: 'miss', label: 'notObeyed', mask: false, totalMs: MISS_MS };
    default:
      // too late: inside the mask it stays a mask (the cut must stay hidden), else a short ✗.
      return frame.kind === 'mask' && frame.trial === trialIndex
        ? { kind: 'miss', label: 'late', mask: true, totalMs: Math.max(MIN_SHOW_MS, frame.toMs - tapMs) }
        : { kind: 'miss', label: 'late', mask: false, totalMs: MISS_MS };
  }
}

/** The verdict under the mask at the end of an obeyed cue's praise window. */
export function maskFeedback(outcome: TrialOutcome | null, remainingMs: number): SchoolFeedback {
  const totalMs = Math.max(MIN_SHOW_MS, remainingMs);
  if (outcome === 'in_time') return { kind: 'ok', label: 'inTime', mask: true, totalMs };
  if (outcome === 'too_early') return { kind: 'miss', label: 'early', mask: true, totalMs };
  return { kind: 'miss', label: 'missed', mask: true, totalMs };
}

export type SchoolCue =
  | { kind: 'cueSound' }
  | { kind: 'announce'; what: 'obeys' }
  | { kind: 'feedback'; feedback: SchoolFeedback }
  | { kind: 'round'; round: number; totalMs: number };

/**
 * What happens when the game goes from `prev` to `next` (pure; the component plays it). After a
 * jump over several steps (a resume, the app back from the background) only `next` counts.
 */
export function schoolCues(
  session: Session,
  prev: SchoolFrame | null,
  next: SchoolFrame,
  taps: readonly number[],
  elapsedMs: number,
  resumedAtMs: number | null = null,
): SchoolCue[] {
  const same = prev !== null && prev.kind === next.kind && prev.trial === next.trial && prev.round === next.round;
  if (same) return [];
  const out: SchoolCue[] = [];
  const firsts = firstTapsBySlot(session.trials, taps);
  const newCue = (next.kind === 'listening' || next.kind === 'ignoredCue') && (prev === null || prev.trial !== next.trial || prev.kind === 'lead' || prev.kind === 'break');
  // An ignored cue that ended without a tap: »Prav, da si počakal(a)!« (a tapped one was told at the tap).
  if (prev !== null && prev.trial !== null && (prev.kind === 'ignoring' || prev.kind === 'ignoredCue') && next.trial !== prev.trial && next.kind !== 'break') {
    if (!firsts.has(prev.trial) && !missedWhileAway(session as TrainingSession, prev.trial, resumedAtMs)) {
      out.push({ kind: 'feedback', feedback: { kind: 'ok', label: 'waited', mask: false, totalMs: OK_MS } });
    }
  }
  if (newCue) out.push({ kind: 'cueSound' });
  if (next.kind === 'window') out.push({ kind: 'announce', what: 'obeys' });
  if (next.kind === 'mask' && next.trial !== null) {
    const outcome = liveOutcome(session as TrainingSession, next.trial, firsts.get(next.trial) ?? null, next.fromMs + 1);
    out.push({ kind: 'feedback', feedback: maskFeedback(outcome, next.toMs - Math.max(elapsedMs, next.fromMs)) });
  }
  if (next.kind === 'break') out.push({ kind: 'round', round: next.round + 1, totalMs: Math.max(MIN_SHOW_MS, next.toMs - Math.max(elapsedMs, next.fromMs)) });
  return out;
}

// ── Progress symbols (top bar) ────────────────────────────────

const GOOD: ReadonlySet<TrialOutcome> = new Set(['in_time', 'waited']);

/** A star per cue praised in time or rightly waited for (display only — §4.1 »Zvezdice«). */
export function isStarOutcome(outcome: TrialOutcome | null): boolean {
  return outcome !== null && GOOD.has(outcome);
}

/** Outcome per trial as known now (null = still open / missed while the app was closed). */
export function liveOutcomes(session: Session, taps: readonly number[], elapsedMs: number, resumedAtMs: number | null = null): (TrialOutcome | null)[] {
  const firsts = firstTapsBySlot(session.trials, taps);
  return session.trials.map((_, i) =>
    missedWhileAway(session as TrainingSession, i, resumedAtMs) ? null : liveOutcome(session as TrainingSession, i, firsts.get(i) ?? null, elapsedMs),
  );
}

export function starCount(outcomes: readonly (TrialOutcome | null)[], from = 0, to = outcomes.length): number {
  return outcomes.slice(from, to).filter(isStarOutcome).length;
}

/** Stars of round `r` (0-based). */
export function roundStars(session: Session, outcomes: readonly (TrialOutcome | null)[], r: number): number {
  const round = session.rounds?.[r];
  return round === undefined ? 0 : starCount(outcomes, round.first_index, round.last_index + 1);
}

// ── Early end (Š3) ────────────────────────────────────────────

/**
 * »Končaj« may be offered (and sent) at `localElapsedMs`: a v2 session, round 1 fully played here,
 * still in the break here (§4.1: »offer it in the break only«), and the server — whose clock of
 * this session runs at most `serverAheadMs` ahead, the finish's travel included — will still be
 * before round 2's start + 2 s (later it answers 422 `training_round_two_started`).
 */
export function earlyEndOpen(session: Session, localElapsedMs: number, serverAheadMs: number): boolean {
  if (!isSchoolV2Session(session)) return false;
  const rounds = session.rounds ?? [];
  const first = rounds[0];
  const second = rounds[1];
  if (first === undefined || second === undefined) return false;
  if (localElapsedMs < first.end_ms || localElapsedMs >= second.start_ms) return false;
  return localElapsedMs + Math.max(0, serverAheadMs) <= second.start_ms + EARLY_END_TOLERANCE_MS - EARLY_END_SAFETY_MS;
}

// ── Praise token (V6) ─────────────────────────────────────────

export type PraiseToken = 'treat' | 'heart';

/**
 * V6 (David 2026-10-30): a treat (bone) only when the family bought treats for this pet in
 * »Želje« (`training.treat_token`), else »Pohvali« ♥. Display only — the school never consumes a
 * treat (the 2-per-day treat rule is the home screen's).
 */
export function praiseToken(training: Pick<ChildTraining, 'treat_token'>): PraiseToken {
  return training.treat_token ? 'treat' : 'heart';
}

// ── Clarity layer (David's device test 2026-10-31) ────────────

/**
 * Praise hint (David 2026-10-31): the token glows / pulses and »Zdaj!« / "Now!" shows the moment
 * the praise window opens — in EVERY session, at any progress (David 31. 10.: he loved it in an
 * English session and missed it in a later Slovenian one, because the earlier < 50 % progress gate
 * had switched it off). Display only — nothing stored, no rule changed.
 */
export function praiseHintOn(frame: Pick<SchoolFrame, 'kind'>): boolean {
  return frame.kind === 'window';
}

/**
 * The dog visibly does not obey (the `ignoring` step, from cue + 1.6 s): the token is dimmed with a
 * »wait« look and the »don't praise, wait« chip shows. Never earlier — during `ignoredCue` the dog
 * may still be »thinking«, a dim token there would give the answer away. The token stays tappable
 * (a tap still counts as `praised_without_obeying`, server rule).
 */
export function tokenWaiting(frame: Pick<SchoolFrame, 'kind'>): boolean {
  return frame.kind === 'ignoring';
}

/**
 * Tap hint (David's device test 2026-10-31: he tried to DRAG the treat to the dog — »mogoče bi
 * lahko tak diskreten namig bil tap«): a small animated finger + »Tapni« on the token ALWAYS —
 * every session, any progress, also after the first counted tap (David 31. 10.; the earlier
 * »until the first counted tap« rule and the < 50 % gate are gone). Hidden only while the dog
 * ignores, because the token says »Počakaj« then and the two would contradict. Display only.
 */
export function tapHintOn(frame: Pick<SchoolFrame, 'kind'>): boolean {
  return frame.kind !== 'ignoring';
}

/**
 * Tap OR drag (David 2026-10-31): the praise counts at the touch-down (press-in) — timing is the
 * scored skill — once per cue (first tap decides, `addTap`). A finger that then moves at least this
 * far is a drag: the token follows the finger and the treat flies to the dog on release. A drag
 * never adds a tap and never moves the counted time.
 */
export const TOKEN_DRAG_START_PT = 8;

export function isTokenDrag(dx: number, dy: number): boolean {
  return Math.hypot(dx, dy) >= TOKEN_DRAG_START_PT;
}

/** The break's auto-continue countdown (»Nadaljuj (3)«). */
export const BREAK_COUNTDOWN_MS = 3_000;

/**
 * Whole seconds shown on »Nadaljuj« in a break (null outside one). Counted down to the NEXT round's
 * start on the server's timeline — never beyond it: a break shorter than the countdown (the server's
 * `round_break_ms` is 2.5 s) starts at the shorter value, and the game continues exactly at the round
 * start. »Nadaljuj« cannot shorten the break (the schedule is the server's).
 */
export function breakCountdown(session: Pick<TrainingSession, 'rounds'>, frame: Pick<SchoolFrame, 'kind' | 'round' | 'toMs'>, elapsedMs: number): number | null {
  if (frame.kind !== 'break') return null;
  const next = session.rounds?.[frame.round + 1];
  const start = next?.start_ms ?? frame.toMs;
  const remaining = Math.min(BREAK_COUNTDOWN_MS, Math.max(0, start - elapsedMs));
  return Math.max(1, Math.ceil(remaining / 1_000));
}

// ── Result ────────────────────────────────────────────────────

/** Result headline (David 2026-10-31): success »Bravo!«, partial »Dober začetek!«, none »Vaja dela mojstra«. */
export type SchoolResultHeadline = 'success' | 'partial' | 'none';

/** By the M5-F04 bucket (never false praise): excellent / good → success, practice → partial, none → none. */
export function resultHeadline(result: Pick<TrainingResult, 'trials'>): SchoolResultHeadline {
  switch (trainingResultBucket(result)) {
    case 'excellent':
    case 'good':
      return 'success';
    case 'practice':
      return 'partial';
    case 'none':
      return 'none';
  }
}

/** The mistake a one-line tip points at. */
export type SchoolMistake = 'notObeyed' | 'early' | 'missed';

const MISTAKE_OF: Partial<Record<TrialOutcome, SchoolMistake>> = {
  praised_without_obeying: 'notObeyed',
  too_early: 'early',
  too_late: 'missed',
  no_praise: 'missed',
};
/** On a tie: praising a dog that didn't obey is the lesson that matters most, then too early. */
const MISTAKE_ORDER: readonly SchoolMistake[] = ['notObeyed', 'early', 'missed'];

/** The most common mistake of the session (null = none). */
export function commonMistake(trials: readonly Pick<TrialResult, 'outcome'>[]): SchoolMistake | null {
  const counts = new Map<SchoolMistake, number>();
  for (const t of trials) {
    const m = MISTAKE_OF[t.outcome];
    if (m !== undefined) counts.set(m, (counts.get(m) ?? 0) + 1);
  }
  let best: SchoolMistake | null = null;
  for (const m of MISTAKE_ORDER) {
    const n = counts.get(m) ?? 0;
    if (n > 0 && (best === null || n > (counts.get(best) ?? 0))) best = m;
  }
  return best;
}

/** The result's cues grouped by round (index 0–2 → round 1, 3–5 → round 2). */
export function resultRounds<T extends { index: number }>(trials: readonly T[]): T[][] {
  const out: T[][] = [];
  for (const trial of [...trials].sort((a, b) => a.index - b.index)) {
    const r = Math.floor(trial.index / SCHOOL_TRIALS_PER_ROUND);
    (out[r] ??= []).push(trial);
  }
  return out.filter((round) => round !== undefined && round.length > 0);
}
