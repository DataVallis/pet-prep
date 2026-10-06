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

import { TRAINING_STRINGS } from '@/modules/training/training';
import { alpha, palette } from '@/theme';

export interface TrainingChipProps {
  todayDone: boolean;
  /** Today's practice is waiting and I can still train (`showTrainingDot`). */
  pending: boolean;
  onPress: () => void;
  disabled?: boolean;
}

export default function TrainingChip({ todayDone, pending, onPress, disabled = false }: TrainingChipProps) {
  return (
    <Pressable
      testID="hud-training-open"
      accessibilityRole="button"
      accessibilityLabel={TRAINING_STRINGS.entryA11y(todayDone)}
      accessibilityState={{ disabled }}
      disabled={disabled}
      onPress={onPress}
      hitSlop={6}
      style={({ pressed }) => [styles.chip, pressed && styles.pressed, disabled && styles.disabled]}
    >
      <GraduationCap color={palette.mint} size={16} />
      <Text style={styles.text}>{TRAINING_STRINGS.entry}</Text>
      {todayDone ? (
        <View style={styles.done} testID="hud-training-done">
          <Check color={palette.white} size={10} />
        </View>
      ) : pending ? (
        <View style={styles.badge} testID="hud-training-badge" />
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
