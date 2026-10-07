/**
 * `useStepSync` — keeps the server's step count for today current while the HUD is open
 * (M1-14, health sources M3-04 / M3-05 / M3-06).
 *
 * Sources (all optional, merged by MAXIMUM — never summed, see `todaySteps.ts`):
 *  - Apple Health (iOS) / Health Connect (Android) once the child connected it — these
 *    keep history, so steps walked while the app was closed count on the next sync.
 *  - Fallback, unchanged: iOS motion history (expo-sensors Pedometer, since the family
 *    midnight), Android live counter while the app is open.
 * Triggers: on start, on every return to the foreground and every 5 minutes (automatic
 * triggers at most once a minute), plus "Osveži" / closing the walk overlay (always).
 * Only a total higher than what the server has for this child TODAY is sent. iOS also
 * registers a best-effort background task once Apple Health is connected
 * (`backgroundSteps.ts`). No permission prompt is ever shown on its own — the walk
 * overlay asks (pre-permission card for Health, button for the motion sensor).
 *
 * "Today" is the family-local day (`state.timezone`): the server closes the step day at
 * the family midnight, so a device in another zone must count from that midnight too.
 * A cached server count from an earlier family day (midnight passed while the app was in
 * the background) is never credited to today.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { AppState, Platform, type AppStateStatus } from 'react-native';
import * as SecureStore from 'expo-secure-store';
import { Pedometer } from 'expo-sensors';

import { useSyncSteps } from '@/hooks/queries/useChildActions';
import { familyCalendar } from '@/modules/childPet/familyTime';
import { registerStepSyncTask } from '@/modules/steps/backgroundSteps';
import { getHealthAdapter } from '@/modules/steps/health/healthAdapter';
import type { HealthSource, HealthStepsAdapter } from '@/modules/steps/health/types';
import {
  createDeltaTracker,
  isoWithOffset,
  LiveStepCounter,
  liveStepsKey,
  type KeyValueStore,
} from '@/modules/steps/stepCounter';
import { autoSyncDue, readTodaySteps } from '@/modules/steps/todaySteps';
import { useAppStore } from '@/store/appStore';

export const STEP_SYNC_INTERVAL_MS = 5 * 60_000;

export type StepPermission = 'checking' | 'granted' | 'undetermined' | 'denied' | 'unavailable';

/**
 * Health store state for the overlay: `connected` → it is a source; `undetermined` →
 * pre-permission card; `denied` (Android) / `needs_update` (Android) → retry / store
 * buttons; `unavailable` / `checking` → nothing shown.
 */
export type HealthStatus = 'checking' | 'unavailable' | 'needs_update' | 'undetermined' | 'denied' | 'connected';

/** What the hook needs from the platform (injectable for tests). */
export interface StepSyncDeps {
  platform: 'ios' | 'android' | 'other';
  pedometer: Pick<
    typeof Pedometer,
    'isAvailableAsync' | 'getPermissionsAsync' | 'requestPermissionsAsync' | 'getStepCountAsync' | 'watchStepCount'
  >;
  storage: KeyValueStore;
  now: () => Date;
  /** Apple Health / Health Connect, or null (none on this device / build). */
  health: HealthStepsAdapter | null;
  /** Best-effort background sync registration (iOS). */
  registerBackground: () => Promise<boolean>;
}

const defaultDeps = (): StepSyncDeps => ({
  platform: Platform.OS === 'ios' ? 'ios' : Platform.OS === 'android' ? 'android' : 'other',
  pedometer: Pedometer,
  storage: SecureStore,
  now: () => new Date(),
  health: getHealthAdapter(),
  registerBackground: registerStepSyncTask,
});

interface Options {
  /** False while there is no pet state yet or the pet is locked. */
  enabled: boolean;
  /** This child's steps according to the server (`state.steps.my_steps_today`). */
  myStepsToday: number;
  /** `state.server_time` — the family day `myStepsToday` belongs to. */
  serverTime: string | null;
  /** `state.timezone` — the family's IANA zone. */
  timezone: string | null;
  deps?: Partial<StepSyncDeps>;
}

export interface StepHealth {
  status: HealthStatus;
  /** Which store this device has (null = none). */
  source: HealthSource | null;
  /** Show the system sheet for step READ access (pre-permission card button). */
  connect: () => Promise<void>;
  /** Health Connect app (Android) / the app's Settings page (iOS) — retry after a refusal. */
  openSettings: () => Promise<void>;
  /** Google Play page of Health Connect (install / update). */
  openStore: () => Promise<void>;
}

export interface StepSync {
  /** Motion-sensor permission (iOS Pedometer / Android activity recognition). */
  permission: StepPermission;
  /** Ask for the motion / activity permission (walk overlay button). */
  requestPermission: () => Promise<void>;
  /** Read today's steps and send them if the server doesn't have them yet (no throttle). */
  syncNow: () => Promise<void>;
  isSyncing: boolean;
  /** True when at least one source can be read (sensor granted or health connected). */
  canSync: boolean;
  health: StepHealth;
}

