/**
 * Map the server's child pet state (`ChildPetStateResource`) onto the session
 * store's `Pet` (M1-07b). The state is authoritative for everything it carries;
 * fields it lacks (owner id, Pet DNA) come from what the app already knows.
 */

import type { ChildPetState } from '@/api/client';
import type { BreedType, Pet, PetDna, PetState } from '@/types';

const BREEDS: readonly BreedType[] = ['mutt', 'border_collie'];
const PET_STATES: readonly PetState[] = ['idle', 'sleeping', 'low_energy', 'hungry', 'sick', 'playing'];

function isBreed(value: string): value is BreedType {
  return (BREEDS as readonly string[]).includes(value);
}

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
  return {
    id: p.id,
    user_id: context.userId,
    breed_type: isBreed(p.breed_type) ? p.breed_type : 'mutt',
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
