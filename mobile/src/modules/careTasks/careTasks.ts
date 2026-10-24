/**
 * Breed care tasks (M5-R14 / R15 / R18 / R19 / R20, David 2026-10-24).
 *
 * Child tasks — mini-games on the brushing model: »Umij zobke« (teeth), »Obriši gubice«
 * (face / skin folds), »Očisti ušesa« (ears) on the brush scratch card (M5-T14 `BrushArea`),
 * »Pristriži kremplje« (nails) = tap each claw. Parent reminders — groomer visit, bath — are
 * confirmed in the parent app and never scored. The summer heat warning — flat-faced breeds,
 * 11:00–16:00 in June–August (southern hemisphere December–February), no weather data.
 *
 * Whether a dog has a task, how often and whether it may start now is the SERVER's
 * (`care_tasks` of the child state / `PetUpdated`, `care_reminders` of the dashboard,
 * `walk_heat`): only breeds whose source states a frequency get one. No rule is re-derived
 * here — this module reads the blocks, merges broadcasts and words the texts (`careTasks:`).
 */

import { t } from '@/i18n';
import { strings } from '@/i18n/strings';
import type { CatSessionBase } from '@/modules/catCare/catCare';

export const CARE_TASKS = ['tooth_brushing', 'fold_cleaning', 'ear_cleaning', 'nail_trim'] as const;
export type CareTaskKey = (typeof CARE_TASKS)[number];

export const CARE_REMINDERS = ['groomer_visit', 'bath'] as const;
export type CareReminderKey = (typeof CARE_REMINDERS)[number];

/** The three brush tasks (scratch card); the nails are a tap game. */
export type BrushCareTask = Exclude<CareTaskKey, 'nail_trim'>;

/** `session` of `care-task/start` (and of `care_tasks[].session` in the child state). */
export interface CareTaskSession extends CatSessionBase {
  task: CareTaskKey;
  min_strokes: number;
  segments: number;
  min_stroke_interval_ms: number;
}

export type CareTaskFailReason = 'too_few_strokes' | 'not_spread' | 'too_uniform';

export interface CareTaskResult {
  session_id: string;
  success: boolean;
  reason: CareTaskFailReason | null;
  strokes: number;
  min_strokes: number;
  segments_hit: number;
  segments: number;
}

/** One entry of the child state's `care_tasks`. */
export interface ChildCareTask {
  task: CareTaskKey;
  /** `times` per `period_days` program days (the breed's sourced frequency). */
  times: number;
  period_days: number;
  done_this_period: number;
  period_started_at: string | null;
  period_ends_at: string | null;
  session_seconds: number;
  next_allowed_at: string | null;
  blocked_reason: string | null;
  can_start: boolean;
  session: CareTaskSession | null;
  session_running: boolean;
}

/** `walk_heat` of the child state: today's summer midday window (family offset). */
export interface WalkHeat {
  starts_at: string;
  ends_at: string;
  active: boolean;
}

/** One entry of the dashboard pet's `care_reminders`. */
export interface CareReminder {
  task: CareReminderKey;
  period_days: number;
  period_started_at: string | null;
  period_ends_at: string | null;
  done: boolean;
  done_at: string | null;
}

/** One row of the report's `care_reminders`. */
export interface CareReminderReportRow {
  task: CareReminderKey;
  period_started_at: string | null;
  period_ends_at: string | null;
  done_at: string | null;
  status: 'done' | 'open' | 'not_done';
}

