/**
 * Puppy socialisation (M5-R17, David 2026-10-12 "tedenska naloga"): while the dog is a puppy
 * the child gets one weekly "new experience" (»nova izkušnja«) — they pick a new person,
 * another dog, a new sound or a new place and mark it done with one tap
 * (`POST /api/child/pet/socialisation {experience}`). The parent sees a weekly row in the
 * report and the experience in the timeline. Never scored: no routine, Care Score, traffic
 * light or metric — a missed week only shows as missed.
 *
 * Whether there is a task is the server's: the `socialisation` block of the child state /
 * `PetUpdated` is non-null only in a puppy week of a breed with a sourced socialisation row,
 * for a dog whose apps declared `socialisation`. No game rule is re-implemented here.
 */

import { t } from '@/i18n';
import { strings } from '@/i18n/strings';
import { catWhen } from '@/modules/catCare/catCare';

export type SocialisationExperience = 'person' | 'dog' | 'sound' | 'place';

/** In the order the child sees them (and the server's `experiences`). */
export const SOCIALISATION_EXPERIENCES: readonly SocialisationExperience[] = ['person', 'dog', 'sound', 'place'];

/** Backend `SocialisationExperience::code()` — `activities_log.value` of a `socialised_pet` row. */
const EXPERIENCE_CODES: Readonly<Record<number, SocialisationExperience>> = { 1: 'person', 2: 'dog', 3: 'sound', 4: 'place' };

export type SocialisationRefusal = 'socialisation_not_available' | 'socialisation_week_done' | 'socialisation_quiet_hours';
const REFUSALS: readonly SocialisationRefusal[] = ['socialisation_not_available', 'socialisation_week_done', 'socialisation_quiet_hours'];

export interface ChildSocialisation {
  goal_per_week: number;
  done_this_week: number;
  /** This week's task is done. */
  done: boolean;
  /** Program week (1 = the week from birth). */
  week: number;
  week_started_at: string | null;
  week_ends_at: string | null;
  /** The last experience recorded this week. */
  experience: SocialisationExperience | null;
  experiences: SocialisationExperience[];
  /** When `blocked_reason` ends (the next puppy week, the end of quiet hours); null = not this stage. */
  next_allowed_at: string | null;
  blocked_reason: SocialisationRefusal | null;
  /** The server would accept a record now (child state: incl. this child's lock; broadcast: pet level). */
  can_record: boolean;
}

