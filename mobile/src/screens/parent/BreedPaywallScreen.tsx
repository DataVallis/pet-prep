/**
 * BreedPaywallScreen — RevenueCat breed selection paywall.
 *
 * Two breed cards side by side: Mutt (free tier) and Border Collie
 * (premium, €4.99 one-time unlock). Includes placeholder
 * react-native-purchases integration and restore-purchases flow.
 */

import { useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  Pressable,
  ScrollView,
  Text,
  View,
} from 'react-native';
import { ChevronLeft, Check, Crown, Footprints, Lock, RotateCcw } from 'lucide-react-native';

import { useAppStore } from '@/store/appStore';

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
    <View className="flex-1 bg-slate-950">
      {/* Header */}
      <View className="flex-row items-center gap-3 bg-slate-900 px-4 py-4 border-b border-slate-800">
        <Pressable onPress={onBack} hitSlop={8}>
          <ChevronLeft color="#818cf8" size={28} />
        </Pressable>
        <Text className="text-xl font-bold text-slate-100">Breed Selection</Text>
      </View>

      <ScrollView className="flex-1" contentContainerClassName="p-4 pb-6">
        <Text className="mb-4 text-sm text-slate-500">
          Choose the breed that fits your child's activity level.
        </Text>

        {/* Breed cards side by side */}
        <View className="flex-row gap-3">
          {/* Mutt (Free) */}
          <View className="flex-1 border border-slate-700 rounded-2xl p-6 bg-slate-900">
            <Text className="text-lg font-bold text-slate-100">Mutt</Text>
            <View className="mt-2 self-start rounded-full bg-slate-800 px-3 py-1">
              <Text className="text-xs font-bold text-slate-400">FREE</Text>
            </View>

            <View className="mt-4 gap-2">
              <View className="flex-row items-center gap-2">
                <Footprints color="#64748b" size={18} />
                <Text className="text-sm text-slate-400">
                  {mutt.dailySteps.toLocaleString('en-US')} steps/day
                </Text>
              </View>
              <Text className="text-sm text-slate-400">Decay: {mutt.decayRate}</Text>
            </View>

            <View className="mt-5 items-center">
              {pet?.breed_type === 'mutt' ? (
                <View className="flex-row items-center gap-1">
                  <Check color="#10B981" size={18} />
                  <Text className="text-sm font-semibold text-emerald-400">
                    Current Breed
                  </Text>
                </View>
              ) : (
                <Text className="text-sm text-slate-600">Starter breed</Text>
              )}
            </View>
          </View>

          {/* Border Collie (Premium) */}
          <View className="flex-1 border-2 border-indigo-500 rounded-2xl p-6 bg-slate-900">
            <View className="flex-row items-center justify-between">
              <Text className="text-lg font-bold text-slate-100">Border Collie</Text>
              <Crown color="#818cf8" size={20} />
            </View>
            <View className="mt-2 self-start rounded-full bg-indigo-500/20 px-3 py-1">
              <Text className="text-xs font-bold text-indigo-400">
                RECOMMENDED · PREMIUM
              </Text>
            </View>

            <View className="mt-4 gap-2">
              <View className="flex-row items-center gap-2">
                <Footprints color="#818cf8" size={18} />
                <Text className="text-sm text-slate-300">
                  {collie.dailySteps.toLocaleString('en-US')} steps/day
                </Text>
              </View>
              <Text className="text-sm text-slate-300">Decay: {collie.decayRate}</Text>
            </View>

            <Text className="mt-3 text-2xl font-bold text-slate-100">
              {PREMIUM_PRICE}
            </Text>

            {isUnlocked ? (
              <View className="mt-4 flex-row items-center justify-center gap-1">
                <Check color="#10B981" size={18} />
                <Text className="text-sm font-semibold text-emerald-400">Unlocked</Text>
              </View>
            ) : (
              <Pressable
                className="mt-4 items-center rounded-xl bg-indigo-600 py-3.5 active:scale-95 disabled:opacity-50"
                onPress={handlePurchase}
                disabled={isPurchasing}
              >
                {isPurchasing ? (
                  <ActivityIndicator color="#ffffff" />
                ) : (
                  <View className="flex-row items-center gap-2">
                    <Lock color="#ffffff" size={18} />
                    <Text className="text-base font-bold text-white">Unlock Now</Text>
                  </View>
                )}
              </Pressable>
            )}
          </View>
        </View>

        {/* Restore Purchases */}
        <Pressable
          className="mt-6 flex-row items-center justify-center gap-2 self-center"
          onPress={handleRestore}
          disabled={isRestoring}
        >
          {isRestoring ? (
            <ActivityIndicator color="#818cf8" size={18} />
          ) : (
            <RotateCcw color="#818cf8" size={18} />
          )}
          <Text className="text-sm font-medium text-indigo-400">
            Restore Purchases
          </Text>
        </Pressable>
      </ScrollView>
    </View>
  );
}
