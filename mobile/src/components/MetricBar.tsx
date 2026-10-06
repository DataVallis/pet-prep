import type { ReactNode } from 'react';
import { StyleSheet, View } from 'react-native';
import { Text } from '@/components/ui/Text';

import { METRIC_BAR_WIDTH, type MetricSizing } from '@/modules/hud/hudLayout';
import { interpolateColor } from '@/utils/metrics';
import { alpha, fonts, palette } from '@/theme';

export interface MetricBarProps {
  /** Metric level, clamped to 0–100. */
  level: number;
  /** Text rendered below the bar (e.g. the percentage). */
  label: string;
  /** Lucide icon node shown in the badge above the bar. */
  icon: ReactNode;
  /** Optional override fill color; defaults to interpolateColor(level). */
  color?: string;
  /** Size from `computeHudLayout` (the HUD fits four bars to the screen); default = 100 pt track. */
  sizing?: Pick<MetricSizing, 'badge' | 'innerGap' | 'trackHeight' | 'showLabel'> & { showPercent?: boolean };
  testID?: string;
}

const DEFAULT_SIZING = { badge: 34, innerGap: 6, trackHeight: 100, showLabel: true } as const;

/**
 * Vertical progress bar used on the right edge of the Child HUD.
 * Fill color interpolates green → amber → red based on level.
 */
export default function MetricBar({ level, label, icon, color, sizing = DEFAULT_SIZING, testID }: MetricBarProps) {
  const clamped = Math.max(0, Math.min(100, level));
  const fillColor = color ?? interpolateColor(clamped);
  const badge = { width: sizing.badge, height: sizing.badge, borderRadius: sizing.badge / 2 };

  return (
    <View style={[styles.container, { gap: sizing.innerGap }]} testID={testID} accessible accessibilityLabel={`${label} ${clamped}%`}>
      <View style={[styles.iconBadge, badge]}>
        {icon}
      </View>

      <View style={[styles.barTrack, { height: sizing.trackHeight }]} testID={testID ? `${testID}-track` : undefined}>
        <View
          style={[
            styles.barFill,
            { height: `${clamped}%`, backgroundColor: fillColor },
          ]}
        />
      </View>

      {sizing.showPercent !== false && (
        <Text style={styles.percentText} numberOfLines={1} maxFontSizeMultiplier={1}>{clamped}%</Text>
      )}
      {label && sizing.showLabel ? (
        // One line always ("ENERGIJA" used to wrap to "ENERGIJ / A"): small Instrument Sans,
        // no letter-spacing, fixed font scale (the HUD height math relies on the line
        // heights; VoiceOver reads the accessibilityLabel above) and shrink-to-fit.
        <Text style={styles.labelText} numberOfLines={1} adjustsFontSizeToFit minimumFontScale={0.7} maxFontSizeMultiplier={1}>
          {label}
        </Text>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    width: METRIC_BAR_WIDTH,
    alignItems: 'center',
  },
  iconBadge: {
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.2),
    backgroundColor: alpha(palette.white, 0.12),
    alignItems: 'center',
    justifyContent: 'center',
    shadowColor: palette.black,
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.3,
    shadowRadius: 4,
  },
  barTrack: {
    width: 12,
    borderRadius: 6,
    backgroundColor: alpha(palette.white, 0.1),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.15),
    overflow: 'hidden',
    justifyContent: 'flex-end',
  },
  barFill: {
    width: '100%',
    borderRadius: 6,
  },
  percentText: {
    fontFamily: fonts.displayBold,
    fontVariant: ['tabular-nums'],
    fontSize: 12,
    lineHeight: 16,
    fontWeight: '700',
    color: palette.white,
  },
  labelText: {
    fontSize: 9,
    lineHeight: 12,
    fontWeight: '600',
    textTransform: 'uppercase',
    letterSpacing: 0,
    color: alpha(palette.white, 0.6),
    maxWidth: METRIC_BAR_WIDTH,
  },
});
