/**
 * Health step sources (M3-04 / M3-05): Apple Health (HealthKit) on iOS, Health Connect
 * on Android. The app reads ONE number from them — today's step total — and nothing
 * else (no other data types, no writes). Everything behind this interface is native and
 * runs only in a dev / store build; Jest uses `__mocks__/healthAdapter.ts`.
 */

/** Matches the server's `SyncStepsRequest.source` values. */
export type HealthSource = 'healthkit' | 'health_connect';

/**
 * - `available` — the platform store exists and can be asked.
 * - `needs_update` — Android only: Health Connect isn't installed or is too old (Android
 *   9–13 get it from Google Play; the app offers to open the store).
 * - `unavailable` — no health store on this device (iPad without Health, Android < 9,
 *   managed device, Expo Go, build without the native module).
 */
export type HealthAvailability = 'available' | 'needs_update' | 'unavailable';

/**
 * - `connected` — Android: READ_STEPS granted. iOS: the Health sheet was answered.
 *   HealthKit never tells an app whether READ access was denied (Apple privacy rule) —
 *   a denied read just returns no samples, so "connected" on iOS means "asked"; the
 *   max-merge with the motion sensor keeps a denied read harmless.
 * - `undetermined` — never asked: show the kind pre-permission card.
 * - `denied` — Android only: asked and refused (Health Connect stops showing its dialog
 *   after two refusals, so the app sends the child / parent to Health Connect itself).
 */
export type HealthAccess = 'connected' | 'undetermined' | 'denied';

export interface HealthStepsAdapter {
  readonly source: HealthSource;
  /** Whether a health store exists on this device (no prompt). */
  availability(): Promise<HealthAvailability>;
  /** Current access to step data (no prompt). */
  access(): Promise<HealthAccess>;
  /** Show the system permission sheet for step READ access only. */
  requestAccess(): Promise<HealthAccess>;
  /**
   * Total steps in [start, end] — de-duplicated across phone / watch by the platform
   * (HealthKit statistics query, Health Connect aggregate). Throws when it can't read
   * (device locked, revoked, store gone): callers treat that as "no reading".
   */
  readSteps(start: Date, end: Date): Promise<number>;
  /** Where the child / a parent can change the access later. */
  openSettings(): Promise<void>;
  /** Android: Google Play page of Health Connect (install / update). iOS: no-op. */
  openStore(): Promise<void>;
}
