/**
 * M5-R09c »Ali si vedel?« — server state of the child's breed facts (TanStack Query).
 *
 * - `useBreedFacts(breed)` — `GET /api/breeds/{breed}/facts` (approved child facts, localized);
 *   an empty list (mutt / domestic cat / nothing approved yet) hides every entry point.
 * - `useFactOfTheDay(day)` — `GET /api/child/fact-of-the-day`, keyed by the family-local day so
 *   the chip / sheet decision is fetched again after family midnight.
 * - `useMarkFactRead()` — `POST /api/breed-facts/{fact}/read`, optimistic (»Novo« and the dot go
 *   at once), then the server's fact; the fact of the day is fetched again (the next unread one).
 * - `useRecordFactShown()` — `POST /api/child/fact-of-the-day/shown`. A 422 `refused` is an
 *   answer, not an error: it resolves `{ outcome: 'refused' }` and the caller keeps the chip.
 *
 * Keys live under `['child', 'breedFacts' | 'factOfTheDay']`, never under `['child', 'pet']`
 * (the pet state's invalidations must not refetch them). `logout()` clears the whole cache.
 */

import { useMutation, useQuery, useQueryClient, type QueryClient } from '@tanstack/react-query';

import {
  ApiError,
  api,
  type BreedFact,
  type BreedFactsResponse,
  type FactOfTheDayResponse,
  type FactShownRefusal,
} from '@/api/client';
import { childFactsOnly, isTimedReason, markReadInList } from '@/modules/breedFacts/breedFacts';
import { currentLanguageTag } from '@/i18n';
import { currentUserId, sameSession, type SessionContext } from '@/hooks/queries/useChildActions';

export const breedFactsRoot = ['child', 'breedFacts'] as const;
/** The language is part of the key: facts come localized (Accept-Language), a switch refetches. */
export const breedFactsKey = (breed: string, lang: string = currentLanguageTag()) => [...breedFactsRoot, breed, lang] as const;
export const factOfTheDayRoot = ['child', 'factOfTheDay'] as const;
export const factOfTheDayKey = (day: string, lang: string = currentLanguageTag()) => [...factOfTheDayRoot, day, lang] as const;

/** Facts change only when David approves new ones; a list is fresh for 10 minutes. */
export const BREED_FACTS_STALE_MS = 10 * 60_000;
export const FACT_OF_THE_DAY_STALE_MS = 5 * 60_000;
/** While the server's "no" is time-based (quiet hours, too soon …) the decision is re-asked this often. */
export const FACT_OF_THE_DAY_POLL_MS = 5 * 60_000;

export function useBreedFacts(breed: string | null, lang: string) {
  return useQuery<BreedFactsResponse>({
    queryKey: breedFactsKey(breed ?? '', lang),
    queryFn: async ({ signal }) => {
      const body = await api.getBreedFacts(breed ?? '', signal);
      // Second guard: a child never sees parent-only facts, whatever the server sent.
      return childFactsOnly(body);
    },
    enabled: breed !== null,
    staleTime: BREED_FACTS_STALE_MS,
  });
}

export function useFactOfTheDay(day: string, lang: string, enabled: boolean) {
  return useQuery<FactOfTheDayResponse>({
    queryKey: factOfTheDayKey(day, lang),
    queryFn: ({ signal }) => api.getFactOfTheDay(signal),
    enabled,
    staleTime: FACT_OF_THE_DAY_STALE_MS,
    refetchInterval: (query) => (isTimedReason(query.state.data?.reason ?? null) ? FACT_OF_THE_DAY_POLL_MS : false),
  });
}

/** The 422 body of a refused `shown` (typed in schema.ts), or null for any other failure. */
export function readShownRefusal(error: unknown): FactShownRefusal | null {
  if (!(error instanceof ApiError) || error.status !== 422) return null;
  const data = error.data;
  if (typeof data !== 'object' || data === null) return null;
  const body = data as Partial<FactShownRefusal>;
  return body.status === 'refused' && typeof body.reason === 'string' && typeof body.state === 'object' && body.state !== null
    ? (body as FactShownRefusal)
    : null;
}

function writeFactOfTheDay(client: QueryClient, state: FactOfTheDayResponse): void {
  // Every cached day gets the server's newest word (only today's is ever read).
  client.setQueriesData<FactOfTheDayResponse>({ queryKey: factOfTheDayRoot }, () => state);
}

/**
 * `shown` = recorded now (this device's card); `already_shown` = another device / an earlier
 * request already used today's card — nothing opens by itself; `refused` = 422.
 */
export type ShownOutcome =
  | { outcome: 'shown'; state: FactOfTheDayResponse }
  | { outcome: 'already_shown'; state: FactOfTheDayResponse }
  | { outcome: 'refused'; reason: FactShownRefusal['reason']; state: FactOfTheDayResponse };

export function useRecordFactShown() {
  const client = useQueryClient();
  return useMutation<ShownOutcome, unknown, void, SessionContext>({
    mutationKey: ['child', 'factOfTheDay', 'shown'],
    // Asked now or never: a paused request firing later would open a sheet nobody expects.
    networkMode: 'always',
    retry: false,
    onMutate: () => ({ userId: currentUserId() }),
    mutationFn: async () => {
      try {
        const body = await api.recordFactShown();
        return body.status === 'already_shown' ? { outcome: 'already_shown', state: body.state } : { outcome: 'shown', state: body.state };
      } catch (error) {
        const refusal = readShownRefusal(error);
        if (refusal) return { outcome: 'refused', reason: refusal.reason, state: refusal.state };
        throw error;
      }
    },
    onSuccess: (result, _variables, context) => {
      if (sameSession(context)) writeFactOfTheDay(client, result.state);
    },
  });
}

interface ReadContext extends SessionContext {
  previous: ReadonlyArray<readonly [readonly unknown[], BreedFactsResponse | undefined]>;
}

export function useMarkFactRead() {
  const client = useQueryClient();
  return useMutation<unknown, unknown, BreedFact, ReadContext>({
    mutationKey: ['child', 'breedFacts', 'read'],
    networkMode: 'always',
    // Idempotent on the server: a lost connection may retry.
    retry: (failureCount, error) => failureCount < 2 && !(error instanceof ApiError),
    onMutate: async (fact) => {
      await client.cancelQueries({ queryKey: breedFactsRoot });
      const previous = client.getQueriesData<BreedFactsResponse>({ queryKey: breedFactsRoot });
      const nowIso = new Date().toISOString();
      client.setQueriesData<BreedFactsResponse>({ queryKey: breedFactsRoot }, (list) =>
        list && Array.isArray(list.facts) ? markReadInList(list, fact.id, nowIso) : list,
      );
      return { previous, userId: currentUserId() };
    },
    mutationFn: (fact) => api.markBreedFactRead(fact.id),
    onSuccess: (_body, _fact, context) => {
      if (!sameSession(context)) return;
      void client.invalidateQueries({ queryKey: factOfTheDayRoot });
    },
    onError: (error, _fact, context) => {
      if (!sameSession(context)) return;
      // 404: the fact is gone (unapproved / pet changed) — the server's lists win.
      if (error instanceof ApiError) {
        void client.invalidateQueries({ queryKey: breedFactsRoot });
        void client.invalidateQueries({ queryKey: factOfTheDayRoot });
        return;
      }
      // Offline: undo, the »Novo« badge comes back (it will be marked the next time).
      for (const [key, data] of context?.previous ?? []) client.setQueryData(key, data);
    },
  });
}
