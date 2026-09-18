/**
 * API client for PetPrep backend.
 * Uses fetch with auth token from SecureStore.
 */

import * as SecureStore from 'expo-secure-store';
import { ENV } from '@/config/env';
import type {
  PairingResponse,
  GeneratePinResponse,
  Pet,
  QuietHours,
} from '@/types';

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

/** Type-safe wrapper around fetch with auth header and JSON handling. */
async function apiRequest<T>(
  path: string,
  options: {
    method?: 'GET' | 'POST' | 'PUT' | 'DELETE';
    body?: Record<string, unknown>;
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
  });

  const data = await response.json();

  if (!response.ok) {
    throw new ApiError(
      data.message ?? 'An error occurred',
      response.status,
      data,
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
    apiRequest<{ token: string; user: { id: number; name: string; email: string; role: string } }>('/api/login', {
      method: 'POST',
      body: { email, password, device_name: deviceName },
    }),

  /** POST /api/logout — Revoke the current token. */
  logout: () =>
    apiRequest<{ message: string }>('/api/logout', { method: 'POST' }),

  /** POST /api/parent/generate-pin — Generate a 6-digit pairing PIN. */
  generatePin: () =>
    apiRequest<GeneratePinResponse>('/api/parent/generate-pin', { method: 'POST' }),

  /** POST /api/child/pair — Pair a child to a parent via PIN. */
  pairChild: (pin: string) =>
    apiRequest<PairingResponse>('/api/child/pair', { method: 'POST', body: { pin } }),

  /** GET /api/user — Get the authenticated user. */
  getUser: () =>
    apiRequest<{ data: { id: number; name: string; email: string; role: string } }>('/api/user'),

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
