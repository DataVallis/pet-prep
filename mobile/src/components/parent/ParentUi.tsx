/**
 * Light parent theme (CGP v2 "Grafit in meta", `brand/README.md`: white cards on fog,
 * graphite ink and primary buttons, mint as the one brand surface) — small shared
 * building blocks for every parent screen (M2-05). Status colours are the CGP light
 * status tokens (ok / warn / danger), never mint or raspberry.
 */

import type { ReactNode } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, View, type StyleProp, type ViewStyle } from 'react-native';
import { Text } from '@/components/ui/Text';
import { AlertTriangle, Beef, Brush, Droplet, Feather, Footprints, GraduationCap, Recycle, Shovel, Sparkles, WifiOff } from 'lucide-react-native';

import { LIGHT_LABELS, type LightColor, type RoutineType } from '@/modules/family/scoring';
import { fonts, light, meter, palette, radius } from '@/theme';
import { t } from '@/i18n';


export const PARENT_COLORS = {
  bg: light.bg,
  card: light.surface,
  border: light.border,
  divider: light.divider,
  text: light.ink,
  muted: light.inkMuted,
  faint: light.inkFaint,
  /** Primary action (graphite button, icons). */
  accent: light.action,
  /** Mint brand surface (selected / highlighted). */
  accentSoft: palette.mintSoft,
  accentBorder: palette.mintBorder,
  /** Links and mint-coloured text (never raw mint on light). */
  link: light.mintText,
  green: palette.ok,
  greenSoft: palette.okSoft,
  greenText: palette.ok,
  yellow: palette.warn,
  yellowSoft: palette.warnSoft,
  yellowText: palette.warn,
  red: palette.danger,
  redSoft: palette.dangerSoft,
  redText: palette.danger,
  track: light.track,
  /** Charts (7-day bars, legend): done = mint, missed = coral — meter colours, not text. */
  chartDone: meter.good,
  chartMissed: meter.low,
} as const;

const C = PARENT_COLORS;

export const LIGHT_STYLE: Record<LightColor, { dot: string; bg: string; text: string }> = {
  green: { dot: C.green, bg: C.greenSoft, text: C.greenText },
  yellow: { dot: C.yellow, bg: C.yellowSoft, text: C.yellowText },
  red: { dot: C.red, bg: C.redSoft, text: C.redText },
};

export function Card({ children, style, testID }: { children: ReactNode; style?: StyleProp<ViewStyle>; testID?: string }) {
  return (
    <View style={[styles.card, style]} testID={testID}>
      {children}
    </View>
  );
}

export function SectionTitle({ children, right }: { children: ReactNode; right?: ReactNode }) {
  return (
    <View style={styles.sectionRow}>
      <Text style={styles.sectionTitle}>{children}</Text>
      {right}
    </View>
  );
}

export function TrafficLightBadge({ color, testID }: { color: LightColor; testID?: string }) {
  const s = LIGHT_STYLE[color];
  return (
    <View
      style={[styles.badge, { backgroundColor: s.bg }]}
      testID={testID}
      accessibilityLabel={t('parent:ui.lightA11y', { label: LIGHT_LABELS[color] })}
    >
      <View style={[styles.badgeDot, { backgroundColor: s.dot }]} />
      <Text style={[styles.badgeText, { color: s.text }]}>{LIGHT_LABELS[color]}</Text>
    </View>
  );
}

const ROUTINE_ICONS: Record<RoutineType, typeof Beef> = {
  feed: Beef,
  water: Droplet,
  clean: Sparkles,
  walk: Footprints,
  training: GraduationCap,
  // M5-R06-08b (cats)
  play: Feather,
  litter_scoop: Shovel,
  litter_change: Recycle,
  grooming: Brush,
};

export function RoutineIcon({ type, color = C.accent, size = 16 }: { type: RoutineType; color?: string; size?: number }) {
  const Icon = ROUTINE_ICONS[type];
  return <Icon color={color} size={size} />;
}

/** Horizontal metric bar (0–100). Mint meter, status colour only when low. */
export function MetricRow({ label, value, testID }: { label: string; value: number; testID?: string }) {
  const clamped = Math.max(0, Math.min(100, Math.round(value)));
  const fill = clamped <= 10 ? meter.low : clamped <= 30 ? meter.mid : meter.good;
  return (
    <View style={styles.metricRow} testID={testID}>
      <Text style={styles.metricLabel}>{label}</Text>
      <View style={styles.metricTrack}>
        <View style={[styles.metricFill, { width: `${clamped}%`, backgroundColor: fill }]} />
      </View>
      <Text style={styles.metricValue}>{t('common:format.percent', { value: clamped })}</Text>
    </View>
  );
}

