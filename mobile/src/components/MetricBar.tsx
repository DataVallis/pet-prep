import type { ReactNode } from 'react';
import { Platform, StyleSheet, Text, View } from 'react-native';

import { interpolateColor } from '@/utils/metrics';

export interface MetricBarProps {
  /** Metric level, clamped to 0–100. */
  level: number;
  /** Text rendered below the bar (e.g. the percentage). */
  label: string;
  /** Lucide icon node shown in the badge above the bar. */
  icon: ReactNode;
  /** Optional override fill color; defaults to interpolateColor(level). */
  color?: string;
}

/**
 * Vertical progress bar used on the right edge of the Child HUD.
 * Fill color interpolates green → amber → red based on level.
 */
export default function MetricBar({ level, label, icon, color }: MetricBarProps) {
  const clamped = Math.max(0, Math.min(100, level));
  const fillColor = color ?? interpolateColor(clamped);

  return (
    <View style={styles.container}>
      <View style={styles.iconBadge}>
        {icon}
      </View>

      <View style={styles.barTrack}>
        <View
          style={[
            styles.barFill,
            { height: `${clamped}%`, backgroundColor: fillColor },
          ]}
        />
      </View>

      <Text style={styles.percentText}>{clamped}%</Text>
      {label ? <Text style={styles.labelText}>{label}</Text> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    width: 48,
    alignItems: 'center',
    gap: 6,
  },
  iconBadge: {
    width: 34,
    height: 34,
    borderRadius: 17,
    borderWidth: 1,
    borderColor: 'rgba(255, 255, 255, 0.2)',
    backgroundColor: 'rgba(255, 255, 255, 0.12)',
    alignItems: 'center',
    justifyContent: 'center',
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.3,
    shadowRadius: 4,
  },
  barTrack: {
    height: 100,
    width: 12,
    borderRadius: 6,
    backgroundColor: 'rgba(255, 255, 255, 0.1)',
    borderWidth: 1,
    borderColor: 'rgba(255, 255, 255, 0.15)',
    overflow: 'hidden',
    justifyContent: 'flex-end',
  },
  barFill: {
    width: '100%',
    borderRadius: 6,
  },
  percentText: {
    fontFamily: Platform.OS === 'ios' ? 'Courier New' : 'monospace',
    fontSize: 12,
    fontWeight: '700',
    color: '#ffffff',
  },
  labelText: {
    fontFamily: Platform.OS === 'ios' ? 'Courier New' : 'monospace',
    fontSize: 10,
    fontWeight: '600',
    textTransform: 'uppercase',
    letterSpacing: 0.5,
    color: 'rgba(255, 255, 255, 0.5)',
  },
});
