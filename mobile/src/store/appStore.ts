/**
 * Global application state using Zustand.
 * Manages: pairing status, WebSocket connection state, current pet data.
 */

import { create } from 'zustand';
import type { Pet, PetUpdatedBroadcast } from '@/types';

export type PairingStatus = 'unpaired' | 'pairing' | 'paired' | 'error';

export type WebSocketStatus = 'disconnected' | 'connecting' | 'connected' | 'reconnecting';

export type LockState = 'none' | 'hard_stop' | 'illness' | 'game_over';

interface AppStore {
  // Auth & pairing
  authToken: string | null;
  pairingStatus: PairingStatus;
  pairingError: string | null;
  setAuthToken: (token: string | null) => void;
  setPairingStatus: (status: PairingStatus, error?: string | null) => void;

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
  pairingStatus: 'unpaired',
  pairingError: null,
  setAuthToken: (token) => set({ authToken: token }),
  setPairingStatus: (status, error = null) =>
    set({ pairingStatus: status, pairingError: error }),

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

  // Logout / reset
  reset: () =>
    set({
      authToken: null,
      pairingStatus: 'unpaired',
      pairingError: null,
      pet: null,
      wsStatus: 'disconnected',
      lockState: 'none',
      isWalkModalVisible: false,
      isCleaningOverlayVisible: false,
    }),
}));
