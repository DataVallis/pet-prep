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
 * Texts are Slovenian (i18n with M1-18), warm and never shaming: a dog that doesn't
 * obey is part of learning, a late praise is "malo prepozno", not a failure.
 */

import type { ChildPetState } from '@/api/client';
import { familyClock } from '@/modules/childPet/familyTime';

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
  return {
    enabled: true,
    commands: readCommands(raw.commands),
    today_done: raw.today_done === true,
    session: readRunningSession(raw.session),
    session_seconds: sessionSeconds > 0 ? sessionSeconds : EMPTY_TRAINING.session_seconds,
    daily_budget_seconds: Math.max(0, num(raw.daily_budget_seconds)),
    daily_budget_left_seconds: Math.max(0, num(raw.daily_budget_left_seconds)),
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

// ── Questions the UI asks ─────────────────────────────────────

/** The HUD shows the "Šola" entry. */
export function showTrainingEntry(training: ChildTraining): boolean {
  return training.enabled && training.commands.length > 0;
}

/** Whole sessions the dog may still do today (5 min budget / 50 s). */
export function sessionsLeftToday(training: ChildTraining): number {
  if (training.session_seconds <= 0) return 0;
  return Math.floor(training.daily_budget_left_seconds / training.session_seconds);
}

export type StartBlock = 'sibling_training' | 'own_session_closing' | 'budget_used' | 'day_ending' | 'unavailable' | null;

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
  if (training.enabled && sessionsLeftToday(training) === 0) return 'budget_used';
  if (dayEnding) return 'day_ending';
  return 'unavailable';
}

// ── Slovenian text ────────────────────────────────────────────

interface CommandText {
  /** Button / list name. */
  name: string;
  /** What the child "says" (the cue bubble). */
  cue: string;
  /** The dog obeys. */
  obeys: string;
  /** The dog doesn't obey this time. */
  ignores: string;
  /** Parent line ("sedi ✓"). */
  lower: string;
}

