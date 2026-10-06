/**
 * Pet profile (M5-R01 server, M5-R04 app): origin, age and life stage of a pet.
 *
 * Read from the loose `profile` object that `GET /api/child/pet`, the family dashboard
 * `pets[]` and pin-login carry. A **legacy** pet (created before M5-R01, `legacy: true`,
 * null origin / ages / stage) or an older server without `profile` yields `null` — the
 * screens then show exactly what they showed before (no stage, no origin, no crash).
 *
 * Strings are Slovenian (extract to i18n with M1-18). Numbers shown here come only from
 * the API payload; rules marked `unverified` by the server are never shown as facts.
 */

import type { LifeStage, PetOrigin } from '@/api/client';

export const LIFE_STAGES: readonly LifeStage[] = ['puppy', 'young', 'adult', 'senior'];
export const PET_ORIGINS: readonly PetOrigin[] = ['bought', 'adopted'];

/** User-visible strings of the profile display (i18n with M1-18). */
export const PET_PROFILE_STRINGS = {
  stages: { puppy: 'Mladiček', young: 'Mlad pes', adult: 'Odrasel', senior: 'Starejši' } satisfies Record<LifeStage, string>,
  /** Stage name inside a sentence ("od 24. 11. postane mlad pes"). */
  stagesLower: { puppy: 'mladiček', young: 'mlad pes', adult: 'odrasel pes', senior: 'starejši pes' } satisfies Record<LifeStage, string>,
  origins: { bought: 'Kupljen pri vzreditelju', adopted: 'Posvojen iz zavetišča' } satisfies Record<PetOrigin, string>,
  nextStage: (stage: string, date: string) => `Od ${date} ${stage}`,
  nextStageChild: (stage: string, date: string) => `${date} postane ${stage}`,
  mealsToday: (meals: number, byParent: number) =>
    byParent > 0 ? `Danes ${mealsWord(meals)} — ${byParent} v tihih urah nahrani starš` : `Danes ${mealsWord(meals)}`,
} as const;

export interface PetProfileInfo {
  /** The dog's age now in whole months (arrival age + one month per real week). */
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

/** Slovenian accusative after a number ("star 3 mesece"): 1 mesec, 2 meseca, 3–4 mesece, 5+ mesecev. */
function monthsWord(n: number): string {
  const mod = n % 100;
  const word = mod === 1 ? 'mesec' : mod === 2 ? 'meseca' : mod === 3 || mod === 4 ? 'mesece' : 'mesecev';
  return `${n} ${word}`;
}

/** 1 leto, 2 leti, 3–4 leta, 5+ let. */
function yearsWord(n: number): string {
  const mod = n % 100;
  const word = mod === 1 ? 'leto' : mod === 2 ? 'leti' : mod === 3 || mod === 4 ? 'leta' : 'let';
  return `${n} ${word}`;
}

/** 1 obrok, 2 obroka, 3–4 obroki, 5+ obrokov. */
function mealsWord(n: number): string {
  const mod = n % 100;
  const word = mod === 1 ? 'obrok' : mod === 2 ? 'obroka' : mod === 3 || mod === 4 ? 'obroki' : 'obrokov';
  return `${n} ${word}`;
}

/** 3 → "3 mesece"; 26 → "2 leti in 2 meseca"; 36 → "3 leta" (years from 24 months). */
export function formatDogAge(months: number): string {
  const n = Math.max(0, Math.floor(months));
  if (n < 24) return monthsWord(n);
  const years = Math.floor(n / 12);
  const rest = n % 12;
  return rest === 0 ? yearsWord(years) : `${yearsWord(years)} in ${monthsWord(rest)}`;
}

/** "2026-11-24" → "24. 11. 2026" (a family-local calendar date — no time-zone math). */
export function formatProfileDate(isoDate: string): string {
  const [y, m, d] = isoDate.split('-').map((part) => Number(part));
  return `${d}. ${m}. ${y}`;
}

/** "Mladiček · 3 mesece" (or only the age for a breed without stage data). */
export function stageLine(info: PetProfileInfo): string {
  const age = formatDogAge(info.ageMonths);
  return info.stage ? `${PET_PROFILE_STRINGS.stages[info.stage]} · ${age}` : age;
}

/** "Kupljen pri vzreditelju" / "Posvojen iz zavetišča"; null when unknown. */
export function originLine(info: PetProfileInfo): string | null {
  return info.origin ? PET_PROFILE_STRINGS.origins[info.origin] : null;
}

/** Parent: "Od 24. 11. 2026 mlad pes"; child: "24. 11. 2026 postane mlad pes". */
export function nextStageLine(info: PetProfileInfo, audience: 'parent' | 'child' = 'parent'): string | null {
  if (!info.nextStage) return null;
  const stage = PET_PROFILE_STRINGS.stagesLower[info.nextStage.stage];
  const date = formatProfileDate(info.nextStage.fromDate);
  return audience === 'child' ? PET_PROFILE_STRINGS.nextStageChild(stage, date) : PET_PROFILE_STRINGS.nextStage(stage, date);
}

/** "Danes 4 obroki — 1 v tihih urah nahrani starš"; null when not shown (unsourced / missing). */
export function mealsLine(info: PetProfileInfo): string | null {
  return info.meals ? PET_PROFILE_STRINGS.mealsToday(info.meals.perDay, info.meals.byParent) : null;
}
