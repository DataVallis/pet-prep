/**
 * Quiet hours — pure helpers (David 2026-11-16: per pet + 12 / 24 h). A schedule is
 * "HH:MM" data (null = no window); the family's lives in `GET /api/parent/quiet-hours`,
 * a pet's effective one in the dashboard `family.pets[].quiet_hours` (+ `follows_family`).
 *
 * School days (David 2026-11-22, »Starš izbere dneve«): the school window applies only on
 * the ISO weekdays in `school_days` (1 = Monday … 7 = Sunday, sorted, unique; `[]` = no
 * school day); bedtime applies every day. A server that does not send the field (older
 * server) → `school_days: null`: read as Mon–Fri (`effectiveSchoolDays`), the day chips are
 * hidden and the field is never sent (`quietTimesOf`).
 */

import { t } from '@/i18n';
import { clockText } from '@/modules/time/clock';

export interface QuietTimes {
  school_start: string | null;
  school_end: string | null;
  bedtime_start: string | null;
  bedtime_end: string | null;
  is_active: boolean;
  /** ISO weekdays 1..7 the school window applies on; null = the server does not know the field. */
  school_days: number[] | null;
}

/** ISO weekdays, Monday first (the order of the day chips). */
export const ISO_WEEKDAYS = [1, 2, 3, 4, 5, 6, 7] as const;

/** Server default (`QuietHours::DEFAULTS['school_days']`): Monday to Friday. */
export const DEFAULT_SCHOOL_DAYS: readonly number[] = [1, 2, 3, 4, 5];

/** Same values as the server default (`QuietHours::DEFAULTS`): night only, active. */
export const DEFAULT_QUIET_TIMES: QuietTimes = {
  school_start: null,
  school_end: null,
  bedtime_start: '21:00',
  bedtime_end: '07:00',
  is_active: true,
  // Unknown until the server sends it (an older server's `quiet_hours: null`).
  school_days: null,
};

const HHMM = /^([01]\d|2[0-3]):[0-5]\d$/;

function time(value: unknown): string | null {
  if (typeof value !== 'string') return null;
  const short = value.slice(0, 5);
  return HHMM.test(short) ? short : null;
}

/** Loose `school_days` → sorted unique ISO weekdays; not an array (missing / older server) → null. */
export function readSchoolDays(value: unknown): number[] | null {
  if (!Array.isArray(value)) return null;
  const days = value.filter((d): d is number => typeof d === 'number' && Number.isInteger(d) && d >= 1 && d <= 7);
  return [...new Set(days)].sort((a, b) => a - b);
}

/** The days the school window applies on (unknown → Mon–Fri, the server default). */
export function effectiveSchoolDays(times: Pick<QuietTimes, 'school_days'>): number[] {
  return times.school_days ?? [...DEFAULT_SCHOOL_DAYS];
}

/** Turns one weekday chip on / off; the result stays sorted and unique (an empty set is allowed). */
export function toggleSchoolDay(days: readonly number[], day: number): number[] {
  const next = days.includes(day) ? days.filter((d) => d !== day) : [...days, day];
  return [...new Set(next)].sort((a, b) => a - b);
}

/** Any loose payload → a clean schedule (missing / broken → the server default values). */
export function readQuietTimes(raw: unknown): QuietTimes | null {
  if (raw === null || typeof raw !== 'object') return null;
  const r = raw as Record<string, unknown>;
  return {
    school_start: time(r.school_start),
    school_end: time(r.school_end),
    bedtime_start: time(r.bedtime_start),
    bedtime_end: time(r.bedtime_end),
    is_active: typeof r.is_active === 'boolean' ? r.is_active : true,
    school_days: readSchoolDays(r.school_days),
  };
}

/** Body of both save endpoints: the times + `school_days` only when the server knows the field. */
export interface QuietTimesPayload {
  school_start: string | null;
  school_end: string | null;
  bedtime_start: string | null;
  bedtime_end: string | null;
  is_active: boolean;
  school_days?: number[];
}

/**
 * The values only (drops `follows_family`, ids …) — what the save endpoints take. `school_days`
 * is sent sorted and unique, and only when known (an older server never gets it).
 */
export function quietTimesOf(times: QuietTimes): QuietTimesPayload {
  const { school_start, school_end, bedtime_start, bedtime_end, is_active, school_days } = times;
  const payload: QuietTimesPayload = { school_start, school_end, bedtime_start, bedtime_end, is_active };
  if (school_days !== null) payload.school_days = [...new Set(school_days)].sort((a, b) => a - b);
  return payload;
}

/** A pet's quiet-hours state from the dashboard pet (older server → follows the family, unknown times). */
export interface PetQuietHours {
  followsFamily: boolean;
  times: QuietTimes | null;
}

export function readPetQuietHours(pet: { quiet_hours?: unknown; quiet_hours_follow_family?: unknown }): PetQuietHours {
  return {
    followsFamily: pet.quiet_hours_follow_family !== false,
    times: readQuietTimes(pet.quiet_hours ?? null),
  };
}

/** True when the server knows quiet hours per pet (the dashboard pet carries them). */
export function supportsPetQuietHours(pet: { quiet_hours_follow_family?: unknown }): boolean {
  return typeof pet.quiet_hours_follow_family === 'boolean';
}

/** "Šola 8:00–13:00 · Spanje 21:00–7:00" (12 / 24 h), "izklopljene" when off. */
export function quietSummary(times: QuietTimes): string {
  if (!times.is_active) return t('parent:quietHours.pet.off');
  const parts: string[] = [];
  const days = effectiveSchoolDays(times);
  if (times.school_start && times.school_end && days.length > 0) {
    const range = `${clockText(times.school_start)}–${clockText(times.school_end)}`;
    const isDefault = days.length === DEFAULT_SCHOOL_DAYS.length && days.every((d, i) => d === DEFAULT_SCHOOL_DAYS[i]);
    parts.push(
      isDefault
        ? t('parent:quietHours.summarySchool', { range })
        : t('parent:quietHours.summarySchoolDays', { range, days: days.map((d) => weekdayShort(d)).join(', ') }),
    );
  }
  if (times.bedtime_start && times.bedtime_end) {
    parts.push(t('parent:quietHours.summaryBedtime', { range: `${clockText(times.bedtime_start)}–${clockText(times.bedtime_end)}` }));
  }
  return parts.length > 0 ? parts.join(' · ') : t('parent:quietHours.summaryNone');
}

/** »Po« / »Mon« — the chip label of an ISO weekday. */
export function weekdayShort(day: number): string {
  return t(`parent:quietHours.weekdays.short.${String(day) as WeekdayKey}`);
}

/** »ponedeljek« / »Monday« — what a screen reader says for a chip. */
export function weekdayLong(day: number): string {
  return t(`parent:quietHours.weekdays.long.${String(day) as WeekdayKey}`);
}

type WeekdayKey = '1' | '2' | '3' | '4' | '5' | '6' | '7';
