/**
 * M5-R16 — gap between a meal and exercise (bloat / GDV), David 2026-10-12 "1 ura, samo
 * opozorilo".
 *
 * After a meal the server sends `feeding.digesting_until` (last meal + the breed's gap —
 * Cane Corso, Dobermann, Boxer 60 min, PDSA advice; null for every other pet). Until then the HUD
 * and the walk screen show a calm "wait with the walk — your pup is digesting" note. It is
 * ONLY a warning: steps still count, the walk button stays enabled, nothing is scored and
 * the child gets no statistics. The note disappears by the clock (server time) — no refetch.
 *
 * The pet's name is never put into the sentence (M5-R08: a name is a label only).
 */

import { t } from '@/i18n';
import { strings } from '@/i18n/strings';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import { familyClock } from '@/modules/childPet/familyTime';

/** User-visible strings (`child:walk.digesting`). */
export const DIGESTION_STRINGS = strings('child', 'walk', {
  digesting: {
    until: (time: string) => t('child:walk.digesting.until', { time }),
    short: (time: string) => t('child:walk.digesting.short', { time }),
  },
}).digesting;

/** End of the warning in ms, or null (no gap for this pet, or a broken instant). */
export function digestingUntilMs(view: ChildPetView): number | null {
  const iso = view.feeding.digesting_until;
  if (!iso) return null;
  const ms = Date.parse(iso);
  return Number.isFinite(ms) ? ms : null;
}

/** The warning shows now (server time); never for a cat or while the pet is locked. */
export function isDigesting(view: ChildPetView, serverNowMs: number): boolean {
  if (view.pet.species === 'cat' || view.lock.is_locked) return false;
  const until = digestingUntilMs(view);
  return until !== null && serverNowMs < until;
}

/** Ms until the warning ends (for one re-render), or null when it isn't showing. */
export function digestionEndsInMs(view: ChildPetView, serverNowMs: number): number | null {
  if (!isDigesting(view, serverNowMs)) return null;
  return (digestingUntilMs(view) as number) - serverNowMs;
}

export interface DigestionNoteText {
  title: string;
  body: string;
  /** "Na sprehod lahko greš ob 08:30." — null when the time can't be shown. */
  until: string | null;
  /** One sentence for screen readers. */
  a11y: string;
  /** The collapsed HUD top stack's pill (David 2026-10-26): "Počakaj s sprehodom do 9:51" (title without a time). */
  short: string;
}

/** The note's texts in the current language, or null when no warning shows now. */
export function digestionNote(view: ChildPetView, serverNowMs: number): DigestionNoteText | null {
  if (!isDigesting(view, serverNowMs)) return null;
  const clock = familyClock(view.feeding.digesting_until, view.timezone);
  const title = DIGESTION_STRINGS.title;
  const body = DIGESTION_STRINGS.body;
  const until = clock !== null ? DIGESTION_STRINGS.until(clock) : null;
  return {
    title,
    body,
    until,
    a11y: [`${title}.`, body, until].filter((s) => s !== null).join(' '),
    short: clock !== null ? DIGESTION_STRINGS.short(clock) : title,
  };
}
