/**
 * Training ("Šola", M5-R03 — David 2026-10-06) on the phone: the dog learns four
 * commands — sit "Sedi", come "Pridi", place "Prostor", potty "Lulat zunaj" — in a
 * reward-timing mini-game. One completed session per family-local day is a routine.
 *
 * Sources (backend `TrainingPayload` / `PetActivityService::sessionPayload|resultPayload`):
 * - child state `training` (full, with `can_start` and the running session);
 * - parent dashboard `family.pets[].training` and `pet.updated` (summary with
 *   `session_active`);
 * - `POST /api/child/pet/training/start|finish` 200 bodies (`session` / `result`).
 * `schema.ts` types the 200 action bodies loosely, so everything is read here from
 * `unknown` into real types (never throws). A legacy pet, a pet created by an older app
 * and an older server send no / a disabled `training` → `EMPTY_TRAINING` → nothing new
 * on screen.
 *
 * Texts (`training` namespace, M1-18) are warm and never shaming: a dog that doesn't
 * obey is part of learning, a late praise is "malo prepozno", not a failure.
 */

import type { ChildPetState } from '@/api/client';
import { familyClock } from '@/modules/childPet/familyTime';
import { t } from '@/i18n';
import { strings } from '@/i18n/strings';

export type TrainingCommand = 'sit' | 'come' | 'place' | 'potty';
export const TRAINING_COMMANDS: readonly TrainingCommand[] = ['sit', 'come', 'place', 'potty'];

export type TrialOutcome = 'in_time' | 'too_early' | 'too_late' | 'no_praise' | 'waited' | 'praised_without_obeying';
const OUTCOMES: readonly TrialOutcome[] = ['in_time', 'too_early', 'too_late', 'no_praise', 'waited', 'praised_without_obeying'];

export interface CommandProgress {
  command: TrainingCommand;
  /** Displayed progress 0–100. */
  progress: number;
  learned: boolean;
  last_practised_at: string | null;
}

/** The running session as the child state shows it (no schedule). */
export interface RunningSessionInfo {
  id: string;
  command: TrainingCommand;
  started_at: string;
  ends_at: string;
  expires_at: string;
  /** Started by this child (else a sibling is training the dog). */
  mine: boolean;
  /**
   * The full schedule of the child's OWN session (PR #53: the server sends it when `mine`),
   * so the game can resume after an app restart; null for a sibling's / an older server.
   */
  schedule: TrainingSession | null;
}

/** `training` of the child state. */
export interface ChildTraining {
  enabled: boolean;
  /** All four commands in the fixed order (empty when not enabled). */
  commands: CommandProgress[];
  today_done: boolean;
  session: RunningSessionInfo | null;
  session_seconds: number;
  daily_budget_seconds: number;
  daily_budget_left_seconds: number;
  /** M5-R03b: children who can train this dog today (1 = only me). */
  children_sharing: number;
  /** M5-R03b: my fair share of the daily budget (budget / children_sharing; 0 = I can't train). */
  my_share_seconds: number;
  /** M5-R03b: my share minus my sessions today, capped by `daily_budget_left_seconds`. */
  my_seconds_left: number;
  can_start: boolean;
}

/** `training` of the parent dashboard / `pet.updated`. */
export interface PetTrainingSummary {
  enabled: boolean;
  commands: CommandProgress[];
  today_done: boolean;
  session_active: boolean;
}

export const EMPTY_TRAINING: ChildTraining = {
  enabled: false,
  commands: [],
  today_done: false,
  session: null,
  session_seconds: 50,
  daily_budget_seconds: 0,
  daily_budget_left_seconds: 0,
  children_sharing: 1,
  my_share_seconds: 0,
  my_seconds_left: 0,
  can_start: false,
};

export const EMPTY_TRAINING_SUMMARY: PetTrainingSummary = {
  enabled: false,
  commands: [],
  today_done: false,
  session_active: false,
};

