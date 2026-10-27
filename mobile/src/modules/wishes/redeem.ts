/**
 * M6-W05 purchase → redeem flow (WISHES_API §4). After a successful store purchase the app
 * assigns the credit to the pet: `POST /api/parent/pets/{pet}/items/redeem` with the product,
 * the wish (when bought from one) and `Idempotency-Key` = the store transaction id.
 *
 * Retries ({@link REDEEM_RETRIES}× with backoff {@link REDEEM_BACKOFF_MS}):
 * - 409 `no_credit` (the RevenueCat webhook hasn't reached the server yet);
 * - QA #416 M2: a network error / timeout (no server answer) — only with a transaction id,
 *   because only then is a repeat idempotent on the server.
 * Still nothing → `pending` (»Nakup je zabeležen, stvar bo kmalu dodana«); the purchase stays
 * in `pendingPurchases.ts` and is redeemed again when »Želje« opens. A 4xx refusal is final:
 * the credit stays unused under »Komu?«.
 */

import { ApiError, api } from '@/api/client';
import { errorReason, readParentPetItems, type ParentPetItem } from '@/modules/wishes/wishes';
import {
  pendingPurchasesOf,
  removePendingPurchase,
  savePendingPurchase,
  type PendingWishPurchase,
} from '@/modules/wishes/pendingPurchases';

export const REDEEM_RETRIES = 3;
/** Wait before retry 1, 2, 3 (ms). */
export const REDEEM_BACKOFF_MS: readonly number[] = [2_000, 4_000, 8_000];
/** One redeem call may not hang longer than this (expo/fetch has no iOS timeout). */
export const REDEEM_TIMEOUT_MS = 15_000;

export type RedeemOutcome =
  | { status: 'assigned'; items: ParentPetItem[] }
  /** The purchase is on the server's books (or will be) but not on a pet yet. */
  | { status: 'pending' }
  /** The server refused (4xx reason) — the credit stays unused for »Komu?«. */
  | { status: 'refused'; reason: string | null };

export interface RedeemDeps {
  redeem: typeof api.redeemPetItem;
  sleep: (ms: number) => Promise<void>;
}

const defaultDeps: RedeemDeps = {
  redeem: (petId, body, key) => api.redeemPetItem(petId, body, key, { timeoutMs: REDEEM_TIMEOUT_MS }),
  sleep: (ms) => new Promise((resolve) => setTimeout(resolve, ms)),
};

export interface RedeemRequest {
  petId: number;
  productId: string;
  wishId: number | null;
  transactionId: string | null;
  species?: 'dog' | 'cat';
}

/** Is this failure worth another attempt? */
function retryable(error: unknown, idempotent: boolean): boolean {
  if (error instanceof ApiError) {
    if (error.status === 409) return true;
    // 5xx / deploy (502 / 503 / 504): no decision was made — safe only with the idempotency key.
    return idempotent && error.status >= 500;
  }
  return idempotent; // network error / timeout
}

export async function redeemWithRetry(req: RedeemRequest, deps: RedeemDeps = defaultDeps): Promise<RedeemOutcome> {
  const idempotent = req.transactionId !== null;
  for (let attempt = 0; ; attempt += 1) {
    try {
      const res = await deps.redeem(req.petId, { product_id: req.productId, wish_id: req.wishId }, req.transactionId);
      return { status: 'assigned', items: readParentPetItems((res as { items?: unknown }).items, req.species ?? 'dog') };
    } catch (error) {
      if (retryable(error, idempotent) && attempt < REDEEM_RETRIES) {
        await deps.sleep(REDEEM_BACKOFF_MS[attempt] ?? REDEEM_BACKOFF_MS[REDEEM_BACKOFF_MS.length - 1]);
        continue;
      }
      if (!(error instanceof ApiError) || error.status === 409 || error.status >= 500) return { status: 'pending' };
      return { status: 'refused', reason: errorReason(error) };
    }
  }
}

/**
 * Redeem a charged purchase and keep the device record in step: saved before the first call,
 * removed once the server answered for good (assigned / refused), kept while `pending`.
 */
export async function redeemPurchase(
  req: RedeemRequest & { parentId: number; species: 'dog' | 'cat' },
  deps: RedeemDeps = defaultDeps,
): Promise<RedeemOutcome> {
  const { transactionId } = req;
  if (transactionId !== null) {
    await savePendingPurchase({ parentId: req.parentId, petId: req.petId, wishId: req.wishId, productId: req.productId, transactionId, species: req.species });
  }
  const outcome = await redeemWithRetry(req, deps);
  if (transactionId !== null && outcome.status !== 'pending') await removePendingPurchase(transactionId);
  return outcome;
}

let retryInFlight: Promise<number> | null = null;

/**
 * Re-try this parent's pending purchases (when »Želje« opens). Single-flight; one bounded
 * pass per entry. Returns how many were settled (assigned or refused) — the caller refetches.
 */
export function retryPendingPurchases(parentId: number, deps: RedeemDeps = defaultDeps): Promise<number> {
  if (retryInFlight) return retryInFlight;
  const run = (async () => {
    const list: PendingWishPurchase[] = await pendingPurchasesOf(parentId);
    let settled = 0;
    for (const e of list) {
      const outcome = await redeemWithRetry(
        { petId: e.petId, productId: e.productId, wishId: e.wishId, transactionId: e.transactionId, species: e.species },
        deps,
      );
      if (outcome.status !== 'pending') {
        await removePendingPurchase(e.transactionId);
        settled += 1;
      }
    }
    return settled;
  })().finally(() => {
    if (retryInFlight === run) retryInFlight = null;
  });
  retryInFlight = run;
  return run;
}
