/**
 * Irreversible-deletion confirmation (M2-08): what will be deleted, the parent's
 * password and the typed word "IZBRIŠI". The red button unlocks only with both.
 * Used for the parent's own account (AccountCard) and a child profile
 * (FamilyChildrenCard). Light parent theme (ADR-007).
 */

import { useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, View } from 'react-native';
import { Text, TextInput } from '@/components/ui/Text';

import { PARENT_COLORS as C } from '@/components/parent/ParentUi';
import { canSubmitDeletion, DELETE_CONFIRM_WORD } from '@/modules/account/account';
import { palette } from '@/theme';

export const DELETION_FORM_STRINGS = {
  passwordLabel: 'Vaše geslo',
  passwordPlaceholder: 'Geslo za prijavo',
  confirmLabel: `Za potrditev vpišite ${DELETE_CONFIRM_WORD}`,
  cancel: 'Prekliči',
  irreversible: 'Brisanje je takojšnje in ga ni mogoče razveljaviti.',
} as const;

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
  onSubmit: (password: string) => void;
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
  testID,
}: DeletionConfirmFormProps) {
  const [password, setPassword] = useState('');
  const [confirmText, setConfirmText] = useState('');
  const enabled = canSubmitDeletion(password, confirmText) && !pending;

  return (
    <View style={styles.box} testID={testID}>
      {consequences.map((line) => (
        <Text key={line} style={styles.consequence}>
          {`• ${line}`}
        </Text>
      ))}
      <Text style={styles.irreversible}>{S.irreversible}</Text>

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
        placeholder={DELETE_CONFIRM_WORD}
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
          onPress={() => onSubmit(password)}
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
