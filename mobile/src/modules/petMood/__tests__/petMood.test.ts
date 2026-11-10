import { i18n } from '@/i18n';
import { normalizePet } from '@/modules/family/family';
import { familyPetMood, moodText, petMoodOf, type PetMoodInput, type PetMoodKind } from '@/modules/petMood/petMood';
import { readPetState } from '@/modules/petMood/readPetState';
import type { LockReason } from '@/modules/childPet/childPetView';
import type { PetState } from '@/types';
import { makeBehaviourEvent, makeFamilyPet } from '@/test-utils/fixtures';

const base: PetMoodInput = { petState: 'idle', lockReason: null, scene: null, hunger: 80, thirst: 80 };

function kindOf(input: Partial<PetMoodInput>): PetMoodKind {
  return petMoodOf({ ...base, ...input }).kind;
}

function text(kind: PetMoodKind, species: 'dog' | 'cat'): string {
  return moodText({ kind }, species);
}

afterEach(async () => {
  await i18n.changeLanguage('sl');
});

describe('petMoodOf (David 2026-11-05, option A)', () => {
  it.each<[PetState, PetMoodKind]>([
    ['idle', 'idle'],
    ['playing', 'playing'],
    ['sleeping', 'sleeping'],
    ['low_energy', 'low_energy'],
    ['hungry', 'hungry'],
    ['sick', 'sick'],
  ])('pet state %s → %s', (petState, kind) => {
    expect(kindOf({ petState })).toBe(kind);
  });

  it('hungry: thirst ≤ 30 and lower than hunger → thirsty; otherwise hungry', () => {
    expect(kindOf({ petState: 'hungry', hunger: 70, thirst: 25 })).toBe('thirsty');
    expect(kindOf({ petState: 'hungry', hunger: 30, thirst: 30 })).toBe('hungry');
    expect(kindOf({ petState: 'hungry', hunger: 10, thirst: 20 })).toBe('hungry');
    expect(kindOf({ petState: 'hungry', hunger: 20, thirst: 40 })).toBe('hungry');
    // Only `hungry` is refined — a low thirst never turns another server state into »Žejen«.
    expect(kindOf({ petState: 'sleeping', hunger: 90, thirst: 10 })).toBe('sleeping');
  });

  it.each<[LockReason, PetMoodKind]>([
    ['hard_stopped', 'hard_stopped'],
    ['ill', 'ill'],
    ['payment_required', 'payment_required'],
    ['game_over', 'game_over'],
    ['inactive', 'inactive'],
    ['contract_required', 'unborn'],
  ])('lock %s overrides the state and an open scene → %s', (lockReason, kind) => {
    expect(kindOf({ lockReason, petState: 'hungry', scene: 'accident' })).toBe(kind);
  });

  it('an open behaviour scene overrides the pet state (as the HUD video)', () => {
    expect(kindOf({ scene: 'accident', petState: 'hungry' })).toBe('accident');
    expect(kindOf({ scene: 'chewing' })).toBe('chewing');
    expect(kindOf({ scene: 'scratching', petState: 'sleeping' })).toBe('scratching');
  });

  it('every kind has a tone and an icon', () => {
    expect(petMoodOf({ ...base, petState: 'sick' })).toEqual({ kind: 'sick', tone: 'danger', icon: 'thermometer' });
    expect(petMoodOf({ ...base, petState: 'hungry', thirst: 10 })).toEqual({ kind: 'thirsty', tone: 'warn', icon: 'droplet' });
    expect(petMoodOf({ ...base, lockReason: 'hard_stopped' })).toEqual({ kind: 'hard_stopped', tone: 'neutral', icon: 'pause' });
  });
});

