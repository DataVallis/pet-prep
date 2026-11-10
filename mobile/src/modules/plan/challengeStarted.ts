/**
 * M6-U01 (FREE_PET_UPGRADE_SPEC §4 »Otrok«, David 2026-11-10): when a parent upgrades the
 * child's free pet to the 12-week challenge, the child sees once »Izziv se je začel! Teden 1
 * od 12« — no price, no purchase wording. Said once per (pet, upgrade) on this device: the key
 * is the pet id + the server's `plan.challenge_started_at` (a new purchase after a refund is a
 * new challenge, K7). SecureStore, a short list, no personal data; an unreadable store counts
 * as "already said" (never a message on every start). Only for an upgrade of the last
 * ANNOUNCE_WITHIN_DAYS days (a device that opens months later says nothing).
 */

import { useEffect, useRef } from 'react';
import * as SecureStore from 'expo-secure-store';

import { t } from '@/i18n';

export const CHALLENGE_STARTED_KEY = 'petprep.challenge_started_announced';
const MAX_ENTRIES = 20;
export const ANNOUNCE_WITHIN_DAYS = 14;
const CHALLENGE_WEEKS = 12;

export interface AnnouncedStore {
  getItemAsync(key: string): Promise<string | null>;
  setItemAsync(key: string, value: string): Promise<void>;
}

let store: AnnouncedStore = SecureStore;

export function setChallengeStartedStoreForTests(next: AnnouncedStore | null): void {
  store = next ?? SecureStore;
}

function parse(value: string | null): string[] {
  if (!value) return [];
  try {
    const list: unknown = JSON.parse(value);
    return Array.isArray(list) ? list.filter((k): k is string => typeof k === 'string') : [];
  } catch {
    return [];
  }
}

const entry = (petId: number, startedAt: string) => `${petId}:${startedAt}`;

export async function wasChallengeStartAnnounced(petId: number, startedAt: string): Promise<boolean> {
  try {
    return parse(await store.getItemAsync(CHALLENGE_STARTED_KEY)).includes(entry(petId, startedAt));
  } catch {
    return true;
  }
}

export async function markChallengeStartAnnounced(petId: number, startedAt: string): Promise<void> {
  try {
    const key = entry(petId, startedAt);
    const list = parse(await store.getItemAsync(CHALLENGE_STARTED_KEY)).filter((k) => k !== key);
    list.push(key);
    await store.setItemAsync(CHALLENGE_STARTED_KEY, JSON.stringify(list.slice(-MAX_ENTRIES)));
  } catch {
    // best effort
  }
}

/** Pure: should the message show now (recent upgrade, not said yet, HUD not locked)? */
export function shouldAnnounceChallengeStart(startedAt: string | null, said: boolean, locked: boolean, nowMs: number): boolean {
  if (startedAt === null || said || locked) return false;
  const at = Date.parse(startedAt);
  if (Number.isNaN(at)) return false;
  return nowMs - at <= ANNOUNCE_WITHIN_DAYS * 86_400_000;
}

/** »Izziv se je začel! Teden N od 12« — N from the server's challenge clock (`virtual_age_months` = whole weeks). */
export function challengeStartedMessage(weeksElapsed: number): string {
  const week = Math.min(CHALLENGE_WEEKS, Math.max(0, Math.floor(weeksElapsed)) + 1);
  return t('child:hud.challengeStarted', { week, total: CHALLENGE_WEEKS });
}

/**
 * Calls `announce()` once per (pet, upgrade) when the server's plan carries a recent
 * `challenge_started_at` and the HUD is not locked. Marked before the message shows, so a
 * remount never repeats it.
 */
export function useChallengeStartAnnouncement(petId: number | null, startedAt: string | null, locked: boolean, nowMs: () => number, announce: () => void): void {
  const announceRef = useRef(announce);
  announceRef.current = announce;
  const nowRef = useRef(nowMs);
  nowRef.current = nowMs;

  useEffect(() => {
    if (petId === null || startedAt === null || locked) return;
    let alive = true;
    void wasChallengeStartAnnounced(petId, startedAt).then(async (said) => {
      if (!alive || !shouldAnnounceChallengeStart(startedAt, said, locked, nowRef.current())) return;
      await markChallengeStartAnnounced(petId, startedAt);
      if (alive) announceRef.current();
    });
    return () => {
      alive = false;
    };
  }, [petId, startedAt, locked]);
}
