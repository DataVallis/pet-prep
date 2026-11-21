/**
 * M5-08 H7 calm dashboard row »Dobili smo pravo žival« (below the children) with a »V pripravi«
 * badge — never reads as an available feature. Rendered only when the dashboard sends
 * `phase2_interest` (older server → hidden). After an answer the subtitle is the thanks.
 */

import { Pressable, StyleSheet, View } from 'react-native';
import { ChevronRight, Home } from 'lucide-react-native';

import { Text } from '@/components/ui/Text';
import { PARENT_COLORS as C } from '@/components/parent/ParentUi';
import { PHASE2_STRINGS as S, thanksKind, type Phase2InterestState } from '@/modules/validation/phase2';
import { radius } from '@/theme';

export default function Phase2Row({ state, onPress }: { state: Phase2InterestState; onPress: () => void }) {
  const subtitle = state.submitted ? (thanksKind(state.notify) === 'notify' ? S.thanksNotify : S.thanksPlain) : S.rowSubtitle;
  return (
    <Pressable
      style={({ pressed }) => [styles.row, pressed && styles.pressed]}
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel={`${S.rowTitle}. ${S.badge}. ${subtitle}`}
      testID="dashboard-phase2"
    >
      <View style={styles.icon}>
        <Home color={C.muted} size={18} />
      </View>
      <View style={styles.texts}>
        <View style={styles.titleRow}>
          <Text style={styles.title}>{S.rowTitle}</Text>
          <View style={styles.badge}>
            <Text style={styles.badgeText}>{S.badge}</Text>
          </View>
        </View>
        <Text style={styles.subtitle} testID="dashboard-phase2-subtitle">
          {subtitle}
        </Text>
      </View>
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
  icon: { width: 36, height: 36, borderRadius: 18, alignItems: 'center', justifyContent: 'center', backgroundColor: C.divider },
  texts: { flex: 1, gap: 2 },
  titleRow: { flexDirection: 'row', alignItems: 'center', flexWrap: 'wrap', gap: 8 },
  title: { fontSize: 15, fontWeight: '700', color: C.text },
  badge: { paddingHorizontal: 8, paddingVertical: 2, borderRadius: 999, backgroundColor: C.divider },
  badgeText: { fontSize: 11, fontWeight: '600', color: C.muted },
  subtitle: { fontSize: 13, color: C.muted },
  pressed: { opacity: 0.85 },
});
