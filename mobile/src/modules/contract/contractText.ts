/**
 * Responsibility contract text per species (M5-R06-08b, CAT_SPEC §9).
 *
 * The dog's text is `contract:screen.body`; a cat's is `cat:override.contract.screen.body`
 * ("muca", feminine, litter + play instead of walks — chosen explicitly here, so it never
 * depends on the text species being set); a cat with a brushing routine (Maine Coon, Ragdoll, Siberian, Norwegian Forest Cat, Bengal,
 * British Shorthair — `hasGroomingRoutine`) adds the brushing line (`cat:contract.bodyWithBrushing`). The wording is Claude's draft of CAT_SPEC §9 (D).
 * M5-R13 (David 2026-10-12, "kot pri mački"): a dog that brushes — its breed has a routine AND the
 * pet has `grooming_enabled` (an existing dog / a dog of an older app does not) — adds the same
 * line to the dog's text (`contract:screen.bodyWithBrushing`).
 */

import { t } from '@/i18n';
import { hasGroomingRoutine } from '@/modules/species/species';

/** The contract body for the child's pet (`breed` / `groomingEnabled` from the session pet; unknown → species text). */
export function contractBody(species: string | null | undefined, breed: string | null | undefined, groomingEnabled?: boolean | null): string {
  if (species === 'cat') return hasGroomingRoutine(breed) ? t('cat:contract.bodyWithBrushing') : t('cat:override.contract.screen.body');
  return groomingEnabled === true && hasGroomingRoutine(breed) ? t('contract:screen.bodyWithBrushing') : t('contract:screen.body');
}
