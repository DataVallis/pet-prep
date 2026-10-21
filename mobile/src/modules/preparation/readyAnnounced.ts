/**
 * M5-T06: »[ime] je pripravljen!« is said once per pet on this device. Remembered per pet id
 * (SecureStore, a short list of ids — no personal data). A failing store never repeats the
 * message on every start: an unreadable store counts as "already said".
 */

import { useEffect, useRef } from 'react';
import * as SecureStore from 'expo-secure-store';

import { NO_PREPARATION, shouldAnnounceReady, type PetPreparation } from '@/modules/preparation/preparation';

export const READY_ANNOUNCED_KEY = 'petprep.pet_ready_announced';
const MAX_IDS = 20;

export interface AnnouncedStore {
  getItemAsync(key: string): Promise<string | null>;
  setItemAsync(key: string, value: string): Promise<void>;
}

let store: AnnouncedStore = SecureStore;

export function setReadyAnnouncedStoreForTests(next: AnnouncedStore | null): void {
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

export async function wasReadyAnnounced(petId: number): Promise<boolean> {
  try {
    return parse(await store.getItemAsync(READY_ANNOUNCED_KEY)).includes(petId);
  } catch {
    return true;
  }
}

export async function markReadyAnnounced(petId: number): Promise<void> {
  try {
    const ids = parse(await store.getItemAsync(READY_ANNOUNCED_KEY)).filter((id) => id !== petId);
    ids.push(petId);
    await store.setItemAsync(READY_ANNOUNCED_KEY, JSON.stringify(ids.slice(-MAX_IDS)));
  } catch {
    // best effort
  }
}

/**
 * Calls `announce()` once per pet when the server says the shown preparation ended
 * (`ready_at`, recently — `nowMs` is the server-corrected clock), the HUD is not locked and
 * this device has not said it yet. Marked before the message shows, so a remount never
 * repeats it.
 */
export function useReadyAnnouncement(
  petId: number | null,
  preparation: PetPreparation,
  locked: boolean,
  nowMs: () => number,
  announce: () => void,
): void {
  const announceRef = useRef(announce);
  announceRef.current = announce;
  const nowRef = useRef(nowMs);
  nowRef.current = nowMs;
  const readyAt = preparation.readyAt;

  useEffect(() => {
    if (petId === null || readyAt === null || locked) return;
    let alive = true;
    void wasReadyAnnounced(petId).then(async (said) => {
      if (!alive || !shouldAnnounceReady({ ...NO_PREPARATION, readyAt }, said, locked, nowRef.current())) return;
      await markReadyAnnounced(petId);
      if (alive) announceRef.current();
    });
    return () => {
      alive = false;
    };
  }, [petId, readyAt, locked]);
}
