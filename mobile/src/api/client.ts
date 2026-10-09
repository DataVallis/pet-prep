/**
 * API client for PetPrep backend.
 * Uses fetch with auth token from SecureStore.
 */

import * as SecureStore from 'expo-secure-store';
import { ENV } from '@/config/env';
import { currentLanguageTag } from '@/i18n';
import type { components, operations } from '@/api/schema';
import type { Pet, QuietHours } from '@/types';
import { CAT_UI_READY } from '@/config/features';

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

/**
 * `POST /api/devices` body (M3-02): this install's Expo push token, plus the push language
 * (M1-18 review: the server stores it only from this explicit field — iOS adds its own
 * implicit `Accept-Language`).
 */
export type RegisterDeviceRequest = components['schemas']['RegisterDeviceRequest'];

/** `POST /api/devices` 200 body. The Expo token itself is never echoed. */
export type RegisterDeviceResponse =
  operations['device.store']['responses'][200]['content']['application/json'];

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

/** `GET /api/child/pet/growth` and `/api/parent/pets/{pet}/growth` (same shape, M5-R04). */
export type PetGrowthResponse =
  operations['petGrowth.child']['responses'][200]['content']['application/json'];

/**
 * `POST /api/child/pet/{feed,water,clean}` 200 body (M1-07). Hand-declared: Scramble
 * types these responses as `unknown[] | string` (HANDOFF M1-07 debt 2). Refusals
 * (422 / 423) throw `ApiError` whose `data` also carries `state`.
 */
export interface ChildActionResponse {
  status: 'accepted' | 'unchanged';
  /** Feed only (M3-12): `emergency` = an emergency meal outside a window (not scored as on time). */
  feed_mode?: 'window' | 'emergency' | null;
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
  reason?: 'invalid_pin' | 'pin_not_usable' | 'too_many_attempts' | 'app_update_required';
  retry_after?: number;
}

/** `POST /api/parent/children` body / 201 response (M2-02). */
export type CreateChildRequest = components['schemas']['CreateChildRequest'];
export type CreateChildResponse =
  operations['childProfile.store']['responses'][201]['content']['application/json'];

/** Breed / origin / age at arrival of a new pet (M5-R01 contract, M5-R04 picker). */
/** Every breed the API knows (dogs + cats since M5-R06-01); the picker lists what `GET /api/breeds` returns. */
export type PetBreed = components['schemas']['BreedType'];
export type PetSpecies = components['schemas']['Species'];
export type PetOrigin = components['schemas']['PetOrigin'];
export type LifeStage = components['schemas']['LifeStage'];

/**
 * The parent's picker choice for a new pet. The server contract is **all or nothing**:
 * either the full set is sent or none of it (legacy pet).
 */
export interface NewPetProfile {
  /** M5-R06-02: dog | cat. The breed must belong to it (server 422 `breed_species_mismatch`). */
  species: PetSpecies;
  /** On the free plan: the species' free breed from the catalogue (mutt / domestic cat). */
  breed: PetBreed;
  origin: PetOrigin;
  age_stage: LifeStage;
  /**
   * M3-09: free plan or the 12-week challenge (starts with a purchase, M3-13). Free → the
   * species' free breed (the server refuses a paid one). Sent with the profile only.
   */
  plan: PetPlanType;
}

/** `POST /api/parent/generate-pin` body — always with `child_id` (the legacy call is deprecated). */
export interface GenerateChildPinRequest {
  child_id: number;
  /** Join this shared pet of the family; omit for a new pet (or a re-login). */
  pet_id?: number | null;
  /** New pet only (no `pet_id`): the full picker choice; ignored when joining a pet. */
  profile?: NewPetProfile | null;
}

/**
 * A UI feature this app build can show for a new pet (backend `ClientFeature`; the schema
 * types it as `string` because unknown values are dropped server-side, not refused).
 */
export type ClientFeature = 'behaviour_events' | 'training' | 'species_cat';

/**
 * What a build declares: the behaviour events ("Pelji ven", luža, pregrizen copat — M5-R02,
 * PR #42), the training mini-game ("Šola" — M5-R03) and — only once the cat HUD exists
 * (`CAT_UI_READY`, M5-R06-02 / T4) — cats (`species_cat`). A new pet gets a feature only
 * when BOTH the parent's generate-pin and the child's pin-login sent it — an older build on
 * either phone never gets something it can't show.
 */
