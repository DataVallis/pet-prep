/**
 * Breed care task mutations (M5-R14 / R15 / R18 / R19 / R20).
 *
 * - The child's mini-games (teeth, folds, ears, nails): start / finish are NOT optimistic
 *   (like grooming) — the server generates the session and judges the finish; every answer
 *   with a `state` replaces the child state in the cache (`useSessionMutation`).
 * - The parent's reminder confirmation (groomer visit, bath): one POST, then the dashboard
 *   is refetched (the server's `care_reminders` win). Never retried automatically.
 */

import { useMutation, useQueryClient } from '@tanstack/react-query';

import { api } from '@/api/client';
import { useSessionMutation } from '@/hooks/queries/useCatCare';
import { readCatFinishResponse, readCatStartResponse, type CatFinishResponse, type CatStartResponse } from '@/modules/catCare/catCare';
import { readCareTaskResult, readCareTaskSession, type CareReminderKey, type CareTaskKey, type CareTaskResult, type CareTaskSession } from '@/modules/careTasks/careTasks';

export const useStartCareTask = (task: CareTaskKey) =>
  useSessionMutation<void, CatStartResponse<CareTaskSession>>(
    ['care-task', task, 'start'],
    () => api.startCareTask(task),
    (raw) => readCatStartResponse(raw, (value) => readCareTaskSession(value, task)),
  );

export interface FinishCareTaskVariables {
  sessionId: string;
  strokes: readonly number[];
}

export const useFinishCareTask = (task: CareTaskKey) =>
  useSessionMutation<FinishCareTaskVariables, CatFinishResponse<CareTaskResult>>(
    ['care-task', task, 'finish'],
    ({ sessionId, strokes }) => api.finishCareTask(task, sessionId, strokes),
    (raw) => readCatFinishResponse(raw, readCareTaskResult),
  );

/** Parent: mark a reminder done for the current period; the dashboard is refetched afterwards. */
export function useConfirmCareReminder() {
  const client = useQueryClient();
  return useMutation<unknown, unknown, { petId: number; task: CareReminderKey }>({
    mutationKey: ['parent', 'care-reminder'],
    mutationFn: ({ petId, task }) => api.confirmCareReminder(petId, task),
    onSettled: () => {
      void client.invalidateQueries({ queryKey: ['parent'] });
    },
  });
}
