/**
 * Plan badge of a dog in the parent app (M3-09; M3-13 — no trial wording): "Brezplačno" ·
 * "Čaka na nakup" · "Plačano" (and for a pre-M3-13 trial "Ni kupljeno: ustavi se čez N dni").
 * Status tokens only, never prices.
 */

import { Pressable, StyleSheet, View } from 'react-native';

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

export default function PlanBadge({ plan, onPress, testID }: { plan: PetPlan; onPress?: () => void; testID?: string }) {
  const badge = planBadge(plan);
  const tone = TONES[badge.tone];
  const label = t('paywall:plan.badgeA11y', { label: badge.label });
  const content = <Text style={[styles.text, { color: tone.text }]}>{badge.label}</Text>;
  const style = [styles.badge, { backgroundColor: tone.bg, borderColor: tone.border }];
  if (onPress) {
    return (
      <Pressable style={style} onPress={onPress} hitSlop={10} accessibilityRole="button" accessibilityLabel={label} testID={testID}>
        {content}
      </Pressable>
    );
  }
  return (
    <View style={style} accessibilityLabel={label} testID={testID}>
      {content}
    </View>
  );
}

const styles = StyleSheet.create({
  badge: { alignSelf: 'flex-start', paddingHorizontal: 10, paddingVertical: 3, borderRadius: 999, borderWidth: 1 },
  text: { fontSize: 12, fontWeight: '700' },
});
