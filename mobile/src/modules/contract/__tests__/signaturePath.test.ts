import {
  MAX_SVG_PATH_LENGTH,
  appendToPath,
  drawnLength,
  hasSignature,
  isValidSvgPath,
  shouldRecord,
} from '@/modules/contract/signaturePath';

describe('signaturePath', () => {
  it('starts strokes with M and continues with L, integer coordinates ≥ 0', () => {
    let path = appendToPath('', { x: 10.4, y: 20.6 }, true) ?? '';
    path = appendToPath(path, { x: -3, y: 30 }, false) ?? '';
    path = appendToPath(path, { x: 50, y: 50 }, true) ?? '';
    expect(path).toBe('M10 21 L0 30 M50 50');
    expect(isValidSvgPath(path)).toBe(true);
  });

  it('matches the backend pattern (must start with a move, commands + numbers only)', () => {
    expect(isValidSvgPath('L10 10')).toBe(false);
    expect(isValidSvgPath('M10 10 <script>')).toBe(false);
    expect(isValidSvgPath('')).toBe(false);
  });

  it('stops at the backend limit of 20,000 characters', () => {
    let path = '';
    let x = 0;
    for (;;) {
      const next = appendToPath(path, { x: x % 900, y: 100 }, path === '');
      if (next === null) break;
      path = next;
      x += 3;
    }
    expect(path.length).toBeLessThanOrEqual(MAX_SVG_PATH_LENGTH);
    expect(path.length).toBeGreaterThan(MAX_SVG_PATH_LENGTH - 12);
  });

  it('skips points closer than 2 px', () => {
    expect(shouldRecord(null, { x: 0, y: 0 })).toBe(true);
    expect(shouldRecord({ x: 0, y: 0 }, { x: 1, y: 1 })).toBe(false);
    expect(shouldRecord({ x: 0, y: 0 }, { x: 2, y: 0 })).toBe(true);
  });

  it('a tap is not a signature, a stroke is', () => {
    expect(hasSignature('M10 10')).toBe(false);
    expect(hasSignature('M10 10 L15 10')).toBe(false);
    expect(drawnLength('M10 10 L40 10 M0 0 L0 10')).toBe(40);
    expect(hasSignature('M10 10 L40 10')).toBe(true);
  });
});
