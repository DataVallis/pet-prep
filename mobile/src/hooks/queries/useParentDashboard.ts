/**
 * TanStack Query hook for `GET /api/parent/dashboard`.
 *
 * Default (M2-05): live — Reverb events patch / refetch the cache
 * (`useParentLiveUpdates`), and while no pet channel is subscribed the query polls
 * every 30 s. Screens that need a tighter loop (the PIN screen waiting for the child)
 * pass their own `refetchInterval`.
 */

import { useQuery, type Query } from '@tanstack/react-query';

import { api, NO_CHILD_PAIRED_MESSAGE, type ParentDashboardResponse } from '@/api/client';
import { livePollInterval, parentDashboardKey } from '@/modules/family/live';
import { useAppStore } from '@/store/appStore';

export { parentDashboardKey };

interface Options {
  /**
   * Poll interval in ms; false = no polling; 'live' (default) = 30 s while the socket
   * is not connected. A function gets the latest data, so polling can stop as soon as
   * a pet appears.
   */
  refetchInterval?: 'live' | number | false | ((data: ParentDashboardResponse | undefined) => number | false);
  enabled?: boolean;
}

export function useParentDashboard({ refetchInterval = 'live', enabled = true }: Options = {}) {
  const wsStatus = useAppStore((s) => s.wsStatus);
  return useQuery<ParentDashboardResponse>({
    queryKey: parentDashboardKey,
    queryFn: api.getParentDashboard,
    refetchInterval:
      refetchInterval === 'live'
        ? livePollInterval(wsStatus)
        : typeof refetchInterval === 'function'
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
