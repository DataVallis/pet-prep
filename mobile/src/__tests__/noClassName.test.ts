/**
 * Guard (2026-10-05): the app is styled with React Native `StyleSheet` only.
 *
 * NativeWind `className` styles were silently dropped in the production (TestFlight)
 * build — the walk overlay rendered as plain black text — because the worklets Babel
 * plugin that babel-preset-expo adds automatically conflicts with NativeWind's
 * `jsxImportSource`. NativeWind was removed (docs/product/DECISIONS.md); this test fails
 * if a `className` prop or a NativeWind / Tailwind import comes back.
 */

import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

const MOBILE_ROOT = join(__dirname, '..', '..');
const SRC = join(MOBILE_ROOT, 'src');
const SELF = __filename;

function sourceFiles(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name);
    if (statSync(path).isDirectory()) return sourceFiles(path);
    return /\.(tsx?|jsx?)$/.test(name) && path !== SELF ? [path] : [];
  });
}

const FILES = [...sourceFiles(SRC), join(MOBILE_ROOT, 'App.tsx'), join(MOBILE_ROOT, 'index.ts')];

/** `className=` / `contentContainerClassName=` etc. as a JSX prop. */
const CLASS_NAME_PROP = /\b\w*[cC]lassName\s*=/;
const NATIVEWIND_IMPORT = /from\s+['"](nativewind|react-native-css-interop|tailwindcss)(\/[^'"]*)?['"]|import\s+['"][^'"]+\.css['"]/;

function offenders(pattern: RegExp): string[] {
  return FILES.flatMap((file) =>
    readFileSync(file, 'utf8')
      .split('\n')
      .flatMap((line, i) => (pattern.test(line) ? [`${relative(MOBILE_ROOT, file)}:${i + 1}: ${line.trim()}`] : [])),
  );
}

describe('styling guard', () => {
  it('scans the app sources', () => {
    expect(FILES.length).toBeGreaterThan(50);
    expect(FILES.some((f) => f.endsWith('ChildHudScreen.tsx'))).toBe(true);
  });

  it('has no className props (use StyleSheet)', () => {
    expect(offenders(CLASS_NAME_PROP)).toEqual([]);
  });

  it('imports neither NativeWind / Tailwind nor CSS files', () => {
    expect(offenders(NATIVEWIND_IMPORT)).toEqual([]);
  });

  it('keeps NativeWind out of the build config', () => {
    for (const config of ['babel.config.js', 'metro.config.js', 'package.json']) {
      const text = readFileSync(join(MOBILE_ROOT, config), 'utf8');
      expect({ config, nativewind: /nativewind|tailwindcss/.test(text) }).toEqual({ config, nativewind: false });
    }
  });

  it('detects the patterns it guards against', () => {
    expect(CLASS_NAME_PROP.test('<View className="flex-1">')).toBe(true);
    expect(CLASS_NAME_PROP.test('<ScrollView contentContainerClassName="p-4">')).toBe(true);
    expect(CLASS_NAME_PROP.test('const styles = StyleSheet.create({})')).toBe(false);
    expect(NATIVEWIND_IMPORT.test("import { styled } from 'nativewind';")).toBe(true);
    expect(NATIVEWIND_IMPORT.test("import './src/global.css';")).toBe(true);
  });
});
