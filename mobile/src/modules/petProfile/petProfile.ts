/**
 * Pet profile (M5-R01 server, M5-R04 app): origin, age and life stage of a pet.
 *
 * Read from the loose `profile` object that `GET /api/child/pet`, the family dashboard
 * `pets[]` and pin-login carry. A **legacy** pet (created before M5-R01, `legacy: true`,
 * null origin / ages / stage) or an older server without `profile` yields `null` — the
 * screens then show exactly what they showed before (no stage, no origin, no crash).
 *
 * Strings come from `pet:profile` / `pet:date` (M1-18). Numbers shown here come only from
 * the API payload; rules marked `unverified` by the server are never shown as facts.
 */

import type { LifeStage, PetOrigin } from '@/api/client';
import { t, tSpecies } from '@/i18n';
import { strings } from '@/i18n/strings';

export const LIFE_STAGES: readonly LifeStage[] = ['puppy', 'young', 'adult', 'senior'];
export const PET_ORIGINS: readonly PetOrigin[] = ['bought', 'adopted'];

/** User-visible strings of the profile display (`pet:profile`, M1-18). */
export const PET_PROFILE_STRINGS = strings('pet', 'profile', {
  /** Parent: "Od 24. 11. 2026 mlad pes" / "Becomes a young dog on 24 Nov 2026". */
  nextStage: (stage: string, date: string) => t('pet:profile.nextStage', { stage, date }),
  /** Child: "24. 11. 2026 postane mlad pes" / "Grows into a young dog on 24 Nov 2026". */
  nextStageChild: (stage: string, date: string) => t('pet:profile.nextStageChild', { stage, date }),
  mealsToday: (meals: number, byParent: number) =>
    byParent > 0
      ? t('pet:profile.mealsTodayWithParent', { count: meals, byParent })
      : t('pet:profile.mealsToday', { count: meals }),
});

export interface PetProfileInfo {
  /** The dog's age now in whole months (arrival age + one month per program week; time locked waiting for payment does not count). */
  ageMonths: number;
  /** null for a breed without life-stage data (the age is still shown). */
  stage: LifeStage | null;
  origin: PetOrigin | null;
  /** Next stage and the family-local date (Y-m-d) its rules start; null for a senior / unborn pet. */
  nextStage: { stage: LifeStage; fromDate: string } | null;
  /** Today's meals (only when the server marks the meal count as sourced). */
  meals: { perDay: number; byParent: number } | null;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null;
}

function lifeStage(value: unknown): LifeStage | null {
  return typeof value === 'string' && (LIFE_STAGES as readonly string[]).includes(value) ? (value as LifeStage) : null;
}

function petOrigin(value: unknown): PetOrigin | null {
  return typeof value === 'string' && (PET_ORIGINS as readonly string[]).includes(value) ? (value as PetOrigin) : null;
}

function wholeNumber(value: unknown): number | null {
  return typeof value === 'number' && Number.isFinite(value) && value >= 0 ? Math.floor(value) : null;
}

function isIsoDate(value: unknown): value is string {
  return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value);
}

function readMeals(profile: Record<string, unknown>): PetProfileInfo['meals'] {
  const unverified = Array.isArray(profile.unverified) ? profile.unverified : [];
  if (unverified.includes('meals_per_day')) return null;
  const today = profile.today;
  if (!isRecord(today)) return null;
  const perDay = wholeNumber(today.meals_per_day);
  if (perDay === null || perDay === 0) return null;
  return { perDay, byParent: Math.min(perDay, wholeNumber(today.meals_by_parent) ?? 0) };
}

/**
 * The displayable profile, or `null` for a legacy pet / missing or malformed payload.
 * Never throws: any shape the server sends is safe.
 */
