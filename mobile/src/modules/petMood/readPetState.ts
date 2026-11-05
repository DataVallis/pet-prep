import type { PetState } from '@/types';

const PET_STATE_VALUES: readonly PetState[] = ['idle', 'sleeping', 'low_energy', 'hungry', 'sick', 'playing'];

/**
 * A loose `pet_state` from a payload (the parent dashboard's `family.pets[].pet_state`,
 * since 2026-11-05). Null when missing / unknown — a server from before that.
 */
export function readPetState(value: unknown): PetState | null {
  return typeof value === 'string' && (PET_STATE_VALUES as readonly string[]).includes(value) ? (value as PetState) : null;
}
