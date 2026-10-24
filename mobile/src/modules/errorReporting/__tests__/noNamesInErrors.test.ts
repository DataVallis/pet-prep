/**
 * M5-05 (QA #375 M2, DECISIONS 2026-10-24): error messages are app-generated text and go
 * to our server's System health. The scrubber cannot recognise a free-text name, so app
 * code must never interpolate a child's nickname, a pet's name or an e-mail into an Error
 * message. This guard fails when an `Error(`…${…name…}…`)` appears in `src/`.
 */

import fs from 'fs';
import path from 'path';

const SRC = path.resolve(__dirname, '../../..');
const FORBIDDEN = /new\s+(?:[A-Z][A-Za-z]*)?Error\(\s*`[^`]*\$\{[^}]*(?:name|nickname|email|e_mail|petName|childName)[^}]*\}/i;
const ALLOWED = [
  // `channelName` is `private-pet.{id}` — an id, not a name.
  'modules/realtime/echoConfig.ts',
];

function files(dir: string): string[] {
  return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) return entry.name === '__tests__' || entry.name === '__mocks__' ? [] : files(full);
    return /\.(ts|tsx)$/.test(entry.name) ? [full] : [];
  });
}

describe('no names in error messages', () => {
  it('no Error message interpolates a name or e-mail', () => {
    const offenders = files(SRC)
      .filter((file) => !ALLOWED.some((allowed) => file.endsWith(allowed)))
      .filter((file) => FORBIDDEN.test(fs.readFileSync(file, 'utf8')))
      .map((file) => path.relative(SRC, file));
    expect(offenders).toEqual([]);
  });
});