export function readPetProfile(value: unknown): PetProfileInfo | null {
  if (!isRecord(value) || value.legacy !== false) return null;
  const ageMonths = wholeNumber(value.age_months);
  if (ageMonths === null) return null;

  const next = value.next_stage;
  const nextStageOf = isRecord(next) ? lifeStage(next.life_stage) : null;
  return {
    ageMonths,
    stage: lifeStage(value.life_stage),
    origin: petOrigin(value.origin),
    nextStage: isRecord(next) && nextStageOf !== null && isIsoDate(next.from_date) ? { stage: nextStageOf, fromDate: next.from_date } : null,
    meals: readMeals(value),
  };
}

/** "3 mesece" / "3 months" (CLDR plurals: sl one/two/few/other). */
function monthsWord(n: number): string {
  return t('pet:profile.months', { count: n });
}

/** "2 leti" / "2 years". */
function yearsWord(n: number): string {
  return t('pet:profile.years', { count: n });
}

/** 3 → "3 mesece"; 26 → "2 leti in 2 meseca"; 36 → "3 leta" (years from 24 months). */
export function formatDogAge(months: number): string {
  const n = Math.max(0, Math.floor(months));
  if (n < 24) return monthsWord(n);
  const years = Math.floor(n / 12);
  const rest = n % 12;
  return rest === 0 ? yearsWord(years) : t('pet:profile.yearsAndMonths', { years: yearsWord(years), months: monthsWord(rest) });
}

const MONTH_KEYS = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12'] as const;

/**
 * "2026-11-24" → "24. 11. 2026" (sl) / "24 Nov 2026" (en) — a family-local calendar date,
 * no time-zone math. Built from keys (not `Intl`) so it is the same on every engine.
 */
export function formatProfileDate(isoDate: string): string {
  const [y, m, d] = isoDate.split('-').map((part) => Number(part));
  const monthKey = MONTH_KEYS[m - 1];
  const month = monthKey ? t(`pet:date.months.${monthKey}`) : String(m);
  return t('pet:date.format', { day: d, month, year: y });
}

/**
 * "Mladiček" / "Mucek" (`lower`: "mlad pes" / "mlada mačka"). M5-R06-08c: a parent screen
 * passes the shown pet's `species`; without it the child's text species applies (`t`).
 */
export function stageText(stage: LifeStage, species: string | null = null, lower = false): string {
  // A known species (parent screen) never reads the child's global switch (QA 08c m1).
  if (species !== null) return tSpecies(`pet:profile.${lower ? 'stagesLower' : 'stages'}.${stage}`, species);
  return (lower ? PET_PROFILE_STRINGS.stagesLower : PET_PROFILE_STRINGS.stages)[stage];
}

/** "Mladiček · 3 mesece" (or only the age for a breed without stage data). */
export function stageLine(info: PetProfileInfo, species: string | null = null): string {
  const age = formatDogAge(info.ageMonths);
  return info.stage ? `${stageText(info.stage, species)} · ${age}` : age;
}

/** "Kupljen pri vzreditelju" / "Posvojen iz zavetišča" ("Kupljena …" for a cat); null when unknown. */
export function originLine(info: PetProfileInfo, species: string | null = null): string | null {
  if (!info.origin) return null;
  return species !== null ? tSpecies(`pet:profile.origins.${info.origin}`, species) : PET_PROFILE_STRINGS.origins[info.origin];
}

/** Parent: "Od 24. 11. 2026 mlad pes"; child: "24. 11. 2026 postane mlad pes". */
export function nextStageLine(
  info: PetProfileInfo,
  audience: 'parent' | 'child' = 'parent',
  species: string | null = null,
): string | null {
  if (!info.nextStage) return null;
  const stage = stageText(info.nextStage.stage, species, true);
  const date = formatProfileDate(info.nextStage.fromDate);
  return audience === 'child' ? PET_PROFILE_STRINGS.nextStageChild(stage, date) : PET_PROFILE_STRINGS.nextStage(stage, date);
}

/** "Danes 4 obroki — 1 v tihih urah nahrani starš"; null when not shown (unsourced / missing). */
export function mealsLine(info: PetProfileInfo): string | null {
  return info.meals ? PET_PROFILE_STRINGS.mealsToday(info.meals.perDay, info.meals.byParent) : null;
}
