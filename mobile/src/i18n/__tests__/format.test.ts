/** One thousands separator (`common:format.thousands`) and percent format for the whole app (M1-18 review). */
import { i18n, t } from '@/i18n';
import { formatThousands } from '@/i18n/format';

describe('formatThousands / percent', () => {
  afterEach(async () => {
    await i18n.changeLanguage('sl');
  });

  it('groups with a dot in Slovenian, also 4-digit numbers', () => {
    expect(formatThousands(4000)).toBe('4.000');
    expect(formatThousands(1234567)).toBe('1.234.567');
    expect(formatThousands(999)).toBe('999');
    expect(formatThousands(-5)).toBe('0');
    expect(formatThousands(Number.NaN)).toBe('0');
    expect(t('common:format.percent', { value: 42 })).toBe('42 %');
  });

  it('groups with a comma in English', async () => {
    await i18n.changeLanguage('en');
    expect(formatThousands(12500.7)).toBe('12,500');
    expect(t('common:format.percent', { value: 42 })).toBe('42%');
  });
});
