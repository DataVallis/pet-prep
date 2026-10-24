/**
 * M5-05: report JS errors that no error boundary catches.
 *
 *  - `ErrorUtils.setGlobalHandler`: an uncaught JS error (scope `global`). The report is
 *    queued — written to disk synchronously — and then RN's own handler runs as before
 *    (for a fatal error that is the crash / red screen; the report is sent on the next start).
 *  - Hermes promise rejection tracker: a rejected promise nobody handled (scope
 *    `unhandled-rejection`, not fatal). RN installs its own tracker only in __DEV__ (LogBox
 *    warnings); release builds have none, so ours replaces nothing there.
 *
 * Installed once from index.ts, only in release builds (`!__DEV__`) — a developer's Metro
 * session must not fill production System health. Every path is wrapped: installing or
 * reporting can never throw and never changes what the previous handler does.
 */

import { reportAppError } from '@/modules/errorReporting/reporter';

type GlobalHandler = (error: unknown, isFatal?: boolean) => void;

interface ErrorUtilsLike {
  getGlobalHandler(): GlobalHandler | undefined;
  setGlobalHandler(handler: GlobalHandler): void;
}

interface RejectionTrackerOptions {
  allRejections: boolean;
  onUnhandled: (id: number, rejection?: unknown) => void;
  onHandled: (id: number) => void;
}

interface HermesLike {
  enablePromiseRejectionTracker?: (options: RejectionTrackerOptions) => void;
}

interface ErrorGlobals {
  ErrorUtils?: ErrorUtilsLike;
  HermesInternal?: HermesLike | null;
}

let installed = false;

export function installGlobalErrorHandlers(scope: ErrorGlobals = globalThis as unknown as ErrorGlobals): boolean {
  if (installed) return false;
  installed = true;
  try {
    const errorUtils = scope.ErrorUtils;
    if (errorUtils && typeof errorUtils.setGlobalHandler === 'function') {
      const previous = errorUtils.getGlobalHandler?.();
      errorUtils.setGlobalHandler((error: unknown, isFatal?: boolean) => {
        try {
          reportAppError('global', error, { fatal: isFatal === true });
        } catch {
          // never block the previous handler
        }
        previous?.(error, isFatal);
      });
    }
  } catch {
    // keep RN's handler as it was
  }
  try {
    scope.HermesInternal?.enablePromiseRejectionTracker?.({
      allRejections: true,
      onUnhandled: (_id, rejection) => {
        try {
          reportAppError('unhandled-rejection', rejection);
        } catch {
          // ignore
        }
      },
      onHandled: () => undefined,
    });
  } catch {
    // no tracker on this engine
  }
  return true;
}

/** Tests: allow a fresh install. */
export function resetGlobalHandlersForTests(): void {
  installed = false;
}
