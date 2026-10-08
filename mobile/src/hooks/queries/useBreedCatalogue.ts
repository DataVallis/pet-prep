/**
 * The picker catalogue `GET /api/breeds` (M5-R06-02) as a TanStack query.
 *
 * - The request declares this build's client features (`species_cat` only with
 *   `CAT_UI_READY`), so the server never offers a species the app can't show.
 * - Cached for an hour (the catalogue changes only when an admin edits `breed_configs`);
 *   one retry, then {@link FALLBACK_CATALOGUE} — dog onboarding never breaks when the
 *   catalogue is unreachable or malformed.
 * - `catalogue` is null only while the first request is still running.
 */

import { useQuery } from '@tanstack/react-query';

import { api, CLIENT_FEATURES, type ClientFeature } from '@/api/client';
import { shouldRetry } from '@/api/queryClient';
import { FALLBACK_CATALOGUE, readBreedCatalogue, type BreedCatalogue } from '@/modules/petProfile/picker';

export const breedCatalogueKey = (features: readonly ClientFeature[] = CLIENT_FEATURES) =>
  ['parent', 'breeds', [...features].sort().join(',')] as const;

export interface BreedCatalogueState {
  /** The catalogue to show: the server's, or the fallback dogs after a failure; null while loading. */
  catalogue: BreedCatalogue | null;
  /** True when the fallback is shown (the server's catalogue could not be read). */
  isFallback: boolean;
  refetch: () => void;
}

export function useBreedCatalogue(
  options: { enabled?: boolean; features?: readonly ClientFeature[] } = {},
): BreedCatalogueState {
  const { enabled = true, features = CLIENT_FEATURES } = options;
  const query = useQuery<BreedCatalogue | null>({
    queryKey: breedCatalogueKey(features),
    enabled,
    queryFn: async ({ signal }) => readBreedCatalogue(await api.getBreedCatalogue(features, signal)),
    staleTime: 60 * 60_000,
    // One retry for a network / server error (never a 4xx), then the fallback.
    retry: (failureCount, error) => failureCount < 1 && shouldRetry(failureCount, error),
  });
  const fromServer = query.data ?? null;
  const failed = query.isError || (query.isSuccess && fromServer === null);
  return {
    catalogue: fromServer ?? (failed ? FALLBACK_CATALOGUE : null),
    isFallback: fromServer === null && failed,
    refetch: () => {
      void query.refetch();
    },
  };
}
