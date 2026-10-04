/**
 * Shared test fixtures (not shipped: only imported from __tests__).
 */

import type { ChildPetState } from '@/api/client';
import type { FamilyChild, FamilyPet } from '@/modules/family/family';
import type { Pet } from '@/types';

export function makePet(overrides: Partial<Pet> = {}): Pet {
  return {
    id: 7,
    user_id: 2,
    breed_type: 'mutt',
    pet_dna: null,
    current_video_url: null,
    hunger_level: 90,
    thirst_level: 80,
    energy_level: 70,
    hygiene_level: 60,
    daily_step_count: 1200,
    born_at: '2026-10-01T08:00:00Z',
    is_active: true,
    pet_state: 'idle',
    illness_until: null,
    escalation_level: 0,
    is_game_over: false,
    certificate_eligible: false,
    ...overrides,
  };
}

/** Child pet state as returned by `GET /api/child/pet` and in action responses (M1-07). */
export function makeChildState(
  petOverrides: Partial<ChildPetState['pet']> = {},
): ChildPetState {
  return {
    pet: {
      id: 7,
      breed_type: 'mutt',
      born_at: '2026-10-04T09:00:00+00:00',
      awaiting_contract: false,
      caretakers_count: 1,
      virtual_age_months: 0,
      hunger_level: 100,
      thirst_level: 100,
      energy_level: 100,
      hygiene_level: 100,
      pet_state: 'idle',
      escalation_level: 0,
      needs_cleaning: false,
      is_active: true,
      is_hard_stopped: false,
      is_ill: false,
      illness_until: null,
      is_game_over: false,
      certificate_eligible: false,
      current_video_url: null,
      media_status: 'disabled',
      reference_image_url: null,
      ...petOverrides,
    },
    lock: { is_locked: false, reason: null, until: null },
    timezone: 'Europe/Ljubljana',
    server_time: '2026-10-04T09:00:00+00:00',
    feeding: {
      windows: [],
      current_window: null,
      fed_in_current_window: false,
      can_feed: 'false',
      next_feed_window: null,
      last_fed_at: '',
    },
    water: {
      times_per_day: 0,
      min_gap_minutes: 0,
      used_today: 0,
      remaining_today: 0,
      last_watered_at: '',
      can_water: 'false',
      next_allowed_at: '',
    },
    steps: { steps_today: 0, my_steps_today: 0, goal: 5000, energy_level: 100 },
    contract: { signed: true, signed_at: '2026-10-04T09:00:00+00:00' },
  };
}

/** A child of `family.children` in `GET /api/parent/dashboard` (M2-01 / M2-02). */
export function makeFamilyChild(overrides: Partial<FamilyChild> = {}): FamilyChild {
  return {
    id: 5,
    name: 'Maja',
    birth_year: 2016,
    login: 'pin',
    devices: 0,
    pet_id: null,
    contract_signed: false,
    stats: { days: 7, fed: 0, watered: 0, cleaned: 0, walk_goals: 0, actions_total: 0, steps: 0, active_step_days: 0 },
    ...overrides,
  };
}

/** A pet of `family.pets`. */
export function makeFamilyPet(overrides: Partial<FamilyPet> = {}): FamilyPet {
  return {
    id: 7,
    breed_type: 'mutt',
    born_at: '2026-10-01T08:00:00+00:00',
    awaiting_contract: false,
    is_active: true,
    is_game_over: false,
    is_hard_stopped: false,
    is_ill: false,
    traffic_light: 'green',
    caretakers: [],
    ...overrides,
  };
}

/** `GET /api/parent/dashboard` for a family without an active legacy pet. */
export function makeFamilyDashboard(children: FamilyChild[], pets: FamilyPet[] = []) {
  return {
    message: children.length > 0 ? 'No active pet session found.' : 'No child profile paired yet.',
    timezone: 'Europe/Ljubljana',
    pet: null,
    traffic_light: 'green',
    quiet_hours: null,
    recent_activities: [],
    family: { id: 1, timezone: 'Europe/Ljubljana', parents: [{ id: 1, name: 'Starš', is_me: true }], children, pets },
  };
}
