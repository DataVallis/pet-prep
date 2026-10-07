/**
 * App language (M1-18): English is the default, Slovenian the second language; more follow.
 * Resolution order: the user's explicit choice (SecureStore) → the device's preferred
 * languages (first supported one) → English.
 * Pure helpers only — the i18next instance lives in `./index.ts`.
 */

export const SUPPORTED_LANGUAGES = ['en', 'sl'] as const;
export type Language = (typeof SUPPORTED_LANGUAGES)[number];

export const DEFAULT_LANGUAGE: Language = 'en';

/** Name of each language in that language (shown in the picker, never translated). */
export const LANGUAGE_NAMES: Record<Language, string> = {
  en: 'English',
  sl: 'Slovenščina',
};

/** BCP-47 tag sent to the API (`Accept-Language`) and used for `Intl` formatting. */
export const LANGUAGE_TAGS: Record<Language, string> = {
  en: 'en-GB',
  sl: 'sl-SI',
};

export const LANGUAGE_STORE_KEY = 'petprep.language';

export function isLanguage(value: unknown): value is Language {
  return typeof value === 'string' && (SUPPORTED_LANGUAGES as readonly string[]).includes(value);
}

/** `sl`, `sl-SI`, `SL_si` → `sl`; anything unsupported → null. */
export function normaliseLanguage(tag: string | null | undefined): Language | null {
  if (!tag) return null;
  const code = tag.trim().toLowerCase().split(/[-_]/)[0];
  return isLanguage(code) ? code : null;
}

/** First supported language among the device's preferences, else English. */
export function pickDeviceLanguage(deviceTags: readonly (string | null | undefined)[]): Language {
  for (const tag of deviceTags) {
    const language = normaliseLanguage(tag);
    if (language) return language;
  }
  return DEFAULT_LANGUAGE;
}
