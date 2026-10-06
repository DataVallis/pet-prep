/**
 * CGP v2 "Grafit in meta" colour tokens — source of truth: `brand/README.md`.
 * Never hard-code colours in screens; import from `@/theme`.
 *
 * Proportion on a screen: fog/white ~65 %, graphite ~25 %, mint ~8 %, raspberry ≤ 2 %.
 * Mint is never used as text on a light background (use `mintText`); raspberry is the nose
 * plus at most one tiny accent per screen; danger is brick red, not raspberry.
 */

/** Raw brand palette + a graphite-tinted neutral ramp (derived, not in the CGP table). */
export const palette = {
  graphite: '#121614',
  fog: '#F3F5F2',
  white: '#FFFFFF',
  black: '#000000',
  mint: '#7FE0B4',
  mintSoft: '#E3F7EE',
  mintBorder: '#B9EBD3',
  mintDeep: '#1A7A55',
  raspberry: '#FF6B8B',

  // Neutral ramp (graphite family). n900 = graphite, n50 = fog.
  n50: '#F3F5F2',
  n100: '#E9EDE9',
  n200: '#DDE3DE',
  n300: '#C5CDC7',
  n400: '#A3ADA6',
  n500: '#7A847D',
  n600: '#5A635D',
  n700: '#3A433E',
  n800: '#2A322E',
  n850: '#1C221F',
  n900: '#121614',

  // Status — light theme (text-safe on white/fog).
  ok: '#1A7A55',
  okSoft: '#E3F7EE',
  okBorder: '#B9EBD3',
  warn: '#8A6500',
  warnSoft: '#FFF6DB',
  warnBorder: '#F5DFA0',
  danger: '#B93125',
  dangerDeep: '#8E2219',
  dangerSoft: '#FDEDEB',
  dangerBorder: '#F2C4BE',

  // Status — dark theme / simulator meters.
  okDark: '#3DD68C',
  warnDark: '#FFD15C',
  dangerDark: '#FF7A6B',
} as const;

/** Light theme: parent dashboard, auth, Phase 2 — white cards on fog. */
export const light = {
  bg: palette.fog,
  surface: palette.white,
  surfaceMuted: palette.n100,
  border: palette.n200,
  divider: palette.n100,
  ink: palette.graphite,
  inkMuted: palette.n600,
  inkFaint: palette.n500,
  action: palette.graphite,
  onAction: palette.white,
  mint: palette.mint,
  mintText: palette.mintDeep,
  ok: palette.ok,
  warn: palette.warn,
  danger: palette.danger,
  track: palette.n200,
} as const;

/** Dark theme: child simulator — graphite with glass panels over the pet video. */
export const dark = {
  bg: palette.graphite,
  surface: palette.n850,
  surfaceRaised: palette.n800,
  border: palette.n700,
  ink: palette.fog,
  inkMuted: palette.n400,
  action: palette.mint,
  onAction: palette.graphite,
  mint: palette.mint,
  mintText: palette.mint,
  ok: palette.okDark,
  warn: palette.warnDark,
  danger: palette.dangerDark,
  glass: 'rgba(28, 34, 31, 0.78)',
  glassStrong: 'rgba(18, 22, 20, 0.88)',
  glassBorder: 'rgba(243, 245, 242, 0.14)',
} as const;

/** Simulator meter colours (good → mid → low). */
export const meter = {
  good: palette.mint,
  mid: palette.warnDark,
  low: palette.dangerDark,
} as const;

/** `rgba()` from a `#RRGGBB` token — for glass and scrims. */
export function alpha(hex: string, opacity: number): string {
  const r = parseInt(hex.slice(1, 3), 16);
  const g = parseInt(hex.slice(3, 5), 16);
  const b = parseInt(hex.slice(5, 7), 16);
  return `rgba(${r}, ${g}, ${b}, ${opacity})`;
}
