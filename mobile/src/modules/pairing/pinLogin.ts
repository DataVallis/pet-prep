/**
 * Child PIN login (M2-02): `POST /api/child/pin-login` → token in SecureStore →
 * the same `SignInPayload` a parent login or a session restore produces.
 */

import { ApiError, api, getAuthToken, saveAuthToken, type PairedPet, type PinLoginErrorBody, type PinLoginResponse } from '@/api/client';
import { logout } from '@/modules/session/logout';
import type { SignInPayload } from '@/store/appStore';
import type { BreedType, Pet } from '@/types';

export const PIN_LENGTH = 6;

/** Fallback wait when a 429 carries neither Retry-After nor `retry_after`. */
export const DEFAULT_LOCKOUT_SECONDS = 60;

export type PinLoginErrorKind = 'invalid' | 'rate_limited' | 'offline' | 'server';

export interface PinLoginError {
  kind: PinLoginErrorKind;
  /** rate_limited only: seconds until the child may try again. */
  retryAfterSeconds: number | null;
}

function retryAfterFromBody(data: unknown): number | null {
  if (typeof data !== 'object' || data === null || !('retry_after' in data)) return null;
  const value = (data as PinLoginErrorBody).retry_after;
  return typeof value === 'number' && Number.isFinite(value) && value > 0 ? Math.ceil(value) : null;
}

/**
 * Map a pin-login failure to what the child sees. Every 422 (wrong, expired, used,
 * `pin_not_usable`, malformed) is the same "invalid" — no hint which part was wrong.
 */
export function classifyPinLoginError(error: unknown): PinLoginError {
  if (error instanceof ApiError) {
    if (error.status === 429) {
      const seconds = error.retryAfterSeconds ?? retryAfterFromBody(error.data) ?? DEFAULT_LOCKOUT_SECONDS;
      return { kind: 'rate_limited', retryAfterSeconds: Math.max(1, seconds) };
    }
    if (error.status === 422) return { kind: 'invalid', retryAfterSeconds: null };
    return { kind: 'server', retryAfterSeconds: null };
  }
  // fetch() rejects with a TypeError when there is no connection.
  return { kind: 'offline', retryAfterSeconds: null };
}

const BREEDS: readonly BreedType[] = ['mutt', 'border_collie'];

/**
 * Minimal `Pet` from the pin-login payload — used only when `GET /api/user` can't be
 * read right after the login (the full raw pet has escalation, illness, steps …).
 */
export function petFromPairedPet(pet: PairedPet, childId: number): Pet {
  const breed = BREEDS.find((b) => b === pet.breed_type) ?? 'mutt';
  return {
    id: pet.id,
    user_id: childId,
    breed_type: breed,
    pet_dna: null,
    current_video_url: pet.current_video_url,
    hunger_level: pet.hunger_level,
    thirst_level: pet.thirst_level,
    energy_level: pet.energy_level,
    hygiene_level: pet.hygiene_level,
    daily_step_count: 0,
    born_at: pet.born_at,
    awaiting_contract: pet.awaiting_contract,
    is_active: pet.is_active,
    pet_state: 'idle',
    illness_until: null,
    escalation_level: 0,
    is_game_over: pet.is_game_over,
    certificate_eligible: false,
  };
}

/**
 * Build the session from a successful pin-login. The pet comes from `GET /api/user`
 * (full raw pet) when possible; `awaiting_contract` always comes from the pin-login
 * answer — it is per child (a child joining a shared, already born pet must still
 * sign), which the raw pet's `born_at` can't express.
 */
export async function sessionFromPinLogin(response: PinLoginResponse): Promise<SignInPayload> {
  let pet: Pet = petFromPairedPet(response.pet, response.user.id);
  try {
    const user = await api.getUser();
    if (user.pet && user.pet.id === response.pet.id) pet = user.pet;
  } catch {
    // Keep the minimal pet; the session restore on next launch reads the full one.
  }
  return {
    token: response.token,
    user: { id: response.user.id, name: response.user.name, email: null, role: 'child' },
    pet: { ...pet, awaiting_contract: response.awaiting_contract },
    awaitingContract: response.awaiting_contract,
  };
}

/**
 * PIN → token saved in SecureStore (same key as every login) → session payload.
 * A token already stored on this device (an older session) is revoked on the
 * server and cleared first — only after the PIN worked, so a wrong PIN never
 * costs the old session. It never lingers as a second live token.
 */
export async function performPinLogin(pin: string, device: string): Promise<SignInPayload> {
  const response = await api.pinLogin(pin, device);
  const previous = await getAuthToken().catch(() => null);
  if (previous !== null && previous !== response.token) {
    await logout();
  }
  await saveAuthToken(response.token);
  return sessionFromPinLogin(response);
}
