/**
 * The teaching tip on the FIRST clean of each kind (GAMES_SPEC §6.2, David 2026-10-30): shown
 * once per device, at the top, for at least `TIP_MIN_MS`.
 *
 * - poop »Na sprehodu vedno pobereš z vrečko.« / "On walks you always pick it up with a bag."
 * - puddle »Ne kregaj ga — pelji ga pogosteje ven.« / "Don't scold — take it out more often."
 *   Source (docs/research/dog-data/sources.md S47, VCA Animal Hospitals, House Training for
 *   Puppies and Dogs): "Do not punish your puppy." … "calmly and quietly clean the area and
 *   resolve to better supervise your puppy" + frequent elimination breaks. `PUDDLE_TIP_ENABLED`
 *   keeps a switch should David want it off.
 * - Dogs only: a cat's mess next to the tray is bagged too, but the walk tip is about dogs.
 *
 * Remembered in SecureStore (a short list of tip kinds — no personal data). Every read / write is
 * guarded: an unreadable store counts as "seen", so a broken keychain never shows it every time.
 */

import { useEffect, useState } from 'react';
import * as SecureStore from 'expo-secure-store';

import type { MessStageKind } from '@/modules/cleaning/messPlan';

export type CleaningTipKind = 'poop' | 'puddle';
export const CLEANING_TIP_KINDS: readonly CleaningTipKind[] = ['poop', 'puddle'];

/** The puddle tip has its source (VCA S47) — on. */
export const PUDDLE_TIP_ENABLED = true;
/** At least this long on screen (spec: ≥ 2.5 s). */
export const TIP_MIN_MS = 2_500;
/** How long it actually stays (reading time for a 6–10-year-old). */
export const TIP_SHOWN_MS = 4_500;

export const CLEANING_TIPS_KEY = 'petprep.cleaning_tips_seen';

export interface TipStore {
  getItemAsync(key: string): Promise<string | null>;
  setItemAsync(key: string, value: string): Promise<void>;
}

let store: TipStore = SecureStore;

export function setTipStoreForTests(next: TipStore | null): void {
  store = next ?? SecureStore;
}

function parse(value: string | null): CleaningTipKind[] {
  if (!value) return [];
  try {
    const list: unknown = JSON.parse(value);
    return Array.isArray(list) ? list.filter((k): k is CleaningTipKind => CLEANING_TIP_KINDS.includes(k as CleaningTipKind)) : [];
  } catch {
    return [];
  }
}

/** Which tip a stage gets (null = none): dogs only, the puddle tip only while enabled. */
export function tipKindFor(stage: MessStageKind, species: string | null | undefined, puddleEnabled = PUDDLE_TIP_ENABLED): CleaningTipKind | null {
  if (species === 'cat') return null;
  if (stage === 'puddle') return puddleEnabled ? 'puddle' : null;
  return 'poop';
}

export async function hasSeenTip(kind: CleaningTipKind): Promise<boolean> {
  try {
    return parse(await store.getItemAsync(CLEANING_TIPS_KEY)).includes(kind);
  } catch {
    return true;
  }
}

export async function markTipSeen(kind: CleaningTipKind): Promise<void> {
  try {
    const kinds = parse(await store.getItemAsync(CLEANING_TIPS_KEY)).filter((k) => k !== kind);
    kinds.push(kind);
    await store.setItemAsync(CLEANING_TIPS_KEY, JSON.stringify(kinds));
  } catch {
    // best effort
  }
}

/**
 * The tip to show now for `kind` (null = none): checks the store once per kind, shows it for
 * `TIP_SHOWN_MS` and marks it seen as soon as it shows (closing the game early doesn't repeat it).
 */
export function useFirstTip(kind: CleaningTipKind | null): CleaningTipKind | null {
  const [shown, setShown] = useState<CleaningTipKind | null>(null);
  useEffect(() => {
    if (kind === null) return;
    let alive = true;
    let timer: ReturnType<typeof setTimeout> | null = null;
    void hasSeenTip(kind).then((seen) => {
      if (!alive || seen) return;
      setShown(kind);
      void markTipSeen(kind);
      timer = setTimeout(() => {
        if (alive) setShown((s) => (s === kind ? null : s));
      }, Math.max(TIP_MIN_MS, TIP_SHOWN_MS));
    });
    return () => {
      alive = false;
      if (timer !== null) clearTimeout(timer);
    };
  }, [kind]);
  return shown;
}
