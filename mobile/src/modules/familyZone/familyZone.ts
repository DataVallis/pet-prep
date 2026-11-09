/**
 * Family time zone setting (David 2026-11-08, »kar B«) — pure helpers.
 *
 * Every wall-clock rule (quiet hours, sleep, meal windows, midnight, step day) of a pet runs in
 * that PET's zone (David 2026-11-09): its own `pets.timezone`, else the family's
 * (`families.timezone`, the default) — the dashboard sends it as `family.pets[].timezone`. A parent
 * can change the family default (`PUT /api/parent/settings`) and each pet's zone
 * (`PUT /api/parent/pets/{id}/timezone`, null = back to the family), each at most once per 24 h.
 * The app suggests switching the pets cared for on this phone when the PHONE's current UTC
 * offset differs from theirs (travelling). Offsets, not names, decide: Europe/Ljubljana and
 * Europe/Vienna are the same clock, so no banner.
 *
 * Nothing here touches the network or storage; UI lives in `components/parent/FamilyTimezoneCard`
 * and `TimezoneSuggestionBanner`, dismissals in `zoneSuggestionPrefs.ts`.
 */

import { ApiError } from '@/api/client';
import type { Language } from '@/i18n/language';

/** Slovenian names of the zones' cities people are likely to pick (others: the IANA city). */
const CITY_SL: Readonly<Record<string, string>> = {
  'Europe/Ljubljana': 'Ljubljana',
  'Europe/Lisbon': 'Lizbona',
  'Europe/London': 'London',
  'Europe/Dublin': 'Dublin',
  'Europe/Paris': 'Pariz',
  'Europe/Madrid': 'Madrid',
  'Europe/Rome': 'Rim',
  'Europe/Vienna': 'Dunaj',
  'Europe/Berlin': 'Berlin',
  'Europe/Zurich': 'Zürich',
  'Europe/Prague': 'Praga',
  'Europe/Budapest': 'Budimpešta',
  'Europe/Zagreb': 'Zagreb',
  'Europe/Belgrade': 'Beograd',
  'Europe/Sarajevo': 'Sarajevo',
  'Europe/Skopje': 'Skopje',
  'Europe/Warsaw': 'Varšava',
  'Europe/Athens': 'Atene',
  'Europe/Istanbul': 'Carigrad',
  'Europe/Moscow': 'Moskva',
  'Europe/Kyiv': 'Kijev',
  'Europe/Brussels': 'Bruselj',
  'Europe/Amsterdam': 'Amsterdam',
  'Europe/Copenhagen': 'København',
  'Europe/Stockholm': 'Stockholm',
  'Europe/Oslo': 'Oslo',
  'Europe/Helsinki': 'Helsinki',
  'Atlantic/Canary': 'Kanarski otoki',
  'America/New_York': 'New York',
  'America/Chicago': 'Chicago',
  'America/Denver': 'Denver',
  'America/Los_Angeles': 'Los Angeles',
  'America/Toronto': 'Toronto',
  'America/Mexico_City': 'Ciudad de México',
  'America/Sao_Paulo': 'São Paulo',
  'Asia/Dubai': 'Dubaj',
  'Asia/Tokyo': 'Tokio',
  'Asia/Shanghai': 'Šanghaj',
  'Asia/Singapore': 'Singapur',
  'Asia/Bangkok': 'Bangkok',
  'Asia/Kolkata': 'Kalkuta',
  'Australia/Sydney': 'Sydney',
  'Pacific/Auckland': 'Auckland',
};

/**
 * Used when the device's Intl can't list zones (`Intl.supportedValuesOf` missing): the zones
 * above plus a spread over every offset. The phone's own zone is always offered first anyway.
 */
const FALLBACK_ZONES: readonly string[] = [
  ...Object.keys(CITY_SL),
  'Atlantic/Azores',
  'Atlantic/Reykjavik',
  'Africa/Casablanca',
  'Africa/Cairo',
  'Africa/Johannesburg',
  'Africa/Nairobi',
  'Africa/Lagos',
  'America/Anchorage',
  'America/Halifax',
  'America/Bogota',
  'America/Lima',
  'America/Argentina/Buenos_Aires',
  'America/Santiago',
  'America/Vancouver',
  'America/Phoenix',
  'Pacific/Honolulu',
  'Asia/Jerusalem',
  'Asia/Tehran',
  'Asia/Karachi',
  'Asia/Kathmandu',
  'Asia/Dhaka',
  'Asia/Jakarta',
  'Asia/Hong_Kong',
  'Asia/Seoul',
  'Asia/Manila',
  'Australia/Perth',
  'Australia/Adelaide',
  'Australia/Brisbane',
];

const formatters = new Map<string, Intl.DateTimeFormat | null>();

function formatterFor(zone: string): Intl.DateTimeFormat | null {
  if (formatters.has(zone)) return formatters.get(zone) ?? null;
  let formatter: Intl.DateTimeFormat | null;
  try {
    formatter = new Intl.DateTimeFormat('en-GB', {
      timeZone: zone,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hourCycle: 'h23',
    });
  } catch {
    formatter = null;
  }
  formatters.set(zone, formatter);
  return formatter;
}

