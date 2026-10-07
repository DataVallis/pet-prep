/**
 * Irreversible-deletion confirmation (M2-08): what will be deleted, the parent's
 * password and the typed confirmation word ("IZBRIŠI" / "DELETE", in the app language). The red button unlocks only with both.
 * Used for the parent's own account (AccountCard) and a child profile
 * (FamilyChildrenCard). Light parent theme (ADR-007).
 */

import { useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, View } from 'react-native';
import { Text, TextInput } from '@/components/ui/Text';
import { Check } from 'lucide-react-native';

import { PARENT_COLORS as C } from '@/components/parent/ParentUi';
import { canSubmitDeletion, deleteConfirmWord } from '@/modules/account/account';
import { t } from '@/i18n';
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
  /** `acknowledgedPaidChallenge` — the parent ticked the paid-challenge warning (M3-11 P5). */
  onSubmit: (password: string, acknowledgedPaidChallenge: boolean) => void;
  /**
   * M3-11 P5: the deletion removes a dog with a purchased, unfinished challenge. Shows the
   * warning and an extra checkbox the red button needs (accidental deletion made harder).
   */
  paidChallenge?: boolean;
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
  testID,
}: DeletionConfirmFormProps) {
  const [password, setPassword] = useState('');
  const [confirmText, setConfirmText] = useState('');
  const [paidAck, setPaidAck] = useState(false);
  const enabled = canSubmitDeletion(password, confirmText) && (!paidChallenge || paidAck) && !pending;

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
            {S.paidWarning}
          </Text>
          <Pressable
            style={styles.ackRow}
            onPress={() => setPaidAck((v) => !v)}
            disabled={pending}
            accessibilityRole="checkbox"
            accessibilityState={{ checked: paidAck }}
            testID={`${testID}-paid-ack`}
          >
            <View style={[styles.checkbox, paidAck && styles.checkboxOn]}>{paidAck && <Check color={palette.white} size={14} />}</View>
            <Text style={styles.ackText}>{S.paidAck}</Text>
          </Pressable>
        </>
      )}

      <Text style={styles.label}>{S.passwordLabel}</Text>
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
        editable={!pending}
        accessibilityLabel={S.passwordLabel}
        testID={`${testID}-password`}
      />

      <Text style={styles.label}>{S.confirmLabel}</Text>
      <TextInput
        style={styles.input}
        value={confirmText}
        onChangeText={setConfirmText}
        placeholder={deleteConfirmWord()}
        placeholderTextColor={C.faint}
        autoCapitalize="characters"
        autoCorrect={false}
        editable={!pending}
        accessibilityLabel={S.confirmLabel}
        testID={`${testID}-confirm`}
      />

      {error && (
        <Text style={styles.error} testID={`${testID}-error`} accessibilityLiveRegion="polite">
          {error}
        </Text>
      )}

      <View style={styles.row}>
        <Pressable
          style={({ pressed }) => [styles.button, pressed && styles.pressed]}
          onPress={onCancel}
          disabled={pending}
          accessibilityRole="button"
          testID={`${testID}-cancel`}
        >
          <Text style={styles.cancelText}>{S.cancel}</Text>
        </Pressable>
        <Pressable
          style={({ pressed }) => [styles.button, styles.danger, !enabled && styles.disabled, pressed && styles.pressed]}
          onPress={() => onSubmit(password, paidChallenge && paidAck)}
          disabled={!enabled}
          accessibilityRole="button"
          accessibilityState={{ disabled: !enabled }}
          testID={`${testID}-submit`}
        >
          {pending ? <ActivityIndicator color={palette.white} /> : <Text style={styles.dangerText}>{submitLabel}</Text>}
        </Pressable>
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
  label: { fontSize: 12, fontWeight: '700', color: C.muted, marginTop: 4 },
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
