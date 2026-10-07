/**
 * Plan badge of a dog in the parent app (M3-09): "Brezplačno" · "Preizkus: še N dni" ·
 * "Preizkus: zadnji dan" · "Čaka na nakup" · "Plačano". Status tokens only, never prices.
 */

import { StyleSheet, View } from 'react-native';

import { Text } from '@/components/ui/Text';
import { t } from '@/i18n';
import { planBadge, type PetPlan, type PlanBadgeTone } from '@/modules/plan/plan';
import { palette } from '@/theme';

const TONES: Record<PlanBadgeTone, { bg: string; border: string; text: string }> = {
  neutral: { bg: palette.n100, border: palette.n200, text: palette.n700 },
  info: { bg: palette.mintSoft, border: palette.mintBorder, text: palette.mintDeep },
  ok: { bg: palette.okSoft, border: palette.okBorder, text: palette.ok },
  warn: { bg: palette.warnSoft, border: palette.warnBorder, text: palette.warn },
  danger: { bg: palette.dangerSoft, border: palette.dangerBorder, text: palette.danger },
};

export default function PlanBadge({ plan, testID }: { plan: PetPlan; testID?: string }) {
  const badge = planBadge(plan);
  const tone = TONES[badge.tone];
  return (
    <View
      style={[styles.badge, { backgroundColor: tone.bg, borderColor: tone.border }]}
      accessibilityLabel={t('paywall:plan.badgeA11y', { label: badge.label })}
      testID={testID}
    >
      <Text style={[styles.text, { color: tone.text }]}>{badge.label}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  badge: { alignSelf: 'flex-start', paddingHorizontal: 10, paddingVertical: 3, borderRadius: 999, borderWidth: 1 },
  text: { fontSize: 12, fontWeight: '700' },
});
