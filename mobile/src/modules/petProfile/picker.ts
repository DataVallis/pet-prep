/**
 * Parent "Izberi kužka" (M5-R04, REALISM_SPEC §1 / §7, PRODUCT_SPEC §3): breed, origin
 * and age at arrival of a **new** pet, chosen before the child's PIN. The choice goes to
 * `POST /api/parent/generate-pin` as the full set `{breed, origin, age_stage}` — the
 * server contract is all or nothing.
 *
 * Descriptions use only sourced facts and David's confirmed decisions (PRODUCT_SPEC §4 /
 * §5, DECISIONS 2026-10-05): meals per day and the daily step goal per breed. Anything the
 * game does not simulate yet (accidents, shyness — M5-R02) is marked "pride kmalu".
 */

import type { LifeStage, NewPetProfile, PetBreed, PetOrigin, PetPlanType } from '@/api/client';
import { t } from '@/i18n';
import { strings } from '@/i18n/strings';

export const PICKER_BREEDS: readonly PetBreed[] = ['mutt', 'border_collie'];

/**
 * Breeds that are part of the paid challenge. They are shown but cannot be picked for a
 * new pet: the server answers 422 `breed_locked` (premium is purchase-only; a purchase
 * upgrades an existing pet). Hard-coded until the API exposes a breed catalogue with
 * lock state (backend gap, see HANDOFF).
 */
export const PREMIUM_BREEDS: readonly PetBreed[] = ['border_collie'];

/**
 * User-visible strings of the picker (`pet:picker`, M1-18). The `ageHints` per breed hold
 * the confirmed meals + step goals (PRODUCT_SPEC §5; minutes × 100 steps; 1 week = 1 month).
 * Puppy / young: 10 min × age in months up to the adult goal (mutt 60 min, Border Collie
 * 120 min); senior 75 % of adult. Arrival age: puppy 2, young 9 months (§4).
 */
export const PICKER_STRINGS = strings('pet', 'picker', {
  title: (name: string) => t('pet:picker.title', { name }),
  plans: {
    challenge: {
      /** "12-tedenski izziv — 7 dni brezplačno, nato 49,99 €" — price from the store when known. */
      title: (price: string) => t('pet:picker.plans.challenge.title', { price }),
    },
  },
  lockedA11y: (breed: string) => t('pet:picker.lockedA11y', { breed }),
});

export const PICKER_ORIGINS: readonly PetOrigin[] = ['bought', 'adopted'];
export const PICKER_AGES: readonly LifeStage[] = ['puppy', 'young', 'adult', 'senior'];

export const PICKER_PLANS: readonly PetPlanType[] = ['free', 'challenge'];

/** Shown in the plan choice until the store price is loaded (PAYMENTS_SPEC P1). */
export const CHALLENGE_LIST_PRICE = '49,99 €';

/** What the parent has picked so far (plan, origin and age have no default — a deliberate choice). */
export interface PickerChoice {
  plan: PetPlanType | null;
  breed: PetBreed;
  origin: PetOrigin | null;
  age_stage: LifeStage | null;
}

export const INITIAL_PICKER_CHOICE: PickerChoice = { plan: null, breed: 'mutt', origin: null, age_stage: null };

/**
 * Premium breeds are part of the 12-week challenge (M3-11: also during its trial); on the
 * free plan — or before a plan is chosen — only the mutt. `serverLocked` = breeds the
 * server refused for this choice (422 `breed_locked`).
 */
export function lockedBreedsFor(plan: PetPlanType | null, serverLocked: readonly PetBreed[] = []): PetBreed[] {
  const base = plan === 'challenge' ? [] : [...PREMIUM_BREEDS];
  return [...new Set([...base, ...serverLocked])];
}

export function isBreedLocked(breed: PetBreed, locked: readonly PetBreed[] = PREMIUM_BREEDS): boolean {
  return locked.includes(breed);
}

/** The full set for generate-pin, or null while anything is missing or the breed is locked. */
export function completeChoice(choice: PickerChoice, serverLocked: readonly PetBreed[] = []): NewPetProfile | null {
  if (choice.plan === null || choice.origin === null || choice.age_stage === null) return null;
  const breed: PetBreed = choice.plan === 'free' ? 'mutt' : choice.breed;
  if (isBreedLocked(breed, lockedBreedsFor(choice.plan, serverLocked))) return null;
  return { breed, origin: choice.origin, age_stage: choice.age_stage, plan: choice.plan };
}