/** One cue of the server's schedule (ms since the session start). */
export interface TrainingTrial {
  index: number;
  cue_at_ms: number;
  obeys: boolean;
  /** When the dog obeys (null when it doesn't this time). */
  obey_at_ms: number | null;
  /** End of the praise window (null when it doesn't obey). */
  window_end_ms: number | null;
}

/** `session` of a `training/start` 200: the schedule the app plays. */
export interface TrainingSession {
  id: string;
  command: TrainingCommand;
  started_at: string;
  ends_at: string;
  expires_at: string;
  duration_ms: number;
  praise_window_ms: number;
  /** Human reaction floor: a praise earlier than obey_at + this is too early (PR #53). */
  min_reaction_ms: number;
  trials: TrainingTrial[];
}

/** Backend `TrainingService::MIN_REACTION_MS` — used when a payload doesn't carry it. */
export const DEFAULT_MIN_REACTION_MS = 150;

export interface TrialResult {
  index: number;
  obeys: boolean;
  outcome: TrialOutcome;
  tap_ms: number | null;
}

/** `result` of a `training/finish` 200 (scored by the server). */
export interface TrainingResult {
  session_id: string;
  command: TrainingCommand;
  /** Correctly timed praises (each one is progress). */
  successes: number;
  /** Cues the dog obeyed. */
  obeyed: number;
  trials: TrialResult[];
  progress_before: number;
  progress_after: number;
  /** Precise gain in percentage points. */
  progress_gain: number;
}

export type TrainingActionStatus = 'accepted' | 'unchanged';

export interface StartTrainingResponse {
  status: TrainingActionStatus;
  session: TrainingSession;
  state: ChildPetState;
}

export interface FinishTrainingResponse {
  status: TrainingActionStatus;
  result: TrainingResult;
  state: ChildPetState;
}

// ── Readers (unknown → typed, never throw) ────────────────────

type Obj = Record<string, unknown>;

