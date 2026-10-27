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
  purchaseStoreProduct,
  fetchStoreProducts,
  resetPurchasesIdentity,
  restorePurchases,
  PurchasesUnavailableError,
  type PurchaseFailure,
  type PurchaseOutcome,
  type PurchasesStatus,
  type RestoreOutcome,
  type StoreProductOutcome,
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
