/**
 * M5-R08 — optional pet name (David 2026-10-09). Only a parent sets, changes or clears it
 * (`PATCH /api/parent/pets/{pet}/name`); free or paid, dog or cat, any time.
 *
 * The name is ONLY a label: the child HUD title, the parent's pet / child cards, the album
 * title and the pet lists. Never put it into a sentence (Slovenian declension), a push text
 * or the child's PIN login screen. Without a name every screen shows exactly what it showed
 * before (breed / species) — the helpers below return the old label unchanged then.
 *
 * Client-side validation mirrors the server (`PetNameService`): trimmed, inner whitespace
 * collapsed, ’ → ', NFC; 1–20 characters (code points); letters (any script, incl. č š ž),
 * space, hyphen, apostrophe; at least one letter. The word filter stays on the server
 * (`name_not_allowed`) — the server is the authority.
 */

import { ApiError, type PetNameErrorCode } from '@/api/client';
import { breedLabel } from '@/modules/family/family';
import { t } from '@/i18n';
import { strings } from '@/i18n/strings';

export const PET_NAME_MAX_LENGTH = 20;

/** Every code the server may send in a name 422 (compile-time complete list). */
export const PET_NAME_ERROR_CODES: readonly PetNameErrorCode[] = ['name_too_long', 'name_invalid', 'name_not_allowed'];

/** Why a save failed, as shown to the parent. */
export type PetNameErrorKind = PetNameErrorCode | 'not_found' | 'forbidden' | 'offline' | 'server';

/** All user-visible strings (`parent:petName`). Species-neutral ("ljubljenček" / "pet"). */
export const PET_NAME_STRINGS = strings('parent', 'petName', {
  counter: (count: number) => t('parent:petName.counter', { count, max: PET_NAME_MAX_LENGTH }),
  editA11y: (pet: string) => t('parent:petName.editA11y', { pet }),
});

const ALLOWED = /^[\p{L}\p{M}' -]+$/u;
const LETTER = /\p{L}/u;

/** Canonical form like the server's; null = no name (empty / only whitespace). */
export function normalizePetName(input: string): string | null {
  let name = input.replace(/\u2019/g, "'");
  try {
    name = name.normalize('NFC');
  } catch {
    // No normalize() in this engine: the server normalises anyway.
  }
  // JS `\s` covers the Unicode spaces (NBSP, U+2000–U+200A, U+3000 …) like PHP's [\s\p{Z}].
  name = name.replace(/\s+/g, ' ').trim();
  return name === '' ? null : name;
}

/** Length in characters (code points), as the server counts it. */
export function petNameLength(name: string): number {
  return Array.from(name).length;
}

export type PetNameValidation =
  | { ok: true; name: string | null }
  | { ok: false; code: Exclude<PetNameErrorCode, 'name_not_allowed'> };

/** Length first, then characters — the server's order. An empty input = "remove the name". */
export function validatePetName(input: string): PetNameValidation {
  const name = normalizePetName(input);
  if (name === null) return { ok: true, name: null };
  if (petNameLength(name) > PET_NAME_MAX_LENGTH) return { ok: false, code: 'name_too_long' };
  if (!ALLOWED.test(name) || !LETTER.test(name)) return { ok: false, code: 'name_invalid' };
  return { ok: true, name };
}

/** The name from any payload (child state, dashboard, broadcast); null when missing / empty. */
export function readPetName(value: unknown): string | null {
  if (typeof value !== 'string') return null;
  const name = value.trim();
  return name === '' ? null : name;
}

function isPetNameErrorCode(value: unknown): value is PetNameErrorCode {
  return typeof value === 'string' && (PET_NAME_ERROR_CODES as readonly string[]).includes(value);
}

/** Map a failed save to what the parent is told. */
export function classifyPetNameError(error: unknown): PetNameErrorKind {
  if (!(error instanceof ApiError)) return 'offline';
  if (error.status === 422) {
    const body = typeof error.data === 'object' && error.data !== null ? (error.data as Record<string, unknown>) : {};
    const codes = typeof body.codes === 'object' && body.codes !== null ? (body.codes as Record<string, unknown>) : {};
    if (isPetNameErrorCode(codes.name)) return codes.name;
    if (isPetNameErrorCode(body.reason)) return body.reason;
    return 'name_invalid';
  }
  if (error.status === 404) return 'not_found';
  if (error.status === 403) return 'forbidden';
  return 'server';
}

/** Friendly text of a failed save / a client-side validation error. */
export function petNameErrorText(kind: PetNameErrorKind): string {
  return PET_NAME_STRINGS.errors[kind];
}

/**
 * A pet's label for cards and lists: "Luna · Border Collie" with a name, otherwise the
 * breed label exactly as before ("Mešanček", "Maine Coon").
 */
export function petLabel(pet: { name?: unknown; breed_type: string; species?: string | null }): string {
  const breed = breedLabel(pet.breed_type, pet.species);
  const name = readPetName(pet.name);
  return name === null ? breed : `${name} · ${breed}`;
}
