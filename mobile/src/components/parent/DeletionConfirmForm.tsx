/**
 * Irreversible-deletion confirmation (M2-08): what will be deleted, the parent's
 * password and the typed confirmation word ("IZBRIŠI" / "DELETE", in the app language). The red button unlocks only with both.
 * M2-10c: a parent without a password (Google / Apple account) — the password field is
 * replaced by a hint, and the red button "Potrdi z Googlom / Apple" re-runs that
 * provider's sign-in (fresh ID token; Apple with a new nonce + its authorization code) and
 * then submits. Which one(s) comes from the server (`has_password`, `linked_providers` →
 * `appStore.authMethods`): only linked providers this device can run, Apple only on iOS;
 * several → one button each. The device's remembered sign-in method is only the fallback
 * for a session without those fields. None runnable here (e.g. Apple-only on Android) →
 * an explanation and no submit.
 * Used for the parent's own account (AccountCard) and a child profile
 * (FamilyChildrenCard). Light parent theme (ADR-007).
 */

import { useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, View } from 'react-native';
import { Text, TextInput } from '@/components/ui/Text';
import { FieldLabel, requiredA11yLabel } from '@/components/ui/RequiredLabel';
import { Check } from 'lucide-react-native';

import { PARENT_COLORS as C } from '@/components/parent/ParentUi';
import type { DeletionProof, ProviderReauth } from '@/api/client';
import { canSubmitDeletion, deleteConfirmWord, isConfirmWord, obtainDeletionProof } from '@/modules/account/account';
import { canOfferForDeletion, type SocialProvider } from '@/modules/auth/socialSdk';
import { deletionIdentity, type AuthMethods } from '@/modules/session/authMethods';
import { refreshAuthMethods } from '@/modules/session/logout';
import type { SignInMethod } from '@/modules/session/signInMethod';
import { useAppStore } from '@/store/appStore';
import { t, tPets, type PetGroup } from '@/i18n';
import { strings } from '@/i18n/strings';
import { palette } from '@/theme';

/** All user-visible strings of the form (`account:deletionForm`, M1-18). */
export const DELETION_FORM_STRINGS = strings('account', 'deletionForm', {
  /** "Za potrditev vpišite IZBRIŠI" / "To confirm, type DELETE". */
  get confirmLabel(): string {
    return t('account:deletionForm.confirmLabel', { word: deleteConfirmWord() });
  },
});

const S = DELETION_FORM_STRINGS;

interface DeletionConfirmFormProps {
  /** Lines describing exactly what goes (shown first). */
  consequences: string[];
  /** Label of the red button, e.g. "Izbriši račun". */
  submitLabel: string;
  pending: boolean;
  /** Error text from the last attempt (null = none). */
  error: string | null;
  onCancel: () => void;
  /**
   * `proof` — the password, or a fresh provider sign-in for a parent without one (M2-10c);
   * `acknowledgedPaidChallenge` — the parent ticked the paid-challenge warning (M3-11 P5).
   */
  onSubmit: (proof: DeletionProof, acknowledgedPaidChallenge: boolean) => void;
  /** The server's sign-in methods of the account (default: the session's `authMethods`). */
  authMethods?: AuthMethods | null;
  /** Fallback when `authMethods` is unknown: how the parent signed in (default: the session's). */
  signInMethod?: SignInMethod;
  /** Can this device offer the linked provider for re-auth (default `canOfferForDeletion`)? */
  canOffer?: (provider: SocialProvider) => boolean;
  /** Tests: the provider re-sign-in (default `obtainDeletionProof`). */
  obtainProof?: (provider: SocialProvider) => Promise<ProviderReauth | null>;
  /**
   * M3-11 P5: the deletion removes a dog with a purchased, unfinished challenge. Shows the
   * warning and an extra checkbox the red button needs (accidental deletion made harder).
   */
  paidChallenge?: boolean;
  /** M5-R06-09: species of the pets the paid-challenge warning is about ("this cat"); default dog. */
  paidPetGroup?: PetGroup;
  /** Prefix for test ids (`${testID}-password`, `-confirm`, `-submit`, `-cancel`, `-error`). */
  testID: string;
}

