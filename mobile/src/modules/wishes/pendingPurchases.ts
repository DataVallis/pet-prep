/**
 * QA #416 M2: wish purchases the store charged but the server hasn't assigned to a pet yet
 * (webhook late, app closed, offline). Kept on the device so the redeem is retried for the
 * SAME pet and wish when the parent opens »Želje« again — instead of the credit falling to
 * »Komu?«. The redeem is idempotent (`Idempotency-Key` = store transaction id), so a retry is
 * always safe.
 *
 * Stored in SecureStore like the other small device records (the project has no
 * AsyncStorage): ids only — pet, wish, product, store transaction, the parent's user id (so
 * another parent signed in on this phone never redeems someone else's entry). An unreadable
 * store = nothing pending; a failing write is ignored (worst case: »Komu?«).
 */

import * as SecureStore from 'expo-secure-store';

export const PENDING_WISH_PURCHASES_KEY = 'petprep.wish_pending_purchases';
/** Keep the list small; older / extra entries are dropped (the credit still shows under »Komu?«). */
export const MAX_PENDING = 10;
export const PENDING_MAX_AGE_MS = 30 * 24 * 60 * 60 * 1000;

export interface PendingWishPurchase {
  parentId: number;
  petId: number;
  wishId: number | null;
  productId: string;
  transactionId: string;
  species: 'dog' | 'cat';
  savedAt: number;
}

export interface PendingStore {
  getItemAsync(key: string): Promise<string | null>;
  setItemAsync(key: string, value: string): Promise<void>;
}

let store: PendingStore = SecureStore;

export function setPendingPurchaseStoreForTests(next: PendingStore | null): void {
  store = next ?? SecureStore;
}

/** In-memory store for tests. */
export function memoryPendingStore(): PendingStore & { data: Map<string, string> } {
  const data = new Map<string, string>();
  return {
    data,
    getItemAsync: (key) => Promise.resolve(data.get(key) ?? null),
    setItemAsync: (key, value) => {
      data.set(key, value);
      return Promise.resolve();
    },
  };
}

function isEntry(v: unknown): v is PendingWishPurchase {
  if (typeof v !== 'object' || v === null) return false;
  const e = v as Record<string, unknown>;
  return (
    typeof e.parentId === 'number' &&
    typeof e.petId === 'number' &&
    (e.wishId === null || typeof e.wishId === 'number') &&
    typeof e.productId === 'string' &&
    typeof e.transactionId === 'string' &&
    e.transactionId !== '' &&
    (e.species === 'dog' || e.species === 'cat') &&
    typeof e.savedAt === 'number'
  );
}

export function parsePending(raw: string | null, nowMs: number = Date.now()): PendingWishPurchase[] {
  if (!raw) return [];
  try {
    const list: unknown = JSON.parse(raw);
    return Array.isArray(list) ? list.filter(isEntry).filter((e) => nowMs - e.savedAt < PENDING_MAX_AGE_MS) : [];
  } catch {
    return [];
  }
}

async function readAll(): Promise<PendingWishPurchase[]> {
  try {
    return parsePending(await store.getItemAsync(PENDING_WISH_PURCHASES_KEY));
  } catch {
    return [];
  }
}

async function writeAll(list: PendingWishPurchase[]): Promise<void> {
  try {
    await store.setItemAsync(PENDING_WISH_PURCHASES_KEY, JSON.stringify(list.slice(-MAX_PENDING)));
  } catch {
    // Ignored: the credit still shows under »Komu?«.
  }
}

/** Remember a charged purchase before it is redeemed (one entry per store transaction). */
export async function savePendingPurchase(entry: Omit<PendingWishPurchase, 'savedAt'>, nowMs: number = Date.now()): Promise<void> {
  const list = (await readAll()).filter((e) => e.transactionId !== entry.transactionId);
  await writeAll([...list, { ...entry, savedAt: nowMs }]);
}

/** Forget a purchase once the server answered for good (assigned, or refused → »Komu?«). */
export async function removePendingPurchase(transactionId: string): Promise<void> {
  const list = await readAll();
  const next = list.filter((e) => e.transactionId !== transactionId);
  if (next.length !== list.length) await writeAll(next);
}

/** This parent's purchases still waiting for a redeem. */
export async function pendingPurchasesOf(parentId: number): Promise<PendingWishPurchase[]> {
  return (await readAll()).filter((e) => e.parentId === parentId);
}
