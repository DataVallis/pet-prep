/**
 * ParentSignupScreen (M2-10a) — parent self-registration with e-mail + password
 * (`POST /api/register`). Same dark style as ParentLoginScreen. On success the token
 * is stored like a login and AppNavigator routes the parent to the dashboard, whose
 * empty state offers "Dodaj otroka". The device timezone becomes the family timezone.
 */

import { useState } from 'react';
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Linking,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
  type TextInputProps,
} from 'react-native';
import { Check, ChevronLeft, Eye, EyeOff, ShieldCheck, UserPlus } from 'lucide-react-native';

import {
  PRIVACY_URL,
  SIGNUP_STRINGS,
  TERMS_URL,
  deviceTimezone,
  mapSignupError,
  performSignup,
  signupBody,
  validateSignup,
  type SignupErrors,
  type SignupField,
  type SignupForm,
} from '@/modules/auth/signup';
import { deviceName } from '@/modules/pairing/deviceName';
import { useAppStore } from '@/store/appStore';

const S = SIGNUP_STRINGS;

interface ParentSignupScreenProps {
  onBack: () => void;
  /** "Že imate račun? Prijava" → back to the e-mail login. */
  onLogin: () => void;
}

const EMPTY_FORM: SignupForm = { name: '', email: '', password: '', passwordRepeat: '', acceptTerms: false };

function FieldError({ message, testID }: { message: string | undefined; testID: string }) {
  if (!message) return null;
  return (
    <Text style={styles.fieldError} testID={testID}>
      {message}
    </Text>
  );
}

interface PasswordInputProps extends Pick<TextInputProps, 'value' | 'onChangeText' | 'editable' | 'onSubmitEditing'> {
  placeholder: string;
  visible: boolean;
  onToggle: () => void;
  invalid: boolean;
  testID: string;
}

function PasswordInput({ placeholder, visible, onToggle, invalid, testID, ...input }: PasswordInputProps) {
  return (
    <View style={[styles.input, styles.passwordRow, invalid && styles.inputInvalid]}>
      <TextInput
        {...input}
        style={styles.passwordInput}
        placeholder={placeholder}
        placeholderTextColor="#64748b"
        secureTextEntry={!visible}
        autoCapitalize="none"
        autoCorrect={false}
        autoComplete="new-password"
        textContentType="newPassword"
        testID={testID}
      />
      <Pressable
        onPress={onToggle}
        hitSlop={10}
        accessibilityRole="button"
        accessibilityLabel={visible ? S.hide : S.show}
        testID={`${testID}-toggle`}
      >
        {visible ? <EyeOff color="#94a3b8" size={20} /> : <Eye color="#94a3b8" size={20} />}
      </Pressable>
    </View>
  );
}

