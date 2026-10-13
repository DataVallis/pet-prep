/**
 * M5-R09c — »Nekaj novega o tvoji pasmi« (DID_YOU_KNOW_SPEC §3.1): a glass chip above the care
 * buttons on a day the sheet may open by itself but hasn't yet (the moment wasn't calm, or the
 * server refused). Tap → the same card. Calm: mint accent, no dot (the book carries the one
 * raspberry dot).
 */

import { Pressable, StyleSheet } from 'react-native';
import { BookOpen } from 'lucide-react-native';

import { Text } from '@/components/ui/Text';
import { t } from '@/i18n';
import { alpha, palette } from '@/theme';

export default function FactChip({ onPress }: { onPress: () => void }) {
  return (
    <Pressable
      testID="hud-fact-chip"
      accessibilityRole="button"
      accessibilityHint={t('facts:child.chipHint')}
      onPress={onPress}
      hitSlop={6}
      style={({ pressed }) => [styles.chip, pressed && styles.pressed]}
    >
      <BookOpen color={palette.mint} size={16} />
      <Text style={styles.text}>{t('facts:child.chip')}</Text>
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
  pressed: { opacity: 0.75 },
});
