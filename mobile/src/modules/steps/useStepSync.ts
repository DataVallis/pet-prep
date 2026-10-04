/**
 * `useStepSync` — keeps the server's step count for today current while the HUD is open
 * (M1-14): once permission is granted, on every return to the foreground and every
 * 5 minutes. Only a total higher than what the server has for this child is sent.
 * The permission prompt is never shown on its own — the walk overlay asks for it.
 */

import { useCallback, useEffect, useRef, useState } from 'react';
import { AppState, Platform, type AppStateStatus } from 'react-native';
import * as SecureStore from 'expo-secure-store';
import { Pedometer } from 'expo-sensors';

import { useSyncSteps } from '@/hooks/queries/useChildActions';
import {
  createDeltaTracker,
  isoWithOffset,
  LiveStepCounter,
  startOfLocalDay,
  type KeyValueStore,
} from '@/modules/steps/stepCounter';

export const STEP_SYNC_INTERVAL_MS = 5 * 60_000;

export type StepPermission = 'checking' | 'granted' | 'undetermined' | 'denied' | 'unavailable';

/** What the hook needs from the platform (injectable for tests). */
export interface StepSyncDeps {
  platform: 'ios' | 'android' | 'other';
  pedometer: Pick<
    typeof Pedometer,
    'isAvailableAsync' | 'getPermissionsAsync' | 'requestPermissionsAsync' | 'getStepCountAsync' | 'watchStepCount'
  >;
  storage: KeyValueStore;
  now: () => Date;
}

const defaultDeps: StepSyncDeps = {
  platform: Platform.OS === 'ios' ? 'ios' : Platform.OS === 'android' ? 'android' : 'other',
  pedometer: Pedometer,
  storage: SecureStore,
  now: () => new Date(),
};

interface Options {
  /** False while there is no pet state yet or the pet is locked. */
  enabled: boolean;
  /** This child's steps today according to the server (`state.steps.my_steps_today`). */
  myStepsToday: number;
  deps?: Partial<StepSyncDeps>;
}

export interface StepSync {
  permission: StepPermission;
  /** Ask for the motion / activity permission (walk overlay button). */
  requestPermission: () => Promise<void>;
  /** Read today's steps and send them if the server doesn't have them yet. */
  syncNow: () => Promise<void>;
  isSyncing: boolean;
}

function permissionFrom(response: { status: string; granted: boolean }): StepPermission {
  if (response.granted || response.status === 'granted') return 'granted';
  return response.status === 'denied' ? 'denied' : 'undetermined';
}

export function useStepSync({ enabled, myStepsToday, deps: depsOverride }: Options): StepSync {
  const depsRef = useRef<StepSyncDeps>({ ...defaultDeps, ...depsOverride });
  const [permission, setPermission] = useState<StepPermission>('checking');
  const mutation = useSyncSteps();
  const { mutateAsync, isPending } = mutation;

  const serverStepsRef = useRef(myStepsToday);
  serverStepsRef.current = myStepsToday;
  const counterRef = useRef<LiveStepCounter | null>(null);
  const inFlightRef = useRef(false);

  // 1. Availability + current permission (no prompt).
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

  const readToday = useCallback(async (): Promise<number | null> => {
    const { platform, pedometer, now } = depsRef.current;
    const at = now();
    if (platform === 'ios') {
      const result = await pedometer.getStepCountAsync(startOfLocalDay(at), at);
      return Math.max(0, Math.floor(result.steps));
    }
    const counter = counterRef.current;
    if (!counter) return null;
    counter.raiseTo(serverStepsRef.current, at);
    return counter.value(at);
  }, []);

  const syncNow = useCallback(async () => {
    if (!enabled || permission !== 'granted' || inFlightRef.current) return;
    inFlightRef.current = true;
    try {
      const steps = await readToday();
      if (steps === null || steps <= serverStepsRef.current) return;
      const recordedAt = isoWithOffset(depsRef.current.now());
      await mutateAsync({ stepsToday: steps, recordedAt });
      await counterRef.current?.persist();
    } catch {
      // Quiet: offline / 423 / 5xx — the next trigger tries again.
    } finally {
      inFlightRef.current = false;
    }
  }, [enabled, permission, readToday, mutateAsync]);

  // 2. Android: live counter while the app is open.
  useEffect(() => {
    const { platform, pedometer, storage, now } = depsRef.current;
    if (!enabled || permission !== 'granted' || platform !== 'android') return;
    const counter = new LiveStepCounter(storage, now());
    counterRef.current = counter;
    const toDelta = createDeltaTracker();
    let subscription: { remove: () => void } | null = null;
    let cancelled = false;
    void counter.load(now()).then(() => {
      if (cancelled) return;
      subscription = pedometer.watchStepCount((result) => {
        counter.add(toDelta(result.steps), depsRef.current.now());
      });
    });
    return () => {
      cancelled = true;
      subscription?.remove();
      void counter.persist();
      counterRef.current = null;
    };
  }, [enabled, permission]);

  // 3. Triggers: now, on foreground, every 5 minutes.
  const syncRef = useRef(syncNow);
  syncRef.current = syncNow;
  useEffect(() => {
    if (!enabled || permission !== 'granted') return;
    void syncRef.current();
    const interval = setInterval(() => {
      void syncRef.current();
    }, STEP_SYNC_INTERVAL_MS);
    const subscription = AppState.addEventListener('change', (state: AppStateStatus) => {
      if (state === 'active') void syncRef.current();
      else void counterRef.current?.persist();
    });
    return () => {
      clearInterval(interval);
      subscription.remove();
    };
  }, [enabled, permission]);

  const requestPermission = useCallback(async () => {
    try {
      const response = await depsRef.current.pedometer.requestPermissionsAsync();
      setPermission(permissionFrom(response));
    } catch {
      setPermission('unavailable');
    }
  }, []);

  return { permission, requestPermission, syncNow, isSyncing: isPending };
}
