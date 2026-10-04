/**
 * API client for PetPrep backend.
 * Uses fetch with auth token from SecureStore.
 */

import * as SecureStore from 'expo-secure-store';
import { ENV } from '@/config/env';
import type { components, operations } from '@/api/schema';
import type { Pet, QuietHours } from '@/types';

/**
 * Authenticated user as returned (flat, no `data` wrapper) by `GET /api/user`
 * and inside `POST /api/login`. Declared here because the generated schema
 * types `role` as a plain string (backend enum `UserRole`: parent | child).
 */
export type UserRole = 'parent' | 'child';

export interface SessionUser {
  id: number;
  name: string;
  /** null for a PIN-only child profile (M2-02). */
  email: string | null;
  role: UserRole;
}

/** `GET /api/user` response. */
export interface UserResponse extends SessionUser {
  pet: Pet | null;
  /**
   * Child: this child must sign the contract before acting — pet unborn, or the
   * child joined a shared, already born pet and hasn't signed yet (M2-02). Also
   * mirrored in `pet.awaiting_contract`. null for a parent.
   */
  awaiting_contract?: boolean | null;
}

/** `POST /api/login` response. */
export interface LoginResponse {
  token: string;
  user: SessionUser;
  pet: Pet | null;
  /** Same per-child flag as `UserResponse.awaiting_contract`. */
  awaiting_contract?: boolean | null;
}

/** `GET /api/parent/dashboard` 200 response (union: paired / no pet / no child). */
export type ParentDashboardResponse =
  operations['parentDashboard.dashboard']['responses'][200]['content']['application/json'];

/**
 * Child pet state (`ChildPetStateResource`): the body of `GET /api/child/pet` and the
 * `state` key of every child action response (M1-07).
 */
export type ChildPetState =
  operations['childPet.show']['responses'][200]['content']['application/json'];

/** `POST /api/child/contract` body (M1-07b): SVG path data or base64 PNG. */
export type SignContractRequest = components['schemas']['SignContractRequest'];

/**
 * `POST /api/child/contract` 201 body. Declared here because the generated schema
 * types the success response as `string` (Scramble can't infer `actionResponse`).
 */
export interface SignContractResponse {
  status: 'accepted';
  state: ChildPetState;
}

/** Pet payload of `POST /api/child/pin-login` (`PairedPetResource`). */
export type PairedPet = components['schemas']['PairedPetResource'];

/** What a child PIN does (M2-02): first pairing, join a shared pet, or only sign in a new device. */
export type PinLoginMode = 'new_pet' | 'join_pet' | 'relogin';

/**
 * `POST /api/child/pin-login` 200 body. Declared here because the generated schema
 * types `joined_existing` / `family_id` as strings and unions the body with `string`.
 */
export interface PinLoginResponse {
  token: string;
  abilities: string[];
  /** Nickname only — a PIN-only child has no e-mail. */
  user: { id: number; name: string; role: UserRole };
  mode: PinLoginMode;
  joined_existing: boolean;
  family_id: number;
  pet: PairedPet;
  /** This child must sign the contract before acting (unborn pet or joined a shared pet). */
  awaiting_contract: boolean;
}

/**
 * Error body of `POST /api/child/pin-login` (422 / 429). Hand-typed: Scramble only
 * documents the validation shape (HANDOFF debt 5).
 */
export interface PinLoginErrorBody {
  message: string;
  reason?: 'invalid_pin' | 'pin_not_usable' | 'too_many_attempts';
  retry_after?: number;
}

/** `POST /api/parent/children` body / 201 response (M2-02). */
export type CreateChildRequest = components['schemas']['CreateChildRequest'];
export type CreateChildResponse =
  operations['childProfile.store']['responses'][201]['content']['application/json'];

/** `POST /api/parent/generate-pin` body — always with `child_id` (the legacy call is deprecated). */
export interface GenerateChildPinRequest {
  child_id: number;
  /** Join this shared pet of the family; omit for a new pet (or a re-login). */
  pet_id?: number | null;
}

/** `POST /api/parent/generate-pin` 200 body for a request with `child_id`. */
export type ChildPinResponse = Extract<
  operations['pairing.generatePin']['responses'][200]['content']['application/json'],
  { child_id: number }
>;

