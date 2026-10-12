/**
 * M5-F10: the OTA runtime version (fingerprint) must be the same on the Mac and on the EAS
 * build server, or EAS refuses the build. `sourceSkips` in fingerprint.config.js REPLACES the
 * library default, so the default skip has to stay listed explicitly.
 */
import { SourceSkips } from 'expo/fingerprint';

// eslint-disable-next-line @typescript-eslint/no-require-imports
const config = require('../../../fingerprint.config.js') as { sourceSkips: number; ignorePaths: string[] };

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
});
