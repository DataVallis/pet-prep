/**
 * App-metadata locales (app.json `locales`): iOS permission texts (NS…UsageDescription) must
 * sit under the `ios` key. Top-level keys go to BOTH platforms; on Android they land in
 * `values-b+sl/strings.xml` without a default-locale string and lint fails the release build
 * (ExtraTranslation — first Android build, 2026-10-19).
 */
import appJson from '../../../app.json';

describe('app.json locales', () => {
  const locales = (appJson.expo as { locales?: Record<string, string> }).locales ?? {};

  it.each(Object.entries(locales))('%s keeps platform keys only', (_lang, file) => {
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    const json = require(`../../../${file.replace(/^\.\//, '')}`) as Record<string, unknown>;
    expect(Object.keys(json).every((key) => key === 'ios' || key === 'android')).toBe(true);
  });
});
