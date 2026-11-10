/**
 * M6-U01 (FREE_PET_UPGRADE_SPEC §5.3, David 2026-11-10): the family holds a paid 12-week
 * challenge that is on no pet yet — the webhook arrived after the app stopped waiting, the
 * app was closed / offline, or the webhook could not choose (several pets waiting) — while a
 * pet could take it (a pet waiting for the purchase or an upgradeable free pet). The dashboard
 * says so on launch: »Imate neporabljen nakup — izberite ljubljenčka«; the button opens the
 * paywall, where »Uporabi kupljen izziv« assigns it. Rule: `showUnusedPurchasePrompt`
 * (server billing + dashboard). Parent app only, no prices.
 */

import { Pressable, StyleSheet, View } from 'react-native';

import { Text } from '@/components/ui/Text';
import { PARENT_COLORS as C } from '@/components/parent/ParentUi';
import { t } from '@/i18n';
import type { FamilyOverview } from '@/modules/family/family';
import { showUnusedPurchasePrompt } from '@/modules/plan/purchaseEntry';
import { useBilling } from '@/modules/purchases';
import { palette } from '@/theme';

export default function UnusedPurchasePrompt({ family, onChoose }: { family: FamilyOverview | null; onChoose: () => void }) {
  const billing = useBilling();
  if (!showUnusedPurchasePrompt(billing.data, family)) return null;

  return (
    <View style={styles.box} testID="unused-purchase-prompt">
      <Text style={styles.title}>{t('paywall:unusedPurchase.title')}</Text>
      <Text style={styles.body}>{t('paywall:unusedPurchase.body')}</Text>
      <Pressable
        style={({ pressed }) => [styles.button, pressed && styles.pressed]}
        onPress={onChoose}
        accessibilityRole="button"
        testID="unused-purchase-choose"
      >
        <Text style={styles.buttonText}>{t('paywall:unusedPurchase.action')}</Text>
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  box: { padding: 14, borderRadius: 16, borderWidth: 1, gap: 6, backgroundColor: palette.okSoft, borderColor: palette.okBorder },
  title: { fontSize: 15, fontWeight: '700', color: C.text },
  body: { fontSize: 14, lineHeight: 20, color: C.text },
  button: { marginTop: 4, height: 44, borderRadius: 12, backgroundColor: C.accent, alignItems: 'center', justifyContent: 'center' },
  buttonText: { fontSize: 15, fontWeight: '700', color: palette.white },
  pressed: { opacity: 0.85 },
});
