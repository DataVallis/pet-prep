/**
 * Species and breeds as the app reads them (M5-R06-02, CAT_SPEC §1, M5-R06_PLAN T1 / T9).
 *
 * - `BreedType` (schema) = every breed this build knows (dogs + cats since M5-R06-01).
 * - `ShownBreed` = a breed read from a loose payload: a known breed, or `unknown` (a breed
 *   newer than this build). An unknown breed is **never** turned into the mutt — that
 *   used to make a cat look like a free dog (M5-R06_PLAN T4). It gets a neutral label
 *   ("Pes" / "Mačka" / "Ljubljenček") and the generic placeholder instead.
 * - All breed and species names live in one place: `family:breeds.*` (the server's
 *   catalogue `label_key` is `breeds.<breed>`), `family:species.*`, `family:breedUnknown.*`.
 */

import type { components } from '@/api/schema';
import { t, tSpecies, type PetGroup } from '@/i18n';
import type { BreedType, ShownBreed, Species } from '@/types';

export type { BreedType, PetGroup, ShownBreed, Species };

export const SPECIES: readonly Species[] = ['dog', 'cat'];

/** Species of every breed this build knows (mirrors backend `BreedType::species()`). */
export const BREED_SPECIES: Readonly<Record<BreedType, Species>> = {
  mutt: 'dog',
  border_collie: 'dog',
  labrador_retriever: 'dog',
  golden_retriever: 'dog',
  french_bulldog: 'dog',
  german_shepherd: 'dog',
  cavalier_king_charles_spaniel: 'dog',
  beagle: 'dog',
  standard_poodle: 'dog',
  domestic_cat: 'cat',
  maine_coon: 'cat',
} satisfies Record<components['schemas']['BreedType'], Species>;

export const KNOWN_BREEDS = Object.keys(BREED_SPECIES) as BreedType[];

/**
 * The species' free breeds as the server seeds them (backend `BreedType::defaultPremium()`
 * false). Only a safety net for old payloads: what is free comes from the server — the pet's
 * `plan` (`display_type`) and the catalogue's `premium` / `free_plan_allowed`.
 */
export const DEFAULT_FREE_BREEDS: readonly BreedType[] = ['mutt', 'domestic_cat'];

export function isDefaultFreeBreed(breed: unknown): boolean {
  return isKnownBreed(breed) && DEFAULT_FREE_BREEDS.includes(breed);
}

export function isKnownBreed(value: unknown): value is BreedType {
  return typeof value === 'string' && (KNOWN_BREEDS as readonly string[]).includes(value);
}

export function isSpecies(value: unknown): value is Species {
  return typeof value === 'string' && (SPECIES as readonly string[]).includes(value);
}

/** Loose breed (any payload) → typed; a breed this build doesn't know → `unknown` (never the mutt). */
export function readBreed(value: unknown): ShownBreed {
  return isKnownBreed(value) ? value : 'unknown';
}

/**
 * The species of a pet: the payload's `species` when valid, else derived from a known
 * breed, else null (an unknown breed from an older server without `species`).
 */
export function readSpecies(value: unknown, breed?: ShownBreed | null): Species | null {
  if (isSpecies(value)) return value;
  return breed && breed !== 'unknown' ? BREED_SPECIES[breed] : null;
}

/** Cats among the pets (M5-R06-08c); a pet without a known species counts as a dog (the old default). */
export function catCount(pets: readonly { species?: unknown; breed_type?: unknown }[]): number {
  return pets.filter((p) => readSpecies(p.species, readBreed(p.breed_type)) === 'cat').length;
}

/**
 * The species of a group of pets (M5-R06-09, paywall / paid-challenge texts): no cat (or no
 * pet) → `dog` (the old texts, byte-identical), only cats → `cat`, dogs and cats → `mixed`.
 */
export function petGroup(pets: readonly { species?: unknown; breed_type?: unknown }[]): PetGroup {
  const cats = catCount(pets);
  if (cats === 0) return 'dog';
  return cats === pets.length ? 'cat' : 'mixed';
}

/**
 * A pet count per species (M5-R06-08c): `key` is the dog plural key ("1 kuža"); cats read
 * its cat override ("2 muci"); dogs and cats together → "1 kuža · 1 muca" (`cat:parent.petsMixed`).
 * With no cat the text is exactly the dog text.
 */
export function petCountText(key: 'parent:dashboard.counts.dogs' | 'account:counts.dogs', pets: number, cats: number): string {
  const dogs = Math.max(0, pets - cats);
  if (cats <= 0) return t(key, { count: pets });
  const catText = tSpecies(key, 'cat', { count: cats });
  return dogs === 0 ? catText : t('cat:parent.petsMixed', { dogs: t(key, { count: dogs }), cats: catText });
}

/** "Pes" / "Mačka" — read at render time. */
export function speciesName(species: Species): string {
  return t(`family:species.${species}`);
}

/**
 * The breed's display name ("Mešanček", "Maine Coon"). An unknown breed reads as its
 * species ("Pes" / "Mačka") or, without a species, "Ljubljenček" — never as a mutt.
 */
export function breedName(breed: string | null | undefined, species?: Species | null): string {
  if (isKnownBreed(breed)) return t(`family:breeds.${breed}`);
  return t(`family:breedUnknown.${species ?? 'any'}`);
}

/**
 * Search key: lower case, diacritics removed (č → c, š → s, ž → z …), spaces collapsed.
 * The Slovenian letters are mapped explicitly, so it does not depend on
 * `String.prototype.normalize` being available in the JS engine.
 */
const FOLD: Readonly<Record<string, string>> = {
  č: 'c', ć: 'c', š: 's', ž: 'z', đ: 'd', ä: 'a', á: 'a', à: 'a', â: 'a', é: 'e', è: 'e', ê: 'e', ë: 'e',
  í: 'i', ì: 'i', ó: 'o', ò: 'o', ô: 'o', ö: 'o', ú: 'u', ù: 'u', ü: 'u', ß: 'ss', ñ: 'n',
};

export function foldForSearch(value: string): string {
  let out = value.toLowerCase().replace(/[^\u0000-\u007f]/g, (ch) => FOLD[ch] ?? ch);
  try {
    out = out.normalize('NFD').replace(/[̀-ͯ]/g, '');
  } catch {
    // No normalize() in this engine: the explicit map above covers Slovenian.
  }
  return out.replace(/\s+/g, ' ').trim();
}
