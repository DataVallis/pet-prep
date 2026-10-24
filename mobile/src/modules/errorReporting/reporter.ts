/**
 * M5-05 app error reporting to our own server (`POST /api/app-errors` → /admin System
 * health; David 2026-10-19: no Sentry / third-party SDK). JS only — OTA-able.
 *
 *  - `reportAppError(scope, error, …)`: build the report (errorReport.ts), drop it when the
 *    same error was queued within 10 min on this device, queue it (persisted, max 20, oldest
 *    dropped) and send soon. NEVER throws and never awaits anything on the caller's path.
 *  - `flushAppErrors()`: single-flight, oldest first, one request at a time, each bounded
 *    (`SEND_TIMEOUT_MS`). Sent (2xx) or refused for good (4xx other than 408 / 429) → removed;
 *    offline / 429 / 5xx / timeout → kept for the next flush (app start, foreground, next
 *    report).
 *  - The stored session token is sent when there is one (the server reads only the role);
 *    a keychain error just sends the report anonymously. A 401 never signs out.
 */

import { Platform } from 'react-native';
import { requireOptionalNativeModule } from 'expo';

import { ApiError, api, getAuthToken } from '@/api/client';
import { getBuildInfo } from '@/config/buildInfo';
import { withTimeoutOr } from '@/utils/withTimeout';
import { buildReport, dedupeKey, type AppErrorScope, type AppIdentity } from '@/modules/errorReporting/errorReport';
import { enqueueReport, peekReport, removeReport } from '@/modules/errorReporting/errorQueue';

export const SEND_TIMEOUT_MS = 10_000;
export const FLUSH_DELAY_MS = 2_000;
const TOKEN_TIMEOUT_MS = 2_000;

let route: string | null = null;
let flushing: Promise<void> | null = null;
let flushTimer: ReturnType<typeof setTimeout> | null = null;
let identityOverride: AppIdentity | null = null;
/** Release builds only: a developer's Metro session (`__DEV__`) must not fill production System health. */
let enabled = !__DEV__;

export function errorReportingEnabled(): boolean {
  return enabled;
}

/** The screen currently shown (a NAME like `child-hud`, set by AppNavigator). */
export function setErrorRoute(name: string | null): void {
  route = name;
}

export function currentErrorRoute(): string | null {
  return route;
}

interface UpdatesConstants {
  runtimeVersion?: string | null;
  updateId?: string | null;
}

/** expo-updates identity, read synchronously (constants only — never a check / fetch). */
function updatesIdentity(): UpdatesConstants {
  try {
    if (requireOptionalNativeModule('ExpoUpdates') == null) return {};
    // Lazy: never loaded in Jest / builds without the module. Constants only (boot rule:
    // nothing awaits expo-updates — updatesNeverBlockBoot.test.ts).
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    const updates = require('expo-updates') as UpdatesConstants;
    return { runtimeVersion: updates.runtimeVersion ?? null, updateId: updates.updateId ?? null };
  } catch {
    return {};
  }
}

export function appIdentity(): AppIdentity | null {
  if (identityOverride) return identityOverride;
  if (Platform.OS !== 'ios' && Platform.OS !== 'android') return null;
  const build = getBuildInfo();
  const updates = updatesIdentity();
  return {
    appVersion: build.version,
    build: build.sha === 'dev' ? null : build.sha,
    runtimeVersion: updates.runtimeVersion ?? null,
    updateId: updates.updateId ?? null,
    platform: Platform.OS,
    osVersion: Platform.Version === undefined ? null : String(Platform.Version),
  };
}

export interface ReportOptions {
  componentStack?: string | null;
  fatal?: boolean;
  /** Send right away instead of after `FLUSH_DELAY_MS` (not for fatal errors — the app is going down). */
  immediate?: boolean;
}

/** Report one error. Returns whether it was queued (false: duplicate, unsupported platform or a failure). */
export function reportAppError(scope: AppErrorScope, error: unknown, options: ReportOptions = {}): boolean {
  try {
    if (!enabled) return false;
    const identity = appIdentity();
    if (identity === null) return false;
    const now = new Date();
    const report = buildReport({ scope, error, componentStack: options.componentStack, fatal: options.fatal, route, identity, now });
    const queued = enqueueReport(report, dedupeKey(report), now.getTime());
    if (queued) scheduleFlush(options.immediate ? 0 : FLUSH_DELAY_MS);
    return queued;
  } catch {
    // Reporting must never throw — not from an error boundary, not from the global handler.
    return false;
  }
}

/** Send the queue soon (debounced). Never throws. */
export function scheduleFlush(delayMs: number = FLUSH_DELAY_MS): void {
  try {
    if (flushTimer !== null) return;
    flushTimer = setTimeout(() => {
      flushTimer = null;
      void flushAppErrors();
    }, delayMs);
  } catch {
    flushTimer = null;
  }
}

async function sessionToken(): Promise<string | null> {
  try {
    return await withTimeoutOr(getAuthToken(), TOKEN_TIMEOUT_MS, null);
  } catch {
    return null;
  }
}

/** 4xx that will never succeed with the same body → drop it (422, 413, …). */
function refusedForGood(error: unknown): boolean {
  return error instanceof ApiError && error.status >= 400 && error.status < 500 && error.status !== 408 && error.status !== 429;
}

async function drain(): Promise<void> {
  // At most one full pass over the queue per flush.
  for (let sent = 0; sent < 20; sent++) {
    const report = peekReport();
    if (report === null) return;
    try {
      await api.reportAppError(report, { token: await sessionToken(), timeoutMs: SEND_TIMEOUT_MS });
      removeReport(report);
    } catch (error) {
      if (refusedForGood(error)) {
        removeReport(report);
        continue;
      }
      return; // Offline, throttled, server down: keep it for the next flush.
    }
  }
}

/** Send every queued report (single-flight). Never rejects. */
export function flushAppErrors(): Promise<void> {
  if (flushing === null) {
    flushing = drain()
      .catch(() => undefined)
      .finally(() => {
        flushing = null;
      });
  }
  return flushing;
}

/** Tests: fixed identity (null = real), reporting on / off and a clean module state. */
export function resetErrorReporterForTests(identity: AppIdentity | null = null, on = true): void {
  identityOverride = identity;
  enabled = on;
  route = null;
  flushing = null;
  if (flushTimer !== null) clearTimeout(flushTimer);
  flushTimer = null;
}
