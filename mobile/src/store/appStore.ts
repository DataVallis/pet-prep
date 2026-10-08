/**
 * Global application state using Zustand — session and UI only.
 * Manages: session (token, user, the session pet used for routing), WebSocket status,
 * lock overlay, overlay visibility. The child's live pet state (metrics, windows,
 * steps) is server state in TanStack Query (`useChildPet`, M1-13), not here.
 */

import { isPaymentRequired, readPetPlan } from '@/modules/plan/plan';
import { create } from 'zustand';
import type { Pet, PetUpdatedBroadcast } from '@/types';
import type { VideoState } from '@/modules/petMedia/petMedia';

export interface AppUser {
  id: number;
  name: string;
  /** null for a PIN-only child profile (M2-02). */
  email: string | null;
  role: 'parent' | 'child';
}

/** Where a tapped push should lead (parent app): the pet it is about. */
export interface PushTarget {
  petId: number;
}

export type PairingStatus = 'unpaired' | 'pairing' | 'paired' | 'error';

export type WebSocketStatus = 'disconnected' | 'connecting' | 'connected' | 'reconnecting';

export type LockState = 'none' | 'hard_stop' | 'payment_required' | 'illness' | 'game_over' | 'inactive';

/** Details for the lock overlay (e.g. "do 18:30" at the vet). */
export interface LockDetails {
  /** End of the lock (illness), ISO; null when open-ended. */
  until: string | null;
  /** Family IANA timezone for showing `until`. */
  timezone: string | null;
}

/**
 * App launch state (M1-12): `restoring` while the saved token is checked,
 * `offline` when the check could not reach the server (token kept, retry offered),
 * `ready` once routing can happen (signed in or showing login).
 */
export type BootStatus = 'restoring' | 'offline' | 'ready';

/** M5-R05: what the "Igra" overlay shows — the choice, the ball game or cuddles. */
export type PlayOverlayMode = 'pick' | 'play' | 'cuddle';

export interface SignInPayload {
  token: string;
  user: AppUser;
  pet: Pet | null;
  /**
   * Per-child contract flag from the server (`/api/user`, `/api/login`, pin-login).
   * When a boolean it wins over anything on the pet: a child who joined a shared,
   * already born pet must still sign (the pet's `born_at` says "born").
   */
  awaitingContract?: boolean | null;
  /**
   * Family IANA zone remembered on this device (child, session restore) — the lock
   * overlay shows the vet end time in it before the child state loads.
   */
  familyTimezone?: string | null;
}

/** The session pet with the server's per-child contract flag applied (if any). */
export function petWithContractFlag(pet: Pet | null, awaitingContract?: boolean | null): Pet | null {
  if (!pet || typeof awaitingContract !== 'boolean') return pet;
  return { ...pet, awaiting_contract: awaitingContract };
}

/**
 * Lock state from a raw pet snapshot (login / session restore, M1-16), in the server's
 * priority: game over › inactive › hard stop › payment (M3-11) › illness. The HUD replaces it with the
 * per-child lock from `GET /api/child/pet` as soon as that arrives.
 */
export function lockStateFromPet(pet: Pet | null, now: number = Date.now()): LockState {
  if (!pet) return 'none';
  if (pet.is_game_over) return 'game_over';
  if (pet.is_active === false) return 'inactive';
  if (pet.is_hard_stopped === true) return 'hard_stop';
  if (isPaymentRequired(readPetPlan(pet.plan))) return 'payment_required';
  if (pet.illness_until && Date.parse(pet.illness_until) > now) return 'illness';
  return 'none';
}

/** Same priority for a `PetUpdated` broadcast (parent session pet). */
export function lockStateFromBroadcast(broadcast: PetUpdatedBroadcast): LockState {
  if (broadcast.is_game_over) return 'game_over';
  if (!broadcast.is_active) return 'inactive';
  if (broadcast.is_hard_stopped) return 'hard_stop';
  if (isPaymentRequired(readPetPlan(broadcast.plan))) return 'payment_required';
  if (broadcast.is_ill) return 'illness';
  return 'none';
}

