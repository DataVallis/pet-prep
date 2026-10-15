/**
 * M5-F13: while the "Updating PetPrep" banner shows, ask the server's health route
 * (`GET /up`) now and then; the moment the server answers again the banner hides and
 * every active query refetches, so the screens show fresh data without a tap — also
 * queries that already gave up retrying, and also missed realtime broadcasts.
 *
 * Bounded: one probe in flight, delays from `probeDelayMs` (the server's `Retry-After`,
 * else 3 s … 30 s), no probes in the background (the foreground refetch covers it).
 */

import { useContext, useEffect } from 'react';
import { AppState } from 'react-native';
import { QueryClientContext, type QueryClient } from '@tanstack/react-query';

import { probeServer } from '@/api/client';
import { probeDelayMs } from '@/modules/connectivity/serverOutage';
import { useServerStatusStore } from '@/store/serverStatusStore';

export interface RecoveryDeps {
  /** Ask the server; true = it answers normally (reports to the store itself). */
  probe: () => Promise<boolean>;
  /** The outage ended — refetch what the screens show. */
  onRecovered: () => void;
  /** Whether the app is in the foreground (no probes in the background). */
  isForeground?: () => boolean;
  /**
   * Calls the listener when the app comes to the foreground (QA n1: probe at once — the
   * deploy may have ended while the app was in the background). Returns the unsubscribe.
   */
  onForeground?: (listener: () => void) => () => void;
}

/**
 * Watch `serverStatusStore`: probe while `outage`, call `onRecovered` once per outage when
 * it ends (by a probe or by any other request that succeeded). Returns the stop function.
 */
export function startServerRecovery({
  probe,
  onRecovered,
  isForeground = () => true,
  onForeground,
}: RecoveryDeps): () => void {
  let timer: ReturnType<typeof setTimeout> | null = null;
  let attempt = 0;
  let probing = false;
  let stopped = false;

  const clear = () => {
    if (timer !== null) clearTimeout(timer);
    timer = null;
  };

  const schedule = () => {
    clear();
    if (stopped || !useServerStatusStore.getState().outage) return;
    const { retryAfterMs } = useServerStatusStore.getState();
    timer = setTimeout(() => {
      timer = null;
      void runProbe();
    }, probeDelayMs(attempt, retryAfterMs));
  };

  const runProbe = async () => {
    if (stopped || probing || !useServerStatusStore.getState().outage) return;
    if (!isForeground()) {
      schedule();
      return;
    }
    probing = true;
    attempt += 1;
    try {
      await probe();
    } finally {
      probing = false;
    }
    // Recovered → the store listener handles it; still down → next probe.
    if (useServerStatusStore.getState().outage) schedule();
  };

  const unsubscribe = useServerStatusStore.subscribe((state, previous) => {
    if (stopped) return;
    if (state.outage && !previous.outage) {
      attempt = 0;
      schedule();
    } else if (!state.outage && previous.outage) {
      clear();
      attempt = 0;
      onRecovered();
    }
  });

  // Back in the foreground during an outage → probe now instead of waiting for the timer.
  const removeForeground = onForeground?.(() => {
    if (stopped || probing || !useServerStatusStore.getState().outage) return;
    clear();
    void runProbe();
  });

  // Mounted during an outage (e.g. a remount) → start probing now.
  if (useServerStatusStore.getState().outage) schedule();

  return () => {
    stopped = true;
    clear();
    unsubscribe();
    removeForeground?.();
  };
}

/** Refetch everything a screen shows; inactive queries are only marked stale. */
export function refetchAfterOutage(client: QueryClient): void {
  void client.invalidateQueries({ refetchType: 'active' });
}

/** Mount once inside the QueryClientProvider (AppNavigator); without a provider it only probes. */
export function useServerRecovery(): void {
  const client = useContext(QueryClientContext);
  useEffect(
    () =>
      startServerRecovery({
        probe: () => probeServer(),
        onRecovered: () => {
          if (client) refetchAfterOutage(client);
        },
        isForeground: () => AppState.currentState === 'active',
        onForeground: (listener) => {
          const subscription = AppState.addEventListener('change', (state) => {
            if (state === 'active') listener();
          });
          return () => subscription.remove();
        },
      }),
    [client],
  );
}
