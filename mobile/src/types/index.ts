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
  /** null until the child signs the contract (unborn pet, M1-07b). */
  born_at: string | null;
  /**
   * true while the pet waits for the contract (M1-07b). Present in child state and
   * broadcasts; the raw pet from `/api/login` / `/api/user` omits it → use `born_at`.
   */
  awaiting_contract?: boolean;
  is_active: boolean;
  pet_state: PetState;
  illness_until: string | null;
  escalation_level: number;
  is_game_over: boolean;
  certificate_eligible: boolean;
}

/** Pet data as received from WebSocket broadcast (pet.updated channel). */
/**
 * `pet.updated` on `private-pet.{id}` (backend PetUpdated::payloadFor,
 * ARCHITECTURE §4). Pet state only — no child PII, no user id (M1-08).
 */
export interface PetUpdatedBroadcast {
  pet_id: number;
  breed_type: BreedType;
  hunger_level: number;
  thirst_level: number;
  energy_level: number;
  hygiene_level: number;
  is_active: boolean;
  pet_state: PetState;
  escalation_level: number;
  is_ill: boolean;
  illness_until: string | null;
  is_game_over: boolean;
  is_hard_stopped: boolean;
  /** false once the contract is signed (birth arrives as event_type `signed_contract`, M1-07b). */
  awaiting_contract?: boolean;
  /** null until the contract is signed (M1-07b). */
  born_at?: string | null;
  virtual_age_months: number;
  current_video_url: string | null;
  media_status?: MediaStatus;
  reference_image_url: string | null;
  event_type: string | null;
  updated_at: string | null;
  /** When the server emitted this snapshot (ms precision); newer wins. */
  emitted_at: string;
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
    /** null: the pet is unborn until POST /api/child/contract (M1-07b). */
    born_at: string | null;
    /** true until the contract is signed — every other child action → 423 contract_required. */
    awaiting_contract: boolean;
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
