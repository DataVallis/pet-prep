/**
 * The child's pet state as the app keeps it in the TanStack cache (M1-13).
 *
 * Source: `GET /api/child/pet` and the `state` key of every child action response
 * (`ChildPetStateResource`). The generated schema types several of its fields loosely
 * (`can_feed: string`, water counters `string | 0`, `windows: unknown[]`), so the raw
 * object is normalised once here and the UI only sees proper types.
 *
 * Ordering: realtime snapshots (`PetUpdated`, `emitted_at` in ms) and HTTP snapshots
 * (`server_time`, whole seconds) can arrive out of order. The view remembers both
 * clocks; `applyBroadcast` drops an event older than what is shown, `mergePolledState`
 * keeps a newer broadcast over an older poll.
 */

import type { ChildPetState } from '@/api/client';
import type { BreedType, PetState, PetUpdatedBroadcast } from '@/types';
import type { LockState } from '@/store/appStore';
import { familyCalendar } from '@/modules/childPet/familyTime';
import { normalizePetMedia, type PetMediaInfo } from '@/modules/petMedia/petMedia';
import { readPetProfile, type PetProfileInfo } from '@/modules/petProfile/petProfile';
import {
  afterClean,
  afterResolveChewing,
  afterTakeOut,
  hasOpenChewing,
  readChildBehaviour,
  readPetBehaviour,
  type ChildBehaviour,
} from '@/modules/behaviour/behaviour';

export type LockReason = 'game_over' | 'inactive' | 'hard_stopped' | 'contract_required' | 'ill';

export interface TimeWindow {
  start: string;
  end: string;
}

export interface ChildPetView {
  pet: {
    id: number;
    breed_type: BreedType;
    born_at: string | null;
    /** This child must sign before acting (per child — M2-01). */
    awaiting_contract: boolean;
    caretakers_count: number;
    virtual_age_months: number;
    hunger_level: number;
    thirst_level: number;
    energy_level: number;
    hygiene_level: number;
    pet_state: PetState;
    escalation_level: number;
    needs_cleaning: boolean;
    is_active: boolean;
    is_hard_stopped: boolean;
    is_ill: boolean;
    illness_until: string | null;
    is_game_over: boolean;
    certificate_eligible: boolean;
    current_video_url: string | null;
    reference_image_url: string | null;
    /** AI media (M4-03 / M4-05): state videos + reference image, signed URLs. */
    media: PetMediaInfo;
    /** M5-R01 profile (stage, age, origin, next stage); null for a legacy pet. */
    profile: PetProfileInfo | null;
  };
  lock: { is_locked: boolean; reason: LockReason | null; until: string | null };
  /** IANA zone of the family (all wall-clock rules). */
  timezone: string;
  /** Server clock of the last HTTP snapshot (ISO with family offset). */
  server_time: string;
  feeding: {
    /** Local "HH:MM" windows of the breed. */
    windows: TimeWindow[];
    current_window: TimeWindow | null;
    fed_in_current_window: boolean;
    can_feed: boolean;
    /** The current window while unused, otherwise the next one (ISO instants). */
    next_feed_window: TimeWindow | null;
    last_fed_at: string | null;
  };
  water: {
    times_per_day: number;
    min_gap_minutes: number;
    used_today: number;
    remaining_today: number;
    last_watered_at: string | null;
    can_water: boolean;
    next_allowed_at: string | null;
  };
  steps: { steps_today: number; my_steps_today: number; goal: number; energy_level: number };
  contract: { signed: boolean; signed_at: string | null };
  /**
   * M5-R02 behaviour events: puppy bladder clock, open messes, behaviour scene and what
   * the child may do. `EMPTY_BEHAVIOUR` for a legacy pet / an older server.
   */
  behaviour: ChildBehaviour;
  /** ms of `server_time` (whole seconds). */
  snapshotAtMs: number;
  /** ms of the newest applied broadcast `emitted_at`; 0 if none. */
  lastEmittedMs: number;
  /**
   * Server clock − device clock when this HTTP snapshot arrived (ms, ±1 s because
   * `server_time` has whole seconds). Time-based refreshes compare in server time.
   */
  clockSkewMs: number;
}

const BREEDS: readonly BreedType[] = ['mutt', 'border_collie'];
const PET_STATES: readonly PetState[] = ['idle', 'sleeping', 'low_energy', 'hungry', 'sick', 'playing'];
const LOCK_REASONS: readonly LockReason[] = ['game_over', 'inactive', 'hard_stopped', 'contract_required', 'ill'];

