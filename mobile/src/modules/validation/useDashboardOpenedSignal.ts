/**
 * M5-08 H6 wiring: mounted once by `ParentDashboardScreen` (the parent app shell). Rules live in
 * `dashboardOpened.ts`; this hook only feeds it the mount, AppState background → (inactive →) active and
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

/**
 * `userId` = the signed-in parent (the hook stays mounted per parent); `enabled` = the admin switch
 * »Validation signals« for the family (null while unknown). The switch only gates SENDING — a refetch
 * that turns it on never counts as an open (QA #1500 m4).
 */
export function useDashboardOpenedSignal(userId: number | null, enabled: boolean | null): void {
  const pushTarget = useAppStore((s) => s.pushTarget);
  const signalRef = useRef<DashboardOpenSignal | null>(null);
  const enabledRef = useRef(enabled);
  enabledRef.current = enabled;

  useEffect(() => {
    if (userId === null) return undefined;
    const signal = new DashboardOpenSignal(userId, reporter, {
      setTimer: (fn, ms) => setTimeout(fn, ms),
      clearTimer: (handle) => clearTimeout(handle as ReturnType<typeof setTimeout>),
    });
    signalRef.current = signal;
    signal.setEnabled(enabledRef.current);
    signal.mounted(useAppStore.getState().pushTarget !== null);

    // QA #1479 m3: iOS often goes background → inactive → active. The flag survives `inactive` and is
    // cleared at the next `active`; inactive → active alone (control centre, a system sheet) is no new open.
    let wasBackground = AppState.currentState === 'background';
    const subscription = AppState.addEventListener('change', (next: AppStateStatus) => {
      if (next === 'background') {
        wasBackground = true;
      } else if (next === 'active') {
        if (wasBackground) signal.foreground();
        wasBackground = false;
      }
    });
    return () => {
      subscription.remove();
      signal.dispose();
      signalRef.current = null;
    };
  }, [userId]);

  useEffect(() => {
    signalRef.current?.setEnabled(enabled);
  }, [enabled]);

  // A tapped push (set by `routePushTap`, cleared by the dashboard once routed).
  const hadPush = useRef(pushTarget !== null);
  useEffect(() => {
    const has = pushTarget !== null;
    if (has && !hadPush.current) signalRef.current?.push();
    hadPush.current = has;
  }, [pushTarget]);
}
