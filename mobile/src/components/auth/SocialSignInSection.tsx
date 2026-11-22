/**
 * Google / Apple sign-in block on the parent login and sign-up screens (M2-10c), above the
 * e-mail form: "Nadaljuj z Googlom" (Google's branded button), on iOS the official
 * `AppleAuthenticationButton` (CONTINUE, black — Apple HIG), then the divider "ali z
 * e-pošto". Renders nothing when neither provider is available (Expo Go, no client IDs,
 * Android without Google config). Children never reach these screens.
 *
 * Flow (`modules/auth/socialSignIn.ts`): provider UI → `POST /api/auth/social` → the same
 * session as a password login. A new identity answers `terms_required`: a sheet asks for
 * the terms + privacy consent (as on the e-mail sign-up) and retries with the SAME token.
 * Closing the provider UI does nothing. M5-08: the sheet also asks the optional »Kje ste izvedeli za
 * PetPrep?« (`AcquisitionPicker`); the answer goes with the `terms_accepted` retry.
 */

import { useState } from 'react';
import { ActivityIndicator, KeyboardAvoidingView, Modal, Platform, Pressable, ScrollView, StyleSheet, View } from 'react-native';

import GoogleSignInButton from '@/components/auth/GoogleSignInButton';
import TermsCheckbox from '@/components/auth/TermsCheckbox';
import AcquisitionPicker from '@/components/auth/AcquisitionPicker';
import { EMPTY_ACQUISITION, type AcquisitionChoice } from '@/modules/validation/acquisition';
import { AUTH_STYLES } from '@/components/auth/authStyles';
import { Text } from '@/components/ui/Text';
import {
  SOCIAL_STRINGS as S,
  completeSocialSignUp,
  startSocialSignIn,
  type SocialErrorKind,
  type SocialSignInOutcome,
} from '@/modules/auth/socialSignIn';
import { appleButtonSdk, type ProviderCredential, type SocialProvider } from '@/modules/auth/socialSdk';
import { useSocialProviders, type SocialProviders } from '@/modules/auth/useSocialProviders';
import { useAppStore } from '@/store/appStore';
import { alpha, fonts, light, palette, radius, tightTracking } from '@/theme';

type Busy = SocialProvider | 'terms' | null;

interface SocialSignInSectionProps {
  /** The e-mail form is sending: the provider buttons wait. */
  disabled?: boolean;
  /** A provider flow started / ended — the screen disables its e-mail form meanwhile. */
  onBusyChange?: (busy: boolean) => void;
  /** Tests: fixed availability instead of probing the device. */
  providers?: SocialProviders;
  /**
   * M5-08: show »Kje ste izvedeli za PetPrep?« on the new-account sheet — only when the public
   * `validation_signals_signup` flag is true (the screen reads it); default hidden, nothing sent.
   */
  showAcquisition?: boolean;
  testID?: string;
}

