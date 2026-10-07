/**
 * Child care actions (M1-13 / M1-14): `useFeed`, `useWater`, `useClean`, `useSyncSteps`;
 * M5-R02: `useTakeOut` ("Pelji ven") and `useResolveChewing` ("Pospravi in daj igračo").
 *
 * Feed / water / clean are optimistic — the metric jumps to 100 % at once — and the
 * cache is then ALWAYS replaced by the server's `state` (200, 422 refusal, 423 lock).
 * Without a state in the answer (offline, 5xx, 429) only the action's metric and flags
 * are restored on top of the current cache (a broadcast may have landed meanwhile) and
 * the state is refetched. Answers that arrive after the session changed (logout / other
 * child) are ignored. Locks and the contract step follow from the cached state (HUD → store), so the
 * hooks need no navigation logic. Mutations run even when TanStack thinks the device is
 * offline (`networkMode: 'always'`): a paused feed that fires an hour later, outside
 * the window, would only confuse a child.
 */

import { useMutation, useQueryClient, type QueryClient } from '@tanstack/react-query';

import { api, type ChildActionResponse, type SyncStepsRequest, type SyncStepsResponse } from '@/api/client';
import { classifyActionError } from '@/modules/childPet/actionMessages';
import {
  optimisticView,
  revertOptimistic,
  type CareAction,
  type ChildPetView,
} from '@/modules/childPet/childPetView';
import { useAppStore } from '@/store/appStore';
import { childPetKey, writeChildState } from '@/hooks/queries/useChildPet';

export interface SessionContext {
  /** Child signed in when the request started; writes are skipped for another session. */
  userId: number | null;
}

interface CareContext extends SessionContext {
  previous: ChildPetView | undefined;
}

export const currentUserId = (): number | null => useAppStore.getState().user?.id ?? null;

export function sameSession(context: SessionContext | undefined): boolean {
  return context !== undefined && context.userId !== null && context.userId === currentUserId();
}

const CALLS: Record<CareAction, () => Promise<ChildActionResponse>> = {
  feed: () => api.feedPet(),
  water: () => api.waterPet(),
  clean: () => api.cleanPet(),
  take_out: () => api.takeOutPet(),
  resolve_chewing: () => api.resolveChewing(),
};

/** Put the server's answer into the cache, or undo the optimistic change and refetch. */
export function settleFromError(
  client: QueryClient,
  error: unknown,
  action: CareAction | null,
  context: CareContext | SessionContext | undefined,
): void {
  if (!sameSession(context)) return;
  const failure = classifyActionError(error);
  if ((failure.kind === 'refused' || failure.kind === 'locked') && failure.state) {
    writeChildState(client, failure.state);
    return;
  }
  const previous = context && 'previous' in context ? context.previous : undefined;
  const current = client.getQueryData<ChildPetView>(childPetKey);
  if (action && previous && current) {
    client.setQueryData<ChildPetView>(childPetKey, revertOptimistic(current, previous, action));
  }
  void client.invalidateQueries({ queryKey: childPetKey });
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
      return { previous, userId: currentUserId() };
    },
    onSuccess: (response, _variables, context) => {
      if (sameSession(context)) writeChildState(client, response.state);
    },
    onError: (error, _variables, context) => {
      settleFromError(client, error, action, context);
    },
  });
}

export const useFeed = () => useCareAction('feed');
export const useWater = () => useCareAction('water');
export const useClean = () => useCareAction('clean');
export const useTakeOut = () => useCareAction('take_out');
export const useResolveChewing = () => useCareAction('resolve_chewing');

export interface StepSyncVariables {
  stepsToday: number;
  /** ISO 8601 with the device offset (`isoWithOffset`). */
  recordedAt: string;
  /** Which source gave the (max-merged) total; validated by the server, not stored. Default `pedometer`. */
  source?: SyncStepsRequest['source'];
}

/**
 * Step sync (`POST /api/child/pet/steps`). Not optimistic — the server decides what it
 * accepts (anti-cheat, stale day); every answer, including capped / rejected / stale
 * and a 423, replaces the cache. Failures are quiet (the next sync retries).
 */
export function useSyncSteps() {
  const client = useQueryClient();
  return useMutation<SyncStepsResponse, unknown, StepSyncVariables, SessionContext>({
    mutationKey: ['child', 'pet', 'steps'],
    networkMode: 'always',
    onMutate: () => ({ userId: currentUserId() }),
    mutationFn: ({ stepsToday, recordedAt, source = 'pedometer' }) =>
      api.syncSteps({ steps_today: stepsToday, source, recorded_at: recordedAt }),
    onSuccess: (response, _variables, context) => {
      if (sameSession(context)) writeChildState(client, response.state);
    },
    onError: (error, _variables, context) => {
      settleFromError(client, error, null, context);
    },
  });
}
