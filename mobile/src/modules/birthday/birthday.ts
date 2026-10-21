/**
 * The pet's first birthday on the phone (M5-T13, APP_REFINEMENT_SPEC §9, David 2026-10-20).
 *
 * The server decides everything: `birthday: {date, is_today, celebrated}` of the child state,
 * the parent dashboard pet and `pet.updated` (backend `BirthdayPayload`). The birthday is the
 * family-local day the pet reaches 1 year by the simulation's age formula (once per pet). On
 * that day the child HUD shows a party hat over the pet and, once per pet on this device, a
 * card with a SAFE "cake" (a dog-/cat-safe treat — never chocolate) and one sourced tip.
 * An older server sends no `birthday` → null (nothing shown).
 *
 * Wording rules: the Slovenian name is never declined — it stands as the subject in the
 * nominative (»Luna ima danes 1 leto«) or as the address (»Vse najboljše, Luna!«), and a named
 * line is gender-neutral (the app does not know the pet's sex, David 2026-10-21). Without a
 * name the species noun is used (kuža / muca, "pup" / "kitty"). No new AI media:
 * the hat is drawn by the app over the existing pet media (a birthday clip is future work).
 */

import { t } from '@/i18n';
import { isolatePetName } from '@/modules/petName/petName';

export interface PetBirthday {
  /** Family-local date (YYYY-MM-DD) the pet turns 1. */
  date: string;
  /** Today is the birthday (family-local) — the hat shows the whole day. */
  isToday: boolean;
  /** The server celebrated it (one push, one `pet_birthday` event). */
  celebrated: boolean;
}

const DATE = /^\d{4}-\d{2}-\d{2}$/;

/** `birthday` from any payload; missing / malformed → null (older server or no birthday). */
export function readBirthday(raw: unknown): PetBirthday | null {
  if (typeof raw !== 'object' || raw === null || Array.isArray(raw)) return null;
  const b = raw as Record<string, unknown>;
  if (typeof b.date !== 'string' || !DATE.test(b.date)) return null;
  return { date: b.date, isToday: b.is_today === true, celebrated: b.celebrated === true };
}

/** Back to the wire shape (the parent dashboard cache keeps raw payloads, validated). */
export function birthdayPayload(b: PetBirthday | null): { date: string; is_today: boolean; celebrated: boolean } | null {
  return b === null ? null : { date: b.date, is_today: b.isToday, celebrated: b.celebrated };
}

/** The hat shows the whole birthday — never over a lock (the lock screen explains). */
export function showBirthdayHat(birthday: PetBirthday | null, locked: boolean): boolean {
  return birthday !== null && birthday.isToday && !locked;
}

type SpeciesKey = 'dog' | 'cat';

function speciesKey(species: string | null | undefined): SpeciesKey {
  return species === 'cat' ? 'cat' : 'dog';
}

const tr = t as unknown as (key: string, options?: Record<string, unknown>) => string;

function named(key: string, name: string | null, species: string | null | undefined): string {
  const k = speciesKey(species);
  return name ? tr(`birthday:${k}.${key}Named`, { name: isolatePetName(name) }) : tr(`birthday:${k}.${key}`);
}

/** »Vse najboljše, Luna!« / "Happy birthday, Luna!" — without a name »Vse najboljše, kuža!«. */
export function birthdayTitle(name: string | null, species: string | null | undefined): string {
  return named('title', name, species);
}

/** »Luna ima danes 1 leto.« (neutral) / »Tvoj kuža je danes star 1 leto.« / "Luna is 1 year old today." */
export function birthdayAgeLine(name: string | null, species: string | null | undefined): string {
  return named('age', name, species);
}

/** The safe "cake": a dog-/cat-safe treat, never chocolate. */
export function birthdayCakeLine(species: string | null | undefined): string {
  return tr(`birthday:${speciesKey(species)}.cake`);
}

/**
 * Where the tip comes from (proper names, not translated). Dog: FDA »Leave Chocolate Out of
 * Rover's Celebrations«; cat: Agriculture Victoria »Human foods to avoid for cats« (also
 * ASPCA »People Foods to Avoid Feeding Your Pets«). Full citations: APP_REFINEMENT_SPEC §9.
 */
export const BIRTHDAY_TIP_SOURCE: Readonly<Record<SpeciesKey, string>> = {
  dog: 'U.S. FDA',
  cat: 'Agriculture Victoria, ASPCA',
};

/** The educational tip (a well-known, sourced safety fact) and its source line. */
export function birthdayTip(species: string | null | undefined): { text: string; source: string } {
  const k = speciesKey(species);
  return { text: tr(`birthday:${k}.tip`), source: tr('birthday:source', { source: BIRTHDAY_TIP_SOURCE[k] }) };
}

/** Parent card line: »Luna danes praznuje prvi rojstni dan.« (formal tone needs no pronoun). */
export function parentBirthdayLine(name: string | null, species: string | null | undefined): string {
  return named('parentLine', name, species);
}
