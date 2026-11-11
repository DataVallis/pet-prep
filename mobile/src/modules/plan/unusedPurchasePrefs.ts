/**
 * M6-U01 (QA #978 f): »Imate neporabljen nakup« dismissed on this phone — remembered as the number
 * of unused purchases the parent dismissed it for (per parent id; SecureStore, ids + a count, no
 * personal data). A NEW unused purchase (a higher count) asks again; when purchases get used the
 * stored count follows them down (`lowerDismissal`). An unreadable store reads as "not dismissed".
 */

import * as SecureStore from 'expo-secure-store';

export const UNUSED_PURCHASE_DISMISSED_KEY = 'petprep.unused_purchase_dismissed';

export interface PrefsStore {
  getItemAsync(key: string): Promise<string | null>;
  setItemAsync(key: string, value: string): Promise<void>;
}

let store: PrefsStore = SecureStore;

export function setUnusedPurchaseStoreForTests(next: PrefsStore | null): void {
  store = next ?? SecureStore;
}

async function readAll(): Promise<Record<string, number>> {
  try {
    const raw = await store.getItemAsync(UNUSED_PURCHASE_DISMISSED_KEY);
    const parsed: unknown = raw ? JSON.parse(raw) : {};
    if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) return {};
    return Object.fromEntries(Object.entries(parsed as Record<string, unknown>).filter((e): e is [string, number] => typeof e[1] === 'number'));
  } catch {
    return {};
  }
}

export async function dismissedCountFor(parentId: number): Promise<number> {
  return (await readAll())[String(parentId)] ?? 0;
}

export async function setDismissedCount(parentId: number, count: number): Promise<void> {
  try {
    const all = await readAll();
    all[String(parentId)] = Math.max(0, Math.floor(count));
    await store.setItemAsync(UNUSED_PURCHASE_DISMISSED_KEY, JSON.stringify(all));
  } catch {
    // best effort
  }
}

/** Purchases got used: the stored dismissal follows the count down, so the next new one asks again. */
export function lowerDismissal(dismissed: number, creditsAvailable: number): number {
  return Math.min(dismissed, Math.max(0, creditsAvailable));
}
