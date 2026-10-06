/**
 * Live parent dashboard (M2-05): Reverb `pet.updated` events for any pet of the
 * family patch the cached `GET /api/parent/dashboard` at once (metrics, flags), and
 * every event other than a plain decay tick also refetches it — routines, Care Score,
 * traffic light and timeline are server computations the event can't carry. A decay
 * tick refetches at most once a minute (a tick can close a window → "missed").
 * Without every pet channel live the dashboard polls every 30 s; while live it still
 * refreshes every 3 min (feed windows / deadlines pass without any event).
 */

import type { QueryClient } from '@tanstack/react-query';

import type { ParentDashboardResponse } from '@/api/client';
import type { WebSocketStatus } from '@/store/appStore';
import type { PetUpdatedBroadcast } from '@/types';

/** Poll interval while not every pet channel is subscribed (polling fallback). */
export const PARENT_POLL_MS = 30_000;

/** Background refresh while live: deadlines pass without events (review M3). */
export const PARENT_LIVE_REFRESH_MS = 180_000;

/** At most one refetch per this interval for plain decay ticks (`metric_changed`). */
export const TICK_REFETCH_THROTTLE_MS = 60_000;

export const parentDashboardKey = ['parent', 'dashboard'] as const;
export const parentActivitiesKey = (petId: number) => ['parent', 'activities', petId] as const;
export const childReportKey = (childId: number, days: number) => ['parent', 'childReport', childId, days] as const;

/** 30 s polling unless every pet channel is subscribed; then a slow 3-min refresh. */
export function livePollInterval(wsStatus: WebSocketStatus): number {
  return wsStatus === 'connected' ? PARENT_LIVE_REFRESH_MS : PARENT_POLL_MS;
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
    // Freshly signed media (M4-05) — e.g. the reference image appears on `reference_image_ready`.
    media: event.media ?? pet.media,
    // M5-R02: bladder clock / open messes — an accident arrives with a plain decay tick.
    behaviour: event.behaviour ?? pet.behaviour,
    // M5-R03: training progress / today's session / a session running.
    training: event.training ?? pet.training,
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

/**
 * A handler with the tick throttle: every event is patched (and non-tick events
 * refetch as in `applyParentBroadcast`); decay ticks refetch the dashboard at most
 * once per `TICK_REFETCH_THROTTLE_MS` — a pet ticks ~200× a day.
 */
export function createParentBroadcastHandler(
  queryClient: QueryClient,
  now: () => number = Date.now,
): (event: PetUpdatedBroadcast) => void {
  let lastTickRefetch = Number.NEGATIVE_INFINITY;
  return (event) => {
    applyParentBroadcast(queryClient, event);
    if (needsRefetch(event)) {
      lastTickRefetch = now(); // a full refetch just happened anyway
      return;
    }
    const t = now();
    if (t - lastTickRefetch >= TICK_REFETCH_THROTTLE_MS) {
      lastTickRefetch = t;
      void queryClient.invalidateQueries({ queryKey: parentDashboardKey });
    }
  };
}