describe('moodText', () => {
  const ALL: readonly PetMoodKind[] = [
    'idle', 'playing', 'sleeping', 'low_energy', 'hungry', 'thirsty', 'sick',
    'accident', 'chewing', 'scratching', 'hard_stopped', 'ill', 'payment_required', 'game_over', 'inactive', 'unborn',
  ];

  it('Slovenian — dog', () => {
    expect(ALL.map((k) => text(k, 'dog'))).toEqual([
      'Zadovoljen', 'Igriv', 'Spi', 'Utrujen — rabi sprehod', 'Lačen', 'Žejen', 'Bolan',
      'Luža na tleh — počisti', 'Pregrizen copat — pospravi', 'Opraskan kavč', 'Igra ustavljena',
      'Kuža je pri veterinarju', 'Igra počaka na starša', 'Kuža je v zavetišču', 'Igra ni aktivna', 'Kuža čaka na podpis pogodbe',
    ]);
  });

  it('Slovenian — cat (feminine; the energy meter is play)', () => {
    expect(text('idle', 'cat')).toBe('Zadovoljna');
    expect(text('playing', 'cat')).toBe('Igriva');
    expect(text('low_energy', 'cat')).toBe('Utrujena — rabi igro');
    expect(text('hungry', 'cat')).toBe('Lačna');
    expect(text('thirsty', 'cat')).toBe('Žejna');
    expect(text('sick', 'cat')).toBe('Bolna');
    expect(text('sleeping', 'cat')).toBe('Spi');
    expect(text('ill', 'cat')).toBe('Muca je pri veterinarju');
    expect(text('game_over', 'cat')).toBe('Muca je v zavetišču');
    expect(text('unborn', 'cat')).toBe('Muca čaka na podpis pogodbe');
  });

  it('English — every kind has a text, the cat needs play', async () => {
    await i18n.changeLanguage('en');
    for (const kind of ALL) {
      expect(text(kind, 'dog').length).toBeGreaterThan(0);
      expect(text(kind, 'dog')).not.toContain('pet:mood');
    }
    expect(text('low_energy', 'dog')).toBe('Tired — needs a walk');
    expect(text('low_energy', 'cat')).toBe('Tired — needs play');
    expect(text('hungry', 'dog')).toBe('Hungry');
    expect(text('thirsty', 'dog')).toBe('Thirsty');
    expect(text('hard_stopped', 'dog')).toBe('Game paused');
  });
});

describe('familyPetMood (parent card)', () => {
  it('shows the server mood of an unlocked pet', () => {
    expect(familyPetMood(normalizePet(makeFamilyPet({ pet_state: 'low_energy' })))?.kind).toBe('low_energy');
    const thirsty = normalizePet(makeFamilyPet({ pet_state: 'hungry', metrics: { hunger: 80, thirst: 20, energy: 50, hygiene: 90 } }));
    expect(familyPetMood(thirsty)?.kind).toBe('thirsty');
  });

  it('an open mess overrides the mood', () => {
    const pet = normalizePet(
      makeFamilyPet({ pet_state: 'idle', behaviour: { take_out: null, active_events: [makeBehaviourEvent('accident')], scene: 'accident' } }),
    );
    expect(familyPetMood(pet)?.kind).toBe('accident');
  });

  it('null while a lock / payment status line is shown, or without pet_state (older server)', () => {
    expect(familyPetMood(normalizePet(makeFamilyPet({ pet_state: 'idle', is_hard_stopped: true })))).toBeNull();
    expect(familyPetMood(normalizePet(makeFamilyPet({ pet_state: 'sick', is_ill: true })))).toBeNull();
    expect(familyPetMood(normalizePet(makeFamilyPet({ pet_state: 'idle', is_game_over: true })))).toBeNull();
    expect(familyPetMood(normalizePet(makeFamilyPet({ pet_state: 'idle', awaiting_contract: true })))).toBeNull();
    expect(
      familyPetMood(
        normalizePet(
          makeFamilyPet({
            pet_state: 'idle',
            plan: { type: 'challenge', status: 'payment_required', trial_ends_at: null, paid_at: null, payments_enforced: true, display_type: 'challenge', challenge_started_at: null },
          }),
        ),
      ),
    ).toBeNull();
    const old = makeFamilyPet();
    delete (old as { pet_state?: unknown }).pet_state;
    expect(familyPetMood(normalizePet(old))).toBeNull();
  });

  it('readPetState accepts only the six pet states', () => {
    expect(readPetState('sleeping')).toBe('sleeping');
    expect(readPetState('accident')).toBeNull();
    expect(readPetState(undefined)).toBeNull();
  });
});
