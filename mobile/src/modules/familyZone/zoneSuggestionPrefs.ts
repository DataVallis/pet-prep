/**
 * »Telefon je v drugem časovnem pasu …« banner (David 2026-11-08): the phone zone the parent
 * dismissed it for, remembered on this device (SecureStore like the other small device flags —
 * the project has no AsyncStorage). One value only: the banner returns when the phone moves to
 * yet another zone. A zone name is not personal data. Unreadable store = nothing dismissed;
 * a failing write is ignored (the dismissal still holds for this app run).
 */

import { useEffect, useState } from 'react';
import * as SecureStore from 'expo-secure-store';
import { create } from 'zustand';

export const ZONE_SUGGESTION_KEY = 'petprep.zone_suggestion_dismissed';

export interface ZoneSuggestionStore {
  getItemAsync(key: string): Promise<string | null>;
  setItemAsync(key: string, value: string): Promise<void>;
}

let store: ZoneSuggestionStore = SecureStore;

export function setZoneSuggestionStoreForTests(next: ZoneSuggestionStore | null): void {
  store = next ?? SecureStore;
}

interface DismissalState {
  loaded: boolean;
  dismissedZone: string | null;
}

const useDismissal = create<DismissalState>(() => ({ loaded: false, dismissedZone: null }));

let loading: Promise<void> | null = null;

function load(): Promise<void> {
  if (useDismissal.getState().loaded) return Promise.resolve();
  loading ??= (async () => {
    let value: string | null = null;
    try {
      value = await store.getItemAsync(ZONE_SUGGESTION_KEY);
    } catch {
      value = null;
    }
    // A dismissal made while loading wins.
    if (!useDismissal.getState().loaded) useDismissal.setState({ loaded: true, dismissedZone: value || null });
  })();
  return loading;
}

/** Remember that the banner was closed while the phone was in `zone`. */
export function dismissZoneSuggestion(zone: string): void {
  useDismissal.setState({ loaded: true, dismissedZone: zone });
  try {
    Promise.resolve(store.setItemAsync(ZONE_SUGGESTION_KEY, zone)).catch(() => undefined);
  } catch {
    // Kept for this run only.
  }
}

/** `{loaded, dismissedZone}` — the banner waits for `loaded` so it never flashes. */
export function useZoneSuggestionDismissal(): DismissalState {
  const state = useDismissal();
  const [, setTick] = useState(0);
  useEffect(() => {
    let alive = true;
    void load().then(() => {
      if (alive) setTick((n) => n + 1);
    });
    return () => {
      alive = false;
    };
  }, []);
  return state;
}

/** Test hook (jest.afterEnv). */
export function resetZoneSuggestionForTests(): void {
  loading = null;
  useDismissal.setState({ loaded: false, dismissedZone: null });
}
