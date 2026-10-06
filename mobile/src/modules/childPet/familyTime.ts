/**
 * Wall-clock helpers in the family's timezone (PRODUCT_SPEC §4: every window, limit
 * and midnight is family-local). The server sends instants as ISO 8601 with the
 * family offset (`2026-10-04T17:00:00+02:00`); broadcasts send UTC. Both are shown
 * as the family's local "HH:MM" using `state.timezone`.
 */

export interface LocalParts {
  /** `YYYY-MM-DD` in the family timezone. */
  date: string;
  /** `HH:MM`, 24 h. */
  time: string;
}

const ISO_WALL_CLOCK = /^(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2})/;

/**
 * One `Intl.DateTimeFormat` per zone (N4): building one is far more expensive than
 * formatting, and the HUD formats on every render. `null` = the device can't do the zone.
 */
const formatterCache = new Map<string, Intl.DateTimeFormat | null>();

function zoneFormatter(timeZone: string): Intl.DateTimeFormat | null {
  if (formatterCache.has(timeZone)) return formatterCache.get(timeZone) ?? null;
  let formatter: Intl.DateTimeFormat | null;
  try {
    formatter = new Intl.DateTimeFormat('en-GB', {
      timeZone,
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
  formatterCache.set(timeZone, formatter);
  return formatter;
}

interface ZoneParts {
  year: string;
  month: string;
  day: string;
  hour: string;
  minute: string;
  second: string;
}

function zoneParts(ms: number, timeZone: string): ZoneParts | null {
  const formatter = zoneFormatter(timeZone);
  if (!formatter) return null;
  try {
    const parts = formatter.formatToParts(new Date(ms));
    const get = (type: Intl.DateTimeFormatPartTypes): string => parts.find((p) => p.type === type)?.value ?? '';
    const result = {
      year: get('year'),
      month: get('month'),
      day: get('day'),
      hour: get('hour') === '24' ? '00' : get('hour'),
      minute: get('minute'),
      second: get('second'),
    };
    return Object.values(result).every((v) => v.length > 0) ? result : null;
  } catch {
    return null;
  }
}

function viaIntl(ms: number, timeZone: string): LocalParts | null {
  const p = zoneParts(ms, timeZone);
  return p ? { date: `${p.year}-${p.month}-${p.day}`, time: `${p.hour}:${p.minute}` } : null;
}

/**
 * Local date + time of an instant in `timeZone`. Falls back to the wall clock written
 * in the ISO string itself (the server already uses the family offset) when the
 * device's Intl can't handle the zone.
 */
export function localParts(iso: string, timeZone: string | null): LocalParts | null {
  const ms = Date.parse(iso);
  if (Number.isNaN(ms)) return null;
  if (timeZone) {
    const parts = viaIntl(ms, timeZone);
    if (parts) return parts;
  }
  const match = ISO_WALL_CLOCK.exec(iso);
  return match ? { date: match[1], time: `${match[2]}:${match[3]}` } : null;
}

/** "17:00" in the family timezone, or null for a missing / broken instant. */
export function familyClock(iso: string | null, timeZone: string | null): string | null {
  if (!iso) return null;
  return localParts(iso, timeZone)?.time ?? null;
}

/**
 * "ob 17:00" when `iso` is on the same family-local day as `nowIso`, "jutri ob 06:00"
 * the next day, else just "ob 17:00" (the child only needs the hour).
 */
export function whenText(iso: string | null, nowIso: string | null, timeZone: string | null): string | null {
  if (!iso) return null;
  const target = localParts(iso, timeZone);
  if (!target) return null;
  const now = nowIso ? localParts(nowIso, timeZone) : null;
  if (now && target.date > now.date) return `jutri ob ${target.time}`;
  return `ob ${target.time}`;
}

/**
 * "18:30" for a lock end in the family zone (N1). The string's own clock time is used
 * only when its offset IS the family offset at that instant (`state.lock.until`), or
 * when the device can't resolve the zone; a UTC value (`illness_until` from a broadcast,
 * `+00:00` / `Z`) for a family elsewhere goes through Intl.
 */
export function lockClock(iso: string | null, timeZone: string | null): string | null {
  if (!iso) return null;
  const ms = Date.parse(iso);
  if (Number.isNaN(ms)) return null;
  const written = isoOffsetMinutes(iso);
  const family = timeZone ? zoneOffsetMinutes(ms, timeZone) : null;
  if (written !== null && (family === null || family === written)) {
    const match = ISO_WALL_CLOCK.exec(iso);
    if (match) return `${match[2]}:${match[3]}`;
  }
  return familyClock(iso, timeZone);
}

/** IANA zone of the device (`Intl`), or null when the runtime can't tell. */
export function deviceTimeZone(): string | null {
  try {
    const zone = new Intl.DateTimeFormat().resolvedOptions().timeZone;
    return typeof zone === 'string' && zone.length > 0 ? zone : null;
  } catch {
    return null;
  }
}

/**
 * Lock end "HH:MM" when the family zone may be unknown (session restored, no state
 * loaded yet — hotfix 2026-10-06): the family zone if known, else the device zone, else
 * the device's local clock. Never the UTC wall clock of a `+00:00` instant.
 */
export function lockClockWithFallback(
  iso: string | null,
  familyZone: string | null,
  deviceZone: string | null = deviceTimeZone(),
): string | null {
  if (!iso) return null;
  const ms = Date.parse(iso);
  if (Number.isNaN(ms)) return null;
  const zone = familyZone ?? deviceZone;
  if (zone !== null && zoneOffsetMinutes(ms, zone) !== null) return lockClock(iso, zone);
  const local = new Date(ms);
  return `${String(local.getHours()).padStart(2, '0')}:${String(local.getMinutes()).padStart(2, '0')}`;
}

/** Minutes east of UTC written in an ISO string (`Z` → 0), or null without one. */
export function isoOffsetMinutes(iso: string | null): number | null {
  if (!iso) return null;
  if (/Z$/i.test(iso)) return 0;
  const match = /([+-])(\d{2}):(\d{2})$/.exec(iso);
  if (!match) return null;
  const minutes = Number(match[2]) * 60 + Number(match[3]);
  return match[1] === '-' ? -minutes : minutes;
}

/** Offset (minutes east of UTC) of `timeZone` at instant `ms`, via Intl; null if unsupported. */
export function zoneOffsetMinutes(ms: number, timeZone: string): number | null {
  const p = zoneParts(ms, timeZone);
  if (!p) return null;
  const asUtc = Date.UTC(Number(p.year), Number(p.month) - 1, Number(p.day), Number(p.hour), Number(p.minute), Number(p.second));
  if (Number.isNaN(asUtc)) return null;
  return Math.round((asUtc - Math.floor(ms / 1000) * 1000) / 60_000);
}

/**
 * The family's calendar (PRODUCT_SPEC §4: "today", midnight, step day are family-local,
 * not device-local). Uses Intl with the family zone; without Intl support it falls back
 * to the offset in a server instant (`state.server_time`), then the device offset.
 */
export interface FamilyCalendar {
  /** `YYYY-MM-DD` of an instant in the family zone. */
  dateOf(ms: number): string;
  /** Instant (ms) of 00:00 family time on the day of `ms`. */
  startOfDay(ms: number): number;
  /** Instant (ms) of the next family midnight after `ms`. */
  nextMidnight(ms: number): number;
}

export function familyCalendar(timeZone: string | null, referenceIso: string | null = null): FamilyCalendar {
  const fallback = isoOffsetMinutes(referenceIso);
  const offsetAt = (ms: number): number => {
    const viaZone = timeZone ? zoneOffsetMinutes(ms, timeZone) : null;
    if (viaZone !== null) return viaZone;
    return fallback ?? -new Date(ms).getTimezoneOffset();
  };
  const ymd = (ms: number): [number, number, number] => {
    const shifted = new Date(ms + offsetAt(ms) * 60_000);
    return [shifted.getUTCFullYear(), shifted.getUTCMonth(), shifted.getUTCDate()];
  };
  const dateOf = (ms: number): string => {
    const [y, m, d] = ymd(ms);
    return `${y}-${String(m + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
  };
  const startOfDay = (ms: number): number => {
    const [y, m, d] = ymd(ms);
    const wallMidnight = Date.UTC(y, m, d);
    const guess = wallMidnight - offsetAt(ms) * 60_000;
    // The offset at midnight can differ from the one at `ms` (DST switch that day).
    return wallMidnight - offsetAt(guess) * 60_000;
  };
  const nextMidnight = (ms: number): number => startOfDay(startOfDay(ms) + 36 * 3_600_000);
  return { dateOf, startOfDay, nextMidnight };
}

/** True when `iso` falls on a later family-local day than `nowIso`. */
export function isLaterDay(iso: string | null, nowIso: string | null, timeZone: string | null): boolean {
  if (!iso || !nowIso) return false;
  const target = localParts(iso, timeZone);
  const now = localParts(nowIso, timeZone);
  return target !== null && now !== null && target.date > now.date;
}