/** `DELETE /api/parent/children/{child}/tokens` 200 body. */
export type RevokeChildTokensResponse =
  operations['childProfile.revokeTokens']['responses'][200]['content']['application/json'];

/** Response from POST /api/broadcasting/auth (Pusher protocol signature). */
export interface BroadcastAuthResponse {
  auth: string;
  channel_data?: string;
}

/** Exact backend message when the parent has no child profile yet. */
export const NO_CHILD_PAIRED_MESSAGE = 'No child profile paired yet.';

const TOKEN_KEY = 'petprep_auth_token';

/** Save the Sanctum auth token to secure storage. */
export async function saveAuthToken(token: string): Promise<void> {
  await SecureStore.setItemAsync(TOKEN_KEY, token);
}

/** Retrieve the saved auth token. Returns null if not set. */
export async function getAuthToken(): Promise<string | null> {
  return await SecureStore.getItemAsync(TOKEN_KEY);
}

/** Delete the auth token (logout). */
export async function clearAuthToken(): Promise<void> {
  await SecureStore.deleteItemAsync(TOKEN_KEY);
}

/** Check if a token exists in secure storage. */
export async function hasAuthToken(): Promise<boolean> {
  const token = await getAuthToken();
  return token !== null;
}

type UnauthorizedHandler = () => void;
let unauthorizedHandler: UnauthorizedHandler | null = null;

/**
 * Register what happens when an authenticated request comes back 401
 * (token revoked or expired). The session module uses it to log out locally.
 * Pass null to unregister.
 */
export function setUnauthorizedHandler(handler: UnauthorizedHandler | null): void {
  unauthorizedHandler = handler;
}

/** Parse a JSON body without throwing on empty / non-JSON responses (e.g. proxy errors). */
async function readJson(response: Response): Promise<unknown> {
  try {
    return await response.json();
  } catch {
    return null;
  }
}

function messageFrom(data: unknown): string | null {
  if (typeof data === 'object' && data !== null && 'message' in data) {
    const { message } = data as { message: unknown };
    if (typeof message === 'string' && message.length > 0) return message;
  }
  return null;
}

/** `Retry-After` header (seconds) — sent by Laravel's throttle middleware with 429. */
function retryAfterFrom(response: Response): number | null {
  const raw = response.headers?.get?.('Retry-After');
  if (!raw) return null;
  const seconds = Number(raw);
  return Number.isFinite(seconds) && seconds >= 0 ? Math.ceil(seconds) : null;
}

/** Type-safe wrapper around fetch with auth header and JSON handling. */
async function apiRequest<T>(
  path: string,
  options: {
    method?: 'GET' | 'POST' | 'PUT' | 'DELETE';
    body?: Record<string, unknown>;
    signal?: AbortSignal;
    /** Send no Bearer token (public endpoints such as the child PIN login). */
    anonymous?: boolean;
  } = {},
): Promise<T> {
  const token = options.anonymous ? null : await getAuthToken();
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  };

  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }

  const response = await fetch(`${ENV.API_BASE_URL}${path}`, {
    method: options.method ?? 'GET',
    headers,
    body: options.body ? JSON.stringify(options.body) : undefined,
    signal: options.signal,
  });

  const data = await readJson(response);

  if (!response.ok) {
    if (response.status === 401 && token && unauthorizedHandler) {
      // Only if the rejected token is still the session's token: a late 401 for an
      // old token (e.g. after logout + a new login) must not log the new user out.
      const current = await getAuthToken().catch(() => null);
      if (current === token) unauthorizedHandler();
    }
    throw new ApiError(
      messageFrom(data) ?? 'An error occurred',
      response.status,
      data,
      retryAfterFrom(response),
    );
  }

  return data as T;
}

/** API error with status code and response body. */
export class ApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly data?: unknown,
    /** Seconds until a throttled request may be retried (429 only). */
    public readonly retryAfterSeconds: number | null = null,
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

// ──────────────────────────────────────────────────────────────
//  API endpoint functions
// ──────────────────────────────────────────────────────────────

