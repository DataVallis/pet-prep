/**
 * Shared test fixtures (not shipped: only imported from __tests__).
 */

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
