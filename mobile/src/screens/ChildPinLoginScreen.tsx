/**
 * ChildPinLoginScreen — "Sem otrok" (M2-02). The child's only way in: the 6-digit
 * code from the parent, typed on a big on-screen keypad (no system keyboard, no
 * e-mail, no password). `POST /api/child/pin-login` → token in SecureStore →
 * `appStore.signIn()`; AppNavigator then shows the contract step (pet waits for
 * this child's signature) or the HUD. Child UI = dark glass (ADR-007).
 */

import { useEffect, useMemo, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { ChevronLeft, Delete, KeyRound, RotateCcw } from 'lucide-react-native';

import { usePinLogin } from '@/hooks/queries/usePinLogin';
import { useCountdown } from '@/hooks/useCountdown';
import { formatCountdown, secondsUntil } from '@/modules/pairing/pin';
import { classifyPinLoginError, PIN_LENGTH, type PinLoginError } from '@/modules/pairing/pinLogin';
import { useAppStore } from '@/store/appStore';

/** All user-visible strings of this screen (extract to i18n with M1-18). */
export const CHILD_PIN_STRINGS = {
  title: 'Vpiši svojo kodo',
  hint: 'Kodo s 6 številkami ti pokaže starš na svojem telefonu.',
  checking: 'Preverjam kodo …',
  invalid: 'Ta koda ne deluje. Prosi starša za novo kodo.',
  rateLimited: (time: string) => `Preveč poskusov. Počakaj še ${time}, potem poskusi znova.`,
  offline: 'Ni povezave z internetom. Preveri povezavo in poskusi znova.',
  server: 'Nekaj je šlo narobe. Poskusi znova čez trenutek.',
  retry: 'Poskusi znova',
  back: 'Nazaj',
  logout: 'Odjava',
  deleteDigit: 'Pobriši številko',
  digitsEntered: (count: number) => `Vpisanih ${count} od ${PIN_LENGTH} številk`,
} as const;

const S = CHILD_PIN_STRINGS;
const KEYS = ['1', '2', '3', '4', '5', '6', '7', '8', '9'] as const;

interface ChildPinLoginScreenProps {
  onBack: () => void;
  /** "Odjava" instead of "Nazaj" — a signed-in (legacy) child without a pet. */
  backIsLogout?: boolean;
}

export default function ChildPinLoginScreen({ onBack, backIsLogout = false }: ChildPinLoginScreenProps) {
  const [digits, setDigits] = useState('');
  const [error, setError] = useState<PinLoginError | null>(null);
  const [lockedUntil, setLockedUntil] = useState<string | null>(null);
  const signIn = useAppStore((s) => s.signIn);
  const login = usePinLogin();

  const lockRemaining = useCountdown(lockedUntil);
  // Wall clock as well: right after a 429 the countdown state hasn't ticked yet.
  const isLockedOut = lockedUntil !== null && (lockRemaining > 0 || secondsUntil(lockedUntil) > 0);
  const isBusy = login.isPending;
  const keypadDisabled = isBusy || isLockedOut;

  // The lockout is over → drop its message so the child can try again.
  useEffect(() => {
    if (lockedUntil !== null && lockRemaining <= 0 && secondsUntil(lockedUntil) <= 0) {
      setLockedUntil(null);
      setError(null);
    }
  }, [lockedUntil, lockRemaining]);

  const submit = (pin: string) => {
    if (pin.length !== PIN_LENGTH || isBusy || isLockedOut) return;
    setError(null);
    login.mutate(pin, {
      onSuccess: (session) => signIn(session),
      onError: (err) => {
        const classified = classifyPinLoginError(err);
        setError(classified);
        if (classified.kind === 'invalid') setDigits('');
        if (classified.kind === 'rate_limited') {
          setDigits('');
          const seconds = classified.retryAfterSeconds ?? 0;
          setLockedUntil(new Date(Date.now() + seconds * 1000).toISOString());
        }
      },
    });
  };

  const press = (digit: string) => {
    if (keypadDisabled || digits.length >= PIN_LENGTH) return;
    const next = digits + digit;
    setDigits(next);
    if (error && error.kind !== 'rate_limited') setError(null);
    // Kid-friendly: the 6th digit sends the code, no extra button to find.
    if (next.length === PIN_LENGTH) submit(next);
  };

  const removeDigit = () => {
    if (keypadDisabled) return;
    setDigits((d) => d.slice(0, -1));
    if (error && error.kind !== 'rate_limited') setError(null);
  };

  const errorText = useMemo(() => {
    if (!error) return null;
    switch (error.kind) {
      case 'invalid':
        return S.invalid;
      case 'rate_limited':
        return isLockedOut
          ? S.rateLimited(formatCountdown(lockRemaining > 0 ? lockRemaining : error.retryAfterSeconds ?? 0))
          : null;
      case 'offline':
        return S.offline;
      case 'server':
        return S.server;
    }
  }, [error, isLockedOut, lockRemaining]);

  const canRetry = (error?.kind === 'offline' || error?.kind === 'server') && digits.length === PIN_LENGTH;

  return (
    <View style={styles.container}>
      <View style={[styles.glowOrb, styles.glowIndigo]} />
      <View style={[styles.glowOrb, styles.glowEmerald]} />

      <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
        <Pressable
          onPress={onBack}
          hitSlop={10}
          style={styles.backRow}
          accessibilityRole="button"
          accessibilityLabel={backIsLogout ? S.logout : S.back}
          disabled={isBusy}
        >
          <ChevronLeft color="#94a3b8" size={22} />
          <Text style={styles.backText}>{backIsLogout ? S.logout : S.back}</Text>
        </Pressable>

        <View style={styles.header}>
          <View style={styles.badge}>
            <KeyRound color="#818cf8" size={30} />
          </View>
          <Text style={styles.title}>{S.title}</Text>
          <Text style={styles.hint}>{S.hint}</Text>
        </View>

        <View
          style={styles.slotsRow}
          testID="pin-slots"
          accessible
          accessibilityLabel={S.digitsEntered(digits.length)}
        >
          {Array.from({ length: PIN_LENGTH }, (_, i) => {
            const filled = i < digits.length;
            return (
              <View
                key={i}
                style={[styles.slot, filled && styles.slotFilled, i === 2 && styles.slotGap]}
                testID={filled ? 'pin-slot-filled' : 'pin-slot-empty'}
              >
                <Text style={styles.slotText}>{filled ? digits[i] : ''}</Text>
              </View>
            );
          })}
        </View>

        <View style={styles.statusArea}>
          {isBusy ? (
            <View style={styles.statusRow} testID="pin-login-loading">
              <ActivityIndicator color="#a5b4fc" />
              <Text style={styles.statusText}>{S.checking}</Text>
            </View>
          ) : errorText ? (
            <View style={styles.errorBox} testID="pin-login-error">
              <Text style={styles.errorText}>{errorText}</Text>
            </View>
          ) : null}
          {canRetry && !isBusy && (
            <Pressable
              style={({ pressed }) => [styles.retryButton, pressed && styles.pressed]}
              onPress={() => submit(digits)}
              accessibilityRole="button"
            >
              <RotateCcw color="#ffffff" size={18} />
              <Text style={styles.retryText}>{S.retry}</Text>
            </Pressable>
          )}
        </View>

        <View style={styles.keypad}>
          {KEYS.map((key) => (
            <KeypadButton key={key} label={key} onPress={() => press(key)} disabled={keypadDisabled} />
          ))}
          <View style={styles.keySpacer} />
          <KeypadButton label="0" onPress={() => press('0')} disabled={keypadDisabled} />
          <Pressable
            onPress={removeDigit}
            disabled={keypadDisabled || digits.length === 0}
            style={({ pressed }) => [styles.key, styles.keyGhost, pressed && styles.keyPressed]}
            accessibilityRole="button"
            accessibilityLabel={S.deleteDigit}
            testID="pin-key-delete"
          >
            <Delete color="#cbd5e1" size={28} />
          </Pressable>
        </View>
      </ScrollView>
    </View>
  );
}

interface KeypadButtonProps {
  label: string;
  onPress: () => void;
  disabled: boolean;
}

function KeypadButton({ label, onPress, disabled }: KeypadButtonProps) {
  return (
    <Pressable
      onPress={onPress}
      disabled={disabled}
      style={({ pressed }) => [styles.key, disabled && styles.keyDisabled, pressed && styles.keyPressed]}
      accessibilityRole="button"
      accessibilityLabel={label}
      accessibilityState={{ disabled }}
      testID={`pin-key-${label}`}
    >
      <Text style={styles.keyText}>{label}</Text>
    </Pressable>
  );
}

const KEY_SIZE = 76;

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#020617' },
  glowOrb: { position: 'absolute', borderRadius: 9999 },
  glowIndigo: { width: 280, height: 280, top: 40, left: -90, backgroundColor: 'rgba(79, 70, 229, 0.2)' },
  glowEmerald: { width: 240, height: 240, bottom: 60, right: -70, backgroundColor: 'rgba(16, 185, 129, 0.12)' },
  content: { flexGrow: 1, alignItems: 'center', paddingHorizontal: 24, paddingTop: 56, paddingBottom: 32 },
  backRow: { alignSelf: 'flex-start', flexDirection: 'row', alignItems: 'center', gap: 2, paddingVertical: 6 },
  backText: { fontSize: 15, color: '#94a3b8' },
  header: { alignItems: 'center', marginTop: 8, marginBottom: 24 },
  badge: {
    width: 64,
    height: 64,
    borderRadius: 20,
    backgroundColor: 'rgba(255, 255, 255, 0.08)',
    borderWidth: 1,
    borderColor: 'rgba(255, 255, 255, 0.18)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  title: { marginTop: 14, fontSize: 28, fontWeight: '800', color: '#ffffff', textAlign: 'center' },
  hint: { marginTop: 8, fontSize: 16, lineHeight: 22, color: '#cbd5e1', textAlign: 'center', maxWidth: 300 },
  slotsRow: { flexDirection: 'row', gap: 8 },
  slot: {
    width: 46,
    height: 60,
    borderRadius: 16,
    borderWidth: 2,
    borderColor: 'rgba(255, 255, 255, 0.18)',
    backgroundColor: 'rgba(255, 255, 255, 0.06)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  slotFilled: { borderColor: '#818cf8', backgroundColor: 'rgba(99, 102, 241, 0.25)' },
  slotGap: { marginRight: 10 },
  slotText: { fontSize: 28, fontWeight: '800', color: '#ffffff', fontVariant: ['tabular-nums'] },
  statusArea: { minHeight: 76, alignSelf: 'stretch', alignItems: 'center', justifyContent: 'center', gap: 10, marginVertical: 12 },
  statusRow: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  statusText: { fontSize: 16, color: '#c7d2fe' },
  errorBox: {
    alignSelf: 'stretch',
    padding: 12,
    borderRadius: 14,
    backgroundColor: 'rgba(244, 63, 94, 0.14)',
    borderWidth: 1,
    borderColor: 'rgba(244, 63, 94, 0.4)',
  },
  errorText: { fontSize: 15, lineHeight: 21, color: '#fda4af', textAlign: 'center', fontVariant: ['tabular-nums'] },
  retryButton: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    paddingHorizontal: 22,
    height: 48,
    borderRadius: 14,
    backgroundColor: '#4f46e5',
  },
  retryText: { fontSize: 16, fontWeight: '700', color: '#ffffff' },
  keypad: {
    width: KEY_SIZE * 3 + 18 * 2,
    flexDirection: 'row',
    flexWrap: 'wrap',
    justifyContent: 'space-between',
    rowGap: 14,
  },
  key: {
    width: KEY_SIZE,
    height: KEY_SIZE,
    borderRadius: KEY_SIZE / 2,
    backgroundColor: 'rgba(255, 255, 255, 0.08)',
    borderWidth: 1,
    borderColor: 'rgba(255, 255, 255, 0.16)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  keyGhost: { backgroundColor: 'transparent', borderColor: 'transparent' },
  keySpacer: { width: KEY_SIZE, height: KEY_SIZE },
  keyDisabled: { opacity: 0.4 },
  keyPressed: { backgroundColor: 'rgba(99, 102, 241, 0.35)', transform: [{ scale: 0.96 }] },
  keyText: { fontSize: 32, fontWeight: '700', color: '#ffffff' },
  pressed: { opacity: 0.85 },
});
