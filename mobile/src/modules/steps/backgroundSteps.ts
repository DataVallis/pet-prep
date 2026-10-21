/**
 * Background step sync (M3-06), best effort, iOS only.
 *
 * `expo-background-task` (BGTaskScheduler on iOS) wakes the app at most every
 * ~15 minutes when iOS decides (battery, usage, network) — maybe much later, maybe never
 * on a phone in Low Power Mode. When it runs: read today's total from Apple Health (+ the
 * motion sensor, max-merged) and send it like the open app would. This only makes the
 * parent's dashboard fresher; correctness never depends on it — Health keeps the history,
 * so the next app open catches the whole day up anyway.
 *
 * Android: not registered. Health Connect forbids reads from the background without the
 * extra `READ_HEALTH_DATA_IN_BACKGROUND` permission, which we deliberately don't ask for
 * (minimum data, READ_STEPS only); the open app reads the day's history instead.
 *
 * Child only: the task is registered by the child HUD once Health is connected and
 * unregistered by `logout()`. It only talks to our own API with the stored child token
 * and sends one number (`steps_today`) — the same request the open app makes.
 */

import { Platform } from 'react-native';
import { requireOptionalNativeModule } from 'expo';
import type * as BackgroundTaskModule from 'expo-background-task';
import type * as TaskManagerModule from 'expo-task-manager';
import { Pedometer } from 'expo-sensors';

import { api, getAuthToken, type ChildPetState, type SyncStepsRequest } from '@/api/client';
import { familyCalendar } from '@/modules/childPet/familyTime';
import { normalizeChildState } from '@/modules/childPet/childPetView';
import { getHealthAdapter } from './health/healthAdapter';
import type { HealthStepsAdapter } from './health/types';
import { isoWithOffset } from './stepCounter';
import { readStepsInRanges, readTodaySteps, type TodaySources } from './todaySteps';
import { loadStepWindows, windowsInRange } from './activeWindows';
import { loadChildSessions } from '@/modules/session/profiles';

export const STEP_SYNC_TASK = 'petprep-background-step-sync';

/**
 * `expo-background-task` / `expo-task-manager` call `requireNativeModule` when their JS
 * is evaluated — in a binary built before M3-06 (or a dev client without them) a static
 * import would crash the app at startup (this module is imported from `index.ts`). So
 * they are loaded lazily, only after checking that both native modules exist; without
 * them every function here is a no-op.
 */
export interface TaskModules {
  BackgroundTask: typeof BackgroundTaskModule;
  TaskManager: typeof TaskManagerModule;
}

type NativeProbe = (name: string) => boolean;
const defaultProbe: NativeProbe = (name) => requireOptionalNativeModule(name) != null;
let probe: NativeProbe = defaultProbe;
let cachedModules: TaskModules | null | undefined;

/** The task modules, or null when this binary doesn't have them. Never throws. */
export function loadTaskModules(): TaskModules | null {
  if (cachedModules !== undefined) return cachedModules;
  try {
    if (!probe('ExpoBackgroundTask') || !probe('ExpoTaskManager')) {
      cachedModules = null;
    } else {
      cachedModules = {
        BackgroundTask: require('expo-background-task') as typeof BackgroundTaskModule,
        TaskManager: require('expo-task-manager') as typeof TaskManagerModule,
      };
    }
  } catch {
    cachedModules = null;
  }
  return cachedModules;
}

/** Tests: replace the native-module probe (and forget the cached result). */
export function setNativeModuleProbeForTests(next: NativeProbe | null): void {
  probe = next ?? defaultProbe;
  cachedModules = undefined;
}

/** BGTaskScheduler / WorkManager minimum; iOS treats it as a hint. */
export const STEP_SYNC_TASK_INTERVAL_MIN = 15;

export type BackgroundSyncOutcome = 'synced' | 'up_to_date' | 'skipped' | 'failed';

export interface BackgroundSyncDeps {
  platform: 'ios' | 'android' | 'other';
  health: HealthStepsAdapter | null;
  /** M5-T12: the wheelchair-push adapter of the same store (child in adapted mode). */
  pushesHealth: HealthStepsAdapter | null;
  pedometer: Pick<typeof Pedometer, 'getPermissionsAsync' | 'getStepCountAsync'>;
  getToken: () => Promise<string | null>;
  getChildPet: () => Promise<ChildPetState>;
  syncSteps: (body: SyncStepsRequest) => Promise<unknown>;
  now: () => Date;
  /**
   * M2-11: the active windows of today when the stored token is a shared child session
   * (opened by a parent on this phone), else null (the child's own phone: midnight → now).
   */
  sharedRanges: (token: string, dayStart: Date, at: Date) => Promise<Array<[Date, Date]> | null>;
}

async function defaultSharedRanges(token: string, dayStart: Date, at: Date): Promise<Array<[Date, Date]> | null> {
  const childId = Object.entries(await loadChildSessions()).find(([, s]) => s.token === token)?.[0];
  if (childId === undefined) return null;
  return windowsInRange((await loadStepWindows())[childId] ?? [], dayStart, at);
}

const defaultDeps = (): BackgroundSyncDeps => ({
  platform: Platform.OS === 'ios' ? 'ios' : Platform.OS === 'android' ? 'android' : 'other',
  health: getHealthAdapter(),
  pushesHealth: getHealthAdapter('pushes'),
  pedometer: Pedometer,
  getToken: getAuthToken,
  getChildPet: () => api.getChildPet(),
  syncSteps: (body) => api.syncSteps(body),
  now: () => new Date(),
  sharedRanges: defaultSharedRanges,
});

