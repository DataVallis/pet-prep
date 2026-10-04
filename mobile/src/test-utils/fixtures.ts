/**
 * Shared test fixtures (not shipped: only imported from __tests__).
 */

import type { ChildPetState } from '@/api/client';
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
    steps: { steps_today: 0, goal: 5000, energy_level: 100 },
    contract: { signed: true, signed_at: '2026-10-04T09:00:00+00:00' },
  };
}
