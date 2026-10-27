/**
 * M6-W05 parent purchase of a wish item: store purchase (`purchaseStoreProduct`, parent only —
 * the parent app is password-protected, same protection as the challenge, PAYMENTS_SPEC) →
 * `redeemWithRetry` (409 → 3 retries with backoff → `pending`) → refetch wishes + dashboard.
 * Assigning an unassigned credit (»Komu?«) is the redeem without a purchase.
 * The outcome is a code; the screen translates it at render (`wishResultText`).
 */

import { useMutation, useQueryClient } from '@tanstack/react-query';
import type { PurchasesStoreProduct } from 'react-native-purchases';

import { t } from '@/i18n';
import { parentDashboardKey } from '@/modules/family/live';
import { purchaseOutcomeMessage, purchaseStoreProduct, type PurchaseFailure } from '@/modules/purchases';
import { PARENT_WISHES_KEY } from '@/modules/wishes/keys';
import { api } from '@/api/client';
import { useAppStore } from '@/store/appStore';
import { redeemPurchase, redeemWithRetry, type RedeemOutcome } from '@/modules/wishes/redeem';
import { purchaseStillPossible, readParentWishes, type PurchaseCheck } from '@/modules/wishes/wishes';

export type WishPurchaseResult =
  | { kind: 'purchase'; failure: PurchaseFailure }
  /** QA #416 m4: refused before the store sheet — fresh data says it is no longer possible. */
  | { kind: 'stale'; check: Exclude<PurchaseCheck, 'ok'> }
  /** The fresh re-check itself failed (offline) — nothing was charged. */
  | { kind: 'checkFailed' }
  | { kind: 'redeem'; outcome: RedeemOutcome };

export interface WishPurchaseInput {
  /** Null = assign an already bought credit (»Komu?«). */
  product: PurchasesStoreProduct | null;
  productId: string;
  petId: number;
  wishId: number | null;
  species: 'dog' | 'cat';
}

export function wishResultText(result: WishPurchaseResult): string {
  if (result.kind === 'purchase') return purchaseOutcomeMessage({ status: result.failure });
  if (result.kind === 'checkFailed') return t('wishes:parent.result.checkFailed');
  if (result.kind === 'stale') return t(`wishes:parent.result.stale.${result.check}`);
  const o = result.outcome;
  switch (o.status) {
    case 'assigned':
      return t('wishes:parent.result.assigned');
    case 'pending':
      return t('wishes:parent.result.pending');
    case 'refused':
      return o.reason === 'already_owned' ? t('wishes:parent.result.alreadyOwned') : t('wishes:parent.result.refused');
  }
}

export function isGoodResult(result: WishPurchaseResult): boolean {
  return result.kind === 'redeem' && (result.outcome.status === 'assigned' || result.outcome.status === 'pending');
}

export function useWishPurchase() {
  const client = useQueryClient();
  return useMutation<WishPurchaseResult, unknown, WishPurchaseInput>({
    mutationFn: async ({ product, productId, petId, wishId, species }) => {
      const parentId = useAppStore.getState().user?.id ?? null;
      if (product === null) {
        // »Komu?«: assign an already bought credit — no store, no device record.
        return { kind: 'redeem', outcome: await redeemWithRetry({ petId, productId, wishId, transactionId: null, species }) };
      }
      // QA #416 m4: another parent may have bought / declined meanwhile — re-check on fresh data
      // right before the store sheet opens (nothing is charged when it is no longer possible).
      let fresh;
      try {
        fresh = readParentWishes(await api.getParentWishes());
      } catch {
        return { kind: 'checkFailed' };
      }
      if (fresh === null) return { kind: 'checkFailed' };
      client.setQueryData(PARENT_WISHES_KEY, fresh);
      const check = purchaseStillPossible(fresh, { petId, productId, wishId });
      if (check !== 'ok') return { kind: 'stale', check };
      const bought = await purchaseStoreProduct(product);
      if (bought.status !== 'success') return { kind: 'purchase', failure: bought.status };
      // QA #416 M2: remembered on the device until the server assigned it to THIS pet / wish.
      const outcome =
        parentId !== null
          ? await redeemPurchase({ petId, productId, wishId, transactionId: bought.transactionId, species, parentId })
          : await redeemWithRetry({ petId, productId, wishId, transactionId: bought.transactionId, species });
      return { kind: 'redeem', outcome };
    },
    retry: false,
    onSettled: () => {
      void client.invalidateQueries({ queryKey: PARENT_WISHES_KEY });
      void client.invalidateQueries({ queryKey: parentDashboardKey });
    },
  });
}
