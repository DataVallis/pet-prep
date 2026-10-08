/**
 * Routines, Care Score, traffic light and 12-week progress as the parent sees them
 * (M2-05 / M2-06, PRODUCT_SPEC §9 / §11). The backend computes everything; this module
 * only reads the loosely generated payloads (`schema.ts` types `family.children` as a
 * string, pet timelines as `{[key]: unknown}`, the child report as `unknown[]`) into
 * real types with safe defaults, and turns codes into friendly text (i18n `family`, M1-18).
 *
 * No game rule is re-implemented here.
 */

import { familyClock, localParts } from '@/modules/childPet/familyTime';
import { PARENT_BEHAVIOUR_STRINGS, type BehaviourKind } from '@/modules/behaviour/behaviour';
import { playTimelineText } from '@/modules/play/play';
import { t } from '@/i18n';
import { strings } from '@/i18n/strings';

// ── Types ─────────────────────────────────────────────────────

export type LightColor = 'green' | 'yellow' | 'red';
export type LightReason = 'game_over' | 'phase3_alarm' | 'fell_ill_today' | 'missed_routines';
/** `training` (M5-R03): one completed training session per day, only for a pet with training. */
export type RoutineType = 'feed' | 'water' | 'clean' | 'walk' | 'training';

export const ROUTINE_TYPES: readonly RoutineType[] = ['feed', 'water', 'clean', 'walk', 'training'];

export interface TrafficLight {
  color: LightColor;
  reasons: LightReason[];
}

export interface CareScore {
  /** 0–100, null while no routine was expected yet. */
  score: number | null;
  /** Child: routines this child did; pet: routines done. */
  done: number;
  /** Child: fair share (Σ 1/n, 2 decimals); pet: routines expected. */
  expected: number;
  /** Child only: the shared routines, undivided (null on pet scores / older payloads). */
  routines: number | null;
  illnesses: number;
  since: string | null;
}

export interface MissedRoutine {
  type: RoutineType;
  /** M5-R02: which mess a missed `clean` was (poop | accident | chewing); null otherwise / older server. */
  kind: BehaviourKind | null;
  /** Family-local day of the routine (`YYYY-MM-DD`). */
  date: string;
  opens_at: string;
  due_at: string;
}

export interface TodayRoutines {
  date: string;
  expected: number;
  done: number;
  /** Of `done`, what this child earned (null on the pet block). */
  done_by_child: number | null;
  pending: number;
  missed_count: number;
  missed: MissedRoutine[];
}

export interface DayRow {
  date: string;
  expected: number;
  fair_expected: number;
  done: number;
  done_by_child: number;
  missed: number;
  pending: number;
  walk_steps: number;
  walk_goal: number | null;
  walk_done: boolean | null;
}

export interface ChallengeProgress {
  started_at: string;
  days_elapsed: number;
  week: number;
  weeks_total: number;
  completed: boolean;
}

export interface TimelineEntry {
  id: number;
  activity_type: string;
  value: number | null;
  actor_user_id: number | null;
  /** Nickname of the family's child who did it; null for system rows. */
  actor_nickname: string | null;
  created_at: string | null;
  is_positive: boolean;
}

export interface TypeTotals {
  expected: number;
  done: number;
  done_by_child: number;
  missed: number;
  pending: number;
}

export interface IllnessPeriod {
  started_at: string;
  ended_at: string | null;
}

/** `GET /api/parent/children/{child}/report?days=7|30|84`. */
export interface ChildReport {
  child: { id: number; name: string };
  pet_id: number | null;
  timezone: string;
  days: number;
  from: string;
  to: string;
  traffic_light: TrafficLight;
  care_score: CareScore;
  period_score: CareScore;
  progress: ChallengeProgress | null;
  by_type: Record<RoutineType, TypeTotals>;
  daily: DayRow[];
  missed: MissedRoutine[];
  illnesses: IllnessPeriod[];
}

export type ReportDays = 7 | 30 | 84;
export const REPORT_PERIODS: readonly ReportDays[] = [7, 30, 84];

// ── Readers (unknown → typed, never throw) ────────────────────

type Obj = Record<string, unknown>;

