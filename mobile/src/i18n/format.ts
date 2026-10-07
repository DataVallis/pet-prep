/**
 * Number formatting shared by the child and parent apps (M1-18 review).
 *
 * Hand-rolled grouping with the language's separator (`common:format.thousands`) instead of
 * `Intl.NumberFormat`: the same output on every engine (Hermes' Intl differs per platform),
 * and 4-digit counts are grouped too ("4.000" in Slovenian, where CLDR would print "4000").
 */

import { t } from './index';

/** 12500 → "12.500" (sl) / "12,500" (en); negatives → 0, fractions are rounded down. */
export function formatThousands(n: number): string {
  const whole = Number.isFinite(n) ? Math.max(0, Math.floor(n)) : 0;
  return String(whole).replace(/\B(?=(\d{3})+(?!\d))/g, t('common:format.thousands'));
}
