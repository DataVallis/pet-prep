/**
 * Runs the session restore once on mount and exposes a retry for the offline
 * state. Also wires the API client's 401 hook to a local logout, so a token
 * revoked while the app is open sends the user back to login.
 *
 * A `locked` restore (Keychain unreadable: iOS launched the app in the background while
 * the phone was locked) keeps the boot pending and never signs anyone out (fix 2026-10-17).
 *
 * Boot-hang fix 2026-10-19 — the launch splash can never spin forever:
 *  - every await in `restoreSession` is bounded (Keychain, `/api/user`, optional reads);
 *  - `locked` while the app is not in the background retries by itself with backoff
 *    ({@link LOCKED_RETRY_DELAYS_MS}), then shows the offline screen — no foreground event needed;
 *  - a watchdog: still `restoring` after {@link BOOT_WATCHDOG_MS} with the app on screen →
 *    the run is abandoned and the offline screen ("Poskusi znova" / "Odjava") shows. The
 *    token is kept: a transient failure never signs anyone out.
 */

import { useCallback, useEffect, useRef } from 'react';
import { AppState } from 'react-native';

import { setUnauthorizedHandler } from '@/api/client';
import { useAppStore } from '@/store/appStore';
import { logout } from './logout';
import { migrateLegacyProfiles, restoreSession } from './restoreSession';
import { retryPendingRevokes } from './profiles';

/** Backoff of the automatic retries after a `locked` restore while the app is on screen. */
export const LOCKED_RETRY_DELAYS_MS = [500, 1_000, 2_000, 4_000] as const;
/** `locked` results in a row on screen before the boot shows the offline screen (retry button). */
export const MAX_FOREGROUND_LOCKED = LOCKED_RETRY_DELAYS_MS.length + 1;
/**
 * The splash never waits longer than this (app on screen) before offering Try again / Sign
 * out. Above a single restore's own worst case (`RESTORE_BUDGET_MS` in restoreSession.ts), so the
 * watchdog only fires for something that truly hangs.
 */
export const BOOT_WATCHDOG_MS = 26_000;

/** iOS reports `background` for a background launch; anything else may be on screen. */
function maybeOnScreen(): boolean {
  return AppState.currentState !== 'background';
}

export function useSessionBootstrap(): { retry: () => void } {
  // Only the newest run may apply its result (e.g. a quick double "Poskusi znova",
  // or a remount while an older restore is still in flight).
  const runIdRef = useRef(0);
  // The last run ended `locked`: retry once the app is in the foreground (phone unlocked).
  const retryOnForegroundRef = useRef(false);
  const foregroundLockedRef = useRef(0);

  const retryTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const watchdogRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  const clearTimer = (ref: { current: ReturnType<typeof setTimeout> | null }) => {
    if (ref.current !== null) clearTimeout(ref.current);
    ref.current = null;
  };

  const showOffline = useCallback(() => {
    // Never the login screen: the token stays, "Poskusi znova" runs the restore again.
    retryOnForegroundRef.current = false;
    foregroundLockedRef.current = 0;
    clearTimer(retryTimerRef);
    clearTimer(watchdogRef);
    useAppStore.getState().setBootStatus('offline');
  }, []);

  const armWatchdog = useCallback(() => {
    clearTimer(watchdogRef);
    const check = () => {
      watchdogRef.current = null;
      if (useAppStore.getState().bootStatus !== 'restoring') return;
      // A background launch may wait for the foreground; nobody sees the splash there.
      if (!maybeOnScreen()) {
        watchdogRef.current = setTimeout(check, BOOT_WATCHDOG_MS);
        return;
      }
      runIdRef.current += 1; // abandon the stuck run: its late result must not flip the screen
      showOffline();
    };
    watchdogRef.current = setTimeout(check, BOOT_WATCHDOG_MS);
  }, [showOffline]);

  const run = useCallback(async () => {
    const runId = ++runIdRef.current;
    clearTimer(retryTimerRef);
    const store = useAppStore.getState();
    store.setBootStatus('restoring');
    // A new attempt (first run, "Poskusi znova") gets the full budget; automatic retries
    // of a locked Keychain stay inside the running one.
    if (watchdogRef.current === null) armWatchdog();
    // iOS launched the app in the background (step sync, a push): restore only once the
    // app is opened. The Keychain may be locked and the other session keys (sign-in
    // method, family zone, child sessions) would load as defaults for the whole run.
    let result: Awaited<ReturnType<typeof restoreSession>>;
    if (AppState.currentState === 'background') {
      result = { status: 'locked' };
    } else {
      try {
        result = await restoreSession();
      } catch (error) {
        // restoreSession never throws by contract; a bug must not leave the splash spinning.
        result = { status: 'offline', error };
      }
    }
    // M2-11: revoke + delete what the first profile-switch draft left (dev builds only).
    if (runId === 1) {
      void migrateLegacyProfiles().catch(() => undefined);
      // M2-11 QA n1: tokens that couldn't be revoked last time (offline).
      void retryPendingRevokes();
    }
    if (runId !== runIdRef.current) return;
    retryOnForegroundRef.current = result.status === 'locked';
    if (result.status === 'locked') {
      // Stay on 'restoring'. In the background nobody sees it: the foreground listener
      // retries. On screen (active, or `inactive` during launch / a system sheet) → retry
      // by itself with backoff, then the offline screen — never wait for an event that
      // may never come (boot-hang fix 2026-10-19).
      if (maybeOnScreen()) {
        const attempt = foregroundLockedRef.current;
        foregroundLockedRef.current += 1;
        if (foregroundLockedRef.current >= MAX_FOREGROUND_LOCKED) {
          showOffline();
          return;
        }
        retryTimerRef.current = setTimeout(() => {
          retryTimerRef.current = null;
          if (runId === runIdRef.current && retryOnForegroundRef.current) void run();
        }, LOCKED_RETRY_DELAYS_MS[attempt]);
      } else {
        // Background launch: no budget runs while nobody can see the splash; the
        // foreground listener starts a fresh run (and a fresh watchdog).
        clearTimer(watchdogRef);
      }
      return;
    }
    foregroundLockedRef.current = 0;
    clearTimer(watchdogRef);
    if (result.status === 'authenticated') {
      useAppStore.getState().signIn(result.session);
    } else if (result.status === 'offline') {
      useAppStore.getState().setBootStatus('offline');
    } else {
      useAppStore.getState().reset();
    }
  }, [armWatchdog, showOffline]);

  useEffect(() => {
    setUnauthorizedHandler(() => {
      void logout({ revoke: false });
    });
    void run();
    // M2-11 QA n1: queued token revokes are retried whenever the app comes back.
    const foreground = AppState.addEventListener('change', (state) => {
      if (state !== 'active') return;
      if (retryOnForegroundRef.current) {
        retryOnForegroundRef.current = false;
        // Back from the background (no watchdog was running): a fresh budget. An
        // inactive → active flip during the on-screen backoff keeps the running watchdog
        // and the lock count — it must never extend the wait (QA m3).
        if (watchdogRef.current === null) foregroundLockedRef.current = 0;
        clearTimer(retryTimerRef);
        void run();
      }
      void retryPendingRevokes();
    });
    return () => {
      runIdRef.current += 1; // invalidate an in-flight run on unmount
      clearTimer(retryTimerRef);
      clearTimer(watchdogRef);
      setUnauthorizedHandler(null);
      foreground.remove();
    };
  }, [run]);

  return {
    retry: () => {
      // "Poskusi znova": a fresh attempt with its own watchdog budget.
      foregroundLockedRef.current = 0;
      clearTimer(watchdogRef);
      void run();
    },
  };
}
