/**
 * M5-08 validation signals (the M5-08 API contract): server state for the parent's acquisition answer
 * and the Phase-2 fake door. TanStack Query only — nothing lands in Zustand.
 */

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import {
  api,
  type AcquisitionFields,
  type Phase2InterestRequest,
  type Phase2InterestResponse,
  type UpdateAcquisitionResponse,
  type UserResponse,
} from '@/api/client';
import { parentDashboardKey } from '@/modules/family/live';

/** `GET /api/user` of the signed-in parent (only read for `acquisition_source`). */
export const parentAccountKey = ['parent', 'account'] as const;

/** Read once per session; `enabled` false once the card can never show (dismissed on this phone). */
export function useParentAccount(enabled: boolean) {
  return useQuery<UserResponse>({
    queryKey: parentAccountKey,
    queryFn: () => api.getUser(),
    enabled,
    staleTime: Infinity,
  });
}

/** The late answer via `PATCH /api/parent/settings`; the cached account then reads as answered. */
export function useSaveAcquisition() {
  const queryClient = useQueryClient();
  return useMutation<UpdateAcquisitionResponse, Error, AcquisitionFields>({
    mutationFn: (fields) => api.updateAcquisitionSource(fields),
    onSuccess: (_data, fields) => {
      queryClient.setQueryData<UserResponse>(parentAccountKey, (old) =>
        old ? { ...old, acquisition_source: fields.acquisition_source ?? null } : old,
      );
    },
  });
}

/** `POST /api/parent/phase2-interest`; the dashboard then says `submitted` (row shows the thanks). */
export function useSubmitPhase2Interest() {
  const queryClient = useQueryClient();
  return useMutation<Phase2InterestResponse, Error, Phase2InterestRequest>({
    mutationFn: (body) => api.submitPhase2Interest(body),
    onSuccess: (_data, body) => {
      queryClient.setQueryData<unknown>(parentDashboardKey, (old: unknown) =>
        typeof old === 'object' && old !== null ? { ...old, phase2_interest: { submitted: true, notify: body.notify } } : old,
      );
    },
  });
}
