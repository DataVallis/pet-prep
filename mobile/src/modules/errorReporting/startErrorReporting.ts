/**
 * M5-05: start app error reporting once per JS start (index.ts). Release builds only.
 * Installs the global handlers, sends what an earlier run left in the queue (a crash) a few
 * seconds after launch, and again whenever the app comes to the foreground. Nothing here is
 * awaited: launch never waits for error reporting (boot-hang rule 2026-10-19).
 */

import { AppState } from 'react-native';

import { installGlobalErrorHandlers } from '@/modules/errorReporting/globalHandlers';
import { errorReportingEnabled, scheduleFlush } from '@/modules/errorReporting/reporter';

export const LAUNCH_FLUSH_DELAY_MS = 5_000;

let started = false;

export function startErrorReporting(): void {
  if (started || !errorReportingEnabled()) return;
  started = true;
  try {
    installGlobalErrorHandlers();
    scheduleFlush(LAUNCH_FLUSH_DELAY_MS);
    AppState.addEventListener('change', (state) => {
      if (state === 'active') scheduleFlush();
    });
  } catch {
    // Error reporting must never break the launch.
  }
}

/** Tests only. */
export function resetStartErrorReportingForTests(): void {
  started = false;
}
