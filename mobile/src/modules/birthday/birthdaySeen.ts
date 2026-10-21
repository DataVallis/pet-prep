/**
 * M5-T13: the birthday card opens by itself once per pet on this device (on the birthday),
 * then only from the party hat. Remembered per pet id (SecureStore, a short list of ids — no
 * personal data). An unreadable store counts as "seen" so a broken keychain can't pop the
 * card on every start; the hat still opens it.
 */

import { useCallback, useEffect, useState } from 'react';
import * as SecureStore from 'expo-secure-store';

export const BIRTHDAY_SEEN_KEY = 'petprep.birthday_seen';
const MAX_IDS = 20;

export interface BirthdaySeenStore {
  getItemAsync(key: string): Promise<string | null>;
  setItemAsync(key: string, value: string): Promise<void>;
}

let store: BirthdaySeenStore = SecureStore;

export function setBirthdayStoreForTests(next: BirthdaySeenStore | null): void {
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

export async function hasSeenBirthday(petId: number): Promise<boolean> {
  try {
    return parse(await store.getItemAsync(BIRTHDAY_SEEN_KEY)).includes(petId);
  } catch {
    return true;
  }
}

export async function markBirthdaySeen(petId: number): Promise<void> {
  try {
    const ids = parse(await store.getItemAsync(BIRTHDAY_SEEN_KEY)).filter((id) => id !== petId);
    ids.push(petId);
    await store.setItemAsync(BIRTHDAY_SEEN_KEY, JSON.stringify(ids.slice(-MAX_IDS)));
  } catch {
    // best effort
  }
}

/**
 * Open state of the birthday card. `ready` = the hat shows (birthday today, not locked) and
 * nothing else covers the HUD. Opens by itself once per pet; closing marks it seen.
 */
export function useBirthdayCard(petId: number | null, ready: boolean): { visible: boolean; open: () => void; close: () => void } {
  const [visible, setVisible] = useState(false);

  useEffect(() => {
    if (petId === null || !ready) return;
    let alive = true;
    void hasSeenBirthday(petId).then((seen) => {
      if (alive && !seen) setVisible(true);
    });
    return () => {
      alive = false;
    };
  }, [petId, ready]);

  const open = useCallback(() => setVisible(true), []);
  const close = useCallback(() => {
    setVisible(false);
    if (petId !== null) void markBirthdaySeen(petId);
  }, [petId]);

  return { visible, open, close };
}
