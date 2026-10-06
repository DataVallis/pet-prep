/**
 * ParentSignupScreen (M2-10a) — parent self-registration with e-mail + password
 * (`POST /api/register`). Same dark style as ParentLoginScreen. On success the token
 * is stored like a login and AppNavigator routes the parent to the dashboard, whose
 * empty state offers "Dodaj otroka". The device timezone becomes the family timezone.
 */

import { useState } from 'react';
import { ActivityIndicator, KeyboardAvoidingView, Linking, Platform, Pressable, ScrollView, StyleSheet, View, type TextInputProps } from 'react-native';
import { Text, TextInput } from '@/components/ui/Text';
import { Check, ChevronLeft, Eye, EyeOff, UserPlus } from 'lucide-react-native';

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
import { light, palette } from '@/theme';
import { AUTH_STYLES } from '@/components/auth/authStyles';
import { BrandLogo } from '@/components/brand/BrandLogo';

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
        placeholderTextColor={light.inkFaint}
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
        {visible ? <EyeOff color={light.inkMuted} size={20} /> : <Eye color={light.inkMuted} size={20} />}
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

      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.flex}>
        <ScrollView
          contentContainerStyle={styles.scrollContent}
          keyboardShouldPersistTaps="handled"
          showsVerticalScrollIndicator={false}
        >
          <Pressable onPress={onBack} hitSlop={10} style={styles.backRow} accessibilityRole="button" accessibilityLabel={S.back}>
            <ChevronLeft color={light.inkMuted} size={22} />
            <Text style={styles.backText}>{S.back}</Text>
          </Pressable>

          <View style={styles.header}>
            <BrandLogo width={150} tone="light" />
            <Text style={styles.title}>{S.title}</Text>
            <Text style={styles.subtitle}>{S.subtitle}</Text>
          </View>

          <View style={styles.formCard}>
            <View style={styles.inputsContainer}>
              <View>
                <TextInput
                  style={[styles.input, errors.name !== undefined && styles.inputInvalid]}
                  placeholder={S.name}
                  placeholderTextColor={light.inkFaint}
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
                  placeholderTextColor={light.inkFaint}
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
                {form.acceptTerms && <Check color={palette.white} size={16} />}
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
                <ActivityIndicator color={palette.white} />
              ) : (
                <>
                  <UserPlus color={palette.white} size={18} />
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
  ...AUTH_STYLES,
  passwordRow: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  passwordInput: { flex: 1, height: '100%', fontSize: 15, color: light.ink },
  hint: { marginTop: 6, fontSize: 12, color: light.inkMuted },
  fieldError: { marginTop: 6, fontSize: 12, color: light.danger },
  termsRow: { flexDirection: 'row', alignItems: 'flex-start', gap: 10, marginTop: 16 },
  checkbox: {
    width: 24,
    height: 24,
    borderRadius: 7,
    borderWidth: 1.5,
    borderColor: palette.n400,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: 1,
  },
  checkboxChecked: { backgroundColor: light.action, borderColor: light.action },
  termsText: { flex: 1, fontSize: 13, lineHeight: 19, color: light.inkMuted },
  link: { color: light.mintText, textDecorationLine: 'underline' },
});