function isObj(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function iso(value: unknown): string | null {
  return typeof value === 'string' && value.length > 0 ? value : null;
}

function count(value: unknown): number {
  const n = typeof value === 'number' ? value : typeof value === 'string' && value !== '' ? Number(value) : Number.NaN;
  return Number.isFinite(n) ? Math.max(0, Math.round(n)) : 0;
}

export function isExperience(value: unknown): value is SocialisationExperience {
  return typeof value === 'string' && (SOCIALISATION_EXPERIENCES as readonly string[]).includes(value);
}

function isRefusal(value: unknown): value is SocialisationRefusal {
  return typeof value === 'string' && (REFUSALS as readonly string[]).includes(value);
}

/** The child state's / broadcast's `socialisation`; null = no task this week (or an older server). */
export function readChildSocialisation(raw: unknown): ChildSocialisation | null {
  if (!isObj(raw)) return null;
  const goal = count(raw.goal_per_week);
  if (goal <= 0) return null;
  const done = count(raw.done_this_week);
  const experiences = Array.isArray(raw.experiences) ? raw.experiences.filter(isExperience) : [];
  return {
    goal_per_week: goal,
    done_this_week: done,
    done: raw.done === true || done >= goal,
    week: count(raw.week),
    week_started_at: iso(raw.week_started_at),
    week_ends_at: iso(raw.week_ends_at),
    experience: isExperience(raw.experience) ? raw.experience : null,
    experiences: experiences.length > 0 ? experiences : [...SOCIALISATION_EXPERIENCES],
    next_allowed_at: iso(raw.next_allowed_at),
    blocked_reason: isRefusal(raw.blocked_reason) ? raw.blocked_reason : null,
    can_record: raw.can_record === true,
  };
}

/**
 * `socialisation` after a `PetUpdated` (pet level): its `can_record` ignores this child's own
 * contract, so a lock always turns it off. A broadcast without the key (older server) keeps
 * what the view has; an explicit null means this week has no task (the puppy grew up).
 */
export function broadcastSocialisation(current: ChildSocialisation | null, raw: unknown, locked: boolean): ChildSocialisation | null {
  if (raw === undefined) return current && locked ? { ...current, can_record: false } : current;
  const next = readChildSocialisation(raw);
  if (next === null) return null;
  return locked ? { ...next, can_record: false } : next;
}

/** The "Nova izkušnja" chip: this week's task is still open (the sheet explains a block). */
export function showSocialisationChip(socialisation: ChildSocialisation | null, locked: boolean): boolean {
  return socialisation !== null && !locked && !socialisation.done;
}

/** The record the child just made, shown at once (the server's state replaces it). */
export function optimisticSocialisation(current: ChildSocialisation, experience: SocialisationExperience): ChildSocialisation {
  const done = Math.min(current.goal_per_week, current.done_this_week + 1);
  return { ...current, done_this_week: done, done: done >= current.goal_per_week, experience, can_record: false };
}

/** `activities_log.value` → experience (null for an unknown code). */
export function experienceFromCode(code: number | null | undefined): SocialisationExperience | null {
  return typeof code === 'number' ? (EXPERIENCE_CODES[code] ?? null) : null;
}

// ── Texts (namespace `socialisation`, EN + SL) ────────────────

export const SOCIALISATION_STRINGS = strings('socialisation', 'child', {
  week: (week: number) => t('socialisation:child.week', { week }),
  doneWith: (experience: string) => t('socialisation:child.doneWith', { experience }),
});

/** "Nov človek" / "A new person" … */
export function experienceLabel(experience: SocialisationExperience): string {
  return t(`socialisation:experiences.${experience}.label`);
}

/** "Kuža spozna nekoga, ki ga še ne pozna …" — the short idea under the label. */
export function experienceHint(experience: SocialisationExperience): string {
  return t(`socialisation:experiences.${experience}.hint`);
}

/** Child text for a refusal code (422 or `blocked_reason`), with the family-local time when known. */
export function socialisationRefusalText(
  reason: string | null,
  nextAllowedAt: string | null,
  nowIso: string | null,
  timezone: string | null,
): string {
  if (!isRefusal(reason)) return t('socialisation:errors.other');
  if (reason === 'socialisation_not_available') return t('socialisation:errors.socialisation_not_available');
  const when = catWhen(nextAllowedAt, nowIso, timezone);
  if (reason === 'socialisation_week_done') {
    return when !== null ? t('socialisation:errors.socialisation_week_done', { when }) : t('socialisation:errors.socialisation_week_done_no_time');
  }
  return when !== null ? t('socialisation:errors.socialisation_quiet_hours', { when }) : t('socialisation:errors.socialisation_quiet_hours_no_time');
}

/** Parent timeline: "Mia · nova izkušnja: drug kuža" (value = the experience code); null for other rows. */
export function socialisationTimelineText(activityType: string, nickname: string | null, value: number | null): string | null {
  if (activityType !== 'socialised_pet') return null;
  const experience = experienceFromCode(value);
  const what = experience !== null ? t(`socialisation:parent.experiences.${experience}`) : null;
  const base = what !== null ? t('socialisation:parent.timeline', { what }) : t('socialisation:parent.timelinePlain');
  return nickname ? t('socialisation:parent.timelineBy', { text: base, child: nickname }) : base;
}

// ── Parent report row ─────────────────────────────────────────

export type ReportWeekStatus = 'done' | 'open' | 'missed';

export interface ReportSocialisationWeek {
  week: number;
  started_at: string | null;
  ends_at: string | null;
  status: ReportWeekStatus;
  experiences: SocialisationExperience[];
  by_child: boolean;
}

export interface ReportSocialisation {
  expected: number;
  done: number;
  done_by_child: number;
  missed: number;
  open: number;
  weeks: ReportSocialisationWeek[];
}

/** The child report's `socialisation` (M5-R17); null = no task week in the period / an older server. */
export function readReportSocialisation(raw: unknown): ReportSocialisation | null {
  if (!isObj(raw) || !Array.isArray(raw.weeks)) return null;
  const weeks = raw.weeks.flatMap((w): ReportSocialisationWeek[] => {
    if (!isObj(w)) return [];
    const status = w.status === 'done' || w.status === 'open' || w.status === 'missed' ? w.status : null;
    if (status === null) return [];
    return [
      {
        week: count(w.week),
        started_at: iso(w.started_at),
        ends_at: iso(w.ends_at),
        status,
        experiences: Array.isArray(w.experiences) ? w.experiences.filter(isExperience) : [],
        by_child: w.by_child === true,
      },
    ];
  });
  if (weeks.length === 0) return null;
  return {
    expected: count(raw.expected),
    done: count(raw.done),
    done_by_child: count(raw.done_by_child),
    missed: count(raw.missed),
    open: count(raw.open),
    weeks,
  };
}

/** "2 od 3 tednov" + "· 1 zamujen" + "· ta teden še čaka" — the row's right side. */
export function reportSocialisationLine(r: ReportSocialisation): { count: string; detail: string } {
  const parts = [
    r.missed > 0 ? t('socialisation:parent.missed', { count: r.missed }) : null,
    r.open > 0 ? t('socialisation:parent.open') : null,
  ].filter((x): x is string => x !== null);
  return { count: t('socialisation:parent.weeksOf', { count: r.expected, done: r.done }), detail: parts.join(' · ') };
}