/** One background run. Never throws. */
export async function runBackgroundStepSync(overrides: Partial<BackgroundSyncDeps> = {}): Promise<BackgroundSyncOutcome> {
  const deps = { ...defaultDeps(), ...overrides };
  try {
    if (deps.platform !== 'ios' || deps.health === null) return 'skipped';
    // A locked Keychain is not a failure (that would make iOS schedule the task less often).
    const token = await deps.getToken().catch(() => null);
    if (!token) return 'skipped';
    if ((await deps.health.availability()) !== 'available') return 'skipped';
    // M5-T12: the child's adapted mode decides the data type (pushes only, no sensor).
    // The pet state is read only when one of the two accesses is connected.
    const stepsConnected = (await deps.health.access()) === 'connected';
    const pushesConnected = deps.pushesHealth !== null && (await deps.pushesHealth.access()) === 'connected';
    if (!stepsConnected && !pushesConnected) return 'skipped';

    const view = normalizeChildState(await deps.getChildPet());
    if (view.lock.is_locked) return 'skipped';
    const adapted = view.steps.adapted_mode;
    const health = adapted ? deps.pushesHealth : deps.health;
    if (health === null || !(adapted ? pushesConnected : stepsConnected)) return 'skipped';

    const at = deps.now();
    const calendar = familyCalendar(view.timezone, view.server_time);
    const today = calendar.dateOf(at.getTime());
    const serverDay = calendar.dateOf(Date.parse(view.server_time));
    // M5-T12: compare with the device part only (walk minutes a parent logged are on top).
    const serverSteps = serverDay === today ? view.steps.my_device_steps_today : 0;

    let pedometerGranted = false;
    try {
      // Adapted mode: the motion sensor counts steps, never pushes — not a source.
      pedometerGranted = !adapted && (await deps.pedometer.getPermissionsAsync()).granted;
    } catch {
      pedometerGranted = false;
    }
    const pedometer = deps.pedometer;
    const sources: TodaySources = {
      health,
      pedometerHistory: pedometerGranted ? async (start, end) => (await pedometer.getStepCountAsync(start, end)).steps : null,
      liveTotal: null,
    };
    const dayStart = new Date(calendar.startOfDay(at.getTime()));
    // M2-11: a shared child session is credited only its active windows (background too).
    const ranges = await deps.sharedRanges(token, dayStart, at);
    const reading = ranges === null ? await readTodaySteps(sources, dayStart, at) : await readStepsInRanges(sources, ranges);
    if (reading === null) return 'failed';
    if (reading.steps <= serverSteps) return 'up_to_date';

    await deps.syncSteps({
      steps_today: reading.steps,
      source: reading.source,
      recorded_at: isoWithOffset(at),
      ...(adapted && reading.source !== 'pedometer' ? { unit: 'pushes' as const } : {}),
    });
    return 'synced';
  } catch {
    return 'failed';
  }
}

/** Values of `BackgroundTaskResult` (Success = 1, Failed = 2), without loading the module. */
export const TASK_RESULT_SUCCESS = 1;
export const TASK_RESULT_FAILED = 2;

/** Task body for TaskManager — `failed` tells the OS it may back off. */
export async function stepSyncTaskBody(): Promise<BackgroundTaskModule.BackgroundTaskResult> {
  const outcome = await runBackgroundStepSync();
  return (outcome === 'failed' ? TASK_RESULT_FAILED : TASK_RESULT_SUCCESS) as BackgroundTaskModule.BackgroundTaskResult;
}

/**
 * Must run at module scope on every JS start (imported from `index.ts`), also when iOS
 * launches the app in the background just for the task.
 */
export function defineStepSyncTask(): void {
  if (Platform.OS !== 'ios') return;
  const modules = loadTaskModules();
  if (modules === null) return;
  try {
    if (modules.TaskManager.isTaskDefined(STEP_SYNC_TASK)) return;
    modules.TaskManager.defineTask(STEP_SYNC_TASK, stepSyncTaskBody);
  } catch {
    // The open-app sync still works.
  }
}

/** Register (idempotent) — called by the child HUD once Apple Health is connected. Never throws. */
export async function registerStepSyncTask(): Promise<boolean> {
  if (Platform.OS !== 'ios') return false;
  const modules = loadTaskModules();
  if (modules === null) return false;
  const { BackgroundTask, TaskManager } = modules;
  try {
    if ((await BackgroundTask.getStatusAsync()) !== BackgroundTask.BackgroundTaskStatus.Available) return false;
    if (await TaskManager.isTaskRegisteredAsync(STEP_SYNC_TASK)) return true;
    await BackgroundTask.registerTaskAsync(STEP_SYNC_TASK, { minimumInterval: STEP_SYNC_TASK_INTERVAL_MIN });
    return true;
  } catch {
    return false;
  }
}

/** Unregister (logout). Never throws. */
export async function unregisterStepSyncTask(): Promise<void> {
  const modules = loadTaskModules();
  if (modules === null) return;
  const { BackgroundTask, TaskManager } = modules;
  try {
    if (await TaskManager.isTaskRegisteredAsync(STEP_SYNC_TASK)) {
      await BackgroundTask.unregisterTaskAsync(STEP_SYNC_TASK);
    }
  } catch {
    // Nothing registered / module missing in this binary.
  }
}
