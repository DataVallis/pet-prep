/**
 * ChildPinLoginScreen — "Sem otrok" (M2-02). The child's only way in: the 6-digit
 * code from the parent, typed on a big on-screen keypad (no system keyboard, no
 * e-mail, no password). `POST /api/child/pin-login` → token in SecureStore →
 * `appStore.signIn()`; AppNavigator then shows the contract step (pet waits for
 * this child's signature) or the HUD. Child UI = dark glass (ADR-007).
 *
 * "Prilepi kodo" (David 2026-10-09): the keypad has no text field, so a code the parent
 * copied can't be pasted through the system menu. The paste button (and a long press on
 * the slots) reads the clipboard via `pinClipboard`; exactly 6 digits → filled in and sent
 * like the 6th typed digit, anything else → a friendly hint, the typed digits stay.
 */

import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { AccessibilityInfo, ActivityIndicator, Platform, Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { Text } from '@/components/ui/Text';
import { ChevronLeft, ClipboardPaste, Delete, RotateCcw } from 'lucide-react-native';

import { usePinLogin } from '@/hooks/queries/usePinLogin';
import { useCountdown } from '@/hooks/useCountdown';
import { formatCountdown, secondsUntil } from '@/modules/pairing/pin';
import { isPinClipboardAvailable, readPinFromClipboard } from '@/modules/pairing/pinClipboard';
import { classifyPinLoginError, PIN_LENGTH, type PinLoginError } from '@/modules/pairing/pinLogin';
import { useAppStore } from '@/store/appStore';
import { alpha, fonts, palette, radius, tightTracking } from '@/theme';
import { BrandMark } from '@/components/brand/BrandLogo';
import { useDarkStatusBar } from '@/components/ui/useDarkStatusBar';
import { t } from '@/i18n';
import { strings } from '@/i18n/strings';

/** All user-visible strings of this screen (`auth:pin`, M1-18). */
export const CHILD_PIN_STRINGS = strings('auth', 'pin', {
  rateLimited: (time: string) => t('auth:pin.rateLimited', { time }),
  digitsEntered: (count: number) => t('auth:pin.digitsEntered', { count, total: PIN_LENGTH }),
});

const S = CHILD_PIN_STRINGS;
const KEYS = ['1', '2', '3', '4', '5', '6', '7', '8', '9'] as const;

interface ChildPinLoginScreenProps {
  onBack: () => void;
}

export default function ChildPinLoginScreen({ onBack }: ChildPinLoginScreenProps) {
  useDarkStatusBar();
  const [digits, setDigits] = useState('');
  const [error, setError] = useState<PinLoginError | null>(null);
  const [lockedUntil, setLockedUntil] = useState<string | null>(null);
  const [pasteFailed, setPasteFailed] = useState(false);
  const [isPasting, setIsPasting] = useState(false);
  // Fixed for the binary: false in a build without the expo-clipboard native module.
  const [canPaste] = useState(isPinClipboardAvailable);
  const signIn = useAppStore((s) => s.signIn);
  const login = usePinLogin();

  const lockRemaining = useCountdown(lockedUntil);
  // Wall clock as well: right after a 429 the countdown state hasn't ticked yet.
  const isLockedOut = lockedUntil !== null && (lockRemaining > 0 || secondsUntil(lockedUntil) > 0);
  const isBusy = login.isPending;
  // While a paste reads the clipboard the keypad waits too — one code at a time.
  const keypadDisabled = isBusy || isLockedOut || isPasting;
  const pasteDisabled = keypadDisabled;

  // Synchronous guards: render state is stale inside the async paste and between a
  // `mutate()` call and the re-render that shows `isPending`.
  const loginInFlightRef = useRef(false);
  const pastingRef = useRef(false);
  const lockedUntilRef = useRef<string | null>(null);
  const lockedNow = () => lockedUntilRef.current !== null && secondsUntil(lockedUntilRef.current) > 0;
  const lockUntil = (iso: string | null) => {
    lockedUntilRef.current = iso;
    setLockedUntil(iso);
  };

  // The lockout is over → drop its message so the child can try again.
  useEffect(() => {
    if (lockedUntil !== null && lockRemaining <= 0 && secondsUntil(lockedUntil) <= 0) {
      lockUntil(null);
      setError(null);
    }
  }, [lockedUntil, lockRemaining]);

  const submit = (pin: string) => {
    if (pin.length !== PIN_LENGTH || loginInFlightRef.current || lockedNow()) return;
    loginInFlightRef.current = true;
    setError(null);
    login.mutate(pin, {
      onSettled: () => {
        loginInFlightRef.current = false;
      },
      onSuccess: (session) => signIn(session),
      onError: (err) => {
        const classified = classifyPinLoginError(err);
        setError(classified);
        if (classified.kind === 'invalid' || classified.kind === 'update_required') setDigits('');
        if (classified.kind === 'rate_limited') {
          setDigits('');
          const seconds = classified.retryAfterSeconds ?? 0;
          lockUntil(new Date(Date.now() + seconds * 1000).toISOString());
        }
      },
    });
  };

  const press = (digit: string) => {
    if (keypadDisabled || pastingRef.current || digits.length >= PIN_LENGTH) return;
    const next = digits + digit;
    setDigits(next);
    setPasteFailed(false);
    if (error && error.kind !== 'rate_limited') setError(null);
    // Kid-friendly: the 6th digit sends the code, no extra button to find.
    if (next.length === PIN_LENGTH) submit(next);
  };

  const removeDigit = () => {
    if (keypadDisabled || pastingRef.current) return;
    setDigits((d) => d.slice(0, -1));
    setPasteFailed(false);
    if (error && error.kind !== 'rate_limited') setError(null);
  };

  const paste = async () => {
    if (!canPaste || pastingRef.current || loginInFlightRef.current || lockedNow()) return;
    pastingRef.current = true;
    setIsPasting(true);
    let result: Awaited<ReturnType<typeof readPinFromClipboard>>;
    try {
      // The clipboard text stays inside readPinFromClipboard — only a 6-digit code comes back.
      result = await readPinFromClipboard();
    } finally {
      pastingRef.current = false;
      setIsPasting(false);
    }
    // Re-check after the await: a request or a lockout may have started meanwhile.
    if (loginInFlightRef.current || lockedNow()) return;
    if (result.kind !== 'pin') {
      // Same as typing: an old "wrong code" message gives way to the paste hint.
      setError((prev) => (prev && prev.kind !== 'rate_limited' ? null : prev));
      setPasteFailed(true);
      // Android reads the live region; iOS needs an explicit announcement.
      if (Platform.OS === 'ios') AccessibilityInfo.announceForAccessibility(S.pasteNoCode);
      return;
    }
    setPasteFailed(false);
    setDigits(result.pin);
    submit(result.pin);
  };

  const { i18n: { language } } = useTranslation(); // errorText is memoised text
  const errorText = useMemo(() => {
    if (!error) return null;
    switch (error.kind) {
      case 'invalid':
        return S.invalid;
      case 'update_required':
        // M5-R06-01: the code is fine, this app is too old for the pet (e.g. a cat).
        return S.updateRequired;
      case 'rate_limited':
        return isLockedOut
          ? S.rateLimited(formatCountdown(lockRemaining > 0 ? lockRemaining : error.retryAfterSeconds ?? 0))
          : null;
      case 'offline':
        return S.offline;
      case 'server':
        return S.server;
    }
  }, [error, isLockedOut, lockRemaining, language]);

  const canRetry = (error?.kind === 'offline' || error?.kind === 'server') && digits.length === PIN_LENGTH;

  return (
    <View style={styles.container}>

      <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
        <Pressable
          onPress={onBack}
          hitSlop={10}
          style={styles.backRow}
          accessibilityRole="button"
          accessibilityLabel={S.back}
          disabled={isBusy}
        >
          <ChevronLeft color={palette.n400} size={22} />
          <Text style={styles.backText}>{S.back}</Text>
        </Pressable>

        <View style={styles.header}>
          <BrandMark size={64} tone="dark" />
          <Text style={styles.title}>{S.title}</Text>
          <Text style={styles.hint}>{S.hint}</Text>
        </View>

        <SlotsRow
          canPaste={canPaste}
          pasteDisabled={pasteDisabled}
          label={S.digitsEntered(digits.length)}
          pasteLabel={S.paste}
          onPaste={() => void paste()}
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
        </SlotsRow>

        {canPaste && (
          <Pressable
            onPress={() => void paste()}
            disabled={pasteDisabled}
            hitSlop={6}
            style={({ pressed }) => [styles.pasteButton, pasteDisabled && styles.keyDisabled, pressed && styles.keyPressed]}
            accessibilityRole="button"
            accessibilityLabel={S.paste}
            accessibilityHint={S.pasteHint}
            accessibilityState={{ disabled: pasteDisabled }}
            testID="pin-paste"
          >
            <ClipboardPaste color={palette.mint} size={18} />
            <Text style={styles.pasteText}>{S.paste}</Text>
          </Pressable>
        )}

        <View style={styles.statusArea}>
          {isBusy ? (
            <View style={styles.statusRow} testID="pin-login-loading">
              <ActivityIndicator color={palette.mint} />
              <Text style={styles.statusText}>{S.checking}</Text>
            </View>
          ) : errorText ? (
            <View style={styles.errorBox} testID="pin-login-error">
              <Text style={styles.errorText}>{errorText}</Text>
            </View>
          ) : pasteFailed ? (
            <View style={styles.infoBox} testID="pin-paste-message" accessibilityLiveRegion="polite">
              <Text style={styles.infoText}>{S.pasteNoCode}</Text>
            </View>
          ) : null}
          {canRetry && !isBusy && (
            <Pressable
              style={({ pressed }) => [styles.retryButton, pressed && styles.pressed]}
              onPress={() => submit(digits)}
              accessibilityRole="button"
            >
              <RotateCcw color={palette.graphite} size={18} />
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
            <Delete color={palette.n300} size={28} />
          </Pressable>
        </View>
      </ScrollView>
    </View>
  );
}

interface SlotsRowProps {
  canPaste: boolean;
  pasteDisabled: boolean;
  label: string;
  pasteLabel: string;
  onPaste: () => void;
  children: ReactNode;
}

/**
 * The code slots. With a clipboard a long press pastes (also as the "long press"
 * accessibility action); the row itself never announces "disabled" — it isn't a button.
 */
function SlotsRow({ canPaste, pasteDisabled, label, pasteLabel, onPaste, children }: SlotsRowProps) {
  if (!canPaste) {
    return (
      <View style={styles.slotsRow} testID="pin-slots" accessible accessibilityLabel={label}>
        {children}
      </View>
    );
  }
  return (
    <Pressable
      style={styles.slotsRow}
      testID="pin-slots"
      accessible
      accessibilityLabel={label}
      accessibilityState={{ disabled: false }}
      accessibilityActions={[{ name: 'longpress', label: pasteLabel }]}
      onAccessibilityAction={(event) => {
        if (event.nativeEvent.actionName === 'longpress' && !pasteDisabled) onPaste();
      }}
      onLongPress={pasteDisabled ? undefined : onPaste}
    >
      {children}
    </Pressable>
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
  container: { flex: 1, backgroundColor: palette.graphite },
  content: { flexGrow: 1, alignItems: 'center', paddingHorizontal: 24, paddingTop: 56, paddingBottom: 32 },
  backRow: { alignSelf: 'flex-start', flexDirection: 'row', alignItems: 'center', gap: 2, paddingVertical: 10, minHeight: 44 },
  backText: { fontSize: 15, color: palette.n400 },
  header: { alignItems: 'center', marginTop: 8, marginBottom: 24 },
  title: { marginTop: 16, fontFamily: fonts.display, fontSize: 28, letterSpacing: tightTracking(28), color: palette.fog, textAlign: 'center' },
  hint: { marginTop: 8, fontSize: 16, lineHeight: 22, color: palette.n300, textAlign: 'center', maxWidth: 300 },
  slotsRow: { flexDirection: 'row', gap: 8 },
  slot: {
    width: 46,
    height: 60,
    borderRadius: 16,
    borderWidth: 2,
    borderColor: alpha(palette.white, 0.18),
    backgroundColor: alpha(palette.white, 0.06),
    alignItems: 'center',
    justifyContent: 'center',
  },
  slotFilled: { borderColor: palette.mint, backgroundColor: alpha(palette.mint, 0.25) },
  slotGap: { marginRight: 10 },
  pasteButton: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    marginTop: 14,
    paddingHorizontal: 18,
    minHeight: 44,
    borderRadius: radius.button,
    backgroundColor: alpha(palette.white, 0.06),
    borderWidth: 1,
    borderColor: alpha(palette.mint, 0.45),
  },
  pasteText: { fontSize: 16, fontWeight: '700', color: palette.mint },
  slotText: { fontSize: 28, fontWeight: '800', color: palette.white, fontVariant: ['tabular-nums'] },
  statusArea: { minHeight: 76, alignSelf: 'stretch', alignItems: 'center', justifyContent: 'center', gap: 10, marginVertical: 12 },
  statusRow: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  statusText: { fontSize: 16, color: palette.mint },
  errorBox: {
    alignSelf: 'stretch',
    padding: 12,
    borderRadius: 14,
    backgroundColor: alpha(palette.dangerDark, 0.14),
    borderWidth: 1,
    borderColor: alpha(palette.dangerDark, 0.4),
  },
  errorText: { fontSize: 15, lineHeight: 21, color: palette.dangerDark, textAlign: 'center', fontVariant: ['tabular-nums'] },
  infoBox: {
    alignSelf: 'stretch',
    padding: 12,
    borderRadius: 14,
    backgroundColor: alpha(palette.white, 0.08),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.16),
  },
  infoText: { fontSize: 15, lineHeight: 21, color: palette.n300, textAlign: 'center' },
  retryButton: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    paddingHorizontal: 22,
    height: 48,
    borderRadius: radius.button,
    backgroundColor: palette.mint,
  },
  retryText: { fontSize: 16, fontWeight: '700', color: palette.graphite },
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
    backgroundColor: alpha(palette.white, 0.08),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.16),
    alignItems: 'center',
    justifyContent: 'center',
  },
  keyGhost: { backgroundColor: 'transparent', borderColor: 'transparent' },
  keySpacer: { width: KEY_SIZE, height: KEY_SIZE },
  keyDisabled: { opacity: 0.4 },
  keyPressed: { backgroundColor: alpha(palette.mint, 0.35), transform: [{ scale: 0.96 }] },
  keyText: { fontSize: 32, letterSpacing: tightTracking(32), fontFamily: fonts.displayBold, color: palette.white },
  pressed: { opacity: 0.85 },
});
