/**
 * M5-T05 care guide — »Kako skrbim za …« / "How I care for …" (APP_REFINEMENT_SPEC §5).
 *
 * The server sends only numbers + keys (`care_guide` of the child state, built from the same
 * services the simulation uses: stage params, breed config, feed windows, quiet hours,
 * grooming per week). This module reads that block and turns it into localized, child-friendly
 * sentences — it never holds a game number of its own, so the guide can't drift from the rules.
 *
 * Cat wording: »muca« / "kitty" + "it" (`careGuide:cat.*`); dog: »kuža« / "pup" (`careGuide:dog.*`).
 * The pet's name is only used in the title (a label, M5-R08) — never inside a sentence.
 *
 * M5-T05b audience: the parent app shows the same guide (same server block, same numbers, same
 * sections) worded for the parent — formal »vi« in Slovenian, the child as the one who acts
 * (`careGuide:parent.dog.*` / `careGuide:parent.cat.*`). Only the wording differs.
 */

import { t } from '@/i18n';
import { clockText } from '@/modules/childPet/familyTime';
import { isolatePetName } from '@/modules/petName/petName';
import type { Species } from '@/modules/species/species';

export interface CareGuideWindow {
  start: string;
  end: string;
  parent_covered: boolean;
}

export interface CareGuide {
  species: Species;
  meals: { per_day: number; by_child: number; by_parent: number; windows: CareGuideWindow[] };
  steps: { goal: number } | null;
  take_out: { hold_hours: number } | null;
  water: { refill_below: number; min_gap_minutes: number } | null;
  grooming: { per_week: number } | null;
  sleep: { night: { start: string; end: string } | null; school: { start: string; end: string } | null };
  play: { sessions_per_day: number; min_gap_minutes: number } | null;
  litter: { scoop_within_hours: number; change_every_days: number } | null;
  scratching: boolean;
  clean_within_hours: number;
  digestion_gap_minutes: number | null;
}

export type CareGuideSectionKey =
  | 'food'
  | 'water'
  | 'walk'
  | 'takeOut'
  | 'difference'
  | 'digestion'
  | 'play'
  | 'litter'
  | 'scratching'
  | 'grooming'
  | 'sleep'
  | 'mess';

/** Who reads the guide: the child (HUD, »ti«) or a parent (child / pet detail, »vi«). */
export type CareGuideAudience = 'child' | 'parent';

export interface CareGuideSection {
  key: CareGuideSectionKey;
  label: string;
  lines: string[];
}

function isObj(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function num(value: unknown): number | null {
  return typeof value === 'number' && Number.isFinite(value) && value >= 0 ? value : null;
}

const HHMM = /^\d{1,2}:\d{2}$/;

function span(value: unknown): { start: string; end: string } | null {
  if (!isObj(value)) return null;
  const { start, end } = value;
  return typeof start === 'string' && typeof end === 'string' && HHMM.test(start) && HHMM.test(end) ? { start, end } : null;
}

/** `care_guide` of the child state; null when missing or malformed (older server → no »?« button). */
export function readCareGuide(raw: unknown): CareGuide | null {
  if (!isObj(raw)) return null;
  const species: Species | null = raw.species === 'cat' ? 'cat' : raw.species === 'dog' ? 'dog' : null;
  if (species === null || !isObj(raw.meals)) return null;
  const meals = raw.meals;
  const windows = Array.isArray(meals.windows)
    ? meals.windows.flatMap((w): CareGuideWindow[] => {
        const s = span(w);
        return s && isObj(w) ? [{ ...s, parent_covered: w.parent_covered === true }] : [];
      })
    : [];
  const steps = isObj(raw.steps) && (num(raw.steps.goal) ?? 0) > 0 ? { goal: num(raw.steps.goal) as number } : null;
  const hold = isObj(raw.take_out) ? num(raw.take_out.hold_hours) : null;
  const water =
    isObj(raw.water) && num(raw.water.refill_below) !== null
      ? { refill_below: num(raw.water.refill_below) as number, min_gap_minutes: num(raw.water.min_gap_minutes) ?? 0 }
      : null;
  const perWeek = isObj(raw.grooming) ? num(raw.grooming.per_week) : null;
  const sleep = isObj(raw.sleep) ? { night: span(raw.sleep.night), school: span(raw.sleep.school) } : { night: null, school: null };
  const sessions = isObj(raw.play) ? num(raw.play.sessions_per_day) : null;
  const litter =
    isObj(raw.litter) && (num(raw.litter.scoop_within_hours) ?? 0) > 0
      ? { scoop_within_hours: num(raw.litter.scoop_within_hours) as number, change_every_days: num(raw.litter.change_every_days) ?? 0 }
      : null;
  const gap = num(raw.digestion_gap_minutes);
  return {
    species,
    meals: {
      per_day: num(meals.per_day) ?? windows.length,
      by_child: num(meals.by_child) ?? 0,
      by_parent: num(meals.by_parent) ?? 0,
      windows,
    },
    steps,
    take_out: hold !== null && hold > 0 ? { hold_hours: hold } : null,
    water,
    grooming: perWeek !== null && perWeek > 0 ? { per_week: perWeek } : null,
    sleep,
    play:
      sessions !== null && sessions > 0 && isObj(raw.play)
        ? { sessions_per_day: sessions, min_gap_minutes: num(raw.play.min_gap_minutes) ?? 0 }
        : null,
    litter,
    scratching: raw.scratching === true,
    clean_within_hours: num(raw.clean_within_hours) ?? 0,
    digestion_gap_minutes: gap !== null && gap > 0 ? gap : null,
  };
}

/** "120 min" → "2 h"; "90 min" stays minutes (a child reads whole hours more easily). */
function duration(minutes: number): string {
  return minutes >= 60 && minutes % 60 === 0
    ? t('careGuide:units.hours', { value: minutes / 60 })
    : t('careGuide:units.minutes', { value: minutes });
}

function hours(value: number): string {
  return t('careGuide:units.hours', { value: Number.isInteger(value) ? value : Number(value.toFixed(1)) });
}

function windowText(w: { start: string; end: string }): string {
  return `${clockText(w.start)}–${clockText(w.end)}`;
}

function steps(n: number): string {
  return String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, t('common:format.thousands'));
}

