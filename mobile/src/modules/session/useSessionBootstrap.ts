/**
 * Runs the session restore once on mount and exposes a retry for the offline
 * state. Also wires the API client's 401 hook to a local logout, so a token
 * revoked while the app is open sends the user back to login.
 *
 * A `locked` restore (Keychain unreadable: iOS launched the app in the background while
 * the phone was locked) keeps the boot pending and is retried on the next foreground —
 * it never signs anyone out (fix 2026-10-17).
 */

import { useCallback, useEffect, useRef } from 'react';
import { AppState } from 'react-native';

import { setUnauthorizedHandler } from '@/api/client';
import { useAppStore } from '@/store/appStore';
import { logout } from './logout';
import { migrateLegacyProfiles, restoreSession } from './restoreSession';
import { retryPendingRevokes } from './profiles';

/** Delay of the foreground retry after a `locked` restore. */
export const LOCKED_RETRY_MS = 1000;
/** Foreground `locked` results in a row before the boot shows the offline screen (retry button). */
export const MAX_FOREGROUND_LOCKED = 3;

export function useSessionBootstrap(): { retry: () => void } {
  // Only the newest run may apply its result (e.g. a quick double "Poskusi znova",
  // or a remount while an older restore is still in flight).
  const runIdRef = useRef(0);
  // The last run ended `locked`: retry once the app is in the foreground (phone unlocked).
  const retryOnForegroundRef = useRef(false);
  const foregroundLockedRef = useRef(0);

  const run = useCallback(async () => {
    const runId = ++runIdRef.current;
    const store = useAppStore.getState();
    store.setBootStatus('restoring');
    // iOS launched the app in the background (step sync, a push): restore only once the
    // app is opened. The Keychain may be locked and the other session keys (sign-in
    // method, family zone, child sessions) would load as defaults for the whole run.
    const result: Awaited<ReturnType<typeof restoreSession>> =
      AppState.currentState === 'background' ? { status: 'locked' } : await restoreSession();
    // M2-11: revoke + delete what the first profile-switch draft left (dev builds only).
    if (runId === 1) {
      void migrateLegacyProfiles().catch(() => undefined);
      // M2-11 QA n1: tokens that couldn't be revoked last time (offline).
      void retryPendingRevokes();
    }
    if (runId !== runIdRef.current) return;
    retryOnForegroundRef.current = result.status === 'locked';
    if (result.status === 'locked') {
      // Stay on 'restoring' (nobody sees it in the background). Already in the foreground
      // (rare: Keychain busy) → one retry shortly; otherwise the foreground listener retries.
      if (AppState.currentState === 'active') {
        foregroundLockedRef.current += 1;
        if (foregroundLockedRef.current >= MAX_FOREGROUND_LOCKED) {
          // The Keychain stays unreadable with the phone in use: the offline screen
          // ("Poskusi znova") rather than a splash forever — never the login screen.
          retryOnForegroundRef.current = false;
          foregroundLockedRef.current = 0;
          useAppStore.getState().setBootStatus('offline');
          return;
        }
        setTimeout(() => {
          if (runId === runIdRef.current && retryOnForegroundRef.current) void run();
        }, LOCKED_RETRY_MS);
      }
      return;
    }
    foregroundLockedRef.current = 0;
    if (result.status === 'authenticated') {
      useAppStore.getState().signIn(result.session);
    } else if (result.status === 'offline') {
      useAppStore.getState().setBootStatus('offline');
    } else {
      useAppStore.getState().reset();
    }
  }, []);

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
        void run();
      }
      void retryPendingRevokes();
    });
    return () => {
      runIdRef.current += 1; // invalidate an in-flight run on unmount
      setUnauthorizedHandler(null);
      foreground.remove();
    };
  }, [run]);

  return {
    retry: () => {
      void run();
    },
  };
}
