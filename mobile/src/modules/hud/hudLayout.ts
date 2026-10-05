/**
 * Child HUD geometry (PRODUCT_SPEC §8) — pure, unit-tested in `__tests__/hudLayout.test.ts`.
 *
 * The four vertical metric bars (Hrana, Voda, Energija, Čistoča) must fit between the
 * glass header card and the bottom action dock on every phone, from an iPhone SE
 * (667 pt, no notch) to a Pro Max (932 pt). Before 2026-10-05 the column had a fixed
 * `top: 130` and 100 pt tracks (~750 pt for the column) — the 4th bar and its "100%"
 * ended up behind the dock on every device.
 *
 * Inputs are the window height, the safe-area insets and (once measured via onLayout)
 * the real header / dock heights; the estimates below are used for the first frame.
 */

export interface HudInsets {
  top: number;
  bottom: number;
}

export interface HudLayoutInput {
  screenHeight: number;
  insets: HudInsets;
  /** Measured header card height (onLayout); defaults to {@link HEADER_HEIGHT_ESTIMATE}. */
  headerHeight?: number;
  /** Measured action dock height (onLayout); defaults to {@link DOCK_HEIGHT_ESTIMATE}. */
  dockHeight?: number;
}

/** Size of one MetricBar; `trackHeight` is the coloured bar itself. */
export interface MetricSizing {
  /**
   * `micro` = badge + bar only (no texts) when even `tiny` doesn't fit;
   * `hidden` = no column at all (e.g. landscape) — never drawn behind the dock.
   */
  variant: 'regular' | 'compact' | 'tiny' | 'micro' | 'hidden';
  /** Round icon badge diameter. */
  badge: number;
  iconSize: number;
  /** Gap between badge, track, percent and label inside one bar. */
  innerGap: number;
  /** Gap between two bars. */
  gap: number;
  trackHeight: number;
  showLabel: boolean;
  /** The "100%" line under the bar. */
  showPercent: boolean;
}

export interface HudLayout {
  /** `top` of the header card. */
  headerTop: number;
  /** `top` of the metrics column (below the header card). */
  metricsTop: number;
  /** Height the metrics column may use (down to just above the dock). */
  metricsAvailable: number;
  /** Height the column actually takes with {@link metric}. */
  metricsHeight: number;
  /** `bottom` of the action dock. */
  dockBottom: number;
  /** `top` of the "stale" banner / toast (just below the header). */
  bannerTop: number;
  metric: MetricSizing;
}

export const METRIC_COUNT = 4;
/** Header card: 12 + 38 (icon box) + 12 padding + 2 border. */
export const HEADER_HEIGHT_ESTIMATE = 64;
/** Dock: 14 + 64 (button) + 8 + 14 (label) + 4 + 12 (hint) + 14 padding + 2 border ≈ 132. */
export const DOCK_HEIGHT_ESTIMATE = 134;
/** Breathing room between header / column / dock. */
export const SPACING = 10;
/** Line heights of the "100%" and "ENERGIJA" texts in MetricBar. */
export const PERCENT_LINE = 16;
export const LABEL_LINE = 12;
export const MAX_TRACK = 100;
/** Width of one MetricBar (fits "ENERGIJA" in 9 pt mono on one line). */
export const METRIC_BAR_WIDTH = 58;
/** Distance of the metric column from the right screen edge. */
export const METRICS_RIGHT = 12;
/** Width the metric column reserves on the right (+12 pt air): banners / toasts stay left of it. */
export const METRICS_RESERVED_RIGHT = METRICS_RIGHT + METRIC_BAR_WIDTH + 12;

type Variant = Omit<MetricSizing, 'trackHeight'> & { minTrack: number };

/** Tried in order; the first whose track reaches `minTrack` wins. */
const VARIANTS: readonly Variant[] = [
  { variant: 'regular', badge: 34, iconSize: 16, innerGap: 6, gap: 12, showLabel: true, showPercent: true, minTrack: 40 },
  { variant: 'compact', badge: 26, iconSize: 13, innerGap: 3, gap: 8, showLabel: true, showPercent: true, minTrack: 20 },
  { variant: 'tiny', badge: 24, iconSize: 12, innerGap: 3, gap: 6, showLabel: false, showPercent: true, minTrack: 8 },
  { variant: 'micro', badge: 18, iconSize: 10, innerGap: 2, gap: 4, showLabel: false, showPercent: false, minTrack: 4 },
];

const HIDDEN: MetricSizing = {
  variant: 'hidden',
  badge: 0,
  iconSize: 0,
  innerGap: 0,
  gap: 0,
  trackHeight: 0,
  showLabel: false,
  showPercent: false,
};

/** Height of one MetricBar without its track. */
export function metricChrome(s: Pick<MetricSizing, 'badge' | 'innerGap' | 'showLabel'> & { showPercent?: boolean }): number {
  const percent = s.showPercent === false ? 0 : s.innerGap + PERCENT_LINE;
  return s.badge + s.innerGap + percent + (s.showLabel ? s.innerGap + LABEL_LINE : 0);
}

/** Height of the whole column of {@link METRIC_COUNT} bars (0 when hidden). */
export function metricsColumnHeight(s: MetricSizing): number {
  if (s.variant === 'hidden') return 0;
  return METRIC_COUNT * (metricChrome(s) + s.trackHeight) + (METRIC_COUNT - 1) * s.gap;
}

function toSizing(v: Variant, trackHeight: number): MetricSizing {
  return {
    variant: v.variant,
    badge: v.badge,
    iconSize: v.iconSize,
    innerGap: v.innerGap,
    gap: v.gap,
    showLabel: v.showLabel,
    showPercent: v.showPercent,
    trackHeight,
  };
}

/**
 * Largest bar sizing that fits `available` points. Invariant (tested for every height):
 * `metricsColumnHeight(fitMetrics(a)) <= a` — when not even the text-less `micro` bars
 * fit, the column is hidden instead of running behind the dock.
 */
export function fitMetrics(available: number): MetricSizing {
  for (const v of VARIANTS) {
    const perBar = (available - (METRIC_COUNT - 1) * v.gap) / METRIC_COUNT;
    const track = Math.floor(perBar - metricChrome(v));
    if (track >= v.minTrack) return toSizing(v, Math.min(MAX_TRACK, track));
  }
  return HIDDEN;
}

export function computeHudLayout({
  screenHeight,
  insets,
  headerHeight = HEADER_HEIGHT_ESTIMATE,
  dockHeight = DOCK_HEIGHT_ESTIMATE,
}: HudLayoutInput): HudLayout {
  // Status bar / notch / Dynamic Island; 20 pt minimum when a device reports no inset.
  const headerTop = Math.max(insets.top, 20) + 8;
  // Home indicator; phones without one keep a 20 pt margin.
  const dockBottom = insets.bottom > 0 ? insets.bottom + 4 : 20;

  const metricsTop = headerTop + headerHeight + SPACING;
  const metricsBottom = screenHeight - dockBottom - dockHeight - SPACING;
  const metricsAvailable = Math.max(0, metricsBottom - metricsTop);
  const metric = fitMetrics(metricsAvailable);

  return {
    headerTop,
    metricsTop,
    metricsAvailable,
    metricsHeight: metricsColumnHeight(metric),
    dockBottom,
    bannerTop: headerTop + headerHeight + 6,
    metric,
  };
}
