/**
 * Dog grooming (M5-R13, David 2026-10-12 "kot pri mački"): a brushed dog brushes exactly like a
 * brushed cat — the server's `grooming` block (week goal per breed, matted coat, `can_start`,
 * `blocked_reason`), the "Počeši" chip above the dock and the 30 s stroke mini-game
 * (`ChoreOverlay`, kind `grooming`). Only the words differ: the dog's texts live in
 * `child:grooming.*` ("Počeši kužka" / "Brush your pup"); the cat keeps `cat:chore.grooming`.
 *
 * Whether a dog brushes at all is the server's: the block is non-null only for a dog whose
 * breed has a `grooming_sessions_per_week` row and whose pet has `grooming_enabled` (both apps
 * declared `dog_grooming`). No rule is re-implemented here.
 */

import { t } from '@/i18n';
import { strings } from '@/i18n/strings';
import type { ChildPetView } from '@/modules/childPet/childPetView';

/** `child:grooming` texts (title, intro, steps, result, chip, refusals). */
export const DOG_GROOMING_STRINGS = strings('child', 'grooming', {
  matted: (seconds: number) => t('child:grooming.matted', { seconds }),
  week: (done: number, goal: number) => t('child:grooming.week', { done, goal }),
  chipA11y: (done: number, goal: number) => t('child:grooming.chipA11y', { done, goal }),
});

/** The pet is a dog (not a cat) with the server's grooming block — it brushes (M5-R13). */
export function isDogGrooming(view: Pick<ChildPetView, 'pet' | 'cat'>): boolean {
  return view.pet.species !== 'cat' && view.cat.grooming !== null;
}

/** Refusals a dog words with its own text (`child:grooming.errors.*`); the others are species-neutral. */
export const DOG_GROOMING_REFUSALS: ReadonlySet<string> = new Set([
  'grooming_not_available',
  'grooming_done_today',
  'grooming_quiet_hours',
  'grooming_session_active',
  'care_session_active',
]);
