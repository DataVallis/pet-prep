/**
 * Parent app guards (M2-05): no demo data left in the app source, and the shared
 * metric colour helpers. The traffic light itself is the server's (M2-06) — its
 * rendering is covered in ParentDashboardScreen.overview.test.tsx.
 */
import { getMetricColor, interpolateColor } from '@/utils/metrics';

// The app has no Node typings (React Native); type the few Node APIs used here.
declare const __dirname: string;
interface DirEntry {
  name: string;
  isDirectory: () => boolean;
}
const fs = jest.requireActual<{
  readdirSync: (dir: string, options: { withFileTypes: true }) => DirEntry[];
  readFileSync: (file: string, encoding: 'utf8') => string;
}>('fs');
const path = jest.requireActual<{
  resolve: (...parts: string[]) => string;
  join: (...parts: string[]) => string;
  relative: (from: string, to: string) => string;
}>('path');

const SRC = path.resolve(__dirname, '../../..');

function sourceFiles(dir: string): string[] {
  return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      return entry.name === '__tests__' || entry.name === 'test-utils' ? [] : sourceFiles(full);
    }
    return /\.(ts|tsx)$/.test(entry.name) ? [full] : [];
  });
}

describe('Parent dashboard — no demo data (M2-05)', () => {
  it('no MOCK_* constants or imports anywhere in the app source', () => {
    const files = sourceFiles(SRC);
    expect(files.length).toBeGreaterThan(20);
    const offenders = files.filter((f) => /\bMOCK_[A-Z_]+/.test(fs.readFileSync(f, 'utf8')));
    expect(offenders.map((f) => path.relative(SRC, f))).toEqual([]);
  });

  it('the dashboard no longer reads the session pet from the store', () => {
    const screen = fs.readFileSync(path.join(SRC, 'screens/parent/ParentDashboardScreen.tsx'), 'utf8');
    expect(screen).not.toMatch(/useAppStore\(\(s\) => s\.pet\)/);
  });
});

describe('Metric colours', () => {
  it('green / amber / red thresholds', () => {
    expect(getMetricColor(80)).toBe('#7FE0B4');
    expect(getMetricColor(50)).toBe('#FFD15C');
    expect(getMetricColor(15)).toBe('#FF7A6B');
  });

  it('interpolates between the reference colours', () => {
    expect(interpolateColor(100).toLowerCase()).toBe('#7fe0b4');
    expect(interpolateColor(50).toLowerCase()).toBe('#ffd15c');
    expect(interpolateColor(0).toLowerCase()).toBe('#ff7a6b');
  });
});
