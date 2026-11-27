/**
 * »Darilo je na poti« on the pet screen (David 2026-11-27): the line the child sees while a
 * bought thing is not there yet — see `giftsOnTheWay`. One item is named (»Darilo je na
 * poti: Pasja postelja«), several are not. Never a word about pictures or videos.
 */

import { t } from '@/i18n';

import { isKnownVariant } from '@/modules/wishes/wishes';

const tr = t as unknown as (key: string, options?: Record<string, unknown>) => string;

/** Null when nothing is on its way. */
export function giftOnTheWayText(variants: readonly string[]): string | null {
  const first = variants[0];
  if (first === undefined) return null;
  // Several things, or a variant this build does not know (newer server): the line without a name.
  if (variants.length > 1 || !isKnownVariant(first)) return tr('wishes:child.giftsOnTheWay');
  return tr('wishes:child.giftOnTheWay', { item: tr(`wishes:items.${first}`) });
}
