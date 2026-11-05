/**
 * Mood label (David 2026-11-05, option A): one short line that says how the pet is right
 * now and what it needs — in the child HUD header (it replaced the »Kupljen pri
 * vzreditelju · …« line, which lives in the »O tvojem kužku« sheet) and on the parent's
 * pet card / child detail.
 *
 * Pure. Driven ONLY by what the server already decided: `pet_state` (`PetDecayService`),
 * the lock reason and the open behaviour scene. No new rule — the one refinement is
 * WHICH meter made the server say `hungry` (the server sets it when hunger OR thirst is
 * ≤ 30): thirst ≤ 30 and lower than hunger → »Žejen«, read from the same displayed meters.
 *
 * Priority (as the HUD's video, `videoStateFor`): lock › behaviour scene › pet state.
 */

import { tSpecies } from '@/i18n';
import { panelScene, type BehaviourScene } from '@/modules/behaviour/behaviour';
import type { LockReason } from '@/modules/childPet/childPetView';
import { petStatus, type FamilyPet } from '@/modules/family/family';
import { isPaymentRequired } from '@/modules/plan/plan';
import type { PetState } from '@/types';

/** The server's threshold for `hungry` (PRODUCT_SPEC §6: hunger / thirst ≤ 30). */
export const LOW_METER = 30;

export type PetMoodKind =
  | PetState
  | 'thirsty'
  | 'accident'
  | 'chewing'
  | 'scratching'
  | 'hard_stopped'
  | 'ill'
  | 'payment_required'
  | 'game_over'
  | 'inactive'
  | 'unborn';

/** Colour role of the label (status tokens only; mapped per theme by `PetMoodLabel`). */
export type PetMoodTone = 'ok' | 'warn' | 'danger' | 'neutral';

/** Lucide icon of the label (brand: Lucide, 2 px stroke — no emoji). */
export type PetMoodIcon =
  | 'moon'
  | 'battery'
  | 'food'
  | 'droplet'
  | 'thermometer'
  | 'ball'
  | 'smile'
  | 'mess'
  | 'pause'
  | 'vet'
  | 'lock'
  | 'shelter'
  | 'off'
  | 'contract';

export interface PetMood {
  kind: PetMoodKind;
  tone: PetMoodTone;
  icon: PetMoodIcon;
}

export interface PetMoodInput {
  petState: PetState;
  lockReason: LockReason | null;
  scene: BehaviourScene | null;
  /** Displayed meters (0–100), the same values the meters show. */
  hunger: number;
  thirst: number;
}

const LOOK: Readonly<Record<PetMoodKind, { tone: PetMoodTone; icon: PetMoodIcon }>> = {
  idle: { tone: 'ok', icon: 'smile' },
  playing: { tone: 'ok', icon: 'ball' },
  sleeping: { tone: 'neutral', icon: 'moon' },
  low_energy: { tone: 'warn', icon: 'battery' },
  hungry: { tone: 'warn', icon: 'food' },
  thirsty: { tone: 'warn', icon: 'droplet' },
  sick: { tone: 'danger', icon: 'thermometer' },
  accident: { tone: 'warn', icon: 'mess' },
  chewing: { tone: 'warn', icon: 'mess' },
  scratching: { tone: 'warn', icon: 'mess' },
  hard_stopped: { tone: 'neutral', icon: 'pause' },
  ill: { tone: 'danger', icon: 'vet' },
  payment_required: { tone: 'neutral', icon: 'lock' },
  game_over: { tone: 'danger', icon: 'shelter' },
  inactive: { tone: 'neutral', icon: 'off' },
  unborn: { tone: 'neutral', icon: 'contract' },
};

function mood(kind: PetMoodKind): PetMood {
  return { kind, ...LOOK[kind] };
}

function lockKind(reason: LockReason): PetMoodKind {
  return reason === 'contract_required' ? 'unborn' : reason;
}

/** Which mood to show (pure; see the module comment for the priority). */
export function petMoodOf(input: PetMoodInput): PetMood {
  if (input.lockReason !== null) return mood(lockKind(input.lockReason));
  if (input.scene !== null) return mood(input.scene);
  if (input.petState === 'hungry' && input.thirst <= LOW_METER && input.thirst < input.hunger) return mood('thirsty');
  return mood(input.petState);
}

/**
 * Text keys. Moods have their own keys (`pet:mood.*`, cat wording in
 * `cat:override.pet.mood.*`); locks reuse the wording the app already shows for them.
 */
const TEXT_KEYS: Readonly<Record<PetMoodKind, string>> = {
  idle: 'pet:mood.idle',
  playing: 'pet:mood.playing',
  sleeping: 'pet:mood.sleeping',
  low_energy: 'pet:mood.low_energy',
  hungry: 'pet:mood.hungry',
  thirsty: 'pet:mood.thirsty',
  sick: 'pet:mood.sick',
  accident: 'pet:mood.accident',
  chewing: 'pet:mood.chewing',
  scratching: 'pet:mood.scratching',
  hard_stopped: 'pet:mood.hard_stopped',
  ill: 'child:locked.illness.title',
  payment_required: 'child:locked.payment_required.title',
  game_over: 'child:locked.game_over.title',
  inactive: 'child:locked.inactive.title',
  unborn: 'family:petStatus.awaiting_contract',
};

export function moodTextKey(kind: PetMoodKind): string {
  return TEXT_KEYS[kind];
}

/** The label text for the pet's species (read at render time — follows the app language). */
export function moodText(m: Pick<PetMood, 'kind'>, species: string | null | undefined): string {
  return tSpecies(TEXT_KEYS[m.kind], species);
}

/**
 * Parent side (»Pregled družine« pet card, child detail): the MOOD only. A locked pet
 * (game over, waiting for the contract, inactive, hard stop, vet — `petStatus`) already
 * has its status line there, and a payment lock its plan badge → null, never a second
 * line. Null too when the payload has no `pet_state` (a server from before 2026-11-05).
 */
export function familyPetMood(pet: FamilyPet): PetMood | null {
  if (petStatus(pet) !== null || isPaymentRequired(pet.plan) || pet.pet_state === null) return null;
  return petMoodOf({
    petState: pet.pet_state,
    lockReason: null,
    scene: panelScene(pet.behaviour),
    hunger: pet.metrics.hunger,
    thirst: pet.metrics.thirst,
  });
}