function permissionFrom(response: { status: string; granted: boolean }): StepPermission {
  if (response.granted || response.status === 'granted') return 'granted';
  return response.status === 'denied' ? 'denied' : 'undetermined';
}

async function healthStatusOf(health: HealthStepsAdapter | null): Promise<HealthStatus> {
  if (health === null) return 'unavailable';
  const availability = await health.availability();
  if (availability !== 'available') return availability;
  return health.access();
}

export function useStepSync({ enabled, myStepsToday, serverTime, timezone, deps: depsOverride }: Options): StepSync {
  const depsRef = useRef<StepSyncDeps | null>(null) as { current: StepSyncDeps };
  depsRef.current ??= { ...defaultDeps(), ...depsOverride };
  const userId = useAppStore((s) => s.user?.id ?? null);
  const [permission, setPermission] = useState<StepPermission>('checking');
  const [healthStatus, setHealthStatus] = useState<HealthStatus>('checking');
  const { mutateAsync, isPending } = useSyncSteps();

  const healthConnected = healthStatus === 'connected';
  const canSync = permission === 'granted' || healthConnected;

  const calendar = useMemo(() => familyCalendar(timezone, serverTime), [timezone, serverTime]);
  const calendarRef = useRef(calendar);
  calendarRef.current = calendar;

  // The server's count and the family day it belongs to.
  const serverRef = useRef({ steps: myStepsToday, day: serverTime ? calendar.dateOf(Date.parse(serverTime)) : null });
  serverRef.current = { steps: myStepsToday, day: serverTime ? calendar.dateOf(Date.parse(serverTime)) : null };

  const counterRef = useRef<LiveStepCounter | null>(null);
  /** The last `persist()` of a previous counter — the next `load()` waits for it (m2). */
  const persistChainRef = useRef<Promise<void>>(Promise.resolve());

  /**
   * Save a child's counter only while that child is still signed in (N3): `logout()`
   * deletes the key before the HUD unmounts, and the unmount must not write it back.
   */
  const persistIfSignedIn = useCallback((counter: LiveStepCounter, owner: number) => {
    if (useAppStore.getState().user?.id !== owner) return;
    persistChainRef.current = counter.persist();
  }, []);
  const counterOwnerRef = useRef<number | null>(null);
  const inFlightRef = useRef(false);
  /** Last read attempt (ms) — throttles automatic triggers. */
  const lastAttemptRef = useRef<number | null>(null);

  // 1a. Motion sensor: availability + current permission (no prompt).
  useEffect(() => {
    if (!enabled) return;
    let cancelled = false;
    const { pedometer, platform } = depsRef.current;
    void (async () => {
      try {
        if (platform === 'other' || !(await pedometer.isAvailableAsync())) {
          if (!cancelled) setPermission('unavailable');
          return;
        }
        const response = await pedometer.getPermissionsAsync();
        if (!cancelled) setPermission(permissionFrom(response));
      } catch {
        if (!cancelled) setPermission('unavailable');
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [enabled]);

  // 1b. Health store: availability + access (no prompt). Re-checked on foreground —
  // the child / a parent may have changed it in Health Connect or Settings meanwhile.
  const refreshHealth = useCallback(async () => {
    try {
      setHealthStatus(await healthStatusOf(depsRef.current.health));
    } catch {
      setHealthStatus('unavailable');
    }
  }, []);
  useEffect(() => {
    if (!enabled) return;
    void refreshHealth();
  }, [enabled, refreshHealth]);

  // 1c. iOS: once Apple Health is connected, let iOS wake us now and then (best effort).
  useEffect(() => {
    if (!enabled || !healthConnected || depsRef.current.platform !== 'ios') return;
    void depsRef.current.registerBackground();
  }, [enabled, healthConnected]);

  /** The server's count if it is for the family day of `at`, else 0 (new day). */
  const serverStepsOn = useCallback((day: string): number => {
    const server = serverRef.current;
    return server.day === day ? server.steps : 0;
  }, []);

  const healthConnectedRef = useRef(healthConnected);
  healthConnectedRef.current = healthConnected;
  const permissionRef = useRef(permission);
  permissionRef.current = permission;

  const readToday = useCallback(async (at: Date) => {
    const { platform, pedometer, health } = depsRef.current;
    const cal = calendarRef.current;
    const sensorGranted = permissionRef.current === 'granted';
    const counter = counterRef.current;
    if (platform === 'android' && counter) {
      const server = serverRef.current;
      if (server.day !== null) counter.raiseTo(server.steps, server.day, at);
    }
    return readTodaySteps(
      {
        health: healthConnectedRef.current ? health : null,
        pedometerHistory:
          platform === 'ios' && sensorGranted
            ? async (start, end) => (await pedometer.getStepCountAsync(start, end)).steps
            : null,
        liveTotal: platform === 'android' && sensorGranted && counter ? () => counter.value(at) : null,
      },
      new Date(cal.startOfDay(at.getTime())),
      at,
    );
  }, []);

  const runSync = useCallback(
    async (force: boolean) => {
      if (!enabled || !canSync || inFlightRef.current) return;
      const at = depsRef.current.now();
      if (!force && !autoSyncDue(lastAttemptRef.current, at.getTime())) return;
      inFlightRef.current = true;
      lastAttemptRef.current = at.getTime();
      try {
        const reading = await readToday(at);
        const today = calendarRef.current.dateOf(at.getTime());
        if (reading === null || reading.steps <= serverStepsOn(today)) return;
        await mutateAsync({ stepsToday: reading.steps, recordedAt: isoWithOffset(at), source: reading.source });
        const counter = counterRef.current;
        const owner = counterOwnerRef.current;
        if (counter && owner !== null) persistIfSignedIn(counter, owner);
      } catch {
        // Quiet: offline / 423 / 5xx — the next trigger tries again.
      } finally {
        inFlightRef.current = false;
      }
    },
    [enabled, canSync, readToday, serverStepsOn, mutateAsync, persistIfSignedIn],
  );

  const syncNow = useCallback(() => runSync(true), [runSync]);
  const runSyncRef = useRef(runSync);
  runSyncRef.current = runSync;
  const autoSyncRef = useRef(() => runSync(false));
  autoSyncRef.current = () => runSync(false);

  // 2. Android: live counter while the app is open, per child, family day.
  useEffect(() => {
    const { platform, pedometer, storage, now } = depsRef.current;
    if (!enabled || permission !== 'granted' || platform !== 'android' || userId === null) return;
    const dayKey = (date: Date) => calendarRef.current.dateOf(date.getTime());
    const counter = new LiveStepCounter(storage, liveStepsKey(userId), dayKey, now());
    counterRef.current = counter;
    counterOwnerRef.current = userId;
    const toDelta = createDeltaTracker();
    let subscription: { remove: () => void } | null = null;
    let cancelled = false;
    void persistChainRef.current
      .then(() => counter.load(depsRef.current.now()))
      .then(() => {
        if (cancelled) return;
        subscription = pedometer.watchStepCount((result) => {
          counter.add(toDelta(result.steps), depsRef.current.now());
        });
        // The saved total may be ahead of the server (e.g. persisted right before a pause).
        void runSyncRef.current(true);
      });
    return () => {
      cancelled = true;
      subscription?.remove();
      persistIfSignedIn(counter, userId);
      if (counterRef.current === counter) {
        counterRef.current = null;
        counterOwnerRef.current = null;
      }
    };
  }, [enabled, permission, userId, persistIfSignedIn]);

  // 3. Triggers: now, on foreground, every 5 minutes (automatic → throttled).
  useEffect(() => {
    if (!enabled || !canSync) return;
    void runSyncRef.current(true);
    const interval = setInterval(() => {
      void autoSyncRef.current();
    }, STEP_SYNC_INTERVAL_MS);
    const subscription = AppState.addEventListener('change', (state: AppStateStatus) => {
      if (state === 'active') {
        void autoSyncRef.current();
      } else {
        const counter = counterRef.current;
        const owner = counterOwnerRef.current;
        if (counter && owner !== null) persistIfSignedIn(counter, owner);
      }
    });
    return () => {
      clearInterval(interval);
      subscription.remove();
    };
  }, [enabled, canSync, persistIfSignedIn]);

  // 3b. Foreground: re-check Health access (changed in Health Connect / Settings).
  useEffect(() => {
    if (!enabled || depsRef.current.health === null) return;
    const subscription = AppState.addEventListener('change', (state: AppStateStatus) => {
      if (state === 'active') void refreshHealth();
    });
    return () => subscription.remove();
  }, [enabled, refreshHealth]);

  const requestPermission = useCallback(async () => {
    try {
      const response = await depsRef.current.pedometer.requestPermissionsAsync();
      setPermission(permissionFrom(response));
    } catch {
      setPermission('unavailable');
    }
  }, []);

  const connect = useCallback(async () => {
    const { health, platform } = depsRef.current;
    if (health === null) return;
    try {
      setHealthStatus(await health.requestAccess());
    } catch {
      await refreshHealth();
    }
    // iOS hides a denied Health read (it returns 0 steps): ask for the motion sensor too,
    // right after the Health sheet, so the max-merge always has a real second source.
    if (platform === 'ios' && permissionRef.current === 'undetermined') await requestPermission();
  }, [refreshHealth, requestPermission]);

  const openSettings = useCallback(async () => {
    try {
      await depsRef.current.health?.openSettings();
    } catch {
      // Nothing to open on this device.
    }
  }, []);

  const openStore = useCallback(async () => {
    try {
      await depsRef.current.health?.openStore();
    } catch {
      // No store app (e.g. a phone without Google Play).
    }
  }, []);

  const healthSource = depsRef.current.health?.source ?? null;
  const health = useMemo<StepHealth>(
    () => ({ status: healthStatus, source: healthSource, connect, openSettings, openStore }),
    [healthStatus, healthSource, connect, openSettings, openStore],
  );

  return { permission, requestPermission, syncNow, isSyncing: isPending, canSync, health };
}
