/**
 * `POST /api/parent/generate-pin {child_id, pet_id?}` as a TanStack mutation (M2-02).
 * A new PIN for a child replaces that child's previous one on the server.
 * Throttled server-side to 5 requests / minute (`throttle:pairing`).
 */

import { useMutation } from '@tanstack/react-query';

import { api, type ChildPinResponse, type GenerateChildPinRequest } from '@/api/client';

export function useGeneratePin() {
  return useMutation<ChildPinResponse, unknown, GenerateChildPinRequest>({
    mutationFn: (body) => api.generatePin(body),
    retry: false,
  });
}