export const TRAINING_STRINGS = {
  entry: 'Šola',
  entryA11y: (todayDone: boolean) => (todayDone ? 'Šola — današnja vaja je opravljena' : 'Šola — današnja vaja te še čaka'),
  title: 'Šola',
  close: 'Zapri',
  intro: 'Izberi ukaz. Ko kuža uboga, ga takoj pohvali!',
  howTo: 'Kuža ne uboga vsakič. Če ne uboga, počakaj — pohvala šteje samo, ko uboga.',
  learned: 'naučeno',
  todayDone: 'Današnja vaja: opravljena ✓',
  todayOpen: 'Današnja vaja te še čaka.',
  sessionsLeft: (n: number) =>
    n === 1 ? 'Danes še 1 vaja.' : n === 2 ? 'Danes še 2 vaji.' : n === 3 || n === 4 ? `Danes še ${n} vaje.` : `Danes še ${n} vaj.`,
  sessionLength: (seconds: number) => `Vaja traja ${seconds} s.`,
  start: 'Začni vajo',
  startA11y: (name: string) => `Začni vajo: ${name}`,
  blocked: {
    sibling_training: 'Nekdo drug zdaj vadi s kužkom. Poskusi čez minutko.',
    own_session_closing: 'Prejšnja vaja se še zaključuje. Poskusi čez minutko.',
    budget_used: 'Kuža je danes že dovolj vadil. Jutri spet!',
    day_ending: 'Dan se izteka — kuža gre spat. Nova vaja jutri!',
    unavailable: 'Zdaj ni čas za vajo.',
  } satisfies Record<Exclude<StartBlock, null>, string>,
  starting: 'Kuža se pripravlja …',
  getReady: 'Pripravi se …',
  listening: 'Kuža posluša …',
  praise: 'Pohvali',
  praiseA11y: 'Pohvali kužka',
  secondsLeft: (s: number) => `še ${s} s`,
  trialOf: (n: number, total: number) => `Ukaz ${n} od ${total}`,
  finishing: 'Shranjujem vajo …',
  resumed: 'Nadaljujemo vajo, ki se je začela prej.',
  feedback: {
    in_time: 'Bravo, ob pravem trenutku!',
    too_early: 'Prezgodaj — počakaj, da kuža uboga.',
    too_late: 'Malo prepozno — pohvali takoj, ko uboga.',
    no_praise: 'Kuža je ubogal — naslednjič ga pohvali!',
    waited: 'Super, da si počakal(a) — kuža tokrat ni ubogal.',
    praised_without_obeying: 'Kuža še ni ubogal — pohvali, ko uboga.',
  } satisfies Record<TrialOutcome, string>,
  outcomeShort: {
    in_time: 'pravočasno',
    too_early: 'prezgodaj',
    too_late: 'prepozno',
    no_praise: 'brez pohvale',
    waited: 'počakal(a)',
    praised_without_obeying: 'ni ubogal',
  } satisfies Record<TrialOutcome, string>,
  result: {
    titleGood: 'Bravo!',
    titleLearning: 'Kuža se uči!',
    successes: (successes: number, obeyed: number) =>
      obeyed === 0 ? 'Kuža tokrat ni ubogal.' : `Pravočasne pohvale: ${successes} od ${obeyed}.`,
    gain: (name: string, before: number, after: number) => `${name}: ${before} % → ${after} %`,
    gainSmall: (name: string, after: number) => `${name}: ${after} % — kuža je malo bližje.`,
    noGain: 'Napredka tokrat ni bilo — jutri bo šlo bolje. Pohvali takoj, ko kuža uboga.',
    learned: (name: string) => `Kuža zna ukaz »${name}«!`,
    routineDone: 'Današnja vaja je opravljena.',
    /** `unchanged`: the server returned a session it had already saved. */
    titleStored: 'Ta vaja je že shranjena',
    stored: 'Tukaj je njen rezultat.',
    again: 'Nazaj v šolo',
    done: 'Končano',
  },
  errors: {
    training_not_available: 'Šola za tega kužka ni na voljo.',
    training_session_active: 'Nekdo že vadi s kužkom. Poskusi čez minutko.',
    training_session_active_until: (clock: string) => `Nekdo že vadi s kužkom. Poskusi spet ob ${clock}.`,
    training_daily_budget_used: 'Kuža je danes že dovolj vadil. Jutri spet!',
    training_session_invalid: 'Te vaje ni več. Začni novo vajo.',
    training_session_not_over: 'Vaja še ni čisto končana. Poskusi znova.',
    training_session_expired: 'Vaja se je iztekla, preden smo jo shranili. Napredek tokrat ni zapisan — začni novo vajo.',
    training_invalid_taps: 'Pri štetju je šlo nekaj narobe. Začni novo vajo.',
    training_day_ending: 'Dan se izteka — kuža gre spat. Nova vaja jutri!',
    training_session_interrupted: 'Vaja je bila prekinjena, zato tokrat ne šteje. Čas za vajo ti ostane — poskusi znova, ko bo kuža spet prost.',
    expiredWhileAway: 'Vaja se je iztekla, ker je bila aplikacija zaprta. Napredek tokrat ni zapisan — začni novo vajo.',
    badResponse: 'Nekaj je šlo narobe. Poskusi znova.',
  },
  retry: 'Poskusi znova',
  commands: {
    sit: { name: 'Sedi', cue: 'Sedi!', obeys: 'Kuža se usede.', ignores: 'Kuža voha naokrog …', lower: 'sedi' },
    come: { name: 'Pridi', cue: 'Pridi!', obeys: 'Kuža priteče k tebi.', ignores: 'Kuža ostane, kjer je …', lower: 'pridi' },
    place: { name: 'Prostor', cue: 'Prostor!', obeys: 'Kuža gre na svoj prostor.', ignores: 'Kuža se raje igra …', lower: 'prostor' },
    potty: { name: 'Lulat zunaj', cue: 'Lulat zunaj!', obeys: 'Kuža lula zunaj.', ignores: 'Kuža gleda metuljčka …', lower: 'lulat zunaj' },
  } satisfies Record<TrainingCommand, CommandText>,
} as const;

/** Refusal reasons of the two training endpoints (backend `CareRefusal`). */
export type TrainingRefusal = Exclude<keyof typeof TRAINING_STRINGS.errors, 'training_session_active_until' | 'expiredWhileAway' | 'badResponse'>;

const REFUSALS: readonly TrainingRefusal[] = [
  'training_not_available',
  'training_session_active',
  'training_daily_budget_used',
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
  const parts = commands.map((c) =>
    c.learned ? `${TRAINING_STRINGS.commands[c.command].lower} ✓` : `${TRAINING_STRINGS.commands[c.command].lower} ${c.progress} %`,
  );
  return `Kuža zna: ${parts.join(', ')}`;
}

export const PARENT_TRAINING_STRINGS = {
  title: 'Šola',
  todayDone: 'Današnja vaja: opravljena ✓',
  todayOpen: 'Današnja vaja: še ne',
  sessionActive: 'Otrok zdaj vadi s kužkom.',
} as const;

/** Parent lines for a pet with training; empty for a legacy pet / older server. */
export function parentTrainingLines(t: PetTrainingSummary): string[] {
  if (!t.enabled) return [];
  const knows = knowsLine(t.commands);
  return [
    ...(knows !== null ? [knows] : []),
    t.today_done ? PARENT_TRAINING_STRINGS.todayDone : PARENT_TRAINING_STRINGS.todayOpen,
    ...(t.session_active ? [PARENT_TRAINING_STRINGS.sessionActive] : []),
  ];
}
