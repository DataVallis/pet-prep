/**
 * M5-T05: the care guide opens by itself the first time a pet is shown on this device, then
 * only from the »?« button. Remembered per pet id on the device (SecureStore, a list of ids —
 * no personal data). A failing store never blocks the HUD: worst case the card shows again.
 */

import { useCallback, useEffect, useState } from 'react';
import * as SecureStore from 'expo-secure-store';

export const CARE_GUIDE_SEEN_KEY = 'petprep.care_guide_seen';
/** Keep the list small (one child rarely sees many pets on one device). */
const MAX_IDS = 20;

export interface SeenStore {
  getItemAsync(key: string): Promise<string | null>;
  setItemAsync(key: string, value: string): Promise<void>;
}

let store: SeenStore = SecureStore;

export function setCareGuideStoreForTests(next: SeenStore | null): void {
  store = next ?? SecureStore;
}

function parse(value: string | null): number[] {
  if (!value) return [];
  try {
    const list: unknown = JSON.parse(value);
    return Array.isArray(list) ? list.filter((id): id is number => typeof id === 'number' && Number.isInteger(id)) : [];
  } catch {
    return [];
  }
}

export async function hasSeenCareGuide(petId: number): Promise<boolean> {
  try {
    return parse(await store.getItemAsync(CARE_GUIDE_SEEN_KEY)).includes(petId);
  } catch {
    // Unreadable store: treat as seen so a broken keychain can't pop the card on every start.
    return true;
  }
}

export async function markCareGuideSeen(petId: number): Promise<void> {
  try {
    const ids = parse(await store.getItemAsync(CARE_GUIDE_SEEN_KEY)).filter((id) => id !== petId);
    ids.push(petId);
    await store.setItemAsync(CARE_GUIDE_SEEN_KEY, JSON.stringify(ids.slice(-MAX_IDS)));
  } catch {
    // best effort
  }
}

/**
 * Open state of the guide for one pet. `petId` null (no state yet) or `ready` false (the
 * guide data is missing / the HUD is locked) → nothing opens by itself. Opens automatically
 * once per pet; closing marks it seen.
 */
export function useCareGuide(petId: number | null, ready: boolean): { visible: boolean; open: () => void; close: () => void } {
  const [visible, setVisible] = useState(false);

  useEffect(() => {
    if (petId === null || !ready) return;
    let alive = true;
    void hasSeenCareGuide(petId).then((seen) => {
      if (alive && !seen) setVisible(true);
    });
    return () => {
      alive = false;
    };
  }, [petId, ready]);

  const open = useCallback(() => setVisible(true), []);
  const close = useCallback(() => {
    setVisible(false);
    if (petId !== null) void markCareGuideSeen(petId);
  }, [petId]);

  return { visible, open, close };
}