/** Minutes east of UTC of `zone` at `atMs` (Ljubljana in winter → 60), null = unknown zone. */
export function zoneOffsetMinutes(zone: string, atMs: number): number | null {
  const formatter = formatterFor(zone);
  if (!formatter) return null;
  try {
    const parts = formatter.formatToParts(new Date(atMs));
    const get = (type: Intl.DateTimeFormatPartTypes): number => Number(parts.find((p) => p.type === type)?.value);
    const hour = get('hour') === 24 ? 0 : get('hour');
    const wall = Date.UTC(get('year'), get('month') - 1, get('day'), hour, get('minute'), get('second'));
    if (Number.isNaN(wall)) return null;
    const seconds = Math.floor(atMs / 1000) * 1000;
    return Math.round((wall - seconds) / 60000);
  } catch {
    return null;
  }
}

/** "UTC", "UTC+1", "UTC−5", "UTC+5:30" (true minus sign). */
export function formatUtcOffset(minutes: number): string {
  if (minutes === 0) return 'UTC';
  const sign = minutes > 0 ? '+' : '−';
  const abs = Math.abs(minutes);
  const h = Math.floor(abs / 60);
  const m = abs % 60;
  return `UTC${sign}${h}${m === 0 ? '' : `:${String(m).padStart(2, '0')}`}`;
}

/** Friendly city of a zone: Slovenian name when known, else the IANA city ("New_York" → "New York"). */
export function zoneCity(zone: string, language: Language): string {
  if (language === 'sl' && CITY_SL[zone]) return CITY_SL[zone];
  const last = zone.split('/').pop() ?? zone;
  return last.replace(/_/g, ' ');
}

/** "Lizbona · UTC" / "Ljubljana · UTC+1" (unknown offset → the city only). */
export function zoneLabel(zone: string, language: Language, atMs: number): string {
  const offset = zoneOffsetMinutes(zone, atMs);
  const city = zoneCity(zone, language);
  return offset === null ? city : `${city} · ${formatUtcOffset(offset)}`;
}

/**
 * True when the phone's clock now differs from the family's clock now (different current UTC
 * offsets). Unknown zones never differ (no banner on a broken Intl).
 */
export function phoneDiffersFromFamily(familyZone: string | null, phoneZone: string | null, atMs: number): boolean {
  if (!familyZone || !phoneZone || familyZone === phoneZone) return false;
  const family = zoneOffsetMinutes(familyZone, atMs);
  const phone = zoneOffsetMinutes(phoneZone, atMs);
  return family !== null && phone !== null && family !== phone;
}

/**
 * The dashboard banner (parent only): shown while the phone's offset differs from the family's,
 * unless it was dismissed for THIS phone zone — it comes back only in yet another zone.
 */
export function suggestionVisible(input: {
  familyZone: string | null;
  phoneZone: string | null;
  dismissedZone: string | null;
  atMs: number;
}): boolean {
  if (input.phoneZone !== null && input.dismissedZone === input.phoneZone) return false;
  return phoneDiffersFromFamily(input.familyZone, input.phoneZone, input.atMs);
}

type SupportedValuesOf = (key: 'timeZone') => string[];

/** Every IANA zone the device knows (Area/City form), or the fallback list. */
export function allZones(): string[] {
  const supported = (Intl as unknown as { supportedValuesOf?: SupportedValuesOf }).supportedValuesOf;
  let zones: string[] = [];
  if (typeof supported === 'function') {
    try {
      zones = supported('timeZone');
    } catch {
      zones = [];
    }
  }
  const list = zones.length > 0 ? zones : [...FALLBACK_ZONES];
  return [...new Set(list.filter((z) => /^[A-Z][A-Za-z_]+\/[A-Za-z_/-]+$/.test(z)))].sort();
}

