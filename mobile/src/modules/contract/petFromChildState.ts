/**
 * Map the server's child pet state (`ChildPetStateResource`) onto the session
 * store's `Pet` (M1-07b). The state is authoritative for everything it carries;
 * fields it lacks (owner id, Pet DNA) come from what the app already knows.
 */

import type { ChildPetState } from '@/api/client';
import { readBreed, readSpecies } from '@/modules/species/species';
import type { Pet, PetDna, PetState } from '@/types';

const PET_STATES: readonly PetState[] = ['idle', 'sleeping', 'low_energy', 'hungry', 'sick', 'playing'];

function isPetState(value: string): value is PetState {
  return (PET_STATES as readonly string[]).includes(value);
}

export interface PetContext {
  /** The child's user id (owner of the pet). */
  userId: number;
  /** Pet DNA from the pairing response or the restored pet (the state doesn't carry it). */
  petDna: PetDna | null;
}

export function petFromChildState(state: ChildPetState, context: PetContext): Pet {
  const p = state.pet;
  // M5-R06-02: an unknown breed stays `unknown` (never the mutt).
  const breed = readBreed(p.breed_type);
  return {
    id: p.id,
    user_id: context.userId,
    breed_type: breed,
    species: readSpecies(p.species, breed),
    pet_dna: context.petDna,
    current_video_url: p.current_video_url,
    hunger_level: p.hunger_level,
    thirst_level: p.thirst_level,
    energy_level: p.energy_level,
    hygiene_level: p.hygiene_level,
    daily_step_count: state.steps.steps_today,
    born_at: p.born_at,
    awaiting_contract: p.awaiting_contract,
    is_active: p.is_active,
    pet_state: isPetState(p.pet_state) ? p.pet_state : 'idle',
    illness_until: p.illness_until,
    escalation_level: p.escalation_level,
    is_game_over: p.is_game_over,
    certificate_eligible: p.certificate_eligible,
  };
}
