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

function viaIntl(ms: number, timeZone: string): LocalParts | null {
  try {
    const parts = new Intl.DateTimeFormat('en-GB', {
      timeZone,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      hourCycle: 'h23',
    }).formatToParts(new Date(ms));
    const get = (type: Intl.DateTimeFormatPartTypes): string | undefined =>
      parts.find((p) => p.type === type)?.value;
    const year = get('year');
    const month = get('month');
    const day = get('day');
    const hour = get('hour');
    const minute = get('minute');
    if (!year || !month || !day || !hour || !minute) return null;
    return { date: `${year}-${month}-${day}`, time: `${hour === '24' ? '00' : hour}:${minute}` };
  } catch {
    return null;
  }
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
 * "18:30" for a lock end. A server instant carries the family offset already
 * (`state.lock.until`), so its own wall clock is used; a UTC instant (`Z`, e.g. a
 * broadcast's `illness_until`) goes through Intl in `timeZone`.
 */
export function lockClock(iso: string | null, timeZone: string | null): string | null {
  if (!iso) return null;
  if (/[+-]\d{2}:\d{2}$/.test(iso)) {
    const match = ISO_WALL_CLOCK.exec(iso);
    if (match) return `${match[2]}:${match[3]}`;
  }
  return familyClock(iso, timeZone);
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
  try {
    const parts = new Intl.DateTimeFormat('en-GB', {
      timeZone,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hourCycle: 'h23',
    }).formatToParts(new Date(ms));
    const n = (type: Intl.DateTimeFormatPartTypes): number => Number(parts.find((p) => p.type === type)?.value);
    const hour = n('hour') === 24 ? 0 : n('hour');
    const asUtc = Date.UTC(n('year'), n('month') - 1, n('day'), hour, n('minute'), n('second'));
    if (Number.isNaN(asUtc)) return null;
    return Math.round((asUtc - Math.floor(ms / 1000) * 1000) / 60_000);
  } catch {
    return null;
  }
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