export default function DeletionConfirmForm({
  consequences,
  submitLabel,
  pending,
  error,
  onCancel,
  onSubmit,
  paidChallenge = false,
  paidPetGroup = 'dog',
  authMethods,
  signInMethod,
  canOffer = canOfferForDeletion,
  obtainProof = obtainDeletionProof,
  testID,
}: DeletionConfirmFormProps) {
  const sessionMethods = useAppStore((s) => s.authMethods);
  const sessionMethod = useAppStore((s) => s.signInMethod);
  const methods = authMethods === undefined ? sessionMethods : authMethods;
  // Bug 2026-10-14 hardening: sign-in methods are re-read from `GET /api/user` once when the
  // session doesn't know them (older payload — in the background, the fallback decides
  // meanwhile) or when they say "no password" without any linked provider (contradictory —
  // the form waits for the answer before offering anything).
  const contradictory = authMethods === undefined && methods !== null && !methods.hasPassword && methods.linkedProviders.length === 0;
  const unknown = authMethods === undefined && methods === null;
  const [checking, setChecking] = useState(contradictory);
  useEffect(() => {
    if (!contradictory && !unknown) return;
    let active = true;
    void refreshAuthMethods().finally(() => {
      if (active) setChecking(false);
    });
    return () => {
      active = false;
    };
    // Once per opened form; the refresh updates the store (and so this form).
  }, []);
  const identity = deletionIdentity(methods, signInMethod ?? sessionMethod, canOffer);
  const providers: SocialProvider[] = identity.kind === 'providers' ? identity.providers : [];
  const single: SocialProvider | null = providers.length === 1 ? providers[0] : null;
  const [password, setPassword] = useState('');
  const [confirmText, setConfirmText] = useState('');
  const [paidAck, setPaidAck] = useState(false);
  const [reauthing, setReauthing] = useState<SocialProvider | null>(null);
  const [reauthFailed, setReauthFailed] = useState(false);
  const busy = pending || reauthing !== null || checking;
  const identityReady =
    identity.kind === 'password' ? canSubmitDeletion(password, confirmText) : identity.kind === 'providers' && isConfirmWord(confirmText);
  const enabled = identityReady && (!paidChallenge || paidAck) && !busy;

  const submit = async (provider: SocialProvider | null) => {
    const ack = paidChallenge && paidAck;
    if (identity.kind === 'password') {
      onSubmit(password, ack);
      return;
    }
    if (!provider) return;
    setReauthFailed(false);
    setReauthing(provider);
    try {
      const proof = await obtainProof(provider);
      // Closed the Google / Apple sheet: nothing happens.
      if (proof) onSubmit(proof, ack);
    } catch {
      setReauthFailed(true);
    } finally {
      setReauthing(null);
    }
  };

  const shownError = reauthFailed ? S.reauth.failed : error;
  const providerLabel = (provider: SocialProvider): string => (provider === 'google' ? S.reauth.google : S.reauth.apple);
  const hint =
    identity.kind === 'unavailable'
      ? S.reauth.unavailable
      : single
        ? single === 'google'
          ? S.reauth.googleHint
          : S.reauth.appleHint
        : S.reauth.anyHint;

  return (
    <View style={styles.box} testID={testID}>
      {consequences.map((line) => (
        <Text key={line} style={styles.consequence}>
          {`• ${line}`}
        </Text>
      ))}
      <Text style={styles.irreversible}>{S.irreversible}</Text>
      {paidChallenge && (
        <>
          <Text style={styles.paidWarning} testID={`${testID}-paid-warning`}>
            {tPets('account:deletionForm.paidWarning', paidPetGroup)}
          </Text>
          <Pressable
            style={styles.ackRow}
            onPress={() => setPaidAck((v) => !v)}
            disabled={busy}
            accessibilityRole="checkbox"
            accessibilityState={{ checked: paidAck }}
            testID={`${testID}-paid-ack`}
          >
            <View style={[styles.checkbox, paidAck && styles.checkboxOn]}>{paidAck && <Check color={palette.white} size={14} />}</View>
            <Text style={styles.ackText}>{S.paidAck}</Text>
          </Pressable>
        </>
      )}

      {identity.kind !== 'password' ? (
        <Text style={styles.reauthHint} testID={`${testID}-reauth-hint`}>
          {hint}
        </Text>
      ) : (
        <>
          <FieldLabel required style={styles.label} containerStyle={styles.labelRow}>
            {S.passwordLabel}
          </FieldLabel>
          <TextInput
            style={styles.input}
            value={password}
            onChangeText={setPassword}
            placeholder={S.passwordPlaceholder}
            placeholderTextColor={C.faint}
            secureTextEntry
            autoCapitalize="none"
            autoCorrect={false}
            textContentType="password"
            editable={!busy}
            accessibilityLabel={requiredA11yLabel(S.passwordLabel)}
            testID={`${testID}-password`}
          />
        </>
      )}

      <FieldLabel required style={styles.label} containerStyle={styles.labelRow}>
        {S.confirmLabel}
      </FieldLabel>
      <TextInput
        style={styles.input}
        value={confirmText}
        onChangeText={setConfirmText}
        placeholder={deleteConfirmWord()}
        placeholderTextColor={C.faint}
        autoCapitalize="characters"
        autoCorrect={false}
        editable={!busy}
        accessibilityLabel={requiredA11yLabel(S.confirmLabel)}
        testID={`${testID}-confirm`}
      />

      {shownError && (
        <Text style={styles.error} testID={`${testID}-error`} accessibilityLiveRegion="polite">
          {shownError}
        </Text>
      )}

      {providers.length > 1 &&
        providers.map((provider) => (
          <Pressable
            key={provider}
            style={({ pressed }) => [styles.button, styles.danger, !enabled && styles.disabled, pressed && styles.pressed]}
            onPress={() => void submit(provider)}
            disabled={!enabled}
            accessibilityRole="button"
            accessibilityState={{ disabled: !enabled }}
            accessibilityLabel={`${submitLabel}: ${providerLabel(provider)}`}
            testID={`${testID}-submit-${provider}`}
          >
            {reauthing === provider || (pending && reauthing === null) ? (
              <ActivityIndicator color={palette.white} />
            ) : (
              <Text style={styles.dangerText}>{providerLabel(provider)}</Text>
            )}
          </Pressable>
        ))}

      <View style={styles.row}>
        <Pressable
          style={({ pressed }) => [styles.button, pressed && styles.pressed]}
          onPress={onCancel}
          disabled={busy}
          accessibilityRole="button"
          testID={`${testID}-cancel`}
        >
          <Text style={styles.cancelText}>{S.cancel}</Text>
        </Pressable>
        {providers.length <= 1 && (
          <Pressable
            style={({ pressed }) => [styles.button, styles.danger, !enabled && styles.disabled, pressed && styles.pressed]}
            onPress={() => void submit(single)}
            disabled={!enabled}
            accessibilityRole="button"
            accessibilityState={{ disabled: !enabled }}
            accessibilityLabel={single ? `${submitLabel}: ${providerLabel(single)}` : submitLabel}
            testID={`${testID}-submit`}
          >
            {busy ? (
              <ActivityIndicator color={palette.white} />
            ) : (
              <Text style={styles.dangerText}>{single ? providerLabel(single) : submitLabel}</Text>
            )}
          </Pressable>
        )}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  box: {
    gap: 8,
    padding: 12,
    borderRadius: 14,
    backgroundColor: C.redSoft,
    borderWidth: 1,
    borderColor: palette.dangerBorder,
  },
  consequence: { fontSize: 13, lineHeight: 18, color: palette.dangerDeep },
  irreversible: { fontSize: 13, fontWeight: '700', color: C.redText },
  paidWarning: { fontSize: 13, lineHeight: 18, fontWeight: '600', color: palette.dangerDeep, marginTop: 4 },
  ackRow: { flexDirection: 'row', alignItems: 'center', gap: 10, minHeight: 44 },
  checkbox: { width: 22, height: 22, borderRadius: 6, borderWidth: 2, borderColor: palette.danger, alignItems: 'center', justifyContent: 'center' },
  checkboxOn: { backgroundColor: palette.danger },
  ackText: { flex: 1, fontSize: 13, lineHeight: 18, color: C.text },
  label: { fontSize: 12, fontWeight: '700', color: C.muted },
  labelRow: { marginTop: 4 },
  reauthHint: { fontSize: 13, lineHeight: 18, color: C.text, marginTop: 4 },
  input: {
    height: 44,
    borderRadius: 12,
    borderWidth: 1,
    borderColor: C.border,
    backgroundColor: C.card,
    paddingHorizontal: 12,
    fontSize: 15,
    color: C.text,
  },
  error: { fontSize: 13, color: C.redText },
  row: { flexDirection: 'row', gap: 8, marginTop: 4 },
  button: {
    flex: 1,
    minHeight: 44,
    borderRadius: 12,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 1,
    borderColor: C.border,
    backgroundColor: C.card,
    paddingHorizontal: 10,
  },
  cancelText: { fontSize: 14, fontWeight: '700', color: C.link },
  danger: { backgroundColor: palette.danger, borderColor: palette.danger },
  dangerText: { fontSize: 14, fontWeight: '800', color: palette.white, textAlign: 'center' },
  disabled: { opacity: 0.45 },
  pressed: { opacity: 0.85 },
});
