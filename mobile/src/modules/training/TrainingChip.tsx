/**
 * "Šola" entry of the child HUD (M5-R03): a glass chip just above the action dock (the
 * dock already holds up to five buttons — a sixth would not fit a 375 pt phone). A small
 * amber dot marks a day whose training session is still open; a done day shows ✓.
 * Rendered only for a pet with training (never for a legacy pet / older server).
 */

import { Pressable, StyleSheet, Text, View } from 'react-native';
import { Check, GraduationCap } from 'lucide-react-native';

import { TRAINING_STRINGS } from '@/modules/training/training';

export interface TrainingChipProps {
  todayDone: boolean;
  onPress: () => void;
  disabled?: boolean;
}

export default function TrainingChip({ todayDone, onPress, disabled = false }: TrainingChipProps) {
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
      <GraduationCap color="#a5b4fc" size={16} />
      <Text style={styles.text}>{TRAINING_STRINGS.entry}</Text>
      {todayDone ? (
        <View style={styles.done} testID="hud-training-done">
          <Check color="#ffffff" size={10} />
        </View>
      ) : (
        <View style={styles.badge} testID="hud-training-badge" />
      )}
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
    backgroundColor: 'rgba(15, 23, 42, 0.85)',
    borderWidth: 1,
    borderColor: 'rgba(165, 180, 252, 0.35)',
  },
  text: { color: '#ffffff', fontSize: 14, fontWeight: '800' },
  badge: { width: 9, height: 9, borderRadius: 5, backgroundColor: '#f59e0b' },
  done: {
    width: 16,
    height: 16,
    borderRadius: 8,
    backgroundColor: '#10b981',
    alignItems: 'center',
    justifyContent: 'center',
  },
  pressed: { opacity: 0.75 },
  disabled: { opacity: 0.5 },
});