/** Green confirmation banner (e.g. "Pridružili ste se družini") with a close action. */
export function NoticeBanner({ text, onClose, closeLabel }: { text: string; onClose: () => void; closeLabel: string }) {
  return (
    <View style={styles.notice} testID="parent-notice" accessibilityLiveRegion="polite">
      <Text style={styles.noticeText}>{text}</Text>
      <Pressable onPress={onClose} hitSlop={8} accessibilityRole="button" accessibilityLabel={closeLabel}>
        <Text style={styles.errorRetry}>{closeLabel}</Text>
      </Pressable>
    </View>
  );
}

/** Inline error / offline banner with a retry action. */
export function ErrorBanner({
  text,
  retryLabel,
  onRetry,
  offline = false,
  testID,
}: {
  text: string;
  retryLabel: string;
  onRetry: () => void;
  offline?: boolean;
  testID?: string;
}) {
  const Icon = offline ? WifiOff : AlertTriangle;
  return (
    <View style={styles.errorBanner} testID={testID}>
      <Icon color={C.redText} size={18} />
      <Text style={styles.errorText}>{text}</Text>
      <Pressable onPress={onRetry} hitSlop={8} accessibilityRole="button">
        <Text style={styles.errorRetry}>{retryLabel}</Text>
      </Pressable>
    </View>
  );
}

export function LoadingBlock({ text, testID }: { text: string; testID?: string }) {
  return (
    <View style={styles.loading} testID={testID}>
      <ActivityIndicator color={C.accent} />
      <Text style={styles.muted}>{text}</Text>
    </View>
  );
}

/** Segmented control (e.g. 7 / 30 / 84 dni). */
export function Segmented<T extends string | number>({
  options,
  value,
  onChange,
  label,
  testIDPrefix,
}: {
  options: readonly { value: T; label: string }[];
  value: T;
  onChange: (value: T) => void;
  label: (value: T) => string;
  testIDPrefix: string;
}) {
  return (
    <View style={styles.segmented} accessibilityRole="tablist">
      {options.map((o) => {
        const active = o.value === value;
        return (
          <Pressable
            key={String(o.value)}
            onPress={() => onChange(o.value)}
            style={[styles.segment, active && styles.segmentActive]}
            accessibilityRole="tab"
            accessibilityState={{ selected: active }}
            accessibilityLabel={label(o.value)}
            testID={`${testIDPrefix}-${String(o.value)}`}
          >
            <Text style={[styles.segmentText, active && styles.segmentTextActive]}>{o.label}</Text>
          </Pressable>
        );
      })}
    </View>
  );
}

export const parentStyles = StyleSheet.create({
  muted: { fontSize: 13, color: C.muted },
  body: { fontSize: 14, color: C.text },
  strong: { fontSize: 14, fontWeight: '700', color: C.text },
});

const styles = StyleSheet.create({
  card: {
    backgroundColor: C.card,
    borderRadius: radius.card,
    padding: 16,
    borderWidth: 1,
    borderColor: C.border,
    gap: 12,
  },
  sectionRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8 },
  sectionTitle: { fontSize: 16, fontFamily: fonts.displayBold, letterSpacing: -0.2, color: C.text },
  badge: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    paddingHorizontal: 10,
    paddingVertical: 4,
    borderRadius: 999,
    alignSelf: 'flex-start',
  },
  badgeDot: { width: 8, height: 8, borderRadius: 4 },
  badgeText: { fontSize: 12, fontWeight: '700' },
  metricRow: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  metricLabel: { width: 64, fontSize: 12, color: C.muted },
  metricTrack: { flex: 1, height: 6, borderRadius: 3, backgroundColor: C.track, overflow: 'hidden' },
  metricFill: { height: '100%', borderRadius: 3 },
  metricValue: { width: 44, textAlign: 'right', fontSize: 12, fontWeight: '600', color: C.text, fontVariant: ['tabular-nums'] },
  errorBanner: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    padding: 12,
    borderRadius: radius.button,
    borderWidth: 1,
    borderColor: palette.dangerBorder,
    backgroundColor: C.redSoft,
  },
  errorText: { flex: 1, fontSize: 13, color: C.redText },
  notice: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    padding: 12,
    borderRadius: radius.button,
    borderWidth: 1,
    borderColor: palette.okBorder,
    backgroundColor: C.greenSoft,
  },
  noticeText: { flex: 1, fontSize: 13, color: C.greenText },
  errorRetry: { fontSize: 13, fontWeight: '700', color: C.link },
  loading: { alignItems: 'center', gap: 10, paddingVertical: 40 },
  muted: { fontSize: 13, color: C.muted },
  segmented: {
    flexDirection: 'row',
    backgroundColor: C.divider,
    borderRadius: 12,
    padding: 3,
    gap: 3,
  },
  segment: { flex: 1, alignItems: 'center', paddingVertical: 8, borderRadius: 10 },
  segmentActive: { backgroundColor: C.card, borderWidth: 1, borderColor: C.border },
  segmentText: { fontSize: 13, fontWeight: '600', color: C.muted },
  segmentTextActive: { color: C.text, fontWeight: '800' },
});