function bool(value: unknown): boolean {
  return value === true || value === 'true' || value === 1 || value === '1';
}

function num(value: unknown): number {
  const n = typeof value === 'number' ? value : Number(value);
  return Number.isFinite(n) ? n : 0;
}

function isoOrNull(value: unknown): string | null {
  return typeof value === 'string' && value.length > 0 ? value : null;
}

function windowOrNull(value: unknown): TimeWindow | null {
  if (typeof value !== 'object' || value === null) return null;
  const { start, end } = value as Record<string, unknown>;
  return typeof start === 'string' && typeof end === 'string' ? { start, end } : null;
}

function lockReason(value: unknown): LockReason | null {
  return typeof value === 'string' && (LOCK_REASONS as readonly string[]).includes(value)
    ? (value as LockReason)
    : null;
}

function breed(value: string): BreedType {
  return (BREEDS as readonly string[]).includes(value) ? (value as BreedType) : 'mutt';
}

function petState(value: string): PetState {
  return (PET_STATES as readonly string[]).includes(value) ? (value as PetState) : 'idle';
}

function msOf(iso: string | null): number {
  if (!iso) return 0;
  const ms = Date.parse(iso);
  return Number.isNaN(ms) ? 0 : ms;
}

/**
 * Normalise the server's child state (GET or action `state`). `receivedAtMs` = device
 * clock when it arrived (for the clock skew).
 */
export function normalizeChildState(raw: ChildPetState, lastEmittedMs = 0, receivedAtMs = Date.now()): ChildPetView {
  const p = raw.pet;
  const windows = Array.isArray(raw.feeding.windows)
    ? raw.feeding.windows.map(windowOrNull).filter((w): w is TimeWindow => w !== null)
    : [];

  return {
    pet: {
      id: p.id,
      breed_type: breed(p.breed_type),
      born_at: p.born_at,
      awaiting_contract: bool(p.awaiting_contract),
      caretakers_count: num(p.caretakers_count),
      virtual_age_months: num(p.virtual_age_months),
      hunger_level: num(p.hunger_level),
      thirst_level: num(p.thirst_level),
      energy_level: num(p.energy_level),
      hygiene_level: num(p.hygiene_level),
      pet_state: petState(p.pet_state),
      escalation_level: num(p.escalation_level),
      needs_cleaning: bool(p.needs_cleaning),
      is_active: bool(p.is_active),
      is_hard_stopped: bool(p.is_hard_stopped),
      is_ill: bool(p.is_ill),
      illness_until: isoOrNull(p.illness_until),
      is_game_over: bool(p.is_game_over),
      certificate_eligible: bool(p.certificate_eligible),
      current_video_url: isoOrNull(p.current_video_url),
      reference_image_url: isoOrNull(p.reference_image_url),
      media: normalizePetMedia(p.media, {
        current_video_url: p.current_video_url,
        reference_image_url: p.reference_image_url,
        media_status: p.media_status,
      }),
      profile: readPetProfile(p.profile),
    },
    lock: {
      is_locked: bool(raw.lock.is_locked),
      reason: lockReason(raw.lock.reason),
      until: isoOrNull(raw.lock.until),
    },
    timezone: raw.timezone,
    server_time: raw.server_time,
    feeding: {
      windows,
      current_window: windowOrNull(raw.feeding.current_window),
      fed_in_current_window: bool(raw.feeding.fed_in_current_window),
      can_feed: bool(raw.feeding.can_feed),
      next_feed_window: windowOrNull(raw.feeding.next_feed_window),
      last_fed_at: isoOrNull(raw.feeding.last_fed_at),
    },
    water: {
      times_per_day: num(raw.water.times_per_day),
      min_gap_minutes: num(raw.water.min_gap_minutes),
      used_today: num(raw.water.used_today),
      remaining_today: num(raw.water.remaining_today),
      last_watered_at: isoOrNull(raw.water.last_watered_at),
      can_water: bool(raw.water.can_water),
      next_allowed_at: isoOrNull(raw.water.next_allowed_at),
    },
    steps: {
      steps_today: num(raw.steps.steps_today),
      my_steps_today: num(raw.steps.my_steps_today),
      goal: num(raw.steps.goal),
      energy_level: num(raw.steps.energy_level),
    },
    contract: { signed: bool(raw.contract.signed), signed_at: isoOrNull(raw.contract.signed_at) },
    // Older servers send no `behaviour` (typed as required by the current schema).
    behaviour: readChildBehaviour((raw as { behaviour?: unknown }).behaviour),
    snapshotAtMs: msOf(raw.server_time),
    lastEmittedMs,
    clockSkewMs: msOf(raw.server_time) > 0 ? msOf(raw.server_time) - receivedAtMs : 0,
  };
}

