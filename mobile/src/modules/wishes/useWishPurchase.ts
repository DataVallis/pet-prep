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
import { redeemWithRetry, type RedeemOutcome } from '@/modules/wishes/redeem';

export type WishPurchaseResult = { kind: 'purchase'; failure: PurchaseFailure } | { kind: 'redeem'; outcome: RedeemOutcome };

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
      let transactionId: string | null = null;
      if (product !== null) {
        const bought = await purchaseStoreProduct(product);
        if (bought.status !== 'success') return { kind: 'purchase', failure: bought.status };
        transactionId = bought.transactionId;
      }
      const outcome = await redeemWithRetry({ petId, productId, wishId, transactionId, species });
      return { kind: 'redeem', outcome };
    },
    retry: false,
    onSettled: () => {
      void client.invalidateQueries({ queryKey: PARENT_WISHES_KEY });
      void client.invalidateQueries({ queryKey: parentDashboardKey });
    },
  });
}
