/**
 * M5-05: the small persisted queue of app error reports (offline / server down / crash
 * right after the report). At most `MAX_QUEUE` (20) reports — the oldest is dropped — and
 * a dedupe map (same error within `DEDUPE_MS`, 10 min, is not queued again). Written
 * SYNCHRONOUSLY (expo-file-system `File.write`) so a fatal error is on disk before the app
 * dies. Every read / write is wrapped: a broken file or a missing native module only turns
 * persistence off, it never throws.
 */

import { requireOptionalNativeModule } from 'expo';

import type { AppErrorReport } from '@/modules/errorReporting/errorReport';

export const MAX_QUEUE = 20;
export const DEDUPE_MS = 10 * 60 * 1000;
const FILE_NAME = 'petprep-app-error-queue.json';

export interface QueueState {
  reports: AppErrorReport[];
  /** dedupe key → last time (ms) this error was queued. */
  seen: Record<string, number>;
}

/** Where the queue lives — the app's document folder, or a test fake. */
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
  const file = new fs.File(fs.Paths.document, FILE_NAME);
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

function isReport(value: unknown): value is AppErrorReport {
  if (typeof value !== 'object' || value === null) return false;
  const r = value as Record<string, unknown>;
  return typeof r.scope === 'string' && typeof r.name === 'string' && typeof r.message === 'string' && typeof r.occurred_at === 'string';
}

function parse(raw: string | null): QueueState {
  if (!raw) return { reports: [], seen: {} };
  try {
    const data = JSON.parse(raw) as { reports?: unknown; seen?: unknown };
    const reports = Array.isArray(data.reports) ? data.reports.filter(isReport).slice(-MAX_QUEUE) : [];
    const seen: Record<string, number> = {};
    if (typeof data.seen === 'object' && data.seen !== null) {
      for (const [key, at] of Object.entries(data.seen as Record<string, unknown>)) {
        if (typeof at === 'number' && Number.isFinite(at)) seen[key] = at;
      }
    }
    return { reports, seen };
  } catch {
    return { reports: [], seen: {} };
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

/** Drop dedupe entries older than the window (keeps the file small). */
function freshSeen(seen: Record<string, number>, nowMs: number): Record<string, number> {
  const out: Record<string, number> = {};
  for (const [key, at] of Object.entries(seen)) {
    if (nowMs - at < DEDUPE_MS && at <= nowMs) out[key] = at;
  }
  return out;
}

/**
 * Queue a report unless the same error was queued within 10 min. Returns whether it was
 * queued. Over `MAX_QUEUE` the oldest report is dropped.
 */
export function enqueueReport(report: AppErrorReport, key: string, nowMs: number): boolean {
  const state = loadQueue();
  const seen = freshSeen(state.seen, nowMs);
  if (seen[key] !== undefined) {
    if (Object.keys(seen).length !== Object.keys(state.seen).length) save({ reports: state.reports, seen });
    return false;
  }
  seen[key] = nowMs;
  save({ reports: [...state.reports, report].slice(-MAX_QUEUE), seen });
  return true;
}

/** The oldest queued report, or null. */
export function peekReport(): AppErrorReport | null {
  return loadQueue().reports[0] ?? null;
}

/** Remove the given report (after it was sent or refused for good). */
export function removeReport(report: AppErrorReport): void {
  const state = loadQueue();
  const index = state.reports.indexOf(report);
  if (index < 0) return;
  save({ reports: state.reports.filter((_, i) => i !== index), seen: state.seen });
}

export function queuedCount(): number {
  return loadQueue().reports.length;
}

/** Tests: use a fake file (or null = memory only) and forget the in-memory queue. */
export function setQueueFileStoreForTests(store: QueueFileStore | null): void {
  fileStore = store;
  memory = null;
}
