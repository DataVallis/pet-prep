/**
 * M6-U03 (FREE_PET_UPGRADE_SPEC U7, David 2026-11-15): when a parent upgrades a free pet to the
 * 12-week challenge, the parent chooses whether the Care Score so far is KEPT (the score and the
 * certificate count everything since each child started caring) or STARTS AGAIN at the purchase.
 * The 12 weeks run from the purchase either way; the choice is final.
 *
 * The scores come from the parent dashboard (each child's fair-share Care Score on this pet).
 * No score yet (no routine was expected) → nothing to choose. Default: keep when every child's
 * score reaches the certificate threshold, else start again — the parent can always change it.
 */

import type { FamilyOverview, FamilyPet } from '@/modules/family/family';

/** Server rule (ChallengeCertificateService::CERTIFICATE_MIN_SCORE): a final score of 70+ earns the certificate. */
export const CERTIFICATE_MIN_SCORE = 70;

export interface CaretakerScore {
  childId: number;
  name: string;
  score: number;
}

/** The current Care Score of every child caring for this pet (children without a score yet are left out). */
export function caretakerScores(pet: Pick<FamilyPet, 'id' | 'caretakers'> | null | undefined, family: FamilyOverview | null): CaretakerScore[] {
  if (!pet || !family) return [];
  return pet.caretakers.flatMap((c) => {
    const child = family.children.find((k) => k.id === c.child_id && k.pet_id === pet.id);
    const score = child?.care_score.score;
    return child && typeof score === 'number' ? [{ childId: child.id, name: child.name, score }] : [];
  });
}

/** The lowest of the scores (the one that decides a warning); null without a score. */
export function lowestScore(scores: readonly CaretakerScore[]): number | null {
  return scores.length === 0 ? null : Math.min(...scores.map((s) => s.score));
}

/** The preselected choice: null = nothing to choose; true = keep (every score ≥ 70); false = start again. */
export function defaultKeepScore(scores: readonly CaretakerScore[]): boolean | null {
  const lowest = lowestScore(scores);
  return lowest === null ? null : lowest >= CERTIFICATE_MIN_SCORE;
}

/** Which note belongs under the choice: a low score is being kept, or a good one is being dropped. */
export function scoreChoiceWarning(scores: readonly CaretakerScore[], keep: boolean): 'keepLow' | 'dropGood' | null {
  const lowest = lowestScore(scores);
  if (lowest === null) return null;
  if (keep) return lowest < CERTIFICATE_MIN_SCORE ? 'keepLow' : null;
  return lowest >= CERTIFICATE_MIN_SCORE ? 'dropGood' : null;
}
