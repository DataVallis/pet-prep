/**
 * i18n (M1-18): i18next + react-i18next, resources bundled per namespace in
 * `./locales/<lang>/<namespace>.json` (registered in `./resources.ts`).
 *
 * Rules for app code:
 * - Components that render text call `useTranslation()` (re-render on language change).
 * - Plain helpers (no hooks) call `t()` from this module at call time — never store a
 *   translated string in a module-level constant (it would freeze the language).
 * - Keys are typed (`./i18next.d.ts`); English is the reference language, every other
 *   language must have exactly the same keys (guarded by `__tests__/parity.test.ts`).
 * - Plurals use i18next's `count` (CLDR: en one/other, sl one/two/few/other).
 */

import i18next from 'i18next';
import { initReactI18next } from 'react-i18next';
import * as Localization from 'expo-localization';
import * as SecureStore from 'expo-secure-store';

import {
  DEFAULT_LANGUAGE,
  LANGUAGE_STORE_KEY,
  LANGUAGE_TAGS,
  SUPPORTED_LANGUAGES,
  isLanguage,
  pickDeviceLanguage,
  type Language,
} from './language';
import { NAMESPACES, resources } from './resources';

export * from './language';

export const i18n = i18next.createInstance();

function deviceLanguage(): Language {
  try {
    return pickDeviceLanguage(Localization.getLocales().map((l) => l.languageTag ?? l.languageCode));
  } catch {
    return DEFAULT_LANGUAGE;
  }
}

// Synchronous init (bundled resources): `t()` works from the first render on.
void i18n.use(initReactI18next).init({
  resources,
  lng: deviceLanguage(),
  fallbackLng: DEFAULT_LANGUAGE,
  supportedLngs: [...SUPPORTED_LANGUAGES],
  ns: [...NAMESPACES],
  defaultNS: 'common',
  interpolation: { escapeValue: false },
  returnNull: false,
  initAsync: false,
  react: { useSuspense: false },
});

/** Translate outside React (helpers, error mappers). Bound to the shared instance. */
export const t = ((...args: unknown[]) =>
  (i18n.t as unknown as (...a: unknown[]) => string)(...args)) as unknown as typeof i18n.t;

export function currentLanguage(): Language {
  const language = i18n.resolvedLanguage ?? i18n.language;
  return isLanguage(language) ? language : DEFAULT_LANGUAGE;
}

/** BCP-47 tag of the current language (`Accept-Language`, `Intl` formatters). */
export function currentLanguageTag(): string {
  return LANGUAGE_TAGS[currentLanguage()];
}

type LanguageStore = Pick<typeof SecureStore, 'getItemAsync' | 'setItemAsync'>;

/**
 * Apply the user's saved choice (if any) — call once at startup. A failing store keeps
 * the device language.
 */
let chosenThisSession = false;

export async function loadSavedLanguage(store: LanguageStore = SecureStore): Promise<Language> {
  try {
    const saved = await store.getItemAsync(LANGUAGE_STORE_KEY);
    // A slow keychain must not undo a choice the user made meanwhile.
    if (!chosenThisSession && isLanguage(saved) && saved !== currentLanguage()) await i18n.changeLanguage(saved);
  } catch {
    // keep the device language
  }
  return currentLanguage();
}

/** Switch the app language and remember the choice on this device. */
export async function setLanguage(language: Language, store: LanguageStore = SecureStore): Promise<void> {
  chosenThisSession = true;
  await i18n.changeLanguage(language);
  try {
    await store.setItemAsync(LANGUAGE_STORE_KEY, language);
  } catch {
    // the switch still applies for this session
  }
}

export default i18n;
