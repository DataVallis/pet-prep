/**
 * M1-18 review: Slovenian plurals need `Intl.PluralRules` with sl data. Node has it; Hermes
 * may not — the FormatJS polyfill (`@/i18n/pluralRules`) fills the gap before i18next starts.
 */
import { pluralCategories, warnIfPluralRulesMissing } from '@/i18n/pluralRules';

describe('Intl.PluralRules for i18next', () => {
  it('resolves the Slovenian and English categories', () => {
    expect(pluralCategories('sl')).toEqual(['few', 'one', 'other', 'two']);
    expect(pluralCategories('en')).toEqual(['one', 'other']);
  });

  describe('on an engine without Intl.PluralRules (Hermes)', () => {
    const native = Intl.PluralRules;

    afterEach(() => {
      Object.defineProperty(Intl, 'PluralRules', { value: native, configurable: true, writable: true });
      jest.restoreAllMocks();
    });

    it('the polyfill installs en + sl and i18next picks the Slovenian forms', () => {
      Reflect.deleteProperty(Intl, 'PluralRules');
      jest.isolateModules(() => {
        const plural = require('@/i18n/pluralRules') as typeof import('@/i18n/pluralRules');
        expect(Intl.PluralRules).not.toBe(native);
        expect(plural.pluralCategories('sl')).toEqual(['few', 'one', 'other', 'two']);
        expect(new Intl.PluralRules('sl').select(102)).toBe('two');
        expect(new Intl.PluralRules('sl').select(103)).toBe('few');

        const { t, i18n } = require('@/i18n') as typeof import('@/i18n');
        expect(i18n.language).toBe('sl');
        expect(t('pet:profile.months', { count: 2 })).toBe('2 meseca');
        expect(t('pet:profile.months', { count: 104 })).toBe('104 mesece');
        expect(t('pet:profile.months', { count: 101 })).toBe('101 mesec');
      });
    });

    it('warns in development when plurals still cannot work', () => {
      const warn = jest.spyOn(console, 'warn').mockImplementation(() => undefined);
      Reflect.deleteProperty(Intl, 'PluralRules');
      warnIfPluralRulesMissing();
      expect(warn).toHaveBeenCalledWith(expect.stringContaining('Intl.PluralRules'));
    });
  });

  it('stays quiet when plurals work', () => {
    const warn = jest.spyOn(console, 'warn').mockImplementation(() => undefined);
    warnIfPluralRulesMissing();
    expect(warn).not.toHaveBeenCalled();
    warn.mockRestore();
  });
});