export default function SocialSignInSection({
  disabled = false,
  onBusyChange,
  providers,
  showAcquisition = false,
  testID = 'social-signin',
}: SocialSignInSectionProps) {
  const detected = useSocialProviders();
  const available = providers ?? detected;
  const apple = available.apple ? appleButtonSdk() : null;
  const signIn = useAppStore((s) => s.signIn);

  const [busy, setBusyState] = useState<Busy>(null);
  const [error, setError] = useState<SocialErrorKind | null>(null);
  const [pendingCredential, setPendingCredential] = useState<ProviderCredential | null>(null);
  const [termsAccepted, setTermsAccepted] = useState(false);
  const [termsInvalid, setTermsInvalid] = useState(false);
  // M5-08: optional answer on the new-account sheet, sent with the `terms_accepted` retry.
  const [acquisition, setAcquisition] = useState<AcquisitionChoice>(EMPTY_ACQUISITION);

  if (!available.google && !apple) return null;

  const setBusy = (next: Busy) => {
    setBusyState(next);
    onBusyChange?.(next !== null);
  };

  const apply = (outcome: SocialSignInOutcome) => {
    switch (outcome.kind) {
      case 'signed_in':
        setPendingCredential(null);
        // Same store update as a password login; AppNavigator routes the parent.
        signIn(outcome.session);
        return;
      case 'terms_required':
        setTermsAccepted(false);
        setTermsInvalid(false);
        setAcquisition(EMPTY_ACQUISITION);
        setPendingCredential(outcome.credential);
        return;
      case 'cancelled':
        return;
      case 'error':
        setPendingCredential(null);
        setError(outcome.error);
    }
  };

  const start = async (provider: SocialProvider) => {
    if (busy !== null || disabled) return;
    setError(null);
    setBusy(provider);
    try {
      apply(await startSocialSignIn(provider));
    } finally {
      setBusy(null);
    }
  };

  const confirmTerms = async () => {
    if (!pendingCredential || busy !== null) return;
    if (!termsAccepted) {
      setTermsInvalid(true);
      return;
    }
    setBusy('terms');
    try {
      apply(await completeSocialSignUp(pendingCredential, undefined, showAcquisition ? acquisition : null));
    } finally {
      setBusy(null);
    }
  };

  const cancelTerms = () => {
    if (busy !== null) return;
    setPendingCredential(null);
  };

  const locked = busy !== null || disabled;

  return (
    <View style={styles.section} testID={testID}>
      {available.google && (
        <GoogleSignInButton
          label={S.google}
          onPress={() => void start('google')}
          disabled={locked}
          loading={busy === 'google'}
          testID={`${testID}-google`}
        />
      )}

      {apple && (
        <View style={[styles.appleWrap, locked && styles.locked]} pointerEvents={locked ? 'none' : 'auto'} testID={`${testID}-apple`}>
          <apple.AppleAuthenticationButton
            buttonType={apple.AppleAuthenticationButtonType.CONTINUE}
            buttonStyle={apple.AppleAuthenticationButtonStyle.BLACK}
            cornerRadius={radius.button}
            style={styles.appleButton}
            onPress={() => void start('apple')}
          />
          {busy === 'apple' && (
            <View style={styles.appleSpinner} pointerEvents="none">
              <ActivityIndicator color={palette.white} accessibilityLabel={S.busy} />
            </View>
          )}
        </View>
      )}

      {error && (
        <View style={styles.errorBox} testID={`${testID}-error`} accessibilityLiveRegion="polite">
          <Text style={styles.errorText}>{S.errors[error]}</Text>
        </View>
      )}

      <View style={styles.dividerRow}>
        <View style={styles.dividerLine} />
        <Text style={styles.dividerText}>{S.divider}</Text>
        <View style={styles.dividerLine} />
      </View>

      <Modal visible={pendingCredential !== null} transparent animationType="slide" onRequestClose={cancelTerms}>
        <KeyboardAvoidingView style={styles.scrim} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
          <View style={styles.sheet} testID={`${testID}-terms`} accessibilityViewIsModal>
            <Text style={styles.sheetTitle} accessibilityRole="header">
              {S.termsTitle}
            </Text>
            <Text style={styles.sheetBody}>{S.termsBody}</Text>
            {showAcquisition && (
              <ScrollView style={styles.sheetScroll} keyboardShouldPersistTaps="handled" showsVerticalScrollIndicator={false}>
                <View style={styles.sheetAcquisition}>
                  <AcquisitionPicker value={acquisition} onChange={setAcquisition} disabled={busy !== null} testID={`${testID}-acquisition`} />
                </View>
              </ScrollView>
            )}
            <TermsCheckbox
              checked={termsAccepted}
              onToggle={() => {
                setTermsAccepted((v) => !v);
                setTermsInvalid(false);
              }}
              disabled={busy !== null}
              invalid={termsInvalid}
              testID={`${testID}-terms-checkbox`}
            />
            <Pressable
              style={({ pressed }) => [styles.primaryButton, (!termsAccepted || busy !== null) && styles.buttonDisabled, pressed && styles.pressed]}
              onPress={() => void confirmTerms()}
              disabled={busy !== null}
              accessibilityRole="button"
              accessibilityState={{ disabled: !termsAccepted || busy !== null, busy: busy === 'terms' }}
              testID={`${testID}-terms-submit`}
            >
              {busy === 'terms' ? <ActivityIndicator color={palette.white} /> : <Text style={styles.primaryButtonText}>{S.termsSubmit}</Text>}
            </Pressable>
            <Pressable
              onPress={cancelTerms}
              hitSlop={8}
              style={styles.switchRow}
              accessibilityRole="button"
              disabled={busy !== null}
              testID={`${testID}-terms-cancel`}
            >
              <Text style={styles.switchText}>{S.termsCancel}</Text>
            </Pressable>
          </View>
        </KeyboardAvoidingView>
      </Modal>
    </View>
  );
}

const styles = StyleSheet.create({
  ...AUTH_STYLES,
  section: { gap: 10 },
  appleWrap: { height: 48, justifyContent: 'center' },
  appleButton: { width: '100%', height: 48 },
  appleSpinner: { position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, alignItems: 'center', justifyContent: 'center' },
  locked: { opacity: 0.5 },
  errorBox: { ...AUTH_STYLES.errorBox, marginTop: 4 },
  dividerRow: { flexDirection: 'row', alignItems: 'center', marginTop: 8, marginBottom: 6 },
  dividerLine: { flex: 1, height: 1, backgroundColor: light.border },
  dividerText: { paddingHorizontal: 10, fontSize: 12, color: light.inkFaint },
  scrim: { flex: 1, justifyContent: 'flex-end', backgroundColor: alpha(palette.graphite, 0.45) },
  sheet: {
    backgroundColor: light.surface,
    borderTopLeftRadius: radius.sheet,
    borderTopRightRadius: radius.sheet,
    paddingHorizontal: 24,
    paddingTop: 28,
    paddingBottom: 40,
  },
  sheetTitle: { fontFamily: fonts.display, fontSize: 24, letterSpacing: tightTracking(24), color: light.ink },
  sheetBody: { marginTop: 10, fontSize: 15, lineHeight: 21, color: light.inkMuted },
  sheetScroll: { flexGrow: 0, maxHeight: 320 },
  sheetAcquisition: { marginTop: 16 },
});