export function clientFeatures(catUiReady: boolean = CAT_UI_READY): readonly ClientFeature[] {
  return catUiReady ? ['behaviour_events', 'training', 'species_cat'] : ['behaviour_events', 'training'];
}

/** This build's features (see {@link clientFeatures}). */
export const CLIENT_FEATURES: readonly ClientFeature[] = clientFeatures();

/**
 * The JSON body of a generate-pin request: profile fields only for a new pet, always the
 * full set plus the plan (M3-09) and the species (M5-R06-02), together with `features`
 * (never with `pet_id`, never without the profile). The breed is sent as chosen — the
 * picker already put the species' free breed on the free plan (no hard-coded mutt).
 */
export function generatePinBody(
  body: GenerateChildPinRequest,
  features: readonly ClientFeature[] = CLIENT_FEATURES,
): Record<string, number | string | ClientFeature[]> {
  if (body.pet_id != null) return { child_id: body.child_id, pet_id: body.pet_id };
  if (body.profile) {
    const { species, breed, origin, age_stage, plan } = body.profile;
    return { child_id: body.child_id, species, breed, origin, age_stage, plan, features: [...features] };
  }
  return { child_id: body.child_id };
}

/** `GET /api/breeds` 200 body (M5-R06-01 picker catalogue) — read it through `readBreedCatalogue`. */
export type BreedCatalogueResponse =
  operations['breed.index']['responses'][200]['content']['application/json'];

/** Query string of `GET /api/breeds` (`features[]` repeated). */
export function breedCataloguePath(features: readonly ClientFeature[] = CLIENT_FEATURES, species?: PetSpecies): string {
  const params = [
    ...(species ? [`species=${encodeURIComponent(species)}`] : []),
    ...features.map((f) => `features[]=${encodeURIComponent(f)}`),
  ];
  return params.length > 0 ? `/api/breeds?${params.join('&')}` : '/api/breeds';
}

/** `POST /api/parent/generate-pin` 200 body for a request with `child_id`. */
export type ChildPinResponse = Extract<
  operations['pairing.generatePin']['responses'][200]['content']['application/json'],
  { child_id: number }
>;

/** `DELETE /api/parent/children/{child}/tokens` 200 body. */
export type RevokeChildTokensResponse =
  operations['childProfile.revokeTokens']['responses'][200]['content']['application/json'];

/**
 * Body of both irreversible deletions (M2-08): the parent's current password and an
 * explicit `confirm: true`. The app additionally makes the parent type the confirmation
 * word of the app language ("IZBRIŠI" / "DELETE") and sends it as `confirm_word`.
 */
export type ConfirmDeletionRequest = components['schemas']['ConfirmDeletionRequest'];

/**
 * `POST /api/parent/account/delete` 200 body (M2-08). Hand-typed: Scramble infers the
 * spread array loosely. `scope: family` = the last parent took the whole family with
 * them; `parent` = only this account, the family stays with the other parent.
 */
export interface DeleteAccountResponse {
  status: 'deleted';
  scope: 'family' | 'parent';
  family_deleted: boolean;
  parents_deleted: number;
  children_deleted: number;
  pets_deleted: number;
}

/** `DELETE /api/parent/children/{child}` 200 body (M2-08). */
export interface DeleteChildResponse {
  status: 'deleted';
  child_id: number;
  /** Pets only this child cared for (deleted with all their data). */
  pets_deleted: number;
  /** Shared pets that stay with the other caretakers. */
  pets_kept: number;
}

/** Machine-readable `reason` of a refused deletion / export (M2-08). */
export type AccountDeletionReason =
  | 'invalid_password'
  | 'superadmin_protected'
  | 'child_not_found'
  | 'export_too_large';

/**
 * `GET /api/parent/account/export` 200 body (M2-08, GDPR art. 15 / 20) — the family's
 * data as one JSON document. The app never reads into it; it only hands it to the share
 * sheet, so only the envelope is typed.
 */
export interface FamilyExport {
  format: 'petprep.family-export';
  version: number;
  generated_at: string;
  [key: string]: unknown;
}

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

