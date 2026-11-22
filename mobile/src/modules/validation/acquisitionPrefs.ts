/**
 * M5-08: the parents (by account id) for whom »Kje ste izvedeli za PetPrep?« is done on this
 * phone — the dashboard card was closed or answered, or the parent already saw the question at
 * sign-up here. SecureStore like the other small device flags (the project has no AsyncStorage);
 * only numeric ids, no personal data. An unreadable store reads as "nothing done" (the card may
 * ask once more — harmless, the server ignores a second answer); a failing write keeps the
 * dismissal for this app run.
 */

import { useEffect } from 'react';
import * as SecureStore from 'expo-secure-store';
import { create } from 'zustand';

export const ACQUISITION_PROMPT_KEY = 'petprep.acquisition_prompt_done';

export interface AcquisitionPrefsStore {
  getItemAsync(key: string): Promise<string | null>;
  setItemAsync(key: string, value: string): Promise<void>;
}

let store: AcquisitionPrefsStore = SecureStore;

export function setAcquisitionPrefsStoreForTests(next: AcquisitionPrefsStore | null): void {
  store = next ?? SecureStore;
}

interface PrefsState {
  loaded: boolean;
  doneIds: readonly number[];
}

const usePrefs = create<PrefsState>(() => ({ loaded: false, doneIds: [] }));

/** Stored JSON → the ids (anything unexpected → none). */
export function parseDoneIds(raw: string | null): number[] {
  if (!raw) return [];
  try {
    const parsed: unknown = JSON.parse(raw);
    return Array.isArray(parsed) ? parsed.filter((v): v is number => typeof v === 'number' && Number.isInteger(v)) : [];
  } catch {
    return [];
  }
}

let loading: Promise<void> | null = null;

function load(): Promise<void> {
  if (usePrefs.getState().loaded) return Promise.resolve();
  loading ??= (async () => {
    let ids: number[] = [];
    try {
      ids = parseDoneIds(await store.getItemAsync(ACQUISITION_PROMPT_KEY));
    } catch {
      ids = [];
    }
    // Ids marked while loading are kept.
    const current = usePrefs.getState();
    usePrefs.setState({ loaded: true, doneIds: [...new Set([...ids, ...current.doneIds])] });
  })();
  return loading;
}

/** Never ask this parent again on this phone (card closed / answered / seen at sign-up). */
export async function markAcquisitionPromptDone(userId: number): Promise<void> {
  try {
    await load();
    const ids = usePrefs.getState().doneIds;
    if (ids.includes(userId)) return;
    const next = [...ids, userId];
    usePrefs.setState({ doneIds: next });
    await store.setItemAsync(ACQUISITION_PROMPT_KEY, JSON.stringify(next));
  } catch {
    // Kept for this run only.
  }
}

/** `{loaded, dismissed}` for one parent — the card waits for `loaded` so it never flashes. */
export function useAcquisitionPromptDone(userId: number | null): { loaded: boolean; dismissed: boolean } {
  const loaded = usePrefs((s) => s.loaded);
  const dismissed = usePrefs((s) => userId !== null && s.doneIds.includes(userId));
  useEffect(() => {
    void load();
  }, []);
  return { loaded, dismissed };
}

/** Test hook (jest.afterEnv). */
export function resetAcquisitionPrefsForTests(): void {
  loading = null;
  usePrefs.setState({ loaded: false, doneIds: [] });
}
