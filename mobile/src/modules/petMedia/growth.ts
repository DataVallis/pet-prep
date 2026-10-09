/**
 * "Album rasti" (M5-R04 part 2) — pure model of the growth section inside the "Moj kuža"
 * album, tested in `__tests__/growth.test.ts`.
 *
 * Source: `GET /api/child/pet/growth` (child) / `GET /api/parent/pets/{pet}/growth`
 * (parent): the pet's picture per life stage, oldest first, the current one marked
 * `is_current`. Image URLs are signed and expire at `expires_at` (like every pet media).
 * - The section shows only from two pictures on (one picture = nothing to compare).
 * - Caption: stage ("Mladiček", null → none), the dog's age then ("2 meseca") and the
 *   family-local date ("4. 10. 2026"); the current picture is marked "Zdaj".
 * Never throws on a malformed payload (`PetGrowthResponse` is read loosely): broken entries are dropped.
 */

import type { LifeStage } from '@/api/client';
import { localParts } from '@/modules/childPet/familyTime';
import { mediaKey } from '@/modules/petMedia/petMedia';
import { formatDogAge, formatProfileDate, LIFE_STAGES, stageText } from '@/modules/petProfile/petProfile';
import { strings } from '@/i18n/strings';

/** User-visible strings (`pet:growth`, M1-18). `photo` = viewer title of a legacy picture (no stage, no age). */
export const GROWTH_STRINGS = strings('pet', 'growth');

/** The section shows from this many pictures on. */
export const GROWTH_MIN_ENTRIES = 2;

export interface GrowthEntry {
  generation: number;
  stage: LifeStage | null;
  ageMonths: number | null;
  takenAt: string | null;
  isCurrent: boolean;
  url: string;
}

export interface GrowthAlbum {
  petId: number;
  entries: GrowthEntry[];
  /** When the image URLs stop working (ISO); null without pictures. */
  expiresAt: string | null;
}

/** One growth picture as the album viewer shows it. */
export interface GrowthViewerItem {
  kind: 'photo';
  id: string;
  /** "Mladiček · 2 meseca" (title of the viewer / tile caption). */
  label: string;
  /** "4. 10. 2026 · Zdaj" (second line); null when there is nothing to say. */
  detail: string | null;
  /** Strip caption parts: "Mladiček", "2 meseca", "4. 10. 2026" (each null when unknown). */
  stageLabel: string | null;
  ageLabel: string | null;
  dateLabel: string | null;
  url: string;
  key: string;
  isCurrent: boolean;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null;
}

function lifeStage(value: unknown): LifeStage | null {
  return typeof value === 'string' && (LIFE_STAGES as readonly string[]).includes(value) ? (value as LifeStage) : null;
}

function readEntry(value: unknown): GrowthEntry | null {
  if (!isRecord(value)) return null;
  const { generation, life_stage, age_months, taken_at, is_current, image_url } = value;
  if (typeof generation !== 'number' || !Number.isFinite(generation)) return null;
  if (typeof image_url !== 'string' || image_url.length === 0) return null;
  return {
    generation,
    stage: lifeStage(life_stage),
    ageMonths: typeof age_months === 'number' && Number.isFinite(age_months) && age_months >= 0 ? Math.floor(age_months) : null,
    takenAt: typeof taken_at === 'string' && taken_at.length > 0 ? taken_at : null,
    isCurrent: is_current === true,
    url: image_url,
  };
}

/** Normalise the server payload (oldest first by `generation`). */
export function readGrowth(raw: unknown): GrowthAlbum {
  const body = isRecord(raw) ? raw : {};
  const list = Array.isArray(body.growth) ? body.growth : [];
  const entries = list.map(readEntry).filter((e): e is GrowthEntry => e !== null);
  entries.sort((a, b) => a.generation - b.generation);
  return {
    petId: typeof body.pet_id === 'number' ? body.pet_id : 0,
    entries,
    expiresAt: typeof body.expires_at === 'string' && body.expires_at.length > 0 ? body.expires_at : null,
  };
}

/** Whether the album shows the growth section (≥ 2 pictures). */
export function hasGrowthSection(album: GrowthAlbum | null | undefined): album is GrowthAlbum {
  return album !== null && album !== undefined && album.entries.length >= GROWTH_MIN_ENTRIES;
}

/** "Mladiček · 2 meseca", "Odrasel", "3 mesece"; null for a legacy picture (no stage, no age). */
export function growthTitle(entry: GrowthEntry, species: string | null = null): string | null {
  const parts = [
    entry.stage ? stageText(entry.stage, species) : null,
    entry.ageMonths !== null ? formatDogAge(entry.ageMonths) : null,
  ].filter((x): x is string => x !== null);
  return parts.length > 0 ? parts.join(' · ') : null;
}

/** "4. 10. 2026" — the family-local day the picture was taken; null when unknown. */
export function growthDate(entry: GrowthEntry, timeZone: string | null): string | null {
  if (!entry.takenAt) return null;
  const parts = localParts(entry.takenAt, timeZone);
  return parts ? formatProfileDate(parts.date) : null;
}

/** `species`: the parent's album passes the pet's species (M5-R06-08c); the child's follows its text species. */
export function growthViewerItems(album: GrowthAlbum, timeZone: string | null, species: string | null = null): GrowthViewerItem[] {
  return album.entries.map((entry) => {
    const date = growthDate(entry, timeZone);
    const detail = [date, entry.isCurrent ? GROWTH_STRINGS.current : null].filter((x): x is string => x !== null).join(' · ');
    return {
      kind: 'photo',
      id: `growth-${entry.generation}`,
      label: growthTitle(entry, species) ?? GROWTH_STRINGS.photo,
      detail: detail.length > 0 ? detail : null,
      stageLabel: entry.stage ? stageText(entry.stage, species) : null,
      ageLabel: entry.ageMonths !== null ? formatDogAge(entry.ageMonths) : null,
      dateLabel: date,
      url: entry.url,
      key: mediaKey(entry.url),
      isCurrent: entry.isCurrent,
    };
  });
}

/** Refetch lead before the URLs expire (same as the album's media). */
export const GROWTH_REFRESH_LEAD_MS = 60_000;

/**
 * TanStack `staleTime` of a loaded growth album: fresh until 1 min before its URLs
 * expire, so reopening the album within that time costs no request and an album opened
 * after it fetches fresh URLs. No data / no expiry → 0 (fetch on open).
 */
export function growthStaleTime(album: GrowthAlbum | undefined, nowMs: number): number {
  if (!album || album.expiresAt === null) return 0;
  const at = Date.parse(album.expiresAt);
  if (Number.isNaN(at)) return 0;
  return Math.max(0, at - GROWTH_REFRESH_LEAD_MS - nowMs);
}
