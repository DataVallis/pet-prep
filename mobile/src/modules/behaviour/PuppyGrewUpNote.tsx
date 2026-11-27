/**
 * »{{name}} je zrasel!« — the calm HUD top-stack card on a dog's last puppy day once the potty
 * clock has ended (rule + memory in `puppyGrewUp.ts`, David 2026-11-27). Not a warning colour,
 * not urgent; × closes it for good on this device.
 */

import { Pressable, StyleSheet, View } from 'react-native';
import { Sparkles, X } from 'lucide-react-native';

import { Text } from '@/components/ui/Text';
import { t } from '@/i18n';
import { alpha, palette } from '@/theme';

export interface PuppyGrewUpNoteProps {
  text: string;
  onDismiss: () => void;
}

export default function PuppyGrewUpNote({ text, onDismiss }: PuppyGrewUpNoteProps) {
  return (
    <View style={styles.box} testID="hud-puppy-grew-up">
      <Sparkles color={palette.mint} size={14} />
      <Text style={styles.text} accessibilityLiveRegion="polite">
        {text}
      </Text>
      <Pressable
        testID="hud-puppy-grew-up-dismiss"
        accessibilityRole="button"
        accessibilityLabel={t('behaviour:child.grewUpDismiss')}
        onPress={onDismiss}
        hitSlop={12}
        style={({ pressed }) => [styles.close, pressed && styles.pressed]}
      >
        <X color={palette.n300} size={14} />
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  box: {
    maxWidth: '100%',
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: 6,
    paddingLeft: 12,
    paddingRight: 8,
    paddingVertical: 8,
    borderRadius: 14,
    backgroundColor: alpha(palette.graphite, 0.85),
    borderWidth: 1,
    borderColor: alpha(palette.mint, 0.3),
  },
  text: { flexShrink: 1, color: palette.n200, fontSize: 12, lineHeight: 17 },
  close: { padding: 2 },
  pressed: { opacity: 0.6 },
});