/**
 * The child must sign the contract before the HUD (M1-07b / M2-02). The per-child
 * `awaiting_contract` wins when present — `signIn` copies the session flag from
 * `/api/user` / `/api/login` / pin-login onto the pet, child state and broadcasts set
 * it too; only a pet without the flag falls back to `born_at === null`.
 */
export function isAwaitingContract(pet: Pet | null): boolean {
  if (!pet) return false;
  return pet.awaiting_contract ?? pet.born_at === null;
}

interface AppStore {
  // Auth & pairing
  authToken: string | null;
  user: AppUser | null;
  pairingStatus: PairingStatus;
  pairingError: string | null;
  setAuthToken: (token: string | null) => void;
  setUser: (user: AppUser | null) => void;
  setPairingStatus: (status: PairingStatus, error?: string | null) => void;
  /** Apply a successful login or session restore in one update (routing by role follows). */
  signIn: (payload: SignInPayload) => void;

  // App launch
  bootStatus: BootStatus;
  setBootStatus: (status: BootStatus) => void;

  // Pet data
  pet: Pet | null;
  setPet: (pet: Pet | null) => void;
  /**
   * Route the child to the contract step (true) — the server's per-child state says
   * this child must sign (`awaiting_contract` / 423 `contract_required`).
   */
  setAwaitingContract: (awaiting: boolean) => void;
  /**
   * Parent dashboard only: map a broadcast onto the session pet. The child HUD writes
   * broadcasts into the TanStack cache instead (`applyBroadcastToCache`).
   */
  updatePetFromBroadcast: (broadcast: PetUpdatedBroadcast) => void;

  // WebSocket
  wsStatus: WebSocketStatus;
  setWsStatus: (status: WebSocketStatus) => void;

  // Lock state (hard stop, illness, game over)
  lockState: LockState;
  lockDetails: LockDetails;
  setLockState: (state: LockState, details?: LockDetails) => void;
  /**
   * State video the child HUD is showing (after fallbacks; null = image / placeholder /
   * unknown). The vet lock darkens its veil unless this is a real `sick` video.
   */
  hudVideoState: VideoState | null;
  setHudVideoState: (state: VideoState | null) => void;

  // UI state
  isWalkModalVisible: boolean;
  setWalkModalVisible: (visible: boolean) => void;
  isCleaningOverlayVisible: boolean;
  setCleaningOverlayVisible: (visible: boolean) => void;
  /** "Moj kuža" album over the HUD (the HUD video pauses while it is open). */
  isAlbumVisible: boolean;
  setAlbumVisible: (visible: boolean) => void;
  /** "Šola" training overlay over the HUD (M5-R03; the HUD video pauses while it is open). */
  isTrainingVisible: boolean;
  setTrainingVisible: (visible: boolean) => void;
  /**
   * M5-R05 "Igra" overlay over the HUD: `pick` (Žoga / Crkljanje), or one mini-game;
   * null = closed. The HUD video pauses while it is open.
   */
  playOverlay: PlayOverlayMode | null;
  setPlayOverlay: (mode: PlayOverlayMode | null) => void;
  /**
   * Parent: a tapped push (M3-02) asks the dashboard to open the detail of the child
   * caring for this pet; the dashboard clears it once handled.
   */
  pushTarget: PushTarget | null;
  setPushTarget: (target: PushTarget | null) => void;

  // Logout / reset
  reset: () => void;
}

