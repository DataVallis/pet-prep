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

/** `POST /api/register` body (M2-10a): parent self-registration. */
export type RegisterParentRequest = components['schemas']['RegisterParentRequest'];

/**
 * `POST /api/register` 201 body — same shape as `LoginResponse` (parent token, `pet`
 * null). Errors: 422 `{ message, errors: { field: string[] } }` (e-mail taken, password
 * policy, terms), 429 with `Retry-After`.
 */
export type RegisterResponse = LoginResponse;

/** Laravel validation error body (422). */
export interface ValidationErrorBody {
  message: string;
  errors?: Record<string, string[]>;
}

/** Machine-readable reason per field in a `POST /api/register` 422 (`codes`). */
export type RegisterErrorCode =
  | 'name_invalid'
  | 'email_invalid'
  | 'email_taken'
  | 'password_weak'
  | 'password_mismatch'
  | 'terms_required'
  | 'timezone_invalid'
  | 'device_name_invalid';

/** `POST /api/register` 422 body: validation errors + `codes` {field: code}. */
export interface RegisterErrorBody extends ValidationErrorBody {
  codes?: Partial<Record<string, RegisterErrorCode>>;
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

/**
 * `POST /api/child/pet/{feed,water,clean}` 200 body (M1-07). Hand-declared: Scramble
 * types these responses as `unknown[] | string` (HANDOFF M1-07 debt 2). Refusals
 * (422 / 423) throw `ApiError` whose `data` also carries `state`.
 */
export interface ChildActionResponse {
  status: 'accepted' | 'unchanged';
  state: ChildPetState;
}

/** `POST /api/child/pet/steps` body (M1-04 / M1-07). */
export type SyncStepsRequest = components['schemas']['SyncStepsRequest'];

/** What the server did with a step sync — every one of them is a 200. */
export type StepSyncStatus = 'accepted' | 'capped' | 'rejected' | 'unchanged' | 'stale';

/** `POST /api/child/pet/steps` 200 body. */
export interface SyncStepsResponse {
  status: StepSyncStatus;
  accepted_steps: number;
  steps_today: number;
  energy_level: number;
  state: ChildPetState;
}

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

/** `POST /api/parent/hard-stop` 200 body (toggle for one pet of the family). */
export type HardStopResponse =
  operations['parentDashboard.toggleHardStop']['responses'][200]['content']['application/json'];

/** `POST /api/parent/invite-parent` 201 body: single-use 8-char code, valid 24 h. */
export type InviteParentResponse =
  operations['family.invite']['responses'][201]['content']['application/json'];

/**
 * `POST /api/parent/join-family` 200 body. Hand-typed: Scramble types `parents` as a
 * string and unions the body with `string`. Errors: 422 `invalid_code` / `code_expired`
 * / `code_used`, 409 `already_member` / `family_not_empty`, 429 `too_many_attempts`.
 */
export interface JoinFamilyResponse {
  message: string;
  family: {
    id: number;
    timezone: string;
    parents: { id: number; name: string }[];
    children_count: number;
    pets_count: number;
  };
}

/**
 * `GET /api/parent/activities?pet_id=&page=` 200 body. Items are read through
 * `readTimeline` (the schema types ids as strings); `meta` drives pagination.
 */
export interface PetActivitiesResponse {
  data: unknown[];
  meta: { current_page: number; last_page: number; total: number };
}

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

  /**
   * POST /api/register (M2-10a, public) — parent sign-up with e-mail + password; creates
   * the parent's family (timezone from the device) and returns a parent token.
   */
  register: (body: RegisterParentRequest) =>
    apiRequest<RegisterResponse>('/api/register', {
      method: 'POST',
      body: { ...body },
      anonymous: true,
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
   * POST /api/child/pet/feed — hunger → 100 % inside a feed window, once per window.
   * 422 `outside_feed_window` / `already_fed_this_window` / `needs_cleaning`, 423 locked.
   */
  feedPet: () => apiRequest<ChildActionResponse>('/api/child/pet/feed', { method: 'POST' }),

  /** POST /api/child/pet/water — thirst → 100 %. 422 `water_daily_limit` / `water_too_soon` / `needs_cleaning`, 423 locked. */
  waterPet: () => apiRequest<ChildActionResponse>('/api/child/pet/water', { method: 'POST' }),

  /** POST /api/child/pet/clean — hygiene → 100 % (`unchanged` when already clean), 423 locked. */
  cleanPet: () => apiRequest<ChildActionResponse>('/api/child/pet/clean', { method: 'POST' }),

  /** POST /api/child/pet/steps — today's cumulative steps of this device (max wins on the server). */
  syncSteps: (body: SyncStepsRequest) =>
    apiRequest<SyncStepsResponse>('/api/child/pet/steps', {
      method: 'POST',
      body: { steps_today: body.steps_today, source: body.source, recorded_at: body.recorded_at },
    }),

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

  /**
   * POST /api/parent/hard-stop {pet_id, active} — SETS the hard stop of one family pet
   * to `active` (idempotent: a repeat or a lost-response retry changes nothing,
   * `changed: false`). Without `active` the server still toggles (deprecated; never
   * used by the app). → `{pet_id, is_hard_stopped, changed}`.
   */
  setHardStop: (petId: number, active: boolean) =>
    apiRequest<HardStopResponse>('/api/parent/hard-stop', {
      method: 'POST',
      body: { pet_id: petId, active },
    }),

  /**
   * GET /api/parent/children/{child}/report?days=7|30|84 (M2-05) — the child's report.
   * Returned untyped (`schema.ts` says `unknown[]`); read it with `readChildReport`.
   */
  getChildReport: (childId: number, days: 7 | 30 | 84) =>
    apiRequest<unknown>(`/api/parent/children/${childId}/report?days=${days}`),

  /** GET /api/parent/activities — one page of a family pet's activities, newest first. */
  getPetActivities: (petId: number, page: number, perPage: number = 20) =>
    apiRequest<PetActivitiesResponse>(
      `/api/parent/activities?pet_id=${petId}&per_page=${perPage}&page=${page}`,
    ),

  /** POST /api/parent/invite-parent — code for a second parent (revokes this parent's previous code). */
  inviteParent: () => apiRequest<InviteParentResponse>('/api/parent/invite-parent', { method: 'POST' }),

  /** POST /api/parent/join-family — join another parent's family with their code. */
  joinFamily: (code: string) =>
    apiRequest<JoinFamilyResponse>('/api/parent/join-family', { method: 'POST', body: { code } }),

  /** POST /api/webhooks/fal-ai — (Internal) fal.ai webhook endpoint. */
  falAiWebhook: (petId: number, payload: Record<string, unknown>) =>
    apiRequest(`/api/webhooks/fal-ai?pet_id=${petId}`, { method: 'POST', body: payload }),
};
