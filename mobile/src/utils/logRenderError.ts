/**
 * The app's single log path for React render errors caught by error boundaries. It logs
 * the error name, message and the component stack to the console (device log) and — M5-05
 * — queues a report for our own server (`POST /api/app-errors` → /admin System health;
 * release builds only, see `modules/errorReporting`). Never props, state or the store (no
 * user / child data). Never throws.
 */

import type { ErrorInfo } from 'react';

import { reportAppError } from '@/modules/errorReporting/reporter';

export type RenderErrorScope = 'app' | 'child-hud' | 'locked-overlay';

export function logRenderError(scope: RenderErrorScope, error: Error, info?: Pick<ErrorInfo, 'componentStack'>): void {
  try {
    console.error(`[render-error:${scope}] ${error.name}: ${error.message}`, info?.componentStack ?? '');
  } catch {
    // Logging must never throw from an error boundary.
  }
  try {
    // The whole app is gone behind the root boundary: send at once, not after the debounce.
    reportAppError(scope, error, { componentStack: info?.componentStack ?? null, immediate: scope === 'app' });
  } catch {
    // Reporting must never throw from an error boundary either.
  }
}
