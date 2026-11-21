/**
 * M5-08 acquisition source (VALIDATION_PLAN §4.1, `docs/engineering/VALIDATION_API.md` §1, David 2026-11-21 D2):
 * the optional question »Kje ste izvedeli za PetPrep?« at parent sign-up (e-mail form and the
 * Google / Apple terms sheet) and, once, as a dismissible card on the dashboard for parents who
 * signed up before it existed. Pure functions — the picker and the card only wire them up.
 *
 * The answer is never required and never blocks sign-up; the server stores null for anything it
 * does not accept. The free-text detail (»Kje? (ali koda)«, e.g. a campaign code) is sent only
 * with `ad` / `other` — the server drops it for every other source anyway.
 */

import type { AcquisitionFields, AcquisitionSource } from '@/api/client';
import { strings } from '@/i18n/strings';

/** User-visible strings of the picker and the dashboard card (`auth:acquisition`). */
export const ACQUISITION_STRINGS = strings('auth', 'acquisition');

/** Display order of the options (`AcquisitionSource`, VALIDATION_API.md §1). */
export const ACQUISITION_SOURCES: readonly AcquisitionSource[] = [
  'friend_family',
  'instagram',
  'tiktok',
  'facebook',
  'youtube',
  'school_kindergarten',
  'search',
  'website',
  'ad',
  'other',
];

/** Server limit of `acquisition_detail` (characters, after trimming). */
export const ACQUISITION_DETAIL_MAX = 60;

/** What the parent picked; `source` null = nothing (the question stays unanswered). */
export interface AcquisitionChoice {
  source: AcquisitionSource | null;
  detail: string;
}

export const EMPTY_ACQUISITION: AcquisitionChoice = { source: null, detail: '' };

export function isAcquisitionSource(value: unknown): value is AcquisitionSource {
  return typeof value === 'string' && (ACQUISITION_SOURCES as readonly string[]).includes(value);
}

/** Only »Oglas« and »Drugo« ask »Kje? (ali koda)«. */
export function sourceTakesDetail(source: AcquisitionSource | null): boolean {
  return source === 'ad' || source === 'other';
}

/** Trimmed, inner whitespace collapsed, at most 60 characters (code points, not UTF-16 units). */
export function normalizeDetail(detail: string): string {
  return [...detail.trim().replace(/\s+/g, ' ')].slice(0, ACQUISITION_DETAIL_MAX).join('').trim();
}

/** Tapping the selected option again clears it (the question is optional). The detail is kept only while it applies. */
export function toggleSource(choice: AcquisitionChoice, source: AcquisitionSource): AcquisitionChoice {
  const next = choice.source === source ? null : source;
  return { source: next, detail: sourceTakesDetail(next) ? choice.detail : '' };
}

/**
 * The request fields for a choice: `{}` when nothing was picked (old behaviour, the server
 * stores null), `acquisition_detail` only for `ad` / `other` and only when non-empty.
 */
export function acquisitionFields(choice: AcquisitionChoice): AcquisitionFields {
  if (choice.source === null) return {};
  const fields: AcquisitionFields = { acquisition_source: choice.source };
  if (sourceTakesDetail(choice.source)) {
    const detail = normalizeDetail(choice.detail);
    if (detail.length > 0) fields.acquisition_detail = detail;
  }
  return fields;
}

/** Was anything picked (decides whether the dashboard card may still ask later)? */
export function hasAnswer(choice: AcquisitionChoice): boolean {
  return choice.source !== null;
}

/**
 * `GET /api/user` → is the question still open for this account? Only an explicit `null` counts:
 * a missing key means an older server that would not take the answer, so the app never asks.
 */
export function acquisitionUnanswered(user: { role?: string; acquisition_source?: unknown } | null | undefined): boolean {
  if (!user || user.role !== 'parent') return false;
  return 'acquisition_source' in user && user.acquisition_source === null;
}

export interface AcquisitionCardInput {
  /** `GET /api/user` of the signed-in parent (undefined while loading / failed). */
  user: { role?: string; acquisition_source?: unknown } | null | undefined;
  /** The local dismissal store has been read (the card never flashes). */
  prefsLoaded: boolean;
  /** This parent closed the card, answered it, or already saw the question at sign-up on this phone. */
  dismissed: boolean;
}

/** Dashboard card »Kje ste izvedeli za PetPrep?« — offered once, only while the server says unanswered. */
export function shouldOfferAcquisitionCard({ user, prefsLoaded, dismissed }: AcquisitionCardInput): boolean {
  return prefsLoaded && !dismissed && acquisitionUnanswered(user);
}
