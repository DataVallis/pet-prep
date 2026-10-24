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
 *  - QA #375 B1: nothing is queued or sent while the server's switch is off
 *    (`GET /api/user` → `app_error_reports`, default off; errorQueue).
 *  - QA #375 m1: the session role is stored with each report when it is queued; the stored
 *    token is sent only while the session still has that role (else anonymously), so a
 *    report from a child session never goes out with a parent token or vice versa. A
 *    keychain error sends it anonymously. A 401 never signs out.
 *  - QA #375 m3: a 429 (Retry-After, else 60 s) or a 5xx / network failure pauses flushing
 *    with a backoff (doubling, ≤ 30 min); `silentStatus` keeps these requests out of the
 *    outage banner (m2).
 */

import { Platform } from 'react-native';
import { requireOptionalNativeModule } from 'expo';

import { ApiError, api, getAuthToken } from '@/api/client';
import { getBuildInfo } from '@/config/buildInfo';
import { withTimeoutOr } from '@/utils/withTimeout';
import { buildReport, dedupeKey, type AppErrorScope, type AppIdentity } from '@/modules/errorReporting/errorReport';
import { enqueueReport, peekReport, removeReport, reportingAllowed, type QueuedRole } from '@/modules/errorReporting/errorQueue';

export const SEND_TIMEOUT_MS = 10_000;
export const FLUSH_DELAY_MS = 2_000;
const TOKEN_TIMEOUT_MS = 2_000;
export const BACKOFF_MIN_MS = 60_000;
export const BACKOFF_MAX_MS = 30 * 60_000;

let route: string | null = null;
let sessionRole: QueuedRole = null;
let pausedUntil = 0;
let backoffMs = 0;
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

/** The role of the signed-in session (AppNavigator), null when signed out. */
export function setErrorSession(role: QueuedRole): void {
  sessionRole = role;
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
    if (!enabled || !reportingAllowed()) return false;
    const identity = appIdentity();
    if (identity === null) return false;
    const now = new Date();
    const report = buildReport({ scope, error, componentStack: options.componentStack, fatal: options.fatal, route, identity, now });
    const queued = enqueueReport(report, dedupeKey(report), now.getTime(), sessionRole);
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

function pause(error: unknown): void {
  const retryAfter = error instanceof ApiError && error.status === 429 ? error.retryAfterSeconds : null;
  backoffMs = Math.min(BACKOFF_MAX_MS, backoffMs === 0 ? BACKOFF_MIN_MS : backoffMs * 2);
  const wait = retryAfter !== null && retryAfter > 0 ? Math.min(BACKOFF_MAX_MS, retryAfter * 1000) : backoffMs;
  pausedUntil = Date.now() + wait;
}

async function drain(): Promise<void> {
  if (Date.now() < pausedUntil || !reportingAllowed()) return;
  // At most one full pass over the queue per flush.
  for (let sent = 0; sent < 20; sent++) {
    const entry = peekReport();
    if (entry === null) return;
    try {
      // m1: the token only while the session still has the role the report was queued under.
      const token = entry.role !== null && entry.role === sessionRole ? await sessionToken() : null;
      await api.reportAppError(entry.report, { token, timeoutMs: SEND_TIMEOUT_MS });
      removeReport(entry);
      backoffMs = 0;
    } catch (error) {
      if (refusedForGood(error)) {
        removeReport(entry);
        continue;
      }
      pause(error);
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
  sessionRole = null;
  pausedUntil = 0;
  backoffMs = 0;
  flushing = null;
  if (flushTimer !== null) clearTimeout(flushTimer);
  flushTimer = null;
}
