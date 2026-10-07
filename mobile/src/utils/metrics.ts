/**
 * Utility functions for metric color interpolation and formatting.
 * Meter colours follow CGP v2 (`brand/README.md`): good = mint, mid = #FFD15C, low = #FF7A6B.
 */

import { meter } from '@/theme/colors';
import { currentLanguageTag } from '@/i18n';

/**
 * Get the color for a metric level (0–100).
 * Mint (≥ 60) → yellow (30–59) → coral (< 30).
 * Returns a hex color string suitable for React Native styles.
 */
export function getMetricColor(level: number): string {
  if (level >= 60) {
    // Green to Amber: interpolate from 100% (green) to 60% (green-amber)
    return meter.good;
  } else if (level >= 30) {
    // Amber zone
    return meter.mid;
  } else {
    // Red zone (<30%)
    return meter.low;
  }
}

/**
 * Interpolate between two hex colors.
 * Used for smooth gradient transitions on progress bars.
 */
export function interpolateColor(
  level: number,
  highColor: string = meter.good,
  midColor: string = meter.mid,
  lowColor: string = meter.low,
): string {
  if (level >= 50) {
    // Interpolate between high (100%) and mid (50%)
    const t = (100 - level) / 50; // 0 at 100%, 1 at 50%
    return lerpColor(highColor, midColor, t);
  } else {
    // Interpolate between mid (50%) and low (0%)
    const t = (50 - level) / 50; // 0 at 50%, 1 at 0%
    return lerpColor(midColor, lowColor, t);
  }
}

function hexToRgb(hex: string): [number, number, number] {
  const r = parseInt(hex.slice(1, 3), 16);
  const g = parseInt(hex.slice(3, 5), 16);
  const b = parseInt(hex.slice(5, 7), 16);
  return [r, g, b];
}

function rgbToHex(r: number, g: number, b: number): string {
  const toHex = (n: number) => Math.round(n).toString(16).padStart(2, '0');
  return `#${toHex(r)}${toHex(g)}${toHex(b)}`;
}

function lerpColor(a: string, b: string, t: number): string {
  const [r1, g1, b1] = hexToRgb(a);
  const [r2, g2, b2] = hexToRgb(b);
  return rgbToHex(
    r1 + (r2 - r1) * t,
    g1 + (g2 - g1) * t,
    b1 + (b2 - b1) * t,
  );
}

/**
 * Format a step count with the current language's grouping (M1-18).
 * e.g., 5120 → "5,120" (en), 10000 → "10.000" (sl; CLDR leaves 4 digits ungrouped in sl).
 * Unused by the app today (the HUD uses `formatSteps`).
 */
export function formatStepCount(steps: number): string {
  return steps.toLocaleString(currentLanguageTag());
}

/**
 * Check if an action button should be disabled based on pet state.
 */
export function isActionDisabled(
  actionType: 'feed' | 'water' | 'walk' | 'clean',
  petState: string,
  isLocked: boolean,
): boolean {
  if (isLocked) return true;
  if (petState === 'sick') return true;
  if (petState === 'sleeping') return true;

  // Walk is always available if not locked/sleeping/sick
  if (actionType === 'walk') return false;

  // Feed/Water/Clean are available if not locked
  return false;
}
