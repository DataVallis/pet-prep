/**
 * Mood label (David 2026-11-05, option A): icon + one short line (»Lačen«, »Utrujen — rabi
 * sprehod«). What to show is decided only in `modules/petMood/petMood.ts`; this draws it —
 * `theme="dark"` in the child HUD header (glass), `theme="light"` on the parent cards.
 * One line, truncates at the tail; the whole label is one accessible text.
 */

import { StyleSheet, View, type StyleProp, type ViewStyle } from 'react-native';
import {
  BatteryLow,
  Droplet,
  Drumstick,
  House,
  Lock,
  Moon,
  Pause,
  Power,
  Signature,
  Smile,
  SprayCan,
  Stethoscope,
  Thermometer,
  Volleyball,
  type LucideIcon,
} from 'lucide-react-native';

import { Text } from '@/components/ui/Text';
import { moodText, type PetMood, type PetMoodIcon, type PetMoodTone } from '@/modules/petMood/petMood';
import { alpha, dark, light, palette } from '@/theme';

const ICONS: Readonly<Record<PetMoodIcon, LucideIcon>> = {
  moon: Moon,
  battery: BatteryLow,
  food: Drumstick,
  droplet: Droplet,
  thermometer: Thermometer,
  ball: Volleyball,
  smile: Smile,
  mess: SprayCan,
  pause: Pause,
  vet: Stethoscope,
  lock: Lock,
  shelter: House,
  off: Power,
  contract: Signature,
};

const DARK_TONES: Readonly<Record<PetMoodTone, string>> = {
  ok: dark.ok,
  warn: dark.warn,
  danger: dark.danger,
  neutral: palette.n300,
};

const LIGHT_TONES: Readonly<Record<PetMoodTone, string>> = {
  ok: light.ok,
  warn: light.warn,
  danger: light.danger,
  neutral: light.inkMuted,
};

export interface PetMoodLabelProps {
  mood: PetMood;
  species: string | null | undefined;
  theme: 'dark' | 'light';
  style?: StyleProp<ViewStyle>;
  testID?: string;
}

export default function PetMoodLabel({ mood, species, theme, style, testID }: PetMoodLabelProps) {
  const Icon = ICONS[mood.icon];
  const colour = (theme === 'dark' ? DARK_TONES : LIGHT_TONES)[mood.tone];
  const text = moodText(mood, species);
  return (
    <View
      style={[styles.row, style]}
      accessible
      accessibilityRole="text"
      accessibilityLabel={text}
      testID={testID}
    >
      <Icon color={colour} size={theme === 'dark' ? 12 : 14} strokeWidth={2} />
      <Text
        style={[styles.text, theme === 'dark' ? styles.textDark : styles.textLight]}
        numberOfLines={1}
        ellipsizeMode="tail"
        testID={testID ? `${testID}-text` : undefined}
      >
        {text}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'center', gap: 5, minWidth: 0 },
  text: { flexShrink: 1, fontWeight: '600' },
  textDark: { fontSize: 11, color: alpha(palette.white, 0.85) },
  textLight: { fontSize: 13, color: light.ink },
});
