/**
 * React side of in-app purchases (M3-07). Parent only — in a child session every query
 * here is disabled and nothing reaches RevenueCat.
 */

import { useEffect, useSyncExternalStore } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import type { PurchasesOfferings, PurchasesPackage } from 'react-native-purchases';

import type { Entitlement } from '@/api/client';
import { useAppStore } from '@/store/appStore';
import { ENTITLEMENTS_KEY, fetchEntitlements } from './entitlements';
import {
  ensureParentIdentified,
  fetchOfferings,
  getPurchasesStatus,
  purchasePackage,
  restorePurchases,
  subscribePurchasesStatus,
  type PurchaseOutcome,
  type PurchasesStatus,
  type RestoreOutcome,
} from './purchases';

export function usePurchasesStatus(): PurchasesStatus {
  return useSyncExternalStore(subscribePurchasesStatus, getPurchasesStatus, getPurchasesStatus);
}

/** The signed-in parent's id, or null (signed out / child / still restoring). */
function useParentId(): number | null {
  return useAppStore((s) =>
    s.authToken !== null && s.bootStatus === 'ready' && s.user?.role === 'parent' ? s.user.id : null,
  );
}

/**
 * Mounted once in `AppNavigator`: identifies a parent with RevenueCat after sign-in and
 * on session restore. Runs once per parent id (the effect's only dependency); identify is
 * single-flight and does not retry by itself, so this can never loop. Logout is handled
 * by `logout()` → `resetPurchasesIdentity()`, not here.
 */
export function usePurchasesSession(): void {
  const parentId = useParentId();
  useEffect(() => {
    if (parentId === null) return;
    void ensureParentIdentified();
  }, [parentId]);
}

export const offeringsKey = (parentId: number | null) => ['parent', 'offerings', parentId] as const;

/** Store offerings (prices from the store, localised by it). Parent only; off when purchases are disabled. */
export function useOfferings() {
  const parentId = useParentId();
  const status = usePurchasesStatus();
  return useQuery<PurchasesOfferings>({
    queryKey: offeringsKey(parentId),
    queryFn: fetchOfferings,
    enabled: parentId !== null && status !== 'disabled',
    staleTime: 5 * 60_000,
    retry: 1,
  });
}

/** The family's server entitlements (`GET /api/parent/entitlements`) — what is actually unlocked. */
export function useEntitlements() {
  const parentId = useParentId();
  return useQuery<Entitlement[]>({
    queryKey: ENTITLEMENTS_KEY,
    queryFn: fetchEntitlements,
    enabled: parentId !== null,
    staleTime: 60_000,
  });
}

/** Buy a package; the outcome is a code — show it with `purchaseOutcomeMessage` at render. */
export function usePurchasePackage() {
  return useMutation<PurchaseOutcome, Error, PurchasesPackage>({ mutationFn: purchasePackage, retry: false });
}

export function useRestorePurchases() {
  return useMutation<RestoreOutcome, Error, void>({ mutationFn: () => restorePurchases(), retry: false });
}
