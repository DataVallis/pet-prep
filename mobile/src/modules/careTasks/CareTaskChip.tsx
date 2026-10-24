/**
 * The breed care chips above the HUD dock (M5-R14 / R15 / R19 / R20): »Umij zobke«,
 * »Obriši gubice«, »Očisti ušesa«, »Pristriži kremplje« — glass chips like »Počeši«, shown
 * while the task's period still has an open slot. A small amber dot = it can be done now.
 * Tapping opens the mini-game, which explains a block with its time; while the pet sleeps the
 * chip is off with the reason read out (»💤 Kuža spi do 7:00«).
 */

import { Pressable, StyleSheet, View } from 'react-native';
import { Droplets, Ear, Scissors, Sparkles } from 'lucide-react-native';

import { Text } from '@/components/ui/Text';
import { careTaskText, careTaskTitle, type ChildCareTask } from '@/modules/careTasks/careTasks';
import { MIN_TOUCH, alpha, palette } from '@/theme';

const ICONS = { tooth_brushing: Sparkles, fold_cleaning: Droplets, ear_cleaning: Ear, nail_trim: Scissors } as const;

export interface CareTaskChipProps {
  task: ChildCareTask;
  onPress: () => void;
  disabled?: boolean;
  disabledReason?: string;
}

export function CareTaskChip({ task, onPress, disabled = false, disabledReason }: CareTaskChipProps) {
  const Icon = ICONS[task.task];
  return (
    <Pressable
      testID={`hud-care-chip-${task.task}`}
      accessibilityRole="button"
      accessibilityLabel={careTaskText(task.task, 'chipA11y')}
      accessibilityHint={disabled ? disabledReason : undefined}
      accessibilityState={{ disabled }}
      disabled={disabled}
      onPress={onPress}
      hitSlop={6}
      style={({ pressed }) => [styles.chip, pressed && styles.pressed, disabled && styles.disabled]}
    >
      <Icon color={palette.mint} size={16} />
      <Text style={styles.text}>{careTaskTitle(task.task)}</Text>
      {task.can_start && !disabled && <View style={styles.badge} testID={`hud-care-chip-${task.task}-badge`} />}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  chip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    minHeight: MIN_TOUCH,
    paddingHorizontal: 14,
    borderRadius: 20,
    backgroundColor: alpha(palette.graphite, 0.85),
    borderWidth: 1,
    borderColor: alpha(palette.mint, 0.35),
  },
  text: { color: palette.white, fontSize: 14, fontWeight: '800' },
  badge: { width: 9, height: 9, borderRadius: 5, backgroundColor: palette.warnDark },
  disabled: { opacity: 0.5 },
  pressed: { opacity: 0.75 },
});