function isObj(value: unknown): value is Obj {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function iso(value: unknown): string | null {
  return typeof value === 'string' && value.length > 0 && !Number.isNaN(Date.parse(value)) ? value : null;
}

function int(value: unknown): number | null {
  const n = typeof value === 'number' ? value : typeof value === 'string' && value !== '' ? Number(value) : Number.NaN;
  return Number.isFinite(n) ? Math.round(n) : null;
}

function num(value: unknown, fallback = 0): number {
  const n = typeof value === 'number' ? value : typeof value === 'string' && value !== '' ? Number(value) : Number.NaN;
  return Number.isFinite(n) ? n : fallback;
}

export function isTrainingCommand(value: unknown): value is TrainingCommand {
  return typeof value === 'string' && (TRAINING_COMMANDS as readonly string[]).includes(value);
}

function isOutcome(value: unknown): value is TrialOutcome {
  return typeof value === 'string' && (OUTCOMES as readonly string[]).includes(value);
}

function clampProgress(value: number): number {
  return Math.max(0, Math.min(100, Math.round(value)));
}

/** Commands in the fixed order (sit, come, place, potty); unknown entries dropped, missing ones at 0 %. */
function readCommands(value: unknown): CommandProgress[] {
  if (!Array.isArray(value)) return [];
  const byCommand = new Map<TrainingCommand, CommandProgress>();
  for (const item of value) {
    if (!isObj(item) || !isTrainingCommand(item.command) || byCommand.has(item.command)) continue;
    const progress = clampProgress(num(item.progress));
    byCommand.set(item.command, {
      command: item.command,
      progress,
      learned: item.learned === true || progress >= 100,
      last_practised_at: iso(item.last_practised_at),
    });
  }
  if (byCommand.size === 0) return [];
  return TRAINING_COMMANDS.map(
    (command) => byCommand.get(command) ?? { command, progress: 0, learned: false, last_practised_at: null },
  );
}

function readRunningSession(value: unknown): RunningSessionInfo | null {
  if (!isObj(value) || typeof value.id !== 'string' || value.id === '' || !isTrainingCommand(value.command)) return null;
  const started = iso(value.started_at);
  const ends = iso(value.ends_at);
  const expires = iso(value.expires_at);
  if (started === null || ends === null || expires === null) return null;
  const mine = value.mine === true;
  return {
    id: value.id,
    command: value.command,
    started_at: started,
    ends_at: ends,
    expires_at: expires,
    mine,
    // Only the own session's schedule is playable; a partial one is never guessed.
    schedule: mine ? readTrainingSession(value) : null,
  };
}

/** Child state `training`; missing / malformed / disabled → `EMPTY_TRAINING` (legacy, older server). */
export function readChildTraining(raw: unknown): ChildTraining {
  if (!isObj(raw) || raw.enabled !== true) return EMPTY_TRAINING;
  const sessionSeconds = num(raw.session_seconds, EMPTY_TRAINING.session_seconds);
  const budget = Math.max(0, num(raw.daily_budget_seconds));
  const budgetLeft = Math.max(0, num(raw.daily_budget_left_seconds));
  // A pre-M5-R03b server sends no share: the whole budget is "mine" (old behaviour).
  const myLeft = Math.max(0, num(raw.my_seconds_left, budgetLeft));
  return {
    enabled: true,
    commands: readCommands(raw.commands),
    today_done: raw.today_done === true,
    session: readRunningSession(raw.session),
    session_seconds: sessionSeconds > 0 ? sessionSeconds : EMPTY_TRAINING.session_seconds,
    daily_budget_seconds: budget,
    daily_budget_left_seconds: budgetLeft,
    children_sharing: Math.max(1, Math.round(num(raw.children_sharing, 1))),
    my_share_seconds: Math.max(0, num(raw.my_share_seconds, budget)),
    my_seconds_left: Math.min(myLeft, budgetLeft),
    can_start: raw.can_start === true,
  };
}

/** Parent dashboard / broadcast `training`; missing or disabled → `EMPTY_TRAINING_SUMMARY`. */
export function readPetTraining(raw: unknown): PetTrainingSummary {
  if (!isObj(raw) || raw.enabled !== true) return EMPTY_TRAINING_SUMMARY;
  return {
    enabled: true,
    commands: readCommands(raw.commands),
    today_done: raw.today_done === true,
    session_active: raw.session_active === true,
  };
}

function readTrial(value: unknown): TrainingTrial | null {
  if (!isObj(value)) return null;
  const index = int(value.index);
  const cue = int(value.cue_at_ms);
  if (index === null || cue === null || cue < 0) return null;
  const obeys = value.obeys === true;
  const obeyAt = obeys ? int(value.obey_at_ms) : null;
  const windowEnd = obeys ? int(value.window_end_ms) : null;
  if (obeys && (obeyAt === null || windowEnd === null || obeyAt < cue || windowEnd < obeyAt)) return null;
  return { index, cue_at_ms: cue, obeys, obey_at_ms: obeyAt, window_end_ms: windowEnd };
}

/** The schedule of a start response, or null when it is not a playable one. */
export function readTrainingSession(raw: unknown): TrainingSession | null {
  if (!isObj(raw) || typeof raw.id !== 'string' || raw.id === '' || !isTrainingCommand(raw.command)) return null;
  const started = iso(raw.started_at);
  const ends = iso(raw.ends_at);
  const expires = iso(raw.expires_at);
  const duration = int(raw.duration_ms);
  const window = int(raw.praise_window_ms);
  const minReaction = int(raw.min_reaction_ms);
  if (started === null || ends === null || expires === null || duration === null || duration <= 0 || window === null || window <= 0) {
    return null;
  }
  if (!Array.isArray(raw.trials)) return null;
  const trials: TrainingTrial[] = [];
  for (const item of raw.trials) {
    const trial = readTrial(item);
    // One malformed cue makes the local schedule untrustworthy — never guess.
    if (trial === null || trial.cue_at_ms > duration) return null;
    trials.push(trial);
  }
  if (trials.length === 0) return null;
  trials.sort((a, b) => a.cue_at_ms - b.cue_at_ms);
  return {
    id: raw.id,
    command: raw.command,
    started_at: started,
    ends_at: ends,
    expires_at: expires,
    duration_ms: duration,
    praise_window_ms: window,
    min_reaction_ms: minReaction !== null && minReaction >= 0 && minReaction < window ? minReaction : DEFAULT_MIN_REACTION_MS,
    trials,
  };
}

/** The server's scored result of a finish response, or null. */
export function readTrainingResult(raw: unknown): TrainingResult | null {
  if (!isObj(raw) || typeof raw.session_id !== 'string' || !isTrainingCommand(raw.command)) return null;
  const trials = Array.isArray(raw.trials)
    ? raw.trials.flatMap((item): TrialResult[] => {
        if (!isObj(item) || !isOutcome(item.outcome)) return [];
        const index = int(item.index);
        if (index === null) return [];
        return [{ index, obeys: item.obeys === true, outcome: item.outcome, tap_ms: int(item.tap_ms) }];
      })
    : [];
  return {
    session_id: raw.session_id,
    command: raw.command,
    successes: Math.max(0, int(raw.successes) ?? 0),
    obeyed: Math.max(0, int(raw.obeyed) ?? 0),
    trials,
    progress_before: clampProgress(num(raw.progress_before)),
    progress_after: clampProgress(num(raw.progress_after)),
    progress_gain: Math.max(0, num(raw.progress_gain)),
  };
}

function status(value: unknown): TrainingActionStatus | null {
  return value === 'accepted' || value === 'unchanged' ? value : null;
}

/** A child state object (the caller normalises it); just "an object with a pet". */
function stateOf(value: unknown): ChildPetState | null {
  return isObj(value) && isObj(value.pet) ? (value as unknown as ChildPetState) : null;
}

/** `POST /api/child/pet/training/start` 200 body; null when it isn't one (→ treat as a failure). */
export function readStartResponse(raw: unknown): StartTrainingResponse | null {
  if (!isObj(raw)) return null;
  const s = status(raw.status);
  const session = readTrainingSession(raw.session);
  const state = stateOf(raw.state);
  return s !== null && session !== null && state !== null ? { status: s, session, state } : null;
}

/** `POST /api/child/pet/training/finish` 200 body; null when it isn't one. */
export function readFinishResponse(raw: unknown): FinishTrainingResponse | null {
  if (!isObj(raw)) return null;
  const s = status(raw.status);
  const result = readTrainingResult(raw.result);
  const state = stateOf(raw.state);
  return s !== null && result !== null && state !== null ? { status: s, result, state } : null;
}

// ── Result title (M5-F04) ─────────────────────────────────────

/**
 * How the session went, for the result title (M5-F04, thresholds confirmed by David
 * 2026-10-07 21:35). Never false praise: a weak session never says "Great job".
 * - `excellent` — every command praised on time ("Odlično!")
 * - `good` — more than half ("Dobro!")
 * - `practice` — at least one, but half or fewer ("Še malo vaje — jutri bo bolje")
 * - `none` — no praise on time ("Tokrat ni šlo, poskusi jutri")
 */
export type TrainingResultBucket = 'excellent' | 'good' | 'practice' | 'none';

/**
 * ratio = praises on time (`successes`, scored by the server) / all commands of the session
 * (`trials.length`). 1 → excellent; > 0.5 → good; > 0 → practice; 0 → none. **Exactly half
 * is `practice`** ("more than half" is good). No commands (malformed / empty result) → none.
 * Pure: integer comparisons only (no float rounding at the boundaries).
 */
export function trainingResultBucket(result: Pick<TrainingResult, 'successes' | 'trials'>): TrainingResultBucket {
  const total = result.trials.length;
  if (total <= 0) return 'none';
  const onTime = Math.min(Math.max(0, Math.floor(result.successes)), total);
  if (onTime === 0) return 'none';
  if (onTime === total) return 'excellent';
  return onTime * 2 > total ? 'good' : 'practice';
}

// ── Questions the UI asks ─────────────────────────────────────

/** The HUD shows the "Šola" entry. */
export function showTrainingEntry(training: ChildTraining): boolean {
  return training.enabled && training.commands.length > 0;
}

/**
 * Whole sessions THIS child may still do today (M5-R03b): my share of the 5 min / 50 s,
 * i.e. `my_seconds_left` (already capped by what the dog has left).
 */
export function sessionsLeftToday(training: ChildTraining): number {
  if (training.session_seconds <= 0) return 0;
  return Math.floor(Math.min(training.my_seconds_left, training.daily_budget_left_seconds) / training.session_seconds);
}

/** Whole sessions the dog may still do today, whoever trains (pet-wide budget). */
function petSessionsLeftToday(training: ChildTraining): number {
  if (training.session_seconds <= 0) return 0;
  return Math.floor(training.daily_budget_left_seconds / training.session_seconds);
}

/** The daily time is split between siblings (show the "deliš" hint). */
export function isTimeShared(training: ChildTraining): boolean {
  return training.enabled && training.children_sharing > 1;
}

/**
 * The HUD chip's amber "today's practice is waiting" dot: the routine isn't done today
 * AND I still have time to train (not shown to a child whose share is used up — they
 * couldn't act on it). A sibling's running session doesn't hide it (that's only a minute).
 */
export function showTrainingDot(training: ChildTraining): boolean {
  return !training.today_done && sessionsLeftToday(training) > 0;
}

export type StartBlock =
  | 'sibling_training'
  | 'own_session_closing'
  | 'budget_used'
  | 'share_used'
  | 'day_ending'
  | 'unavailable'
  | null;

/** A session started now (+ its 60 s finish TTL) would run past the family midnight (PR #53). */
export const DAY_END_RESERVE_MS = 60_000;

export function isDayEnding(training: ChildTraining, serverNowMs: number, nextMidnightMs: number): boolean {
  return serverNowMs + training.session_seconds * 1000 + DAY_END_RESERVE_MS > nextMidnightMs;
}

/**
 * Why "Začni vajo" is disabled (null = it isn't). The server decides; this only explains
 * `can_start`. `dayEnding` = `isDayEnding(…)` with the family clock.
 */
export function startBlock(training: ChildTraining, dayEnding = false): StartBlock {
  if (training.can_start) return null;
  if (training.session !== null) return training.session.mine ? 'own_session_closing' : 'sibling_training';
  // Same order as the server: the dog's whole budget first, then my share of it.
  if (training.enabled && petSessionsLeftToday(training) === 0) return 'budget_used';
  if (training.enabled && sessionsLeftToday(training) === 0) return 'share_used';
  if (dayEnding) return 'day_ending';
  return 'unavailable';
}

// ── Text (`training:child`, M1-18) ────────────────────────────

/** Per command: `name` (button / list), `cue` (what the child "says"), `obeys`, `ignores`, `lower` (parent line "sedi ✓"). */
export const TRAINING_STRINGS = strings('training', 'child', {
  entryA11y: (todayDone: boolean) => (todayDone ? t('training:child.entryA11yDone') : t('training:child.entryA11yOpen')),
  /** "60 %" / "60%". */
  percent: (value: number) => t('training:child.percent', { value }),
  sessionsLeft: (n: number) => t('training:child.sessionsLeft', { count: n }),
  sessionLength: (seconds: number) => t('training:child.sessionLength', { seconds }),
  /** M5-R03b: the daily time is split fairly between the children of the dog. */
  sharedTime: (children: number) =>
    children === 2 ? t('training:child.sharedTimeSibling') : t('training:child.sharedTimeSiblings'),
  startA11y: (name: string) => t('training:child.startA11y', { name }),
  secondsLeft: (seconds: number) => t('training:child.secondsLeft', { seconds }),
  trialOf: (n: number, total: number) => t('training:child.trialOf', { n, total }),
  result: {
    successes: (successes: number, obeyed: number) =>
      obeyed === 0 ? t('training:child.result.noObey') : t('training:child.result.successes', { successes, obeyed }),
    gain: (name: string, before: number, after: number) => t('training:child.result.gain', { name, before, after }),
    gainSmall: (name: string, after: number) => t('training:child.result.gainSmall', { name, after }),
    learned: (name: string) => t('training:child.result.learned', { name }),
  },
  errors: {
    training_session_active_until: (clock: string) => t('training:child.errors.training_session_active_until', { clock }),
  },
});

/** Every block, outcome and command has its texts (compile-time check). */
const _trainingTexts: {
  blocked: Record<Exclude<StartBlock, null>, string>;
  feedback: Record<TrialOutcome, string>;
  outcomeShort: Record<TrialOutcome, string>;
  commands: Record<TrainingCommand, { name: string; cue: string; obeys: string; ignores: string; lower: string }>;
} = TRAINING_STRINGS;
void _trainingTexts;

/** Refusal reasons of the two training endpoints (backend `CareRefusal`). */
export type TrainingRefusal = Exclude<keyof typeof TRAINING_STRINGS.errors, 'training_session_active_until' | 'expiredWhileAway' | 'badResponse'>;

const REFUSALS: readonly TrainingRefusal[] = [
  'training_not_available',
  'training_session_active',
  'training_daily_budget_used',
  'training_child_share_used',
  'training_session_invalid',
  'training_session_not_over',
  'training_session_expired',
  'training_invalid_taps',
  'training_day_ending',
  'training_session_interrupted',
];

export function isTrainingRefusal(value: unknown): value is TrainingRefusal {
  return typeof value === 'string' && (REFUSALS as readonly string[]).includes(value);
}

/** Text for a 422 of start / finish; `next_allowed_at` names the time for a sibling's session. */
export function trainingRefusalMessage(reason: string | null, nextAllowedAt: string | null, timezone: string | null): string {
  if (reason === 'training_session_active') {
    const clock = familyClock(nextAllowedAt, timezone);
    return clock ? TRAINING_STRINGS.errors.training_session_active_until(clock) : TRAINING_STRINGS.errors.training_session_active;
  }
  return isTrainingRefusal(reason) ? TRAINING_STRINGS.errors[reason] : TRAINING_STRINGS.errors.badResponse;
}

/** "Kuža zna: sedi ✓, pridi 60 %, prostor 0 %, lulat zunaj 10 %" (parent). Empty commands → null. */
export function knowsLine(commands: readonly CommandProgress[]): string | null {
  if (commands.length === 0) return null;
  const parts = commands.map((c) => {
    const name = TRAINING_STRINGS.commands[c.command].lower;
    return c.learned
      ? t('training:parent.knowsLearned', { name })
      : t('training:parent.knowsProgress', { name, value: c.progress });
  });
  return t('training:parent.knows', { list: parts.join(', ') });
}

/** Parent texts (`training:parent`, M1-18). */
export const PARENT_TRAINING_STRINGS = strings('training', 'parent');

/** Parent lines for a pet with training; empty for a legacy pet / older server. */
export function parentTrainingLines(training: PetTrainingSummary): string[] {
  if (!training.enabled) return [];
  const knows = knowsLine(training.commands);
  return [
    ...(knows !== null ? [knows] : []),
    training.today_done ? PARENT_TRAINING_STRINGS.todayDone : PARENT_TRAINING_STRINGS.todayOpen,
    ...(training.session_active ? [PARENT_TRAINING_STRINGS.sessionActive] : []),
  ];
}
