/**
 * The picker catalogue `GET /api/breeds` (M5-R06-02) as a TanStack query.
 *
 * - The request declares this build's client features (`species_cat` only with
 *   `CAT_UI_READY`), so the server never offers a species the app can't show.
 * - Cached for an hour (the catalogue changes only when an admin edits `breed_configs`);
 *   one retry, then {@link FALLBACK_CATALOGUE} — dog onboarding never breaks when the
 *   catalogue is unreachable or malformed.
 * - `catalogue` is null only while the first request is still running, and at most
 *   {@link CATALOGUE_LOADING_LIMIT_MS}: a hung request (no answer, no error) then shows the
 *   fallback too, so the picker never spins forever (QA PR #94 m1). A late answer replaces it.
 * - M5-R21: colour labels come in the request language (`Accept-Language`), so the app
 *   language is part of the key — a language switch loads the catalogue again.
 */

import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';

import { api, CLIENT_FEATURES, type ClientFeature } from '@/api/client';
import { shouldRetry } from '@/api/queryClient';
import { currentLanguageTag } from '@/i18n';
import { FALLBACK_CATALOGUE, readBreedCatalogue, type BreedCatalogue } from '@/modules/petProfile/picker';

/** Prefix of every catalogue key (all feature sets and languages) — reset it to reload. */
export const breedCatalogueRoot = ['parent', 'breeds'] as const;

export const breedCatalogueKey = (features: readonly ClientFeature[] = CLIENT_FEATURES, lang: string = currentLanguageTag()) =>
  [...breedCatalogueRoot, [...features].sort().join(','), lang] as const;

/** Longest the picker shows a spinner before it falls back to today's dogs. */
export const CATALOGUE_LOADING_LIMIT_MS = 5_000;

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
  const { i18n } = useTranslation();
  void i18n.language; // re-render on a language switch → the new key loads localized labels
  const query = useQuery<BreedCatalogue | null>({
    queryKey: breedCatalogueKey(features, currentLanguageTag()),
    enabled,
    queryFn: async ({ signal }) => readBreedCatalogue(await api.getBreedCatalogue(features, signal)),
    staleTime: 60 * 60_000,
    // One retry for a network / server error (never a 4xx), then the fallback.
    retry: (failureCount, error) => failureCount < 1 && shouldRetry(failureCount, error),
  });
  const fromServer = query.data ?? null;
  const waiting = enabled && fromServer === null && query.isPending;
  const [timedOut, setTimedOut] = useState(false);
  useEffect(() => {
    if (!waiting) {
      setTimedOut(false);
      return undefined;
    }
    const timer = setTimeout(() => setTimedOut(true), CATALOGUE_LOADING_LIMIT_MS);
    return () => clearTimeout(timer);
  }, [waiting]);
  const failed = query.isError || (query.isSuccess && fromServer === null) || (waiting && timedOut);
  return {
    catalogue: fromServer ?? (failed ? FALLBACK_CATALOGUE : null),
    isFallback: fromServer === null && failed,
    refetch: () => {
      void query.refetch();
    },
  };
}
