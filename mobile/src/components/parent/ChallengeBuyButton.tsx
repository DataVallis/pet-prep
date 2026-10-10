/**
 * M5-F01 — the visible purchase entry: "12-week challenge — buy" (parent app only; never in a
 * child session). Graphite primary button on the light parent UI, ≥ 44 pt, wraps at large
 * font sizes. Show it only when `canBuyChallenge(pet)`; it opens the paywall (`ChallengeScreen`),
 * which shows the store price — no price here.
 */

import { Pressable, StyleSheet } from 'react-native';
import { ShoppingBag } from 'lucide-react-native';

import { Text } from '@/components/ui/Text';
import { PARENT_COLORS as C } from '@/components/parent/ParentUi';
import { t, tSpecies } from '@/i18n';
import { MIN_TOUCH, palette, radius } from '@/theme';

interface ChallengeBuyButtonProps {
  onPress: () => void;
  /** Child nickname for the screen-reader label ("… for Mia's dog"); null → generic label. */
  childName?: string | null;
  /** M5-R06-09: species of the pet the purchase is for ("… for Mia's cat"). */
  species?: string | null;
  testID?: string;
}

export default function ChallengeBuyButton({ onPress, childName = null, species = null, testID }: ChallengeBuyButtonProps) {
  return (
    <Pressable
      style={({ pressed }) => [styles.button, pressed && styles.pressed]}
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel={childName ? tSpecies('paywall:entry.buyA11y', species, { name: childName }) : t('paywall:entry.buy')}
      accessibilityHint={t('paywall:entry.buyHint')}
      testID={testID}
    >
      <ShoppingBag color={palette.white} size={16} />
      <Text style={styles.text}>{t('paywall:entry.buy')}</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  button: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    minHeight: MIN_TOUCH,
    paddingHorizontal: 16,
    paddingVertical: 10,
    borderRadius: radius.button,
    backgroundColor: C.accent,
  },
  text: { flexShrink: 1, fontSize: 15, fontWeight: '700', color: palette.white, textAlign: 'center' },
  pressed: { opacity: 0.85 },
});