/**
 * Server lock priority (game over › inactive › hard stop › contract › illness) from pet
 * flags. A still-running illness keeps the server's `until` (family offset) over the
 * broadcast's UTC `illness_until`.
 */
export function lockFromFlags(pet: ChildPetView['pet'], previous?: ChildPetView['lock']): ChildPetView['lock'] {
  let reason: LockReason | null = null;
  if (pet.is_game_over) reason = 'game_over';
  else if (!pet.is_active) reason = 'inactive';
  else if (pet.is_hard_stopped) reason = 'hard_stopped';
  else if (pet.awaiting_contract) reason = 'contract_required';
  else if (pet.is_ill) reason = 'ill';
  let until: string | null = null;
  if (reason === 'ill') {
    const sameEnd =
      previous?.reason === 'ill' &&
      previous.until !== null &&
      pet.illness_until !== null &&
      Date.parse(previous.until) === Date.parse(pet.illness_until);
    until = sameEnd ? previous.until : pet.illness_until;
  }
  return { is_locked: reason !== null, reason, until };
}

/**
 * Incoming cache value vs. what the cache shows (TanStack `structuralSharing`, which
 * runs for fetches *and* `setQueryData`). Only a value that hasn't seen the newest
 * broadcast (a poll / refetch — `lastEmittedMs` behind) is checked: `server_time` has
 * whole seconds, `emitted_at` ms, so it is dropped only when its snapshot is from an
 * earlier second than that broadcast. Broadcast, optimistic and action-response writes
 * carry the clock forward and always pass.
 */
export function mergePolledState(previous: ChildPetView | undefined, next: ChildPetView): ChildPetView {
  if (!previous) return next;
  // An HTTP snapshot older than the one shown (slow poll overtaken by a newer answer).
  if (next.snapshotAtMs < previous.snapshotAtMs) return previous;
  if (next.lastEmittedMs >= previous.lastEmittedMs) return next;
  const lastEmittedSecond = Math.floor(previous.lastEmittedMs / 1000) * 1000;
  if (next.snapshotAtMs < lastEmittedSecond) return previous;
  return { ...next, lastEmittedMs: previous.lastEmittedMs };
}

export interface BroadcastResult {
  view: ChildPetView;
  /**
   * Fetch the full state afterwards: a broadcast has no feed-window / water data and
   * only a pet-level contract flag, so after anything other than a plain metric tick
   * (an action by a sibling, hard stop, illness, birth …) or a lock change the
   * cached `can_*` / hints would be stale.
   */
  refetch: boolean;
}

/**
 * Apply a `PetUpdated` snapshot to the cached view. Returns null when the event is for
 * another pet or older than what the view already shows (out-of-order delivery).
 */
