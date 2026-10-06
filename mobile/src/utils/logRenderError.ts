/**
 * The app's single log path for React render errors caught by error boundaries.
 * Local console only — no third-party SDK, no network. It logs the error name,
 * message and the component stack; never props, state or the store (no user / child
 * data). In a release build this ends up in the device log (Xcode / logcat).
 */

import type { ErrorInfo } from 'react';

export type RenderErrorScope = 'app' | 'child-hud' | 'locked-overlay';

export function logRenderError(scope: RenderErrorScope, error: Error, info?: Pick<ErrorInfo, 'componentStack'>): void {
  try {
    console.error(`[render-error:${scope}] ${error.name}: ${error.message}`, info?.componentStack ?? '');
  } catch {
    // Logging must never throw from an error boundary.
  }
}
