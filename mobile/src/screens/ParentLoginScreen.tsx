/**
 * ParentLoginScreen — "Sem starš": e-mail + password (`POST /api/login`).
 * Children never log in here any more (M2-02): a child account is signed out
 * again right away and pointed to the PIN path.
 */

import { useState } from 'react';
import { ActivityIndicator, KeyboardAvoidingView, Platform, Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { Text, TextInput } from '@/components/ui/Text';
import { ChevronLeft, LogIn, ShieldCheck } from 'lucide-react-native';

import { ApiError, api, saveAuthToken } from '@/api/client';
import { logout } from '@/modules/session/logout';
import { useAppStore } from '@/store/appStore';
import { light, palette, radius } from '@/theme';
import { AUTH_STYLES } from '@/components/auth/authStyles';
import { BrandLogo } from '@/components/brand/BrandLogo';
import { strings } from '@/i18n/strings';

/** Seeded demo accounts are offered only in development builds (Expo Go / dev client). */
export function showDevLogins(): boolean {
  return __DEV__ === true;
}

/** All user-visible strings of this screen (`auth:login`, M1-18). */
export const PARENT_LOGIN_STRINGS = strings('auth', 'login');

const S = PARENT_LOGIN_STRINGS;

interface ParentLoginScreenProps {
  onBack: () => void;
  /** "Nimate računa? Registracija" → ParentSignupScreen (M2-10a). Hidden when absent. */
  onSignup?: () => void;
}

export default function ParentLoginScreen({ onBack, onSignup }: ParentLoginScreenProps) {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const signIn = useAppStore((s) => s.signIn);

  const handleLogin = async (loginEmail?: string, loginPassword?: string) => {
    const targetEmail = (loginEmail ?? email).trim();
    const targetPassword = loginPassword ?? password;
    if (!targetEmail || !targetPassword || isLoading) return;

    setIsLoading(true);
    setError(null);
    try {
      const response = await api.login(targetEmail, targetPassword);
      await saveAuthToken(response.token);
      if (response.user.role !== 'parent') {
        // Legacy child e-mail account: revoke the fresh token, show the PIN path instead.
        await logout();
        setError(S.childAccount);
        return;
      }
      // Same store update as the launch-time session restore (M1-12); AppNavigator routes by role.
      signIn({
        token: response.token,
        user: response.user,
        pet: response.pet,
        awaitingContract: response.awaiting_contract,
      });
    } catch (err) {
      if (err instanceof ApiError) {
        setError(err.status === 401 || err.status === 422 ? S.wrongCredentials : S.failed);
      } else {
        setError(S.offline);
      }
    } finally {
      setIsLoading(false);
    }
  };

  const ready = email.trim().length > 0 && password.trim().length > 0;

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
          </View>

          <View style={styles.formCard}>
            {/* Quick 1-tap demo login — development builds only (M0-10). __DEV__ is false in
                EAS preview/production builds, so seeded test credentials never ship. */}
            {showDevLogins() && (
              <View style={styles.quickAccessSection}>
                <Text style={styles.quickAccessTitle}>{S.devTitle}</Text>
                <Pressable
                  style={({ pressed }) => [styles.quickButton, pressed && styles.pressed]}
                  onPress={() => {
                    setEmail('parent@test.com');
                    setPassword('password');
                    void handleLogin('parent@test.com', 'password');
                  }}
                  disabled={isLoading}
                >
                  <ShieldCheck color={light.ink} size={16} />
                  <Text style={styles.quickButtonText}>{S.devParent}</Text>
                </Pressable>
                <View style={styles.dividerContainer}>
                  <View style={styles.dividerLine} />
                  <Text style={styles.dividerText}>{S.divider}</Text>
                  <View style={styles.dividerLine} />
                </View>
              </View>
            )}

            <View style={styles.inputsContainer}>
              <TextInput
                style={styles.input}
                placeholder={S.email}
                placeholderTextColor={light.inkFaint}
                keyboardType="email-address"
                autoCapitalize="none"
                autoCorrect={false}
                autoComplete="email"
                value={email}
                onChangeText={setEmail}
                editable={!isLoading}
              />
              <TextInput
                style={styles.input}
                placeholder={S.password}
                placeholderTextColor={light.inkFaint}
                secureTextEntry
                autoComplete="password"
                value={password}
                onChangeText={setPassword}
                editable={!isLoading}
                onSubmitEditing={() => void handleLogin()}
              />
            </View>

            {error && (
              <View style={styles.errorBox} testID="parent-login-error">
                <Text style={styles.errorText}>{error}</Text>
              </View>
            )}

            <Pressable
              style={({ pressed }) => [styles.primaryButton, (!ready || isLoading) && styles.buttonDisabled, pressed && styles.pressed]}
              onPress={() => void handleLogin()}
              disabled={isLoading || !ready}
              accessibilityRole="button"
            >
              {isLoading ? (
                <ActivityIndicator color={palette.white} />
              ) : (
                <>
                  <LogIn color={palette.white} size={18} />
                  <Text style={styles.primaryButtonText}>{S.submit}</Text>
                </>
              )}
            </Pressable>

            {onSignup && (
              <Pressable
                onPress={onSignup}
                hitSlop={8}
                style={styles.switchRow}
                accessibilityRole="button"
                disabled={isLoading}
                testID="parent-login-signup"
              >
                <Text style={styles.switchText}>{S.noAccount}</Text>
              </Pressable>
            )}
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </View>
  );
}

const styles = StyleSheet.create({
  ...AUTH_STYLES,
  quickAccessSection: { marginBottom: 4 },
  quickAccessTitle: {
    fontSize: 11,
    fontWeight: '700',
    color: light.inkFaint,
    letterSpacing: 0.8,
    marginBottom: 10,
    textAlign: 'center',
  },
  quickButton: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
    minHeight: 44,
    borderRadius: radius.button,
    borderWidth: 1,
    backgroundColor: palette.mintSoft,
    borderColor: palette.mintBorder,
  },
  quickButtonText: { fontSize: 13, fontWeight: '600', color: light.ink },
  dividerContainer: { flexDirection: 'row', alignItems: 'center', marginVertical: 14 },
  dividerLine: { flex: 1, height: 1, backgroundColor: light.border },
  dividerText: { paddingHorizontal: 10, fontSize: 11, color: light.inkFaint },
});
