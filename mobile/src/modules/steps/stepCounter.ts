/**
 * Today's step count of this device (M1-14), sent to `POST /api/child/pet/steps`.
 *
 *  - iOS: `Pedometer.getStepCountAsync(local midnight, now)` — CoreMotion keeps 7 days
 *    of history, so steps walked while the app was closed count too.
 *  - Android: expo-sensors has no history (`getStepCountAsync` is iOS-only; Health
 *    Connect = M3-05). `watchStepCount` reports steps since the subscription started
 *    while the app is open; `LiveStepCounter` adds those deltas to a per-day total kept
 *    in SecureStore per child (no AsyncStorage in the project), reset at midnight.
 *
 * "Today" is the FAMILY-local day (PRODUCT_SPEC §4; the server closes the step day at
 * the family midnight), not the device's — callers pass a family day key.
 *
 * The server keeps the maximum of the day and applies anti-cheat (≤ 200 steps / min),
 * so the app only reports totals — never deltas.
 */

import { t } from '@/i18n';

/** Device-local `YYYY-MM-DD`. */
export function localDateKey(date: Date): string {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

/** Local midnight of `date` on the device. */
export function startOfLocalDay(date: Date): Date {
  const start = new Date(date.getTime());
  start.setHours(0, 0, 0, 0);
  return start;
}

/**
 * ISO 8601 in device local time with an explicit offset, e.g. `2026-10-04T15:30:00+02:00`
 * (the server rejects offset-less strings). `offsetMinutes` = minutes EAST of UTC;
 * defaults to the device's (`-getTimezoneOffset()`).
 */
export function isoWithOffset(date: Date, offsetMinutes: number = -date.getTimezoneOffset()): string {
  const local = new Date(date.getTime() + offsetMinutes * 60_000);
  const pad = (n: number) => String(n).padStart(2, '0');
  const sign = offsetMinutes >= 0 ? '+' : '-';
  const abs = Math.abs(offsetMinutes);
  return (
    `${local.getUTCFullYear()}-${pad(local.getUTCMonth() + 1)}-${pad(local.getUTCDate())}` +
    `T${pad(local.getUTCHours())}:${pad(local.getUTCMinutes())}:${pad(local.getUTCSeconds())}` +
    `${sign}${pad(Math.floor(abs / 60))}:${pad(abs % 60)}`
  );
}

/** The subset of expo-secure-store the counter needs (injectable for tests). */
export interface KeyValueStore {
  getItemAsync(key: string): Promise<string | null>;
  setItemAsync(key: string, value: string): Promise<void>;
}

/** Day key of an instant (family-local `YYYY-MM-DD`). */
export type DayKey = (date: Date) => string;

const LIVE_STEPS_PREFIX = 'petprep_live_steps_today';

/** SecureStore key of a child's live total — per user, so a shared phone never mixes children. */
export function liveStepsKey(userId: number): string {
  return `${LIVE_STEPS_PREFIX}_${userId}`;
}

/** Delete a child's saved total (logout). Best effort. */
export async function clearLiveSteps(
  userId: number | null,
  store: { deleteItemAsync(key: string): Promise<void> },
): Promise<void> {
  if (userId === null) return;
  try {
    await store.deleteItemAsync(liveStepsKey(userId));
  } catch {
    // Nothing to do: a stale total of another day is ignored on load anyway.
  }
}

interface StoredDay {
  date: string;
  steps: number;
}

function parseStored(raw: string | null): StoredDay | null {
  if (!raw) return null;
  try {
    const value: unknown = JSON.parse(raw);
    if (typeof value !== 'object' || value === null) return null;
    const { date, steps } = value as Record<string, unknown>;
    if (typeof date !== 'string' || typeof steps !== 'number' || !Number.isFinite(steps) || steps < 0) return null;
    return { date, steps: Math.floor(steps) };
  } catch {
    return null;
  }
}

/**
 * Android live counter: today's total = stored total of today + deltas while open.
 * A new local day starts again at 0 (steps after midnight belong to the new day).
 */
export class LiveStepCounter {
  private day: StoredDay;

  constructor(
    private readonly store: KeyValueStore,
    private readonly key: string,
    private readonly dayKey: DayKey = localDateKey,
    now: Date = new Date(),
  ) {
    this.day = { date: dayKey(now), steps: 0 };
  }

  /** The day the counter currently counts for. */
  currentDay(now: Date = new Date()): string {
    this.rollOver(now);
    return this.day.date;
  }

  /** Read the saved total (ignored when it's from another day). */
  async load(now: Date = new Date()): Promise<number> {
    let stored: StoredDay | null = null;
    try {
      stored = parseStored(await this.store.getItemAsync(this.key));
    } catch {
      stored = null;
    }
    const today = this.dayKey(now);
    this.day = stored && stored.date === today ? stored : { date: today, steps: Math.max(0, this.valueOn(today)) };
    return this.day.steps;
  }

  private valueOn(date: string): number {
    return this.day.date === date ? this.day.steps : 0;
  }

  private rollOver(now: Date): void {
    const today = this.dayKey(now);
    if (this.day.date !== today) this.day = { date: today, steps: 0 };
  }

  /** Today's total. */
  value(now: Date = new Date()): number {
    this.rollOver(now);
    return this.day.steps;
  }

  /** Count new steps from the live sensor. */
  add(delta: number, now: Date = new Date()): number {
    this.rollOver(now);
    if (Number.isFinite(delta) && delta > 0) this.day.steps += Math.floor(delta);
    return this.day.steps;
  }

  /**
   * Never report less than the server already has for this child today (e.g. storage was
   * wiped) — but only a server value of the SAME family day: after midnight the cached
   * state still holds yesterday's count, which must not be credited to today.
   */
  raiseTo(steps: number, serverDay: string, now: Date = new Date()): number {
    this.rollOver(now);
    if (serverDay === this.day.date && Number.isFinite(steps) && steps > this.day.steps) {
      this.day.steps = Math.floor(steps);
    }
    return this.day.steps;
  }

  async persist(): Promise<void> {
    try {
      await this.store.setItemAsync(this.key, JSON.stringify(this.day));
    } catch {
      // Best effort: the server already has every synced total.
    }
  }
}

/**
 * Turns `watchStepCount` readings (cumulative since the subscription started) into
 * deltas. A smaller reading means the sensor subscription restarted.
 */
export function createDeltaTracker(): (cumulative: number) => number {
  let last = 0;
  return (cumulative: number) => {
    if (!Number.isFinite(cumulative) || cumulative < 0) return 0;
    const delta = cumulative >= last ? cumulative - last : cumulative;
    last = cumulative;
    return delta;
  };
}

/**
 * "4.000" (sl) / "4,000" (en) — thousands separator of the current language
 * (`child:format.thousandsSeparator`, M1-18). Hand-rolled instead of `Intl.NumberFormat`
 * so 4-digit counts are grouped the same on every engine.
 */
export function formatSteps(steps: number): string {
  return String(Math.max(0, Math.floor(steps))).replace(/\B(?=(\d{3})+(?!\d))/g, t('child:format.thousandsSeparator'));
}
