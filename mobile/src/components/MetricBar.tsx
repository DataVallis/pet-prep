import type { ReactNode } from 'react';
import { Text, View } from 'react-native';

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
    <View className="w-12 flex-col items-center gap-1.5">
      <View className="h-8 w-8 items-center justify-center rounded-full border border-white/15 bg-white/10">
        {icon}
      </View>

      <View className="h-30 w-3 overflow-hidden rounded-full bg-white/8">
        <View
          className="w-full rounded-full"
          style={{ height: `${clamped}%`, backgroundColor: fillColor }}
        />
      </View>

      <Text className="font-mono text-xs font-semibold text-white">{clamped}%</Text>
      {label ? <Text className="font-mono text-[10px] uppercase tracking-wider text-white/40">{label}</Text> : null}
    </View>
  );
}