export const api = {
  /** POST /api/login — Login with email + password, returns Sanctum token. */
  login: (email: string, password: string, deviceName: string = 'mobile-app') =>
    apiRequest<LoginResponse>('/api/login', {
      method: 'POST',
      body: { email, password, device_name: deviceName },
    }),

  /** POST /api/logout — Revoke the current token. Pass a signal to abort (offline logout). */
  logout: (signal?: AbortSignal) =>
    apiRequest<{ message: string }>('/api/logout', { method: 'POST', signal }),

  /**
   * POST /api/child/pin-login (M2-02, public) — the child's only way in: PIN from the
   * parent → child token. 422 `invalid_pin` / `pin_not_usable`, 429 with Retry-After.
   */
  pinLogin: (pin: string, deviceName: string) =>
    apiRequest<PinLoginResponse>('/api/child/pin-login', {
      method: 'POST',
      body: { pin, device_name: deviceName },
      anonymous: true,
    }),

  /** POST /api/parent/children (M2-02) — child profile: nickname + optional birth year, no e-mail. */
  createChild: (body: CreateChildRequest) =>
    apiRequest<CreateChildResponse>('/api/parent/children', {
      method: 'POST',
      body: { display_name: body.display_name, birth_year: body.birth_year ?? null },
    }),

  /**
   * POST /api/parent/generate-pin — one-time 6-digit PIN for a child profile (15 min).
   * `mode` new_pet | join_pet (with `pet_id`) | relogin (already paired child, new device).
   */
  generatePin: (body: GenerateChildPinRequest) =>
    apiRequest<ChildPinResponse>('/api/parent/generate-pin', {
      method: 'POST',
      body: body.pet_id != null ? { child_id: body.child_id, pet_id: body.pet_id } : { child_id: body.child_id },
    }),

  /** DELETE /api/parent/children/{child}/tokens — sign the child out on every device. */
  revokeChildTokens: (childId: number) =>
    apiRequest<RevokeChildTokensResponse>(`/api/parent/children/${childId}/tokens`, { method: 'DELETE' }),

  /**
   * POST /api/broadcasting/auth — sign a private channel subscription for
   * Reverb (M1-08). Called by the pusher-js authorizer (`modules/realtime`).
   * 403 = not this user's pet.
   */
  authorizeChannel: (socketId: string, channelName: string) =>
    apiRequest<BroadcastAuthResponse>('/api/broadcasting/auth', {
      method: 'POST',
      body: { socket_id: socketId, channel_name: channelName },
    }),

  /** GET /api/user — the authenticated user (flat object) with the active pet. */
  getUser: () => apiRequest<UserResponse>('/api/user'),

  /** GET /api/child/pet — the child's full pet state (read-only, also while locked / unborn). */
  getChildPet: () => apiRequest<ChildPetState>('/api/child/pet'),

  /**
   * POST /api/child/contract — sign the responsibility contract (M1-07b). For an unborn
   * pet this is its birth. 201 → state; 409 `contract_already_signed`, 423 locked and
   * 422 validation all throw `ApiError` (409 / 423 bodies still carry `state`).
   */
  signContract: (body: SignContractRequest) =>
    apiRequest<SignContractResponse>('/api/child/contract', {
      method: 'POST',
      body: { signature_format: body.signature_format, signature: body.signature },
    }),

  /** GET /api/parent/dashboard — pet metrics, traffic light, quiet hours, activities. */
  getParentDashboard: () => apiRequest<ParentDashboardResponse>('/api/parent/dashboard'),

  /** GET /api/parent/quiet-hours — Get quiet hours config. */
  getQuietHours: () =>
    apiRequest<{ quiet_hours: QuietHours | null }>('/api/parent/quiet-hours'),

  /** PUT /api/parent/quiet-hours — Update quiet hours config. */
  updateQuietHours: (data: Partial<QuietHours>) =>
    apiRequest<{ message: string; quiet_hours: QuietHours }>('/api/parent/quiet-hours', {
      method: 'PUT',
      body: data as unknown as Record<string, unknown>,
    }),

  /** POST /api/parent/hard-stop — Toggle the emergency hard stop on the child's device. */
  toggleHardStop: (active: boolean) =>
    apiRequest<{ message: string; hard_stop_active: boolean }>('/api/parent/hard-stop', {
      method: 'POST',
      body: { active },
    }),

  /** POST /api/webhooks/fal-ai — (Internal) fal.ai webhook endpoint. */
  falAiWebhook: (petId: number, payload: Record<string, unknown>) =>
    apiRequest(`/api/webhooks/fal-ai?pet_id=${petId}`, { method: 'POST', body: payload }),
};
