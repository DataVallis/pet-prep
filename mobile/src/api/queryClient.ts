/**
 * The single TanStack Query client. Exported so logout can drop every cached
 * response of the previous user (`queryClient.clear()`).
 */

import { QueryClient } from '@tanstack/react-query';

import { ApiError } from '@/api/client';

/** Don't retry what retrying can't fix: auth, permission, validation, throttling. */
export function shouldRetry(failureCount: number, error: unknown): boolean {
  if (error instanceof ApiError && error.status >= 400 && error.status < 500) {
    return false;
  }
  return failureCount < 2;
}

export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      retry: shouldRetry,
    },
  },
});
