/**
 * API client for PetPrep backend.
 * Uses fetch with auth token from SecureStore.
 */

import * as SecureStore from 'expo-secure-store';
import { ENV } from '@/config/env';
import type { components, operations } from '@/api/schema';
import type {
  PairingResponse,
  GeneratePinResponse,
  Pet,
  QuietHours,
} from '@/types';

/**
 * Authenticated user as returned (flat, no `data` wrapper) by `GET /api/user`
 * and inside `POST /api/login`. Declared here because the generated schema
 * types `role` as a plain string (backend enum `UserRole`: parent | child).
 */
export type UserRole = 'parent' | 'child';

export interface SessionUser {
  id: number;
  name: string;
  email: string;
  role: UserRole;
}

/** `GET /api/user` response. */
export interface UserResponse extends SessionUser {
  pet: Pet | null;
}

/** `POST /api/login` response. */
export interface LoginResponse {
  token: string;
  user: SessionUser;
  pet: Pet | null;
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
  } = {},
): Promise<T> {
  const token = await getAuthToken();
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

  /** POST /api/parent/generate-pin — Generate a 6-digit pairing PIN. */
  generatePin: () =>
    apiRequest<GeneratePinResponse>('/api/parent/generate-pin', { method: 'POST' }),

  /** POST /api/child/pair — Pair a child to a parent via PIN. */
  pairChild: (pin: string) =>
    apiRequest<PairingResponse>('/api/child/pair', { method: 'POST', body: { pin } }),

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
