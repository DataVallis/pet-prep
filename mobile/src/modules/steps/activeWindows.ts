/**
 * Step windows on a shared phone (M2-11, David 2026-10-14): a parent's phone may carry
 * several children's profiles; the phone's step sensors count the PHONE's steps, so a
 * child may only be credited the steps taken while THEIR profile was the active one —
 * including with the app in the background (a walk happens with the phone in a pocket).
 *
 * A window opens when a shared child profile becomes active (»Igraj kot«) and closes only
 * when that profile stops being active (a parent signs in again, another profile is
 * chosen, the child session ends). Stored in SecureStore as
 * `{ [childId]: [{ start, end }] }` (epoch ms, `end: null` = still active); windows older
 * than two days are pruned. A child's own phone never has windows (counts from midnight).
 */

import * as SecureStore from 'expo-secure-store';

export const STEP_WINDOWS_KEY = 'petprep_step_windows';
/** Fallback prune without a family calendar: two days. */
const KEEP_MS = 2 * 24 * 60 * 60 * 1000;

export interface StepWindow {
  start: number;
  end: number | null;
}

export type StepWindows = Record<string, StepWindow[]>;

type Store = Pick<typeof SecureStore, 'getItemAsync' | 'setItemAsync' | 'deleteItemAsync'>;

/**
 * QA n2 — fail closed: children whose open window could not be closed. Their windows are
 * ignored for the rest of this app run (and deleted when possible), so a stuck window can
 * never credit another profile's steps.
 */
const poisoned = new Set<string>();

/** Tests only. */
export function resetStepWindowsForTests(): void {
  poisoned.clear();
}

function isWindow(value: unknown): value is StepWindow {
  if (typeof value !== 'object' || value === null) return false;
  const { start, end } = value as Record<string, unknown>;
  return typeof start === 'number' && Number.isFinite(start) && (end === null || (typeof end === 'number' && Number.isFinite(end)));
}

/** Sort and merge overlapping / touching windows (an open window swallows later ones). */
export function mergeWindows(list: readonly StepWindow[]): StepWindow[] {
  const sorted = [...list].sort((a, b) => a.start - b.start);
  const merged: StepWindow[] = [];
  for (const w of sorted) {
    const last = merged[merged.length - 1];
    if (last && (last.end === null || w.start <= last.end)) {
      last.end = last.end === null || w.end === null ? null : Math.max(last.end, w.end);
    } else {
      merged.push({ start: w.start, end: w.end });
    }
  }
  return merged;
}

export async function loadStepWindows(store: Store = SecureStore): Promise<StepWindows> {
  try {
    const raw = await store.getItemAsync(STEP_WINDOWS_KEY);
    const value: unknown = raw ? JSON.parse(raw) : null;
    if (typeof value !== 'object' || value === null || Array.isArray(value)) return {};
    const windows: StepWindows = {};
    for (const [id, list] of Object.entries(value as Record<string, unknown>)) {
      if (/^\d+$/.test(id) && Array.isArray(list) && !poisoned.has(id)) windows[id] = mergeWindows(list.filter(isWindow));
    }
    return windows;
  } catch {
    return {};
  }
}

/** Normalise (merge, drop windows that ended before `pruneBefore`) and write. True on success. */
async function save(windows: StepWindows, pruneBefore: number, store: Store): Promise<boolean> {
  const clean: StepWindows = {};
  for (const [id, list] of Object.entries(windows)) {
    const kept = mergeWindows(list).filter((w) => w.end === null || w.end > pruneBefore);
    if (kept.length > 0) clean[id] = kept;
  }
  try {
    await store.setItemAsync(STEP_WINDOWS_KEY, JSON.stringify(clean));
    return true;
  } catch {
    return false;
  }
}

function closeAll(windows: StepWindows, now: number): StepWindows {
  const closed: StepWindows = {};
  for (const [id, list] of Object.entries(windows)) {
    closed[id] = list.map((w) => (w.end === null ? { start: w.start, end: Math.max(w.start, now) } : w));
  }
  return closed;
}

function openIds(windows: StepWindows): string[] {
  return Object.entries(windows)
    .filter(([, list]) => list.some((w) => w.end === null))
    .map(([id]) => id);
}

/**
 * Close every open window (another profile becomes active / the parent is back). A failed
 * write is retried once; if it still fails, those children's windows are ignored from now on
 * and deleted if possible — no future steps are credited to them (fail closed).
 */
export async function closeStepWindows(now: number = Date.now(), store: Store = SecureStore): Promise<void> {
  const windows = await loadStepWindows(store);
  const open = openIds(windows);
  if (open.length === 0) return;
  const closed = closeAll(windows, now);
  if (await save(closed, now - KEEP_MS, store)) return;
  if (await save(closed, now - KEEP_MS, store)) return;
  open.forEach((id) => poisoned.add(id));
  try {
    await store.deleteItemAsync(STEP_WINDOWS_KEY);
  } catch {
    // Still ignored in memory for this app run.
  }
}

/** A shared child profile becomes active: close any other window, open this child's. */
export async function openStepWindow(childId: number, now: number = Date.now(), store: Store = SecureStore): Promise<void> {
  await closeStepWindows(now, store);
  const windows = await loadStepWindows(store);
  const id = String(childId);
  // A poisoned child's old windows are not loaded: it starts afresh with this window only.
  windows[id] = [...(windows[id] ?? []), { start: now, end: null }];
  // Not saved → no window → nothing credited (fail closed); saved → this child counts again.
  if (await save(windows, now - KEEP_MS, store)) poisoned.delete(id);
}

/**
 * Keep only windows of the current and the previous family day (`previousDayStart` = start
 * of yesterday in the family zone). Called by the step sync, which knows the family day.
 */
export async function pruneStepWindows(previousDayStart: number, store: Store = SecureStore): Promise<void> {
  const windows = await loadStepWindows(store);
  const stale = Object.values(windows).some((list) => list.some((w) => w.end !== null && w.end <= previousDayStart));
  if (stale) await save(windows, previousDayStart, store);
}

/**
 * This child's windows clipped to [dayStart, at] (an open window ends at `at`), as
 * non-empty `[start, end]` pairs. Windows across midnight count only their part of today.
 */
export function windowsInRange(windows: readonly StepWindow[], dayStart: Date, at: Date): Array<[Date, Date]> {
  const from = dayStart.getTime();
  const to = at.getTime();
  const ranges: Array<[Date, Date]> = [];
  for (const w of mergeWindows(windows)) {
    const start = Math.max(w.start, from);
    const end = Math.min(w.end ?? to, to);
    if (end > start) ranges.push([new Date(start), new Date(end)]);
  }
  return ranges;
}
