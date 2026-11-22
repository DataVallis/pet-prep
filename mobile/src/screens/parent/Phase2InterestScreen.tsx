/**
 * M5-08 H7 fake door (`docs/engineering/VALIDATION_API.md` §3): »Pripravljamo pomočnika za pravega ljubljenčka«.
 * Honest »v pripravi« screen — the helper does not exist; nothing here names a feature as
 * available. The price question (3 monthly options, D3), »Obvestite me, ko bo na voljo« + e-mail
 * (prefilled with the account e-mail), »Pošlji« → `POST /api/parent/phase2-interest` (422
 * `email_required` → the e-mail field). After sending, or when the dashboard already says
 * `submitted`, only the thank-you shows. The »viewed« signal is sent by the dashboard on the tap.
 */

import { useEffect, useRef, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { ActivityIndicator, BackHandler, KeyboardAvoidingView, Platform, Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { Check, ChevronLeft } from 'lucide-react-native';

import { Text, TextInput } from '@/components/ui/Text';
import { PARENT_COLORS as C } from '@/components/parent/ParentUi';
import { useSubmitPhase2Interest } from '@/hooks/queries/useValidationQueries';
import { parentDashboardKey } from '@/modules/family/live';
import {
  initialPhase2Form,
  mapPhase2Error,
  PHASE2_PRICE_CHOICES,
  PHASE2_STRINGS as S,
  phase2Body,
  phase2View,
  validatePhase2,
  withdrawBody,
  type Phase2Form,
  type Phase2FormError,
  type Phase2InterestState,
  type Phase2SendError,
} from '@/modules/validation/phase2';
import { fonts, light, MIN_TOUCH, palette, radius, tightTracking } from '@/theme';

interface Phase2InterestScreenProps {
  /** The dashboard's `phase2_interest` (null = unknown; the dashboard only opens this when present). */
  state: Phase2InterestState | null;
  accountEmail: string | null;
  onBack: () => void;
}

export default function Phase2InterestScreen({ state, accountEmail, onBack }: Phase2InterestScreenProps) {
  const backRef = useRef(onBack);
  backRef.current = onBack;
  useEffect(() => {
    const sub = BackHandler.addEventListener('hardwareBackPress', () => {
      backRef.current();
      return true;
    });
    return () => sub.remove();
  }, []);

  const submit = useSubmitPhase2Interest();
  const queryClient = useQueryClient();
  /** 422 `feature_unavailable`: the admin switch is off for this family → refetch, the row follows the server. */
  const refetchDashboard = () => void queryClient.invalidateQueries({ queryKey: parentDashboardKey });
  const [form, setForm] = useState<Phase2Form>(() => initialPhase2Form(accountEmail));
  const [error, setError] = useState<Phase2FormError | Phase2SendError | null>(null);
  /** Just sent here: whether the parent asked to be notified (decides the thank-you text). */
  const [sentNotify, setSentNotify] = useState<boolean | null>(null);

  const [withdrawError, setWithdrawError] = useState<Phase2SendError | null>(null);

  const view = phase2View(state, sentNotify);
  const thanks = view.thanks === 'notify' ? S.thanksNotify : S.thanksPlain;
  const busy = submit.isPending;
  const emailError = error === 'email_required' || error === 'email_invalid';

  const update = (next: Partial<Phase2Form>) => {
    setForm((prev) => ({ ...prev, ...next }));
    setError(null);
  };

  const send = async () => {
    if (busy) return;
    const invalid = validatePhase2(form);
    if (invalid !== null || form.price === null) {
      setError(invalid ?? 'price_required');
      return;
    }
    setError(null);
    try {
      await submit.mutateAsync(phase2Body({ ...form, price: form.price }));
      setSentNotify(form.notify);
    } catch (err) {
      const kind = mapPhase2Error(err);
      setError(kind);
      if (kind === 'unavailable') refetchDashboard();
    }
  };

  /** QA #1480 B1 (GDPR): withdraw »Obvestite me« — the price answer stays on the server. */
  const withdraw = async () => {
    if (busy) return;
    setWithdrawError(null);
    try {
      await submit.mutateAsync(withdrawBody());
      setSentNotify(false);
    } catch (err) {
      const kind = mapPhase2Error(err);
      setWithdrawError(kind === 'offline' || kind === 'unavailable' ? kind : 'failed');
      if (kind === 'unavailable') refetchDashboard();
    }
  };

  return (
    <View style={styles.root} testID="phase2-screen">
      <View style={styles.header}>
        <Pressable onPress={onBack} hitSlop={8} accessibilityRole="button" accessibilityLabel={S.back} testID="phase2-back">
          <ChevronLeft color={C.accent} size={26} />
        </Pressable>
        <Text style={styles.headerTitle} numberOfLines={1}>
          {S.rowTitle}
        </Text>
      </View>

      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.flex}>
        <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
          <View style={styles.badge}>
            <Text style={styles.badgeText}>{S.badge}</Text>
          </View>
          <Text style={styles.title} accessibilityRole="header">
            {S.title}
          </Text>
          {view.showBody && (
            <Text style={styles.body} testID="phase2-body">
              {S.body}
            </Text>
          )}

          {view.done ? (
            <View style={styles.thanksWrap}>
              <View style={styles.thanks} testID="phase2-thanks" accessibilityLiveRegion="polite">
                <Check color={C.green} size={20} />
                <Text style={styles.thanksText}>{thanks}</Text>
              </View>
              {view.canWithdraw && (
                <Pressable
                  style={({ pressed }) => [styles.secondary, busy && styles.disabled, pressed && styles.pressed]}
                  onPress={() => void withdraw()}
                  disabled={busy}
                  accessibilityRole="button"
                  accessibilityState={{ disabled: busy, busy }}
                  testID="phase2-withdraw"
                >
                  {busy ? <ActivityIndicator color={C.accent} /> : <Text style={styles.secondaryText}>{S.withdraw}</Text>}
                </Pressable>
              )}
              {withdrawError && (
                <Text style={styles.error} accessibilityRole="alert" testID="phase2-withdraw-error">
                  {S.errors[withdrawError]}
                </Text>
              )}
            </View>
          ) : (
            <View style={styles.card}>
              <Text style={styles.question}>{S.question}</Text>
              <View accessibilityRole="radiogroup" accessibilityLabel={S.question} style={styles.options}>
                {PHASE2_PRICE_CHOICES.map((price) => {
                  const selected = form.price === price;
                  return (
                    <Pressable
                      key={price}
                      style={({ pressed }) => [styles.option, selected && styles.optionOn, pressed && styles.pressed]}
                      onPress={() => update({ price })}
                      disabled={busy}
                      accessibilityRole="radio"
                      accessibilityState={{ checked: selected, disabled: busy }}
                      testID={`phase2-price-${price}`}
                    >
                      <View style={[styles.radio, selected && styles.radioOn]}>{selected && <View style={styles.radioDot} />}</View>
                      <Text style={styles.optionText}>{S.prices[price]}</Text>
                    </Pressable>
                  );
                })}
              </View>
              {error === 'price_required' && <Text style={styles.error} accessibilityRole="alert" testID="phase2-error-price">{S.errors.price_required}</Text>}

              <Pressable
                style={styles.notifyRow}
                onPress={() => update({ notify: !form.notify })}
                disabled={busy}
                accessibilityRole="checkbox"
                accessibilityState={{ checked: form.notify, disabled: busy }}
                testID="phase2-notify"
              >
                <View style={[styles.box, form.notify && styles.boxOn]}>{form.notify && <Check color={palette.white} size={14} />}</View>
                <Text style={styles.optionText}>{S.notify}</Text>
              </Pressable>
              {form.notify && (
                <TextInput
                  style={[styles.input, emailError && styles.inputInvalid]}
                  value={form.email}
                  onChangeText={(email) => update({ email })}
                  placeholder={S.emailPlaceholder}
                  placeholderTextColor={light.inkFaint}
                  accessibilityLabel={S.emailPlaceholder}
                  keyboardType="email-address"
                  autoCapitalize="none"
                  autoCorrect={false}
                  autoComplete="email"
                  textContentType="emailAddress"
                  editable={!busy}
                  testID="phase2-email"
                />
              )}
              {emailError && (
                <Text style={styles.error} accessibilityRole="alert" testID="phase2-error-email">
                  {S.errors[error]}
                </Text>
              )}
              {(error === 'offline' || error === 'failed' || error === 'unavailable') && (
                <Text style={styles.error} accessibilityRole="alert" testID="phase2-error">
                  {S.errors[error]}
                </Text>
              )}

              <Pressable
                style={({ pressed }) => [styles.primary, busy && styles.disabled, pressed && styles.pressed]}
                onPress={() => void send()}
                disabled={busy}
                accessibilityRole="button"
                accessibilityState={{ disabled: busy, busy }}
                testID="phase2-submit"
              >
                {busy ? <ActivityIndicator color={C.card} /> : <Text style={styles.primaryText}>{S.submit}</Text>}
              </Pressable>
            </View>
          )}
        </ScrollView>
      </KeyboardAvoidingView>
    </View>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: C.bg },
  flex: { flex: 1 },
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
  headerTitle: { flex: 1, fontSize: 18, letterSpacing: tightTracking(18), fontFamily: fonts.display, color: C.text },
  content: { padding: 16, gap: 12, paddingBottom: 40 },
  badge: { alignSelf: 'flex-start', paddingHorizontal: 10, paddingVertical: 3, borderRadius: 999, backgroundColor: C.divider },
  badgeText: { fontSize: 12, fontWeight: '600', color: C.muted },
  title: { fontFamily: fonts.display, fontSize: 22, letterSpacing: tightTracking(22), color: C.text },
  body: { fontSize: 15, lineHeight: 21, color: C.muted },
  card: { borderRadius: radius.card, padding: 16, gap: 12, backgroundColor: C.card, borderWidth: 1, borderColor: C.border },
  question: { fontSize: 15, fontWeight: '700', color: C.text },
  options: { gap: 6 },
  option: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    minHeight: MIN_TOUCH,
    paddingHorizontal: 12,
    borderRadius: radius.button,
    borderWidth: 1,
    borderColor: C.border,
  },
  optionOn: { backgroundColor: C.accentSoft, borderColor: C.accentBorder },
  radio: { width: 20, height: 20, borderRadius: 10, borderWidth: 2, borderColor: C.accent, alignItems: 'center', justifyContent: 'center' },
  radioOn: { borderColor: C.accent },
  radioDot: { width: 10, height: 10, borderRadius: 5, backgroundColor: C.accent },
  optionText: { flex: 1, fontSize: 14, color: C.text },
  notifyRow: { flexDirection: 'row', alignItems: 'center', gap: 10, minHeight: MIN_TOUCH },
  box: { width: 22, height: 22, borderRadius: 6, borderWidth: 2, borderColor: C.accent, alignItems: 'center', justifyContent: 'center' },
  boxOn: { backgroundColor: C.accent },
  input: {
    height: 48,
    paddingHorizontal: 14,
    borderRadius: radius.button,
    borderWidth: 1,
    borderColor: C.border,
    backgroundColor: C.card,
    fontSize: 15,
    color: C.text,
  },
  inputInvalid: { borderColor: C.red },
  error: { fontSize: 13, color: C.redText },
  primary: { minHeight: 48, borderRadius: 14, backgroundColor: C.accent, alignItems: 'center', justifyContent: 'center' },
  primaryText: { fontSize: 15, fontWeight: '700', color: C.card },
  thanksWrap: { gap: 10 },
  secondary: {
    minHeight: MIN_TOUCH,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: C.border,
    backgroundColor: C.card,
    alignItems: 'center',
    justifyContent: 'center',
  },
  secondaryText: { fontSize: 15, fontWeight: '600', color: C.text },
  thanks: { flexDirection: 'row', alignItems: 'center', gap: 10, padding: 16, borderRadius: radius.card, backgroundColor: C.greenSoft },
  thanksText: { flex: 1, fontSize: 15, fontWeight: '600', color: C.text },
  disabled: { opacity: 0.5 },
  pressed: { opacity: 0.85 },
});