function isObj(value: unknown): value is Obj {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function num(value: unknown, fallback = 0): number {
  if (typeof value === 'number' && Number.isFinite(value)) return value;
  if (typeof value === 'string' && value.trim() !== '' && Number.isFinite(Number(value))) return Number(value);
  return fallback;
}

function numOrNull(value: unknown): number | null {
  if (value === null || value === undefined) return null;
  const n = num(value, Number.NaN);
  return Number.isNaN(n) ? null : n;
}

function str(value: unknown, fallback = ''): string {
  return typeof value === 'string' ? value : fallback;
}

function strOrNull(value: unknown): string | null {
  return typeof value === 'string' ? value : null;
}

function boolOrNull(value: unknown): boolean | null {
  return typeof value === 'boolean' ? value : null;
}

function arr(value: unknown): unknown[] {
  return Array.isArray(value) ? value : [];
}

const LIGHT_COLORS: readonly LightColor[] = ['green', 'yellow', 'red'];
const LIGHT_REASONS: readonly LightReason[] = ['game_over', 'phase3_alarm', 'fell_ill_today', 'missed_routines'];

export function readTrafficLight(value: unknown): TrafficLight {
  if (!isObj(value)) return { color: 'green', reasons: [] };
  const color = LIGHT_COLORS.find((c) => c === value.color) ?? 'green';
  const reasons = arr(value.reasons).filter((r): r is LightReason => LIGHT_REASONS.some((k) => k === r));
  return { color, reasons };
}

export function readCareScore(value: unknown): CareScore {
  const o = isObj(value) ? value : {};
  return {
    score: numOrNull(o.score),
    done: num(o.done),
    expected: num(o.expected),
    routines: numOrNull(o.routines),
    illnesses: num(o.illnesses),
    since: strOrNull(o.since),
  };
}

function isRoutineType(value: unknown): value is RoutineType {
  return ROUTINE_TYPES.some((t) => t === value);
}

const BEHAVIOUR_KINDS: readonly BehaviourKind[] = ['poop', 'accident', 'chewing'];

function readKind(value: unknown): BehaviourKind | null {
  return BEHAVIOUR_KINDS.find((k) => k === value) ?? null;
}

function readMissed(value: unknown): MissedRoutine[] {
  return arr(value).flatMap((item) => {
    if (!isObj(item) || !isRoutineType(item.type)) return [];
    return [
      {
        type: item.type,
        kind: item.type === 'clean' ? readKind(item.kind) : null,
        date: str(item.date),
        opens_at: str(item.opens_at),
        due_at: str(item.due_at),
      },
    ];
  });
}

export function emptyToday(date = ''): TodayRoutines {
  return { date, expected: 0, done: 0, done_by_child: null, pending: 0, missed_count: 0, missed: [] };
}

export function readToday(value: unknown): TodayRoutines {
  if (!isObj(value)) return emptyToday();
  const missed = readMissed(value.missed);
  return {
    date: str(value.date),
    expected: num(value.expected),
    done: num(value.done),
    done_by_child: numOrNull(value.done_by_child),
    pending: num(value.pending),
    missed_count: num(value.missed_count, missed.length),
    missed,
  };
}

export function readDayRows(value: unknown): DayRow[] {
  return arr(value).flatMap((item) => {
    if (!isObj(item) || typeof item.date !== 'string') return [];
    return [
      {
        date: item.date,
        expected: num(item.expected),
        fair_expected: num(item.fair_expected),
        done: num(item.done),
        done_by_child: num(item.done_by_child),
        missed: num(item.missed),
        pending: num(item.pending),
        walk_steps: num(item.walk_steps),
        walk_goal: numOrNull(item.walk_goal),
        walk_done: boolOrNull(item.walk_done),
      },
    ];
  });
}

export function readProgress(value: unknown): ChallengeProgress | null {
  if (!isObj(value)) return null;
  return {
    started_at: str(value.started_at),
    days_elapsed: num(value.days_elapsed),
    week: num(value.week, 1),
    weeks_total: num(value.weeks_total, 12),
    completed: value.completed === true,
  };
}

/** System rows that are bad news (fallback when a payload has no `is_positive`). */
const NEGATIVE_ACTIVITIES: readonly string[] = ['ignored_warning', 'pet_accident', 'pet_chewed'];

export function readTimeline(value: unknown): TimelineEntry[] {
  return arr(value).flatMap((item) => {
    if (!isObj(item)) return [];
    const id = num(item.id, Number.NaN);
    if (Number.isNaN(id) || typeof item.activity_type !== 'string') return [];
    const actor = numOrNull(item.actor_user_id);
    return [
      {
        id,
        activity_type: item.activity_type,
        value: numOrNull(item.value),
        actor_user_id: actor,
        actor_nickname: strOrNull(item.actor_nickname),
        created_at: strOrNull(item.created_at),
        is_positive:
          typeof item.is_positive === 'boolean' ? item.is_positive : !NEGATIVE_ACTIVITIES.includes(item.activity_type),
      },
    ];
  });
}

function readTypeTotals(value: unknown): TypeTotals {
  const o = isObj(value) ? value : {};
  return {
    expected: num(o.expected),
    done: num(o.done),
    done_by_child: num(o.done_by_child),
    missed: num(o.missed),
    pending: num(o.pending),
  };
}

/** The child report body, or null when it isn't one (wrong shape). */
export function readChildReport(value: unknown): ChildReport | null {
  if (!isObj(value) || !isObj(value.child)) return null;
  const byTypeRaw = isObj(value.by_type) ? value.by_type : {};
  const days = num(value.days, 7);
  return {
    child: { id: num(value.child.id), name: str(value.child.name) },
    pet_id: numOrNull(value.pet_id),
    timezone: str(value.timezone, 'Europe/Ljubljana'),
    days,
    from: str(value.from),
    to: str(value.to),
    traffic_light: readTrafficLight(value.traffic_light),
    care_score: readCareScore(value.care_score),
    period_score: readCareScore(value.period_score),
    progress: readProgress(value.progress),
    by_type: {
      feed: readTypeTotals(byTypeRaw.feed),
      water: readTypeTotals(byTypeRaw.water),
      clean: readTypeTotals(byTypeRaw.clean),
      walk: readTypeTotals(byTypeRaw.walk),
      // Older servers send no `training` → zeros (the detail hides an all-zero training row).
      training: readTypeTotals(byTypeRaw.training),
    },
    daily: readDayRows(value.daily),
    missed: readMissed(value.missed),
    illnesses: arr(value.illnesses).flatMap((item) =>
      isObj(item) && typeof item.started_at === 'string'
        ? [{ started_at: item.started_at, ended_at: strOrNull(item.ended_at) }]
        : [],
    ),
  };
}

// ── Text (i18n `family`, M1-18) ───────────────────────────────

/** "Vse v redu" / "All good" … — a live view: read it when rendering. */
export const LIGHT_LABELS: Readonly<Record<LightColor, string>> = strings('family', 'light');

export const ROUTINE_LABELS: Readonly<Record<RoutineType, string>> = strings('family', 'routines');

const DATE = strings('family', 'date');

/**
 * Label of a missed routine: a missed `clean` names its mess (M5-R02) — "Luža",
 * "Pregrizen copat", "Kakec"; without a kind (older server) the type label ("Čiščenje").
 */
export function missedLabel(item: Pick<MissedRoutine, 'type' | 'kind'>): string {
  if (item.type === 'clean' && item.kind !== null) return PARENT_BEHAVIOUR_STRINGS.kinds[item.kind];
  return ROUTINE_LABELS[item.type];
}

/** "9", "9,5" (sl) / "9.5" (en) — at most one decimal, the language's decimal mark. */
export function formatAmount(value: number): string {
  const rounded = Math.round(value * 10) / 10;
  return Number.isInteger(rounded) ? String(rounded) : rounded.toFixed(1).replace('.', t('family:format.decimal'));
}

/** "1 od 1 rutine", "8 od 9,5 rutin" / "8 of 9.5 routines" (plural by the denominator). */
export function routinesOfText(done: number, expected: number): string {
  return t('family:score.routinesOf', { count: expected, done: formatAmount(done), expected: formatAmount(expected) });
}

/**
 * "x od y rutin" for a score (PR #20 review): the denominator is the routines the
 * child shared (undivided) when known, else `expected`, and the count never exceeds
 * it — a child who did more than their fair share must not read "10 od 5". On a
 * shared pet the fair share is added: "10 od 12 rutin · pošten delež 6".
 */
export function scoreRoutinesText(score: Pick<CareScore, 'done' | 'expected' | 'routines'>): string {
  const total = score.routines !== null && score.routines > 0 ? score.routines : score.expected;
  const base = routinesOfText(Math.min(score.done, total), total);
  const shared = score.routines !== null && score.routines > 0 && Math.abs(score.routines - score.expected) >= 0.01;
  return shared ? t('family:score.fairShare', { text: base, share: formatAmount(score.expected) }) : base;
}

/** "1 bolezen", "2 bolezni", "5 bolezni" / "1 illness", "3 illnesses". */
export function illnessesText(count: number): string {
  return t('family:score.illnesses', { count });
}

/** Friendly reason text for the parent. */
export function reasonText(reason: LightReason, missedToday = 0): string {
  switch (reason) {
    case 'game_over':
    case 'phase3_alarm':
    case 'fell_ill_today':
      return t(`family:reasons.${reason}`);
    case 'missed_routines':
      return missedToday > 2 ? t('family:reasons.missedMany', { count: missedToday }) : t('family:reasons.missedSome');
  }
}

export function progressText(progress: ChallengeProgress | null): string | null {
  if (!progress) return null;
  if (progress.completed) return t('family:progress.completed', { count: progress.weeks_total });
  return t('family:progress.week', { week: progress.week, total: progress.weeks_total });
}

/** 0–1 share of the challenge done (for a progress bar). */
export function progressShare(progress: ChallengeProgress | null): number {
  if (!progress) return 0;
  const total = progress.weeks_total * 7;
  return total > 0 ? Math.max(0, Math.min(1, progress.days_elapsed / total)) : 0;
}

/** Day + month without the year: "4. 10." (sl) / "4 Oct" (en). */
export function shortDate(day: number, month: number): string {
  const monthLabel = (DATE.months as Readonly<Record<string, string | undefined>>)[String(month)] ?? String(month);
  return t('family:date.short', { day, month: monthLabel });
}

function weekdayOf(year: number, month: number, day: number): string {
  const index = new Date(Date.UTC(year, month - 1, day)).getUTCDay();
  return (DATE.weekdays as Readonly<Record<string, string | undefined>>)[String(index)] ?? '';
}

const ISO_DATE = /^(\d{4})-(\d{2})-(\d{2})$/;

/** "pon 4. 10." / "Mon 4 Oct" for a family-local `YYYY-MM-DD`; the raw string if it isn't one. */
export function dayLabel(date: string): string {
  const match = ISO_DATE.exec(date);
  if (!match) return date;
  const [y, m, d] = [Number(match[1]), Number(match[2]), Number(match[3])];
  return t('family:date.day', { weekday: weekdayOf(y, m, d), date: shortDate(d, m) });
}

/** Short weekday for a chart column ("pon" / "Mon"). */
export function weekdayShort(date: string): string {
  const match = ISO_DATE.exec(date);
  if (!match) return date;
  return weekdayOf(Number(match[1]), Number(match[2]), Number(match[3]));
}

/**
 * When a missed routine was due, in the family's wall clock:
 * feed → "okno 07:00–09:00", clean → "rok 14:30", water / walk → "do konca dneva".
 * A routine from another day than `today` gets its date in front.
 */
export function missedWhenText(item: MissedRoutine, timezone: string | null, today: string | null = null): string {
  const opens = familyClock(item.opens_at, timezone);
  const due = familyClock(item.due_at, timezone);
  let when: string;
  switch (item.type) {
    case 'feed':
      when = t('family:missedWhen.window', { opens: opens ?? '?', due: due ?? '?' });
      break;
    case 'clean':
      when = t('family:missedWhen.deadline', { due: due ?? '?' });
      break;
    default:
      when = t('family:missedWhen.endOfDay');
  }
  return today && item.date && item.date !== today
    ? t('family:missedWhen.withDay', { day: dayLabel(item.date), when })
    : when;
}

/** Child actions in the timeline ("nahranil(a) kužka"), keyed by `activity_type`. */
const ACTIVITY_LABELS = strings('family', 'activities') as Readonly<Record<string, string | undefined>>;
/** System rows ("Opozorilo ni bilo upoštevano"), keyed by `activity_type`. */
const SYSTEM_ACTIVITY_LABELS = strings('family', 'systemActivities') as Readonly<Record<string, string | undefined>>;

/**
 * "Maja nahranil(a) kužka", "Opozorilo ni bilo upoštevano"; an unknown type shows its code.
 * M5-R05: "Igra z žogo ×3 · Maja" / "Crkljanje · Maja" (`value` = plays merged into the row).
 */
export function activityText(entry: Pick<TimelineEntry, 'activity_type' | 'actor_nickname'> & { value?: number | null }): string {
  const play = playTimelineText(entry.activity_type, entry.actor_nickname, entry.value ?? null);
  if (play !== null) return play;
  const system = SYSTEM_ACTIVITY_LABELS[entry.activity_type];
  if (system) return system;
  const label = ACTIVITY_LABELS[entry.activity_type] ?? entry.activity_type;
  return entry.actor_nickname ? `${entry.actor_nickname} ${label}` : label.charAt(0).toUpperCase() + label.slice(1);
}

/** "07:15" today, "včeraj 07:15", else "3. 10. 07:15" — family wall clock. */
export function activityWhenText(createdAt: string | null, timezone: string | null, today: string | null): string {
  if (!createdAt) return '';
  const parts = localParts(createdAt, timezone);
  if (!parts) return '';
  if (today && parts.date === today) return parts.time;
  if (today) {
    const todayMs = Date.parse(`${today}T00:00:00Z`);
    const dayMs = Date.parse(`${parts.date}T00:00:00Z`);
    if (!Number.isNaN(todayMs) && !Number.isNaN(dayMs) && todayMs - dayMs === 86_400_000) {
      return t('family:date.yesterday', { time: parts.time });
    }
  }
  const [, m, d] = parts.date.split('-');
  return t('family:date.dateTime', { date: shortDate(Number(d), Number(m)), time: parts.time });
}

/** The family-local date of an instant (`YYYY-MM-DD`), e.g. "today" from a timestamp. */
export function familyDate(iso: string, timezone: string | null): string | null {
  return localParts(iso, timezone)?.date ?? null;
}
