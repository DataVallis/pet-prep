/**
 * David 2026-10-26: the child HUD's top stack can be collapsed (× / swipe up) — the choice is
 * remembered on this device (UI-only state, never the server). Also remembered: the urgent items
 * already seen (`topStack.ts#reconcileAlerts` tokens — card keys, event ids and priorities, no
 * personal data), so a restart does not auto-expand the stack again for the same mess / clock.
 *
 * Stored in SecureStore like the other small device flags (`birthdaySeen.ts`,
 * `readyAnnounced.ts`; the project has no AsyncStorage). An unreadable store = expanded (the
 * pre-2026-10-26 layout), and a failing write is ignored — the toggle still works for the session.
 */

import * as SecureStore from 'expo-secure-store';
import { create } from 'zustand';

export const TOP_STACK_PREFS_KEY = 'petprep.hud_top_stack';
/** Cap of remembered alert tokens (a handful are on screen at once). */
const MAX_SEEN = 20;

export interface TopStackPrefsStore {
  getItemAsync(key: string): Promise<string | null>;
  setItemAsync(key: string, value: string): Promise<void>;
}

let store: TopStackPrefsStore = SecureStore;

export function setTopStackPrefsStoreForTests(next: TopStackPrefsStore | null): void {
  store = next ?? SecureStore;
}

export interface TopStackPrefs {
  collapsed: boolean;
  /** Urgent alert tokens already seen (see `reconcileAlerts`). */
  seen: string[];
}

export const DEFAULT_TOP_STACK_PREFS: TopStackPrefs = { collapsed: false, seen: [] };

/** Tolerant parse of the stored JSON (anything unexpected → defaults). */
export function parseTopStackPrefs(raw: string | null): TopStackPrefs {
  if (!raw) return DEFAULT_TOP_STACK_PREFS;
  try {
    const value: unknown = JSON.parse(raw);
    if (typeof value !== 'object' || value === null) return DEFAULT_TOP_STACK_PREFS;
    const record = value as Record<string, unknown>;
    const seen = Array.isArray(record.seen) ? record.seen.filter((s): s is string => typeof s === 'string') : [];
    return { collapsed: record.collapsed === true, seen: seen.slice(-MAX_SEEN) };
  } catch {
    return DEFAULT_TOP_STACK_PREFS;
  }
}

function persist(prefs: TopStackPrefs): void {
  const value = JSON.stringify({ collapsed: prefs.collapsed, seen: prefs.seen.slice(-MAX_SEEN) });
  Promise.resolve()
    .then(() => store.setItemAsync(TOP_STACK_PREFS_KEY, value))
    .catch(() => {
      // best effort
    });
}

function sameList(a: readonly string[], b: readonly string[]): boolean {
  return a.length === b.length && a.every((v, i) => v === b[i]);
}

interface TopStackPrefsState extends TopStackPrefs {
  /** The stored value was read (or could not be) — before that the alert bookkeeping waits. */
  hydrated: boolean;
  /** The user (or an auto-expand) changed it before the store answered: the store must not win. */
  touched: boolean;
  setCollapsed: (collapsed: boolean) => void;
  setSeen: (seen: readonly string[]) => void;
  hydrate: () => Promise<void>;
}

let hydrating: Promise<void> | null = null;
/** Bumped by the test reset: a read that started before it never lands after it. */
let generation = 0;

export const useTopStackPrefs = create<TopStackPrefsState>()((set, get) => ({
  ...DEFAULT_TOP_STACK_PREFS,
  hydrated: false,
  touched: false,
  setCollapsed: (collapsed) => {
    if (get().collapsed === collapsed && get().touched) return;
    set({ collapsed, touched: true });
    persist(get());
  },
  setSeen: (seen) => {
    if (sameList(get().seen, seen)) return;
    set({ seen: [...seen] });
    persist(get());
  },
  hydrate: () => {
    if (get().hydrated) return Promise.resolve();
    const run = generation;
    hydrating ??= Promise.resolve()
      .then(() => store.getItemAsync(TOP_STACK_PREFS_KEY))
      .then(parseTopStackPrefs, () => DEFAULT_TOP_STACK_PREFS)
      .then((prefs) => {
        if (run !== generation) return;
        const { touched } = get();
        set(touched ? { hydrated: true, seen: prefs.seen } : { ...prefs, hydrated: true });
      })
      .finally(() => {
        hydrating = null;
      });
    return hydrating;
  },
}));

/** Tests: back to a fresh, unread store. */
export function resetTopStackPrefsForTests(): void {
  generation += 1;
  hydrating = null;
  useTopStackPrefs.setState({ ...DEFAULT_TOP_STACK_PREFS, hydrated: false, touched: false });
}
