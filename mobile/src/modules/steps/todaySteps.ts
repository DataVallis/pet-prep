/**
 * Today's step total from every source this device has (M3-06), merged by MAXIMUM.
 *
 * Sources of the same person on the same phone overlap: the iPhone motion sensor
 * (CoreMotion) is one of the inputs Apple Health itself aggregates, and the Android live
 * counter sees the same walk Health Connect gets from Google Fit / Samsung Health. Adding
 * them would count one walk twice; taking the larger one is always a real reading of
 * that walk:
 *  - Health with a watch / denied iOS read (0) / not yet synced → the other value wins.
 *  - The day total only grows, and the server keeps the maximum of the day per child
 *    (`pet_daily_steps`), so switching source mid-day never lowers or doubles anything.
 *
 * A source that throws (locked phone, revoked access) simply gives no reading.
 */

import type { HealthSource, HealthStepsAdapter } from './health/types';

export type StepSource = HealthSource | 'pedometer';

export interface StepReading {
  steps: number;
  source: StepSource;
}

/** Larger reading wins; on a tie the health store (it covers more devices). Never a sum. */
export function mergeReadings(readings: readonly StepReading[]): StepReading | null {
  let best: StepReading | null = null;
  for (const reading of readings) {
    if (!Number.isFinite(reading.steps) || reading.steps < 0) continue;
    const steps = Math.floor(reading.steps);
    if (best === null || steps > best.steps || (steps === best.steps && best.source === 'pedometer' && reading.source !== 'pedometer')) {
      best = { steps, source: reading.source };
    }
  }
  return best;
}

export interface TodaySources {
  /** Connected health store (Apple Health / Health Connect), or null. */
  health: HealthStepsAdapter | null;
  /** iOS motion history since a start instant (expo-sensors Pedometer), or null. */
  pedometerHistory: ((start: Date, end: Date) => Promise<number>) | null;
  /** Android live counter total of today, or null. */
  liveTotal: (() => number) | null;
}

/** Read every available source for [dayStart, at] and merge (null = nothing readable). */
export async function readTodaySteps(sources: TodaySources, dayStart: Date, at: Date): Promise<StepReading | null> {
  const readings: StepReading[] = [];
  const { health, pedometerHistory, liveTotal } = sources;
  if (health) {
    try {
      readings.push({ steps: await health.readSteps(dayStart, at), source: health.source });
    } catch {
      // No health reading this time.
    }
  }
  if (pedometerHistory) {
    try {
      readings.push({ steps: await pedometerHistory(dayStart, at), source: 'pedometer' });
    } catch {
      // No sensor reading this time.
    }
  }
  if (liveTotal) readings.push({ steps: liveTotal(), source: 'pedometer' });
  return mergeReadings(readings);
}

/** Minimum gap between AUTOMATIC syncs (foreground flapping, permission sheets); manual ones skip it. */
export const MIN_AUTO_SYNC_GAP_MS = 60_000;

/** True when an automatic sync may run now (`lastAt` = last read attempt in ms, null = never). */
export function autoSyncDue(lastAt: number | null, now: number, gapMs: number = MIN_AUTO_SYNC_GAP_MS): boolean {
  return lastAt === null || now - lastAt >= gapMs || now < lastAt;
}
