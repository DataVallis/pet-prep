/** In-app purchases (M3-07) — see `purchases.ts` for the rules (parent only, server is the truth). */

export {
  CHALLENGE_PRODUCT_ID,
  challengePackage,
  configurePurchases,
  ensureParentIdentified,
  fetchOfferings,
  getPurchasesStatus,
  mapPurchaseError,
  purchasePackage,
  resetPurchasesIdentity,
  restorePurchases,
  PurchasesUnavailableError,
  type PurchaseFailure,
  type PurchaseOutcome,
  type PurchasesStatus,
  type RestoreOutcome,
} from './purchases';
export { BILLING_KEY, billingPet, readBilling, type Billing, type PurchaseTarget } from './billing';
export { purchaseOutcomeMessage, restoreOutcomeMessage } from './messages';
export {
  useBilling,
  useOfferings,
  usePurchasePackage,
  usePurchasesSession,
  usePurchasesStatus,
  useRestorePurchases,
} from './usePurchases';
