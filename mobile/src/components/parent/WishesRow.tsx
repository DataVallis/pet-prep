/**
 * M6-W05 »Želje (N)« row on the parent dashboard: opens `WishesScreen` (open wishes, surprise
 * purchase, »Komu?«). `count` = the dashboard's `wishes_open_count`; the row is not rendered
 * at all when the server doesn't send it (older server → feature hidden).
 */

import { Pressable, StyleSheet, View } from 'react-native';
import { ChevronRight, Gift } from 'lucide-react-native';

import { Text } from '@/components/ui/Text';
import { PARENT_COLORS as C } from '@/components/parent/ParentUi';
import { t } from '@/i18n';
import { palette, radius } from '@/theme';

export default function WishesRow({ count, onPress }: { count: number; onPress: () => void }) {
  const title = t('wishes:parent.row', { count });
  const subtitle = count > 0 ? t('wishes:parent.rowSubtitle', { count }) : t('wishes:parent.rowSubtitleNone');
  return (
    <Pressable
      style={({ pressed }) => [styles.row, count > 0 && styles.rowActive, pressed && styles.pressed]}
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel={`${title}. ${subtitle}`}
      testID="dashboard-wishes"
    >
      <View style={styles.icon}>
        <Gift color={C.accent} size={18} />
      </View>
      <View style={styles.texts}>
        <Text style={styles.title}>{title}</Text>
        <Text style={styles.subtitle}>{subtitle}</Text>
      </View>
      {count > 0 && <View style={styles.dot} testID="dashboard-wishes-dot" />}
      <ChevronRight color={C.muted} size={18} />
    </Pressable>
  );
}

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    minHeight: 56,
    paddingHorizontal: 14,
    paddingVertical: 10,
    borderRadius: radius.card,
    borderWidth: 1,
    borderColor: C.border,
    backgroundColor: C.card,
  },
  rowActive: { borderColor: C.accentBorder },
  icon: { width: 36, height: 36, borderRadius: 18, alignItems: 'center', justifyContent: 'center', backgroundColor: C.accentSoft },
  texts: { flex: 1, gap: 2 },
  title: { fontSize: 15, fontWeight: '700', color: C.text },
  subtitle: { fontSize: 13, color: C.muted },
  dot: { width: 8, height: 8, borderRadius: 4, backgroundColor: palette.raspberry },
  pressed: { opacity: 0.85 },
});
