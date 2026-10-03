/**
 * `POST /api/parent/generate-pin` as a TanStack mutation. Each call replaces the
 * parent's previous PIN on the server (only the newest one is valid).
 * Throttled server-side to 5 requests / minute (`throttle:pairing`).
 */

import { useMutation } from '@tanstack/react-query';

import { api } from '@/api/client';
import type { GeneratePinResponse } from '@/types';

export function useGeneratePin() {
  return useMutation<GeneratePinResponse, unknown, void>({
    mutationFn: () => api.generatePin(),
    retry: false,
  });
}
