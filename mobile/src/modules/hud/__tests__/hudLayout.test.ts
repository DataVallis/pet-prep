/**
 * HUD geometry: the four metric bars must fit between the header card and the action
 * dock on every phone (TestFlight 2026-10-05: the hygiene bar sat behind the dock).
 */

import {
  computeHudLayout,
  DOCK_HEIGHT_ESTIMATE,
  fitMetrics,
  HEADER_HEIGHT_ESTIMATE,
  LABEL_LINE,
  MAX_TRACK,
  metricChrome,
  metricsColumnHeight,
  PERCENT_LINE,
  SPACING,
  type HudLayout,
} from '@/modules/hud/hudLayout';

/** Portrait window heights (pt) and safe-area insets of real devices. */
const DEVICES = [
  { name: 'iPhone SE (2nd/3rd gen)', height: 667, top: 20, bottom: 0 },
  { name: 'iPhone 13 mini', height: 812, top: 50, bottom: 34 },
  { name: 'iPhone 15 / 16', height: 852, top: 59, bottom: 34 },
  { name: 'iPhone 16 Pro Max', height: 932, top: 59, bottom: 34 },
  { name: 'small Android (edge-to-edge)', height: 640, top: 24, bottom: 0 },
  { name: 'Pixel 8 (gesture nav)', height: 915, top: 48, bottom: 24 },
] as const;

/** Bottom edge of the metric column must stay above the dock (with its spacing). */
function columnBottom(l: HudLayout): number {
  return l.metricsTop + l.metricsHeight;
}

function dockTop(screenHeight: number, l: HudLayout, dockHeight = DOCK_HEIGHT_ESTIMATE): number {
  return screenHeight - l.dockBottom - dockHeight;
}

describe('computeHudLayout', () => {
  it.each(DEVICES)('fits all four bars between header and dock on $name', ({ height, top, bottom }) => {
    const l = computeHudLayout({ screenHeight: height, insets: { top, bottom } });

    expect(l.metricsTop).toBeGreaterThanOrEqual(l.headerTop + HEADER_HEIGHT_ESTIMATE);
    expect(columnBottom(l)).toBeLessThanOrEqual(dockTop(height, l) - SPACING);
    expect(l.metricsHeight).toBe(metricsColumnHeight(l.metric));
    expect(l.metric.trackHeight).toBeGreaterThanOrEqual(20);
    expect(l.metric.trackHeight).toBeLessThanOrEqual(MAX_TRACK);
    // Labels stay visible on every listed phone (only the "tiny" fallback drops them).
    expect(l.metric.showLabel).toBe(true);
  });

  it('keeps the header below the notch / status bar and the dock above the home indicator', () => {
    const proMax = computeHudLayout({ screenHeight: 932, insets: { top: 59, bottom: 34 } });
    expect(proMax.headerTop).toBe(67);
    expect(proMax.dockBottom).toBe(38);

    const se = computeHudLayout({ screenHeight: 667, insets: { top: 20, bottom: 0 } });
    expect(se.headerTop).toBe(28);
    expect(se.dockBottom).toBe(20);
  });

  it('uses minimum margins when a device reports no insets', () => {
    const l = computeHudLayout({ screenHeight: 700, insets: { top: 0, bottom: 0 } });
    expect(l.headerTop).toBe(28);
    expect(l.dockBottom).toBe(20);
  });

  it('uses the regular size with long bars on a Pro Max', () => {
    const l = computeHudLayout({ screenHeight: 932, insets: { top: 59, bottom: 34 } });
    expect(l.metric.variant).toBe('regular');
    expect(l.metric.trackHeight).toBeGreaterThanOrEqual(55);
  });

  it('shrinks to the compact size on an iPhone SE', () => {
    const l = computeHudLayout({ screenHeight: 667, insets: { top: 20, bottom: 0 } });
    expect(l.metric.variant).toBe('compact');
    expect(l.metric.badge).toBeLessThan(34);
  });

  it('recomputes with measured heights (e.g. a taller dock with bigger system fonts)', () => {
    const estimate = computeHudLayout({ screenHeight: 852, insets: { top: 59, bottom: 34 } });
    const measured = computeHudLayout({
      screenHeight: 852,
      insets: { top: 59, bottom: 34 },
      headerHeight: 70,
      dockHeight: 170,
    });

    expect(measured.metricsTop).toBe(estimate.metricsTop + 6);
    expect(measured.metricsHeight).toBeLessThan(estimate.metricsHeight);
    expect(columnBottom(measured)).toBeLessThanOrEqual(dockTop(852, measured, 170) - SPACING);
  });

  it('puts the stale banner / toast just below the header', () => {
    const l = computeHudLayout({ screenHeight: 852, insets: { top: 59, bottom: 34 }, headerHeight: 64 });
    expect(l.bannerTop).toBe(l.headerTop + 64 + 6);
  });

  it('never reports negative space and hides the column when nothing fits (landscape / split screen)', () => {
    const l = computeHudLayout({ screenHeight: 200, insets: { top: 0, bottom: 0 } });
    expect(l.metricsAvailable).toBe(0);
    expect(l.metric.variant).toBe('hidden');
    expect(l.metricsHeight).toBe(0);
  });

  it('PR #30 review: the column never runs behind the dock for ANY screen height / measured dock', () => {
    for (let height = 150; height <= 1000; height += 1) {
      for (const dockHeight of [DOCK_HEIGHT_ESTIMATE, 180, 260]) {
        const l = computeHudLayout({ screenHeight: height, insets: { top: 20, bottom: 0 }, dockHeight });
        const dockTopY = height - l.dockBottom - dockHeight;
        if (l.metricsHeight > 0) expect(l.metricsTop + l.metricsHeight).toBeLessThanOrEqual(dockTopY - SPACING);
      }
    }
  });
});

describe('fitMetrics', () => {
  it('caps the track at 100 pt on very tall screens', () => {
    expect(fitMetrics(2000).trackHeight).toBe(MAX_TRACK);
  });

  it('chooses the largest variant that fits', () => {
    // Regular chrome = 34 + 6 + 6 + 16 + 6 + 12 = 80 → 4×(80+40) + 3×12 = 516 pt for a 40 pt track.
    expect(metricChrome({ badge: 34, innerGap: 6, showLabel: true })).toBe(34 + 6 + 6 + PERCENT_LINE + 6 + LABEL_LINE);
    expect(fitMetrics(516).variant).toBe('regular');
    expect(fitMetrics(515).variant).toBe('compact');
  });

  it('the column never exceeds the available space (every height 0–800 pt)', () => {
    for (let available = 0; available <= 800; available += 1) {
      expect(metricsColumnHeight(fitMetrics(available))).toBeLessThanOrEqual(available);
    }
  });

  it('shrinks step by step: labels go first, then the percentages, then the whole column', () => {
    // tiny: chrome 24 + 3 + 3 + 16 = 46, track ≥ 8 → 4 × 54 + 3 × 6 = 234 pt.
    expect(fitMetrics(380)).toMatchObject({ variant: 'compact', showLabel: true, showPercent: true });
    expect(fitMetrics(234)).toMatchObject({ variant: 'tiny', showLabel: false, showPercent: true });
    // micro: chrome 18 + 2 = 20, track ≥ 4 → 4 × 24 + 3 × 4 = 108 pt.
    expect(fitMetrics(233)).toMatchObject({ variant: 'micro', showLabel: false, showPercent: false });
    expect(fitMetrics(108)).toMatchObject({ variant: 'micro', trackHeight: 4 });
    expect(fitMetrics(107)).toMatchObject({ variant: 'hidden', trackHeight: 0 });
  });
});
