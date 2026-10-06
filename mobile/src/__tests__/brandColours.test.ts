/**
 * Guard (CGP v2 "Grafit in meta", 2026-10-06): colours come from `@/theme` tokens
 * (`brand/README.md`), never hard-coded hex values in screens or components — so the
 * brand can't drift back to the retired v1 / Tailwind palette (indigo, slate, emerald…),
 * and text always renders through the brand `Text` (Instrument Sans / Bricolage).
 */

import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative, sep } from 'node:path';

const MOBILE_ROOT = join(__dirname, '..', '..');
const SRC = join(MOBILE_ROOT, 'src');

function sourceFiles(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name);
    if (statSync(path).isDirectory()) return name === '__tests__' || name === 'theme' ? [] : sourceFiles(path);
    return /\.(tsx?|jsx?)$/.test(name) ? [path] : [];
  });
}

const HEX = /['"]#[0-9a-fA-F]{3,8}['"]/;

describe('brand colour guard', () => {
  it('no hex colour literals outside src/theme', () => {
    const files = [...sourceFiles(SRC), join(MOBILE_ROOT, 'App.tsx')];
    const offenders = files.flatMap((file) =>
      readFileSync(file, 'utf8')
        .split('\n')
        .flatMap((line, i) => (HEX.test(line) ? [`${relative(MOBILE_ROOT, file).split(sep).join('/')}:${i + 1}: ${line.trim()}`] : [])),
    );
    expect(offenders).toEqual([]);
  });

  it('Text / TextInput come from @/components/ui/Text (brand fonts), not react-native', () => {
    const RN_TEXT = /import\s*\{[^}]*\bText(Input)?\b[^}]*\}\s*from\s*'react-native'/;
    const files = sourceFiles(SRC).filter((f) => !f.endsWith(join('components', 'ui', 'Text.tsx')));
    const offenders = files.filter((file) => RN_TEXT.test(readFileSync(file, 'utf8'))).map((f) => relative(MOBILE_ROOT, f));
    expect(offenders).toEqual([]);
  });

  it('the guard can fail', () => {
    expect(HEX.test("color: '#4f46e5'")).toBe(true);
  });
});
