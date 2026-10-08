import { petFromChildState } from '@/modules/contract/petFromChildState';
import { makeChildState } from '@/test-utils/fixtures';
import type { PetDna } from '@/types';

const dna: PetDna = {
  seed: 1,
  visual_traits: { color_scheme: 'brown', eye_color: 'amber', fur_texture: 'short', markings: 'none' },
  prompt_anchor: 'a brown mutt',
  reference_image_url: null,
};

describe('petFromChildState', () => {
  it('takes born_at, awaiting_contract, metrics and steps from the server state', () => {
    const state = makeChildState({ hunger_level: 99, pet_state: 'sleeping' });
    state.steps.steps_today = 321;

    expect(petFromChildState(state, { userId: 2, petDna: dna })).toEqual({
      id: 7,
      user_id: 2,
      breed_type: 'mutt',
      species: 'dog',
      pet_dna: dna,
      current_video_url: null,
      hunger_level: 99,
      thirst_level: 100,
      energy_level: 100,
      hygiene_level: 100,
      daily_step_count: 321,
      born_at: '2026-10-04T09:00:00+00:00',
      awaiting_contract: false,
      is_active: true,
      pet_state: 'sleeping',
      illness_until: null,
      escalation_level: 0,
      is_game_over: false,
      certificate_eligible: false,
    });
  });

  it('falls back for unknown enum values', () => {
    const pet = petFromChildState(makeChildState({ breed_type: 'poodle', pet_state: 'dancing' }), { userId: 2, petDna: null });
    // M5-R06-02: an unknown breed stays unknown (never the mutt).
    expect(pet.breed_type).toBe('unknown');
    expect(pet.pet_state).toBe('idle');
  });
});
