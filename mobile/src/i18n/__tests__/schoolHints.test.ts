/**
 * School v2 praise / tap hints in every language (David 2026-10-31: »naj piše tudi v slovenskem pa
 * v vseh bodočih jezikih«). The »Zdaj!« / "Now!" hint shows in every session, so every locale
 * folder under `locales/` — including one added later — must carry non-empty `school.now`,
 * `school.tapHint` and `school.hintA11y`, and must be a registered language (so the generic parity
 * test covers it too).
 */
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';

import { SUPPORTED_LANGUAGES } from '@/i18n/language';

const LOCALES_DIR = join(__dirname, '..', 'locales');
const HINT_KEYS = ['now', 'tapHint', 'hintA11y'] as const;

const localeDirs = readdirSync(LOCALES_DIR).filter((name) => statSync(join(LOCALES_DIR, name)).isDirectory());

function schoolStrings(locale: string): Record<string, unknown> {
  const parsed: unknown = JSON.parse(readFileSync(join(LOCALES_DIR, locale, 'training.json'), 'utf8'));
  if (typeof parsed !== 'object' || parsed === null) return {};
  const school: unknown = (parsed as Record<string, unknown>).school;
  return typeof school === 'object' && school !== null ? (school as Record<string, unknown>) : {};
}

describe('school hints exist in every locale', () => {
  it('finds the locale folders', () => {
    expect(localeDirs.length).toBeGreaterThanOrEqual(2);
    expect(localeDirs).toEqual(expect.arrayContaining(['en', 'sl']));
  });

  it.each(localeDirs)('%s is a supported language', (locale) => {
    expect(SUPPORTED_LANGUAGES as readonly string[]).toContain(locale);
  });

  it.each(localeDirs)('%s has non-empty school.now, school.tapHint and school.hintA11y', (locale) => {
    const school = schoolStrings(locale);
    for (const key of HINT_KEYS) {
      const value = school[key];
      expect({ locale, key, ok: typeof value === 'string' && value.trim() !== '' }).toEqual({ locale, key, ok: true });
    }
  });
});
