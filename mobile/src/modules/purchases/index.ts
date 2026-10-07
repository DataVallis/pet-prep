/** In-app purchases (M3-07) — see `purchases.ts` for the rules (parent only, server is the truth). */

export {
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
export { ENTITLEMENTS_KEY, isEntitlementActive, readEntitlements } from './entitlements';
export { purchaseOutcomeMessage, restoreOutcomeMessage } from './messages';
export {
  useEntitlements,
  useOfferings,
  usePurchasePackage,
  usePurchasesSession,
  usePurchasesStatus,
  useRestorePurchases,
} from './usePurchases';
