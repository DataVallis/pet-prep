import type { ReactElement } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render } from '@testing-library/react-native';

/**
 * Render with a fresh QueryClient (no retries, no cache sharing between tests).
 * gcTime Infinity on both queries and mutations: a finite gcTime schedules a
 * garbage-collection timer (default 5 min) that keeps the Jest worker alive.
 */
export function renderWithQuery(ui: ReactElement) {
  const client = new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: Infinity },
      mutations: { retry: false, gcTime: Infinity },
    },
  });
  return { client, ...render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>) };
}
