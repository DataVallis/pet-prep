/**
 * M5-F01 — where the parent app offers the 12-week challenge for purchase (device feedback
 * 2026-10-07: the small "Trial" badge alone was overlooked). Pure, parent app only.
 *
 * One shared rule for every purchase surface (buy button, Nadzor row, paywall list, banner):
 * `isOfferablePet` — a pet the parent sees as free (the server's `plan` / `display_type`:
 * a free-plan pet of any species, a grandfathered mutt) and a finished (game over) pet are
 * never offered a purchase (M5-R06-02: the plan decides, not `breed_type !== 'mutt'`, so a
 * free cat is never offered one). A species' free breed (mutt / domestic cat) stays excluded
 * as a safety net, even if an old payload still carries a challenge (M5-F02/F03 and the
 * backend migration move every unpaid mutt challenge to the free plan).
 *
 * The buy button additionally follows the pet's `plan` (`needsPurchase`: an unpaid challenge).
 * M3-13 (David 2026-10-08, no free trial): an unborn challenge dog gets the button too — the
 * challenge starts with a purchase, so the parent buys first (the server sends it as
 * `payment_required`; an older server sent `trial`, also covered by `needsPurchase`).
 */

import type { BillingPet } from '@/api/client';
import type { FamilyOverview, FamilyPet } from '@/modules/family/family';
import { needsPurchase, planOfBillingPet, shownAsFree } from '@/modules/plan/plan';
import { isDefaultFreeBreed } from '@/modules/species/species';

type OfferablePet = Pick<FamilyPet, 'plan' | 'is_game_over' | 'breed_type'>;
type PurchasablePet = OfferablePet;

/** Shared exclusion for every purchase surface: never a free pet (plan or free breed), never a game-over pet. */
export function isOfferablePet(pet: OfferablePet | null | undefined): boolean {
  return !!pet && !pet.is_game_over && !shownAsFree(pet.plan) && !isDefaultFreeBreed(pet.breed_type);
}

/** Show the "buy" button for this pet (overview card, child detail, Nadzor count) — born or not. */
export function canBuyChallenge(pet: PurchasablePet | null | undefined): boolean {
  if (!pet || !isOfferablePet(pet)) return false;
  return needsPurchase(pet.plan);
}

/** The family's pets the parent can buy the challenge for right now. */
export function petsAwaitingPurchase(family: FamilyOverview | null): FamilyPet[] {
  return (family?.pets ?? []).filter((pet) => canBuyChallenge(pet));
}

/**
 * A pet of `GET /api/parent/billing` the paywall lists: the billing status needs a purchase
 * and the family pet passes the shared exclusion. A billing pet missing from the family
 * overview (breed unknown) is not listed — a mutt must never be offered.
 */
export function isBillingPetPurchasable(pet: BillingPet, family: FamilyOverview | null): boolean {
  if (!needsPurchase(planOfBillingPet(pet))) return false;
  return isOfferablePet(family?.pets.find((p) => p.id === pet.pet_id));
}

/**
 * The "Purchases / challenge" row in Nadzor: shown while the family has any challenge dog
 * that may be offered (to buy, or to restore purchases on a new phone); hidden for a
 * mutt-only family.
 */
export function showPurchasesRow(family: FamilyOverview | null): boolean {
  return (family?.pets ?? []).some((pet) => pet.plan.type === 'challenge' && isOfferablePet(pet));
}
