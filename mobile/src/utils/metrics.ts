/**
 * Utility functions for metric color interpolation and formatting.
 */

/**
 * Get the color for a metric level (0–100).
 * Green (100%) → Amber (50%) → Red (<20%).
 * Returns a hex color string suitable for React Native styles.
 */
export function getMetricColor(level: number): string {
  if (level >= 60) {
    // Green to Amber: interpolate from 100% (green) to 60% (green-amber)
    return '#10B981'; // emerald-500
  } else if (level >= 30) {
    // Amber zone
    return '#F59E0B'; // amber-500
  } else {
    // Red zone (<30%)
    return '#EF4444'; // rose-500
  }
}

/**
 * Interpolate between two hex colors.
 * Used for smooth gradient transitions on progress bars.
 */
export function interpolateColor(
  level: number,
  highColor: string = '#10B981',
  midColor: string = '#F59E0B',
  lowColor: string = '#EF4444',
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
 * Format a step count with thousands separator.
 * e.g., 5120 → "5,120"
 */
export function formatStepCount(steps: number): string {
  return steps.toLocaleString('en-US');
}

/**
 * Calculate virtual age label from born_at timestamp.
 * 1 real week = 1 virtual month.
 */
export function formatVirtualAge(bornAt: string | null): string {
  if (!bornAt) return 'AGE: 0 MONTHS';

  const born = new Date(bornAt);
  const now = new Date();
  const diffMs = now.getTime() - born.getTime();
  const diffWeeks = Math.floor(diffMs / (1000 * 60 * 60 * 24 * 7));

  return `AGE: ${diffWeeks} MONTHS`;
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