/** Key prefix of the audience's texts (`careGuide:` for the child, `careGuide:parent.` for a parent). */
function prefix(audience: CareGuideAudience): string {
  return audience === 'parent' ? 'careGuide:parent.' : 'careGuide:';
}

/** The card title (also the parent's button): the pet's name (a label) or the species noun when it has none. */
export function careGuideTitle(species: Species, name: string | null, audience: CareGuideAudience = 'child'): string {
  const p = prefix(audience);
  if (name) return t(`${p}titleNamed` as 'careGuide:titleNamed', { name: isolatePetName(name) });
  return species === 'cat' ? t(`${p}cat.title` as 'careGuide:cat.title') : t(`${p}dog.title` as 'careGuide:dog.title');
}

/**
 * The guide as sections in reading order (screen readers follow it top to bottom). Only the
 * rules that apply to this pet appear (no take-out for an older dog, no steps for a cat …).
 */
export function careGuideSections(
  guide: CareGuide,
  audience: CareGuideAudience = 'child',
  /** M5-T12: the child's adapted mode — the walk is counted in wheelchair pushes. */
  options: { pushes?: boolean } = {},
): CareGuideSection[] {
  const pushes = options.pushes === true;
  const ns = `${prefix(audience)}${guide.species === 'cat' ? 'cat' : 'dog'}`;
  const tr = (key: string, options?: Record<string, unknown>): string =>
    (t as unknown as (k: string, o?: Record<string, unknown>) => string)(`${ns}.${key}`, options);
  const label = (key: CareGuideSectionKey): string => t(`careGuide:labels.${key}`);
  const out: CareGuideSection[] = [];

  // Food: today's windows of this pet (the child's ones first, then the parent's quiet-hour ones).
  const own = guide.meals.windows.filter((w) => !w.parent_covered);
  const parent = guide.meals.windows.filter((w) => w.parent_covered);
  const food = [tr('mealsCount', { count: guide.meals.per_day })];
  if (own.length > 0) food.push(tr('mealsWindows', { windows: own.map(windowText).join(', ') }));
  if (parent.length > 0) food.push(tr('mealsByParent', { windows: parent.map(windowText).join(', ') }));
  if (guide.meals.per_day > 0) out.push({ key: 'food', label: label('food'), lines: food });

  if (guide.water) {
    out.push({
      key: 'water',
      label: label('water'),
      lines: [
        tr('water', {
          percent: t('common:format.percent', { value: guide.water.refill_below }),
          gap: duration(guide.water.min_gap_minutes),
        }),
      ],
    });
  }

  if (guide.species === 'dog') {
    if (guide.steps) {
      out.push({ key: 'walk', label: label('walk'), lines: [tr(pushes ? 'walkPushes' : 'walk', { steps: steps(guide.steps.goal) })] });
    }
    if (guide.take_out) {
      out.push({ key: 'takeOut', label: label('takeOut'), lines: [tr('takeOut', { hours: hours(guide.take_out.hold_hours) })] });
      // David 2026-10-20: steps never resolve the potty need — »Pelji ven« is its own button.
      out.push({ key: 'difference', label: label('difference'), lines: [tr(pushes ? 'differencePushes' : 'difference')] });
    }
    if (guide.digestion_gap_minutes !== null) {
      out.push({ key: 'digestion', label: label('digestion'), lines: [tr('digestion', { minutes: duration(guide.digestion_gap_minutes) })] });
    }
  } else {
    if (guide.play) {
      out.push({
        key: 'play',
        label: label('play'),
        lines: [tr('play', { count: guide.play.sessions_per_day, gap: duration(guide.play.min_gap_minutes) })],
      });
    }
    if (guide.litter) {
      out.push({
        key: 'litter',
        label: label('litter'),
        lines: [tr('litter', { hours: hours(guide.litter.scoop_within_hours), days: guide.litter.change_every_days })],
      });
    }
    if (guide.scratching) out.push({ key: 'scratching', label: label('scratching'), lines: [tr('scratching')] });
  }

  if (guide.grooming) out.push({ key: 'grooming', label: label('grooming'), lines: [tr('grooming', { count: guide.grooming.per_week })] });

  const sleep: string[] = [];
  if (guide.sleep.night) sleep.push(tr('sleepNight', { start: clockText(guide.sleep.night.start), end: clockText(guide.sleep.night.end) }));
  if (guide.sleep.school) sleep.push(tr('sleepSchool', { start: clockText(guide.sleep.school.start), end: clockText(guide.sleep.school.end) }));
  if (sleep.length === 0) sleep.push(tr('sleepNone'));
  out.push({ key: 'sleep', label: label('sleep'), lines: sleep });

  if (guide.clean_within_hours > 0) out.push({ key: 'mess', label: label('mess'), lines: [tr('mess', { hours: hours(guide.clean_within_hours) })] });

  return out;
}
