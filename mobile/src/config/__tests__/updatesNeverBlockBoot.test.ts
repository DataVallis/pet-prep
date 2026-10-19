/**
 * Boot-hang fix 2026-10-19: OTA updates must never hold the app at launch. With
 * `fallbackToCacheTimeout: 0` the native launcher starts the cached bundle at once and
 * downloads a new update in the background (applied on the next launch); the JS never
 * awaits `checkForUpdateAsync` / `fetchUpdateAsync` / `reloadAsync` on the boot path.
 */
import fs from 'fs';
import path from 'path';

import appJson from '../../../app.json';

const SRC = path.resolve(__dirname, '../..');

function sourceFiles(dir: string): string[] {
  return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) return entry.name === '__tests__' || entry.name === '__mocks__' ? [] : sourceFiles(full);
    return /\.(ts|tsx)$/.test(entry.name) ? [full] : [];
  });
}

describe('OTA updates never block startup', () => {
  it('the launcher never waits for a download (fallbackToCacheTimeout 0)', () => {
    const updates = (appJson.expo as { updates?: { fallbackToCacheTimeout?: number } }).updates;
    expect(updates?.fallbackToCacheTimeout).toBe(0);
  });

  it('no JS code awaits an update check / download / reload', () => {
    const roots = [SRC, path.resolve(SRC, '../App.tsx'), path.resolve(SRC, '../index.ts')];
    const files = roots.flatMap((root) => (fs.statSync(root).isDirectory() ? sourceFiles(root) : [root]));
    const offenders = files.filter((file) => /checkForUpdateAsync|fetchUpdateAsync|reloadAsync|from 'expo-updates'/.test(fs.readFileSync(file, 'utf8')));
    expect(offenders).toEqual([]);
  });
});