export function applyBroadcast(view: ChildPetView, b: PetUpdatedBroadcast): BroadcastResult | null {
  if (b.pet_id !== view.pet.id) return null;
  const emittedMs = msOf(b.emitted_at);
  if (emittedMs === 0) return null;
  if (emittedMs < view.lastEmittedMs || emittedMs < view.snapshotAtMs) return null;

  const pet: ChildPetView['pet'] = {
    ...view.pet,
    breed_type: breed(b.breed_type),
    hunger_level: b.hunger_level,
    thirst_level: b.thirst_level,
    energy_level: b.energy_level,
    hygiene_level: b.hygiene_level,
    pet_state: petState(b.pet_state),
    escalation_level: b.escalation_level,
    needs_cleaning: b.hygiene_level <= 0,
    is_active: b.is_active,
    is_hard_stopped: b.is_hard_stopped,
    is_ill: b.is_ill,
    illness_until: b.illness_until,
    is_game_over: b.is_game_over,
    virtual_age_months: b.virtual_age_months,
    born_at: b.born_at !== undefined ? b.born_at : view.pet.born_at,
    // The broadcast flag is pet-level: a child who joined a born pet still has to sign
    // even when it says false. Only "the pet is unborn" (true) is safe to take over.
    awaiting_contract: view.pet.awaiting_contract || b.awaiting_contract === true,
    current_video_url: b.current_video_url ?? view.pet.current_video_url,
    reference_image_url: b.reference_image_url ?? view.pet.reference_image_url,
    // Every broadcast since M4-05 carries the full `media` (freshly signed); an older
    // server's event without it keeps what the view has.
    media: b.media ? normalizePetMedia(b.media) : view.pet.media,
  };
  const lock = lockFromFlags(pet, view.lock);
  const blocked = lock.is_locked || pet.needs_cleaning;
  const behaviour = broadcastBehaviour(view.behaviour, b.behaviour, lock.is_locked);

  const next: ChildPetView = {
    ...view,
    pet,
    lock,
    feeding: { ...view.feeding, can_feed: view.feeding.can_feed && !blocked },
    water: { ...view.water, can_water: view.water.can_water && !blocked },
    steps: { ...view.steps, energy_level: b.energy_level },
    behaviour,
    lastEmittedMs: emittedMs,
  };

  const refetch =
    lock.reason !== view.lock.reason ||
    pet.needs_cleaning !== view.pet.needs_cleaning ||
    // Energy only drops at the family midnight: a new step day → steps / windows / water reset.
    b.energy_level < view.pet.energy_level ||
    (b.event_type !== null && b.event_type !== 'metric_changed');

  return { view: next, refetch };
}

/**
 * `behaviour` after a `PetUpdated` (M5-R02). The broadcast carries the clock, the open
 * messes and the scene but not the child's `can_*` flags; they follow from the server's
 * own rules (a puppy with a clock may go out, an open chewing may be tidied up — never
 * while locked). A broadcast without `behaviour` (older server) keeps what the view has.
 */
export function broadcastBehaviour(current: ChildBehaviour, raw: unknown, locked: boolean): ChildBehaviour {
  if (raw === undefined || raw === null) {
    return locked ? { ...current, can_take_out: false, can_resolve_chewing: false } : current;
  }
  const next = readPetBehaviour(raw);
  return {
    ...next,
    can_take_out: next.take_out !== null && !locked,
    can_resolve_chewing: hasOpenChewing(next) && !locked,
  };
}

export type CareAction = 'feed' | 'water' | 'clean' | 'take_out' | 'resolve_chewing';

/**
 * What the HUD shows while the request is in flight (the response replaces it).
 * `nowMs` = server time now (device clock + skew), only used by "Pelji ven".
 */
export function optimisticView(view: ChildPetView, action: CareAction, nowMs: number = Date.now() + view.clockSkewMs): ChildPetView {
  switch (action) {
    case 'feed':
      return {
        ...view,
        pet: { ...view.pet, hunger_level: 100 },
        feeding: { ...view.feeding, can_feed: false, fed_in_current_window: true },
      };
    case 'water':
      return { ...view, pet: { ...view.pet, thirst_level: 100 }, water: { ...view.water, can_water: false } };
    case 'clean': {
      // M5-R02: a chewed slipper is not scrubbed away — hygiene stays 0 until it's tidied up.
      const behaviour = afterClean(view.behaviour);
      if (hasOpenChewing(behaviour)) return { ...view, behaviour };
      return { ...view, pet: { ...view.pet, hygiene_level: 100, needs_cleaning: false }, behaviour };
    }
    case 'resolve_chewing': {
      const behaviour = afterResolveChewing(view.behaviour);
      // Something else still open (poop / accident) → still dirty.
      if (behaviour.active_events.length > 0 || view.behaviour.active_events.length === 0) return { ...view, behaviour };
      return { ...view, pet: { ...view.pet, hygiene_level: 100, needs_cleaning: false }, behaviour };
    }
    case 'take_out':
      return { ...view, behaviour: afterTakeOut(view.behaviour, nowMs) };
  }
}

/**
 * Undo an optimistic action that failed without a server answer (offline / 5xx / 429),
 * on top of what the cache shows NOW — a broadcast may have landed meanwhile. Only the
 * action's metric and flags are restored; the metric is kept when a newer broadcast
 * already brought the server's value. The caller refetches afterwards.
 */
