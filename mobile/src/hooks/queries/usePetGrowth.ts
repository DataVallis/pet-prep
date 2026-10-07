/**
 * "Album rasti" (M5-R04 part 2): the pet's picture per life stage, for the growth
 * section of the "Moj kuža" album. Fetched only while the album is open (`enabled`),
 * never polled. The data stays fresh until 1 min before its signed URLs expire
 * (`growthStaleTime`): reopening the album later fetches new URLs; while it is open the
 * album itself asks for fresh URLs before / after expiry. An error simply hides the
 * section — the album keeps working.
 * Every refresh goes through `useGrowthRefresh` (at most one per minute): a phone clock
 * running ahead would otherwise see every fresh answer as already expired and loop.
 */

import { useCallback, useRef } from 'react';
import { useQuery, useQueryClient, type QueryKey } from '@tanstack/react-query';

import { api } from '@/api/client';
import { growthStaleTime, readGrowth, type GrowthAlbum } from '@/modules/petMedia/growth';

/** Per pet: after a game over the child gets a new pet — never show the old pet's album from cache. */
export const childPetGrowthKey = (petId: number) => ['child', 'growth', petId] as const;
export const parentPetGrowthKey = (petId: number) => ['parent', 'pets', petId, 'growth'] as const;

/**
 * Child token: `GET /api/child/pet/growth` for the HUD's pet (`petId`; null = no state yet
 * → nothing fetched). An answer for another pet (the pet changed meanwhile) is an error.
 */
export function useChildPetGrowth(petId: number | null, enabled: boolean) {
  return useQuery<GrowthAlbum>({
    queryKey: childPetGrowthKey(petId ?? 0),
    queryFn: async () => {
      const album = readGrowth(await api.getChildPetGrowth());
      if (album.petId !== petId) throw new Error('Growth album of another pet');
      return album;
    },
    enabled: enabled && petId !== null,
    staleTime: (query) => growthStaleTime(query.state.data, Date.now()),
    retry: false,
  });
}

/** Parent token: `GET /api/parent/pets/{pet}/growth` (read-only viewer). */
export function useParentPetGrowth(petId: number | null, enabled: boolean) {
  return useQuery<GrowthAlbum>({
    queryKey: parentPetGrowthKey(petId ?? 0),
    queryFn: async () => {
      const album = readGrowth(await api.getParentPetGrowth(petId ?? 0));
      if (album.petId !== petId) throw new Error('Growth album of another pet');
      return album;
    },
    enabled: enabled && petId !== null,
    staleTime: (query) => growthStaleTime(query.state.data, Date.now()),
    retry: false,
  });
}

/** Minimum gap between two growth refreshes asked for by the album. */
export const GROWTH_REFRESH_MIN_GAP_MS = 60_000;

/** The album's "fresh URLs please" for the growth album: invalidate, at most once per minute. */
export function useGrowthRefresh(queryKey: QueryKey): () => void {
  const queryClient = useQueryClient();
  const last = useRef<number | null>(null);
  const key = useRef(queryKey);
  key.current = queryKey;
  return useCallback(() => {
    const now = Date.now();
    if (last.current !== null && now - last.current < GROWTH_REFRESH_MIN_GAP_MS) return;
    last.current = now;
    void queryClient.invalidateQueries({ queryKey: key.current });
  }, [queryClient]);
}