export default function ParentSignupScreen({ onBack, onLogin }: ParentSignupScreenProps) {
  const [form, setForm] = useState<SignupForm>(EMPTY_FORM);
  const [errors, setErrors] = useState<SignupErrors>({});
  const [general, setGeneral] = useState<string | null>(null);
  const [showPassword, setShowPassword] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const signIn = useAppStore((s) => s.signIn);

  const update = <K extends keyof SignupForm>(field: K, value: SignupForm[K]) => {
    setForm((prev) => ({ ...prev, [field]: value }));
    // Clear that field's message as soon as the parent edits it.
    setErrors((prev) => {
      if (!(field in prev)) return prev;
      const next = { ...prev };
      delete next[field as SignupField];
      return next;
    });
  };

  const handleSubmit = async () => {
    if (isLoading) return;
    const clientErrors = validateSignup(form);
    setErrors(clientErrors);
    setGeneral(null);
    if (Object.keys(clientErrors).length > 0) return;

    setIsLoading(true);
    try {
      const session = await performSignup(signupBody(form, deviceName(), deviceTimezone()));
      // Same store update as login / session restore; AppNavigator routes by role.
      signIn(session);
    } catch (err) {
      const failure = mapSignupError(err);
      setErrors(failure.fields);
      setGeneral(failure.general);
      setIsLoading(false);
    }
  };

  const openLink = (url: string) => {
    void Linking.openURL(url).catch(() => undefined);
  };

  return (
    <View style={styles.container}>
      <View style={[styles.glowOrb, styles.glowIndigo]} />
      <View style={[styles.glowOrb, styles.glowEmerald]} />

      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.flex}>
        <ScrollView
          contentContainerStyle={styles.scrollContent}
          keyboardShouldPersistTaps="handled"
          showsVerticalScrollIndicator={false}
        >
          <Pressable onPress={onBack} hitSlop={10} style={styles.backRow} accessibilityRole="button" accessibilityLabel={S.back}>
            <ChevronLeft color="#94a3b8" size={22} />
            <Text style={styles.backText}>{S.back}</Text>
          </Pressable>

          <View style={styles.header}>
            <View style={styles.logoBadge}>
              <ShieldCheck color="#10b981" size={34} />
            </View>
            <Text style={styles.title}>{S.title}</Text>
            <Text style={styles.subtitle}>{S.subtitle}</Text>
          </View>

          <View style={styles.formCard}>
            <View style={styles.inputsContainer}>
              <View>
                <TextInput
                  style={[styles.input, errors.name !== undefined && styles.inputInvalid]}
                  placeholder={S.name}
                  placeholderTextColor="#64748b"
                  autoCapitalize="words"
                  autoComplete="name"
                  textContentType="name"
                  maxLength={80}
                  value={form.name}
                  onChangeText={(v) => update('name', v)}
                  editable={!isLoading}
                  testID="signup-name"
                />
                <FieldError message={errors.name} testID="signup-name-error" />
              </View>

              <View>
                <TextInput
                  style={[styles.input, errors.email !== undefined && styles.inputInvalid]}
                  placeholder={S.email}
                  placeholderTextColor="#64748b"
                  keyboardType="email-address"
                  autoCapitalize="none"
                  autoCorrect={false}
                  autoComplete="email"
                  textContentType="emailAddress"
                  value={form.email}
                  onChangeText={(v) => update('email', v)}
                  editable={!isLoading}
                  testID="signup-email"
                />
                <FieldError message={errors.email} testID="signup-email-error" />
              </View>

              <View>
                <PasswordInput
                  placeholder={S.password}
                  visible={showPassword}
                  onToggle={() => setShowPassword((v) => !v)}
                  invalid={errors.password !== undefined}
                  value={form.password}
                  onChangeText={(v) => update('password', v)}
                  editable={!isLoading}
                  testID="signup-password"
                />
                {errors.password ? (
                  <FieldError message={errors.password} testID="signup-password-error" />
                ) : (
                  <Text style={styles.hint}>{S.passwordHint}</Text>
                )}
              </View>

              <View>
                <PasswordInput
                  placeholder={S.passwordRepeat}
                  visible={showPassword}
                  onToggle={() => setShowPassword((v) => !v)}
                  invalid={errors.passwordRepeat !== undefined}
                  value={form.passwordRepeat}
                  onChangeText={(v) => update('passwordRepeat', v)}
                  editable={!isLoading}
                  onSubmitEditing={() => void handleSubmit()}
                  testID="signup-password-repeat"
                />
                <FieldError message={errors.passwordRepeat} testID="signup-password-repeat-error" />
              </View>
            </View>

            <View style={styles.termsRow}>
              <Pressable
                onPress={() => update('acceptTerms', !form.acceptTerms)}
                hitSlop={8}
                accessibilityRole="checkbox"
                accessibilityState={{ checked: form.acceptTerms }}
                accessibilityLabel={`${S.termsPrefix}${S.termsLink}${S.termsMiddle}${S.privacyLink}`}
                style={[styles.checkbox, form.acceptTerms && styles.checkboxChecked, errors.acceptTerms !== undefined && styles.inputInvalid]}
                disabled={isLoading}
                testID="signup-terms"
              >
                {form.acceptTerms && <Check color="#ffffff" size={16} />}
              </Pressable>
              <Text style={styles.termsText}>
                {S.termsPrefix}
                <Text style={styles.link} onPress={() => openLink(TERMS_URL)} accessibilityRole="link">
                  {S.termsLink}
                </Text>
                {S.termsMiddle}
                <Text style={styles.link} onPress={() => openLink(PRIVACY_URL)} accessibilityRole="link">
                  {S.privacyLink}
                </Text>
                {S.termsSuffix}
              </Text>
            </View>
            <FieldError message={errors.acceptTerms} testID="signup-terms-error" />

            {general && (
              <View style={styles.errorBox} testID="signup-error">
                <Text style={styles.errorText}>{general}</Text>
              </View>
            )}

            <Pressable
              style={({ pressed }) => [styles.primaryButton, isLoading && styles.buttonDisabled, pressed && styles.pressed]}
              onPress={() => void handleSubmit()}
              disabled={isLoading}
              accessibilityRole="button"
              accessibilityLabel={S.submit}
              testID="signup-submit"
            >
              {isLoading ? (
                <ActivityIndicator color="#ffffff" />
              ) : (
                <>
                  <UserPlus color="#ffffff" size={18} />
                  <Text style={styles.primaryButtonText}>{S.submit}</Text>
                </>
              )}
            </Pressable>

            <Pressable onPress={onLogin} hitSlop={8} style={styles.switchRow} accessibilityRole="button" disabled={isLoading}>
              <Text style={styles.switchText}>{S.haveAccount}</Text>
            </Pressable>
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#020617' },
  flex: { flex: 1 },
  glowOrb: { position: 'absolute', borderRadius: 9999 },
  glowIndigo: { width: 280, height: 280, top: 60, left: -80, backgroundColor: 'rgba(79, 70, 229, 0.2)' },
  glowEmerald: { width: 240, height: 240, bottom: 80, right: -60, backgroundColor: 'rgba(16, 185, 129, 0.12)' },
  scrollContent: { flexGrow: 1, alignItems: 'center', paddingHorizontal: 24, paddingTop: 56, paddingBottom: 40 },
  backRow: { alignSelf: 'flex-start', flexDirection: 'row', alignItems: 'center', gap: 2, paddingVertical: 6 },
  backText: { fontSize: 15, color: '#94a3b8' },
  header: { alignItems: 'center', marginTop: 12, marginBottom: 24 },
  logoBadge: {
    width: 72,
    height: 72,
    borderRadius: 22,
    backgroundColor: 'rgba(255, 255, 255, 0.08)',
    borderWidth: 1,
    borderColor: 'rgba(255, 255, 255, 0.18)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  title: { marginTop: 14, fontSize: 26, fontWeight: '800', color: '#ffffff', textAlign: 'center' },
  subtitle: { marginTop: 6, fontSize: 15, color: '#cbd5e1', textAlign: 'center' },
  formCard: {
    width: '100%',
    maxWidth: 360,
    backgroundColor: 'rgba(15, 23, 42, 0.75)',
    borderWidth: 1,
    borderColor: 'rgba(255, 255, 255, 0.12)',
    borderRadius: 24,
    padding: 22,
  },
  inputsContainer: { gap: 12 },
  input: {
    height: 52,
    backgroundColor: 'rgba(255, 255, 255, 0.05)',
    borderWidth: 1,
    borderColor: 'rgba(255, 255, 255, 0.15)',
    borderRadius: 14,
    paddingHorizontal: 16,
    fontSize: 15,
    color: '#ffffff',
  },
  inputInvalid: { borderColor: 'rgba(244, 63, 94, 0.7)' },
  passwordRow: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  passwordInput: { flex: 1, height: '100%', fontSize: 15, color: '#ffffff' },
  hint: { marginTop: 6, fontSize: 12, color: '#94a3b8' },
  fieldError: { marginTop: 6, fontSize: 12, color: '#fb7185' },
  termsRow: { flexDirection: 'row', alignItems: 'flex-start', gap: 10, marginTop: 16 },
  checkbox: {
    width: 24,
    height: 24,
    borderRadius: 7,
    borderWidth: 1.5,
    borderColor: 'rgba(255, 255, 255, 0.35)',
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: 1,
  },
  checkboxChecked: { backgroundColor: '#10b981', borderColor: '#10b981' },
  termsText: { flex: 1, fontSize: 13, lineHeight: 19, color: '#cbd5e1' },
  link: { color: '#a5b4fc', textDecorationLine: 'underline' },
  errorBox: {
    marginTop: 14,
    padding: 12,
    backgroundColor: 'rgba(244, 63, 94, 0.12)',
    borderWidth: 1,
    borderColor: 'rgba(244, 63, 94, 0.35)',
    borderRadius: 12,
  },
  errorText: { fontSize: 13, color: '#fb7185', textAlign: 'center' },
  primaryButton: {
    marginTop: 18,
    height: 52,
    backgroundColor: '#4f46e5',
    borderRadius: 14,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
  },
  buttonDisabled: { backgroundColor: 'rgba(51, 65, 85, 0.6)' },
  primaryButtonText: { fontSize: 16, fontWeight: '700', color: '#ffffff' },
  switchRow: { marginTop: 16, alignItems: 'center', paddingVertical: 4 },
  switchText: { fontSize: 14, color: '#a5b4fc', fontWeight: '600' },
  pressed: { opacity: 0.85, transform: [{ scale: 0.98 }] },
});
