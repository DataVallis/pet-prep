/**
 * M5-F10: the OTA runtime version (fingerprint) must be the same on the Mac and on the EAS
 * build server, or EAS refuses the build. `sourceSkips` in fingerprint.config.js REPLACES the
 * library default, so the default skip has to stay listed explicitly.
 */
import { SourceSkips } from 'expo/fingerprint';

// eslint-disable-next-line @typescript-eslint/no-require-imports
const config = require('../../../fingerprint.config.js') as {
  sourceSkips: number;
  ignorePaths: string[];
  googleServicesIgnorePaths: (vars: Record<string, string | undefined>, root: string) => string[];
};

describe('fingerprint.config.js', () => {
  it('keeps the default skip for the scripts `expo prebuild` rewrites on the build server', () => {
    expect(config.sourceSkips & SourceSkips.PackageJsonAndroidAndIosScriptsIfNotContainRun).toBeTruthy();
  });

  it('skips `extra` (app.config.ts writes the commit SHA there)', () => {
    expect(config.sourceSkips & SourceSkips.ExpoConfigExtraSection).toBeTruthy();
  });

  it('never hashes google-services.json (only on the build server)', () => {
    expect(config.ignorePaths).toContain('**/google-services.json');
  });

  it('also ignores the EAS file env var path (stored outside mobile/ under another name)', () => {
    expect(
      config.googleServicesIgnorePaths({ GOOGLE_SERVICES_JSON: '/home/expo/workingdir/env/__GOOGLE_SERVICES_JSON' }, '/home/expo/workingdir/build/mobile'),
    ).toEqual(['**/google-services.json', '../../env/__GOOGLE_SERVICES_JSON']);
    expect(config.googleServicesIgnorePaths({}, '/x/mobile')).toEqual(['**/google-services.json']);
  });
});
