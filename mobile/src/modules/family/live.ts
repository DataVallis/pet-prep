/**
 * Live parent dashboard (M2-05): Reverb `pet.updated` events for any pet of the
 * family patch the cached `GET /api/parent/dashboard` at once (metrics, flags), and
 * every event other than a plain decay tick also refetches it — routines, Care Score,
 * traffic light and timeline are server computations the event can't carry.
 * Without a live socket the dashboard polls instead (`PARENT_POLL_MS`).
 */

import type { QueryClient } from '@tanstack/react-query';

import type { ParentDashboardResponse } from '@/api/client';
import type { WebSocketStatus } from '@/store/appStore';
import type { PetUpdatedBroadcast } from '@/types';

/** Poll interval while the socket is not subscribed (spec: polling fallback 30 s). */
export const PARENT_POLL_MS = 30_000;

export const parentDashboardKey = ['parent', 'dashboard'] as const;
export const parentActivitiesKey = (petId: number) => ['parent', 'activities', petId] as const;
export const childReportKey = (childId: number, days: number) => ['parent', 'childReport', childId, days] as const;

/** 30 s polling unless the private channel is subscribed. */
export function livePollInterval(wsStatus: WebSocketStatus): number | false {
  return wsStatus === 'connected' ? false : PARENT_POLL_MS;
}

/** Copy the broadcast's pet snapshot onto that pet of the cached dashboard (immutable). */
export function patchDashboardPet(
  data: ParentDashboardResponse | undefined,
  event: PetUpdatedBroadcast,
): ParentDashboardResponse | undefined {
  if (!data || !data.family) return data;
  const pets = data.family.pets;
  const index = pets.findIndex((p) => p.id === event.pet_id);
  if (index === -1) return data;
  const pet = pets[index];
  const patched = {
    ...pet,
    metrics: {
      hunger: event.hunger_level,
      thirst: event.thirst_level,
      energy: event.energy_level,
      hygiene: event.hygiene_level,
    },
    is_active: event.is_active,
    is_game_over: event.is_game_over,
    is_hard_stopped: event.is_hard_stopped,
    is_ill: event.is_ill,
    escalation_level: event.escalation_level,
    awaiting_contract: event.awaiting_contract ?? pet.awaiting_contract,
    born_at: event.born_at !== undefined ? event.born_at : pet.born_at,
  };
  const nextPets = pets.slice();
  nextPets[index] = patched;
  return { ...data, family: { ...data.family, pets: nextPets } } as ParentDashboardResponse;
}

/** Set one pet's hard-stop flag in the cached dashboard (after the toggle answered). */
export function setDashboardHardStop(
  data: ParentDashboardResponse | undefined,
  petId: number,
  isHardStopped: boolean,
): ParentDashboardResponse | undefined {
  if (!data || !data.family) return data;
  const pets = data.family.pets.map((p) => (p.id === petId ? { ...p, is_hard_stopped: isHardStopped } : p));
  return { ...data, family: { ...data.family, pets } } as ParentDashboardResponse;
}

/** A plain decay tick only moves metrics — everything else changes routines / light / timeline. */
export function needsRefetch(event: PetUpdatedBroadcast): boolean {
  return event.event_type !== 'metric_changed';
}

/** The parent's broadcast handler: patch now, refetch the computed parts when needed. */
export function applyParentBroadcast(queryClient: QueryClient, event: PetUpdatedBroadcast): void {
  queryClient.setQueryData<ParentDashboardResponse>(parentDashboardKey, (old) => patchDashboardPet(old, event));
  if (!needsRefetch(event)) return;
  void queryClient.invalidateQueries({ queryKey: parentDashboardKey });
  void queryClient.invalidateQueries({ queryKey: parentActivitiesKey(event.pet_id) });
  void queryClient.invalidateQueries({ queryKey: ['parent', 'childReport'] });
}
