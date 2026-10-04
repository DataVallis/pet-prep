/**
 * Global application state using Zustand.
 * Manages: pairing status, WebSocket connection state, current pet data.
 */

import { create } from 'zustand';
import type { Pet, PetUpdatedBroadcast } from '@/types';

export interface AppUser {
  id: number;
  name: string;
  email: string;
  role: 'parent' | 'child';
}

export type PairingStatus = 'unpaired' | 'pairing' | 'paired' | 'error';

export type WebSocketStatus = 'disconnected' | 'connecting' | 'connected' | 'reconnecting';

export type LockState = 'none' | 'hard_stop' | 'illness' | 'game_over';

/**
 * App launch state (M1-12): `restoring` while the saved token is checked,
 * `offline` when the check could not reach the server (token kept, retry offered),
 * `ready` once routing can happen (signed in or showing login).
 */
export type BootStatus = 'restoring' | 'offline' | 'ready';

export interface SignInPayload {
  token: string;
  user: AppUser;
  pet: Pet | null;
}

/** Lock state that can be derived from a pet snapshot (hard stop isn't on the pet yet — M1-16). */
export function lockStateFromPet(pet: Pet | null, now: number = Date.now()): LockState {
  if (!pet) return 'none';
  if (pet.is_game_over) return 'game_over';
  if (pet.illness_until && Date.parse(pet.illness_until) > now) return 'illness';
  return 'none';
}

/**
 * The pet is paired but unborn until the child signs the contract (M1-07b): the app
 * must show the contract step, not the HUD. `awaiting_contract` wins when present
 * (child state, broadcasts); the raw pet from login / `/api/user` only has `born_at`.
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
  updatePetFromBroadcast: (broadcast: PetUpdatedBroadcast) => void;

  // WebSocket
  wsStatus: WebSocketStatus;
  setWsStatus: (status: WebSocketStatus) => void;

  // Lock state (hard stop, illness, game over)
  lockState: LockState;
  setLockState: (state: LockState) => void;

  // UI state
  isWalkModalVisible: boolean;
  setWalkModalVisible: (visible: boolean) => void;
  isCleaningOverlayVisible: boolean;
  setCleaningOverlayVisible: (visible: boolean) => void;

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
  signIn: ({ token, user, pet }) =>
    set({
      authToken: token,
      user: { id: user.id, name: user.name, email: user.email, role: user.role },
      pet,
      pairingStatus: pet ? 'paired' : 'unpaired',
      pairingError: null,
      lockState: lockStateFromPet(pet),
      bootStatus: 'ready',
    }),

  // App launch
  bootStatus: 'restoring',
  setBootStatus: (bootStatus) => set({ bootStatus }),

  // Pet data
  pet: null,
  setPet: (pet) => set({ pet }),
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
        awaiting_contract: broadcast.awaiting_contract ?? state.pet.awaiting_contract,
        born_at: broadcast.born_at !== undefined ? broadcast.born_at : state.pet.born_at,
      };

      // Determine lock state from broadcast
      let lockState: LockState = 'none';
      if (broadcast.is_game_over) {
        lockState = 'game_over';
      } else if (broadcast.is_ill) {
        lockState = 'illness';
      }

      return { pet: updatedPet, lockState };
    }),

  // WebSocket
  wsStatus: 'disconnected',
  setWsStatus: (status) => set({ wsStatus: status }),

  // Lock state
  lockState: 'none',
  setLockState: (lockState) => set({ lockState }),

  // UI state
  isWalkModalVisible: false,
  setWalkModalVisible: (isWalkModalVisible) => set({ isWalkModalVisible }),
  isCleaningOverlayVisible: false,
  setCleaningOverlayVisible: (isCleaningOverlayVisible) => set({ isCleaningOverlayVisible }),

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
      isWalkModalVisible: false,
      isCleaningOverlayVisible: false,
    }),
}));
