/**
 * Parent "Izberi kužka" (M5-R04, REALISM_SPEC §1 / §7, PRODUCT_SPEC §3): breed, origin
 * and age at arrival of a **new** pet, chosen before the child's PIN. The choice goes to
 * `POST /api/parent/generate-pin` as the full set `{breed, origin, age_stage}` — the
 * server contract is all or nothing.
 *
 * Descriptions use only facts from PRODUCT_SPEC §4 / §5 and REALISM_SPEC (sourced meal
 * counts; walk lengths and behaviour stay qualitative — the minutes are unverified
 * proposals). Behaviour events (accidents, shyness) are M5-R02 → "pride kmalu".
 */

import type { LifeStage, NewPetProfile, PetBreed, PetOrigin } from '@/api/client';

export const PICKER_BREEDS: readonly PetBreed[] = ['mutt', 'border_collie'];

/**
 * Breeds that are part of the paid challenge. They are shown but cannot be picked for a
 * new pet: the server answers 422 `breed_locked` (premium is purchase-only; a purchase
 * upgrades an existing pet). Hard-coded until the API exposes a breed catalogue with
 * lock state (backend gap, see HANDOFF).
 */
export const PREMIUM_BREEDS: readonly PetBreed[] = ['border_collie'];

/** User-visible strings of the picker (i18n with M1-18). */
export const PICKER_STRINGS = {
  title: (name: string) => `Izberi kužka za: ${name}`,
  intro: 'Izbira določa, kako bo skrb za kužka videti — kot pri pravem psu.',
  breedTitle: 'Pasma',
  breeds: { mutt: 'Mešanček', border_collie: 'Border collie' } satisfies Record<PetBreed, string>,
  breedHints: {
    mutt: 'Brezplačen v vseh kombinacijah.',
    border_collie: 'Del plačljivega izziva.',
  } satisfies Record<PetBreed, string>,
  breedLockedNote:
    'Plačljive pasme se odklenejo samo z nakupom. Za začetek izberite mešančka — brezplačen je pri vsakem izvoru in starosti.',
  lockedA11y: (breed: string) => `${breed}, zaklenjeno — del plačljivega izziva`,
  originTitle: 'Od kod pride',
  origins: { bought: 'Kupljen (vzreditelj)', adopted: 'Posvojen (zavetišče)' } satisfies Record<PetOrigin, string>,
  originHints: {
    bought: 'Od vzreditelja; praviloma pride kot mladiček.',
    adopted: 'Iz zavetišča; lahko je starejši, preteklost ni znana. Vpliv na vedenje (npr. plašnost) pride kmalu.',
  } satisfies Record<PetOrigin, string>,
  ageTitle: 'Starost ob prihodu',
  ages: { puppy: 'Mladiček', young: 'Mlad pes', adult: 'Odrasel', senior: 'Starejši' } satisfies Record<LifeStage, string>,
  ageHints: {
    puppy: 'Pride star 2 meseca. 4 obroki na dan (nato 3, pozneje 2), krajši sprehodi, veliko spanja. Nezgode v hiši pridejo kmalu.',
    young: '2 obroka na dan, sprehodi se daljšajo do dolžine odraslega psa.',
    adult: '2 obroka na dan, polni dnevni sprehodi.',
    senior: '2 obroka na dan, mirnejši, krajši sprehodi in več spanja.',
  } satisfies Record<LifeStage, string>,
  quietHoursNote: 'Obrok, ki pade v celoti v tihe ure (šola, spanje), nahrani starš.',
  missing: 'Izberite izvor in starost.',
  confirm: 'Ustvari kodo',
} as const;

export const PICKER_ORIGINS: readonly PetOrigin[] = ['bought', 'adopted'];
export const PICKER_AGES: readonly LifeStage[] = ['puppy', 'young', 'adult', 'senior'];

/** What the parent has picked so far (origin and age have no default — a deliberate choice). */
export interface PickerChoice {
  breed: PetBreed;
  origin: PetOrigin | null;
  age_stage: LifeStage | null;
}

export const INITIAL_PICKER_CHOICE: PickerChoice = { breed: 'mutt', origin: null, age_stage: null };

export function isBreedLocked(breed: PetBreed, locked: readonly PetBreed[] = PREMIUM_BREEDS): boolean {
  return locked.includes(breed);
}

/** The full set for generate-pin, or null while anything is missing or the breed is locked. */
export function completeChoice(choice: PickerChoice, locked: readonly PetBreed[] = PREMIUM_BREEDS): NewPetProfile | null {
  if (choice.origin === null || choice.age_stage === null || isBreedLocked(choice.breed, locked)) return null;
  return { breed: choice.breed, origin: choice.origin, age_stage: choice.age_stage };
}
