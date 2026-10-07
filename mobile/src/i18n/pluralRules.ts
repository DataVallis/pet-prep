/**
 * `Intl.PluralRules` for i18next plurals (M1-18 review). Hermes may ship without it; i18next
 * then falls back to one/other and Slovenian `_two` / `_few` forms would never be chosen.
 *
 * The FormatJS polyfill only installs itself when the engine lacks (or has a broken)
 * `Intl.PluralRules`; the locale data registers en + sl only with the polyfill. Imported by
 * `./index.ts` BEFORE i18next is initialised.
 */

import '@formatjs/intl-pluralrules/polyfill.js';
import '@formatjs/intl-pluralrules/locale-data/en.js';
import '@formatjs/intl-pluralrules/locale-data/sl.js';

/** CLDR cardinal categories of `locale`, sorted; [] when `Intl.PluralRules` is unavailable. */
export function pluralCategories(locale: string): string[] {
  if (typeof Intl === 'undefined' || typeof Intl.PluralRules !== 'function') return [];
  try {
    return [...new Intl.PluralRules(locale).resolvedOptions().pluralCategories].sort();
  } catch {
    return [];
  }
}

/** Development only: say loudly when Slovenian plurals cannot work on this engine. */
export function warnIfPluralRulesMissing(): void {
  if (!__DEV__) return;
  const sl = pluralCategories('sl');
  if (!['few', 'one', 'other', 'two'].every((c) => sl.includes(c))) {
    console.warn('[i18n] Intl.PluralRules is missing or lacks Slovenian data — plurals fall back to one/other.');
  }
}
