/**
 * Quiet hours — pure helpers (David 2026-11-16: per pet + 12 / 24 h). A schedule is
 * "HH:MM" data (null = no window); the family's lives in `GET /api/parent/quiet-hours`,
 * a pet's effective one in the dashboard `family.pets[].quiet_hours` (+ `follows_family`).
 */

import { t } from '@/i18n';
import { clockText } from '@/modules/time/clock';

export interface QuietTimes {
  school_start: string | null;
  school_end: string | null;
  bedtime_start: string | null;
  bedtime_end: string | null;
  is_active: boolean;
}

/** Same values as the server default (`QuietHours::DEFAULTS`): night only, active. */
export const DEFAULT_QUIET_TIMES: QuietTimes = {
  school_start: null,
  school_end: null,
  bedtime_start: '21:00',
  bedtime_end: '07:00',
  is_active: true,
};

const HHMM = /^([01]\d|2[0-3]):[0-5]\d$/;

function time(value: unknown): string | null {
  if (typeof value !== 'string') return null;
  const short = value.slice(0, 5);
  return HHMM.test(short) ? short : null;
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
  };
}

/** The values only (drops `follows_family`, ids …) — what the save endpoints take. */
export function quietTimesOf(times: QuietTimes): QuietTimes {
  const { school_start, school_end, bedtime_start, bedtime_end, is_active } = times;
  return { school_start, school_end, bedtime_start, bedtime_end, is_active };
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
  if (times.school_start && times.school_end) {
    parts.push(t('parent:quietHours.summarySchool', { range: `${clockText(times.school_start)}–${clockText(times.school_end)}` }));
  }
  if (times.bedtime_start && times.bedtime_end) {
    parts.push(t('parent:quietHours.summaryBedtime', { range: `${clockText(times.bedtime_start)}–${clockText(times.bedtime_end)}` }));
  }
  return parts.length > 0 ? parts.join(' · ') : t('parent:quietHours.summaryNone');
}