export const useAppStore = create<AppStore>((set) => ({
  // Auth & pairing
  authToken: null,
  user: null,
  pairingStatus: 'unpaired',
  pairingError: null,
  setAuthToken: (token) => set({ authToken: token }),
  setUser: (user) => set({ user }),
  setPairingStatus: (status, error = null) =>
    set({ pairingStatus: status, pairingError: error }),
  signIn: ({ token, user, pet, awaitingContract, familyTimezone }) => {
    const sessionPet = petWithContractFlag(pet, awaitingContract);
    set({
      authToken: token,
      user: { id: user.id, name: user.name, email: user.email, role: user.role },
      pet: sessionPet,
      pairingStatus: sessionPet ? 'paired' : 'unpaired',
      pairingError: null,
      lockState: lockStateFromPet(sessionPet),
      lockDetails: { until: sessionPet?.illness_until ?? null, timezone: familyTimezone ?? null },
      bootStatus: 'ready',
    });
  },

  // App launch
  bootStatus: 'restoring',
  setBootStatus: (bootStatus) => set({ bootStatus }),

  // Pet data
  pet: null,
  setPet: (pet) => set({ pet }),
  setAwaitingContract: (awaiting) =>
    set((state) => {
      if (!state.pet || state.pet.awaiting_contract === awaiting) return {};
      return { pet: { ...state.pet, awaiting_contract: awaiting } };
    }),
  updatePetFromBroadcast: (broadcast) =>
    set((state) => {
      if (!state.pet) return {};

      const updatedPet: Pet = {
        ...state.pet,
        hunger_level: broadcast.hunger_level,
        thirst_level: broadcast.thirst_level,
        energy_level: broadcast.energy_level,
        hygiene_level: broadcast.hygiene_level,
        is_active: broadcast.is_active,
        pet_state: broadcast.pet_state,
        escalation_level: broadcast.escalation_level,
        current_video_url: broadcast.current_video_url ?? state.pet.current_video_url,
        is_game_over: broadcast.is_game_over,
        is_hard_stopped: broadcast.is_hard_stopped,
        illness_until: broadcast.illness_until,
        awaiting_contract: broadcast.awaiting_contract ?? state.pet.awaiting_contract,
        born_at: broadcast.born_at !== undefined ? broadcast.born_at : state.pet.born_at,
      };

      return {
        pet: updatedPet,
        lockState: lockStateFromBroadcast(broadcast),
        lockDetails: { until: broadcast.illness_until, timezone: state.lockDetails.timezone },
      };
    }),

  // WebSocket
  wsStatus: 'disconnected',
  setWsStatus: (status) => set({ wsStatus: status }),

  // Lock state
  lockState: 'none',
  lockDetails: { until: null, timezone: null },
  setLockState: (lockState, details = { until: null, timezone: null }) =>
    set((state) =>
      state.lockState === lockState &&
      state.lockDetails.until === details.until &&
      state.lockDetails.timezone === details.timezone
        ? {}
        : { lockState, lockDetails: details },
    ),
  hudVideoState: null,
  setHudVideoState: (hudVideoState) => set((state) => (state.hudVideoState === hudVideoState ? {} : { hudVideoState })),

  // UI state
  isWalkModalVisible: false,
  setWalkModalVisible: (isWalkModalVisible) => set({ isWalkModalVisible }),
  isCleaningOverlayVisible: false,
  setCleaningOverlayVisible: (isCleaningOverlayVisible) => set({ isCleaningOverlayVisible }),
  isAlbumVisible: false,
  setAlbumVisible: (isAlbumVisible) => set({ isAlbumVisible }),
  isTrainingVisible: false,
  setTrainingVisible: (isTrainingVisible) => set({ isTrainingVisible }),
  playOverlay: null,
  setPlayOverlay: (playOverlay) => set({ playOverlay }),
  pushTarget: null,
  setPushTarget: (pushTarget) => set({ pushTarget }),

  // Logout / reset — leaves the app on the login screen (bootStatus 'ready').
  reset: () =>
    set({
      bootStatus: 'ready',
      authToken: null,
      user: null,
      pairingStatus: 'unpaired',
      pairingError: null,
      pet: null,
      wsStatus: 'disconnected',
      lockState: 'none',
      lockDetails: { until: null, timezone: null },
      hudVideoState: null,
      isWalkModalVisible: false,
      isCleaningOverlayVisible: false,
      isAlbumVisible: false,
      isTrainingVisible: false,
      playOverlay: null,
      pushTarget: null,
    }),
}));
