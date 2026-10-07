/**
 * Dashboard banner for the challenge (M3-09, PAYMENTS_SPEC P3): the game is paused for a
 * dog whose trial ended unpaid, or a trial ends within 24 h. One banner (paused wins),
 * naming the children who care for those dogs; the button opens the paywall.
 */

import { Pressable, StyleSheet, View } from 'react-native';

import { Text } from '@/components/ui/Text';
import { PARENT_COLORS as C } from '@/components/parent/ParentUi';
import { t } from '@/i18n';
import { caretakerNames, type FamilyOverview } from '@/modules/family/family';
import { planBanner } from '@/modules/plan/plan';
import { isOfferablePet } from '@/modules/plan/purchaseEntry';
import { palette } from '@/theme';

export default function ChallengeBanner({ family, onOpen }: { family: FamilyOverview | null; onOpen: () => void }) {
  if (!family) return null;
  // M5-F01 QA: the shared rule — a mutt / game-over pet is never offered a purchase.
  const offered = family.pets.filter((p) => p.is_active && isOfferablePet(p));
  const paused = offered.filter((p) => planBanner(p.plan) === 'payment_required');
  const ending = offered.filter((p) => planBanner(p.plan) === 'trial_last_day');
  const pets = paused.length > 0 ? paused : ending;
  if (pets.length === 0) return null;
  const isPaused = paused.length > 0;
  const names = pets.map((p) => caretakerNames(p, family)).filter((n) => n !== '').join(', ');

  return (
    <View style={[styles.box, isPaused ? styles.paused : styles.ending]} testID={isPaused ? 'challenge-banner-paused' : 'challenge-banner-ending'}>
      <Text style={styles.title}>{t(isPaused ? 'paywall:banner.paymentRequiredTitle' : 'paywall:banner.trialLastDayTitle')}</Text>
      <Text style={styles.body}>{t(isPaused ? 'paywall:banner.paymentRequired' : 'paywall:banner.trialLastDay', { names })}</Text>
      <Pressable
        style={({ pressed }) => [styles.button, pressed && styles.pressed]}
        onPress={onOpen}
        accessibilityRole="button"
        testID="challenge-banner-open"
      >
        <Text style={styles.buttonText}>{t(isPaused ? 'paywall:banner.open' : 'paywall:banner.openTrial')}</Text>
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  box: { padding: 14, borderRadius: 16, borderWidth: 1, gap: 6 },
  paused: { backgroundColor: palette.dangerSoft, borderColor: palette.dangerBorder },
  ending: { backgroundColor: palette.warnSoft, borderColor: palette.warnBorder },
  title: { fontSize: 15, fontWeight: '700', color: C.text },
  body: { fontSize: 14, lineHeight: 20, color: C.text },
  button: { marginTop: 4, height: 44, borderRadius: 12, backgroundColor: C.accent, alignItems: 'center', justifyContent: 'center' },
  buttonText: { fontSize: 15, fontWeight: '700', color: palette.white },
  pressed: { opacity: 0.85 },
});
