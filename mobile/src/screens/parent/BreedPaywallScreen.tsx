/**
 * BreedPaywallScreen — RevenueCat breed selection paywall.
 *
 * Two breed cards side by side: Mutt (free tier) and Border Collie
 * (premium, €4.99 one-time unlock). Includes placeholder
 * react-native-purchases integration and restore-purchases flow.
 */

import { useState } from 'react';
import { ActivityIndicator, Alert, Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { Text } from '@/components/ui/Text';
import { ChevronLeft, Check, Crown, Footprints, Lock, RotateCcw } from 'lucide-react-native';

import { useAppStore } from '@/store/appStore';
import { fonts, light, palette, radius, tightTracking } from '@/theme';

// RevenueCat integration placeholder.
// In production, import and configure Purchases here:
//   import { Purchases } from 'react-native-purchases';
//   await Purchases.configure({ apiKey: REVENUECAT_API_KEY });
//   const { customerInfo } = await Purchases.purchaseStoreProduct(product);

interface BreedPaywallScreenProps {
  /** Navigate back to the dashboard. */
  onBack: () => void;
}

interface BreedStats {
  dailySteps: number;
  decayRate: string;
}

const BREED_STATS: Record<'mutt' | 'border_collie', BreedStats> = {
  mutt: { dailySteps: 4_000, decayRate: '-8%/hr' },
  border_collie: { dailySteps: 10_000, decayRate: '-12%/hr' },
};

const PREMIUM_PRICE = '4.99 €';
const PREMIUM_ENTITLEMENT = 'border_collie_unlock';

export default function BreedPaywallScreen({ onBack }: BreedPaywallScreenProps) {
  const pet = useAppStore((s) => s.pet);

  const [isPurchasing, setIsPurchasing] = useState(false);
  const [isRestoring, setIsRestoring] = useState(false);
  const [isUnlocked, setIsUnlocked] = useState(
    pet?.breed_type === 'border_collie',
  );

  const handlePurchase = async () => {
    setIsPurchasing(true);
    try {
      // Placeholder: real RevenueCat flow
      // const { customerInfo } = await Purchases.purchaseStoreProduct(product);
      // if (customerInfo.entitlements.active[PREMIUM_ENTITLEMENT]) {
      //   setIsUnlocked(true);
      // }
      await new Promise((resolve) => setTimeout(resolve, 1_500));
      setIsUnlocked(true);
      Alert.alert('Success', 'Border Collie breed unlocked!');
    } catch (err) {
      Alert.alert(
        'Purchase Failed',
        err instanceof Error ? err.message : 'An unexpected error occurred.',
      );
    } finally {
      setIsPurchasing(false);
    }
  };

  const handleRestore = async () => {
    setIsRestoring(true);
    try {
      // Placeholder: real RevenueCat restore flow
      // const { customerInfo } = await Purchases.restorePurchases();
      // if (customerInfo.entitlements.active[PREMIUM_ENTITLEMENT]) {
      //   setIsUnlocked(true);
      // }
      await new Promise((resolve) => setTimeout(resolve, 1_500));
      Alert.alert('Restore', 'No previous purchases found.');
    } catch (err) {
      Alert.alert(
        'Restore Failed',
        err instanceof Error ? err.message : 'An unexpected error occurred.',
      );
    } finally {
      setIsRestoring(false);
    }
  };

  const mutt = BREED_STATS.mutt;
  const collie = BREED_STATS.border_collie;

  return (
    <View style={styles.root} testID="breed-paywall">
      {/* Header */}
      <View style={styles.header}>
        <Pressable onPress={onBack} hitSlop={8} accessibilityRole="button" testID="paywall-back">
          <ChevronLeft color={light.ink} size={28} />
        </Pressable>
        <Text style={styles.headerTitle}>Breed Selection</Text>
      </View>

      <ScrollView style={styles.scroll} contentContainerStyle={styles.scrollContent}>
        <Text style={styles.intro}>Choose the breed that fits your child's activity level.</Text>

        {/* Breed cards side by side */}
        <View style={styles.cards}>
          {/* Mutt (Free) */}
          <View style={styles.card} testID="paywall-card-mutt">
            <Text style={styles.cardTitle}>Mutt</Text>
            <View style={[styles.pill, styles.pillFree]}>
              <Text style={[styles.pillText, styles.pillTextFree]}>FREE</Text>
            </View>

            <View style={styles.stats}>
              <View style={styles.statRow}>
                <Footprints color={light.inkMuted} size={18} />
                <Text style={styles.statText}>{mutt.dailySteps.toLocaleString('en-US')} steps/day</Text>
              </View>
              <Text style={styles.statText}>Decay: {mutt.decayRate}</Text>
            </View>

            <View style={styles.cardFooter}>
              {pet?.breed_type === 'mutt' ? (
                <View style={styles.statusRow}>
                  <Check color={light.ok} size={18} />
                  <Text style={styles.statusText}>Current Breed</Text>
                </View>
              ) : (
                <Text style={styles.mutedText}>Starter breed</Text>
              )}
            </View>
          </View>

          {/* Border Collie (Premium) */}
          <View style={[styles.card, styles.cardPremium]} testID="paywall-card-collie">
            <View style={styles.cardTitleRow}>
              <Text style={styles.cardTitle}>Border Collie</Text>
              <Crown color={light.ink} size={20} />
            </View>
            <View style={[styles.pill, styles.pillPremium]}>
              <Text style={[styles.pillText, styles.pillTextPremium]}>RECOMMENDED · PREMIUM</Text>
            </View>

            <View style={styles.stats}>
              <View style={styles.statRow}>
                <Footprints color={light.ink} size={18} />
                <Text style={[styles.statText, styles.statTextPremium]}>
                  {collie.dailySteps.toLocaleString('en-US')} steps/day
                </Text>
              </View>
              <Text style={[styles.statText, styles.statTextPremium]}>Decay: {collie.decayRate}</Text>
            </View>

            <Text style={styles.price}>{PREMIUM_PRICE}</Text>

            {isUnlocked ? (
              <View style={[styles.statusRow, styles.unlockedRow]}>
                <Check color={light.ok} size={18} />
                <Text style={styles.statusText}>Unlocked</Text>
              </View>
            ) : (
              <Pressable
                onPress={handlePurchase}
                disabled={isPurchasing}
                accessibilityRole="button"
                accessibilityState={{ disabled: isPurchasing, busy: isPurchasing }}
                testID="paywall-unlock"
                style={({ pressed }) => [
                  styles.unlockButton,
                  isPurchasing && styles.disabled,
                  pressed && !isPurchasing && styles.pressed,
                ]}
              >
                {isPurchasing ? (
                  <ActivityIndicator color={palette.white} />
                ) : (
                  <View style={styles.statusRow}>
                    <Lock color={palette.white} size={18} />
                    <Text style={styles.unlockText}>Unlock Now</Text>
                  </View>
                )}
              </Pressable>
            )}
          </View>
        </View>

        {/* Restore Purchases */}
        <Pressable
          onPress={handleRestore}
          disabled={isRestoring}
          accessibilityRole="button"
          testID="paywall-restore"
          style={styles.restore}
        >
          {isRestoring ? <ActivityIndicator color={light.mintText} size={18} /> : <RotateCcw color={light.mintText} size={18} />}
          <Text style={styles.restoreText}>Restore Purchases</Text>
        </Pressable>
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: light.bg },
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    paddingHorizontal: 16,
    paddingVertical: 16,
    backgroundColor: light.surface,
    borderBottomWidth: 1,
    borderBottomColor: light.border,
  },
  headerTitle: { fontFamily: fonts.display, fontSize: 20, letterSpacing: tightTracking(20), color: light.ink },
  scroll: { flex: 1 },
  scrollContent: { padding: 16, paddingBottom: 24 },
  intro: { marginBottom: 16, fontSize: 14, color: light.inkMuted },
  cards: { flexDirection: 'row', gap: 12 },
  card: {
    flex: 1,
    padding: 16,
    borderRadius: radius.card,
    borderWidth: 1,
    borderColor: light.border,
    backgroundColor: light.surface,
  },
  // The premium card is the screen's one mint surface (CGP v2).
  cardPremium: { borderWidth: 2, borderColor: palette.mint, backgroundColor: palette.mintSoft },
  cardTitleRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 6 },
  cardTitle: { flexShrink: 1, fontFamily: fonts.displayBold, fontSize: 18, letterSpacing: tightTracking(18), color: light.ink },
  pill: { marginTop: 8, alignSelf: 'flex-start', borderRadius: radius.pill, paddingHorizontal: 10, paddingVertical: 4 },
  pillFree: { backgroundColor: light.surfaceMuted },
  pillPremium: { backgroundColor: palette.mint },
  pillText: { fontSize: 11, fontWeight: '700' },
  pillTextFree: { color: light.inkMuted },
  pillTextPremium: { color: palette.graphite },
  stats: { marginTop: 16, gap: 8 },
  statRow: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  statText: { flexShrink: 1, fontSize: 14, color: light.inkMuted },
  statTextPremium: { color: light.ink },
  cardFooter: { marginTop: 20, alignItems: 'center' },
  statusRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6 },
  statusText: { fontSize: 14, fontWeight: '600', color: light.ok },
  mutedText: { fontSize: 14, color: light.inkFaint },
  price: { marginTop: 12, fontFamily: fonts.display, fontSize: 26, letterSpacing: tightTracking(26), color: light.ink },
  unlockedRow: { marginTop: 16 },
  unlockButton: {
    marginTop: 16,
    alignItems: 'center',
    justifyContent: 'center',
    minHeight: 48,
    borderRadius: radius.button,
    paddingVertical: 14,
    backgroundColor: light.action,
  },
  unlockText: { fontSize: 16, fontWeight: '600', color: light.onAction },
  restore: {
    marginTop: 24,
    minHeight: 44,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    alignSelf: 'center',
  },
  restoreText: { fontSize: 14, fontWeight: '600', color: light.mintText },
  disabled: { opacity: 0.5 },
  pressed: { transform: [{ scale: 0.95 }] },
});
