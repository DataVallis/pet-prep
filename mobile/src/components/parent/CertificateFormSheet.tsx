/**
 * M2-01d — "Ustvari certifikat" form (CERTIFICATE_SPEC §1): the certificate language
 * (English / Slovenščina), the child's name as printed (default: the nickname) and the
 * pet's name (default: its name or breed), a note that no surname is needed, "Ustvari".
 * Opened again from "Uredi in ustvari znova" prefilled with what is printed now. The
 * server is the authority (characters, word filter): its 422 codes appear under the
 * field; a 409 / 404 / network error above the button. Light parent theme (ADR-007).
 */

import { useContext, useState } from 'react';
import { ActivityIndicator, KeyboardAvoidingView, Platform, Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { SafeAreaInsetsContext } from 'react-native-safe-area-context';
import { X } from 'lucide-react-native';

import { Text, TextInput } from '@/components/ui/Text';
import { PARENT_COLORS as C } from '@/components/parent/ParentUi';
import {
  CERTIFICATE_FORM_STRINGS as S,
  CERTIFICATE_LOCALES,
  CERTIFICATE_NAME_MAX_LENGTH,
  certificateErrorText,
  certificateFormDefaults,
  certificateNameLength,
  classifyCertificateError,
  validateCertificateForm,
  type CertificateFormErrors,
  type CertificateFormValues,
  type CertificateNameField,
  type ParentCertificate,
} from '@/modules/certificate/certificate';
import { useGenerateCertificate } from '@/modules/certificate/useCertificate';
import { MIN_TOUCH, alpha, fonts, palette, radius, tightTracking } from '@/theme';

/** Room to type past the limit, so "največ 30 znakov" is explained instead of input being cut. */
const INPUT_MAX_LENGTH = CERTIFICATE_NAME_MAX_LENGTH * 2;

interface CertificateFormSheetProps {
  certificate: ParentCertificate;
  onClose: () => void;
  /** After a successful generate (the dashboard cache already holds the new payload). */
  onDone: (certificate: ParentCertificate | null) => void;
  /** Reports whether a request runs, so the modal's back button can wait. */
  onBusyChange?: (busy: boolean) => void;
  testID?: string;
}

export default function CertificateFormSheet({
  certificate,
  onClose,
  onDone,
  onBusyChange,
  testID = 'certificate-form',
}: CertificateFormSheetProps) {
  const [values, setValues] = useState<CertificateFormValues>(() => certificateFormDefaults(certificate));
  const [errors, setErrors] = useState<CertificateFormErrors>({});
  const generate = useGenerateCertificate();
  const busy = generate.isPending;
  const bottomInset = useContext(SafeAreaInsetsContext)?.bottom ?? 0;

  const setField = (field: keyof CertificateFormValues, value: string) => {
    setValues((v) => ({ ...v, [field]: value }));
    setErrors((e) => ({ ...e, [field]: undefined, form: undefined }));
  };

  const submit = () => {
    if (busy) return;
    const result = validateCertificateForm(values);
    if (!result.ok) {
      setErrors(result.errors);
      return;
    }
    setErrors({});
    onBusyChange?.(true);
    generate.mutate(
      { certificateId: certificate.id, values: result.body },
      {
        onSuccess: (payload) => onDone(payload),
        onError: (err) => setErrors(classifyCertificateError(err)),
        onSettled: () => onBusyChange?.(false),
      },
    );
  };

  const nameField = (field: CertificateNameField, label: string) => {
    const error = errors[field];
    const length = certificateNameLength(values[field]);
    return (
      <View style={styles.field}>
        <Text style={styles.label} nativeID={`${testID}-${field}-label`}>
          {label}
        </Text>
        <TextInput
          style={[styles.input, error !== undefined && styles.inputError]}
          value={values[field]}
          onChangeText={(v) => setField(field, v)}
          maxLength={INPUT_MAX_LENGTH}
          autoCapitalize="words"
          autoCorrect={false}
          autoComplete="off"
          editable={!busy}
          returnKeyType="done"
          accessibilityLabel={label}
          accessibilityHint={S.nameHint}
          testID={`${testID}-${field}`}
        />
        <View style={styles.metaRow}>
          {error !== undefined ? (
            <Text style={styles.error} accessibilityRole="alert" accessibilityLiveRegion="polite" testID={`${testID}-${field}-error`}>
              {certificateErrorText(error)}
            </Text>
          ) : (
            <View style={styles.flex} />
          )}
          <Text style={[styles.counter, length > CERTIFICATE_NAME_MAX_LENGTH && styles.counterOver]}>{S.counter(length)}</Text>
        </View>
      </View>
    );
  };

  return (
    <KeyboardAvoidingView style={styles.backdrop} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <Pressable
        style={StyleSheet.absoluteFill}
        onPress={busy ? undefined : onClose}
        accessible={false}
        importantForAccessibility="no"
        testID={`${testID}-backdrop`}
      />
      <View style={[styles.sheet, { paddingBottom: 20 + bottomInset }]} testID={testID} accessibilityViewIsModal>
        <View style={styles.headerRow}>
          <Text style={styles.title} accessibilityRole="header">
            {certificate.generated ? S.editTitle : S.title}
          </Text>
          <Pressable
            onPress={onClose}
            disabled={busy}
            accessibilityRole="button"
            accessibilityLabel={S.close}
            style={({ pressed }) => [styles.close, pressed && styles.pressed]}
            testID={`${testID}-close`}
          >
            <X color={C.text} size={20} />
          </Pressable>
        </View>

        <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={styles.body}>
          <View style={styles.field}>
            <Text style={styles.label}>{S.language}</Text>
            <View style={styles.segmented} accessibilityRole="radiogroup">
              {CERTIFICATE_LOCALES.map((locale) => {
                const selected = values.locale === locale;
                return (
                  <Pressable
                    key={locale}
                    onPress={() => setField('locale', locale)}
                    disabled={busy}
                    accessibilityRole="radio"
                    accessibilityState={{ selected, disabled: busy }}
                    style={({ pressed }) => [styles.segment, selected && styles.segmentSelected, pressed && styles.pressed]}
                    testID={`${testID}-locale-${locale}`}
                  >
                    <Text style={[styles.segmentText, selected && styles.segmentTextSelected]}>{S.languages[locale]}</Text>
                  </Pressable>
                );
              })}
            </View>
          </View>

          {nameField('child_name', S.childName)}
          {nameField('pet_name', S.petName)}
          <Text style={styles.hint} testID={`${testID}-hint`}>
            {S.nameHint}
          </Text>

          {errors.form !== undefined && (
            <Text style={styles.formError} accessibilityRole="alert" accessibilityLiveRegion="polite" testID={`${testID}-error`}>
              {certificateErrorText(errors.form)}
            </Text>
          )}

          <Pressable
            style={({ pressed }) => [styles.primary, busy && styles.disabled, pressed && styles.pressed]}
            onPress={submit}
            disabled={busy}
            accessibilityRole="button"
            accessibilityState={{ disabled: busy, busy }}
            testID={`${testID}-submit`}
          >
            {busy ? <ActivityIndicator color={palette.white} testID={`${testID}-loading`} /> : <Text style={styles.primaryText}>{S.submit}</Text>}
          </Pressable>
          <Pressable
            style={({ pressed }) => [styles.tertiary, busy && styles.disabled, pressed && styles.pressed]}
            onPress={onClose}
            disabled={busy}
            accessibilityRole="button"
            accessibilityState={{ disabled: busy }}
            testID={`${testID}-cancel`}
          >
            <Text style={styles.tertiaryText}>{S.cancel}</Text>
          </Pressable>
        </ScrollView>
      </View>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  backdrop: { flex: 1, justifyContent: 'flex-end', backgroundColor: alpha(palette.black, 0.4) },
  sheet: {
    maxHeight: '92%',
    paddingHorizontal: 20,
    paddingTop: 16,
    borderTopLeftRadius: radius.sheet,
    borderTopRightRadius: radius.sheet,
    backgroundColor: C.card,
  },
  body: { gap: 14, paddingBottom: 4 },
  headerRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 12, marginBottom: 6 },
  title: { flex: 1, fontFamily: fonts.displayBold, fontSize: 20, letterSpacing: tightTracking(20), color: C.text },
  close: { width: MIN_TOUCH, height: MIN_TOUCH, alignItems: 'center', justifyContent: 'center' },
  field: { gap: 6 },
  label: { fontSize: 13, fontWeight: '700', color: C.text },
  segmented: { flexDirection: 'row', gap: 8 },
  segment: {
    flex: 1,
    minHeight: MIN_TOUCH,
    borderRadius: radius.button,
    borderWidth: 1,
    borderColor: C.border,
    backgroundColor: C.bg,
    alignItems: 'center',
    justifyContent: 'center',
  },
  segmentSelected: { backgroundColor: C.accentSoft, borderColor: C.accentBorder },
  segmentText: { fontSize: 15, fontWeight: '600', color: C.muted },
  segmentTextSelected: { color: C.text, fontWeight: '700' },
  input: {
    height: 50,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: palette.n300,
    backgroundColor: palette.fog,
    paddingHorizontal: 14,
    fontSize: 16,
    color: palette.graphite,
  },
  inputError: { borderColor: C.red },
  metaRow: { flexDirection: 'row', alignItems: 'flex-start', gap: 12 },
  counter: { fontSize: 12, fontWeight: '600', color: C.muted },
  counterOver: { color: C.redText },
  error: { flex: 1, fontSize: 13, lineHeight: 18, color: C.redText },
  hint: { fontSize: 13, lineHeight: 19, color: C.muted },
  formError: { fontSize: 14, lineHeight: 20, color: C.redText },
  primary: {
    height: 50,
    borderRadius: radius.button,
    backgroundColor: C.accent,
    alignItems: 'center',
    justifyContent: 'center',
  },
  primaryText: { fontSize: 16, fontWeight: '700', color: palette.white },
  tertiary: { minHeight: MIN_TOUCH, alignItems: 'center', justifyContent: 'center' },
  tertiaryText: { fontSize: 15, fontWeight: '600', color: C.muted },
  disabled: { opacity: 0.5 },
  pressed: { opacity: 0.85 },
});