/**
 * Plan of one pet (M3-09 / M3-11, PAYMENTS_SPEC §2). Local types — **schema regen
 * pending**: the backend (`feat/M3-11-challenge-trial`) is built in parallel; replace with
 * `components['schemas'][…]` after `npm run generate-api-types`. Read every payload
 * through `readPetPlan` / `readBilling`, never directly.
 * - `free` — the mutt sandbox, free forever (no 12-week programme).
 * - `challenge` — the 12-week challenge, which starts with a purchase (M3-13, no free trial):
 *   `payment_required` (unborn: buy first; born: lock) → `paid`. `trial` only for a dog that
 *   still runs a pre-M3-13 7-day trial, or while the server's kill switch is off.
 */
export type PetPlanType = 'free' | 'challenge';
export type ChallengeStatus = 'trial' | 'payment_required' | 'paid';

/** `plan` on the child state, the parent dashboard pet and `pet.updated` (cleaned shape; raw types in schema.ts, read through the readers). */
export interface PetPlan {
  type: PetPlanType;
  status: ChallengeStatus | null;
  trial_ends_at: string | null;
  paid_at: string | null;
  /**
   * M5-F02: the plan a parent sees — `free` also for a mutt whose challenge nobody bought
   * (grandfathered by the M3-11 backfill / admin unlock), so a mutt is never "Plačano".
   * Display only: `type` / `status` stay the real plan (12-week programme, history).
   * Missing (older server) = `type`.
   */
  display_type?: PetPlanType;
}

/** One pet of `GET /api/parent/billing` (cleaned shape; raw types in schema.ts, read through the readers). */
export interface BillingPet {
  pet_id: number;
  plan: PetPlanType;
  status: ChallengeStatus | null;
  trial_ends_at: string | null;
  paid_at: string | null;
  /** Deprecated (M3-13): true only for a dog whose pre-M3-13 trial ran; null for a free pet. Not shown. */
  trial_available: boolean | null;
  /** P5: deleting this pet loses a purchased, unfinished challenge. */
  deletion_loses_purchase: boolean;
}

/** `GET /api/parent/billing` 200 body (cleaned shape; raw types in schema.ts, read through the readers). */
export interface BillingResponse {
  /** Purchased challenges not yet assigned to a pet. */
  credits_available: number;
  pets: BillingPet[];
}

/**
 * `POST /api/parent/pets/{pet}/challenge/activate` 200 body (cleaned shape; raw types in schema.ts, read through the readers).
 * 409 `{ reason: 'no_credit' }`, 422 (free plan / already paid).
 */
