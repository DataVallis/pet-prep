/**
 * M5-T11 »Prikaz: Video / Slika« — two pills (radio group), styled like `LanguageSwitch`:
 * dark tone in the child HUD sheet, light tone in the parent's family card. Controlled: the
 * caller passes the value (server / optimistic) and saves the change.
 */

import { Pressable, StyleSheet, View, type StyleProp, type ViewStyle } from 'react-native';
import { Image as ImageIcon, Video as VideoIcon } from 'lucide-react-native';

import { Text } from '@/components/ui/Text';
import { MEDIA_DISPLAYS, type MediaDisplay } from '@/modules/display/display';
import { MIN_TOUCH, alpha, light, palette, radius } from '@/theme';

interface MediaDisplaySwitchProps {
  value: MediaDisplay;
  onChange: (next: MediaDisplay) => void;
  /** Group label (screen readers). */
  label: string;
  /** Pill labels. */
  labels: Record<MediaDisplay, string>;
  tone?: 'light' | 'dark';
  disabled?: boolean;
  style?: StyleProp<ViewStyle>;
  testID?: string;
}

export default function MediaDisplaySwitch({ value, onChange, label, labels, tone = 'dark', disabled = false, style, testID = 'media-display' }: MediaDisplaySwitchProps) {
  const dark = tone === 'dark';
  return (
    <View style={[styles.row, style]} accessibilityRole="radiogroup" accessibilityLabel={label} testID={testID}>
      {MEDIA_DISPLAYS.map((display) => {
        const selected = display === value;
        const Icon = display === 'video' ? VideoIcon : ImageIcon;
        const iconColour = selected ? (dark ? palette.graphite : palette.white) : dark ? palette.white : light.ink;
        return (
          <Pressable
            key={display}
            onPress={() => {
              if (!selected) onChange(display);
            }}
            disabled={disabled}
            hitSlop={6}
            style={({ pressed }) => [
              styles.pill,
              dark && styles.pillDark,
              selected && (dark ? styles.pillDarkActive : styles.pillActive),
              (pressed || disabled) && styles.pressed,
            ]}
            accessibilityRole="radio"
            accessibilityState={{ selected, disabled }}
            accessibilityLabel={labels[display]}
            testID={`${testID}-${display}`}
          >
            <Icon color={iconColour} size={dark ? 18 : 15} />
            <Text style={[styles.text, dark && styles.textDark, selected && (dark ? styles.textDarkActive : styles.textActive)]}>
              {labels[display]}
            </Text>
          </Pressable>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', gap: 6, flexWrap: 'wrap' },
  pill: {
    minHeight: 32,
    paddingHorizontal: 12,
    borderRadius: radius.pill,
    borderWidth: 1,
    borderColor: light.border,
    backgroundColor: light.surface,
    alignItems: 'center',
    justifyContent: 'center',
    flexDirection: 'row',
    gap: 6,
  },
  pillActive: { backgroundColor: palette.graphite, borderColor: palette.graphite },
  pillDark: { minHeight: MIN_TOUCH, paddingHorizontal: 18, backgroundColor: alpha(palette.white, 0.08), borderColor: alpha(palette.white, 0.2) },
  pillDarkActive: { backgroundColor: palette.mint, borderColor: palette.mint },
  pressed: { opacity: 0.8 },
  text: { fontSize: 13, fontWeight: '600', color: light.ink },
  textActive: { color: palette.white },
  textDark: { fontSize: 15, color: palette.white },
  textDarkActive: { color: palette.graphite },
});
