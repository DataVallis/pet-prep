/**
 * M5-05: the small persisted queue of app error reports (offline / server down / crash
 * right after the report). At most `MAX_QUEUE` (20) reports — the oldest is dropped — and
 * a dedupe map (same error within `DEDUPE_MS`, 10 min, is not queued again). Written
 * SYNCHRONOUSLY (expo-file-system `File.write`) so a fatal error is on disk before the app
 * dies. Lives in the cache folder (QA #375 m4: the OS may clear it; nothing here must
 * survive that). Every read / write is wrapped: a broken file or a missing native module
 * only turns persistence off, it never throws.
 *
 * Also holds the server's switch (QA #375 B1, `GET /api/user` → `app_error_reports`,
 * default OFF): while it is off nothing is queued and the queue is emptied.
 */

import { requireOptionalNativeModule } from 'expo';

import type { AppErrorReport } from '@/modules/errorReporting/errorReport';

export const MAX_QUEUE = 20;
export const DEDUPE_MS = 10 * 60 * 1000;
const FILE_NAME = 'petprep-app-error-queue.json';

/** The session role when the report was queued (QA #375 m1): the token is sent only if it still matches. */
export type QueuedRole = 'parent' | 'child' | null;

export interface QueuedReport {
  report: AppErrorReport;
  role: QueuedRole;
}

export interface QueueState {
  reports: QueuedReport[];
  /** dedupe key → last time (ms) this error was queued. */
  seen: Record<string, number>;
  /** The server's switch as last seen (default false = never send). */
  allowed: boolean;
}

/** Where the queue lives — the app's cache folder, or a test fake. */
export interface QueueFileStore {
  read(): string | null;
  write(content: string): void;
}

type FileSystemModule = typeof import('expo-file-system');

function expoQueueStore(): QueueFileStore | null {
  if (requireOptionalNativeModule('FileSystem') == null) return null;
  // Lazy: Jest and builds without the native module never load it.
  // eslint-disable-next-line @typescript-eslint/no-require-imports
  const fs = require('expo-file-system') as FileSystemModule;
  const file = new fs.File(fs.Paths.cache, FILE_NAME);
  return {
    read: () => (file.exists ? file.textSync() : null),
    write(content) {
      if (!file.exists) file.create({ intermediates: true });
      file.write(content);
    },
  };
}

let fileStore: QueueFileStore | null | undefined;
let memory: QueueState | null = null;

function currentFileStore(): QueueFileStore | null {
  if (fileStore === undefined) {
    try {
      fileStore = expoQueueStore();
    } catch {
      fileStore = null;
    }
  }
  return fileStore;
}

function empty(): QueueState {
  return { reports: [], seen: {}, allowed: false };
}

function isReport(value: unknown): value is AppErrorReport {
  if (typeof value !== 'object' || value === null) return false;
  const r = value as Record<string, unknown>;
  return typeof r.scope === 'string' && typeof r.name === 'string' && typeof r.message === 'string' && typeof r.occurred_at === 'string';
}

function isQueued(value: unknown): value is QueuedReport {
  if (typeof value !== 'object' || value === null) return false;
  const q = value as Record<string, unknown>;
  return isReport(q.report) && (q.role === 'parent' || q.role === 'child' || q.role === null);
}

function parse(raw: string | null): QueueState {
  if (!raw) return empty();
  try {
    const data = JSON.parse(raw) as { reports?: unknown; seen?: unknown; allowed?: unknown };
    const reports = Array.isArray(data.reports) ? data.reports.filter(isQueued).slice(-MAX_QUEUE) : [];
    const seen: Record<string, number> = {};
    if (typeof data.seen === 'object' && data.seen !== null) {
      for (const [key, at] of Object.entries(data.seen as Record<string, unknown>)) {
        if (typeof at === 'number' && Number.isFinite(at)) seen[key] = at;
      }
    }
    const allowed = data.allowed === true;
    return { reports: allowed ? reports : [], seen, allowed };
  } catch {
    return empty();
  }
}

/** The queue (read from disk once per JS start, then kept in memory). */
export function loadQueue(): QueueState {
  if (memory === null) {
    let raw: string | null = null;
    try {
      raw = currentFileStore()?.read() ?? null;
    } catch {
      raw = null;
    }
    memory = parse(raw);
  }
  return memory;
}

function save(state: QueueState): void {
  memory = state;
  try {
    currentFileStore()?.write(JSON.stringify(state));
  } catch {
    // Persistence is best effort — the in-memory queue still sends while the app runs.
  }
}

/** Is the server's switch on (as last seen)? Default false. */
export function reportingAllowed(): boolean {
  return loadQueue().allowed;
}

/** The server's switch (from `GET /api/user`). Off → the queue is emptied. Never throws. */
export function setReportingAllowed(allowed: boolean): void {
  const state = loadQueue();
  if (state.allowed === allowed && (allowed || state.reports.length === 0)) return;
  save({ reports: allowed ? state.reports : [], seen: state.seen, allowed });
}

/** Drop dedupe entries older than the window (keeps the file small). */
function freshSeen(seen: Record<string, number>, nowMs: number): Record<string, number> {
  const out: Record<string, number> = {};
  for (const [key, at] of Object.entries(seen)) {
    if (nowMs - at < DEDUPE_MS && at <= nowMs) out[key] = at;
  }
  return out;
}

/**
 * Queue a report unless reporting is off or the same error was queued within 10 min.
 * Returns whether it was queued. Over `MAX_QUEUE` the oldest report is dropped.
 */
export function enqueueReport(report: AppErrorReport, key: string, nowMs: number, role: QueuedRole = null): boolean {
  const state = loadQueue();
  if (!state.allowed) return false;
  const seen = freshSeen(state.seen, nowMs);
  if (seen[key] !== undefined) {
    if (Object.keys(seen).length !== Object.keys(state.seen).length) save({ ...state, seen });
    return false;
  }
  seen[key] = nowMs;
  save({ reports: [...state.reports, { report, role }].slice(-MAX_QUEUE), seen, allowed: state.allowed });
  return true;
}

/** The oldest queued report, or null. */
export function peekReport(): QueuedReport | null {
  return loadQueue().reports[0] ?? null;
}

/** Remove the given entry (after it was sent or refused for good). */
export function removeReport(entry: QueuedReport): void {
  const state = loadQueue();
  const index = state.reports.indexOf(entry);
  if (index < 0) return;
  save({ ...state, reports: state.reports.filter((_, i) => i !== index) });
}

export function queuedCount(): number {
  return loadQueue().reports.length;
}

/** Tests: use a fake file (or null = memory only), forget the in-memory queue, set the switch. */
export function setQueueFileStoreForTests(store: QueueFileStore | null, allowed = true): void {
  fileStore = store;
  memory = null;
  if (allowed) setReportingAllowed(true);
}
