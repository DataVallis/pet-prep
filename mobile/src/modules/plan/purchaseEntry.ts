/**
 * M5-F01 — where the parent app offers "12-week challenge — buy" (device feedback 2026-10-07:
 * the small "Trial" badge alone was overlooked). Pure, parent app only.
 *
 * The button follows the pet's `plan` payload, the same rule the paywall uses to list a dog
 * (`needsPurchase`: a challenge on trial or locked for payment). So it never shows for:
 * - a free plan (the mutt is free forever),
 * - a paid / grandfathered challenge,
 * - a challenge whose trial hasn't started yet (contract not signed — the paywall wouldn't list it),
 * - a finished (game over) pet.
 * A mixed breed is never offered a purchase, even if an old payload still carries a challenge
 * (M5-F02/F03 move every mutt to the free plan).
 */

import type { FamilyOverview, FamilyPet } from '@/modules/family/family';
import { needsPurchase } from '@/modules/plan/plan';

type PurchasablePet = Pick<FamilyPet, 'plan' | 'is_game_over' | 'breed_type'>;

/** Show the "buy" button for this pet (overview card, child detail). */
export function canBuyChallenge(pet: PurchasablePet | null | undefined): boolean {
  if (!pet || pet.is_game_over) return false;
  if (pet.breed_type === 'mutt') return false;
  return needsPurchase(pet.plan);
}

/** The family's pets the parent can buy the challenge for right now. */
export function petsAwaitingPurchase(family: FamilyOverview | null): FamilyPet[] {
  return (family?.pets ?? []).filter((pet) => canBuyChallenge(pet));
}

/**
 * The "Purchases / challenge" row in Nadzor: shown while the family has any challenge dog
 * (to buy, or to restore purchases on a new phone); hidden for a mutt-only family.
 */
export function showPurchasesRow(family: FamilyOverview | null): boolean {
  return (family?.pets ?? []).some((pet) => pet.plan.type === 'challenge' && pet.breed_type !== 'mutt');
}
