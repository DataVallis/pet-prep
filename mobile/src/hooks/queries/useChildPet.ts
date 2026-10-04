/**
 * `useChildPet()` — the child's pet state from `GET /api/child/pet` (M1-13), the HUD's
 * single source of truth. Realtime `PetUpdated` events and every action response write
 * into the same cache entry; while the websocket isn't connected the query polls every
 * 10 s (M1-15 fallback). Polling pauses in the background (focusManager ↔ AppState).
 */

import { useEffect } from 'react';
import { useQuery, useQueryClient, type QueryClient } from '@tanstack/react-query';

import { api, type ChildPetState } from '@/api/client';
import {
  applyBroadcast,
  mergePolledState,
  nextRefreshAt,
  normalizeChildState,
  type ChildPetView,
} from '@/modules/childPet/childPetView';
import { useAppStore } from '@/store/appStore';
import type { PetUpdatedBroadcast } from '@/types';

export const childPetKey = ['child', 'pet'] as const;

/** Poll interval while the websocket is down (M1-15). */
export const CHILD_PET_POLL_MS = 10_000;

/** Refetch this long after a time-based boundary, so the server is surely past it. */
export const BOUNDARY_MARGIN_MS = 1_000;

/** setTimeout can't take more than ~24.8 days; a day is plenty (midnight always comes first). */
const MAX_TIMER_MS = 24 * 3_600_000;

async function fetchChildPet(): Promise<ChildPetView> {
  return normalizeChildState(await api.getChildPet());
}

export function useChildPet() {
  const wsConnected = useAppStore((s) => s.wsStatus === 'connected');
  const client = useQueryClient();
  const query = useQuery<ChildPetView>({
    queryKey: childPetKey,
    queryFn: fetchChildPet,
    refetchInterval: wsConnected ? false : CHILD_PET_POLL_MS,
    // A poll that started before a newer broadcast arrived must not roll it back.
    structuralSharing: (previous, next) =>
      mergePolledState(previous as ChildPetView | undefined, next as ChildPetView),
  });

  // Time-based staleness (no event announces it): feed window opens / closes, water gap
  // ends, family midnight. One timer for the earliest boundary, rescheduled per state.
  const view = query.data;
  useEffect(() => {
    if (!view) return;
    const now = Date.now();
    const at = nextRefreshAt(view, now);
    if (at === null) return;
    const timer = setTimeout(
      () => {
        void client.invalidateQueries({ queryKey: childPetKey });
      },
      Math.min(Math.max(0, at - now) + BOUNDARY_MARGIN_MS, MAX_TIMER_MS),
    );
    return () => clearTimeout(timer);
  }, [view, client]);

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
