/**
 * »Želje« queries and mutations (M6-W04 child, M6-W05 parent). Server state only; the
 * open sheet / screen is UI state in the components. After every action the child pet
 * state (`childPetKey`) or the dashboard (`parentDashboardKey`) is invalidated, so counts
 * (`wishes.open_count`, `wishes_open_count`) and treats follow the server.
 *
 * An old server without the routes answers 404 (no `reason`) → `isFeatureMissing` →
 * the screens show "not available yet" and the entries stay hidden.
 */

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '@/api/client';
import { childPetKey } from '@/hooks/queries/useChildPet';
import { parentDashboardKey } from '@/modules/family/live';
import { fetchStoreProducts, usePurchasesStatus } from '@/modules/purchases';
import { useAppStore } from '@/store/appStore';
import { CHILD_WISHES_KEY, PARENT_WISHES_KEY, wishProductsKey } from '@/modules/wishes/keys';
import {
  applyWishCreated,
  readChildWishes,
  readParentWishes,
  readTreatResult,
  type ChildWishes,
  type ParentWishes,
  type TreatResult,
  type WishItemKey,
} from '@/modules/wishes/wishes';

export { CHILD_WISHES_KEY, PARENT_WISHES_KEY };

const noRetryOn4xx = (failureCount: number, error: unknown): boolean => {
  const status = (error as { status?: unknown } | null)?.status;
  if (typeof status === 'number' && status >= 400 && status < 500) return false;
  return failureCount < 1;
};

/** Child catalogue — fetched only while the sheet is open. */
export function useChildWishes(enabled: boolean) {
  return useQuery<ChildWishes>({
    queryKey: CHILD_WISHES_KEY,
    queryFn: async () => {
      const data = readChildWishes(await api.getChildWishes());
      if (data === null) throw new Error('Unexpected wishes response');
      return data;
    },
    enabled,
    retry: noRetryOn4xx,
    staleTime: 0,
  });
}

/** »Prosi starša«: the row turns »Prošnja poslana« on success; any answer refetches both. */
export function useCreateWish() {
  const client = useQueryClient();
  return useMutation<{ open_count: number | null; wish_id: number | null }, unknown, WishItemKey>({
    mutationFn: async (key) => {
      const res = (await api.createWish(key)) as { open_count?: unknown; wish?: { id?: unknown } };
      return {
        open_count: typeof res.open_count === 'number' ? res.open_count : null,
        wish_id: typeof res.wish?.id === 'number' ? res.wish.id : null,
      };
    },
    retry: false,
    onSuccess: (res, key) => {
      const current = client.getQueryData<ChildWishes>(CHILD_WISHES_KEY);
      if (current) client.setQueryData<ChildWishes>(CHILD_WISHES_KEY, applyWishCreated(current, key, res.wish_id, res.open_count));
    },
    onSettled: () => {
      void client.invalidateQueries({ queryKey: CHILD_WISHES_KEY });
      void client.invalidateQueries({ queryKey: childPetKey });
    },
  });
}

/** »Priboljšek« — one request per tap (never auto-retried: a care-like action without an idempotency key). */
export function useGiveTreat() {
  const client = useQueryClient();
  return useMutation<TreatResult, unknown, void>({
    mutationFn: async () => {
      const result = readTreatResult(await api.giveTreat());
      if (result === null) throw new Error('Unexpected treat response');
      return result;
    },
    retry: false,
    onSettled: () => {
      void client.invalidateQueries({ queryKey: childPetKey });
      void client.invalidateQueries({ queryKey: CHILD_WISHES_KEY });
    },
  });
}

function useParentId(): number | null {
  return useAppStore((s) => (s.authToken !== null && s.bootStatus === 'ready' && s.user?.role === 'parent' ? s.user.id : null));
}

export function useParentWishes(enabled = true) {
  const parentId = useParentId();
  return useQuery<ParentWishes>({
    queryKey: PARENT_WISHES_KEY,
    queryFn: async () => {
      const data = readParentWishes(await api.getParentWishes());
      if (data === null) throw new Error('Unexpected wishes response');
      return data;
    },
    enabled: enabled && parentId !== null,
    retry: noRetryOn4xx,
  });
}

/** Store prices of the given products (RevenueCat, localized). A product the store lacks is just missing. */
export function useWishProducts(productIds: readonly string[]) {
  const parentId = useParentId();
  const status = usePurchasesStatus();
  return useQuery({
    queryKey: wishProductsKey(parentId, productIds),
    queryFn: () => fetchStoreProducts(productIds),
    enabled: parentId !== null && status !== 'disabled' && productIds.length > 0,
    staleTime: 5 * 60_000,
    retry: 1,
  });
}

export function useDeclineWish() {
  const client = useQueryClient();
  return useMutation<unknown, unknown, number>({
    mutationFn: (wishId) => api.declineWish(wishId),
    retry: false,
    onSettled: () => {
      void client.invalidateQueries({ queryKey: PARENT_WISHES_KEY });
      void client.invalidateQueries({ queryKey: parentDashboardKey });
    },
  });
}
