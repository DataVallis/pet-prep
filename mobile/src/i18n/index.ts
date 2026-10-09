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

// First: `Intl.PluralRules` polyfill (Hermes) — i18next reads it when resolving plurals.
import { warnIfPluralRulesMissing } from './pluralRules';
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

warnIfPluralRulesMissing();

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

// ── Species texts (M5-R06-08b, M5-R06_PLAN T8) ──────────────────
//
// The dog texts stay where they are; a cat's differing child texts live in
// `cat:override.<namespace>.<key>` (the cat is "muca", feminine). While the child app
// shows a cat (`setTextSpecies('cat')`, set by the child screens from the pet's
// `species`), `t('child:hud.loading')` reads `cat:override.child.hud.loading` when that
// key exists, otherwise the dog text (species-neutral texts need no override). Never set
// on the parent side — a family may have a dog and a cat; parent helpers take the species.

/** Namespaces whose keys a cat may override (child-facing only). */
const SPECIES_NAMESPACES: ReadonlySet<string> = new Set(['child', 'behaviour', 'play', 'contract', 'pet', 'push']);

let textSpecies: 'cat' | null = null;

/** The child app's pet species for texts: `'cat'` switches on the cat overrides; anything else = dog texts. */
export function setTextSpecies(species: string | null | undefined): void {
  textSpecies = species === 'cat' ? 'cat' : null;
}

export function getTextSpecies(): 'cat' | null {
  return textSpecies;
}

/** The key to read for the current text species (the cat override when it exists). */
export function speciesKey(key: string, options?: unknown): string {
  if (textSpecies !== 'cat') return key;
  const colon = key.indexOf(':');
  if (colon <= 0 || !SPECIES_NAMESPACES.has(key.slice(0, colon))) return key;
  const override = `cat:override.${key.slice(0, colon)}.${key.slice(colon + 1)}`;
  const exists = i18n.exists as unknown as (k: string, o?: unknown) => boolean;
  return exists(override, options) ? override : key;
}

/** `child:hud.loading` → `cat:override.child.hud.loading` (null for a key without a namespace). */
export function catOverrideKey(key: string): string | null {
  const colon = key.indexOf(':');
  return colon > 0 ? `cat:override.${key.slice(0, colon)}.${key.slice(colon + 1)}` : null;
}

/**
 * Parent-side species texts (M5-R06-08c): the species comes from the pet being shown
 * (a family may have a dog and a cat), never from the child's global switch. For a cat,
 * reads `cat:override.<ns>.<key>` when it exists (any namespace, e.g. `family:petStatus.ill`),
 * otherwise the dog text; for any other species exactly the dog text.
 */
export function tSpecies(key: string, species: string | null | undefined, options?: Record<string, unknown>): string {
  const translate = i18n.t as unknown as (k: string, o?: unknown) => string;
  if (species === 'cat') {
    const override = catOverrideKey(key);
    const exists = i18n.exists as unknown as (k: string, o?: unknown) => boolean;
    if (override !== null && exists(override, options)) return translate(override, options);
  }
  return translate(key, options);
}

/** Translate outside React (helpers, error mappers). Bound to the shared instance; species-aware (above). */
export const t = ((...args: unknown[]) => {
  const [key, ...rest] = args;
  const resolved = typeof key === 'string' ? speciesKey(key, rest[0]) : key;
  return (i18n.t as unknown as (...a: unknown[]) => string)(resolved, ...rest);
}) as unknown as typeof i18n.t;

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
