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

/** True when `iso` falls on a later family-local day than `nowIso`. */
export function isLaterDay(iso: string | null, nowIso: string | null, timeZone: string | null): boolean {
  if (!iso || !nowIso) return false;
  const target = localParts(iso, timeZone);
  const now = localParts(nowIso, timeZone);
  return target !== null && now !== null && target.date > now.date;
}
