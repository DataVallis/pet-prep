/**
 * The single TanStack Query client. Exported so logout can drop every cached
 * response of the previous user (`queryClient.clear()`).
 */

import { AppState, type AppStateStatus } from 'react-native';
import { QueryClient, focusManager } from '@tanstack/react-query';

import { ApiError } from '@/api/client';
import { retryDelayFor } from '@/modules/childPet/refetchGovernor';

/**
 * React Native has no window focus events: tell TanStack the app is "focused"
 * only while it is in the foreground, so refetchInterval polling (e.g. the
 * pairing PIN screen) pauses in the background and refetches on return.
 */
export function bindFocusManagerToAppState(): void {
  focusManager.setEventListener((handleFocus) => {
    const subscription = AppState.addEventListener('change', (state: AppStateStatus) => {
      handleFocus(state === 'active');
    });
    return () => subscription.remove();
  });
}

bindFocusManagerToAppState();

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
      // Exponential (2 s, 4 s …); a query that retries a 429 (the child state) waits Retry-After.
      retryDelay: retryDelayFor,
    },
  },
});
