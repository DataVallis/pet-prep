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
  lockedFreeOnlyA11y: (breed: string) => t('pet:picker.lockedFreeOnlyA11y', { breed }),
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
 * free plan — or before a plan is chosen — only the mutt. The challenge is only with a
 * paid breed (M5-F03, David 2026-10-07): the mutt is the free plan's dog, so it is locked
 * when the challenge is chosen (server 422 `challenge_requires_paid_breed`). `serverLocked`
 * = breeds the server refused for this choice (422 `breed_locked`).
 */
export function lockedBreedsFor(plan: PetPlanType | null, serverLocked: readonly PetBreed[] = []): PetBreed[] {
  const base = plan === 'challenge' ? PICKER_BREEDS.filter((b) => !PREMIUM_BREEDS.includes(b)) : [...PREMIUM_BREEDS];
  return [...new Set([...base, ...serverLocked])];
}

/**
 * Why a breed can't be picked with this plan — for the note and the a11y label:
 * `free_only` = the mutt while the challenge is chosen, `challenge_only` = a premium breed on
 * the free plan (or before a plan), `server` = the server refused it; null = pickable.
 */
export type BreedLockReason = 'free_only' | 'challenge_only' | 'server';

export function breedLockReason(
  breed: PetBreed,
  plan: PetPlanType | null,
  serverLocked: readonly PetBreed[] = [],
): BreedLockReason | null {
  const premium = PREMIUM_BREEDS.includes(breed);
  if (plan === 'challenge' && !premium) return 'free_only';
  if (plan !== 'challenge' && premium) return 'challenge_only';
  return serverLocked.includes(breed) ? 'server' : null;
}

/**
 * The choice after the parent picks `plan`: the free plan is always the mutt; the challenge
 * never keeps a breed it locks (the mutt) — it moves to the first pickable breed (today the
 * Border Collie), so switching plans never leaves an invalid selection behind. When no
 * breed is pickable the breed stays and {@link completeChoice} stays null.
 */
export function choiceWithPlan(choice: PickerChoice, plan: PetPlanType, serverLocked: readonly PetBreed[] = []): PickerChoice {
  if (plan === 'free') return { ...choice, plan, breed: 'mutt' };
  const locked = lockedBreedsFor(plan, serverLocked);
  if (!isBreedLocked(choice.breed, locked)) return { ...choice, plan };
  const firstOpen = PICKER_BREEDS.find((b) => !isBreedLocked(b, locked));
  return { ...choice, plan, breed: firstOpen ?? choice.breed };
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
