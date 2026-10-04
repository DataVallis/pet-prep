/**
 * Tests for the Zustand app store.
 */

import { isAwaitingContract, lockStateFromPet, useAppStore } from '@/store/appStore';
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
    breed_type: 'mutt',
    hunger_level: 50,
    thirst_level: 50,
    energy_level: 50,
    hygiene_level: 50,
    is_active: true,
    pet_state: 'idle',
    escalation_level: 0,
    is_ill: false,
    illness_until: null,
    is_game_over: false,
    is_hard_stopped: false,
    virtual_age_months: 0,
    current_video_url: null,
    reference_image_url: null,
    event_type: 'metric_changed',
    updated_at: new Date().toISOString(),
    emitted_at: new Date().toISOString(),
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

describe('session (M1-12)', () => {
  beforeEach(() => {
    useAppStore.setState(useAppStore.getInitialState(), true);
  });

  it('starts in the restoring boot state', () => {
    expect(useAppStore.getState().bootStatus).toBe('restoring');
  });

  it('signIn sets token, user, pet, pairing status and finishes booting in one update', () => {
    const pet = createMockPet();
    useAppStore.getState().signIn({
      token: 'tok',
      user: { id: 2, name: 'Otrok', email: 'c@x.si', role: 'child' },
      pet,
    });
    const s = useAppStore.getState();
    expect(s.authToken).toBe('tok');
    expect(s.user).toEqual({ id: 2, name: 'Otrok', email: 'c@x.si', role: 'child' });
    expect(s.pet).toEqual(pet);
    expect(s.pairingStatus).toBe('paired');
    expect(s.lockState).toBe('none');
    expect(s.bootStatus).toBe('ready');
  });

  it('signIn without a pet leaves the pairing status unpaired', () => {
    useAppStore.getState().signIn({
      token: 'tok',
      user: { id: 1, name: 'Starš', email: 'p@x.si', role: 'parent' },
      pet: null,
    });
    expect(useAppStore.getState().pairingStatus).toBe('unpaired');
  });

  it('reset after a session lands on the login screen, not the splash', () => {
    useAppStore.getState().signIn({
      token: 'tok',
      user: { id: 1, name: 'Starš', email: 'p@x.si', role: 'parent' },
      pet: null,
    });
    useAppStore.getState().reset();
    const s = useAppStore.getState();
    expect(s.authToken).toBeNull();
    expect(s.user).toBeNull();
    expect(s.bootStatus).toBe('ready');
  });
});

describe('lockStateFromPet', () => {
  const now = Date.parse('2026-10-03T10:00:00Z');

  it('none without a pet or for a healthy pet', () => {
    expect(lockStateFromPet(null, now)).toBe('none');
    expect(lockStateFromPet(createMockPet(), now)).toBe('none');
  });

  it('game over wins over illness', () => {
    expect(
      lockStateFromPet(createMockPet({ is_game_over: true, illness_until: '2026-10-03T20:00:00Z' }), now),
    ).toBe('game_over');
  });

  it('illness only while illness_until is in the future', () => {
    expect(lockStateFromPet(createMockPet({ illness_until: '2026-10-03T20:00:00Z' }), now)).toBe('illness');
    expect(lockStateFromPet(createMockPet({ illness_until: '2026-10-03T09:00:00Z' }), now)).toBe('none');
  });
});

describe('isAwaitingContract (M1-07b)', () => {
  it('is false without a pet', () => {
    expect(isAwaitingContract(null)).toBe(false);
  });

  it('uses born_at when awaiting_contract is absent (raw pet from /api/user)', () => {
    expect(isAwaitingContract({ ...createMockPet(), born_at: null })).toBe(true);
    expect(isAwaitingContract({ ...createMockPet(), born_at: '2026-10-01T08:00:00Z' })).toBe(false);
  });

  it('awaiting_contract wins when present (child state, broadcast)', () => {
    expect(isAwaitingContract({ ...createMockPet(), born_at: null, awaiting_contract: false })).toBe(false);
    expect(isAwaitingContract({ ...createMockPet(), born_at: '2026-10-01T08:00:00Z', awaiting_contract: true })).toBe(true);
  });
});

describe('signed_contract broadcast (M1-07b)', () => {
  it('awaiting_contract false from a broadcast ends the contract step', () => {
    useAppStore.setState(useAppStore.getInitialState(), true);
    useAppStore.getState().setPet(createMockPet({ born_at: null }));
    useAppStore.getState().updatePetFromBroadcast(
      createMockBroadcast({ awaiting_contract: false, event_type: 'signed_contract' }),
    );
    expect(isAwaitingContract(useAppStore.getState().pet)).toBe(false);
  });
});