function isObj(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function int(value: unknown): number | null {
  return typeof value === 'number' && Number.isFinite(value) ? Math.round(value) : null;
}

function nonNeg(value: unknown, fallback = 0): number {
  const n = int(value);
  return n !== null && n >= 0 ? n : fallback;
}

function iso(value: unknown): string | null {
  return typeof value === 'string' && value.length > 0 && Number.isFinite(Date.parse(value)) ? value : null;
}

export function isCareTask(value: unknown): value is CareTaskKey {
  return typeof value === 'string' && (CARE_TASKS as readonly string[]).includes(value);
}

export function isCareReminder(value: unknown): value is CareReminderKey {
  return typeof value === 'string' && (CARE_REMINDERS as readonly string[]).includes(value);
}

export function isBrushTask(task: CareTaskKey): task is BrushCareTask {
  return task !== 'nail_trim';
}

export function readCareTaskSession(raw: unknown, expected?: CareTaskKey): CareTaskSession | null {
  if (!isObj(raw) || !isCareTask(raw.task) || (expected !== undefined && raw.task !== expected)) return null;
  const id = typeof raw.id === 'string' && raw.id.length > 0 ? raw.id : null;
  const started = iso(raw.started_at);
  const ends = iso(raw.ends_at);
  const expires = iso(raw.expires_at);
  const duration = int(raw.duration_ms);
  if (id === null || started === null || ends === null || expires === null || duration === null || duration <= 0) return null;
  return {
    id,
    task: raw.task,
    started_at: started,
    ends_at: ends,
    expires_at: expires,
    duration_ms: duration,
    min_strokes: Math.max(1, nonNeg(raw.min_strokes, 10)),
    segments: Math.max(1, nonNeg(raw.segments, 1)),
    min_stroke_interval_ms: nonNeg(raw.min_stroke_interval_ms, 150),
  };
}

const FAIL_REASONS: readonly CareTaskFailReason[] = ['too_few_strokes', 'not_spread', 'too_uniform'];

export function readCareTaskResult(raw: unknown): CareTaskResult | null {
  if (!isObj(raw) || typeof raw.session_id !== 'string' || typeof raw.success !== 'boolean') return null;
  return {
    session_id: raw.session_id,
    success: raw.success,
    reason: typeof raw.reason === 'string' && (FAIL_REASONS as readonly string[]).includes(raw.reason) ? (raw.reason as CareTaskFailReason) : null,
    strokes: nonNeg(raw.strokes),
    min_strokes: nonNeg(raw.min_strokes),
    segments_hit: nonNeg(raw.segments_hit),
    segments: nonNeg(raw.segments),
  };
}

function readOne(raw: unknown): ChildCareTask | null {
  if (!isObj(raw) || !isCareTask(raw.task)) return null;
  const times = nonNeg(raw.times);
  const days = nonNeg(raw.period_days);
  if (times <= 0 || days <= 0) return null;
  return {
    task: raw.task,
    times,
    period_days: days,
    done_this_period: nonNeg(raw.done_this_period),
    period_started_at: iso(raw.period_started_at),
    period_ends_at: iso(raw.period_ends_at),
    session_seconds: Math.max(1, nonNeg(raw.session_seconds, 30)),
    next_allowed_at: iso(raw.next_allowed_at),
    blocked_reason: typeof raw.blocked_reason === 'string' ? raw.blocked_reason : null,
    can_start: raw.can_start === true,
    session: readCareTaskSession(raw.session, raw.task),
    session_running: raw.session_running === true,
  };
}

/** The child state's / broadcast's `care_tasks`; [] = none (or an older server). One entry per task, in CARE_TASKS order. */
export function readChildCareTasks(raw: unknown): ChildCareTask[] {
  if (!Array.isArray(raw)) return [];
  const seen = new Map<CareTaskKey, ChildCareTask>();
  for (const item of raw) {
    const task = readOne(item);
    if (task !== null && !seen.has(task.task)) seen.set(task.task, task);
  }
  return CARE_TASKS.flatMap((k) => (seen.has(k) ? [seen.get(k) as ChildCareTask] : []));
}

/**
 * `care_tasks` after a `PetUpdated` (pet level: `can_start` ignores this child's contract and
 * own session). A missing key (older server) keeps the list (`can_start` off while locked).
 * The child's own running session stays while the server says one runs (like the cat games).
 */
export function broadcastCareTasks(current: readonly ChildCareTask[], raw: unknown, locked: boolean): ChildCareTask[] {
  if (raw === undefined) return current.map((c) => (locked ? { ...c, can_start: false } : c));
  return readChildCareTasks(raw).map((next) => {
    const prev = current.find((c) => c.task === next.task) ?? null;
    if (prev !== null && prev.session !== null && next.session_running) {
      return { ...next, session: prev.session, blocked_reason: prev.blocked_reason, can_start: prev.can_start && !locked };
    }
    return { ...next, session: null, can_start: next.can_start && !locked };
  });
}

export function readWalkHeat(raw: unknown): WalkHeat | null {
  if (!isObj(raw)) return null;
  const starts = iso(raw.starts_at);
  const ends = iso(raw.ends_at);
  if (starts === null || ends === null) return null;
  return { starts_at: starts, ends_at: ends, active: raw.active === true };
}

/** The heat warning is on at `nowMs` (the window by the clock — the server's `active` is only its snapshot). */
export function heatWarningActive(heat: WalkHeat | null, nowMs: number): boolean {
  if (heat === null) return false;
  const from = Date.parse(heat.starts_at);
  const to = Date.parse(heat.ends_at);
  return Number.isFinite(from) && Number.isFinite(to) && nowMs >= from && nowMs < to;
}

/** The next moment the heat warning turns on or off after `nowMs` (for a re-render); null = none today. */
export function nextHeatChangeMs(heat: WalkHeat | null, nowMs: number): number | null {
  if (heat === null) return null;
  const marks = [Date.parse(heat.starts_at), Date.parse(heat.ends_at)].filter((ms) => Number.isFinite(ms) && ms > nowMs);
  return marks.length > 0 ? Math.min(...marks) : null;
}

export function readCareReminders(raw: unknown): CareReminder[] {
  if (!Array.isArray(raw)) return [];
  return raw.flatMap((r): CareReminder[] =>
    isObj(r) && isCareReminder(r.task) && nonNeg(r.period_days) > 0
      ? [
          {
            task: r.task,
            period_days: nonNeg(r.period_days),
            period_started_at: iso(r.period_started_at),
            period_ends_at: iso(r.period_ends_at),
            done: r.done === true,
            done_at: iso(r.done_at),
          },
        ]
      : [],
  );
}

export function readCareReminderReport(raw: unknown): CareReminderReportRow[] {
  if (!Array.isArray(raw)) return [];
  return raw.flatMap((r): CareReminderReportRow[] =>
    isObj(r) && isCareReminder(r.task) && (r.status === 'done' || r.status === 'open' || r.status === 'not_done')
      ? [{ task: r.task, period_started_at: iso(r.period_started_at), period_ends_at: iso(r.period_ends_at), done_at: iso(r.done_at), status: r.status }]
      : [],
  );
}

/** The HUD chip of a task: this period still has an open slot and the pet is not locked (the overlay explains a block). */
export function showCareTaskChip(task: ChildCareTask, locked: boolean): boolean {
  return !locked && (task.done_this_period < task.times || task.session !== null);
}

/** The task's session finishes by itself when the time is up; the brush tasks / nails may end a little earlier once done. */
export const CARE_EARLY_FINISH_LEAD_MS = 2_000;

// ── Texts (namespace `careTasks`, EN + SL) ────────────────────

export const CARE_TASK_STRINGS = strings('careTasks', 'common', {
  progress: (done: number, min: number) => t('careTasks:common.progress', { done, min }),
  count: (strokes: number) => t('careTasks:common.count', { strokes }),
  stepA11y: (n: number) => t('careTasks:common.stepA11y', { n }),
});

/** Untyped lookup for template keys (checked by the parity test and `careTasks.test.ts`). */
const tr = (key: string, options?: Record<string, unknown>): string => (t as unknown as (k: string, o?: Record<string, unknown>) => string)(key, options);

/** "Umij zobke", "Obriši gubice" … — the chip / overlay title. */
export function careTaskTitle(task: CareTaskKey): string {
  return tr(`careTasks:tasks.${task}.title`);
}

export function careTaskText(task: CareTaskKey, key: 'intro' | 'start' | 'stroke' | 'strokeA11y' | 'stageA11y' | 'successTitle' | 'success' | 'failTitle' | 'chipA11y'): string {
  return tr(`careTasks:tasks.${task}.${key}`);
}

export function careTaskStep(task: CareTaskKey, step: 1 | 2 | 3): string {
  return tr(`careTasks:tasks.${task}.steps.${step}`);
}

/** "Ta teden: 2 / 7", "Ta mesec: 0 / 1" … */
export function careTaskPeriodLine(task: ChildCareTask): string {
  if (task.done_this_period >= task.times) return tr(`careTasks:period.done.${periodKey(task.period_days)}`);
  return tr(`careTasks:period.open.${periodKey(task.period_days)}`, { done: task.done_this_period, goal: task.times });
}

/** Week / two weeks / month / longer — for the period texts. */
export function periodKey(days: number): 'week' | 'twoWeeks' | 'month' | 'longer' {
  if (days <= 7) return 'week';
  if (days <= 14) return 'twoWeeks';
  if (days <= 31) return 'month';
  return 'longer';
}

/** "every day", "once a week", "once a month", "every 6 weeks" … (child / care guide). */
export function frequencyText(times: number, periodDays: number): string {
  if (times >= 7 && periodDays === 7) return tr('careTasks:frequency.daily');
  if (periodDays === 7) return tr('careTasks:frequency.perWeek', { count: times });
  if (periodDays === 28) return tr('careTasks:frequency.perMonth', { count: times });
  if (periodDays % 28 === 0) return tr('careTasks:frequency.everyMonths', { count: periodDays / 28 });
  if (periodDays % 7 === 0) return tr('careTasks:frequency.everyWeeks', { count: periodDays / 7 });
  return tr('careTasks:frequency.everyDays', { count: periodDays });
}

export const CARE_REMINDER_STRINGS = strings('careTasks', 'parent', {
  dueBy: (when: string) => t('careTasks:parent.dueBy', { when }),
  doneOn: (when: string) => t('careTasks:parent.doneOn', { when }),
  every: (frequency: string) => t('careTasks:parent.every', { frequency }),
});

/** "Obisk pasjega frizerja", "Kopanje" — the parent's reminder title. */
export function careReminderTitle(task: CareReminderKey): string {
  return tr(`careTasks:parent.tasks.${task}`);
}

/** Parent timeline: "Mia · umila zobke" / "Starš · pasji frizer" (null for other rows). */
export function careTaskTimelineText(activityType: string, nickname: string | null): string | null {
  const key = TIMELINE[activityType];
  if (key === undefined) return null;
  const text = tr(`careTasks:timeline.${key}`);
  return nickname ? tr('careTasks:timeline.by', { text, child: nickname }) : text;
}

const TIMELINE: Record<string, string> = {
  brushed_teeth: 'brushed_teeth',
  cleaned_folds: 'cleaned_folds',
  cleaned_ears: 'cleaned_ears',
  trimmed_nails: 'trimmed_nails',
  parent_groomer_visit: 'parent_groomer_visit',
  parent_bathed_pet: 'parent_bathed_pet',
};

/** Report row label of a child task routine type ("Zobje", "Gube" …); null for other types. */
export function careRoutineLabel(type: string): string | null {
  return isCareTask(type) ? tr(`careTasks:report.${type}`) : null;
}
