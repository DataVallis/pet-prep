/**
 * M5-08 H6 wiring: mounted once by `ParentDashboardScreen` (the parent app shell). Rules live in
 * `dashboardOpened.ts`; this hook only feeds it the mount, AppState background → active and
 * tapped pushes (`appStore.pushTarget`). Nothing here listens to queries, so polling and refetches
 * never send the signal.
 */

import { useEffect, useRef } from 'react';
import { AppState, type AppStateStatus } from 'react-native';

import { api } from '@/api/client';
import { useAppStore } from '@/store/appStore';
import { DashboardOpenReporter, DashboardOpenSignal } from './dashboardOpened';

const reporter = new DashboardOpenReporter({
  send: (trigger) => api.reportDashboardOpened(trigger),
  now: () => Date.now(),
});

/**
 * Test hook: forget the debounce clock. Call it from the test's own `beforeEach` — never from
 * `jest.afterEnv.ts` (loading this module there binds the real `api` before a test's `jest.mock`).
 */
export function resetDashboardOpenedForTests(): void {
  reporter.reset();
}

export function useDashboardOpenedSignal(userId: number | null): void {
  const pushTarget = useAppStore((s) => s.pushTarget);
  const signalRef = useRef<DashboardOpenSignal | null>(null);

  useEffect(() => {
    if (userId === null) return undefined;
    const signal = new DashboardOpenSignal(userId, reporter, {
      setTimer: (fn, ms) => setTimeout(fn, ms),
      clearTimer: (handle) => clearTimeout(handle as ReturnType<typeof setTimeout>),
    });
    signalRef.current = signal;
    signal.mounted(useAppStore.getState().pushTarget !== null);

    let previous: AppStateStatus = AppState.currentState;
    const subscription = AppState.addEventListener('change', (next) => {
      if (previous === 'background' && next === 'active') signal.foreground();
      previous = next;
    });
    return () => {
      subscription.remove();
      signal.dispose();
      signalRef.current = null;
    };
  }, [userId]);

  // A tapped push (set by `routePushTap`, cleared by the dashboard once routed).
  const hadPush = useRef(pushTarget !== null);
  useEffect(() => {
    const has = pushTarget !== null;
    if (has && !hadPush.current) signalRef.current?.push();
    hadPush.current = has;
  }, [pushTarget]);
}
