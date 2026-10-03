/**
 * AddChildScreen — parent "Dodaj otroka" (M2-02, partial).
 *
 * Generates a 6-digit pairing PIN (`POST /api/parent/generate-pin`, valid 15 min,
 * 5 requests/min), shows it large with a live countdown and a "Nova koda" button,
 * and polls the dashboard while the PIN is valid so the screen confirms as soon
 * as the child has paired. Parent UI = clean light theme (ADR-007).
 */

import { useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Platform, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { CheckCircle, ChevronLeft, KeyRound, RefreshCw, Smartphone } from 'lucide-react-native';

import { useGeneratePin } from '@/hooks/queries/useGeneratePin';
import { hasPairedPet, useParentDashboard } from '@/hooks/queries/useParentDashboard';
import { useCountdown } from '@/hooks/useCountdown';
import { classifyPinError, formatCountdown, formatPin, type PinErrorKind } from '@/modules/pairing/pin';
import { refreshSessionPet } from '@/modules/session/logout';
import type { GeneratePinResponse } from '@/types';

/** All user-visible strings of this screen (extract to i18n with M1-18). */
export const ADD_CHILD_STRINGS = {
  title: 'Dodaj otroka',
  back: 'Nazaj',
  pinLabel: 'Koda za povezavo',
  instructions: 'Otrok naj na svojem telefonu odpre PetPrep in vtipka to kodo.',
  steps: [
    'Na otrokovem telefonu odpri PetPrep.',
    'Otrok vtipka 6-mestno kodo.',
    'Otrok podpiše Pogodbo o odgovornosti in kuža se rodi.',
  ],
  validFor: (time: string) => `Koda velja še ${time}`,
  expired: 'Koda je potekla. Ustvari novo.',
  newCode: 'Nova koda',
  generating: 'Ustvarjam kodo …',
  waiting: 'Čakam, da otrok vnese kodo …',
  oneTime: 'Koda deluje samo enkrat. Nova koda razveljavi prejšnjo.',
  pairedTitle: 'Otrok je povezan!',
  pairedBody: 'Kuža se je rodil. Na pregledu zdaj vidite njegove potrebe v živo.',
  toDashboard: 'Na pregled',
  errors: {
    rate_limited: (seconds: number) => `Preveč novih kod v kratkem času. Poskusite znova čez ${seconds} s.`,
    forbidden: 'Kodo lahko ustvari samo starševski račun.',
    unauthorized: 'Seja je potekla. Prijavite se znova.',
    offline: 'Ni povezave s strežnikom. Preverite internet in poskusite znova.',
    server: 'Kode trenutno ni bilo mogoče ustvariti. Poskusite znova.',
  } satisfies Record<PinErrorKind, string | ((seconds: number) => string)>,
  retry: 'Poskusi znova',
} as const;

const S = ADD_CHILD_STRINGS;
const PAIRING_POLL_MS = 5_000;

interface AddChildScreenProps {
  onBack: () => void;
}

export default function AddChildScreen({ onBack }: AddChildScreenProps) {
  const generate = useGeneratePin();
  const startedRef = useRef(false);
  const [cooldownUntil, setCooldownUntil] = useState<string | null>(null);
  // Last PIN the server issued. Kept across a failed "Nova koda" (e.g. 429): the
  // server only replaces the PIN on success, so the old one is still valid.
  const [pin, setPin] = useState<GeneratePinResponse | null>(null);

  const remaining = useCountdown(pin?.expires_at ?? null);
  const cooldown = useCountdown(cooldownUntil);
  const isExpired = pin !== null && remaining <= 0;

  const dashboard = useParentDashboard({
    refetchInterval: pin !== null && !isExpired ? PAIRING_POLL_MS : false,
  });
  const isPaired = hasPairedPet(dashboard.data);

  const pinError = useMemo(
    () => (generate.isError ? classifyPinError(generate.error) : null),
    [generate.isError, generate.error],
  );

  // First PIN as soon as the screen opens (guarded against a double effect run).
  useEffect(() => {
    if (startedRef.current) return;
    startedRef.current = true;
    generate.mutate(undefined, { onSuccess: setPin });
  }, [generate]);

  // 429 → block "Nova koda" for Retry-After seconds.
  useEffect(() => {
    if (pinError?.kind === 'rate_limited' && pinError.retryAfterSeconds) {
      setCooldownUntil(new Date(Date.now() + pinError.retryAfterSeconds * 1000).toISOString());
    }
  }, [pinError]);

  // Child paired → pull the new pet into the session (dashboard metrics + websocket).
  useEffect(() => {
    if (!isPaired) return;
    refreshSessionPet().catch(() => {
      // The dashboard query already shows the pet; the session pet syncs on next launch.
    });
  }, [isPaired]);

  const isCoolingDown = cooldown > 0;
  const canRequest = !generate.isPending && !isCoolingDown;
  const requestNewPin = () => {
    if (canRequest) generate.mutate(undefined, { onSuccess: setPin });
  };

  const errorText = (() => {
    if (!pinError) return null;
    if (pinError.kind === 'rate_limited') {
      if (cooldownUntil !== null && !isCoolingDown) return null; // wait is over — "Nova koda" works again
      return S.errors.rate_limited(isCoolingDown ? cooldown : pinError.retryAfterSeconds ?? 60);
    }
    return S.errors[pinError.kind];
  })();

  return (
    <View style={styles.root}>
      <View style={styles.header}>
        <Pressable onPress={onBack} hitSlop={8} accessibilityRole="button" accessibilityLabel={S.back}>
          <ChevronLeft color="#4f46e5" size={28} />
        </Pressable>
        <Text style={styles.headerTitle}>{S.title}</Text>
      </View>

      <ScrollView contentContainerStyle={styles.content}>
        {isPaired ? (
          <View style={styles.card} testID="add-child-paired">
            <CheckCircle color="#10b981" size={44} />
            <Text style={styles.pairedTitle}>{S.pairedTitle}</Text>
            <Text style={styles.body}>{S.pairedBody}</Text>
            <Pressable style={({ pressed }) => [styles.primaryButton, pressed && styles.pressed]} onPress={onBack}>
              <Text style={styles.primaryButtonText}>{S.toDashboard}</Text>
            </Pressable>
          </View>
        ) : (
          <>
            <View style={styles.card}>
              <View style={styles.iconBadge}>
                <KeyRound color="#4f46e5" size={22} />
              </View>
              <Text style={styles.pinLabel}>{S.pinLabel}</Text>

              {pin === null && generate.isPending ? (
                <View style={styles.pinPlaceholder}>
                  <ActivityIndicator color="#4f46e5" />
                  <Text style={styles.muted}>{S.generating}</Text>
                </View>
              ) : pin !== null ? (
                <>
                  <Text
                    style={[styles.pin, isExpired && styles.pinExpired]}
                    testID="pairing-pin"
                    accessibilityLabel={pin.pin.split('').join(' ')}
                    selectable
                  >
                    {formatPin(pin.pin)}
                  </Text>
                  {isExpired ? (
                    <Text style={styles.expired}>{S.expired}</Text>
                  ) : (
                    <Text style={styles.countdown} testID="pin-countdown">
                      {S.validFor(formatCountdown(remaining))}
                    </Text>
                  )}
                </>
              ) : null}

              {errorText && (
                <View style={styles.errorBox} testID="pin-error">
                  <Text style={styles.errorText}>{errorText}</Text>
                </View>
              )}

              <Pressable
                style={({ pressed }) => [
                  styles.secondaryButton,
                  !canRequest && styles.buttonDisabled,
                  pressed && styles.pressed,
                ]}
                onPress={requestNewPin}
                disabled={!canRequest}
                accessibilityRole="button"
                accessibilityState={{ disabled: !canRequest }}
              >
                {generate.isPending && pin !== null ? (
                  <ActivityIndicator color="#4f46e5" />
                ) : (
                  <>
                    <RefreshCw color="#4f46e5" size={16} />
                    <Text style={styles.secondaryButtonText}>{pin === null && pinError ? S.retry : S.newCode}</Text>
                  </>
                )}
              </Pressable>

              {pin !== null && !isExpired && (
                <View style={styles.waitingRow}>
                  <ActivityIndicator size="small" color="#94a3b8" />
                  <Text style={styles.muted}>{S.waiting}</Text>
                </View>
              )}
            </View>

            <View style={styles.card}>
              <View style={styles.instructionsHeader}>
                <Smartphone color="#4f46e5" size={20} />
                <Text style={styles.body}>{S.instructions}</Text>
              </View>
              {S.steps.map((step, index) => (
                <View key={step} style={styles.stepRow}>
                  <Text style={styles.stepNumber}>{index + 1}</Text>
                  <Text style={styles.stepText}>{step}</Text>
                </View>
              ))}
              <Text style={styles.note}>{S.oneTime}</Text>
            </View>
          </>
        )}
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: '#f8fafc' },
  header: {
    paddingTop: Platform.OS === 'ios' ? 56 : 40,
    paddingHorizontal: 16,
    paddingBottom: 14,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    backgroundColor: '#ffffff',
    borderBottomWidth: 1,
    borderBottomColor: '#e2e8f0',
  },
  headerTitle: { fontSize: 20, fontWeight: '800', color: '#0f172a' },
  content: { padding: 16, gap: 16, paddingBottom: 32 },
  card: {
    backgroundColor: '#ffffff',
    borderRadius: 20,
    padding: 20,
    borderWidth: 1,
    borderColor: '#e2e8f0',
    alignItems: 'center',
    gap: 10,
  },
  iconBadge: {
    width: 44,
    height: 44,
    borderRadius: 14,
    backgroundColor: '#eef2ff',
    alignItems: 'center',
    justifyContent: 'center',
  },
  pinLabel: { fontSize: 13, fontWeight: '700', letterSpacing: 0.6, color: '#64748b', textTransform: 'uppercase' },
  pinPlaceholder: { height: 72, alignItems: 'center', justifyContent: 'center', gap: 8 },
  pin: {
    fontSize: 52,
    fontWeight: '800',
    letterSpacing: 4,
    color: '#0f172a',
    fontVariant: ['tabular-nums'],
  },
  pinExpired: { color: '#cbd5e1', textDecorationLine: 'line-through' },
  countdown: { fontSize: 15, fontWeight: '600', color: '#10b981', fontVariant: ['tabular-nums'] },
  expired: { fontSize: 15, fontWeight: '600', color: '#f43f5e' },
  errorBox: {
    alignSelf: 'stretch',
    padding: 12,
    borderRadius: 12,
    backgroundColor: '#fff1f2',
    borderWidth: 1,
    borderColor: '#fecdd3',
  },
  errorText: { fontSize: 13, color: '#be123c', textAlign: 'center' },
  secondaryButton: {
    alignSelf: 'stretch',
    height: 48,
    marginTop: 6,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: '#c7d2fe',
    backgroundColor: '#eef2ff',
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
  },
  secondaryButtonText: { fontSize: 15, fontWeight: '700', color: '#4f46e5' },
  buttonDisabled: { opacity: 0.5 },
  waitingRow: { flexDirection: 'row', alignItems: 'center', gap: 8, marginTop: 4 },
  muted: { fontSize: 13, color: '#64748b' },
  instructionsHeader: { flexDirection: 'row', alignItems: 'center', gap: 10, alignSelf: 'stretch' },
  body: { flex: 1, fontSize: 14, lineHeight: 20, color: '#334155' },
  stepRow: { flexDirection: 'row', alignItems: 'flex-start', gap: 10, alignSelf: 'stretch' },
  stepNumber: {
    width: 22,
    height: 22,
    borderRadius: 11,
    overflow: 'hidden',
    backgroundColor: '#eef2ff',
    color: '#4f46e5',
    fontSize: 12,
    fontWeight: '800',
    textAlign: 'center',
    lineHeight: 22,
  },
  stepText: { flex: 1, fontSize: 14, lineHeight: 20, color: '#334155' },
  note: { alignSelf: 'stretch', fontSize: 12, color: '#94a3b8' },
  pairedTitle: { fontSize: 20, fontWeight: '800', color: '#0f172a' },
  primaryButton: {
    alignSelf: 'stretch',
    height: 50,
    marginTop: 8,
    borderRadius: 14,
    backgroundColor: '#4f46e5',
    alignItems: 'center',
    justifyContent: 'center',
  },
  primaryButtonText: { fontSize: 16, fontWeight: '700', color: '#ffffff' },
  pressed: { opacity: 0.85 },
});
