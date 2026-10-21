/**
 * Parent mutations on child profiles (M2-02): create a profile, sign a child out
 * on every device. Both refresh the dashboard's `family` section.
 */

import { useMutation, useQueryClient } from '@tanstack/react-query';

import {
  api,
  type CreateChildRequest,
  type CreateChildResponse,
  type DeleteChildResponse,
  type DeletionProof,
  type RevokeChildTokensResponse,
  type UpdateChildSettingsResponse,
} from '@/api/client';
import { parentDashboardKey } from '@/hooks/queries/useParentDashboard';
import { deleteConfirmWord } from '@/modules/account/account';

export function useCreateChild() {
  const queryClient = useQueryClient();
  return useMutation<CreateChildResponse, unknown, CreateChildRequest>({
    mutationFn: (body) => api.createChild(body),
    retry: false,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: parentDashboardKey }),
  });
}

/**
 * M2-08: delete a child profile (password re-entry, or a provider re-sign-in for a parent
 * without a password — M2-10c). Refreshes the family afterwards.
 */
export function useDeleteChild() {
  const queryClient = useQueryClient();
  return useMutation<DeleteChildResponse, unknown, { childId: number; proof: DeletionProof; acknowledgePaidChallenge?: boolean }>({
    mutationFn: ({ childId, proof, acknowledgePaidChallenge }) =>
      api.deleteChild(childId, proof, deleteConfirmWord(), acknowledgePaidChallenge === true),
    retry: false,
    // Also after a failure: without an answer the deletion may have happened (PR #29 m6).
    onSettled: () => queryClient.invalidateQueries({ queryKey: parentDashboardKey }),
  });
}

export function useRevokeChildDevices() {
  const queryClient = useQueryClient();
  return useMutation<RevokeChildTokensResponse, unknown, number>({
    mutationFn: (childId) => api.revokeChildTokens(childId),
    retry: false,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: parentDashboardKey }),
  });
}

/**
 * M5-T12: switch a child's »Prilagojen način« (wheelchair pushes instead of steps). Only the
 * boolean goes to the server. Refreshes the family afterwards (also after a failure: the
 * switch may have been stored without an answer).
 */
export function useSetAdaptedMode() {
  const queryClient = useQueryClient();
  return useMutation<UpdateChildSettingsResponse, unknown, { childId: number; adaptedMode: boolean }>({
    mutationFn: ({ childId, adaptedMode }) => api.updateChildSettings(childId, { adapted_mode: adaptedMode }),
    retry: false,
    onSettled: () => queryClient.invalidateQueries({ queryKey: parentDashboardKey }),
  });
}
