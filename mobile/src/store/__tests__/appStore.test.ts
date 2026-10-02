/**
 * Tests for the Zustand app store.
 */

import { useAppStore } from '@/store/appStore';
import type { Pet, PetUpdatedBroadcast } from '@/types';

// Helper to create a mock pet
function createMockPet(overrides: Partial<Pet> = {}): Pet {
  return {
    id: 1,
    user_id: 1,
    breed_type: 'mutt',
    pet_dna: null,
    current_video_url: null,
    hunger_level: 100,
    thirst_level: 100,
    energy_level: 100,
    hygiene_level: 100,
    daily_step_count: 0,
    born_at: new Date().toISOString(),
    is_active: true,
    pet_state: 'idle',
    illness_until: null,
    escalation_level: 0,
    is_game_over: false,
    certificate_eligible: false,
    ...overrides,
  };
}

function createMockBroadcast(overrides: Partial<PetUpdatedBroadcast> = {}): PetUpdatedBroadcast {
  return {
    pet_id: 1,
    user_id: 1,
    breed_type: 'mutt',
    hunger_level: 50,
    thirst_level: 50,
    energy_level: 50,
    hygiene_level: 50,
    is_active: true,
    pet_state: 'idle',
    escalation_level: 0,
    is_ill: false,
    is_game_over: false,
    virtual_age_months: 0,
    current_video_url: null,
    reference_image_url: null,
    event_type: 'metric_changed',
    updated_at: new Date().toISOString(),
    ...overrides,
  };
}

describe('useAppStore', () => {
  beforeEach(() => {
    // Reset store before each test
    useAppStore.getState().reset();
  });

  describe('auth & pairing', () => {
    it('starts unpaired', () => {
      const state = useAppStore.getState();
      expect(state.authToken).toBeNull();
      expect(state.pairingStatus).toBe('unpaired');
      expect(state.pairingError).toBeNull();
    });

    it('sets auth token', () => {
      useAppStore.getState().setAuthToken('test-token');
      expect(useAppStore.getState().authToken).toBe('test-token');
    });

    it('sets pairing status with error', () => {
      useAppStore.getState().setPairingStatus('error', 'Invalid PIN');
      const state = useAppStore.getState();
      expect(state.pairingStatus).toBe('error');
      expect(state.pairingError).toBe('Invalid PIN');
    });

    it('clears error when setting non-error status', () => {
      useAppStore.getState().setPairingStatus('error', 'Invalid PIN');
      useAppStore.getState().setPairingStatus('pairing');
      expect(useAppStore.getState().pairingError).toBeNull();
    });
  });

  describe('pet data', () => {
    it('sets pet data', () => {
      const pet = createMockPet();
      useAppStore.getState().setPet(pet);
      expect(useAppStore.getState().pet).toEqual(pet);
    });

    it('updates pet from broadcast', () => {
      const pet = createMockPet({ hunger_level: 100, thirst_level: 100 });
      useAppStore.getState().setPet(pet);

      const broadcast = createMockBroadcast({ hunger_level: 25, thirst_level: 15 });
      useAppStore.getState().updatePetFromBroadcast(broadcast);

      const updatedPet = useAppStore.getState().pet;
      expect(updatedPet?.hunger_level).toBe(25);
      expect(updatedPet?.thirst_level).toBe(15);
    });

    it('sets game_over lock state from broadcast', () => {
      const pet = createMockPet();
      useAppStore.getState().setPet(pet);

      const broadcast = createMockBroadcast({ is_game_over: true });
      useAppStore.getState().updatePetFromBroadcast(broadcast);

      expect(useAppStore.getState().lockState).toBe('game_over');
    });

    it('sets illness lock state from broadcast', () => {
      const pet = createMockPet();
      useAppStore.getState().setPet(pet);

      const broadcast = createMockBroadcast({ is_ill: true });
      useAppStore.getState().updatePetFromBroadcast(broadcast);

      expect(useAppStore.getState().lockState).toBe('illness');
    });

    it('does not update pet if no pet is set', () => {
      const broadcast = createMockBroadcast();
      useAppStore.getState().updatePetFromBroadcast(broadcast);
      expect(useAppStore.getState().pet).toBeNull();
    });
  });

  describe('WebSocket status', () => {
    it('starts disconnected', () => {
      expect(useAppStore.getState().wsStatus).toBe('disconnected');
    });

    it('sets WebSocket status', () => {
      useAppStore.getState().setWsStatus('connecting');
      expect(useAppStore.getState().wsStatus).toBe('connecting');

      useAppStore.getState().setWsStatus('connected');
      expect(useAppStore.getState().wsStatus).toBe('connected');
    });
  });

  describe('UI state', () => {
    it('toggles walk modal visibility', () => {
      useAppStore.getState().setWalkModalVisible(true);
      expect(useAppStore.getState().isWalkModalVisible).toBe(true);

      useAppStore.getState().setWalkModalVisible(false);
      expect(useAppStore.getState().isWalkModalVisible).toBe(false);
    });

    it('toggles cleaning overlay visibility', () => {
      useAppStore.getState().setCleaningOverlayVisible(true);
      expect(useAppStore.getState().isCleaningOverlayVisible).toBe(true);

      useAppStore.getState().setCleaningOverlayVisible(false);
      expect(useAppStore.getState().isCleaningOverlayVisible).toBe(false);
    });
  });

  describe('reset', () => {
    it('resets all state to defaults', () => {
      // Set some state
      useAppStore.getState().setAuthToken('token');
      useAppStore.getState().setPet(createMockPet());
      useAppStore.getState().setWsStatus('connected');
      useAppStore.getState().setLockState('hard_stop');

      // Reset
      useAppStore.getState().reset();

      const state = useAppStore.getState();
      expect(state.authToken).toBeNull();
      expect(state.pet).toBeNull();
      expect(state.wsStatus).toBe('disconnected');
      expect(state.lockState).toBe('none');
      expect(state.pairingStatus).toBe('unpaired');
    });
  });
});
