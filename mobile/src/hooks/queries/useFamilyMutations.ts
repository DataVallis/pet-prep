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
  type RevokeChildTokensResponse,
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

/** M2-08: delete a child profile (password re-entry). Refreshes the family afterwards. */
export function useDeleteChild() {
  const queryClient = useQueryClient();
  return useMutation<DeleteChildResponse, unknown, { childId: number; password: string }>({
    mutationFn: ({ childId, password }) => api.deleteChild(childId, password, deleteConfirmWord()),
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
