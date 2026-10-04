/**
 * `useStepSync` — keeps the server's step count for today current while the HUD is open
 * (M1-14): once permission is granted, on every return to the foreground and every
 * 5 minutes. Only a total higher than what the server has for this child TODAY is sent.
 * The permission prompt is never shown on its own — the walk overlay asks for it.
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
import {
  createDeltaTracker,
  isoWithOffset,
  LiveStepCounter,
  liveStepsKey,
  type KeyValueStore,
} from '@/modules/steps/stepCounter';
import { useAppStore } from '@/store/appStore';

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
  /** This child's steps according to the server (`state.steps.my_steps_today`). */
  myStepsToday: number;
  /** `state.server_time` — the family day `myStepsToday` belongs to. */
  serverTime: string | null;
  /** `state.timezone` — the family's IANA zone. */
  timezone: string | null;
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

export function useStepSync({ enabled, myStepsToday, serverTime, timezone, deps: depsOverride }: Options): StepSync {
  const depsRef = useRef<StepSyncDeps>({ ...defaultDeps, ...depsOverride });
  const userId = useAppStore((s) => s.user?.id ?? null);
  const [permission, setPermission] = useState<StepPermission>('checking');
  const { mutateAsync, isPending } = useSyncSteps();

  const calendar = useMemo(() => familyCalendar(timezone, serverTime), [timezone, serverTime]);
  const calendarRef = useRef(calendar);
  calendarRef.current = calendar;

  // The server's count and the family day it belongs to.
  const serverRef = useRef({ steps: myStepsToday, day: serverTime ? calendar.dateOf(Date.parse(serverTime)) : null });
  serverRef.current = { steps: myStepsToday, day: serverTime ? calendar.dateOf(Date.parse(serverTime)) : null };

  const counterRef = useRef<LiveStepCounter | null>(null);
  /** The last `persist()` of a previous counter — the next `load()` waits for it (m2). */
  const persistChainRef = useRef<Promise<void>>(Promise.resolve());
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

  /** The server's count if it is for the family day of `at`, else 0 (new day). */
  const serverStepsOn = useCallback((day: string): number => {
    const server = serverRef.current;
    return server.day === day ? server.steps : 0;
  }, []);

  const readToday = useCallback(async (at: Date): Promise<number | null> => {
    const { platform, pedometer } = depsRef.current;
    const cal = calendarRef.current;
    if (platform === 'ios') {
      const start = new Date(cal.startOfDay(at.getTime()));
      const result = await pedometer.getStepCountAsync(start, at);
      return Math.max(0, Math.floor(result.steps));
    }
    const counter = counterRef.current;
    if (!counter) return null;
    const server = serverRef.current;
    if (server.day !== null) counter.raiseTo(server.steps, server.day, at);
    return counter.value(at);
  }, []);

  const syncNow = useCallback(async () => {
    if (!enabled || permission !== 'granted' || inFlightRef.current) return;
    inFlightRef.current = true;
    try {
      const at = depsRef.current.now();
      const steps = await readToday(at);
      const today = calendarRef.current.dateOf(at.getTime());
      if (steps === null || steps <= serverStepsOn(today)) return;
      await mutateAsync({ stepsToday: steps, recordedAt: isoWithOffset(at) });
      const counter = counterRef.current;
      if (counter) persistChainRef.current = counter.persist();
    } catch {
      // Quiet: offline / 423 / 5xx — the next trigger tries again.
    } finally {
      inFlightRef.current = false;
    }
  }, [enabled, permission, readToday, serverStepsOn, mutateAsync]);

  const syncRef = useRef(syncNow);
  syncRef.current = syncNow;

  // 2. Android: live counter while the app is open, per child, family day.
  useEffect(() => {
    const { platform, pedometer, storage, now } = depsRef.current;
    if (!enabled || permission !== 'granted' || platform !== 'android' || userId === null) return;
    const dayKey = (date: Date) => calendarRef.current.dateOf(date.getTime());
    const counter = new LiveStepCounter(storage, liveStepsKey(userId), dayKey, now());
    counterRef.current = counter;
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
        void syncRef.current();
      });
    return () => {
      cancelled = true;
      subscription?.remove();
      persistChainRef.current = counter.persist();
      if (counterRef.current === counter) counterRef.current = null;
    };
  }, [enabled, permission, userId]);

  // 3. Triggers: now, on foreground, every 5 minutes.
  useEffect(() => {
    if (!enabled || permission !== 'granted') return;
    void syncRef.current();
    const interval = setInterval(() => {
      void syncRef.current();
    }, STEP_SYNC_INTERVAL_MS);
    const subscription = AppState.addEventListener('change', (state: AppStateStatus) => {
      if (state === 'active') {
        void syncRef.current();
      } else {
        const counter = counterRef.current;
        if (counter) persistChainRef.current = counter.persist();
      }
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
