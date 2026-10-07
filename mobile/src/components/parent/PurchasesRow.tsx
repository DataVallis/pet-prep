/**
 * M5-F01 — "Purchases / challenge" row in the parent "Nadzor" tab. Opens the paywall
 * (`ChallengeScreen`: buy, use a purchased challenge, restore purchases). The subtitle says
 * how many dogs wait for a purchase (`petsAwaitingPurchase`, same rule as the buy button).
 * Rendered only while the family has a challenge dog (`showPurchasesRow`).
 */

import { Pressable, StyleSheet, View } from 'react-native';
import { ChevronRight, ShoppingBag } from 'lucide-react-native';

import { Text } from '@/components/ui/Text';
import { PARENT_COLORS as C } from '@/components/parent/ParentUi';
import { t } from '@/i18n';
import type { FamilyOverview } from '@/modules/family/family';
import { petsAwaitingPurchase } from '@/modules/plan/purchaseEntry';
import { radius } from '@/theme';

interface PurchasesRowProps {
  family: FamilyOverview;
  onPress: () => void;
}

/** "1 dog is waiting for a purchase" · "All challenges are paid …". */
export function purchasesRowSubtitle(family: FamilyOverview): string {
  const waiting = petsAwaitingPurchase(family).length;
  return waiting > 0 ? t('paywall:entry.rowWaiting', { count: waiting }) : t('paywall:entry.rowAllPaid');
}

export default function PurchasesRow({ family, onPress }: PurchasesRowProps) {
  const title = t('paywall:entry.rowTitle');
  const subtitle = purchasesRowSubtitle(family);
  return (
    <Pressable
      style={({ pressed }) => [styles.row, pressed && styles.pressed]}
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel={`${title}. ${subtitle}`}
      testID="controls-purchases"
    >
      <View style={styles.icon}>
        <ShoppingBag color={C.accent} size={18} />
      </View>
      <View style={styles.texts}>
        <Text style={styles.title}>{title}</Text>
        <Text style={styles.subtitle} testID="controls-purchases-subtitle">
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
    padding: 14,
    borderRadius: radius.card,
    borderWidth: 1,
    borderColor: C.border,
    backgroundColor: C.card,
  },
  icon: {
    width: 36,
    height: 36,
    borderRadius: radius.pill,
    backgroundColor: C.accentSoft,
    alignItems: 'center',
    justifyContent: 'center',
  },
  texts: { flex: 1, gap: 2 },
  title: { fontSize: 15, fontWeight: '700', color: C.text },
  subtitle: { fontSize: 13, lineHeight: 18, color: C.muted },
  pressed: { opacity: 0.85 },
});
