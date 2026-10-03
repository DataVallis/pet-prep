/**
 * Core type definitions for the PetPrep mobile app.
 * These mirror the backend Eloquent models and broadcast payloads.
 */

export type BreedType = 'mutt' | 'border_collie';

export type PetState = 'idle' | 'sleeping' | 'low_energy' | 'hungry' | 'sick' | 'playing';

export type UserRole = 'parent' | 'child';

/** Lifecycle of the pet's AI reference image (backend `pets.media_status`). */
export type MediaStatus = 'disabled' | 'pending' | 'ready' | 'failed';

export type ActivityType =
  | 'fed_pet'
  | 'watered_pet'
  | 'walked_pet'
  | 'cleaned_poop'
  | 'ignored_warning';

/** Pet DNA visual identity payload stored in pet_dna JSONB column. */
export interface PetDna {
  seed: number;
  visual_traits: {
    color_scheme: string;
    eye_color: string;
    fur_texture: string;
    markings: string;
  };
  prompt_anchor: string;
  reference_image_url: string | null;
}

/** Full Pet model as returned by the API. */
export interface Pet {
  id: number;
  user_id: number;
  breed_type: BreedType;
  pet_dna: PetDna | null;
  current_video_url: string | null;
  hunger_level: number;
  thirst_level: number;
  energy_level: number;
  hygiene_level: number;
  daily_step_count: number;
  born_at: string;
  is_active: boolean;
  pet_state: PetState;
  illness_until: string | null;
  escalation_level: number;
  is_game_over: boolean;
  certificate_eligible: boolean;
}

/** Pet data as received from WebSocket broadcast (pet.updated channel). */
export interface PetUpdatedBroadcast {
  pet_id: number;
  user_id: number;
  breed_type: BreedType;
  hunger_level: number;
  thirst_level: number;
  energy_level: number;
  hygiene_level: number;
  is_active: boolean;
  pet_state: PetState;
  escalation_level: number;
  is_ill: boolean;
  is_game_over: boolean;
  virtual_age_months: number;
  current_video_url: string | null;
  media_status?: MediaStatus;
  reference_image_url: string | null;
  event_type: string | null;
  updated_at: string | null;
}

/** Response from POST /api/child/pair. */
export interface PairingResponse {
  message: string;
  parent_id: number;
  pet: {
    id: number;
    breed_type: BreedType;
    hunger_level: number;
    thirst_level: number;
    energy_level: number;
    hygiene_level: number;
    born_at: string;
    is_active: boolean;
    pet_dna: PetDna | null;
    current_video_url: string | null;
    media_status?: MediaStatus;
  };
}

/** Response from POST /api/parent/generate-pin. */
export interface GeneratePinResponse {
  pin: string;
  expires_at: string;
  expires_in_minutes: number;
}

/** Quiet hours configuration. */
export interface QuietHours {
  id: number;
  school_start: string | null;
  school_end: string | null;
  bedtime_start: string | null;
  bedtime_end: string | null;
  is_active: boolean;
}
