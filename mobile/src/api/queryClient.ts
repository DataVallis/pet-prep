/**
 * The single TanStack Query client. Exported so logout can drop every cached
 * response of the previous user (`queryClient.clear()`).
 */

import { AppState, type AppStateStatus } from 'react-native';
import { QueryClient, focusManager } from '@tanstack/react-query';

import { ApiError } from '@/api/client';
import { retryDelayFor } from '@/modules/childPet/refetchGovernor';
import { OUTAGE_QUERY_RETRIES } from '@/modules/connectivity/serverOutage';
import { isDeployError } from '@/store/serverStatusStore';

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

/**
 * Don't retry what retrying can't fix: auth, permission, validation, throttling. M5-F13:
 * a deploy (maintenance 503, gateway 502 / 504, connection refused) is retried longer —
 * the query keeps its last data meanwhile and the outage banner explains the wait.
 */
export function shouldRetry(failureCount: number, error: unknown): boolean {
  if (isDeployError(error)) return failureCount < OUTAGE_QUERY_RETRIES;
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
      // Exponential (2 s, 4 s … 30 s); a 429 (the child state) or a maintenance 503 waits Retry-After.
      retryDelay: retryDelayFor,
    },
  },
});