export function revertOptimistic(current: ChildPetView, previous: ChildPetView, action: CareAction): ChildPetView {
  const broadcastSince = current.lastEmittedMs > previous.lastEmittedMs;
  switch (action) {
    case 'feed':
      return {
        ...current,
        pet: { ...current.pet, hunger_level: broadcastSince ? current.pet.hunger_level : previous.pet.hunger_level },
        feeding: {
          ...current.feeding,
          can_feed: previous.feeding.can_feed && !current.lock.is_locked && !current.pet.needs_cleaning,
          fed_in_current_window: previous.feeding.fed_in_current_window,
        },
      };
    case 'water':
      return {
        ...current,
        pet: { ...current.pet, thirst_level: broadcastSince ? current.pet.thirst_level : previous.pet.thirst_level },
        water: {
          ...current.water,
          can_water: previous.water.can_water && !current.lock.is_locked && !current.pet.needs_cleaning,
        },
      };
    case 'clean':
    case 'resolve_chewing': {
      const hygiene = broadcastSince ? current.pet.hygiene_level : previous.pet.hygiene_level;
      return {
        ...current,
        pet: { ...current.pet, hygiene_level: hygiene, needs_cleaning: hygiene <= 0 },
        behaviour: broadcastSince ? current.behaviour : previous.behaviour,
      };
    }
    case 'take_out':
      return { ...current, behaviour: broadcastSince ? current.behaviour : previous.behaviour };
  }
}

/** Retry interval while a boundary has passed but the server still says "not yet" (N2). */
export const BOUNDARY_RETRY_MS = 30_000;

/**
 * How long until the cached state goes stale by the clock alone (M3): a feed window
 * opens or closes, the water gap ends, a vet visit ends, or the family midnight resets
 * steps / water.
 * Boundaries are server instants, so they are compared in SERVER time (device clock +
 * `clockSkewMs`, N2) — a fast or slow phone clock doesn't shift the refresh. When a
 * boundary is already past but the state still blocks the action (a refetch raced the
 * boundary, or the clocks disagree by more than the margin), retry every 30 s.
 * Returns ms from now, or null.
 */
export function nextRefreshDelay(view: ChildPetView, deviceNowMs: number): number | null {
  const serverNow = deviceNowMs + view.clockSkewMs;
  const blocked = view.lock.is_locked || view.pet.needs_cleaning;
  const at = (iso: string | null | undefined): number => (iso ? Date.parse(iso) : Number.NaN);

  const nextWindow = at(view.feeding.next_feed_window?.start);
  const windowEnd = at(view.feeding.current_window?.end);
  const water = at(view.water.next_allowed_at);
  // M5-R02: the puppy's accident is due — fetch the state that shows it (the server
  // records it on its next tick; until the clock moves on, retry like other boundaries).
  // A lock freezes the clock.
  const accident = view.lock.is_locked ? Number.NaN : at(view.behaviour.take_out?.next_due_at);
  // Back from the vet: the lock ends at `lock.until` (hotfix 2026-10-06 — don't depend on
  // the recovery broadcast alone; repeated refetches are bounded by the backoff in useChildPet).
  const illnessEnd = view.lock.reason === 'ill' ? at(view.lock.until) : Number.NaN;
  const future = [nextWindow, windowEnd, water, accident, illnessEnd].filter((ms) => Number.isFinite(ms) && ms > serverNow);
  future.push(familyCalendar(view.timezone, view.server_time).nextMidnight(serverNow));
  let delay = Math.min(...future) - serverNow;

  const stale =
    (Number.isFinite(nextWindow) && nextWindow <= serverNow && !view.feeding.can_feed && !blocked) ||
    (Number.isFinite(windowEnd) && windowEnd <= serverNow) ||
    (Number.isFinite(water) && water <= serverNow && !view.water.can_water && !blocked) ||
    (Number.isFinite(accident) && accident <= serverNow) ||
    (Number.isFinite(illnessEnd) && illnessEnd <= serverNow);
  if (stale) delay = Math.min(delay, BOUNDARY_RETRY_MS);

  return Number.isFinite(delay) ? Math.max(0, delay) : null;
}

/** Session lock overlay state (M1-16) from the server's per-child lock. */
export function lockStateFromView(view: ChildPetView): LockState {
  switch (view.lock.reason) {
    case 'game_over':
      return 'game_over';
    case 'inactive':
      return 'inactive';
    case 'hard_stopped':
      return 'hard_stop';
    case 'ill':
      return 'illness';
    default:
      // contract_required is not an overlay: AppNavigator shows ContractScreen.
      return 'none';
  }
}
