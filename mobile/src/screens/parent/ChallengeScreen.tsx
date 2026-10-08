/**
 * ChallengeScreen — the parent paywall (M3-09, PAYMENTS_SPEC P1–P6): the 12-week challenge
 * for one dog, 49,99 € (consumable `petprep_challenge_12w`). M3-13 (David 2026-10-08): no
 * free trial — the challenge starts with a purchase (also before the dog is born).
 *
 * - Lists the family's dogs that can (or must) be bought now (`GET /api/parent/billing`:
 *   status `payment_required`, or `trial` for a dog that still runs a pre-M3-13 trial), with
 *   "the game is paused", "starts once bought" (not born yet) or when the game will pause.
 * - "Kupi za …" buys with the store price from the RevenueCat offering; the purchase is
 *   assigned to that dog (webhook auto-assign or `activateChallenge`). "Uporabi kupljen
 *   izziv" spends an unassigned credit without buying. "Obnovi nakupe" re-reads the store.
 * - Honest copy: one dog, no subscription, no automatic charge; the store shows the final
 *   price. Parent app only (password-protected = parental gate); never in a child session.
 * - Outcomes are kept as codes and translated at render (follow a language switch).
 */

import { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, BackHandler, Linking, Platform, Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { ChevronLeft, Check } from 'lucide-react-native';

import { ApiError, api } from '@/api/client';
import { Text } from '@/components/ui/Text';
import { Card, PARENT_COLORS as C } from '@/components/parent/ParentUi';
import { currentLanguageTag, t } from '@/i18n';
import { strings } from '@/i18n/strings';
import { parentDashboardKey } from '@/hooks/queries/useParentDashboard';
import { PRIVACY_URL, TERMS_URL } from '@/modules/auth/signup';
import { breedLabel, caretakerNames, type FamilyOverview } from '@/modules/family/family';
import { isBillingPetPurchasable } from '@/modules/plan/purchaseEntry';
import {
  BILLING_KEY,
  challengePackage,
  purchaseOutcomeMessage,
  restoreOutcomeMessage,
  useBilling,
  useOfferings,
  usePurchasePackage,
  useRestorePurchases,
  type PurchaseOutcome,
  type RestoreOutcome,
} from '@/modules/purchases';
import { fonts, palette, tightTracking } from '@/theme';

export const CHALLENGE_STRINGS = strings('paywall', 'challenge', {
  petLine: (breed: string, names: string) => t('paywall:challenge.petLine', { breed, names }),
  pausesAt: (when: string) => t('paywall:challenge.pausesAt', { when }),
  honest: (price: string) => t('paywall:challenge.honest', { price }),
  buy: (price: string) => t('paywall:challenge.buy', { price }),
  credits: (count: number) => t('paywall:challenge.credits', { count }),
});
const RESULT = strings('paywall', 'result');

const LIST_PRICE = '49,99 €';

type Message =
  | { kind: 'purchase'; outcome: PurchaseOutcome }
  | { kind: 'restore'; outcome: RestoreOutcome; activated: boolean | null }
  | { kind: 'activate'; result: 'unlocked' | 'noCredit' | 'refused' | 'failed' };

function messageText(m: Message): string {
  switch (m.kind) {
    case 'purchase':
      return m.outcome.status === 'success' && m.outcome.serverConfirmed ? RESULT.unlocked : purchaseOutcomeMessage(m.outcome);
    case 'restore':
      if (m.outcome.status === 'restored' && m.activated === true) return RESULT.restoredUnlocked;
      if (m.outcome.status === 'restored' && m.activated === false) return RESULT.restoredNoCredit;
      return restoreOutcomeMessage(m.outcome);
    case 'activate':
      return m.result === 'unlocked'
        ? RESULT.unlocked
        : m.result === 'noCredit'
          ? RESULT.noCredit
          : m.result === 'refused'
            ? RESULT.refused
            : RESULT.activateFailed;
  }
}

function isGoodMessage(m: Message): boolean {
  return (
    (m.kind === 'purchase' && m.outcome.status === 'success') ||
    (m.kind === 'restore' && m.outcome.status === 'restored') ||
    (m.kind === 'activate' && m.result === 'unlocked')
  );
}

/** "pon., 14. okt., 18:30" in the family's zone; the raw ISO string if Intl can't. */
export function formatTrialEnd(iso: string, timeZone: string): string {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return iso;
  try {
    return new Intl.DateTimeFormat(currentLanguageTag(), {
      weekday: 'short',
      day: 'numeric',
      month: 'short',
      hour: '2-digit',
      minute: '2-digit',
      timeZone,
    }).format(date);
  } catch {
    return iso;
  }
}

interface ChallengeScreenProps {
  family: FamilyOverview | null;
  onBack: () => void;
}

export default function ChallengeScreen({ family, onBack }: ChallengeScreenProps) {
  useTranslation(); // all text below is read at render
  const S = CHALLENGE_STRINGS;
  const queryClient = useQueryClient();
  // Android hardware back = the header "Nazaj" (M5-F01 QA): returns where the parent came from.
  const backRef = useRef(onBack);
  backRef.current = onBack;
  useEffect(() => {
    const sub = BackHandler.addEventListener('hardwareBackPress', () => {
      backRef.current();
      return true;
    });
    return () => sub.remove();
  }, []);
  const billing = useBilling();
  const offerings = useOfferings();
  const purchase = usePurchasePackage();
  const restore = useRestorePurchases();
  const [message, setMessage] = useState<Message | null>(null);
  const [activating, setActivating] = useState<number | null>(null);

  const pkg = challengePackage(offerings.data);
  const price = pkg?.product.priceString ?? null;
  const busy = purchase.isPending || restore.isPending || activating !== null;
  // M5-F01 QA: one shared rule with the buy buttons — a mutt / game-over pet is never listed.
  const waiting = (billing.data?.pets ?? []).filter((p) => isBillingPetPurchasable(p, family));
  const credits = billing.data?.credits_available ?? 0;
  const timezone = family?.timezone ?? 'Europe/Ljubljana';

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: BILLING_KEY });
    void queryClient.invalidateQueries({ queryKey: parentDashboardKey });
  };

  const activate = async (petId: number): Promise<Message> => {
    setActivating(petId);
    try {
      await api.activateChallenge(petId);
      return { kind: 'activate', result: 'unlocked' };
    } catch (error) {
      if (error instanceof ApiError && error.status === 409) return { kind: 'activate', result: 'noCredit' };
      if (error instanceof ApiError && error.status === 422) return { kind: 'activate', result: 'refused' };
      return { kind: 'activate', result: 'failed' };
    } finally {
      setActivating(null);
      refresh();
    }
  };

  const buy = (petId: number) => {
    if (!pkg || busy) return;
    setMessage(null);
    purchase.mutate(
      { pkg, target: { petId, baselineCredits: credits } },
      {
        onSuccess: (outcome) => {
          setMessage({ kind: 'purchase', outcome });
          refresh();
        },
      },
    );
  };

  const restorePurchases = () => {
    if (busy) return;
    setMessage(null);
    restore.mutate(undefined, {
      // A restored purchase becomes a credit on the server; the parent picks the dog
      // ("Uporabi kupljen izziv") — never assigned automatically (QA PR #67 m1).
      onSuccess: (outcome) => {
        setMessage({ kind: 'restore', outcome, activated: null });
        refresh();
      },
    });
  };

  return (
    <View style={styles.root} testID="challenge-screen">
      <View style={styles.header}>
        <Pressable onPress={onBack} hitSlop={8} accessibilityRole="button" accessibilityLabel={S.back}>
          <ChevronLeft color={C.accent} size={26} />
        </Pressable>
        <Text style={styles.title} accessibilityRole="header">
          {S.title}
        </Text>
      </View>

      <ScrollView contentContainerStyle={styles.content}>
        <Text style={styles.intro}>{S.intro}</Text>

        <Card>
          <Text style={styles.sectionTitle}>{S.includesTitle}</Text>
          {(['program', 'breed', 'certificate', 'history', 'media'] as const).map((key) => (
            <View key={key} style={styles.includeRow}>
              <Check color={C.accent} size={16} />
              <Text style={styles.body}>{S.includes[key]}</Text>
            </View>
          ))}
          <Text style={styles.honest}>{S.honest(price ?? LIST_PRICE)}</Text>
          <Text style={styles.muted}>{S.priceEstimate}</Text>
        </Card>

        {message && (
          <View style={[styles.message, isGoodMessage(message) ? styles.messageOk : styles.messageWarn]} testID="challenge-message">
            <Text style={styles.body}>{messageText(message)}</Text>
          </View>
        )}

        {billing.isPending ? (
          <View style={styles.center}>
            <ActivityIndicator color={C.accent} />
            <Text style={styles.muted}>{S.loading}</Text>
          </View>
        ) : billing.isError ? (
          <Card>
            <Text style={styles.body}>{billing.error instanceof ApiError ? S.loadError : S.offline}</Text>
            <Pressable style={styles.secondaryButton} onPress={() => void billing.refetch()} accessibilityRole="button">
              <Text style={styles.secondaryText}>{S.retry}</Text>
            </Pressable>
          </Card>
        ) : waiting.length === 0 ? (
          <Card testID="challenge-all-paid">
            <Text style={styles.body}>{S.allPaid}</Text>
          </Card>
        ) : (
          <>
            <Text style={styles.sectionTitle}>{S.petsTitle}</Text>
            {credits > 0 && <Text style={styles.body}>{S.credits(credits)}</Text>}
            {waiting.map((pet) => {
              const familyPet = family?.pets.find((p) => p.id === pet.pet_id);
              const names = familyPet && family ? caretakerNames(familyPet, family) : '';
              // M3-13: an unborn dog is bought first; it starts playing once the contract is signed.
              const unborn = familyPet?.born_at === null;
              const paused = pet.status === 'payment_required' && !unborn;
              return (
                <Card key={pet.pet_id} testID={`challenge-pet-${pet.pet_id}`}>
                  <Text style={styles.petTitle}>{S.petLine(breedLabel(familyPet?.breed_type ?? 'mutt'), names)}</Text>
                  <Text style={[styles.body, paused && styles.pausedText]}>
                    {unborn
                      ? S.unborn
                      : paused
                        ? S.paused
                        : pet.trial_ends_at
                          ? S.pausesAt(formatTrialEnd(pet.trial_ends_at, timezone))
                          : S.notBought}
                  </Text>
                  {credits > 0 ? (
                    <Pressable
                      style={({ pressed }) => [styles.primaryButton, busy && styles.disabled, pressed && styles.pressed]}
                      disabled={busy}
                      onPress={() => void activate(pet.pet_id).then(setMessage)}
                      accessibilityRole="button"
                      testID={`challenge-use-credit-${pet.pet_id}`}
                    >
                      <Text style={styles.primaryText}>{activating === pet.pet_id ? S.busy : S.useCredit}</Text>
                    </Pressable>
                  ) : (
                    <Pressable
                      style={({ pressed }) => [styles.primaryButton, (busy || !pkg) && styles.disabled, pressed && styles.pressed]}
                      disabled={busy || !pkg}
                      onPress={() => buy(pet.pet_id)}
                      accessibilityRole="button"
                      testID={`challenge-buy-${pet.pet_id}`}
                    >
                      <Text style={styles.primaryText}>{purchase.isPending ? S.busy : price ? S.buy(price) : S.buyNoPrice}</Text>
                    </Pressable>
                  )}
                </Card>
              );
            })}
            {credits === 0 && !pkg && (
              <View style={styles.center} testID="challenge-store-state">
                {offerings.isPending ? (
                  <Text style={styles.muted}>{S.priceLoading}</Text>
                ) : (
                  <>
                    <Text style={styles.muted}>{S.offeringMissing}</Text>
                    <Pressable style={styles.secondaryButton} onPress={() => void offerings.refetch()} accessibilityRole="button">
                      <Text style={styles.secondaryText}>{S.retryStore}</Text>
                    </Pressable>
                  </>
                )}
              </View>
            )}
          </>
        )}

        <Pressable
          style={({ pressed }) => [styles.secondaryButton, busy && styles.disabled, pressed && styles.pressed]}
          disabled={busy}
          onPress={restorePurchases}
          accessibilityRole="button"
          testID="challenge-restore"
        >
          <Text style={styles.secondaryText}>{restore.isPending ? S.busy : S.restore}</Text>
        </Pressable>
        <Text style={styles.muted}>{S.storeNote}</Text>
        <View style={styles.links}>
          <Text style={styles.link} onPress={() => void Linking.openURL(TERMS_URL)} accessibilityRole="link">
            {S.terms}
          </Text>
          <Text style={styles.link} onPress={() => void Linking.openURL(PRIVACY_URL)} accessibilityRole="link">
            {S.privacy}
          </Text>
        </View>
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: C.bg },
  header: {
    paddingTop: Platform.OS === 'ios' ? 56 : 40,
    paddingHorizontal: 16,
    paddingBottom: 12,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    backgroundColor: C.card,
    borderBottomWidth: 1,
    borderBottomColor: C.border,
  },
  title: { fontSize: 20, letterSpacing: tightTracking(20), fontFamily: fonts.display, color: C.text },
  content: { padding: 16, gap: 14, paddingBottom: 40 },
  intro: { fontSize: 15, lineHeight: 21, color: C.text },
  sectionTitle: { fontSize: 13, fontWeight: '700', color: C.muted, textTransform: 'uppercase', letterSpacing: 0.4, marginBottom: 6 },
  includeRow: { flexDirection: 'row', gap: 8, alignItems: 'flex-start', marginBottom: 6 },
  body: { flex: 1, fontSize: 14, lineHeight: 20, color: C.text },
  honest: { marginTop: 8, fontSize: 14, lineHeight: 20, fontWeight: '600', color: C.text },
  muted: { fontSize: 12, lineHeight: 17, color: C.muted },
  petTitle: { fontSize: 16, fontWeight: '700', color: C.text, marginBottom: 4 },
  pausedText: { color: C.redText },
  message: { padding: 12, borderRadius: 12, borderWidth: 1 },
  messageOk: { backgroundColor: palette.okSoft, borderColor: palette.okBorder },
  messageWarn: { backgroundColor: palette.warnSoft, borderColor: palette.warnBorder },
  center: { alignItems: 'center', gap: 8, paddingVertical: 12 },
  primaryButton: { marginTop: 12, height: 50, borderRadius: 14, backgroundColor: C.accent, alignItems: 'center', justifyContent: 'center' },
  primaryText: { fontSize: 16, fontWeight: '700', color: palette.white },
  secondaryButton: {
    height: 48,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: C.border,
    backgroundColor: C.card,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: 4,
  },
  secondaryText: { fontSize: 15, fontWeight: '700', color: C.accent },
  links: { flexDirection: 'row', gap: 20, justifyContent: 'center' },
  link: { fontSize: 13, color: palette.mintDeep, textDecorationLine: 'underline' },
  disabled: { opacity: 0.5 },
  pressed: { opacity: 0.85 },
});
