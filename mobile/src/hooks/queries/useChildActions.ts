/**
 * Child care actions (M1-13 / M1-14): `useFeed`, `useWater`, `useClean`, `useSyncSteps`.
 *
 * Feed / water / clean are optimistic — the metric jumps to 100 % at once — and the
 * cache is then ALWAYS replaced by the server's `state` (200, 422 refusal, 423 lock).
 * Without a state in the answer (offline, 5xx, 429) the optimistic change is rolled
 * back. Locks and the contract step follow from the cached state (HUD → store), so the
 * hooks need no navigation logic. Mutations run even when TanStack thinks the device is
 * offline (`networkMode: 'always'`): a paused feed that fires an hour later, outside
 * the window, would only confuse a child.
 */

import { useMutation, useQueryClient, type QueryClient } from '@tanstack/react-query';

import { api, type ChildActionResponse, type SyncStepsResponse } from '@/api/client';
import { classifyActionError } from '@/modules/childPet/actionMessages';
import { optimisticView, type CareAction, type ChildPetView } from '@/modules/childPet/childPetView';
import { childPetKey, writeChildState } from '@/hooks/queries/useChildPet';

interface CareContext {
  previous: ChildPetView | undefined;
}

const CALLS: Record<CareAction, () => Promise<ChildActionResponse>> = {
  feed: () => api.feedPet(),
  water: () => api.waterPet(),
  clean: () => api.cleanPet(),
};

/** Put the server's answer into the cache, or roll the optimistic change back. */
function settleFromError(client: QueryClient, error: unknown, context: CareContext | undefined): void {
  const failure = classifyActionError(error);
  if ((failure.kind === 'refused' || failure.kind === 'locked') && failure.state) {
    writeChildState(client, failure.state);
    return;
  }
  if (context?.previous) client.setQueryData<ChildPetView>(childPetKey, context.previous);
}

function useCareAction(action: CareAction) {
  const client = useQueryClient();
  return useMutation<ChildActionResponse, unknown, void, CareContext>({
    mutationKey: ['child', 'pet', action],
    networkMode: 'always',
    mutationFn: CALLS[action],
    onMutate: async () => {
      // A poll landing mid-flight would overwrite the optimistic value with the old one.
      await client.cancelQueries({ queryKey: childPetKey });
      const previous = client.getQueryData<ChildPetView>(childPetKey);
      if (previous) client.setQueryData<ChildPetView>(childPetKey, optimisticView(previous, action));
      return { previous };
    },
    onSuccess: (response) => {
      writeChildState(client, response.state);
    },
    onError: (error, _variables, context) => {
      settleFromError(client, error, context);
    },
  });
}

export const useFeed = () => useCareAction('feed');
export const useWater = () => useCareAction('water');
export const useClean = () => useCareAction('clean');

export interface StepSyncVariables {
  stepsToday: number;
  /** ISO 8601 with the device offset (`isoWithOffset`). */
  recordedAt: string;
}

/**
 * Step sync (`POST /api/child/pet/steps`). Not optimistic — the server decides what it
 * accepts (anti-cheat, stale day); every answer, including capped / rejected / stale
 * and a 423, replaces the cache. Failures are quiet (the next sync retries).
 */
export function useSyncSteps() {
  const client = useQueryClient();
  return useMutation<SyncStepsResponse, unknown, StepSyncVariables>({
    mutationKey: ['child', 'pet', 'steps'],
    networkMode: 'always',
    mutationFn: ({ stepsToday, recordedAt }) =>
      api.syncSteps({ steps_today: stepsToday, source: 'pedometer', recorded_at: recordedAt }),
    onSuccess: (response) => {
      writeChildState(client, response.state);
    },
    onError: (error) => {
      settleFromError(client, error, undefined);
    },
  });
}
