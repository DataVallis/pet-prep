/**
 * `useChildPet()` — the child's pet state from `GET /api/child/pet` (M1-13), the HUD's
 * single source of truth. Realtime `PetUpdated` events and every action response write
 * into the same cache entry; while the websocket isn't connected the query polls every
 * 10 s (M1-15 fallback). Polling pauses in the background (focusManager ↔ AppState).
 *
 * Refetch rules (hotfix 2026-10-06, 429 incident — `modules/childPet/refetchGovernor`):
 * - every GET goes through `childPetFetchGate` (burst 3, then 1 per 6 s ≈ ≤ 10/min;
 *   closed for `Retry-After` after a 429), whatever triggered it;
 * - the time-based boundary refetch fires at most once per 30 s and backs off
 *   (60 s, 120 s … 5 min) while the server keeps answering the same state;
 * - a failed GET is retried with `Retry-After` / exponential backoff and the cached view
 *   stays on screen; once the query is in the error state the hook keeps retrying on
 *   its own (15 s … 2 min), with or without a cached view.
 */

import { useEffect, useRef } from 'react';
import { useQuery, useQueryClient, type QueryClient, type QueryFunctionContext } from '@tanstack/react-query';

import { api, type ChildPetState } from '@/api/client';
import {
  applyBroadcast,
  mergePolledState,
  nextRefreshDelay,
  normalizeChildState,
  type ChildPetView,
} from '@/modules/childPet/childPetView';
import {
  boundaryDelayMs,
  childPetFetchGate,
  errorRetryDelay,
  errorStatus,
  gatedCall,
  NO_BOUNDARY_BACKOFF,
  noteBoundaryFire,
  noteViewForBackoff,
  retryDelayFor,
  viewSignature,
  type BoundaryBackoff,
} from '@/modules/childPet/refetchGovernor';
import { useAppStore } from '@/store/appStore';
import type { PetUpdatedBroadcast } from '@/types';

export const childPetKey = ['child', 'pet'] as const;

/** Poll interval while the websocket is down (M1-15). */
export const CHILD_PET_POLL_MS = 10_000;

/** Refetch this long after a time-based boundary, so the server is surely past it. */
export const BOUNDARY_MARGIN_MS = 1_000;

/** Automatic retries of one failed GET before the query reports the error. */
export const CHILD_PET_RETRIES = 2;

/** setTimeout can't take more than ~24.8 days; a day is plenty (midnight always comes first). */
const MAX_TIMER_MS = 24 * 3_600_000;

async function fetchChildPet({ signal }: QueryFunctionContext): Promise<ChildPetView> {
  const raw = await gatedCall(childPetFetchGate, () => api.getChildPet(), { signal });
  return normalizeChildState(raw);
}

/**
 * Retry a failed GET? Throttling (429), server errors and network failures: yes, a
 * couple of times (the delay honours `Retry-After`). Other 4xx (auth, no pet): no.
 */
export function shouldRetryChildPet(failureCount: number, error: unknown): boolean {
  const status = errorStatus(error);
  if (status !== null && status >= 400 && status < 500 && status !== 429) return false;
  return failureCount < CHILD_PET_RETRIES;
}

/** Whether the HUD keeps trying on its own after the query gave up (same classes as above). */
export function isRecoverableError(error: unknown): boolean {
  const status = errorStatus(error);
  return status === null || status === 429 || status >= 500;
}

export function useChildPet() {
  const wsConnected = useAppStore((s) => s.wsStatus === 'connected');
  const client = useQueryClient();
  const query = useQuery<ChildPetView>({
    queryKey: childPetKey,
    queryFn: fetchChildPet,
    refetchInterval: wsConnected ? false : CHILD_PET_POLL_MS,
    retry: shouldRetryChildPet,
    retryDelay: retryDelayFor,
    // A poll that started before a newer broadcast arrived must not roll it back.
    structuralSharing: (previous, next) =>
      mergePolledState(previous as ChildPetView | undefined, next as ChildPetView),
  });

  // Time-based staleness (no event announces it): feed window opens / closes, water gap
  // ends, family midnight. One timer for the earliest boundary, rescheduled per state,
  // never sooner than the backoff allows after the previous boundary refetch.
  const view = query.data;
  const backoff = useRef<BoundaryBackoff>(NO_BOUNDARY_BACKOFF);
  useEffect(() => {
    if (!view) return;
    const signature = viewSignature(view);
    backoff.current = noteViewForBackoff(backoff.current, signature);
    const now = Date.now();
    const raw = nextRefreshDelay(view, now);
    if (raw === null) return;
    const delay = boundaryDelayMs(raw + BOUNDARY_MARGIN_MS, backoff.current, now);
    const timer = setTimeout(
      () => {
        backoff.current = noteBoundaryFire(backoff.current, signature, Date.now());
        void client.invalidateQueries({ queryKey: childPetKey });
      },
      Math.min(delay, MAX_TIMER_MS),
    );
    return () => clearTimeout(timer);
  }, [view, client]);

  // The query gave up (retries exhausted): keep trying by itself — the HUD shows the
  // last view (or the error screen without one) meanwhile. `errorUpdateCount` grows
  // with every failed attempt, so the backoff grows too; a success resets it.
  const { isError, error, errorUpdateCount } = query;
  const errorBase = useRef(0);
  useEffect(() => {
    if (!isError) {
      errorBase.current = errorUpdateCount;
      return;
    }
    if (!isRecoverableError(error)) return;
    const attempts = Math.max(1, errorUpdateCount - errorBase.current);
    const timer = setTimeout(() => {
      void client.refetchQueries({ queryKey: childPetKey, type: 'active' });
    }, errorRetryDelay(attempts, error));
    return () => clearTimeout(timer);
  }, [isError, error, errorUpdateCount, client]);

  return query;
}

/**
 * Replace the cache with a server state from an action response (success or refusal).
 * The response is the freshest truth for this child's own action, so it always wins;
 * only the broadcast clock is carried over to keep dropping older events.
 */
export function writeChildState(client: QueryClient, raw: ChildPetState): ChildPetView {
  const previous = client.getQueryData<ChildPetView>(childPetKey);
  const view = normalizeChildState(raw, previous?.lastEmittedMs ?? 0);
  client.setQueryData<ChildPetView>(childPetKey, view);
  return view;
}

/**
 * Apply a realtime snapshot to the cache. Older events (by `emitted_at`) are dropped;
 * events that the broadcast can't fully describe trigger a background refetch.
 * Returns whether the event was applied.
 */
export function applyBroadcastToCache(client: QueryClient, broadcast: PetUpdatedBroadcast): boolean {
  const current = client.getQueryData<ChildPetView>(childPetKey);
  if (!current) {
    void client.invalidateQueries({ queryKey: childPetKey });
    return false;
  }
  const result = applyBroadcast(current, broadcast);
  if (!result) return false;
  client.setQueryData<ChildPetView>(childPetKey, result.view);
  if (result.refetch) void client.invalidateQueries({ queryKey: childPetKey });
  return true;
}
