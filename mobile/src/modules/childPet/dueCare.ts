/**
 * M5-T20 soft order (David 2026-11-24, PRODUCT_SPEC §8): only the NEXT care step glows — the ONE
 * solid-mint `ActionButton due` of the child HUD. Everything stays pressable: there is no hard
 * order (the server's own rules decide what is accepted; the glow only guides).
 *
 * Priority: the puppy must go out (countdown due) > a mess to scrub > the cat's litter > food
 * (`rules.feed.enabled`) > water — W1 (David): water glows when it can be refilled
 * (`rules.water.enabled`) and the bowl is not full (`thirst < 100`). A lock → nothing glows.
 *
 * The only source of the HUD's `due` for care buttons (mobile/CLAUDE.md »Due care button«).
 */

import { needsScrubbing, takeOutCountdown } from '@/modules/behaviour/behaviour';
import { isCatView, scoopDock } from '@/modules/catCare/catHud';
import { actionRule } from '@/modules/childPet/actionRules';
import type { ChildPetView } from '@/modules/childPet/childPetView';

export type DueCareAction = 'take_out' | 'clean' | 'scoop' | 'feed' | 'water';

export interface DueCareContext {
  /** Server time now (device clock + skew) — for the take-out countdown. */
  nowMs: number;
}

/** The care that glows now, or null. */
export function dueCare(view: ChildPetView, ctx: DueCareContext): DueCareAction | null {
  if (view.lock.is_locked) return null;
  const clock = view.behaviour.take_out;
  if (clock !== null && actionRule(view, 'take_out').enabled && takeOutCountdown(clock, ctx.nowMs, view.timezone)?.due === true) return 'take_out';
  if (needsScrubbing(view.pet.needs_cleaning, view.behaviour)) return 'clean';
  if (isCatView(view)) {
    const scoop = scoopDock(view);
    if (scoop.due && !scoop.disabled) return 'scoop';
  }
  if (actionRule(view, 'feed').enabled) return 'feed';
  if (actionRule(view, 'water').enabled && view.pet.thirst_level < 100) return 'water';
  return null;
}
