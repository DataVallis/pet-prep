/**
 * M5-R14 »Prevroče za sprehod« — a calm note (not a refusal, not a warning colour) for a
 * flat-faced breed at summer midday (server `walk_heat`, by the clock). The walk stays
 * possible and the steps still count — no penalty (David 2026-10-24).
 */

import { StyleSheet, View } from 'react-native';
import { Sun } from 'lucide-react-native';

import { Text } from '@/components/ui/Text';
import { t } from '@/i18n';
import { alpha, palette } from '@/theme';

export default function HeatNote({ testID = 'hud-heat' }: { testID?: string }) {
  const title = t('careTasks:heat.title');
  const body = t('careTasks:heat.text');
  return (
    <View style={styles.box} testID={testID} accessible accessibilityRole="text" accessibilityLabel={`${title}. ${body}`}>
      <Sun color={palette.mint} size={14} />
      <Text style={styles.text}>
        <Text style={styles.title}>{title}. </Text>
        {body}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  box: {
    maxWidth: '100%',
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: 6,
    paddingHorizontal: 12,
    paddingVertical: 8,
    borderRadius: 14,
    backgroundColor: alpha(palette.graphite, 0.85),
    borderWidth: 1,
    borderColor: alpha(palette.mint, 0.3),
  },
  text: { flexShrink: 1, color: palette.n200, fontSize: 12, lineHeight: 17 },
  title: { color: palette.mint, fontWeight: '800' },
});
