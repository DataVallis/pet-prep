/**
 * Core type definitions for the PetPrep mobile app.
 * These mirror the backend Eloquent models and broadcast payloads.
 */

import type { components, operations } from '@/api/schema';

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
  /**
   * Trait name → value. v1 pets: color_scheme / eye_color / fur_texture / markings;
   * DNA v2 (M4-08): size, build, coat_length, coat_color, coat_pattern, markings,
   * ear_carriage, eye_color, tail — keys vary by version and breed.
   */
  visual_traits: Record<string, string>;
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
  /** Parent's pause (raw model column; M1-16 derives the lock from it on restore). */
  is_hard_stopped?: boolean;
  certificate_eligible: boolean;
}

/**
 * `pet.updated` on `private-pet.{id}` (backend PetUpdated::payloadFor,
 * ARCHITECTURE §4). Pet state only — no child PII, no user id (M1-08).
 */
/**
 * AI media of a pet (M4-03 / M4-05) — same shape in GET /api/child/pet, the
 * parent dashboard, pairing and `pet.updated`. URLs are signed and expire at
 * `expires_at` (60–90 min); the server re-issues them with every state.
 */
export type PetMedia = components['schemas']['PairedPetResource']['media'];

/** `behaviour` of `pet.updated` / the parent dashboard as generated (M5-R02). */
export type PetBehaviourRaw = NonNullable<
  operations['parentDashboard.dashboard']['responses'][200]['content']['application/json']['family']
>['pets'][number]['behaviour'];

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
  /** Signed URL of the video for pet_state (fallback idle), our storage — M4-05. */
  current_video_url: string | null;
  media_status?: MediaStatus;
  /** Signed URL of our stored reference image — M4-05. */
  reference_image_url: string | null;
  /** AI media (M4-05); missing in broadcasts from servers before M4-05. */
  media?: PetMedia;
  /**
   * M5-R02 behaviour (bladder clock, open messes, scene) without the child's `can_*`
   * flags; missing in broadcasts from servers before M5-R02. Read via `readPetBehaviour`.
   */
  behaviour?: PetBehaviourRaw;
  event_type: string | null;
  updated_at: string | null;
  /** When the server emitted this snapshot (ms precision); newer wins. */
  emitted_at: string;
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
