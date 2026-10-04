/**
 * Parent server state beyond the dashboard (M2-05 / M2-01a): child report, pet
 * activity timeline (paginated), hard stop per pet, quiet hours, second-parent
 * invite and joining a family. All TanStack Query; nothing lands in Zustand.
 */

import {
  keepPreviousData,
  useInfiniteQuery,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query';

import {
  api,
  type HardStopResponse,
  type InviteParentResponse,
  type JoinFamilyResponse,
  type PetActivitiesResponse,
} from '@/api/client';
import {
  childReportKey,
  parentActivitiesKey,
  parentDashboardKey,
  setDashboardHardStop,
} from '@/modules/family/live';
import { readChildReport, type ChildReport, type ReportDays } from '@/modules/family/scoring';
import type { ParentDashboardResponse } from '@/api/client';
import type { QuietHours } from '@/types';

/** The child's report for 7 / 30 / 84 days; the previous period stays visible while switching. */
export function useChildReport(childId: number, days: ReportDays) {
  return useQuery<ChildReport>({
    queryKey: childReportKey(childId, days),
    queryFn: async () => {
      const report = readChildReport(await api.getChildReport(childId, days));
      if (!report) throw new Error('Unexpected report shape');
      return report;
    },
    placeholderData: keepPreviousData,
    // Switching back to a period shows the cached report without a request; live
    // events (`applyParentBroadcast`) invalidate reports when something happened.
    staleTime: 60_000,
  });
}

export const ACTIVITIES_PAGE_SIZE = 20;

/** One family pet's activities, newest first, page by page (`/api/parent/activities`). */
export function usePetActivities(petId: number | null) {
  return useInfiniteQuery<PetActivitiesResponse>({
    queryKey: parentActivitiesKey(petId ?? 0),
    queryFn: ({ pageParam }) => api.getPetActivities(petId ?? 0, Number(pageParam), ACTIVITIES_PAGE_SIZE),
    initialPageParam: 1,
    getNextPageParam: (last) =>
      last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined,
    enabled: petId !== null,
  });
}

/**
 * Toggle the hard stop of one pet (the endpoint is a toggle — the screen confirms
 * first). The answer's flag goes straight into the cached dashboard, then it refetches.
 */
export function useToggleHardStop() {
  const queryClient = useQueryClient();
  return useMutation<HardStopResponse, unknown, number>({
    mutationFn: (petId) => api.toggleHardStop(petId),
    retry: false,
    onSuccess: (res) => {
      queryClient.setQueryData<ParentDashboardResponse>(parentDashboardKey, (old) =>
        setDashboardHardStop(old, res.pet_id, res.is_hard_stopped),
      );
      void queryClient.invalidateQueries({ queryKey: parentDashboardKey });
    },
  });
}

export const quietHoursKey = ['parent', 'quietHours'] as const;

/** Family quiet hours (null = none configured yet). */
export function useQuietHours() {
  return useQuery<QuietHours | null>({
    queryKey: quietHoursKey,
    queryFn: async () => (await api.getQuietHours()).quiet_hours,
  });
}

export function useUpdateQuietHours() {
  const queryClient = useQueryClient();
  return useMutation<{ message: string; quiet_hours: QuietHours }, unknown, Partial<QuietHours>>({
    mutationFn: (body) => api.updateQuietHours(body),
    retry: false,
    onSuccess: (res) => {
      queryClient.setQueryData(quietHoursKey, res.quiet_hours);
      // Quiet hours change what is expected today (routines, light).
      void queryClient.invalidateQueries({ queryKey: parentDashboardKey });
    },
  });
}

/** New single-use code for a second parent (revokes this parent's previous code). */
export function useInviteParent() {
  return useMutation<InviteParentResponse, unknown, void>({
    mutationFn: () => api.inviteParent(),
    retry: false,
  });
}

/** Join another parent's family; everything parent-side is refetched for the new family. */
export function useJoinFamily() {
  const queryClient = useQueryClient();
  return useMutation<JoinFamilyResponse, unknown, string>({
    mutationFn: (code) => api.joinFamily(code),
    retry: false,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['parent'] }),
  });
}