/** Lower-case, no diacritics — "Budimpešta" matches "budimpesta". */
function fold(text: string): string {
  return text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

/**
 * Picker rows: the phone's zone first (when known), then every zone matching `query` by its
 * Slovenian / English city or IANA id. No duplicates.
 */
export function zoneChoices(input: { phoneZone: string | null; query: string; zones: readonly string[]; language: Language }): string[] {
  const q = fold(input.query.trim());
  const matches = (zone: string): boolean =>
    q === '' || [zone, zoneCity(zone, 'sl'), zoneCity(zone, 'en')].some((name) => fold(name).includes(q));
  const rows: string[] = [];
  if (input.phoneZone && matches(input.phoneZone)) rows.push(input.phoneZone);
  for (const zone of input.zones) {
    if (zone !== input.phoneZone && matches(zone)) rows.push(zone);
  }
  return rows;
}

export type TimezoneChangeError =
  | { kind: 'tooSoon'; nextAllowedAt: string | null }
  | { kind: 'invalid' }
  | { kind: 'offline' }
  | { kind: 'failed' };

/** Map a failed `PUT /api/parent/settings` / `PUT /api/parent/pets/{id}/timezone` to what the sheet says. */
export function readTimezoneChangeError(error: unknown): TimezoneChangeError {
  if (!(error instanceof ApiError)) return { kind: 'offline' };
  const data = error.data;
  const body = typeof data === 'object' && data !== null ? (data as Record<string, unknown>) : {};
  if (error.status === 422 && body.reason === 'timezone_change_too_soon') {
    return { kind: 'tooSoon', nextAllowedAt: typeof body.next_allowed_at === 'string' ? body.next_allowed_at : null };
  }
  if (error.status === 422) return { kind: 'invalid' };
  return { kind: 'failed' };
}

let phoneZoneOverride: string | null | undefined;

/** The phone's IANA zone right now (null = unknown). */
export function currentPhoneZone(): string | null {
  if (phoneZoneOverride !== undefined) return phoneZoneOverride;
  try {
    const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
    return typeof zone === 'string' && zone.length > 0 ? zone : null;
  } catch {
    return null;
  }
}

/** Test hook: pretend the phone is in `zone` (undefined = the real device zone). */
export function setPhoneZoneForTests(zone: string | null | undefined): void {
  phoneZoneOverride = zone;
}

// ──────────────────────────────────────────────────────────────
//  Time zone per pet (David 2026-11-09)
// ──────────────────────────────────────────────────────────────

/** What the per-pet helpers read of a dashboard pet (`family.pets[]`). */
export interface ZonePet {
  id: number;
  /** The pet's effective zone (own, else the family's) — servers before 2026-11-09 send none. */
  timezone?: unknown;
  /** True = follows the family zone (`pets.timezone` null). */
  timezone_follows_family?: unknown;
  is_active?: unknown;
  is_game_over?: unknown;
  caretakers?: readonly { child_id: number | null }[] | null;
}

/**
 * The zone every time of THIS pet is read in: the server's per-pet `timezone`, else the family
 * zone (older server). Never the phone's zone.
 */
export function petZoneOf(pet: Pick<ZonePet, 'timezone'> | null | undefined, familyZone: string | null): string | null {
  const own = pet?.timezone;
  return typeof own === 'string' && own.length > 0 ? own : familyZone;
}

/** True when the pet follows the family zone (missing flag = older server = it does). */
export function followsFamily(pet: Pick<ZonePet, 'timezone_follows_family'>): boolean {
  return pet.timezone_follows_family !== false;
}

/**
 * The pets the profiles signed in on THIS phone care for: the child sessions this parent opened
 * here (shared phone, M2-11 — the children's profiles and the parent's own caretaker profile).
 * Only active pets.
 */
export function phonePetIds(pets: readonly ZonePet[], phoneChildIds: readonly number[]): number[] {
  if (phoneChildIds.length === 0) return [];
  return pets
    .filter((p) => p.is_active !== false && (p.caretakers ?? []).some((c) => c.child_id !== null && phoneChildIds.includes(c.child_id)))
    .map((p) => p.id);
}

export interface PetZoneCandidate {
  petId: number;
  /** The pet's current effective zone. */
  zone: string;
  /** Pre-checked in the banner: only pets this phone cares for. */
  preselected: boolean;
}

/**
 * The dashboard banner, per pet (David 2026-11-09): every active pet whose clock now differs
 * from the phone's — but only when at least one of them is cared for on this phone (otherwise
 * no banner). This phone's pets first and pre-checked; pets cared for by other family members
 * are listed unchecked. Hidden for a phone zone dismissed with »Ne zdaj«; unknown zones never
 * differ.
 */
export function petZoneSuggestion(input: {
  pets: readonly ZonePet[];
  familyZone: string | null;
  phoneZone: string | null;
  phonePetIds: readonly number[];
  dismissedZone: string | null;
  atMs: number;
}): PetZoneCandidate[] {
  const { phoneZone } = input;
  if (phoneZone === null || input.dismissedZone === phoneZone) return [];
  const candidates: PetZoneCandidate[] = [];
  for (const pet of input.pets) {
    if (pet.is_active === false || pet.is_game_over === true) continue;
    const zone = petZoneOf(pet, input.familyZone);
    if (zone === null || !phoneDiffersFromFamily(zone, phoneZone, input.atMs)) continue;
    candidates.push({ petId: pet.id, zone, preselected: input.phonePetIds.includes(pet.id) });
  }
  if (!candidates.some((c) => c.preselected)) return [];
  return [...candidates.filter((c) => c.preselected), ...candidates.filter((c) => !c.preselected)];
}

/**
 * Parent views: a pet's times carry its zone's city when that clock differs from the phone's
 * (»Lizbona«); null = same clock (or unknown), no label.
 */
export function petZoneNote(petZone: string | null, phoneZone: string | null, atMs: number, language: Language): string | null {
  if (petZone === null || !phoneDiffersFromFamily(petZone, phoneZone, atMs)) return null;
  return zoneCity(petZone, language);
}
