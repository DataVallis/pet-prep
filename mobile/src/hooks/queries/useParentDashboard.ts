/**
 * TanStack Query hook for `GET /api/parent/dashboard`.
 */

import { useQuery, type Query } from '@tanstack/react-query';

import { api, NO_CHILD_PAIRED_MESSAGE, type ParentDashboardResponse } from '@/api/client';

export const parentDashboardKey = ['parent', 'dashboard'] as const;

interface Options {
  /**
   * Poll interval in ms (used while a pairing PIN is shown); false = no polling.
   * A function gets the latest data, so polling can stop as soon as a pet appears.
   */
  refetchInterval?: number | false | ((data: ParentDashboardResponse | undefined) => number | false);
  enabled?: boolean;
}

export function useParentDashboard({ refetchInterval = false, enabled = true }: Options = {}) {
  return useQuery<ParentDashboardResponse>({
    queryKey: parentDashboardKey,
    queryFn: api.getParentDashboard,
    refetchInterval:
      typeof refetchInterval === 'function'
        ? (query: Query<ParentDashboardResponse>) => refetchInterval(query.state.data)
        : refetchInterval,
    enabled,
  });
}

/** True when the parent has no child profile yet → show "Dodaj otroka". */
export function isNoChildPaired(data: ParentDashboardResponse | undefined): boolean {
  return data !== undefined && data.pet === null && 'message' in data && data.message === NO_CHILD_PAIRED_MESSAGE;
}

/** True once the dashboard reports a pet (child paired and the pet was born). */
export function hasPairedPet(data: ParentDashboardResponse | undefined): boolean {
  return data !== undefined && data.pet !== null;
}
