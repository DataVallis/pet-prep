/**
 * M6-U01 (FREE_PET_UPGRADE_SPEC §5.3, David 2026-11-10): the family holds a paid 12-week
 * challenge that is on no pet yet — the webhook arrived after the app stopped waiting, the
 * app was closed / offline, or the webhook could not choose (several pets waiting) — while a
 * pet could take it (a pet waiting for the purchase or an upgradeable free pet). The dashboard
 * says so on launch: »Imate neporabljen nakup — izberite ljubljenčka«; the button opens the
 * paywall, where »Uporabi kupljen izziv« assigns it. Rule: `showUnusedPurchasePrompt`
 * (server billing + dashboard; never while an unborn pet waits for it — P11). QA #978 f: »×«
 * hides it on this phone until a NEW unused purchase appears (`unusedPurchasePrefs`). Parent
 * app only, no prices.
 */

import { useEffect, useState } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';
import { X } from 'lucide-react-native';

import { Text } from '@/components/ui/Text';
import { PARENT_COLORS as C } from '@/components/parent/ParentUi';
import { t } from '@/i18n';
import type { FamilyOverview } from '@/modules/family/family';
import { showUnusedPurchasePrompt } from '@/modules/plan/purchaseEntry';
import { dismissedCountFor, lowerDismissal, setDismissedCount } from '@/modules/plan/unusedPurchasePrefs';
import { useBilling } from '@/modules/purchases';
import { useAppStore } from '@/store/appStore';
import { palette } from '@/theme';

export default function UnusedPurchasePrompt({ family, onChoose }: { family: FamilyOverview | null; onChoose: () => void }) {
  const billing = useBilling();
  const parentId = useAppStore((s) => s.user?.id ?? null);
  // null = not read yet (never flash the card before the dismissal is known).
  const [dismissed, setDismissed] = useState<number | null>(null);
  const credits = billing.data?.credits_available;

  useEffect(() => {
    if (parentId === null) return;
    let alive = true;
    void dismissedCountFor(parentId).then((count) => {
      if (alive) setDismissed(count);
    });
    return () => {
      alive = false;
    };
  }, [parentId]);

  // Purchases got used → the dismissal follows the count down (the next new one asks again).
  useEffect(() => {
    if (parentId === null || dismissed === null || credits === undefined) return;
    const lowered = lowerDismissal(dismissed, credits);
    if (lowered !== dismissed) {
      setDismissed(lowered);
      void setDismissedCount(parentId, lowered);
    }
  }, [parentId, dismissed, credits]);

  if (dismissed === null || !showUnusedPurchasePrompt(billing.data, family, dismissed)) return null;

  const dismiss = () => {
    if (parentId === null || credits === undefined) return;
    setDismissed(credits);
    void setDismissedCount(parentId, credits);
  };

  return (
    <View style={styles.box} testID="unused-purchase-prompt">
      <View style={styles.titleRow}>
        <Text style={[styles.title, styles.flex]}>{t('paywall:unusedPurchase.title')}</Text>
        <Pressable onPress={dismiss} hitSlop={12} accessibilityRole="button" accessibilityLabel={t('paywall:unusedPurchase.dismiss')} testID="unused-purchase-dismiss">
          <X color={C.muted} size={18} />
        </Pressable>
      </View>
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
  titleRow: { flexDirection: 'row', alignItems: 'flex-start', gap: 8 },
  flex: { flex: 1 },
  title: { fontSize: 15, fontWeight: '700', color: C.text },
  body: { fontSize: 14, lineHeight: 20, color: C.text },
  button: { marginTop: 4, height: 44, borderRadius: 12, backgroundColor: C.accent, alignItems: 'center', justifyContent: 'center' },
  buttonText: { fontSize: 15, fontWeight: '700', color: palette.white },
  pressed: { opacity: 0.85 },
});