export interface ActivateChallengeResponse {
  pet_id: number;
  plan: PetPlan;
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

/**
 * Deletion body (M2-08). `confirm_word` (M1-18) is `deleteConfirmWord()` — the canonical
 * confirmation word of the app language ("IZBRIŠI" / "DELETE"), sent once the parent's
 * input matched it; the server accepts the word of any supported language.
 */
function confirmBody(password: string, confirmWord?: string, acknowledgePaidChallenge = false): Record<string, unknown> {
  return {
    password,
    confirm: true,
    ...(confirmWord ? { confirm_word: confirmWord } : {}),
    // M3-11 P5: the parent confirmed that a purchased, unfinished challenge is lost.
    ...(acknowledgePaidChallenge ? { acknowledge_paid_challenge: true } : {}),
  };
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
    // App language (M1-18): server texts; stored per device on push registration.
    'Accept-Language': currentLanguageTag(),
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

  /**
   * POST /api/devices (M3-02, parent or child) — register / refresh this install for
   * escalation pushes. Upsert on the server: the token moves to the signed-in account.
   */
  registerDevice: (body: RegisterDeviceRequest) =>
    apiRequest<RegisterDeviceResponse>('/api/devices', {
      method: 'POST',
      body: {
        expo_push_token: body.expo_push_token,
        platform: body.platform,
        app_version: body.app_version ?? null,
        ...(body.locale !== undefined ? { locale: body.locale } : {}),
      },
    }),

  /**
   * POST /api/devices/unregister (M3-02, PR #35) — stop pushes to this install
   * (logout). 204, idempotent. The token travels in the body, never in the URL.
   */
  unregisterDevice: (expoPushToken: string, signal?: AbortSignal) =>
    apiRequest<null>('/api/devices/unregister', {
      method: 'POST',
      body: { expo_push_token: expoPushToken } satisfies components['schemas']['UnregisterDeviceRequest'],
      signal,
    }),

  /** POST /api/logout — Revoke the current token. Pass a signal to abort (offline logout). */
  logout: (signal?: AbortSignal) =>
    apiRequest<{ message: string }>('/api/logout', { method: 'POST', signal }),

  /**
   * POST /api/child/pin-login (M2-02, public) — the child's only way in: PIN from the
   * parent → child token. 422 `invalid_pin` / `pin_not_usable`, 429 with Retry-After.
   * Always declares this build's `features` (M5-R02: the child's device must show them too).
   * M5-R06-01: 422 `app_update_required` — the pet is a cat and this build can't show one
   * (no `species_cat`, see `CAT_UI_READY`); the PIN stays usable.
   */
  pinLogin: (pin: string, deviceName: string) =>
    apiRequest<PinLoginResponse>('/api/child/pin-login', {
      method: 'POST',
      body: { pin, device_name: deviceName, features: [...CLIENT_FEATURES] },
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
   * A new pet carries the full `{breed, origin, age_stage}` (M5-R04); 422 `breed_locked`
   * for a premium breed.
   */
  generatePin: (body: GenerateChildPinRequest) =>
    apiRequest<ChildPinResponse>('/api/parent/generate-pin', {
      method: 'POST',
      body: generatePinBody(body),
    }),

  /**
   * GET /api/breeds (M5-R06-01, parent token) — the picker catalogue: available species and
   * their breeds (free first, then paid by `sort_order`). Cats only appear while the server
   * switch is on AND this build declares `species_cat` (`CAT_UI_READY`).
   */
  getBreedCatalogue: (features: readonly ClientFeature[] = CLIENT_FEATURES, signal?: AbortSignal) =>
    apiRequest<BreedCatalogueResponse>(breedCataloguePath(features), { signal }),

  /** DELETE /api/parent/children/{child}/tokens — sign the child out on every device. */
  revokeChildTokens: (childId: number) =>
    apiRequest<RevokeChildTokensResponse>(`/api/parent/children/${childId}/tokens`, { method: 'DELETE' }),

  /**
   * DELETE /api/parent/children/{child} (M2-08) — delete a child profile, irreversibly.
   * A pet only this child cared for goes with it; a shared pet stays. 404
   * `child_not_found`, 422 `invalid_password`, 429 (5 per 15 min).
   */
  deleteChild: (childId: number, password: string, confirmWord?: string, acknowledgePaidChallenge = false) =>
    apiRequest<DeleteChildResponse>(`/api/parent/children/${childId}`, {
      method: 'DELETE',
      body: confirmBody(password, confirmWord, acknowledgePaidChallenge),
    }),

  /**
   * POST /api/parent/account/delete (M2-08) — delete the parent's own account; the last
   * parent deletes the whole family. Every token is revoked → the app must log out
   * locally afterwards. 422 `invalid_password`, 403 `superadmin_protected`, 429.
   */
  deleteAccount: (password: string, confirmWord?: string, acknowledgePaidChallenge = false) =>
    apiRequest<DeleteAccountResponse>('/api/parent/account/delete', {
      method: 'POST',
      body: confirmBody(password, confirmWord, acknowledgePaidChallenge),
    }),

  /** GET /api/parent/account/export (M2-08) — the family's data as JSON. 413 too large, 429 (3/h). */
  exportFamilyData: () => apiRequest<FamilyExport>('/api/parent/account/export'),

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

  /** GET /api/child/pet/growth (M5-R04) — the pet's pictures per life stage, oldest first; 404 `no_pet` before pairing. */
  getChildPetGrowth: () => apiRequest<PetGrowthResponse>('/api/child/pet/growth'),

  /**
   * POST /api/child/pet/feed — hunger → 100 % inside a feed window, once per window, or an
   * emergency meal outside a window while hunger shows ≤ 20 % (M3-12, `feed_mode`).
   * 422 `outside_feed_window` / `already_fed_this_window` / `needs_cleaning`, 423 locked.
   */
  feedPet: () => apiRequest<ChildActionResponse>('/api/child/pet/feed', { method: 'POST' }),

  /** POST /api/child/pet/water — thirst → 100 %. 422 `water_daily_limit` / `water_too_soon` / `needs_cleaning`, 423 locked. */
  waterPet: () => apiRequest<ChildActionResponse>('/api/child/pet/water', { method: 'POST' }),

  /** POST /api/child/pet/clean — hygiene → 100 % (`unchanged` when already clean), 423 locked. */
  cleanPet: () => apiRequest<ChildActionResponse>('/api/child/pet/clean', { method: 'POST' }),

  /**
   * POST /api/child/pet/take-out — "Pelji ven" (M5-R02): the puppy's bladder clock restarts.
   * `unchanged` for a repeat within 60 s, 422 `take_out_not_needed` (not a puppy / legacy), 423 locked.
   * An accident that is already due is recorded first; the answer always carries `state`.
   */
  takeOutPet: () => apiRequest<ChildActionResponse>('/api/child/pet/take-out', { method: 'POST' }),

  /** POST /api/child/pet/resolve-chewing — "Pospravi in daj igračo" (M5-R02); `unchanged` when nothing is chewed. */
  resolveChewing: () => apiRequest<ChildActionResponse>('/api/child/pet/resolve-chewing', { method: 'POST' }),

  /**
   * POST /api/child/pet/training/start {command} (M5-R03) — the server's schedule for one
   * 50 s session. 200 untyped in `schema.ts` → read with `readStartResponse`. 422
   * `training_not_available` / `training_session_active` / `training_daily_budget_used` / `training_day_ending` (the session + TTL would cross the family midnight)
   * (+ `next_allowed_at`), 423 locked; refusals carry `state`.
   */
  startTraining: (command: components['schemas']['StartTrainingRequest']['command']) =>
    apiRequest<unknown>('/api/child/pet/training/start', {
      method: 'POST',
      body: { command } satisfies components['schemas']['StartTrainingRequest'],
    }),

  /**
   * POST /api/child/pet/training/finish {session_id, taps} (M5-R03) — the "Pohvali" taps as
   * whole ms since the app's local start (≤ 64, ≤ duration); the server scores them. A
   * repeat answers `unchanged` with the stored result. 200 untyped → `readFinishResponse`.
   * 422 `training_session_invalid|not_over|expired|invalid_taps|not_available|interrupted`, 423 locked.
   */
  finishTraining: (sessionId: string, taps: readonly number[]) =>
    apiRequest<unknown>('/api/child/pet/training/finish', {
      method: 'POST',
      body: { session_id: sessionId, taps: [...taps] } satisfies components['schemas']['FinishTrainingRequest'],
    }),

  /**
   * POST /api/child/pet/play {kind} (M5-R05) — the child finished a ball game (`play`) or a
   * cuddle (`cuddle`), free or from the dog's invitation. Mood only (30 min happy). 200
   * `accepted` {play: {kind, source}, state} / `unchanged` (repeat within 10 s); untyped in
   * `schema.ts` → read with `readPlayResponse`. 422 `play_not_available` (+ `next_allowed_at`
   * = end of quiet hours), 423 locked; refusals carry `state`.
   */
  playWithPet: (kind: components['schemas']['PlayRequest']['kind']) =>
    apiRequest<unknown>('/api/child/pet/play', {
      method: 'POST',
      body: { kind } satisfies components['schemas']['PlayRequest'],
    }),

  // ── M5-R06-08a cat care (server M5-R06-04 / 05). 200 bodies are untyped in `schema.ts`
  //    (the session shapes are mis-inferred) → read with `modules/catCare/catCare.ts`.
  //    Refusals 422 carry `reason` + `next_allowed_at` + `state`; locks 423 carry `state`.

  /**
   * POST /api/child/pet/wand/start — the cat's ~60 s feather wand game: `session` = the
   * server's schedule (pounces, catch). 422 `wand_not_available` / `needs_cleaning` /
   * `wand_quiet_hours` / `wand_too_soon` / `wand_session_active` / `care_session_active` /
   * `wand_day_ending`, 423 locked.
   */
  startWand: () => apiRequest<unknown>('/api/child/pet/wand/start', { method: 'POST' }),

  /**
   * POST /api/child/pet/wand/finish {session_id, moves[{t, away}]} — the feather moves the
   * app saw (ms since its local start). 200 `accepted` (counts) / `rejected` (didn't, no
   * penalty) / `unchanged` (repeat) with `result`. 422 `wand_session_*` / `wand_invalid_moves`.
   */
  finishWand: (sessionId: string, moves: readonly { t: number; away: boolean }[]) =>
    apiRequest<unknown>('/api/child/pet/wand/finish', {
      method: 'POST',
      body: { session_id: sessionId, moves: moves.map((m) => ({ t: m.t, away: m.away })) } satisfies components['schemas']['FinishWandRequest'],
    }),

  /** POST /api/child/pet/litter/scoop — scoop every open litter use. `unchanged` when nothing to scoop; 422 `litter_not_available`. */
  scoopLitter: () => apiRequest<unknown>('/api/child/pet/litter/scoop', { method: 'POST' }),

  /**
   * POST /api/child/pet/{litter-change|grooming}/start — the 30 s stroke game (grooming 60 s
   * while matted). 422 `needs_cleaning`, `litter_change_done` / `litter_change_session_active`,
   * `grooming_*`, `care_session_active` (+ `next_allowed_at`), 423 locked.
   */
  startCareChore: (kind: 'grooming' | 'litter_change') =>
    apiRequest<unknown>(kind === 'grooming' ? '/api/child/pet/grooming/start' : '/api/child/pet/litter-change/start', { method: 'POST' }),

  /** POST /api/child/pet/{litter-change|grooming}/finish {session_id, strokes[{t}]}. 422 `care_session_*`. */
  finishCareChore: (kind: 'grooming' | 'litter_change', sessionId: string, strokes: readonly number[]) =>
    apiRequest<unknown>(kind === 'grooming' ? '/api/child/pet/grooming/finish' : '/api/child/pet/litter-change/finish', {
      method: 'POST',
      body: { session_id: sessionId, strokes: strokes.map((t) => ({ t })) } satisfies components['schemas']['FinishCareChoreRequest'],
    }),

  /** POST /api/child/pet/scratching/start — carry the cat to the scratcher (`land_at_ms`). 422 `scratching_not_needed` / `scratching_session_active` / `care_session_active`. */
  startScratching: () => apiRequest<unknown>('/api/child/pet/scratching/start', { method: 'POST' }),

  /** POST /api/child/pet/scratching/finish {session_id, praise_ms|null} — the praise (ms since the local start). 422 `care_session_*` / `scratching_not_needed`. */
  finishScratching: (sessionId: string, praiseMs: number | null) =>
    apiRequest<unknown>('/api/child/pet/scratching/finish', {
      method: 'POST',
      body: { session_id: sessionId, praise_ms: praiseMs } satisfies components['schemas']['FinishScratchingRequest'],
    }),

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

  /** GET /api/parent/pets/{pet}/growth (M5-R04) — growth album of a family pet; 404 `pet_not_found` otherwise. */
  getParentPetGrowth: (petId: number) => apiRequest<PetGrowthResponse>(`/api/parent/pets/${petId}/growth`),

  /** GET /api/parent/activities — one page of a family pet's activities, newest first. */
  getPetActivities: (petId: number, page: number, perPage: number = 20) =>
    apiRequest<PetActivitiesResponse>(
      `/api/parent/activities?pet_id=${petId}&per_page=${perPage}&page=${page}`,
    ),

  /**
   * GET /api/parent/billing (M3-09 / M3-11, parent only) — unassigned challenge credits and
   * the plan + challenge status of every family pet; the server is the source of truth
   * (RevenueCat webhook, payment lock, admin grants). Untyped until `schema.ts` is
   * regenerated → read with `readBilling`.
   */
  getBilling: () => apiRequest<unknown>('/api/parent/billing'),

  /**
   * POST /api/parent/pets/{pet}/challenge/activate (M3-11) — assign the family's oldest
   * unassigned challenge credit to this pet (idempotent: an already paid pet stays paid).
   * 409 `no_credit`, 422 free plan / already paid. Body read with `readPetPlan`.
   */
  activateChallenge: (petId: number) =>
    apiRequest<unknown>(`/api/parent/pets/${petId}/challenge/activate`, { method: 'POST' }),

  /** POST /api/parent/invite-parent — code for a second parent (revokes this parent's previous code). */
  inviteParent: () => apiRequest<InviteParentResponse>('/api/parent/invite-parent', { method: 'POST' }),

  /** POST /api/parent/join-family — join another parent's family with their code. */
  joinFamily: (code: string) =>
    apiRequest<JoinFamilyResponse>('/api/parent/join-family', { method: 'POST', body: { code } }),

  /** POST /api/webhooks/fal-ai — (Internal) fal.ai webhook endpoint. */
  falAiWebhook: (petId: number, payload: Record<string, unknown>) =>
    apiRequest(`/api/webhooks/fal-ai?pet_id=${petId}`, { method: 'POST', body: payload }),
};
