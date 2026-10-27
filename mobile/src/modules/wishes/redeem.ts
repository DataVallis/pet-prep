/**
 * M6-W05 purchase → redeem flow (WISHES_API §4). After a successful store purchase the app
 * assigns the credit to the pet: `POST /api/parent/pets/{pet}/items/redeem` with the product,
 * the wish (when bought from one) and `Idempotency-Key` = the store transaction id.
 *
 * 409 `no_credit` = the RevenueCat webhook hasn't reached the server yet → retry
 * {@link REDEEM_RETRIES} times with backoff ({@link REDEEM_BACKOFF_MS}); still nothing →
 * `pending` (»Nakup je zabeležen, stvar bo kmalu dodana«): the credit shows up in
 * `unassigned_credits` and the parent assigns it with »Komu?«. Never retries on any other
 * answer (a repeat with the same key would be harmless, but there is nothing to gain).
 */

import { ApiError, api } from '@/api/client';
import { errorReason, readParentPetItems, type ParentPetItem } from '@/modules/wishes/wishes';

export const REDEEM_RETRIES = 3;
/** Wait before retry 1, 2, 3 (ms). */
export const REDEEM_BACKOFF_MS: readonly number[] = [2_000, 4_000, 8_000];

export type RedeemOutcome =
  | { status: 'assigned'; items: ParentPetItem[] }
  /** The purchase is on the server's books but not on a pet yet (409 after the retries, or offline). */
  | { status: 'pending' }
  /** The server refused (422 reason) — the credit stays unused for »Komu?». */
  | { status: 'refused'; reason: string | null };

export interface RedeemDeps {
  redeem: typeof api.redeemPetItem;
  sleep: (ms: number) => Promise<void>;
}

const defaultDeps: RedeemDeps = {
  redeem: (petId, body, key) => api.redeemPetItem(petId, body, key),
  sleep: (ms) => new Promise((resolve) => setTimeout(resolve, ms)),
};

export interface RedeemRequest {
  petId: number;
  productId: string;
  wishId: number | null;
  transactionId: string | null;
  species?: 'dog' | 'cat';
}

export async function redeemWithRetry(req: RedeemRequest, deps: RedeemDeps = defaultDeps): Promise<RedeemOutcome> {
  for (let attempt = 0; ; attempt += 1) {
    try {
      const res = await deps.redeem(req.petId, { product_id: req.productId, wish_id: req.wishId }, req.transactionId);
      return { status: 'assigned', items: readParentPetItems((res as { items?: unknown }).items, req.species ?? 'dog') };
    } catch (error) {
      const noCredit = error instanceof ApiError && error.status === 409;
      if (noCredit && attempt < REDEEM_RETRIES) {
        await deps.sleep(REDEEM_BACKOFF_MS[attempt] ?? REDEEM_BACKOFF_MS[REDEEM_BACKOFF_MS.length - 1]);
        continue;
      }
      if (noCredit || !(error instanceof ApiError)) return { status: 'pending' };
      return { status: 'refused', reason: errorReason(error) };
    }
  }
}
