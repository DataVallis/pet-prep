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
 *
 * M6-U01 (FREE_PET_UPGRADE_SPEC §4, David 2026-11-10): a FREE pet (mutt / domestic cat) can be
 * upgraded to the 12-week challenge with the same pet — but only when the server says so
 * (`upgrade.available`, never decided here: born, active, not game over, not a pre-M5 legacy
 * profile). Such a pet is offerable; it gets its own button (`canUpgradePet`), and a legacy one
 * an honest note instead (`upgradeNote`, D3). An older server sends no `upgrade` → nothing changes.
 */

import type { BillingPet } from '@/api/client';
import type { FamilyOverview, FamilyPet } from '@/modules/family/family';
import { needsPurchase, planOfBillingPet, shownAsFree } from '@/modules/plan/plan';
import { isDefaultFreeBreed } from '@/modules/species/species';
import type { Billing } from '@/modules/purchases/billing';

type OfferablePet = Pick<FamilyPet, 'plan' | 'is_game_over' | 'breed_type'> & { upgrade?: FamilyPet['upgrade'] };
type PurchasablePet = OfferablePet;

/** M6-U01: a free pet the server allows to upgrade to the challenge now. */
export function canUpgradePet(pet: Pick<FamilyPet, 'plan' | 'is_game_over'> & { upgrade?: FamilyPet['upgrade'] } | null | undefined): boolean {
  return !!pet && !pet.is_game_over && pet.plan.type === 'free' && pet.upgrade?.available === true;
}

/**
 * M6-U01 / D3: the honest note shown instead of the upgrade button — today only for a pre-M5
 * legacy-profile pet (`legacy_profile`). Other reasons (not born yet, game over) show nothing:
 * the card already says why the pet is not in play.
 */
export function upgradeNote(pet: Pick<FamilyPet, 'plan' | 'is_game_over'> & { upgrade?: FamilyPet['upgrade'] } | null | undefined): 'legacy_profile' | null {
  if (!pet || pet.is_game_over || pet.plan.type !== 'free') return null;
  return pet.upgrade?.available === false && pet.upgrade.reason === 'legacy_profile' ? 'legacy_profile' : null;
}

/**
 * Shared exclusion for every purchase surface: never a game-over pet; never a free pet (plan or
 * free breed) — except a free pet the server lets the parent upgrade (M6-U01).
 */
export function isOfferablePet(pet: OfferablePet | null | undefined): boolean {
  if (!pet || pet.is_game_over) return false;
  if (canUpgradePet(pet)) return true;
  // M6-U02 (U4 / K3): »mešanček + izziv« — a free-breed pet the server keeps on an UNPAID
  // challenge waits for the purchase like a paid breed (never strand it locked without a button).
  if (isUnpaidFreeBreedChallenge(pet)) return true;
  return !shownAsFree(pet.plan) && !isDefaultFreeBreed(pet.breed_type);
}

/**
 * M6-U02: a free breed (mutt / domestic cat) on the challenge that still needs the purchase —
 * created by a new app as »mešanček + izziv«. A paid grandfathered / admin mutt shows as free
 * (`display_type`) and stays excluded.
 */
export function isUnpaidFreeBreedChallenge(pet: Pick<FamilyPet, 'plan' | 'breed_type'> | null | undefined): boolean {
  return !!pet && isDefaultFreeBreed(pet.breed_type) && !shownAsFree(pet.plan) && needsPurchase(pet.plan);
}

/** Show the "buy" button for this pet (overview card, child detail, Nadzor count) — born or not. Not for an upgrade (`canUpgradePet`). */
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

/** M6-U01: a billing pet that is a free pet both the billing and the dashboard say can be upgraded. */
export function isBillingPetUpgradeable(pet: BillingPet, family: FamilyOverview | null): boolean {
  if (pet.plan !== 'free' || pet.upgrade?.available !== true) return false;
  return canUpgradePet(family?.pets.find((p) => p.id === pet.pet_id));
}

/**
 * M6-U01 (§5.3): the family holds a purchase that is on no pet yet AND a pet it could go to (a
 * pet waiting for the purchase or an upgradeable free pet) — the dashboard shows »Imate
 * neporabljen nakup — izberite ljubljenčka« (the purchase may have landed after the app gave up
 * waiting, or the webhook could not choose). Not while a pet that is not born yet waits for a
 * purchase (P11: it gets the held one at its contract), and not again once dismissed for the
 * same number of unused purchases (`dismissedCount`, per phone — QA #978 f).
 */
export function showUnusedPurchasePrompt(billing: Billing | undefined, family: FamilyOverview | null, dismissedCount = 0): boolean {
  if (!billing || billing.credits_available <= 0) return false;
  // QA #978 f: dismissed for this many unused purchases → only a NEW one asks again.
  if (billing.credits_available <= dismissedCount) return false;
  // P11: a purchase held for a pet that is not born yet is assigned at its contract by itself —
  // not an "unused" purchase to nag about.
  const unbornWaiting = billing.pets.some((p) => p.status === 'payment_required' && family?.pets.find((f) => f.id === p.pet_id)?.born_at === null);
  if (unbornWaiting) return false;
  return billing.pets.some((p) => isBillingPetPurchasable(p, family) || isBillingPetUpgradeable(p, family));
}

/**
 * The "Purchases / challenge" row in Nadzor: shown while the family has any challenge dog
 * that may be offered (to buy, or to restore purchases on a new phone); hidden for a
 * mutt-only family.
 */
export function showPurchasesRow(family: FamilyOverview | null): boolean {
  return challengePets(family).length > 0;
}

/** The family's challenge pets that may be offered (bought or not) — what the purchase surfaces talk about. */
export function challengePets(family: FamilyOverview | null): FamilyPet[] {
  return (family?.pets ?? []).filter((pet) => pet.plan.type === 'challenge' && isOfferablePet(pet));
}
