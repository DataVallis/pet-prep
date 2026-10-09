/**
 * Responsibility contract text per species (M5-R06-08b, CAT_SPEC §9).
 *
 * The dog's text is `contract:screen.body`; a cat's is `cat:override.contract.screen.body`
 * ("muca", feminine, litter + play instead of walks — chosen explicitly here, so it never
 * depends on the text species being set); a Maine Coon adds the brushing line
 * (`cat:contract.bodyMaineCoon`). The wording is Claude's draft of CAT_SPEC §9 (D).
 */

import { t } from '@/i18n';

/** The contract body for the child's pet (`breed` from the session pet; unknown → species text). */
export function contractBody(species: string | null | undefined, breed: string | null | undefined): string {
  if (species === 'cat') return breed === 'maine_coon' ? t('cat:contract.bodyMaineCoon') : t('cat:override.contract.screen.body');
  return t('contract:screen.body');
}
