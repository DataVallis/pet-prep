/**
 * Design tokens (CGP v2 "Grafit in meta", `brand/README.md`).
 * Radii: buttons 12, cards 22, sheets 30, care buttons and badges fully round.
 * Icons: Lucide, 2 px stroke. Touch targets ≥ 44 pt.
 */

export { alpha, dark, light, meter, palette } from './colors';
export { fonts, tightTracking } from './typography';

export const radius = {
  button: 12,
  input: 12,
  card: 22,
  sheet: 30,
  pill: 999,
} as const;

export const MIN_TOUCH = 44;
