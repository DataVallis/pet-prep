/**
 * Parental gate sheet (David 2026-10-14, Apple Kids 1.3 style): "Koliko je 7 × 8?" with a
 * numeric input. Adult-facing text only (no pictures, no hints a non-reader child could
 * follow); a wrong answer asks a new question, the third wrong answer closes the gate.
 * State comes from `useParentalGate` (`<ParentalGate {...gate.props} />`).
 */

import { useEffect, useState } from 'react';
import { KeyboardAvoidingView, Modal, Platform, Pressable, StyleSheet, View } from 'react-native';

import { Text, TextInput } from '@/components/ui/Text';
import { t } from '@/i18n';
import { strings } from '@/i18n/strings';
import type { ParentalGateProps } from '@/modules/parentalGate/useParentalGate';
import { alpha, fonts, light, palette, radius, tightTracking } from '@/theme';

/** All user-visible strings (`common:parentalGate`). */
export const PARENTAL_GATE_STRINGS = strings('common', 'parentalGate', {
  question: (a: number, b: number) => t('common:parentalGate.question', { a, b }),
});

const S = PARENTAL_GATE_STRINGS;

export default function ParentalGate({ visible, question, wrong, onSubmit, onCancel }: ParentalGateProps) {
  const [answer, setAnswer] = useState('');

  // A new question (open or after a wrong answer) starts with an empty field.
  useEffect(() => {
    setAnswer('');
  }, [question, visible]);

  const submit = () => {
    if (answer.trim().length === 0) return;
    onSubmit(answer);
  };

  return (
    <Modal visible={visible} transparent animationType="fade" onRequestClose={onCancel}>
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.scrim}>
        <View style={styles.card} testID="parental-gate" accessibilityViewIsModal>
          <Text style={styles.title} accessibilityRole="header">
            {S.title}
          </Text>
          <Text style={styles.prompt}>{S.prompt}</Text>
          <Text style={styles.question} testID="parental-gate-question">
            {S.question(question.a, question.b)}
          </Text>
          <TextInput
            style={[styles.input, wrong && styles.inputWrong]}
            value={answer}
            onChangeText={(text) => setAnswer(text.replace(/[^0-9]/g, '').slice(0, 3))}
            keyboardType="number-pad"
            maxLength={3}
            autoFocus
            autoCorrect={false}
            accessibilityLabel={S.answerLabel}
            placeholder={S.answerLabel}
            placeholderTextColor={light.inkFaint}
            onSubmitEditing={submit}
            testID="parental-gate-input"
          />
          {wrong && (
            <Text style={styles.wrong} testID="parental-gate-wrong" accessibilityLiveRegion="polite">
              {S.wrong}
            </Text>
          )}
          <View style={styles.row}>
            <Pressable
              style={({ pressed }) => [styles.button, pressed && styles.pressed]}
              onPress={onCancel}
              accessibilityRole="button"
              testID="parental-gate-cancel"
            >
              <Text style={styles.cancelText}>{S.cancel}</Text>
            </Pressable>
            <Pressable
              style={({ pressed }) => [styles.button, styles.primary, answer.length === 0 && styles.disabled, pressed && styles.pressed]}
              onPress={submit}
              disabled={answer.length === 0}
              accessibilityRole="button"
              accessibilityState={{ disabled: answer.length === 0 }}
              testID="parental-gate-submit"
            >
              <Text style={styles.primaryText}>{S.submit}</Text>
            </Pressable>
          </View>
        </View>
      </KeyboardAvoidingView>
    </Modal>
  );
}

const styles = StyleSheet.create({
  scrim: { flex: 1, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 24, backgroundColor: alpha(palette.graphite, 0.7) },
  card: {
    width: '100%',
    maxWidth: 360,
    gap: 10,
    padding: 22,
    borderRadius: radius.card,
    backgroundColor: light.surface,
  },
  title: { fontFamily: fonts.display, fontSize: 22, letterSpacing: tightTracking(22), color: light.ink },
  prompt: { fontSize: 14, lineHeight: 20, color: light.inkMuted },
  question: { fontSize: 20, fontWeight: '700', color: light.ink },
  input: {
    height: 52,
    borderRadius: radius.input,
    borderWidth: 1,
    borderColor: light.border,
    backgroundColor: light.bg,
    paddingHorizontal: 16,
    fontSize: 20,
    color: light.ink,
  },
  inputWrong: { borderColor: light.danger },
  wrong: { fontSize: 13, color: light.danger },
  row: { flexDirection: 'row', gap: 10, marginTop: 6 },
  button: {
    flex: 1,
    minHeight: 48,
    borderRadius: radius.button,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 1,
    borderColor: light.border,
    backgroundColor: light.surface,
  },
  primary: { backgroundColor: light.action, borderColor: light.action },
  disabled: { opacity: 0.45 },
  pressed: { opacity: 0.85 },
  cancelText: { fontSize: 15, fontWeight: '600', color: light.ink },
  primaryText: { fontSize: 15, fontWeight: '700', color: light.onAction },
});
