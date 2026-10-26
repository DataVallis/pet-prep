/**
 * "Šola" entry of the child HUD (M5-R03): a glass chip just above the action dock (the
 * dock already holds up to five buttons — a sixth would not fit a 375 pt phone). A small
 * amber dot marks a day whose training session is still open AND this child can still
 * train (`showTrainingDot`, M5-R03b); a done day shows ✓, otherwise nothing.
 * Rendered only for a pet with training (never for a legacy pet / older server).
 */

import { Pressable, StyleSheet, View } from 'react-native';
import { Text } from '@/components/ui/Text';
import { Check, GraduationCap } from 'lucide-react-native';

import { COMPACT_CHIP_HIT_SLOP, compactChipStyles } from '@/modules/hud/compactChip';
import { TRAINING_STRINGS } from '@/modules/training/training';
import { alpha, palette } from '@/theme';

export interface TrainingChipProps {
  todayDone: boolean;
  /** Today's practice is waiting and I can still train (`showTrainingDot`). */
  pending: boolean;
  onPress: () => void;
  disabled?: boolean;
  /** M5-T02: why it is off (»💤 Kuža spi do 7:00«) — read out with the button. */
  disabledReason?: string;
  /** Collapsed HUD top stack: icon only (the full name stays the a11y label). */
  compact?: boolean;
}

export default function TrainingChip({ todayDone, pending, onPress, disabled = false, disabledReason, compact = false }: TrainingChipProps) {
  return (
    <Pressable
      testID="hud-training-open"
      accessibilityRole="button"
      accessibilityLabel={TRAINING_STRINGS.entryA11y(todayDone)}
      accessibilityHint={disabled ? disabledReason : undefined}
      accessibilityState={{ disabled }}
      disabled={disabled}
      onPress={onPress}
      hitSlop={compact ? COMPACT_CHIP_HIT_SLOP : 6}
      style={({ pressed }) => [styles.chip, compact && compactChipStyles.chip, pressed && styles.pressed, disabled && styles.disabled]}
    >
      <GraduationCap color={palette.mint} size={16} />
      {!compact && <Text style={styles.text}>{TRAINING_STRINGS.entry}</Text>}
      {todayDone ? (
        <View style={[styles.done, compact && compactChipStyles.corner]} testID="hud-training-done">
          <Check color={palette.white} size={10} />
        </View>
      ) : pending ? (
        <View style={[styles.badge, compact && compactChipStyles.corner]} testID="hud-training-badge" />
      ) : null}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  chip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    minHeight: 40,
    paddingHorizontal: 14,
    borderRadius: 20,
    backgroundColor: alpha(palette.graphite, 0.85),
    borderWidth: 1,
    borderColor: alpha(palette.mint, 0.35),
  },
  text: { color: palette.white, fontSize: 14, fontWeight: '800' },
  badge: { width: 9, height: 9, borderRadius: 5, backgroundColor: palette.warnDark },
  done: {
    width: 16,
    height: 16,
    borderRadius: 8,
    backgroundColor: palette.okDark,
    alignItems: 'center',
    justifyContent: 'center',
  },
  pressed: { opacity: 0.75 },
  disabled: { opacity: 0.5 },
});
